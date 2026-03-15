<?php
/**
 * SplitTester.php — A/B Split-test клоака с Байесовским тестом.
 *
 * Структура splits.json:
 *   [
 *     {
 *       "id": "test_001",
 *       "geo": ["UA"],            // пустой массив = все ГЕО
 *       "status": "active",       // active | paused | winner_selected
 *       "winner_variant": null,   // ID варианта-победителя или null
 *       "min_conversions": 100,   // мин. конверсий до анализа
 *       "confidence_threshold": 95,  // % вероятности для автовыбора
 *       "variants": [
 *         {"id": "var_a", "template": "expert_review", "weight": 50},
 *         {"id": "var_b", "template": "sports_news",   "weight": 50}
 *       ]
 *     }
 *   ]
 *
 * Жизненный цикл:
 *   1. assign($geo, $clickId) → возвращает вариант (с консистентностью через cookie)
 *   2. recordConversion($clickId) → помечает клик как конверсию
 *   3. analyze($splitId) → Байесовский тест → если >95% уверенности → автовыбор
 *
 * Байесовский тест:
 *   Beta(1 + conversions, 1 + non_conversions) — 10 000 Монте-Карло сэмплов
 */

class SplitTester
{
    private const SPLITS_FILE     = ROOT . '/config/splits.json';
    private const COOKIE_PREFIX   = '_sp_variant_';
    private const COOKIE_TTL      = 86400 * 30;  // 30 дней
    private const MONTE_CARLO_N   = 10000;

    private PDO   $db;
    private array $splits;

    public function __construct(PDO $db)
    {
        $this->db     = $db;
        $this->splits = $this->loadSplits();
    }

    // ── Публичный API ──────────────────────────────────────────────────────

    /**
     * Определяет активный тест для данного ГЕО и возвращает вариант.
     * Учитывает cookie для консистентности сессии.
     *
     * @param  string $geo      ISO-2 код
     * @param  string $clickId  ID клика (для записи в split_results)
     * @return array|null       Вариант ['id' => 'var_a', 'template' => '...'] или null
     */
    public function assign(string $geo, string $clickId): ?array
    {
        $split = $this->findActiveSplit($geo);
        if ($split === null) {
            return null;
        }

        // Если уже есть победитель — всегда отдаём его
        if (!empty($split['winner_variant'])) {
            return $this->findVariant($split, $split['winner_variant']);
        }

        $cookieName = self::COOKIE_PREFIX . $split['id'];

        // Консистентность: проверяем cookie
        if (!empty($_COOKIE[$cookieName])) {
            $variantId = $_COOKIE[$cookieName];
            $variant   = $this->findVariant($split, $variantId);
            if ($variant !== null) {
                $this->recordImpression($split['id'], $variantId, $geo, $clickId);
                return $variant;
            }
        }

        // Назначаем вариант по весам
        $variant = $this->pickVariant($split['variants']);
        if ($variant === null) {
            return null;
        }

        // Записываем cookie
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie(
            $cookieName,
            $variant['id'],
            time() + self::COOKIE_TTL,
            '/',
            '',
            $secure,
            true  // HttpOnly
        );

        $this->recordImpression($split['id'], $variant['id'], $geo, $clickId);
        return $variant;
    }

    /**
     * Помечает клик как конверсию в split_results.
     * Вызывается из postback.php при успешной конверсии.
     *
     * @param string $clickId
     */
    public function recordConversion(string $clickId): void
    {
        try {
            $this->db->prepare(
                "UPDATE split_results SET converted = 1 WHERE click_id = ?"
            )->execute([$clickId]);
        } catch (PDOException $e) {
            error_log('[SplitTester] recordConversion ошибка: ' . $e->getMessage());
        }
    }

    /**
     * Запускает Байесовский анализ для одного теста.
     * Если уверенность > threshold → выбирает winner и обновляет splits.json.
     *
     * @param  string $splitId  ID теста
     * @return array|null       ['winner' => 'var_b', 'probability' => 97.3] или null
     */
    public function analyze(string $splitId): ?array
    {
        $split = $this->findSplitById($splitId);
        if ($split === null || ($split['status'] ?? '') !== 'active') {
            return null;
        }

        $stats = $this->getVariantStats($splitId);
        if (empty($stats)) {
            return null;
        }

        $minConv  = (int)($split['min_conversions'] ?? 100);
        $totalConv = array_sum(array_column($stats, 'conversions'));
        if ($totalConv < $minConv) {
            error_log("[SplitTester] [$splitId] Недостаточно конверсий: $totalConv / $minConv");
            return null;
        }

        $probs = $this->bayesianProbabilities($stats);
        if (empty($probs)) {
            return null;
        }

        // Определяем победителя
        arsort($probs);
        $winnerId  = array_key_first($probs);
        $winnerPct = $probs[$winnerId];

        $threshold = (float)($split['confidence_threshold'] ?? 95.0);

        error_log(sprintf(
            "[SplitTester] [%s] Байесовский анализ: winner=%s prob=%.1f%% (порог=%.1f%%)",
            $splitId, $winnerId, $winnerPct, $threshold
        ));

        if ($winnerPct < $threshold) {
            return null;  // Ещё не достаточно уверены
        }

        // Автовыбор победителя
        $this->saveWinner($splitId, $winnerId);

        return ['winner' => $winnerId, 'probability' => $winnerPct, 'stats' => $stats];
    }

    /**
     * Возвращает статистику по вариантам теста.
     *
     * @param  string $splitId
     * @return array  [['variant_id' => 'var_a', 'impressions' => 500, 'conversions' => 15], ...]
     */
    public function getVariantStats(string $splitId): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT variant_id,
                        COUNT(*) AS impressions,
                        SUM(converted) AS conversions
                 FROM split_results
                 WHERE split_id = ?
                 GROUP BY variant_id"
            );
            $stmt->execute([$splitId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[SplitTester] getVariantStats ошибка: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Принудительно устанавливает победителя (через ContentHub UI).
     */
    public function setWinner(string $splitId, string $variantId): void
    {
        $this->saveWinner($splitId, $variantId);
    }

    /**
     * Возвращает все тесты (для ContentHub API).
     */
    public function allSplits(): array
    {
        return $this->splits;
    }

    // ── Приватные методы ───────────────────────────────────────────────────

    private function findActiveSplit(string $geo): ?array
    {
        foreach ($this->splits as $split) {
            if (($split['status'] ?? '') !== 'active') {
                continue;
            }
            $allowedGeos = $split['geo'] ?? [];
            if (!empty($allowedGeos) && !in_array($geo, $allowedGeos, true)) {
                continue;
            }
            return $split;
        }
        return null;
    }

    private function findSplitById(string $id): ?array
    {
        foreach ($this->splits as $split) {
            if ($split['id'] === $id) {
                return $split;
            }
        }
        return null;
    }

    private function findVariant(array $split, string $variantId): ?array
    {
        foreach ($split['variants'] as $v) {
            if ($v['id'] === $variantId) {
                return $v;
            }
        }
        return null;
    }

    /**
     * Выбирает вариант по весам (weighted random).
     */
    private function pickVariant(array $variants): ?array
    {
        if (empty($variants)) {
            return null;
        }

        $total = array_sum(array_column($variants, 'weight'));
        if ($total <= 0) {
            return $variants[array_rand($variants)];
        }

        $rand = mt_rand(1, $total);
        $cumulative = 0;
        foreach ($variants as $v) {
            $cumulative += (int)($v['weight'] ?? 1);
            if ($rand <= $cumulative) {
                return $v;
            }
        }

        return $variants[count($variants) - 1];
    }

    private function recordImpression(
        string $splitId,
        string $variantId,
        string $geo,
        string $clickId
    ): void {
        try {
            $this->db->prepare(
                "INSERT INTO split_results (ts, split_id, variant_id, geo, click_id, converted)
                 VALUES (?, ?, ?, ?, ?, 0)"
            )->execute([time(), $splitId, $variantId, $geo, $clickId]);
        } catch (PDOException $e) {
            error_log('[SplitTester] recordImpression ошибка: ' . $e->getMessage());
        }
    }

    /**
     * Байесовский тест (Монте-Карло).
     * Beta(1 + conversions, 1 + non_conv) для каждого варианта.
     * Возвращает ['var_a' => 60.3, 'var_b' => 39.7] — вероятности быть лучшим (%).
     */
    private function bayesianProbabilities(array $stats): array
    {
        $samples = [];

        foreach ($stats as $row) {
            $varId  = $row['variant_id'];
            $conv   = (int)$row['conversions'];
            $nonConv= (int)$row['impressions'] - $conv;
            $alpha  = 1 + max(0, $conv);
            $beta   = 1 + max(0, $nonConv);

            // Генерируем N сэмплов из Beta(alpha, beta) через Box-Muller + аппроксимацию
            $s = [];
            for ($i = 0; $i < self::MONTE_CARLO_N; $i++) {
                $s[] = $this->sampleBeta($alpha, $beta);
            }
            $samples[$varId] = $s;
        }

        if (count($samples) < 2) {
            return [];
        }

        // Считаем как часто каждый вариант выигрывает
        $wins = array_fill_keys(array_keys($samples), 0);
        $varIds = array_keys($samples);

        for ($i = 0; $i < self::MONTE_CARLO_N; $i++) {
            $maxVal = -1;
            $maxVar = null;
            foreach ($varIds as $vid) {
                if ($samples[$vid][$i] > $maxVal) {
                    $maxVal = $samples[$vid][$i];
                    $maxVar = $vid;
                }
            }
            if ($maxVar !== null) {
                $wins[$maxVar]++;
            }
        }

        return array_map(
            fn($w) => round($w / self::MONTE_CARLO_N * 100, 2),
            $wins
        );
    }

    /**
     * Приближённый сэмплинг из Beta-распределения через метод Johnk.
     * Работает без внешних зависимостей.
     */
    private function sampleBeta(float $alpha, float $beta): float
    {
        // Метод: X = Gamma(alpha) / (Gamma(alpha) + Gamma(beta))
        $x = $this->sampleGamma($alpha);
        $y = $this->sampleGamma($beta);
        if (($x + $y) < 1e-10) {
            return 0.5;
        }
        return $x / ($x + $y);
    }

    /**
     * Сэмплинг из Gamma-распределения (Marsaglia-Tsang).
     */
    private function sampleGamma(float $shape): float
    {
        if ($shape < 1.0) {
            return $this->sampleGamma(1.0 + $shape) * pow(mt_rand() / mt_getrandmax(), 1.0 / $shape);
        }

        $d = $shape - 1.0 / 3.0;
        $c = 1.0 / sqrt(9.0 * $d);

        while (true) {
            do {
                $x = $this->normalRand();
                $v = 1.0 + $c * $x;
            } while ($v <= 0);

            $v = $v * $v * $v;
            $u = mt_rand() / mt_getrandmax();

            if ($u < 1.0 - 0.0331 * ($x * $x) * ($x * $x)) {
                return $d * $v;
            }
            if (log($u) < 0.5 * $x * $x + $d * (1.0 - $v + log($v))) {
                return $d * $v;
            }
        }
    }

    /**
     * Box-Muller нормальное распределение N(0,1).
     */
    private function normalRand(): float
    {
        static $spare = null;
        if ($spare !== null) {
            $r = $spare;
            $spare = null;
            return $r;
        }
        do {
            $u = 2.0 * mt_rand() / mt_getrandmax() - 1.0;
            $v = 2.0 * mt_rand() / mt_getrandmax() - 1.0;
            $s = $u * $u + $v * $v;
        } while ($s >= 1.0 || $s === 0.0);

        $mul   = sqrt(-2.0 * log($s) / $s);
        $spare = $v * $mul;
        return $u * $mul;
    }

    private function saveWinner(string $splitId, string $variantId): void
    {
        // Обновляем splits.json атомарно
        $updated = false;
        foreach ($this->splits as &$split) {
            if ($split['id'] === $splitId) {
                $split['winner_variant'] = $variantId;
                $split['status']         = 'winner_selected';
                $split['decided_at']     = date('c');
                $updated = true;
                break;
            }
        }
        unset($split);

        if ($updated) {
            $this->saveSplits($this->splits);
        }
    }

    private function loadSplits(): array
    {
        if (!file_exists(self::SPLITS_FILE)) {
            return [];
        }
        $raw = file_get_contents(self::SPLITS_FILE);
        if ($raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function saveSplits(array $splits): void
    {
        $tmp = self::SPLITS_FILE . '.tmp.' . uniqid();
        $json = json_encode($splits, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (file_put_contents($tmp, $json) !== false) {
            rename($tmp, self::SPLITS_FILE);
        }
    }
}
