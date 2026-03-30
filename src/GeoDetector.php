<?php
declare(strict_types=1);
/**
 * GeoDetector.php
 *
 * Определяет ГЕО пользователя через GeoAdapter:
 * IP2Location -> Accept-Language region.
 * Дополнительно парсит Accept-Language (языковой тег) для ContentLocaleResolver.
 */
class GeoDetector
{
    private string $geo;
    private string $langGeo;
    private string $acceptLanguagePrimary;
    private bool   $isTor;

    public function __construct()
    {
        $this->geo     = GeoAdapter::resolveGeo(self::getRealIp());
        $this->langGeo = $this->detectFromLanguage();
        $this->acceptLanguagePrimary = $this->detectAcceptLanguagePrimary();
        $this->isTor   = ($this->geo === 'T1');
    }

    // ── Публичные методы ──────────────────────────────────────────────────

    /** Двухбуквенный ISO код страны (верхний регистр) или 'XX' */
    public function getGeo(): string
    {
        return $this->geo;
    }

    /** ГЕО из Accept-Language (первая локаль, только страна) */
    public function getLangGeo(): string
    {
        return $this->langGeo;
    }

    /**
     * Первый языковой подтег из Accept-Language (напр. ru из ru-RU), нижний регистр.
     * Для ContentLocaleResolver при CF-IPCountry = XX.
     */
    public function getAcceptLanguagePrimary(): string
    {
        return $this->acceptLanguagePrimary;
    }

    /** Является ли соединение Tor-узлом */
    public function isTor(): bool
    {
        return $this->isTor;
    }

    /**
     * Реальный IP клиента (Cloudflare → CF-Connecting-IP, иначе REMOTE_ADDR).
     */
    public static function getRealIp(): string
    {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);
        }

        return trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    /** ГЕО известно (не XX и не T1) */
    public function isKnown(): bool
    {
        return !in_array($this->geo, ['XX', 'T1', ''], true);
    }

    /**
     * Совпадает ли ГЕО с одним из разрешённых для рекламодателя.
     * Пустой массив = любое ГЕО разрешено.
     */
    public function matchesAllowed(array $allowedGeos): bool
    {
        if (empty($allowedGeos)) {
            return true;
        }
        return in_array($this->geo, $allowedGeos, true);
    }

    // ── Приватные методы ──────────────────────────────────────────────────

    private function detectAcceptLanguagePrimary(): string
    {
        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if ($header === '') {
            return '';
        }

        $first = explode(',', $header)[0];
        $first = trim(explode(';', $first)[0]);
        $parts = explode('-', $first);
        $lang  = strtolower(trim($parts[0] ?? ''));

        if (preg_match('/^[a-z]{2,3}$/', $lang)) {
            return $lang;
        }

        return '';
    }

    private function detectFromLanguage(): string
    {
        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if ($header === '') {
            return 'XX';
        }

        // Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8
        // Берём первый тег и вытаскиваем региональный код
        $first = explode(',', $header)[0];
        $parts = explode('-', $first);

        if (count($parts) >= 2) {
            $region = strtoupper(trim($parts[1]));
            if (preg_match('/^[A-Z]{2}$/', $region)) {
                return $region;
            }
        }

        return 'XX';
    }
}
