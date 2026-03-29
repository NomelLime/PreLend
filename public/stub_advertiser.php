<?php
declare(strict_types=1);
/**
 * stub_advertiser.php — плейсхолдер лендинга для adv_stub.
 *
 * Обычные посетители: лаконичная страница на английском.
 * Техника (HMAC, JSON, тест постбэка): добавьте ?debug=1
 *
 * Ожидает SubID в параметре из advertisers.json → subid_param (по умолчанию click_id).
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
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Unavailable</title></head><body>';
    echo '<p>Configuration error: advertiser <code>' . htmlspecialchars(STUB_ADV_ID, ENT_QUOTES, 'UTF-8') . '</code> is missing.</p>';
    echo '</body></html>';
    exit;
}

$debugMode = isset($_GET['debug']) && (string)$_GET['debug'] === '1';

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
        $back = 'stub_advertiser.php';
        if (isset($_POST['debug_return'])) {
            $back .= '?debug=1';
        }
        echo '<p>Нужен <code>click_id</code>.</p><p><a href="' . htmlspecialchars($back, ENT_QUOTES, 'UTF-8') . '">Назад</a></p>';
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

$formAction = 'stub_advertiser.php';
if ($debugMode) {
    $formAction .= '?debug=1';
}

?>
<!DOCTYPE html>
<html lang="<?= $debugMode ? 'ru' : 'en' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $debugMode ? 'Debug — ' . htmlspecialchars($adv['name'] ?? $advId, ENT_QUOTES, 'UTF-8') : 'Welcome' ?></title>
    <style>
        :root {
            font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
            line-height: 1.5;
            color: #1c1917;
        }
        * { box-sizing: border-box; }
        /* —— Публичная заглушка —— */
        .placeholder {
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1.25rem;
            background: linear-gradient(165deg, #f8fafc 0%, #eef2ff 45%, #faf5ff 100%);
        }
        .placeholder-card {
            max-width: 28rem;
            text-align: center;
        }
        .placeholder h1 {
            margin: 0 0 0.75rem;
            font-size: clamp(1.5rem, 4vw, 1.85rem);
            font-weight: 600;
            letter-spacing: -0.02em;
            color: #0f172a;
        }
        .placeholder p {
            margin: 0;
            font-size: 1.05rem;
            color: #64748b;
        }
        .placeholder .contact-email {
            margin-top: 1.25rem;
        }
        .placeholder .contact-email a {
            color: #2563eb;
            font-weight: 500;
            text-decoration: underline;
            text-underline-offset: 0.15em;
        }
        .placeholder .contact-email a:hover {
            color: #1d4ed8;
        }
        /* —— Режим отладки —— */
        .debug-wrap { max-width: 52rem; margin: 2rem auto; padding: 0 1rem; }
        .debug-wrap h1 { font-size: 1.35rem; }
        .warn { background: #fff8e6; border: 1px solid #e6c200; padding: 0.75rem 1rem; border-radius: 6px; margin: 1rem 0; }
        code, pre { font-size: 0.85rem; background: #f4f4f5; padding: 0.15rem 0.35rem; border-radius: 4px; }
        pre { padding: 1rem; overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; margin: 1rem 0; font-size: 0.9rem; }
        th, td { border: 1px solid #ddd; padding: 0.4rem 0.6rem; text-align: left; vertical-align: top; }
        th { background: #f0f0f1; width: 11rem; }
        .actions { display: flex; flex-wrap: wrap; gap: 0.5rem; margin: 1rem 0; align-items: center; }
        a.btn, button {
            display: inline-block; padding: 0.45rem 0.9rem; border-radius: 6px; text-decoration: none;
            font-size: 0.9rem; cursor: pointer; border: 1px solid #ccc; background: #fff; color: inherit;
        }
        a.btn-primary, button.primary { background: #2563eb; color: #fff; border-color: #1d4ed8; }
        label { display: block; margin: 0.35rem 0 0.15rem; font-size: 0.85rem; }
        input { width: 100%; max-width: 22rem; padding: 0.35rem 0.5rem; font-size: 0.9rem; }
        fieldset { border: 1px solid #ddd; border-radius: 6px; margin: 1rem 0; padding: 0.75rem 1rem; }
        legend { padding: 0 0.35rem; }
    </style>
</head>
<body>
<?php if (!$debugMode) : ?>
    <main class="placeholder">
        <div class="placeholder-card">
            <h1>Your ad could be here.</h1>
            <p>Thank you for visiting.</p>
            <p class="contact-email">
                <a href="mailto:YourAdvPa@proton.me">YourAdvPa@proton.me</a>
            </p>
        </div>
    </main>
<?php else : ?>
    <div class="debug-wrap">
        <h1><?= htmlspecialchars($adv['name'] ?? 'Заглушка', ENT_QUOTES, 'UTF-8') ?></h1>
        <p>ID: <code><?= htmlspecialchars($advId, ENT_QUOTES, 'UTF-8') ?></code>. SubID в параметре <code><?= htmlspecialchars($subParam, ENT_QUOTES, 'UTF-8') ?></code>.</p>

        <div class="warn">
            Низкая ставка (<code>rate: <?= htmlspecialchars((string)($adv['rate'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code>).
            Без <code>?debug=1</code> посетителям показывается только плейсхолдер на английском.
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

        <h2>JSON рекламодателя (секрет замаскирован)</h2>
        <pre><?= htmlspecialchars(json_encode($advForJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?></pre>

        <h2>Ручная отправка постбэка</h2>
        <p>POST сюда → редирект на <code>postback.php</code> (ответ — JSON).</p>
        <form method="post" action="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="fire_postback">
            <input type="hidden" name="debug_return" value="1">
            <fieldset>
                <legend>Параметры</legend>
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
                <p><label><input type="checkbox" name="with_sig" value="1" checked> HMAC <code>sig</code></label></p>
            <?php endif; ?>
            <button type="submit" class="primary">Отправить постбэк</button>
        </form>
    </div>
<?php endif; ?>
</body>
</html>
