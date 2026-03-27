<?php
/**
 * tests/test_postback.php — Логика глобального токена постбэка (PostbackAuth).
 */
require_once __DIR__ . '/bootstrap.php';
require_once ROOT . '/src/PostbackAuth.php';

echo "=== PostbackAuth (global token) ===\n";

test('пустой токен в настройках — всегда принято', function () {
    $ok = PostbackAuth::globalTokenValid([], ['token' => '']);
    assert_true($ok);
});

test('токен задан в settings — без token в запросе = отказ', function () {
    $ok = PostbackAuth::globalTokenValid(
        ['postback_token' => 'secret123'],
        []
    );
    assert_true(!$ok);
});

test('токен задан в settings — верный token = ок', function () {
    $ok = PostbackAuth::globalTokenValid(
        ['postback_token' => 'secret123'],
        ['token' => 'secret123']
    );
    assert_true($ok);
});

test('токен задан в settings — неверный token = отказ', function () {
    $ok = PostbackAuth::globalTokenValid(
        ['postback_token' => 'secret123'],
        ['token' => 'wrong']
    );
    assert_true(!$ok);
});

test('PL_POSTBACK_TOKEN в env приоритетнее settings', function () {
    $prev = getenv('PL_POSTBACK_TOKEN');
    putenv('PL_POSTBACK_TOKEN=from_env');
    try {
        $ok = PostbackAuth::globalTokenValid(
            ['postback_token' => 'from_settings'],
            ['token' => 'from_env']
        );
        assert_true($ok);
        $bad = PostbackAuth::globalTokenValid(
            ['postback_token' => 'from_settings'],
            ['token' => 'from_settings']
        );
        assert_true(!$bad);
    } finally {
        if ($prev === false) {
            putenv('PL_POSTBACK_TOKEN');
        } else {
            putenv('PL_POSTBACK_TOKEN=' . $prev);
        }
    }
});

test_summary('PostbackAuth');
