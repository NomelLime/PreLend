<?php
declare(strict_types=1);
/**
 * BotFilter.php
 *
 * Семь фильтров клоакинга. Возвращает результат проверки:
 *   FilterResult::PASS      — живой пользователь, пропускаем на оффер
 *   FilterResult::BOT       — бот / сканер / парсер
 *   FilterResult::CLOAK     — платформенный сканер (IG/TT/YT) — показываем легенду
 *   FilterResult::VPN       — VPN/прокси/datacenter IP
 *   FilterResult::OFFGEO    — ГЕО не совпадает ни с одним рекламодателем
 *   FilterResult::OFFHOURS  — вне рабочего времени рекламодателя
 *   FilterResult::TOR       — Tor-трафик
 */
require_once __DIR__ . '/FilterResult.php';

class BotFilter
{
    // Строковые константы оставлены для обратной совместимости.
    // Используй FilterResult enum в новом коде.
    const PASS     = 'PASS';
    const BOT      = 'BOT';
    const CLOAK    = 'CLOAK';
    const VPN      = 'VPN';
    const OFFGEO   = 'OFFGEO';
    const OFFHOURS = 'OFFHOURS';
    const TOR      = 'TOR';

    private string       $ua;
    private string       $ip;
    private FilterResult $result   = FilterResult::PASS;
    private string       $platform = 'direct';

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

    // ── Datacenter IP-диапазоны (точные CIDR, не prefix matching) ────────────
    // Формат: [start_ip, end_ip] включительно.
    // Использует ip2long() → без ложных срабатываний на целые /8 блоки.
    // Источники: AWS ip-ranges.json, GCP cloud.json, Azure, DO, Hetzner, OVH, Linode.
    private const DC_CIDRS = [
        // AWS
        ['3.0.0.0',     '3.5.255.255'],
        ['3.208.0.0',   '3.239.255.255'],
        ['13.52.0.0',   '13.59.255.255'],
        ['18.204.0.0',  '18.207.255.255'],
        ['34.192.0.0',  '34.255.255.255'],
        ['35.153.0.0',  '35.153.255.255'],
        ['52.0.0.0',    '52.79.255.255'],
        ['54.144.0.0',  '54.175.255.255'],
        // Google Cloud / Infra
        ['34.64.0.0',   '34.127.255.255'],
        ['35.184.0.0',  '35.247.255.255'],
        ['74.125.0.0',  '74.125.255.255'],
        ['108.177.0.0', '108.177.127.255'],
        ['142.250.0.0', '142.251.255.255'],
        // Microsoft Azure
        ['13.64.0.0',   '13.107.255.255'],
        ['20.33.0.0',   '20.128.255.255'],
        ['40.74.0.0',   '40.125.255.255'],
        ['52.224.0.0',  '52.255.255.255'],
        ['104.40.0.0',  '104.47.255.255'],
        ['157.55.0.0',  '157.55.255.255'],
        ['168.62.0.0',  '168.63.255.255'],
        ['207.46.0.0',  '207.46.255.255'],
        // Cloudflare Workers
        ['104.16.0.0',  '104.31.255.255'],
        // DigitalOcean
        ['134.122.0.0', '134.122.127.255'],
        ['137.184.0.0', '137.184.255.255'],
        ['142.93.0.0',  '142.93.255.255'],
        ['157.245.0.0', '157.245.255.255'],
        ['159.65.0.0',  '159.65.255.255'],
        ['159.89.0.0',  '159.89.255.255'],
        ['164.90.0.0',  '164.92.255.255'],
        ['167.71.0.0',  '167.71.255.255'],
        ['167.172.0.0', '167.172.255.255'],
        ['174.138.0.0', '174.138.127.255'],
        ['188.166.0.0', '188.166.255.255'],
        ['206.189.0.0', '206.189.255.255'],
        // Hetzner
        ['49.12.0.0',   '49.13.255.255'],
        ['65.108.0.0',  '65.109.255.255'],
        ['78.46.0.0',   '78.47.255.255'],
        ['88.99.0.0',   '88.99.255.255'],
        ['88.198.0.0',  '88.198.255.255'],
        ['95.216.0.0',  '95.217.255.255'],
        ['116.202.0.0', '116.203.255.255'],
        ['135.181.0.0', '135.181.255.255'],
        ['136.243.0.0', '136.243.255.255'],
        ['138.201.0.0', '138.201.255.255'],
        ['144.76.0.0',  '144.76.255.255'],
        ['148.251.0.0', '148.251.255.255'],
        ['159.69.0.0',  '159.69.255.255'],
        ['162.55.0.0',  '162.55.255.255'],
        ['167.235.0.0', '167.235.255.255'],
        ['176.9.0.0',   '176.9.255.255'],
        ['178.63.0.0',  '178.63.255.255'],
        ['195.201.0.0', '195.201.255.255'],
        ['213.133.96.0','213.133.111.255'],
        // Linode / Akamai
        ['45.33.0.0',   '45.33.127.255'],
        ['45.56.0.0',   '45.56.127.255'],
        ['45.79.0.0',   '45.79.255.255'],
        ['50.116.0.0',  '50.116.63.255'],
        ['66.175.208.0','66.175.223.255'],
        ['69.164.192.0','69.164.223.255'],
        ['96.126.96.0', '96.126.127.255'],
        ['139.144.0.0', '139.144.255.255'],
        ['143.42.0.0',  '143.42.255.255'],
        ['172.104.0.0', '172.105.255.255'],
        ['173.255.192.0','173.255.255.255'],
        // OVH
        ['51.38.0.0',   '51.38.255.255'],
        ['51.68.0.0',   '51.68.255.255'],
        ['51.75.0.0',   '51.75.255.255'],
        ['51.77.0.0',   '51.79.255.255'],
        ['51.83.0.0',   '51.83.255.255'],
        ['51.89.0.0',   '51.89.255.255'],
        ['51.91.0.0',   '51.91.255.255'],
        ['51.178.0.0',  '51.178.255.255'],
        ['51.210.0.0',  '51.210.255.255'],
        ['54.36.0.0',   '54.38.255.255'],
        ['91.121.0.0',  '91.121.255.255'],
        ['137.74.0.0',  '137.74.255.255'],
        ['141.94.0.0',  '141.95.255.255'],
        ['145.239.0.0', '145.239.255.255'],
        ['147.135.0.0', '147.135.255.255'],
        ['149.202.0.0', '149.202.255.255'],
        ['151.80.0.0',  '151.80.255.255'],
        ['176.31.0.0',  '176.31.255.255'],
        ['178.32.0.0',  '178.33.255.255'],
        ['188.165.0.0', '188.165.255.255'],
        ['198.27.64.0', '198.27.127.255'],
        ['213.186.32.0','213.186.47.255'],
        ['213.251.128.0','213.251.191.255'],
    ];

    public function __construct()
    {
        $this->ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        // Сохраняем IP в момент создания объекта —
        // getRealIp() может быть вызван позже когда $_SERVER уже изменён (тесты)
        $this->ip = $this->getRealIp();
        $this->detectPlatform();
    }

    // ── Публичный API ──────────────────────────────────────────────────────

    /**
     * Запускает все фильтры последовательно.
     * Передаёт GeoDetector и конфиг рекламодателя (опционально).
     *
     * @param GeoDetector $geo
     * @param array|null  $advertiser  Конфиг конкретного рекламодателя для проверки time/device
     * @return FilterResult  Результат проверки (PHP 8.1 backed enum)
     */
    public function check(GeoDetector $geo, ?array $advertiser = null): FilterResult
    {
        // 1. Tor
        if ($geo->isTor()) {
            return $this->result = FilterResult::TOR;
        }

        // 2. Пустой UA → точно бот
        if (trim($this->ua) === '') {
            return $this->result = FilterResult::BOT;
        }

        // 3. Платформенные сканеры → легенда
        if ($this->isPlatformBot()) {
            return $this->result = FilterResult::CLOAK;
        }

        // 4. Общие боты / парсеры / headless
        if ($this->isGenericBot()) {
            return $this->result = FilterResult::BOT;
        }

        // 5. VPN / Proxy / Datacenter
        if ($this->isVpnOrDatacenter()) {
            return $this->result = FilterResult::VPN;
        }

        // 6. Фильтры рекламодателя (geo, device, time) — если передан конфиг
        if ($advertiser !== null) {
            if (!$this->checkGeo($advertiser, $geo)) {
                return $this->result = FilterResult::OFFGEO;
            }
            if (!$this->checkDevice($advertiser)) {
                return $this->result = FilterResult::BOT;
            }
            if (!$this->checkTime($advertiser)) {
                return $this->result = FilterResult::OFFHOURS;
            }
        }

        return $this->result = FilterResult::PASS;
    }

    /** Строковое значение результата (для записи в БД, обратная совместимость). */
    public function getResult(): string
    {
        return $this->result->value;
    }

    /** Возвращает FilterResult enum (для строгой типизации в новом коде). */
    public function getResultEnum(): FilterResult
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
        // Cloudflare datacenter трафик (не пользователь)
        if (!empty($_SERVER['HTTP_CF_WORKER'])) {
            return true;
        }

        // Проверяем заголовки прокси (несколько IP в X-Forwarded-For = цепочка прокси)
        $proxyHeaders = [
            'HTTP_VIA', 'HTTP_X_VIA', 'HTTP_PROXY_CONNECTION', 'HTTP_X_PROXY_ID',
        ];
        foreach ($proxyHeaders as $h) {
            if (!empty($_SERVER[$h])) {
                return true;
            }
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            if (count($ips) > 2) {
                return true;
            }
        }

        // Проверка IP по точным datacenter-диапазонам через ip2long()
        $ip = $this->ip;
        if ($ip === '') {
            return false;
        }
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return false;
        }

        foreach (self::DC_CIDRS as [$start, $end]) {
            $startLong = ip2long($start);
            $endLong   = ip2long($end);
            if ($startLong !== false && $endLong !== false
                && $ipLong >= $startLong && $ipLong <= $endLong
            ) {
                return true;
            }
        }

        return false;
    }

    private function checkGeo(array $advertiser, GeoDetector $geo): bool
    {
        $allowed = $advertiser['geo'] ?? [];
        if (empty($allowed)) {
            return true;  // рекламодатель принимает любое ГЕО
        }
        return in_array($geo->getGeo(), $allowed, true);
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
