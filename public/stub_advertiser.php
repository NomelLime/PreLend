<?php
declare(strict_types=1);
/**
 * stub_advertiser.php — тестовая «лендинг»-заглушка рекламодателя adv_stub.
 *
 * Ожидает в URL параметр click_id (как в advertisers.json → subid_param).
 * Показывает полный конфиг (секрет HMAC в интерфейсе замаскирован), строку для подписи
 * и готовую ссылку GET либо форму POST на эту страницу → редирект на postback.php с верным HMAC.
 *
 * Перед продакшеном: смените hmac_secret у adv_stub, при необходимости url в advertisers.json
 * (должен указывать на реальный хост, где открыта эта страница).
 */

define('ROOT', dirname(__DIR__));

require_once ROOT . '/src/Config.php';

const STUB_ADV_ID = 'adv_stub';

/**
 * @return array{0: string, 1: array<string, mixed>|null}
 */
function resolveStubAdvertiser(): array
{
    foreach (Config::advertisers() as $adv) {
        if (($adv['id'] ?? '') === STUB_ADV_ID) {
            return [STUB_ADV_ID, $adv];
        }
    }
    return [STUB_ADV_ID, null];
}

function postbackEndpoint(): string
{
    $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/stub_advertiser.php';
    $dir    = rtrim(str_replace('\\', '/', dirname($script)), '/');
    $base   = ($dir === '' || $dir === '.') ? '' : $dir;

    return $scheme . '://' . $host . $base . '/postback.php';
}

function maskSecret(string $secret): string
{
    if ($secret === '') {
        return '— (подпись не используется)';
    }
    $len = strlen($secret);
    if ($len <= 10) {
        return str_repeat('•', $len);
    }

    return substr($secret, 0, 4) . '…' . substr($secret, -4) . ' (' . $len . ' симв.)';
}

function buildSigPayload(string $advId, string $clickId, string $date): string
{
    return "adv_id={$advId}&click_id={$clickId}&date={$date}";
}

[$advId, $adv] = resolveStubAdvertiser();

if ($adv === null) {
    header('Content-Type: text/html; charset=utf-8');
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>adv_stub</title></head><body>';
    echo '<p>В config/advertisers.json нет рекламодателя с id <code>' . htmlspecialchars(STUB_ADV_ID, ENT_QUOTES, 'UTF-8') . '</code>.</p>';
    echo '</body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fire_postback') {
    $postbackBase = postbackEndpoint();
    $hmacSecret   = (string)($adv['hmac_secret'] ?? '');
    $pClickId     = trim((string)($_POST['click_id'] ?? ''));
    $pDate        = trim((string)($_POST['date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pDate)) {
        $pDate = date('Y-m-d');
    }
    $pCount  = max(1, (int)($_POST['count'] ?? 1));
    $pPayout = (string)($_POST['payout'] ?? '0');
    $addSig  = isset($_POST['with_sig']) && $hmacSecret !== '';

    if ($pClickId === '') {
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(400);
        echo '<p>Нужен <code>click_id</code>.</p><p><a href="stub_advertiser.php">Назад</a></p>';
        exit;
    }

    $q = [
        'click_id' => $pClickId,
        'adv_id'   => $advId,
        'date'     => $pDate,
        'count'    => (string)$pCount,
        'payout'   => $pPayout,
    ];
    if ($addSig) {
        $q['sig'] = hash_hmac('sha256', buildSigPayload($advId, $pClickId, $pDate), $hmacSecret);
    }

    header('Location: ' . $postbackBase . '?' . http_build_query($q), true, 302);
    exit;
}

header('Content-Type: text/html; charset=utf-8');

$subParam = $adv['subid_param'] ?? 'click_id';
$clickId  = trim((string)($_GET[$subParam] ?? $_GET['click_id'] ?? ''));
$date     = trim((string)($_GET['date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

$countDefault  = max(1, (int)($_GET['count'] ?? 1));
$payoutDefault = (string)($_GET['payout'] ?? '12.34');

$hmacSecret = (string)($adv['hmac_secret'] ?? '');
$sigPayload = buildSigPayload($advId, $clickId, $date);
$sig        = $hmacSecret !== '' ? hash_hmac('sha256', $sigPayload, $hmacSecret) : '';

$postbackBase = postbackEndpoint();

$query = [
    'click_id' => $clickId,
    'adv_id'   => $advId,
    'date'     => $date,
    'count'    => (string)$countDefault,
    'payout'   => $payoutDefault,
];
if ($sig !== '') {
    $query['sig'] = $sig;
}
$getUrl = $postbackBase . '?' . http_build_query($query);

$advForJson = $adv;
$advForJson['hmac_secret'] = maskSecret($hmacSecret);

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Заглушка рекламодателя — <?= htmlspecialchars($adv['name'] ?? $advId, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root { font-family: system-ui, sans-serif; line-height: 1.5; color: #1a1a1a; }
        body { max-width: 52rem; margin: 2rem auto; padding: 0 1rem; }
        h1 { font-size: 1.35rem; }
        .warn { background: #fff8e6; border: 1px solid #e6c200; padding: 0.75rem 1rem; border-radius: 6px; margin: 1rem 0; }
        code, pre { font-size: 0.85rem; background: #f4f4f5; padding: 0.15rem 0.35rem; border-radius: 4px; }
        pre { padding: 1rem; overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; margin: 1rem 0; font-size: 0.9rem; }
        th, td { border: 1px solid #ddd; padding: 0.4rem 0.6rem; text-align: left; vertical-align: top; }
        th { background: #f0f0f1; width: 11rem; }
        .actions { display: flex; flex-wrap: wrap; gap: 0.5rem; margin: 1rem 0; align-items: center; }
        a.btn, button { display: inline-block; padding: 0.45rem 0.9rem; border-radius: 6px; text-decoration: none; font-size: 0.9rem; cursor: pointer; border: 1px solid #ccc; background: #fff; color: inherit; }
        a.btn-primary, button.primary { background: #2563eb; color: #fff; border-color: #1d4ed8; }
        label { display: block; margin: 0.35rem 0 0.15rem; font-size: 0.85rem; }
        input { width: 100%; max-width: 22rem; padding: 0.35rem 0.5rem; font-size: 0.9rem; }
        fieldset { border: 1px solid #ddd; border-radius: 6px; margin: 1rem 0; padding: 0.75rem 1rem; }
        legend { padding: 0 0.35rem; }
    </style>
</head>
<body>
    <h1><?= htmlspecialchars($adv['name'] ?? 'Заглушка', ENT_QUOTES, 'UTF-8') ?></h1>
    <p>ID: <code><?= htmlspecialchars($advId, ENT_QUOTES, 'UTF-8') ?></code>. Эмуляция лендинга партнёра: пришёл клик с вашим SubID в параметре <code><?= htmlspecialchars($subParam, ENT_QUOTES, 'UTF-8') ?></code>.</p>

    <div class="warn">
        Низкая ставка (<code>rate: <?= htmlspecialchars((string)($adv['rate'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code>), чтобы не перехватывать живой трафик.
        Для проверки удобнее открыть преленд с <code>?test=1</code> или временно отключить других рекламодателей.
        URL в конфиге сейчас <code><?= htmlspecialchars((string)($adv['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code> — при другом хосте замените его в <code>config/advertisers.json</code>.
    </div>

    <h2>Текущий клик</h2>
    <table>
        <tr><th>click_id</th><td><?= $clickId !== '' ? '<code>' . htmlspecialchars($clickId, ENT_QUOTES, 'UTF-8') . '</code>' : '<em>не передан — укажите в URL или форме ниже</em>' ?></td></tr>
        <tr><th>date (конверсии)</th><td><code><?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8') ?></code></td></tr>
        <tr><th>Строка для HMAC</th><td><code><?= htmlspecialchars($sigPayload, ENT_QUOTES, 'UTF-8') ?></code></td></tr>
        <tr><th>sig (SHA-256)</th><td><?= $sig !== '' ? '<code>' . htmlspecialchars($sig, ENT_QUOTES, 'UTF-8') . '</code>' : '<em>секрет пуст — подпись не нужна</em>' ?></td></tr>
        <tr><th>postback.php</th><td><code><?= htmlspecialchars($postbackBase, ENT_QUOTES, 'UTF-8') ?></code></td></tr>
    </table>

    <div class="actions">
        <?php if ($clickId !== '' && $sig !== '') : ?>
            <a class="btn btn-primary" href="<?= htmlspecialchars($getUrl, ENT_QUOTES, 'UTF-8') ?>">GET постбэк (с подписью)</a>
        <?php elseif ($clickId !== '' && $sig === '') : ?>
            <a class="btn btn-primary" href="<?= htmlspecialchars($getUrl, ENT_QUOTES, 'UTF-8') ?>">GET постбэк (без подписи)</a>
        <?php else : ?>
            <span class="btn" style="opacity:0.6;cursor:not-allowed">Сначала укажите click_id</span>
        <?php endif; ?>
    </div>

    <h2>Полный JSON рекламодателя (секрет замаскирован)</h2>
    <pre><?= htmlspecialchars(json_encode($advForJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?></pre>

    <h2>Ручная отправка</h2>
    <p>Форма шлёт POST сюда; сервер считает <code>sig</code> и перенаправляет на <code>postback.php</code> (ответ вы увидите как JSON в браузере).</p>
    <form method="post" action="stub_advertiser.php">
        <input type="hidden" name="action" value="fire_postback">
        <fieldset>
            <legend>Параметры постбэка</legend>
            <label for="f_click">click_id</label>
            <input id="f_click" name="click_id" value="<?= htmlspecialchars($clickId, ENT_QUOTES, 'UTF-8') ?>" required>

            <label for="f_date">date</label>
            <input id="f_date" name="date" value="<?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8') ?>">

            <label for="f_count">count</label>
            <input id="f_count" name="count" type="number" min="1" value="<?= (int)$countDefault ?>">

            <label for="f_payout">payout</label>
            <input id="f_payout" name="payout" value="<?= htmlspecialchars($payoutDefault, ENT_QUOTES, 'UTF-8') ?>">
        </fieldset>
        <?php if ($hmacSecret !== '') : ?>
            <p><label><input type="checkbox" name="with_sig" value="1" checked> Добавить HMAC <code>sig</code> (обязательно, пока у adv_stub задан <code>hmac_secret</code>)</label></p>
        <?php endif; ?>
        <button type="submit" class="primary">Отправить постбэк</button>
    </form>
</body>
</html>
