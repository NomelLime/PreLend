<?php
/**
 * public/index.php — точка входа PreLend
 *
 * Этап 1: заглушка — убеждаемся, что структура рабочая.
 * Полная логика роутера — Этап 2.
 */

define('ROOT', dirname(__DIR__));

require_once ROOT . '/src/Config.php';
require_once ROOT . '/src/DB.php';

// Проверка работоспособности БД и конфигов
try {
    $db       = DB::get();
    $settings = Config::settings();
    $advs     = Config::advertisers();

    // Временный ответ для теста окружения
    header('Content-Type: application/json');
    echo json_encode([
        'status'      => 'ok',
        'db'          => 'connected',
        'advertisers' => count($advs),
        'stage'       => 1,
    ]);
} catch (Throwable $e) {
    error_log('[PreLend] Bootstrap error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal error']);
}
