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
require_once ROOT . '/src/PostbackAuth.php';
require_once ROOT . '/src/ContentHubEvents.php';

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

// ── Проверка глобального токена (PL_POSTBACK_TOKEN в env приоритетнее settings) ─
if (!PostbackAuth::globalTokenValid($settings, $input)) {
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
    // Используем CF-Connecting-IP (Cloudflare подставляет реальный IP клиента).
    // REMOTE_ADDR за CF = IP узла Cloudflare. HTTP_X_FORWARDED_FOR подделывается
    // клиентом — не использовать для whitelist. Аналогично ClickLogger::getRealIp().
    $remoteIp = trim($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
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
// Считаем постбэки от этого adv_id за последние 60 секунд по полю created_at (Unix timestamp)
$maxPerMin = (int)($advConfig['max_postbacks_per_min'] ?? 60);
if ($maxPerMin > 0) {
    $db = DB::get();
    $rateStmt = $db->prepare(
        "SELECT COUNT(*) FROM conversions
         WHERE advertiser_id = ? AND source = 'api'
           AND created_at >= CAST(strftime('%s', 'now') AS INTEGER) - 60"
    );
    $rateStmt->execute([$advId]);
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
    if (($result['error'] ?? '') !== 'duplicate') {
        $retryFile = ROOT . '/data/postback_retry.jsonl';
        $retryEntry = json_encode([
            'click_id'  => $clickId,
            'adv_id'    => $advId,
            'date'      => $date,
            'count'     => $count,
            'payout'    => $payout,
            'notes'     => $notes,
            'failed_at' => time(),
            'error'     => $result['error'] ?? 'unknown',
        ], JSON_UNESCAPED_UNICODE);
        @file_put_contents($retryFile, $retryEntry . "\n", FILE_APPEND | LOCK_EX);
    }
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
ContentHubEvents::pushConversion($advId, $clickId, (string) $result['conv_id']);

http_response_code(200);
echo json_encode([
    'status'  => 'ok',
    'conv_id' => $result['conv_id'],
]);
