<?php
/**
 * SubIdBuilder.php
 *
 * Формирует финальный URL редиректа:
 *   1. Берёт базовый URL рекламодателя
 *   2. Подставляет click_id в параметр SubID (sub1 / subid / s1 — из конфига)
 *   3. Прокидывает все входящие UTM-метки и кастомные параметры
 *   4. Добавляет geo и platform для аналитики (опционально)
 */
class SubIdBuilder
{
    // Параметры, которые НЕ прокидываем рекламодателю (служебные)
    private const SKIP_PARAMS = ['click_id', 'test'];

    // ── Публичный API ──────────────────────────────────────────────────────

    /**
     * Строит финальный URL.
     *
     * @param array  $advertiser  Конфиг рекламодателя
     * @param string $clickId     Сгенерированный click_id
     * @return string             Готовый URL для редиректа
     */
    public static function build(array $advertiser, string $clickId): string
    {
        $base       = $advertiser['url'] ?? '';
        $subParam   = $advertiser['subid_param'] ?? 'subid';

        if ($base === '') {
            return '';
        }

        // Разбираем base URL
        $parsed  = parse_url($base);
        $baseUrl = ($parsed['scheme'] ?? 'https') . '://'
                 . ($parsed['host'] ?? '')
                 . ($parsed['path'] ?? '');

        // Существующие параметры base URL
        $baseParams = [];
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $baseParams);
        }

        // SubID — click_id
        $baseParams[$subParam] = $clickId;

        // UTM и кастомные параметры из входящего запроса
        foreach ($_GET as $key => $value) {
            $key = preg_replace('/[^a-zA-Z0-9_\-]/', '', $key); // санитизация
            if ($key === '' || in_array($key, self::SKIP_PARAMS, true)) {
                continue;
            }
            // Не перезаписываем уже заданный SubID
            if ($key === $subParam) {
                continue;
            }
            $baseParams[$key] = self::sanitizeValue($value);
        }

        return $baseUrl . '?' . http_build_query($baseParams);
    }

    /**
     * Строит URL дефолтного оффера (без SubID рекламодателя).
     * Прокидывает только UTM-метки.
     */
    public static function buildDefault(string $defaultUrl): string
    {
        if ($defaultUrl === '') {
            return '';
        }

        $parsed  = parse_url($defaultUrl);
        $baseUrl = ($parsed['scheme'] ?? 'https') . '://'
                 . ($parsed['host'] ?? '')
                 . ($parsed['path'] ?? '');

        $params = [];
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $params);
        }

        // Прокидываем только UTM
        $utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
        foreach ($utmKeys as $key) {
            if (!empty($_GET[$key])) {
                $params[$key] = self::sanitizeValue($_GET[$key]);
            }
        }

        $query = http_build_query($params);
        return $query ? $baseUrl . '?' . $query : $baseUrl;
    }

    // ── Приватные методы ──────────────────────────────────────────────────

    private static function sanitizeValue(string $value): string
    {
        // Убираем управляющие символы, ограничиваем длину
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value);
        return substr($value, 0, 256);
    }
}
