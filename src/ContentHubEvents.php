<?php
/**
 * Опциональная отправка системных событий в ContentHub (POST /api/events).
 * Переменные окружения: CONTENTHUB_URL, CONTENTHUB_INTERNAL_EVENTS_KEY
 */
declare(strict_types=1);

final class ContentHubEvents
{
    public static function pushConversion(string $advId, string $clickId, string $convId): void
    {
        $base = getenv('CONTENTHUB_URL') ?: '';
        $key  = getenv('CONTENTHUB_INTERNAL_EVENTS_KEY') ?: '';
        if ($base === '' || $key === '') {
            return;
        }
        $url = rtrim($base, '/') . '/api/events';
        $body = json_encode([
            'source'      => 'prelend',
            'event_type'  => 'conversion',
            'payload'     => [
                'advertiser_id' => $advId,
                'click_id'      => $clickId,
                'conv_id'       => $convId,
            ],
        ], JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return;
        }
        if (!function_exists('curl_init')) {
            return;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Internal-Events-Key: ' . $key,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
