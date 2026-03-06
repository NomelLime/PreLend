<?php
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

            // Device / time фильтры (передаём BotFilter)
            $checkResult = $filter->check(new GeoDetector(), $adv);
            // Если фильтр вернул не PASS — этот рекламодатель не подходит
            // Но сам PASS мог уже быть установлен раньше; пересчёт только для adv-фильтров
            if (!$this->passesAdvFilters($adv)) {
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
    private function passesAdvFilters(array $adv): bool
    {
        // Device
        $allowed = $adv['device'] ?? [];
        if (!empty($allowed)) {
            $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
            if (preg_match('/tablet|ipad/i', $ua)) {
                $dt = 'tablet';
            } elseif (preg_match('/mobile|android|iphone|ipod/i', $ua)) {
                $dt = 'mobile';
            } else {
                $dt = 'desktop';
            }
            if (!in_array($dt, $allowed, true)) {
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
     * Shave-коэффициент рекламодателя.
     * Сравниваем CR рекламодателя с медианой по всем рекламодателям для данного ГЕО.
     * Если данных нет — считаем shave = 0.
     */
    private function getShaveCoef(string $advId): float
    {
        try {
            // Клики за последние 7 дней
            $since = time() - 7 * 86400;

            // CR рекламодателя
            $stmt = $this->db->prepare("
                SELECT
                    (SELECT COUNT(*) FROM conversions WHERE advertiser_id = ? AND created_at >= ?) * 1.0
                    / NULLIF((SELECT COUNT(*) FROM clicks WHERE advertiser_id = ? AND ts >= ? AND status = 'sent'), 0)
            ");
            $stmt->execute([$advId, $since, $advId, $since]);
            $crAdv = (float)($stmt->fetchColumn() ?? 0.0);

            if ($crAdv === 0.0) {
                return 0.0; // нет данных — не штрафуем
            }

            // Медиана CR по всем активным рекламодателям
            $stmt2 = $this->db->prepare("
                SELECT advertiser_id,
                       (SELECT COUNT(*) FROM conversions c WHERE c.advertiser_id = cl.advertiser_id AND c.created_at >= ?) * 1.0
                       / NULLIF(COUNT(*), 0) AS cr
                FROM clicks cl
                WHERE cl.ts >= ? AND cl.status = 'sent'
                GROUP BY cl.advertiser_id
                HAVING cr > 0
            ");
            $stmt2->execute([$since, $since]);
            $crs = array_column($stmt2->fetchAll(PDO::FETCH_ASSOC), 'cr');

            if (empty($crs)) {
                return 0.0;
            }

            sort($crs);
            $mid    = (int)(count($crs) / 2);
            $median = count($crs) % 2 === 0
                ? ($crs[$mid - 1] + $crs[$mid]) / 2
                : $crs[$mid];

            if ($median <= 0) {
                return 0.0;
            }

            $shave = max(0.0, ($median - $crAdv) / $median);
            return min($shave, 1.0); // не больше 1
        } catch (Throwable $e) {
            error_log('[PreLend][Router] getShaveCoef: ' . $e->getMessage());
            return 0.0;
        }
    }
}
