<?php
/**
 * GeoAdapter.php — Гео-адаптивный контекст для прелендингов.
 *
 * Читает config/geo_data.json и предоставляет переменные для шаблонов:
 *   {{CURRENCY}}        → символ валюты (₴, ₽, €...)
 *   {{CURRENCY_NAME}}   → название валюты ("гривня", "рублей"...)
 *   {{CITY}}            → локальный город ("Київ", "Москва"...)
 *   {{REVIEWER}}        → локальное имя рецензента ("Олексій"...)
 *   {{PHONE_PREFIX}}    → телефонный префикс (+380, +7...)
 *   {{LANG}}            → код языка (uk, ru, en, pl...)
 *   {{GEO}}             → ISO-2 код страны
 *
 * Использование в TemplateRenderer:
 *   $geo = (new GeoDetector())->getGeo();
 *   $ctx = GeoAdapter::context($geo);
 *   TemplateRenderer::renderOffer($template, $url, 1500, $ctx);
 *
 * В PHP-шаблоне переменные доступны напрямую:
 *   <?= $geo_currency ?>  <!-- вместо {{CURRENCY}} -->
 *   <?= $geo_city ?>
 */

class GeoAdapter
{
    private const GEO_DATA_FILE = ROOT . '/config/geo_data.json';
    private static ?array $data = null;

    /**
     * Возвращает массив гео-переменных для переданного ISO-2 кода.
     * Все ключи имеют префикс `geo_` для предотвращения конфликтов с другими vars.
     *
     * @param  string $geo  ISO-2 код (например, "UA", "RU")
     * @return array        ['geo_currency' => '₴', 'geo_city' => 'Київ', ...]
     */
    public static function context(string $geo): array
    {
        $geoData = self::load();
        $geo     = strtoupper(trim($geo));

        $entry = $geoData[$geo] ?? $geoData['_default'] ?? [];

        return [
            'geo'              => $geo,
            'geo_currency'     => $entry['currency']      ?? '$',
            'geo_currency_name'=> $entry['currency_name'] ?? 'dollars',
            'geo_city'         => $entry['city']          ?? '',
            'geo_reviewer'     => $entry['reviewer']      ?? 'Alex',
            'geo_phone_prefix' => $entry['phone_prefix']  ?? '+1',
            'geo_lang'         => $entry['lang']          ?? 'en',
        ];
    }

    /**
     * Подставляет гео-плейсхолдеры в шаблонную строку.
     *
     * Поддерживаемые плейсхолдеры:
     *   {{CURRENCY}}, {{CURRENCY_NAME}}, {{CITY}}, {{REVIEWER}},
     *   {{PHONE_PREFIX}}, {{LANG}}, {{GEO}}
     *
     * @param  string $text  Шаблонная строка
     * @param  string $geo   ISO-2 код
     * @return string
     */
    public static function replace(string $text, string $geo): string
    {
        $ctx = self::context($geo);

        $map = [
            '{{CURRENCY}}'      => $ctx['geo_currency'],
            '{{CURRENCY_NAME}}' => $ctx['geo_currency_name'],
            '{{CITY}}'          => $ctx['geo_city'],
            '{{REVIEWER}}'      => $ctx['geo_reviewer'],
            '{{PHONE_PREFIX}}'  => $ctx['geo_phone_prefix'],
            '{{LANG}}'          => $ctx['geo_lang'],
            '{{GEO}}'           => $ctx['geo'],
        ];

        return str_replace(array_keys($map), array_values($map), $text);
    }

    /**
     * Возвращает все страны из geo_data.json (для ContentHub UI).
     * Исключает ключ _default.
     *
     * @return array  ['UA' => [...], 'RU' => [...], ...]
     */
    public static function allCountries(): array
    {
        $data = self::load();
        return array_filter($data, fn($k) => $k !== '_default', ARRAY_FILTER_USE_KEY);
    }

    // ── Приватные ──────────────────────────────────────────────────────────

    private static function load(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        if (!file_exists(self::GEO_DATA_FILE)) {
            error_log('[PreLend][GeoAdapter] geo_data.json не найден: ' . self::GEO_DATA_FILE);
            self::$data = [];
            return self::$data;
        }

        $raw = file_get_contents(self::GEO_DATA_FILE);
        if ($raw === false) {
            error_log('[PreLend][GeoAdapter] Не удалось прочитать geo_data.json');
            self::$data = [];
            return self::$data;
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[PreLend][GeoAdapter] JSON ошибка: ' . json_last_error_msg());
            self::$data = [];
            return self::$data;
        }

        self::$data = $decoded;
        return self::$data;
    }
}
