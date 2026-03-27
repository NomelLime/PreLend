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

    /** Кеш shave: один расчёт медианы CR и карта CR по adv на запрос (см. getShaveCoef). */
    private ?int   $shaveCacheSince = null;
    private float  $shaveMedianCr   = 0.0;
    /** @var array<string, float> */
    private array  $shaveCrByAdv    = [];

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
     *
     * Медиана и карта CR по adv считаются один раз на запрос (см. ensureShaveCaches).
     */
    private function getShaveCoef(string $advId): float
    {
        try {
            $since = time() - 7 * 86400;
            $this->ensureShaveCaches($since);

            $crAdv = $this->shaveCrByAdv[$advId] ?? 0.0;
            if ($crAdv === 0.0) {
                return 0.0;
            }

            $median = $this->shaveMedianCr;
            if ($median <= 0) {
                return 0.0;
            }

            $shave = max(0.0, ($median - $crAdv) / $median);
            return min($shave, 1.0);
        } catch (Throwable $e) {
            error_log('[PreLend][Router] getShaveCoef: ' . $e->getMessage());
            return 0.0;
        }
    }

    /**
     * Один запрос за медианой CR и картой CR по advertiser_id на окно 7 дней.
     */
    private function ensureShaveCaches(int $since): void
    {
        if ($this->shaveCacheSince === $since) {
            return;
        }
        $this->shaveCacheSince = $since;
        $this->shaveMedianCr   = 0.0;
        $this->shaveCrByAdv    = [];

        $stmt = $this->db->prepare("
            SELECT cl.advertiser_id AS aid,
                   (SELECT COUNT(*) FROM conversions c WHERE c.advertiser_id = cl.advertiser_id AND c.created_at >= ?) * 1.0
                   / NULLIF(COUNT(*), 0) AS cr
            FROM clicks cl
            WHERE cl.ts >= ? AND cl.status = 'sent'
            GROUP BY cl.advertiser_id
        ");
        $stmt->execute([$since, $since]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $aid = (string) $row['aid'];
            $cr  = (float) $row['cr'];
            $this->shaveCrByAdv[$aid] = $cr;
        }

        $crs = array_values(array_filter(
            $this->shaveCrByAdv,
            static fn(float $v): bool => $v > 0.0
        ));
        if ($crs === []) {
            return;
        }

        sort($crs);
        $n    = count($crs);
        $mid  = (int) ($n / 2);
        $this->shaveMedianCr = $n % 2 === 0
            ? ($crs[$mid - 1] + $crs[$mid]) / 2
            : $crs[$mid];
    }
}
