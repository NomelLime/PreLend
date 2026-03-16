<?php
declare(strict_types=1);
/**
 * BotFilter.php
 *
 * Семь фильтров клоакинга. Возвращает результат проверки:
 *   PASS      — живой пользователь, пропускаем на оффер
 *   BOT       — бот / сканер / парсер
 *   CLOAK     — платформенный сканер (IG/TT/YT) — показываем легенду
 *   VPN       — VPN/прокси/datacenter IP
 *   OFFGEO    — ГЕО не совпадает ни с одним рекламодателем
 *   OFFHOURS  — вне рабочего времени рекламодателя
 *   TOR       — Tor-трафик
 */
class BotFilter
{
    // Результаты фильтрации
    const PASS     = 'PASS';
    const BOT      = 'BOT';
    const CLOAK    = 'CLOAK';
    const VPN      = 'VPN';
    const OFFGEO   = 'OFFGEO';
    const OFFHOURS = 'OFFHOURS';
    const TOR      = 'TOR';

    private string $ua;
    private string $result = self::PASS;
    private string $platform = 'direct';

    // ── Платформенные боты (сканеры ссылок) ───────────────────────────────
    private const PLATFORM_BOTS = [
        // Facebook / Instagram
        'facebookexternalhit', 'facebot', 'instagram',
        // TikTok
        'bytespider', 'tiktok',
        // YouTube / Google
        'googlebot', 'google-inspectiontool', 'apis-google', 'mediapartners-google',
        'adsbot-google', 'google-read-aloud',
        // Twitter / X
        'twitterbot', 'xbot',
        // Telegram
        'telegrambot',
        // VK
        'vkshare',
        // Generic link preview bots
        'linkedinbot', 'whatsapp', 'viber', 'skypeuripreview',
        'slackbot', 'discordbot',
        // iMessage / Apple
        'applebot',
    ];

    // ── Общие боты / парсеры ──────────────────────────────────────────────
    private const GENERIC_BOTS = [
        'bot', 'crawler', 'spider', 'slurp', 'bingbot', 'yahoo',
        'baidu', 'duckduckbot', 'sogou', 'exabot', 'ia_archiver',
        'semrush', 'ahrefs', 'mj12bot', 'dotbot', 'rogerbot',
        'screaming frog', 'wget', 'curl', 'python-requests', 'python-urllib',
        'go-http-client', 'libwww-perl', 'java/', 'okhttp', 'axios/',
        'httpie', 'headlesschrome', 'phantomjs', 'selenium', 'puppeteer',
        'playwright', 'scrapy',
    ];

    // ── Datacenter ASN / подсети (IPv4 CIDR) ──────────────────────────────
    // Краткий список наиболее распространённых хостинг-провайдеров
    private const DC_SUBNETS = [
        '3.',       // Amazon AWS (упрощённый диапазон)
        '13.',
        '18.',
        '34.',
        '35.',
        '52.',
        '54.',
        '104.',     // Cloudflare workers
        '172.16.',  // Private
        '172.17.',
        '192.168.', // Private
        '10.',      // Private
        '45.33.',   // Linode
        '45.56.',
        '66.175.',  // Softlayer IBM
        '67.228.',
        '69.162.',
        '74.125.',  // Google
        '108.177.',
        '142.250.',
        '157.55.',  // Microsoft Azure
        '168.62.',
        '207.46.',
        '23.21.',   // Amazon
        '23.22.',
        '50.16.',
        '50.17.',
        '50.18.',
        '50.19.',
    ];

    public function __construct()
    {
        $this->ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $this->detectPlatform();
    }

    // ── Публичный API ──────────────────────────────────────────────────────

    /**
     * Запускает все фильтры последовательно.
     * Передаёт GeoDetector и конфиг рекламодателя (опционально).
     *
     * @param GeoDetector $geo
     * @param array|null  $advertiser  Конфиг конкретного рекламодателя для проверки time/device
     * @return string  Одна из констант: PASS, BOT, CLOAK, VPN, OFFGEO, OFFHOURS, TOR
     */
    public function check(GeoDetector $geo, ?array $advertiser = null): string
    {
        // 1. Tor
        if ($geo->isTor()) {
            return $this->result = self::TOR;
        }

        // 2. Пустой UA → точно бот
        if (trim($this->ua) === '') {
            return $this->result = self::BOT;
        }

        // 3. Платформенные сканеры → легенда
        if ($this->isPlatformBot()) {
            return $this->result = self::CLOAK;
        }

        // 4. Общие боты / парсеры / headless
        if ($this->isGenericBot()) {
            return $this->result = self::BOT;
        }

        // 5. VPN / Proxy / Datacenter
        if ($this->isVpnOrDatacenter()) {
            return $this->result = self::VPN;
        }

        // 6. Фильтры рекламодателя (device, time) — если передан конфиг
        if ($advertiser !== null) {
            if (!$this->checkDevice($advertiser)) {
                return $this->result = self::BOT;
            }
            if (!$this->checkTime($advertiser)) {
                return $this->result = self::OFFHOURS;
            }
        }

        return $this->result = self::PASS;
    }

    public function getResult(): string
    {
        return $this->result;
    }

    /** Определённая платформа-источник (youtube|instagram|tiktok|direct|unknown) */
    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function getUaHash(): string
    {
        return hash('sha256', $this->ua);
    }

    public function getDeviceType(): string
    {
        $ua = strtolower($this->ua);

        if (preg_match('/tablet|ipad|playbook|silk/i', $ua)) {
            return 'tablet';
        }
        if (preg_match('/mobile|android|iphone|ipod|blackberry|windows phone/i', $ua)) {
            return 'mobile';
        }
        return 'desktop';
    }

    // ── Приватные проверки ─────────────────────────────────────────────────

    private function isPlatformBot(): bool
    {
        $ua = strtolower($this->ua);
        foreach (self::PLATFORM_BOTS as $bot) {
            if (str_contains($ua, $bot)) {
                return true;
            }
        }
        return false;
    }

    private function isGenericBot(): bool
    {
        $ua = strtolower($this->ua);
        foreach (self::GENERIC_BOTS as $bot) {
            if (str_contains($ua, $bot)) {
                return true;
            }
        }
        return false;
    }

    private function isVpnOrDatacenter(): bool
    {
        // Проверяем заголовки прокси
        $proxyHeaders = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_VIA',
            'HTTP_X_VIA',
            'HTTP_PROXY_CONNECTION',
            'HTTP_X_PROXY_ID',
        ];

        foreach ($proxyHeaders as $h) {
            if (!empty($_SERVER[$h])) {
                // HTTP_X_FORWARDED_FOR — может быть легитимным за CF,
                // но несколько IP в цепочке = прокси
                if ($h === 'HTTP_X_FORWARDED_FOR') {
                    $ips = explode(',', $_SERVER[$h]);
                    if (count($ips) > 2) {
                        return true;
                    }
                } else {
                    return true;
                }
            }
        }

        // Cloudflare datacenter трафик (не пользователь)
        if (!empty($_SERVER['HTTP_CF_WORKER'])) {
            return true;
        }

        // Проверка IP на datacenter диапазон
        $ip = $this->getRealIp();
        foreach (self::DC_SUBNETS as $prefix) {
            if (str_starts_with($ip, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function checkDevice(array $advertiser): bool
    {
        $allowed = $advertiser['device'] ?? [];
        if (empty($allowed)) {
            return true; // фильтр не задан
        }
        return in_array($this->getDeviceType(), $allowed, true);
    }

    private function checkTime(array $advertiser): bool
    {
        $from = $advertiser['time_from'] ?? '';
        $to   = $advertiser['time_to']   ?? '';

        if ($from === '' || $to === '') {
            return true; // фильтр не задан
        }

        $now     = (int) date('Hi'); // HHMM как число
        $fromInt = (int) str_replace(':', '', $from);
        $toInt   = (int) str_replace(':', '', $to);

        if ($fromInt <= $toInt) {
            return $now >= $fromInt && $now <= $toInt;
        }
        // Ночной диапазон (например 22:00 – 06:00)
        return $now >= $fromInt || $now <= $toInt;
    }

    private function detectPlatform(): void
    {
        $referer = strtolower($_SERVER['HTTP_REFERER'] ?? '');
        $ua      = strtolower($this->ua);

        if (str_contains($referer, 'youtube.com') || str_contains($referer, 'youtu.be') || str_contains($ua, 'youtube')) {
            $this->platform = 'youtube';
        } elseif (str_contains($referer, 'instagram.com') || str_contains($ua, 'instagram')) {
            $this->platform = 'instagram';
        } elseif (str_contains($referer, 'tiktok.com') || str_contains($ua, 'tiktok') || str_contains($ua, 'musical_ly')) {
            $this->platform = 'tiktok';
        } elseif ($referer === '') {
            $this->platform = 'direct';
        } else {
            $this->platform = 'unknown';
        }
    }

    private function getRealIp(): string
    {
        // За Cloudflare реальный IP приходит в CF-Connecting-IP
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
}
