<?php
/**
 * tests/test_conversion_logger.php — Тесты ConversionLogger.
 *
 * Покрываем:
 *   logApi():    happy path, click_id не найден, дедупликация
 *   logManual(): happy path, кастомный conv_id (TEST_*)
 *   clicks.status → 'converted' после logApi()
 */

require_once __DIR__ . '/bootstrap.php';
require_once ROOT . '/src/ConversionLogger.php';

echo "=== ConversionLogger ===\n";

// ── logApi — happy path ───────────────────────────────────────────────────────
test('logApi — OK: click_id существует → конверсия записана', function () {
    $db       = make_test_db();
    $clickId  = insert_click($db, ['advertiser_id' => 'adv_001', 'status' => 'sent']);
    $logger   = new ConversionLogger($db);

    $result = $logger->logApi($clickId, 'adv_001', date('Y-m-d'));

    assert_true($result['ok'], 'ожидали ok=true');
    assert_true(!empty($result['conv_id']), 'conv_id не должен быть пустым');

    // Проверяем запись в БД
    $row = $db->query("SELECT * FROM conversions WHERE conv_id = '{$result['conv_id']}'")->fetch(PDO::FETCH_ASSOC);
    assert_true($row !== false, 'запись должна быть в conversions');
    assert_eq('api',   $row['source']);
    assert_eq('adv_001', $row['advertiser_id']);
});

// ── logApi — статус клика становится 'converted' ─────────────────────────────
test('logApi — clicks.status = converted после зачёта', function () {
    $db      = make_test_db();
    $clickId = insert_click($db, ['status' => 'sent']);
    $logger  = new ConversionLogger($db);
    $logger->logApi($clickId, 'adv_001', date('Y-m-d'));

    $status = $db->query("SELECT status FROM clicks WHERE click_id = '$clickId'")->fetchColumn();
    assert_eq('converted', $status);
});

// ── logApi — click_id не найден ───────────────────────────────────────────────
test('logApi — ошибка: click_id не существует', function () {
    $db     = make_test_db();
    $logger = new ConversionLogger($db);
    $result = $logger->logApi('nonexistent-click-id', 'adv_001', date('Y-m-d'));

    assert_true(!$result['ok'], 'ожидали ok=false');
    assert_eq('click_id not found', $result['error']);
});

// ── logApi — дедупликация ─────────────────────────────────────────────────────
test('logApi — дедупликация: второй вызов с тем же click_id = duplicate', function () {
    $db      = make_test_db();
    $clickId = insert_click($db);
    $logger  = new ConversionLogger($db);
    $date    = date('Y-m-d');

    $r1 = $logger->logApi($clickId, 'adv_001', $date);
    assert_true($r1['ok']);

    $r2 = $logger->logApi($clickId, 'adv_001', $date);
    assert_true(!$r2['ok'], 'второй вызов должен дать ошибку');
    assert_eq('duplicate', $r2['error']);
});

// ── logManual — happy path ────────────────────────────────────────────────────
test('logManual — OK: конверсия записана с source=manual', function () {
    $db     = make_test_db();
    $logger = new ConversionLogger($db);
    $result = $logger->logManual('adv_001', date('Y-m-d'), 2);

    assert_true($result['ok']);
    $row = $db->query("SELECT * FROM conversions WHERE conv_id = '{$result['conv_id']}'")->fetch(PDO::FETCH_ASSOC);
    assert_eq('manual', $row['source']);
    assert_eq(2,        (int)$row['count']);
});

// ── logManual — кастомный TEST_ conv_id ──────────────────────────────────────
test('logManual — TEST_* conv_id сохраняется точно', function () {
    $db      = make_test_db();
    $logger  = new ConversionLogger($db);
    $testId  = 'TEST_' . strtoupper(bin2hex(random_bytes(6)));
    $result  = $logger->logManual('adv_001', date('Y-m-d'), 1, 'test', $testId);

    assert_true($result['ok']);
    assert_eq($testId, $result['conv_id']);

    $found = $db->query("SELECT 1 FROM conversions WHERE conv_id = '$testId'")->fetchColumn();
    assert_true((bool)$found, 'TEST_ конверсия должна быть в БД');
});

// ── logManual — несколько конверсий одного рекламодателя ─────────────────────
test('logManual — несколько записей разных дат без конфликта', function () {
    $db     = make_test_db();
    $logger = new ConversionLogger($db);

    $r1 = $logger->logManual('adv_001', '2026-03-01', 3);
    $r2 = $logger->logManual('adv_001', '2026-03-02', 2);
    $r3 = $logger->logManual('adv_001', '2026-03-03', 1);

    assert_true($r1['ok'] && $r2['ok'] && $r3['ok']);

    $total = $db->query("SELECT SUM(count) FROM conversions WHERE advertiser_id = 'adv_001'")->fetchColumn();
    assert_eq(6, (int)$total);
});

// ── count по умолчанию = 1 ────────────────────────────────────────────────────
test('logManual — count по умолчанию равен 1', function () {
    $db     = make_test_db();
    $logger = new ConversionLogger($db);
    $result = $logger->logManual('adv_002', date('Y-m-d'));

    assert_true($result['ok']);
    $count = $db->query("SELECT count FROM conversions WHERE conv_id = '{$result['conv_id']}'")->fetchColumn();
    assert_eq(1, (int)$count);
});

test_summary('ConversionLogger');
