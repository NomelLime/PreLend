<?php
declare(strict_types=1);
/**
 * GeoDetector.php
 *
 * Определяет ГЕО пользователя из заголовка Cloudflare CF-IPCountry.
 * Дополнительно парсит Accept-Language как fallback-сигнал.
 *
 * CF-IPCountry: двухбуквенный ISO-3166-1 alpha-2 код страны.
 * Специальные значения Cloudflare:
 *   XX — нет данных / неизвестно
 *   T1 — Tor-трафик
 */
class GeoDetector
{
    private string $geo;
    private string $langGeo;
    private string $acceptLanguagePrimary;
    private bool   $isTor;

    public function __construct()
    {
        $this->geo     = $this->detectFromCloudflare();
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

    private function detectFromCloudflare(): string
    {
        // Cloudflare добавляет CF-IPCountry → PHP конвертирует в HTTP_CF_IPCOUNTRY
        $raw = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '';

        if ($raw === '') {
            // Локальная разработка / прямой запрос без CF
            return 'XX';
        }

        $code = strtoupper(trim($raw));

        // Допускаем только ISO-2 + спецкоды CF
        if (preg_match('/^[A-Z]{2}$/', $code) || $code === 'T1') {
            return $code;
        }

        return 'XX';
    }

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
