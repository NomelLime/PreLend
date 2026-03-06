<?php
/**
 * tests/bootstrap.php — тестовая инфраструктура PreLend.
 *
 * Создаёт изолированную in-memory SQLite БД для каждого теста.
 * Переопределяет константы путей так, чтобы тесты не трогали реальные файлы.
 */

define('ROOT', dirname(__DIR__));
define('TEST_ROOT', __DIR__);

// ── Авто-load PHP классов из src/ ─────────────────────────────────────────────
spl_autoload_register(function (string $class): void {
    $file = ROOT . '/src/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// ── Тестовая БД (in-memory, изолирована per-test) ────────────────────────────
function make_test_db(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA foreign_keys = ON;');

    $sql = file_get_contents(ROOT . '/data/init_db.sql');
    $pdo->exec($sql);

    return $pdo;
}

// ── Тестовые фикстуры ─────────────────────────────────────────────────────────
function insert_click(PDO $db, array $override = []): string
{
    $defaults = [
        'click_id'      => 'test-' . bin2hex(random_bytes(4)),
        'ts'            => time(),
        'ip'            => '93.184.216.34',
        'geo'           => 'UA',
        'device'        => 'mobile',
        'platform'      => 'youtube',
        'advertiser_id' => 'adv_001',
        'status'        => 'sent',
        'is_test'       => 0,
        'ua_hash'       => hash('sha256', 'test-ua'),
    ];
    $row = array_merge($defaults, $override);

    $db->prepare("
        INSERT INTO clicks
          (click_id, ts, ip, geo, device, platform, advertiser_id, status, is_test, ua_hash)
        VALUES (?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $row['click_id'], $row['ts'], $row['ip'], $row['geo'],
        $row['device'], $row['platform'], $row['advertiser_id'],
        $row['status'], $row['is_test'], $row['ua_hash'],
    ]);

    return $row['click_id'];
}

function insert_conversion(PDO $db, array $override = []): string
{
    $defaults = [
        'conv_id'       => 'conv-' . bin2hex(random_bytes(4)),
        'date'          => date('Y-m-d'),
        'advertiser_id' => 'adv_001',
        'count'         => 1,
        'source'        => 'manual',
        'notes'         => '',
        'created_at'    => time(),
    ];
    $row = array_merge($defaults, $override);

    $db->prepare("
        INSERT INTO conversions (conv_id, date, advertiser_id, count, source, notes, created_at)
        VALUES (?,?,?,?,?,?,?)
    ")->execute([
        $row['conv_id'], $row['date'], $row['advertiser_id'],
        $row['count'], $row['source'], $row['notes'], $row['created_at'],
    ]);

    return $row['conv_id'];
}

function insert_landing_status(PDO $db, string $advId, bool $isUp = true, float $uptime = 99.5): void
{
    $db->prepare("
        INSERT OR REPLACE INTO landing_status
          (advertiser_id, last_check, response_ms, is_up, uptime_24h)
        VALUES (?,?,?,?,?)
    ")->execute([$advId, time(), 300, (int)$isUp, $uptime]);
}

// ── Минимальный тест-раннер ───────────────────────────────────────────────────
$GLOBALS['_test_pass']   = 0;
$GLOBALS['_test_fail']   = 0;
$GLOBALS['_test_errors'] = [];

function test(string $name, callable $fn): void
{
    try {
        $fn();
        $GLOBALS['_test_pass']++;
        echo "  ✅ {$name}\n";
    } catch (Throwable $e) {
        $GLOBALS['_test_fail']++;
        $GLOBALS['_test_errors'][] = "  ❌ {$name}: {$e->getMessage()}";
        echo "  ❌ {$name}: {$e->getMessage()}\n";
    }
}

function assert_eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($msg ? "{$msg}: " : '') .
            "expected " . json_encode($expected) . ", got " . json_encode($actual)
        );
    }
}

function assert_true(bool $cond, string $msg = 'assertion failed'): void
{
    if (!$cond) throw new RuntimeException($msg);
}

function assert_contains(string $needle, string $haystack, string $msg = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException(
            ($msg ?: "expected to contain '{$needle}'") . " in: " . substr($haystack, 0, 100)
        );
    }
}

function test_summary(string $suite): void
{
    $pass  = $GLOBALS['_test_pass'];
    $fail  = $GLOBALS['_test_fail'];
    $total = $pass + $fail;
    echo "\n{$suite}: {$pass}/{$total} passed";
    if ($fail > 0) {
        echo " ({$fail} failed)\n";
        foreach ($GLOBALS['_test_errors'] as $e) echo $e . "\n";
        exit(1);
    }
    echo " ✅\n";
}
