<?php
declare(strict_types=1);
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

    case FilterResult::CLOAK:
        // Платформенный сканер — показываем легенду
        // Определяем шаблон по первому подходящему рекламодателю для этого ГЕО
        $cloakTemplate = 'expert_review';
        foreach ($advertisers as $a) {
            if (($a['status'] ?? '') === 'active') {
                $geos = $a['geo'] ?? [];
                if (empty($geos) || in_array($geo->getGeo(), $geos, true)) {
                    $cloakTemplate = $a['template'] ?? 'expert_review';
                    break;
                }
            }
        }
        $ctx = ClickLogger::buildContext($geo, $filter, null);
        $logger->log($ctx, 'cloaked');
        renderCloak($cloakTemplate, $geo->getGeo());
        break;

    case FilterResult::OFFGEO:
    case FilterResult::OFFHOURS:
        // Трафик не по ГЕО или вне рабочего времени — клоачим как нецелевой
        $ctx = ClickLogger::buildContext($geo, $filter, null);
        $logger->log($ctx, 'cloaked');
        $cloakTemplate = 'expert_review';
        foreach ($advertisers as $a) {
            if (($a['status'] ?? '') === 'active') {
                $geos = $a['geo'] ?? [];
                if (empty($geos) || in_array($geo->getGeo(), $geos, true)) {
                    $cloakTemplate = $a['template'] ?? 'expert_review';
                    break;
                }
            }
        }
        renderCloak($cloakTemplate, $geo->getGeo());
        break;

    case FilterResult::BOT:
    case FilterResult::VPN:
    case FilterResult::TOR:
        // Нецелевой — тихая заглушка или редирект на дефолт без лога
        $ctx = ClickLogger::buildContext($geo, $filter, null);
        $logger->log($ctx, 'bot');
        redirectInstant($defaultOfferUrl);
        break;

    case FilterResult::PASS:
    default:
        // Живой пользователь
        $router = new Router($advertisers, $settings, $db);
        $adv    = $router->resolve($geo->getGeo(), $filter->getDeviceType(), $filter);

        // Определяем is_test по параметру ?test=1
        $isTest = isset($_GET['test']) && $_GET['test'] === '1';

        if ($adv !== null) {
            $ctx     = ClickLogger::buildContext($geo, $filter, $adv, $isTest);
            $clickId = $logger->log($ctx, 'sent');
            // Если INSERT упал — не передаём мёртвый click_id рекламодателю
            if ($logger->lastInsertFailed) {
                error_log('[PreLend] INSERT click failed — redirect без SubID');
                $url = $adv['url'] ?? $defaultOfferUrl;
            } else {
                $url = SubIdBuilder::build($adv, $clickId);
            }
            $template = $adv['template'] ?? 'expert_review';
        } else {
            // Нет подходящего рекламодателя → дефолтный оффер
            $ctx     = ClickLogger::buildContext($geo, $filter, null, $isTest);
            $clickId = $logger->log($ctx, 'sent');
            $url     = SubIdBuilder::buildDefault($defaultOfferUrl);
            $template = 'expert_review';
        }

        // A/B split-тест: проверяем есть ли активный тест для этого ГЕО
        $splitTester = new SplitTester($db);
        $splitVariant = $splitTester->assign($geo->getGeo(), $clickId ?? '');
        if ($splitVariant !== null && !$isTest) {
            // Используем шаблон из варианта split-теста
            $template = $splitVariant['template'] ?? $template;
        }

        TemplateRenderer::renderOffer($template, $url, $delayMs, [], $geo->getGeo());
        break;
}

exit;

// ── Функции вывода ────────────────────────────────────────────────────────


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
 */
function renderCloak(string $template, string $geo = ''): void
{
    if ($geo !== '') {
        TemplateRenderer::renderCloakedGeo($template, $geo);
    } else {
        TemplateRenderer::renderCloaked($template);
    }
}
