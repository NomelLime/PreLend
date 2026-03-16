<?php
declare(strict_types=1);
/**
 * Config.php — загрузчик конфигов с кешем в памяти
 */
class Config
{
    private static array $cache = [];

    public static function get(string $name): array
    {
        if (!isset(self::$cache[$name])) {
            $path = __DIR__ . '/../config/' . $name . '.json';
            if (!file_exists($path)) {
                error_log('[PreLend][Config] File not found: ' . $path);
                return [];
            }
            $data = json_decode(file_get_contents($path), true);
            if ($data === null) {
                error_log('[PreLend][Config] JSON parse error: ' . $path);
                return [];
            }
            self::$cache[$name] = $data;
        }

        return self::$cache[$name];
    }

    public static function settings(): array
    {
        return self::get('settings');
    }

    public static function advertisers(): array
    {
        return self::get('advertisers');
    }
}
