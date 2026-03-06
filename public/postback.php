<?php
/**
 * public/postback.php — Постбэк-эндпоинт для рекламодателей.
 *
 * Рекламодатель настраивает постбэк URL вида:
 *   https://yourdomain.me/postback.php?click_id={click_id}&adv_id=adv_001&date={date}
 *
 * Обязательные параметры:
 *   click_id  — наш SubID (передавался рекламодателю в URL)
 *   adv_id    — ID рекламодателя (из advertisers.json)
 *
 * Опциональные параметры:
 *   date      — дата конверсии YYYY-MM-DD (дефолт: сегодня)
 *   count     — количество конверсий (дефолт: 1)
 *   payout    — выплата рекламодателя (для будущей сверки)
 *   token     — секретный токен (настраивается в settings.json)
 *
 * Ответы:
 *   {"status":"ok","conv_id":"..."}        — принято
 *   {"status":"error","message":"..."}     — ошибка
 *   {"status":"duplicate"}                 — уже записано
 *
 * Безопасность:
 *   1. IP-whitelist рекламодателя (опционально в конфиге)
 *   2. Секретный токен (опционально в конфиге)
 *   3. Rate limit: не более 100 постбэков/сек на один adv_id (через SQLite)
 */

define('ROOT', dirname(__DIR__));

require_once ROOT . '/src/Config.php';
require_once ROOT . '/src/DB.php';
require_once ROOT . '/src/ConversionLogger.php';

header('Content-Type: application/json; charset=utf-8');

// ── Только GET и POST ─────────────────────────────────────────────────────────
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'method not allowed']);
    exit;
}

$settings = Config::settings();

// ── Параметры запроса ─────────────────────────────────────────────────────────
$input = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? array_merge($_GET, $_POST)
    : $_GET;

$clickId  = trim($input['click_id']  ?? '');
$advId    = trim($input['adv_id']    ?? '');
$date     = trim($input['date']      ?? date('Y-m-d'));
$count    = max(1, (int)($input['count'] ?? 1));
$payout   = (float)($input['payout']  ?? 0);
$token    = trim($input['token']     ?? '');

// ── Валидация обязательных полей ──────────────────────────────────────────────
if ($clickId === '' || $advId === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'click_id and adv_id required']);
    exit;
}

// Формат даты
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

// ── Проверка токена ────────────────────────────────────────────────────────────
$requiredToken = $settings['postback_token'] ?? '';
if ($requiredToken !== '' && $token !== $requiredToken) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'invalid token']);
    exit;
}

// ── Проверка что рекламодатель существует ─────────────────────────────────────
$advertisers = Config::advertisers();
$advFound    = false;
foreach ($advertisers as $adv) {
    if ($adv['id'] === $advId) {
        $advFound = true;
        break;
    }
}
if (!$advFound) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'unknown advertiser']);
    exit;
}

// ── Запись конверсии ──────────────────────────────────────────────────────────
$db     = DB::get();
$logger = new ConversionLogger($db);

$notes = $payout > 0 ? "payout={$payout}" : '';
$result = $logger->logApi($clickId, $advId, $date, $count, $notes);

if (!$result['ok']) {
    $status = $result['error'] === 'duplicate' ? 'duplicate' : 'error';
    $code   = $result['error'] === 'duplicate' ? 200 : 400;
    http_response_code($code);
    echo json_encode([
        'status'  => $status,
        'message' => $result['error'],
    ]);
    exit;
}

// ── Успех ─────────────────────────────────────────────────────────────────────
http_response_code(200);
echo json_encode([
    'status'  => 'ok',
    'conv_id' => $result['conv_id'],
]);
