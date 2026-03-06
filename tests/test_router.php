<?php
/**
 * tests/test_router.php — Юнит-тесты Router.
 *
 * Покрываем:
 *   - getScore(): ставка × uptime × (1 - shave)
 *   - resolve(): выбор рекламодателя по ГЕО, Score, uptime
 *   - Fallback: лендинг упал → следующий по Score
 *   - OFFGEO: нет подходящего рекламодателя → null
 *   - Device-фильтр: mobile-only адв не берёт desktop
 */

require_once __DIR__ . '/bootstrap.php';
require_once ROOT . '/src/GeoDetector.php';
require_once ROOT . '/src/BotFilter.php';
require_once ROOT . '/src/Router.php';

echo "=== Router ===\n";

// ── Общий конфиг ──────────────────────────────────────────────────────────────
$settings = [
    'scoring' => ['min_uptime_to_activate' => 50.0],
];

$advertisers = [
    [
        'id' => 'adv_ua1', 'name' => 'UA-High', 'url' => 'https://ua1.example.com',
        'rate' => 5.00, 'geo' => ['UA', 'KZ'], 'status' => 'active',
        'device' => [], 'time_from' => '', 'time_to' => '', 'subid_param' => 'sub1',
    ],
    [
        'id' => 'adv_ua2', 'name' => 'UA-Low', 'url' => 'https://ua2.example.com',
        'rate' => 3.00, 'geo' => ['UA'], 'status' => 'active',
        'device' => [], 'time_from' => '', 'time_to' => '', 'subid_param' => 'sub2',
    ],
    [
        'id' => 'adv_pl',  'name' => 'PL-Only', 'url' => 'https://pl.example.com',
        'rate' => 6.00, 'geo' => ['PL', 'CZ'], 'status' => 'active',
        'device' => [], 'time_from' => '', 'time_to' => '', 'subid_param' => 'sub3',
    ],
    [
        'id' => 'adv_mob', 'name' => 'Mobile-Only', 'url' => 'https://mob.example.com',
        'rate' => 4.00, 'geo' => ['UA'], 'status' => 'active',
        'device' => ['mobile'], 'time_from' => '', 'time_to' => '', 'subid_param' => 'sub4',
    ],
    [
        'id' => 'adv_off', 'name' => 'Inactive', 'url' => 'https://off.example.com',
        'rate' => 10.00, 'geo' => ['UA'], 'status' => 'inactive',
        'device' => [], 'time_from' => '', 'time_to' => '', 'subid_param' => 'sub5',
    ],
];

function make_router(array $advertisers, array $settings, ?PDO $db = null): Router
{
    $db = $db ?? make_test_db();
    return new Router($advertisers, $settings, $db);
}

function make_bot_filter(string $ua, string $geo): array
{
    $backup = $_SERVER;
    $_SERVER['HTTP_USER_AGENT']   = $ua;
    $_SERVER['HTTP_CF_IPCOUNTRY'] = $geo;
    unset($_SERVER['HTTP_REFERER']);
    $f = new BotFilter();
    $g = new GeoDetector();
    $_SERVER = $backup;
    return [$f, $g];
}

$mobile_ua  = 'Mozilla/5.0 (Linux; Android 13) Mobile Safari/537.36';
$desktop_ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120';

// ════════════════════════════════════════════════════════════
// getScore
// ════════════════════════════════════════════════════════════

test('getScore — без данных в БД = ставка × 1 × 1', function () use ($settings, $advertisers) {
    $db     = make_test_db();
    $router = new Router($advertisers, $settings, $db);
    // Нет записи в landing_status → uptime=100, shave=0
    $score = $router->getScore('adv_ua1', 5.00);
    assert_eq(5.0, $score);
});

test('getScore — uptime 80% → score снижается', function () use ($settings, $advertisers) {
    $db = make_test_db();
    insert_landing_status($db, 'adv_ua1', true, 80.0);
    $router = new Router($advertisers, $settings, $db);
    $score  = $router->getScore('adv_ua1', 5.00);
    // 5.00 × (80/100) × 1 = 4.0
    assert_eq(4.0, $score);
});

test('getScore — uptime < min_uptime → score = 0', function () use ($settings, $advertisers) {
    $db = make_test_db();
    insert_landing_status($db, 'adv_ua1', true, 40.0); // ниже порога 50%
    $router = new Router($advertisers, $settings, $db);
    $score  = $router->getScore('adv_ua1', 5.00);
    assert_eq(0.0, $score);
});

// ════════════════════════════════════════════════════════════
// resolve — выбор рекламодателя
// ════════════════════════════════════════════════════════════

test('resolve — UA mobile → adv_ua1 (высокий Score)', function () use ($settings, $advertisers, $mobile_ua) {
    $db = make_test_db();
    insert_landing_status($db, 'adv_ua1', true, 99.0);
    insert_landing_status($db, 'adv_ua2', true, 99.0);
    insert_landing_status($db, 'adv_mob', true, 99.0);
    $router = new Router($advertisers, $settings, $db);
    [$f, $g] = make_bot_filter($mobile_ua, 'UA');
    $adv = $router->resolve('UA', 'mobile', $f);
    // adv_ua1 (rate 5.0) > adv_mob (rate 4.0) > adv_ua2 (rate 3.0)
    assert_eq('adv_ua1', $adv['id']);
});

test('resolve — PL → adv_pl', function () use ($settings, $advertisers, $desktop_ua) {
    $db = make_test_db();
    insert_landing_status($db, 'adv_pl', true, 99.0);
    $router = new Router($advertisers, $settings, $db);
    [$f, $g] = make_bot_filter($desktop_ua, 'PL');
    $adv = $router->resolve('PL', 'desktop', $f);
    assert_eq('adv_pl', $adv['id']);
});

test('resolve — OFFGEO (JP) → null', function () use ($settings, $advertisers, $mobile_ua) {
    $db     = make_test_db();
    $router = new Router($advertisers, $settings, $db);
    [$f, $g] = make_bot_filter($mobile_ua, 'JP');
    $adv = $router->resolve('JP', 'mobile', $f);
    assert_eq(null, $adv);
});

test('resolve — inactive advertiser пропускается', function () use ($settings, $advertisers, $mobile_ua) {
    // Только inactive для UA? Нет, есть active тоже — просто проверяем что inactive не выбран
    $db = make_test_db();
    insert_landing_status($db, 'adv_ua1', true, 99.0);
    $router = new Router($advertisers, $settings, $db);
    [$f, $g] = make_bot_filter($mobile_ua, 'UA');
    $adv = $router->resolve('UA', 'mobile', $f);
    assert_true($adv['id'] !== 'adv_off', 'inactive не должен выбираться');
});

// ════════════════════════════════════════════════════════════
// resolve — Fallback при упавшем лендинге
// ════════════════════════════════════════════════════════════

test('resolve — лендинг adv_ua1 упал → fallback adv_ua2', function () use ($settings, $mobile_ua) {
    $advs = [
        ['id' => 'adv_ua1', 'name' => 'High', 'url' => 'https://u1.ex', 'rate' => 5.0,
         'geo' => ['UA'], 'status' => 'active', 'device' => [], 'time_from' => '', 'time_to' => '', 'subid_param' => 'sub1'],
        ['id' => 'adv_ua2', 'name' => 'Low',  'url' => 'https://u2.ex', 'rate' => 3.0,
         'geo' => ['UA'], 'status' => 'active', 'device' => [], 'time_from' => '', 'time_to' => '', 'subid_param' => 'sub2'],
    ];
    $db = make_test_db();
    insert_landing_status($db, 'adv_ua1', false, 60.0); // is_up = false
    insert_landing_status($db, 'adv_ua2', true,  99.0);
    $router = new Router($advs, $settings, $db);
    [$f, $g] = make_bot_filter($mobile_ua, 'UA');
    $adv = $router->resolve('UA', 'mobile', $f);
    assert_eq('adv_ua2', $adv['id']);
});

test('resolve — все лендинги упали → null', function () use ($settings, $mobile_ua) {
    $advs = [
        ['id' => 'adv_ua1', 'name' => 'A', 'url' => 'https://u1.ex', 'rate' => 5.0,
         'geo' => ['UA'], 'status' => 'active', 'device' => [], 'time_from' => '', 'time_to' => '', 'subid_param' => 'sub1'],
    ];
    $db = make_test_db();
    insert_landing_status($db, 'adv_ua1', false, 60.0);
    $router = new Router($advs, $settings, $db);
    [$f, $g] = make_bot_filter($mobile_ua, 'UA');
    $adv = $router->resolve('UA', 'mobile', $f);
    assert_eq(null, $adv);
});

// ════════════════════════════════════════════════════════════
// resolve — device-фильтр
// ════════════════════════════════════════════════════════════

test('resolve — mobile-only adv не выбирается для desktop', function () use ($settings, $desktop_ua) {
    $advs = [
        ['id' => 'adv_mob', 'name' => 'Mobile', 'url' => 'https://mob.ex', 'rate' => 10.0,
         'geo' => ['UA'], 'status' => 'active', 'device' => ['mobile'],
         'time_from' => '', 'time_to' => '', 'subid_param' => 'sub1'],
    ];
    $db = make_test_db();
    insert_landing_status($db, 'adv_mob', true, 99.0);
    $router = new Router($advs, $settings, $db);
    [$f, $g] = make_bot_filter($desktop_ua, 'UA');
    $adv = $router->resolve('UA', 'desktop', $f);
    assert_eq(null, $adv);
});

test('resolve — mobile-only adv выбирается для mobile', function () use ($settings, $mobile_ua) {
    $advs = [
        ['id' => 'adv_mob', 'name' => 'Mobile', 'url' => 'https://mob.ex', 'rate' => 4.0,
         'geo' => ['UA'], 'status' => 'active', 'device' => ['mobile'],
         'time_from' => '', 'time_to' => '', 'subid_param' => 'sub1'],
    ];
    $db = make_test_db();
    insert_landing_status($db, 'adv_mob', true, 99.0);
    $router = new Router($advs, $settings, $db);
    [$f, $g] = make_bot_filter($mobile_ua, 'UA');
    $adv = $router->resolve('UA', 'mobile', $f);
    assert_eq('adv_mob', $adv['id']);
});

test_summary('Router');
