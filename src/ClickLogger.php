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
     * Записывает клик, возвращает click_id.
     *
     * @param array  $context  Данные клика
     * @param string $status   sent | bot | cloaked
     * @return string          click_id
     */
    public function log(array $context, string $status = 'sent'): string
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
        } catch (Throwable $e) {
            error_log('[PreLend][ClickLogger] ' . $e->getMessage());
            // Не прерываем выполнение — редирект важнее лога
        }

        return $clickId;
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
            'ip'            => self::getRealIp(),
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

    private static function getRealIp(): string
    {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
}
