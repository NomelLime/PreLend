<?php
declare(strict_types=1);
/**
 * GeoAdapter — IP2Location + Accept-Language rescue.
 *
 * Порядок:
 *   1) IP2Location BIN (локально, без сети; при наличии библиотеки)
 *   2) Регион из Accept-Language (последний rescue)
 */
final class GeoAdapter
{
    /** @var object|null */
    private static $ip2Reader = null;

    private static function ip2DbPath(): string
    {
        return dirname(__DIR__) . '/data/IP2LOCATION-LITE-DB1.BIN';
    }

    public static function resolveGeo(string $ip): string
    {
        self::ensureComposerAutoload();

        $geo = self::lookupIp2Location($ip);
        if ($geo !== 'XX') {
            return $geo;
        }

        return self::lookupAcceptLanguageRegion();
    }

    private static function ensureComposerAutoload(): void
    {
        static $autoloaded = false;
        if ($autoloaded) {
            return;
        }
        $autoloaded = true;

        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }

    private static function lookupIp2Location(string $ip): string
    {
        $path = self::ip2DbPath();
        if (!is_file($path)) {
            return 'XX';
        }
        if (!class_exists(\IP2Location\Database::class)) {
            return 'XX';
        }

        try {
            if (self::$ip2Reader === null) {
                // FILE_IO предпочтительнее для частых lookups.
                self::$ip2Reader = new \IP2Location\Database($path, \IP2Location\Database::FILE_IO);
            }

            $mode = defined('\IP2Location\Database::ALL')
                ? \IP2Location\Database::ALL
                : \IP2Location\Database::COUNTRY_CODE;
            $record = self::$ip2Reader->lookup($ip, $mode);

            $country = '';
            if (is_array($record)) {
                $country = (string) ($record['countryCode'] ?? $record['country_code'] ?? '');
            } elseif (is_string($record)) {
                $country = $record;
            }

            $country = strtoupper(trim($country));
            if (preg_match('/^[A-Z]{2}$/', $country)) {
                return $country;
            }
            return 'XX';
        } catch (Throwable $e) {
            return 'XX';
        }
    }

    private static function lookupAcceptLanguageRegion(): string
    {
        $header = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        if ($header === '') {
            return 'XX';
        }

        $first = explode(',', $header)[0] ?? '';
        $first = trim(explode(';', $first)[0] ?? '');
        if ($first === '') {
            return 'XX';
        }

        $parts = explode('-', $first, 2);
        if (count($parts) < 2) {
            return 'XX';
        }

        $region = strtoupper(trim($parts[1]));
        if (preg_match('/^[A-Z]{2}$/', $region)) {
            return $region;
        }
        return 'XX';
    }

}
