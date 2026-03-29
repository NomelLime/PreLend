<?php
declare(strict_types=1);
/**
 * ClickLogger.php
 *
 * Записывает клик в таблицу clicks.
 * Генерирует click_id (UUID v4).
 * Строит fingerprint SHA256 для дедупликации.
 */
class ClickLogger
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Публичный API ──────────────────────────────────────────────────────

    /**
     * Записывает клик. Возвращает массив {click_id, ok}.
     *
     * Если INSERT упал — ok=false, click_id сгенерирован но не сохранён в БД.
     * Вызывающий код должен проверять ok перед передачей click_id рекламодателю.
     *
     * @param array  $context  Данные клика
     * @param string $status   sent | bot | cloaked
     * @return array{click_id: string, ok: bool}
     */
    public function log(array $context, string $status = 'sent'): array
    {
        $clickId = $this->generateUUID();

        try {
            $stmt = $this->db->prepare("
                INSERT INTO clicks (
                    click_id, ts, ip, geo, device, platform, advertiser_id,
                    utm_source, utm_medium, utm_campaign, utm_content, utm_term,
                    ua_hash, referer, is_test, status
                ) VALUES (
                    :click_id, :ts, :ip, :geo, :device, :platform, :advertiser_id,
                    :utm_source, :utm_medium, :utm_campaign, :utm_content, :utm_term,
                    :ua_hash, :referer, :is_test, :status
                )
            ");

            $stmt->execute([
                ':click_id'      => $clickId,
                ':ts'            => $context['ts']            ?? time(),
                ':ip'            => $context['ip']            ?? '',
                ':geo'           => $context['geo']           ?? 'XX',
                ':device'        => $context['device']        ?? 'unknown',
                ':platform'      => $context['platform']      ?? 'direct',
                ':advertiser_id' => $context['advertiser_id'] ?? null,
                ':utm_source'    => $context['utm_source']    ?? null,
                ':utm_medium'    => $context['utm_medium']    ?? null,
                ':utm_campaign'  => $context['utm_campaign']  ?? null,
                ':utm_content'   => $context['utm_content']   ?? null,
                ':utm_term'      => $context['utm_term']      ?? null,
                ':ua_hash'       => $context['ua_hash']       ?? null,
                ':referer'       => $context['referer']       ?? null,
                ':is_test'       => $context['is_test']       ?? 0,
                ':status'        => $status,
            ]);
            return ['click_id' => $clickId, 'ok' => true];
        } catch (Throwable $e) {
            error_log('[PreLend][ClickLogger] ' . $e->getMessage());
            return ['click_id' => $clickId, 'ok' => false];
        }
    }

    /**
     * Повторный клик с тем же IP+UA в окне TTL — возвращает существующий click_id.
     */
    public function isDuplicateFingerprint(string $ip, string $uaHash, int $ttlSec = 60): ?string
    {
        $fpHash = hash('sha256', $ip . $uaHash);
        $since  = time() - $ttlSec;

        $stmt = $this->db->prepare(
            'SELECT click_id FROM click_fingerprints WHERE fp_hash = ? AND created_at >= ?'
        );
        $stmt->execute([$fpHash, $since]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (string) $row['click_id'] : null;
    }

    public function recordFingerprint(string $ip, string $uaHash, string $clickId): void
    {
        $fpHash = hash('sha256', $ip . $uaHash);
        $stmt   = $this->db->prepare(
            'INSERT OR REPLACE INTO click_fingerprints (fp_hash, click_id, created_at)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$fpHash, $clickId, time()]);
    }

    /**
     * Атомарная проверка дедупликации + запись клика + fingerprint.
     *
     * [FIX] Race condition: без транзакции между isDuplicateFingerprint() и
     * recordFingerprint() есть окно, в котором параллельный запрос создаст дубль.
     * Теперь вся цепочка внутри IMMEDIATE-транзакции (SQLite serializes writes).
     *
     * @return array{click_id: string, ok: bool, is_duplicate: bool}
     */
    public function logWithDedup(
        array  $context,
        string $status,
        string $ip,
        string $uaHash,
        int    $ttlSec = 60
    ): array {
        $fpHash = hash('sha256', $ip . $uaHash);
        $since  = time() - $ttlSec;

        try {
            $this->db->exec('BEGIN IMMEDIATE');

            // 1. Проверяем fingerprint внутри транзакции
            $stmt = $this->db->prepare(
                'SELECT click_id FROM click_fingerprints WHERE fp_hash = ? AND created_at >= ?'
            );
            $stmt->execute([$fpHash, $since]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $this->db->exec('COMMIT');
                return [
                    'click_id'     => (string) $existing['click_id'],
                    'ok'           => true,
                    'is_duplicate' => true,
                ];
            }

            // 2. Записываем клик
            $clickId = $this->generateUUID();
            $insertStmt = $this->db->prepare("
                INSERT INTO clicks (
                    click_id, ts, ip, geo, device, platform, advertiser_id,
                    utm_source, utm_medium, utm_campaign, utm_content, utm_term,
                    ua_hash, referer, is_test, status
                ) VALUES (
                    :click_id, :ts, :ip, :geo, :device, :platform, :advertiser_id,
                    :utm_source, :utm_medium, :utm_campaign, :utm_content, :utm_term,
                    :ua_hash, :referer, :is_test, :status
                )
            ");
            $insertStmt->execute([
                ':click_id'      => $clickId,
                ':ts'            => $context['ts']            ?? time(),
                ':ip'            => $context['ip']            ?? '',
                ':geo'           => $context['geo']           ?? 'XX',
                ':device'        => $context['device']        ?? 'unknown',
                ':platform'      => $context['platform']      ?? 'direct',
                ':advertiser_id' => $context['advertiser_id'] ?? null,
                ':utm_source'    => $context['utm_source']    ?? null,
                ':utm_medium'    => $context['utm_medium']    ?? null,
                ':utm_campaign'  => $context['utm_campaign']  ?? null,
                ':utm_content'   => $context['utm_content']   ?? null,
                ':utm_term'      => $context['utm_term']      ?? null,
                ':ua_hash'       => $context['ua_hash']       ?? null,
                ':referer'       => $context['referer']       ?? null,
                ':is_test'       => $context['is_test']       ?? 0,
                ':status'        => $status,
            ]);

            // 3. Записываем fingerprint
            $fpStmt = $this->db->prepare(
                'INSERT OR REPLACE INTO click_fingerprints (fp_hash, click_id, created_at)
                 VALUES (?, ?, ?)'
            );
            $fpStmt->execute([$fpHash, $clickId, time()]);

            $this->db->exec('COMMIT');
            return ['click_id' => $clickId, 'ok' => true, 'is_duplicate' => false];

        } catch (Throwable $e) {
            try { $this->db->exec('ROLLBACK'); } catch (Throwable $_) {}
            error_log('[PreLend][ClickLogger] logWithDedup: ' . $e->getMessage());
            // Fallback: генерируем click_id но без fingerprint (не потеряем клик)
            return ['click_id' => $this->generateUUID(), 'ok' => false, 'is_duplicate' => false];
        }
    }

    // ── Вспомогательные методы ────────────────────────────────────────────

    /**
     * Собирает context из текущего HTTP-запроса.
     * Используется в index.php перед вызовом log().
     */
    public static function buildContext(
        GeoDetector $geo,
        BotFilter   $filter,
        ?array      $advertiser,
        bool        $isTest = false
    ): array {
        return [
            'ts'            => time(),
            'ip'            => GeoDetector::getRealIp(),
            'geo'           => $geo->getGeo(),
            'device'        => $filter->getDeviceType(),
            'platform'      => $filter->getPlatform(),
            'advertiser_id' => $advertiser['id'] ?? null,
            'utm_source'    => $_GET['utm_source']   ?? null,
            'utm_medium'    => $_GET['utm_medium']   ?? null,
            'utm_campaign'  => $_GET['utm_campaign'] ?? null,
            'utm_content'   => $_GET['utm_content']  ?? null,
            'utm_term'      => $_GET['utm_term']     ?? null,
            'ua_hash'       => $filter->getUaHash(),
            'referer'       => $_SERVER['HTTP_REFERER'] ?? null,
            'is_test'       => $isTest ? 1 : 0,
        ];
    }

    private function generateUUID(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

}
