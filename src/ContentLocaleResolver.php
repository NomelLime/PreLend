<?php
declare(strict_types=1);
/**
 * Язык преленда по GEO (CF-IPCountry) с fallback на Accept-Language, затем en-US.
 *
 * Политика:
 *   - известная страна из карты → BCP-47 локаль;
 *   - страна из списка LATAM без отдельной строки в карте → es-419;
 *   - неизвестная страна (нет в карте и не LATAM) → en-US;
 *   - XX / T1 / пусто → Accept-Language по таблице → иначе en-US.
 */
class ContentLocaleResolver
{
    private const CONFIG_FILE = ROOT . '/config/content_locale_map.json';

    /** @var array<string, mixed>|null */
    private static ?array $config = null;

    /**
     * @return array{content_locale: string, locale_lang: string, resolve_source: string}
     */
    public static function resolve(GeoDetector $geo): array
    {
        $cfg    = self::loadConfig();
        $def    = (string)($cfg['default'] ?? 'en-US');
        $cc     = strtoupper($geo->getGeo());
        $accept = strtolower($geo->getAcceptLanguagePrimary());

        $countries = $cfg['countries'] ?? [];
        if (!is_array($countries)) {
            $countries = [];
        }
        $latam = $cfg['latam_spanish'] ?? [];
        if (!is_array($latam)) {
            $latam = [];
        }
        $latam = array_map('strtoupper', $latam);
        $alMap = $cfg['accept_language'] ?? [];
        if (!is_array($alMap)) {
            $alMap = [];
        }

        if ($cc !== '' && $cc !== 'XX' && $cc !== 'T1') {
            if (isset($countries[$cc]) && is_string($countries[$cc])) {
                $loc = $countries[$cc];
                return self::pack($loc, 'country');
            }
            if (in_array($cc, $latam, true)) {
                return self::pack('es-419', 'country_latam');
            }
            return self::pack($def, 'country_unknown');
        }

        // XX / T1 / пусто → Accept-Language
        if ($accept !== '' && isset($alMap[$accept]) && is_string($alMap[$accept])) {
            return self::pack($alMap[$accept], 'accept_language');
        }

        return self::pack($def, 'default');
    }

    /**
     * @return array{content_locale: string, locale_lang: string, resolve_source: string}
     */
    private static function pack(string $contentLocale, string $source): array
    {
        $parts = explode('-', $contentLocale, 2);
        $lang  = strtolower($parts[0] ?? 'en');

        return [
            'content_locale'  => $contentLocale,
            'locale_lang'     => $lang,
            'resolve_source'  => $source,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadConfig(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        if (!file_exists(self::CONFIG_FILE)) {
            error_log('[PreLend][ContentLocaleResolver] Файл не найден: ' . self::CONFIG_FILE);
            self::$config = [
                'countries'         => [],
                'latam_spanish'     => [],
                'accept_language'   => ['en' => 'en-US'],
                'default'           => 'en-US',
            ];
            return self::$config;
        }

        $raw = file_get_contents(self::CONFIG_FILE);
        if ($raw === false) {
            self::$config = ['countries' => [], 'latam_spanish' => [], 'accept_language' => ['en' => 'en-US'], 'default' => 'en-US'];
            return self::$config;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            error_log('[PreLend][ContentLocaleResolver] Невалидный JSON');
            self::$config = ['countries' => [], 'latam_spanish' => [], 'accept_language' => ['en' => 'en-US'], 'default' => 'en-US'];
            return self::$config;
        }

        self::$config = $decoded;
        return self::$config;
    }
}
