<?php
/**
 * tests/test_bot_filter.php — Юнит-тесты BotFilter + GeoDetector.
 */
require_once __DIR__ . '/bootstrap.php';
require_once ROOT . '/src/GeoDetector.php';
require_once ROOT . '/src/BotFilter.php';

echo "=== BotFilter + GeoDetector ===\n";

function make_filter_geo(array $server): array
{
    $backup  = $_SERVER;
    $_SERVER = array_merge($backup, $server);
    $geo     = new GeoDetector();
    $filter  = new BotFilter();
    $_SERVER = $backup;
    return [$filter, $geo];
}

// ── GeoDetector ───────────────────────────────────────────────────────────────
test('GeoDetector — CF-IPCountry UA', function () {
    [$f, $geo] = make_filter_geo(['HTTP_CF_IPCOUNTRY' => 'UA']);
    assert_eq('UA', $geo->getGeo());
    assert_true(!$geo->isTor());
});

test('GeoDetector — CF T1 = Tor', function () {
    [$f, $geo] = make_filter_geo(['HTTP_CF_IPCOUNTRY' => 'T1']);
    assert_true($geo->isTor());
});

test('GeoDetector — нет заголовка = XX', function () {
    $backup = $_SERVER; unset($_SERVER['HTTP_CF_IPCOUNTRY']);
    $geo = new GeoDetector();
    $_SERVER = $backup;
    assert_eq('XX', $geo->getGeo());
    assert_true(!$geo->isKnown());
});

test('GeoDetector — matchesAllowed пустой = любое ГЕО', function () {
    [$f, $geo] = make_filter_geo(['HTTP_CF_IPCOUNTRY' => 'JP']);
    assert_true($geo->matchesAllowed([]));
    assert_true(!$geo->matchesAllowed(['UA', 'PL']));
    assert_true($geo->matchesAllowed(['JP', 'KR']));
});

// ── TOR ───────────────────────────────────────────────────────────────────────
test('TOR — CF T1 → TOR', function () {
    [$f, $geo] = make_filter_geo([
        'HTTP_CF_IPCOUNTRY' => 'T1',
        'HTTP_USER_AGENT'   => 'Mozilla/5.0 (Windows NT 10.0; rv:109.0) Gecko Firefox/115.0',
    ]);
    assert_eq(BotFilter::TOR, $f->check($geo));
});

// ── CLOAK — платформенные боты ────────────────────────────────────────────────
test('CLOAK — facebookexternalhit', function () {
    [$f, $geo] = make_filter_geo([
        'HTTP_CF_IPCOUNTRY' => 'US',
        'HTTP_USER_AGENT'   => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
    ]);
    assert_eq(BotFilter::CLOAK, $f->check($geo));
});

test('CLOAK — Bytespider (TikTok)', function () {
    [$f, $geo] = make_filter_geo([
        'HTTP_CF_IPCOUNTRY' => 'SG',
        'HTTP_USER_AGENT'   => 'Bytespider; spider-feedback@bytedance.com',
    ]);
    assert_eq(BotFilter::CLOAK, $f->check($geo));
});

test('CLOAK — Googlebot (YouTube)', function () {
    [$f, $geo] = make_filter_geo([
        'HTTP_CF_IPCOUNTRY' => 'US',
        'HTTP_USER_AGENT'   => 'Googlebot/2.1 (+http://www.google.com/bot.html)',
    ]);
    assert_eq(BotFilter::CLOAK, $f->check($geo));
});

// ── BOT — общие парсеры ───────────────────────────────────────────────────────
test('BOT — пустой UA', function () {
    [$f, $geo] = make_filter_geo(['HTTP_CF_IPCOUNTRY' => 'UA', 'HTTP_USER_AGENT' => '']);
    assert_eq(BotFilter::BOT, $f->check($geo));
});

test('BOT — curl UA', function () {
    [$f, $geo] = make_filter_geo(['HTTP_CF_IPCOUNTRY' => 'UA', 'HTTP_USER_AGENT' => 'curl/7.88.1']);
    assert_eq(BotFilter::BOT, $f->check($geo));
});

test('BOT — python-requests', function () {
    [$f, $geo] = make_filter_geo(['HTTP_CF_IPCOUNTRY' => 'UA', 'HTTP_USER_AGENT' => 'python-requests/2.28.1']);
    assert_eq(BotFilter::BOT, $f->check($geo));
});

// ── PASS — живые пользователи ─────────────────────────────────────────────────
test('PASS — Android Chrome mobile', function () {
    [$f, $geo] = make_filter_geo([
        'HTTP_CF_IPCOUNTRY' => 'UA',
        'HTTP_USER_AGENT'   => 'Mozilla/5.0 (Linux; Android 13; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
        'REMOTE_ADDR'       => '93.184.216.34',
    ]);
    assert_eq(BotFilter::PASS, $f->check($geo));
});

test('PASS — iPhone Safari', function () {
    [$f, $geo] = make_filter_geo([
        'HTTP_CF_IPCOUNTRY' => 'PL',
        'HTTP_USER_AGENT'   => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1',
        'REMOTE_ADDR'       => '83.25.200.1',
    ]);
    assert_eq(BotFilter::PASS, $f->check($geo));
});

test('PASS — Windows Chrome desktop', function () {
    [$f, $geo] = make_filter_geo([
        'HTTP_CF_IPCOUNTRY' => 'KZ',
        'HTTP_USER_AGENT'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
        'REMOTE_ADDR'       => '178.90.0.1',
    ]);
    assert_eq(BotFilter::PASS, $f->check($geo));
});

// ── OFFGEO / OFFHOURS ─────────────────────────────────────────────────────────
test('OFFGEO — запрос с ГЕО не из списка рекламодателя не попадает в PASS', function () {
    [$f, $geo] = make_filter_geo([
        'HTTP_CF_IPCOUNTRY' => 'JP',
        'HTTP_USER_AGENT'   => 'Mozilla/5.0 (Linux; Android 13; SM-G991B) AppleWebKit/537.36 Chrome/120.0.0.0 Mobile Safari/537.36',
        'REMOTE_ADDR'       => '1.2.3.4',
    ]);
    // Передаём рекламодателя с ограниченным ГЕО (только UA/PL) → должен вернуть OFFGEO
    $advertiser = ['geo' => ['UA', 'PL'], 'device' => [], 'time_from' => '', 'time_to' => ''];
    $result = $f->check($geo, $advertiser);
    assert_true($result !== BotFilter::PASS, "OFFGEO-запрос не должен быть PASS, получено: $result");
});

test('OFFGEO — константа OFFGEO определена', function () {
    assert_eq('OFFGEO', BotFilter::OFFGEO);
    assert_eq('OFFHOURS', BotFilter::OFFHOURS);
});

// ── VPN / Datacenter CIDR ────────────────────────────────────────────────────
test('VPN — AWS IP в точном диапазоне детектируется', function () {
    [$f, $geo] = make_filter_geo([
        'HTTP_CF_IPCOUNTRY'     => 'US',
        'HTTP_CF_CONNECTING_IP' => '52.10.20.30',
        'HTTP_USER_AGENT'       => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120',
        'REMOTE_ADDR'           => '52.10.20.30',
    ]);
    assert_eq(BotFilter::VPN, $f->check($geo));
});

test('PASS — обычный ISP (Comcast) не фильтруется', function () {
    [$f, $geo] = make_filter_geo([
        'HTTP_CF_IPCOUNTRY'     => 'US',
        'HTTP_CF_CONNECTING_IP' => '73.162.45.100',
        'HTTP_USER_AGENT'       => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X)',
        'REMOTE_ADDR'           => '73.162.45.100',
    ]);
    assert_eq(BotFilter::PASS, $f->check($geo));
});

test('PASS — IP 3.100.0.1 не в DC_CIDRS (не бросает false-positive)', function () {
    // Старый DC_SUBNETS '3.' фильтровал весь /8. Новый CIDR — только точные диапазоны.
    [$f, $geo] = make_filter_geo([
        'HTTP_CF_IPCOUNTRY'     => 'AU',
        'HTTP_CF_CONNECTING_IP' => '3.100.0.1',
        'HTTP_USER_AGENT'       => 'Mozilla/5.0 (Linux; Android 13) Chrome/120',
        'REMOTE_ADDR'           => '3.100.0.1',
    ]);
    assert_eq(BotFilter::PASS, $f->check($geo));
});

test('VPN — Azure IP детектируется', function () {
    [$f, $geo] = make_filter_geo([
        'HTTP_CF_IPCOUNTRY'     => 'US',
        'HTTP_CF_CONNECTING_IP' => '40.80.10.5',
        'HTTP_USER_AGENT'       => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120',
        'REMOTE_ADDR'           => '40.80.10.5',
    ]);
    assert_eq(BotFilter::VPN, $f->check($geo));
});

// ── getDeviceType ─────────────────────────────────────────────────────────────
test('getDeviceType — mobile (Android)', function () {
    [$f, $geo] = make_filter_geo(['HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 13) Mobile Safari/537.36']);
    assert_eq('mobile', $f->getDeviceType());
});

test('getDeviceType — mobile (iPhone)', function () {
    [$f, $geo] = make_filter_geo(['HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Mobile/15E148']);
    assert_eq('mobile', $f->getDeviceType());
});

test('getDeviceType — desktop (Windows)', function () {
    [$f, $geo] = make_filter_geo(['HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120']);
    assert_eq('desktop', $f->getDeviceType());
});

// ── getPlatform ───────────────────────────────────────────────────────────────
test('getPlatform — YouTube реферер', function () {
    [$f, $geo] = make_filter_geo([
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Mobile',
        'HTTP_REFERER'    => 'https://www.youtube.com/shorts/abc123',
    ]);
    $f->check($geo);
    assert_eq('youtube', $f->getPlatform());
});

test('getPlatform — TikTok реферер', function () {
    [$f, $geo] = make_filter_geo([
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Mobile',
        'HTTP_REFERER'    => 'https://www.tiktok.com/@user/video/123',
    ]);
    $f->check($geo);
    assert_eq('tiktok', $f->getPlatform());
});

test('getPlatform — Instagram реферер', function () {
    [$f, $geo] = make_filter_geo([
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Mobile',
        'HTTP_REFERER'    => 'https://www.instagram.com/reel/abc/',
    ]);
    $f->check($geo);
    assert_eq('instagram', $f->getPlatform());
});

// ── getUaHash ─────────────────────────────────────────────────────────────────
test('getUaHash — SHA256 корректный', function () {
    $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)';
    [$f, $geo] = make_filter_geo(['HTTP_USER_AGENT' => $ua]);
    assert_eq(hash('sha256', $ua), $f->getUaHash());
});

test_summary('BotFilter + GeoDetector');
