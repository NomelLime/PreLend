<?php
/**
 * tests/test_click_dedup.php — дедуп кликов по fingerprint.
 */
require_once __DIR__ . '/bootstrap.php';
require_once ROOT . '/src/ClickLogger.php';

echo "=== Click dedup ===\n";

test('первый fingerprint → null', function () {
    $db = make_test_db();
    $log = new ClickLogger($db);
    $r = $log->isDuplicateFingerprint('1.1.1.1', hash('sha256', 'ua1'), 60);
    assert_eq(null, $r);
});

test('дубликат в TTL → тот же click_id', function () {
    $db = make_test_db();
    $log = new ClickLogger($db);
    $cid = insert_click($db, ['ua_hash' => hash('sha256', 'ua1'), 'ip' => '1.1.1.1']);
    $log->recordFingerprint('1.1.1.1', hash('sha256', 'ua1'), $cid);
    $dup = $log->isDuplicateFingerprint('1.1.1.1', hash('sha256', 'ua1'), 60);
    assert_eq($cid, $dup);
});

test('после TTL (0 сек окна) → null', function () {
    $db = make_test_db();
    $log = new ClickLogger($db);
    $old = time() - 120;
    $uaH = hash('sha256', 'ua1');
    $fp  = hash('sha256', '1.1.1.1' . $uaH);
    $db->prepare(
        'INSERT OR REPLACE INTO click_fingerprints (fp_hash, click_id, created_at) VALUES (?,?,?)'
    )->execute([$fp, 'old-id', $old]);
    $r = $log->isDuplicateFingerprint('1.1.1.1', hash('sha256', 'ua1'), 60);
    assert_eq(null, $r);
});

test_summary('Click dedup');
