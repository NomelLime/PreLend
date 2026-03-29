<?php
declare(strict_types=1);
/**
 * Router.php
 *
 * Выбирает лучшего рекламодателя для данного ГЕО по формуле скоринга:
 *   Score = Ставка × Uptime_коэф × (1 - Shave_коэф)
 *
 * Fallback: если лучший недоступен — переходит к следующему по Score.
 * Если ни один не подходит — возвращает null (→ дефолтный оффер).
 */
class Router
{
    private array  $advertisers;
    private array  $settings;
    private PDO    $db;

    /** @var array<string, array> Кэш landing_status (preloaded) */
    private array $statusCache = [];
    /** @var array<string, float> Кэш shave_cache (preloaded) */
    private array $shaveCacheMap = [];
    private bool  $cacheLoaded = false;

    public function __construct(array $advertisers, array $settings, PDO $db)
    {
        $this->advertisers = $advertisers;
        $this->settings    = $settings;
        $this->db          = $db;
    }

    // ── Публичный API ──────────────────────────────────────────────────────

    /**
     * Подбирает рекламодателя.
     *
     * @param string    $geo        ISO-2 код ГЕО
     * @param string    $device     mobile | desktop | tablet
     * @param BotFilter $filter     Уже инициализированный фильтр (для проверки device/time)
     * @return array|null           Конфиг рекламодателя или null
     */
    public function resolve(string $geo, string $device, BotFilter $filter): ?array
    {
        // [FIX] Preload: 2 SQL-запроса вместо O(N*3).
        // При 10 рекламодателях: было ~30 запросов → стало 2.
        $this->preloadCaches();

        $candidates = $this->filterCandidates($geo, $device, $filter);

        if (empty($candidates)) {
            return null;
        }

        // Сортируем по убыванию Score
        usort($candidates, fn($a, $b) => $b['_score'] <=> $a['_score']);

        // Берём первого доступного (is_up = 1)
        foreach ($candidates as $adv) {
            if ($this->isUp($adv['id'])) {
                return $adv;
            }
        }

        return null; // Все рекламодатели для этого ГЕО недоступны
    }

    /** Возвращает Score конкретного рекламодателя */
    public function getScore(string $advId, float $rate): float
    {
        $status = $this->getLandingStatus($advId);
        $uptime = $status['uptime_24h'] ?? 100.0;
        $shave  = $this->getShaveCoef($advId);

        $minUptime = $this->settings['scoring']['min_uptime_to_activate'] ?? 50.0;

        if ($uptime < $minUptime) {
            return 0.0;
        }

        return round($rate * ($uptime / 100) * (1 - $shave), 4);
    }

    // ── Приватные методы ──────────────────────────────────────────────────

    /**
     * [FIX] Предзагрузка landing_status и shave_cache одним batch.
     * Вызывается один раз в начале resolve().
     */
    private function preloadCaches(): void
    {
        if ($this->cacheLoaded) {
            return;
        }
        $this->cacheLoaded = true;

        try {
            $stmt = $this->db->query(
                'SELECT advertiser_id, is_up, uptime_24h FROM landing_status'
            );
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->statusCache[$row['advertiser_id']] = $row;
            }
        } catch (Throwable $e) {
            error_log('[PreLend][Router] preload landing_status: ' . $e->getMessage());
        }

        try {
            $stmt = $this->db->query(
                'SELECT advertiser_id, shave_coef FROM shave_cache'
            );
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->shaveCacheMap[$row['advertiser_id']] = (float) $row['shave_coef'];
            }
        } catch (Throwable $e) {
            error_log('[PreLend][Router] preload shave_cache: ' . $e->getMessage());
        }
    }

    private function filterCandidates(string $geo, string $device, BotFilter $filter): array
    {
        $result = [];

        foreach ($this->advertisers as $adv) {
            // Статус активен
            if (($adv['status'] ?? '') !== 'active') {
                continue;
            }

            // ГЕО совпадает
            $allowedGeos = $adv['geo'] ?? [];
            if (!empty($allowedGeos) && !in_array($geo, $allowedGeos, true)) {
                continue;
            }

            // Device / time фильтры
            if (!$this->passesAdvFilters($adv, $device)) {
                continue;
            }

            // Считаем Score
            $score      = $this->getScore($adv['id'], (float)($adv['rate'] ?? 0));
            $adv['_score'] = $score;
            $result[]   = $adv;
        }

        return $result;
    }

    /**
     * Проверяет device и time без повторного запуска всего BotFilter.
     */
    private function passesAdvFilters(array $adv, string $device = ''): bool
    {
        // Device
        $allowed = $adv['device'] ?? [];
        if (!empty($allowed)) {
            // Используем явно переданный $device; если не передан — определяем из UA
            if ($device === '') {
                $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
                if (preg_match('/tablet|ipad/i', $ua)) {
                    $device = 'tablet';
                } elseif (preg_match('/mobile|android|iphone|ipod/i', $ua)) {
                    $device = 'mobile';
                } else {
                    $device = 'desktop';
                }
            }
            if (!in_array($device, $allowed, true)) {
                return false;
            }
        }

        // Time
        $from = $adv['time_from'] ?? '';
        $to   = $adv['time_to']   ?? '';
        if ($from !== '' && $to !== '') {
            $now     = (int) date('Hi');
            $fromInt = (int) str_replace(':', '', $from);
            $toInt   = (int) str_replace(':', '', $to);
            if ($fromInt <= $toInt) {
                if (!($now >= $fromInt && $now <= $toInt)) {
                    return false;
                }
            } else {
                if (!($now >= $fromInt || $now <= $toInt)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function isUp(string $advId): bool
    {
        $status = $this->getLandingStatus($advId);
        return (bool)($status['is_up'] ?? true);
    }

    private function getLandingStatus(string $advId): array
    {
        // [FIX] Используем preloaded кэш вместо SQL-запроса на каждого кандидата
        if (isset($this->statusCache[$advId])) {
            return $this->statusCache[$advId];
        }

        // Fallback: единичный запрос если кэш не загружен (вызов getScore() напрямую)
        try {
            $stmt = $this->db->prepare(
                'SELECT is_up, uptime_24h FROM landing_status WHERE advertiser_id = ? LIMIT 1'
            );
            $stmt->execute([$advId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: ['is_up' => 1, 'uptime_24h' => 100.0];
        } catch (Throwable $e) {
            error_log('[PreLend][Router] getLandingStatus: ' . $e->getMessage());
            return ['is_up' => 1, 'uptime_24h' => 100.0];
        }
    }

    /**
     * Shave-коэффициент из таблицы shave_cache (пересчёт cron / Internal API).
     */
    private function getShaveCoef(string $advId): float
    {
        // [FIX] Используем preloaded кэш вместо SQL-запроса на каждого кандидата
        if (isset($this->shaveCacheMap[$advId])) {
            return $this->shaveCacheMap[$advId];
        }

        // Fallback: единичный запрос если кэш не загружен
        try {
            $stmt = $this->db->prepare(
                'SELECT shave_coef FROM shave_cache WHERE advertiser_id = ? LIMIT 1'
            );
            $stmt->execute([$advId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (float) $row['shave_coef'] : 0.0;
        } catch (Throwable $e) {
            error_log('[PreLend][Router] getShaveCoef: ' . $e->getMessage());
            return 0.0;
        }
    }
}
