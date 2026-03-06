<?php
/**
 * public/index.php — точка входа PreLend
 *
 * Порядок выполнения:
 *   1. Автозагрузка классов
 *   2. GeoDetector — определяем ГЕО из CF-IPCountry
 *   3. BotFilter   — проверяем запрос (7 фильтров)
 *   4. Router      — выбираем лучшего рекламодателя по Score
 *   5. ClickLogger — записываем клик в SQLite
 *   6. SubIdBuilder — строим финальный URL
 *   7. Редирект    — JS + meta fallback (без задержки для ботов/клоака)
 */

define('ROOT', dirname(__DIR__));

// ── Автозагрузка ──────────────────────────────────────────────────────────
spl_autoload_register(function (string $class): void {
    $path = ROOT . '/src/' . $class . '.php';
    if (file_exists($path)) {
        require_once $path;
    }
});

// ── Обработка ошибок ──────────────────────────────────────────────────────
set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    error_log("[PreLend][ERROR] $errstr in $errfile:$errline");
    return true;
});

// ── Инициализация ─────────────────────────────────────────────────────────
$settings    = Config::settings();
$advertisers = Config::advertisers();
$db          = DB::get();

$defaultOfferUrl = $settings['default_offer_url'] ?? '';
$cloakUrl        = $settings['cloak_url']         ?? $defaultOfferUrl;
$delayMs         = (int)($settings['redirect_delay_ms'] ?? 1500);

// ── 1. ГЕО ───────────────────────────────────────────────────────────────
$geo = new GeoDetector();

// ── 2. Фильтрация ─────────────────────────────────────────────────────────
$filter = new BotFilter();
$filterResult = $filter->check($geo);

$logger = new ClickLogger($db);

// ── 3. Роутинг и редирект ─────────────────────────────────────────────────
switch ($filterResult) {

    case BotFilter::CLOAK:
        // Платформенный сканер — показываем легенду (шаблон определяется в Этапе 3)
        $ctx = ClickLogger::buildContext($geo, $filter, null);
        $logger->log($ctx, 'cloaked');
        renderCloak($cloakUrl);
        break;

    case BotFilter::BOT:
    case BotFilter::VPN:
    case BotFilter::TOR:
        // Нецелевой — тихая заглушка или редирект на дефолт без лога
        $ctx = ClickLogger::buildContext($geo, $filter, null);
        $logger->log($ctx, 'bot');
        redirectInstant($defaultOfferUrl);
        break;

    case BotFilter::PASS:
    default:
        // Живой пользователь
        $router = new Router($advertisers, $settings, $db);
        $adv    = $router->resolve($geo->getGeo(), $filter->getDeviceType(), $filter);

        // Определяем is_test по параметру ?test=1
        $isTest = isset($_GET['test']) && $_GET['test'] === '1';

        if ($adv !== null) {
            $ctx     = ClickLogger::buildContext($geo, $filter, $adv, $isTest);
            $clickId = $logger->log($ctx, 'sent');
            $url     = SubIdBuilder::build($adv, $clickId);
        } else {
            // Нет подходящего рекламодателя → дефолтный оффер
            $ctx     = ClickLogger::buildContext($geo, $filter, null, $isTest);
            $clickId = $logger->log($ctx, 'sent');
            $url     = SubIdBuilder::buildDefault($defaultOfferUrl);
        }

        redirectDelayed($url, $delayMs);
        break;
}

exit;

// ── Функции вывода ────────────────────────────────────────────────────────

/**
 * Редирект с небольшой задержкой (живой пользователь).
 * JS + meta fallback. Страница рендерится ДО редиректа.
 * Этап 3 заменит body на клоак-шаблон.
 */
function redirectDelayed(string $url, int $delayMs): void
{
    if ($url === '') {
        http_response_code(404);
        exit;
    }

    $safeUrl   = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $delayReal = max(500, $delayMs);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="refresh" content="<?= (int)($delayReal / 1000) ?>;url=<?= $safeUrl ?>">
<title>Loading...</title>
<style>
  body{margin:0;background:#111;display:flex;align-items:center;justify-content:center;height:100vh}
  .loader{width:48px;height:48px;border:5px solid #333;border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite}
  @keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body>
<div class="loader"></div>
<script>
  setTimeout(function(){window.location.href="<?= addslashes($url) ?>";},<?= $delayReal ?>);
</script>
</body>
</html>
<?php
}

/**
 * Мгновенный редирект (боты, VPN, Tor).
 */
function redirectInstant(string $url): void
{
    if ($url === '') {
        http_response_code(404);
        exit;
    }
    header('Location: ' . $url, true, 302);
    exit;
}

/**
 * Отдаём клоак-страницу (легенду) для платформенных сканеров.
 * Этап 3: здесь будет подключаться шаблон по $advertiser['template'].
 */
function renderCloak(string $fallbackUrl): void
{
    http_response_code(200);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Expert Tips &amp; Reviews</title>
<meta name="description" content="Professional tips, strategies and expert reviews.">
</head>
<body>
<h1>Expert Tips &amp; Reviews</h1>
<p>Welcome! Explore our expert articles and strategies.</p>
</body>
</html>
<?php
}
