<?php
declare(strict_types=1);
/**
 * GeoAdapter — CF-IPCountry с fallback на MaxMind GeoLite2 (MMDB).
 *
 * База: data/GeoLite2-Country.mmdb (скачать с maxmind.com, бесплатная лицензия).
 * PHP: расширение maxminddb или пакет maxmind-db/reader через Composer.
 */
final class GeoAdapter
{
    /** @var \MaxMind\Db\Reader|null */
    private static $reader = null;

    private static function dbPath(): string
    {
        return dirname(__DIR__) . '/data/GeoLite2-Country.mmdb';
    }

    public static function resolveGeo(string $ip): string
    {
        $cfGeo = trim($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '');
        if ($cfGeo !== '' && $cfGeo !== 'XX') {
            return strtoupper($cfGeo);
        }

        return self::lookupMaxMind($ip);
    }

    private static function lookupMaxMind(string $ip): string
    {
        $path = self::dbPath();
        if (!is_file($path)) {
            return 'XX';
        }
        try {
            if (self::$reader === null) {
                if (!class_exists(\MaxMind\Db\Reader::class)) {
                    return 'XX';
                }
                self::$reader = new \MaxMind\Db\Reader($path);
            }
            $record = self::$reader->get($ip);
            if (!is_array($record)) {
                return 'XX';
            }
            $iso = $record['country']['iso_code'] ?? 'XX';

            return strtoupper((string) $iso);
        } catch (Throwable $e) {
            return 'XX';
        }
    }
}
