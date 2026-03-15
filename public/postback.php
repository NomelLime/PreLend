<?php
/**
 * public/postback.php — Постбэк-эндпоинт для рекламодателей.
 *
 * Рекламодатель настраивает постбэк URL вида:
 *   https://yourdomain.me/postback.php?click_id={click_id}&adv_id=adv_001&date={date}&sig={HMAC}
 *
 * Обязательные параметры:
 *   click_id  — наш SubID (передавался рекламодателю в URL)
 *   adv_id    — ID рекламодателя (из advertisers.json)
 *
 * Опциональные параметры:
 *   date      — дата конверсии YYYY-MM-DD (дефолт: сегодня)
 *   count     — количество конверсий (дефолт: 1)
 *   payout    — выплата рекламодателя (для будущей сверки)
 *   sig       — HMAC-SHA256 подпись (если hmac_secret задан у рекламодателя)
 *
 * Ответы:
 *   {"status":"ok","conv_id":"..."}        — принято
 *   {"status":"error","message":"..."}     — ошибка
 *   {"status":"duplicate"}                 — уже записано
 *   {"status":"rate_limited"}              — слишком много запросов
 *
 * Безопасность (Этап 3 ContentHub):
 *   1. HMAC-SHA256 подпись: sig=HMAC(hmac_secret, "click_id=X&adv_id=Y&date=Z")
 *   2. IP-whitelist: поле allowed_ips в advertisers.json (пустой = без ограничений)
 *   3. Rate limit: max_postbacks_per_min постбэков/мин на один adv_id (через SQLite)
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

// ── Проверка глобального токена (обратная совместимость) ───────────────────────
$requiredToken = $settings['postback_token'] ?? '';
if ($requiredToken !== '' && isset($input['token']) && $input['token'] !== $requiredToken) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'invalid token']);
    exit;
}

// ── Найти рекламодателя ────────────────────────────────────────────────────────
$advertisers = Config::advertisers();
$advConfig   = null;
foreach ($advertisers as $adv) {
    if ($adv['id'] === $advId) {
        $advConfig = $adv;
        break;
    }
}
if ($advConfig === null) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'unknown advertiser']);
    exit;
}

// ── IP-whitelist ───────────────────────────────────────────────────────────────
$allowedIps = $advConfig['allowed_ips'] ?? [];
if (!empty($allowedIps)) {
    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
    // Поддержка X-Forwarded-For если за прокси (только от trusted source)
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $remoteIp = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    if (!in_array($remoteIp, $allowedIps, true)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'ip not whitelisted']);
        exit;
    }
}

// ── HMAC-SHA256 проверка подписи ───────────────────────────────────────────────
$hmacSecret = $advConfig['hmac_secret'] ?? '';
if ($hmacSecret !== '') {
    $sig = trim($input['sig'] ?? '');
    if ($sig === '') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'signature required']);
        exit;
    }
    // Строка для подписи: "click_id=X&adv_id=Y&date=Z" (параметры в алфавитном порядке)
    $sigPayload  = "adv_id={$advId}&click_id={$clickId}&date={$date}";
    $expectedSig = hash_hmac('sha256', $sigPayload, $hmacSecret);
    if (!hash_equals($expectedSig, strtolower($sig))) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'invalid signature']);
        exit;
    }
}

// ── Rate limiting ──────────────────────────────────────────────────────────────
// Считаем постбэки от этого adv_id за последние 60 секунд в таблице conversions
$maxPerMin = (int)($advConfig['max_postbacks_per_min'] ?? 60);
if ($maxPerMin > 0) {
    $db = DB::get();
    // Считаем постбэки за последние 60 секунд: смотрим последние $maxPerMin записей
    // и проверяем есть ли там уже лимит от этого рекламодателя.
    // Для production рекомендуется отдельная таблица с timestamp-индексом.
    $rateStmt = $db->prepare(
        "SELECT COUNT(*) FROM conversions WHERE advertiser_id = ? AND source = 'api' AND date = date('now') AND id > (SELECT COALESCE(MAX(id), 0) - ? FROM conversions)"
    );
    $rateStmt->execute([$advId, $maxPerMin]);
    $recent = (int)$rateStmt->fetchColumn();
    if ($recent >= $maxPerMin) {
        http_response_code(429);
        echo json_encode(['status' => 'rate_limited', 'message' => "max {$maxPerMin} postbacks/min exceeded"]);
        exit;
    }
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
