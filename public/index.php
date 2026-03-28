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

    case FilterResult::PROBE:
        // Мониторинг / curl / wget — ответ без записи в clicks (не засоряем метрики)
        header('Content-Type: text/plain; charset=UTF-8');
        http_response_code(200);
        echo "ok\n";
        break;

    case FilterResult::CLOAK:
        // Платформенный сканер — показываем легенду
        $cloakTemplate = resolveCloakTemplate($advertisers, $geo);
        $ctx = ClickLogger::buildContext($geo, $filter, null);
        $logger->log($ctx, 'cloaked');   // ok не проверяем — cloak без SubID всегда
        renderCloak($cloakTemplate, $geo->getGeo());
        break;

    case FilterResult::OFFGEO:
    case FilterResult::OFFHOURS:
        // Трафик не по ГЕО или вне рабочего времени — клоачим как нецелевой
        $ctx = ClickLogger::buildContext($geo, $filter, null);
        $logger->log($ctx, 'cloaked');   // ok не проверяем — cloak без SubID всегда
        $cloakTemplate = resolveCloakTemplate($advertisers, $geo);
        renderCloak($cloakTemplate, $geo->getGeo());
        break;

    case FilterResult::BOT:
    case FilterResult::VPN:
    case FilterResult::TOR:
        // Нецелевой — тихая заглушка или редирект на дефолт без лога
        $ctx = ClickLogger::buildContext($geo, $filter, null);
        $logger->log($ctx, 'bot');   // ok не проверяем — bot без SubID всегда
        redirectInstant($defaultOfferUrl);
        break;

    case FilterResult::PASS:
    default:
        // Живой пользователь
        $router = new Router($advertisers, $settings, $db);
        $adv    = $router->resolve($geo->getGeo(), $filter->getDeviceType(), $filter);

        // Определяем is_test по параметру ?test=1
        $isTest = isset($_GET['test']) && $_GET['test'] === '1';

        $ip              = GeoDetector::getRealIp();
        $uaHash          = $filter->getUaHash();
        $existingClickId = $logger->isDuplicateFingerprint($ip, $uaHash, 60);

        if ($existingClickId !== null) {
            $clickId = $existingClickId;
            if ($adv !== null) {
                $url      = SubIdBuilder::build($adv, $clickId);
                $template = $adv['template'] ?? 'expert_review';
            } else {
                $url      = SubIdBuilder::buildDefault($defaultOfferUrl);
                $template = 'expert_review';
            }
        } elseif ($adv !== null) {
            $ctx     = ClickLogger::buildContext($geo, $filter, $adv, $isTest);
            $result  = $logger->log($ctx, 'sent');
            $clickId = $result['click_id'];
            if (!$result['ok']) {
                error_log('[PreLend] INSERT click failed — redirect без SubID');
                $url = $adv['url'] ?? $defaultOfferUrl;
            } else {
                $url = SubIdBuilder::build($adv, $clickId);
                $logger->recordFingerprint($ip, $uaHash, $clickId);
            }
            $template = $adv['template'] ?? 'expert_review';
        } else {
            $ctx     = ClickLogger::buildContext($geo, $filter, null, $isTest);
            $result  = $logger->log($ctx, 'sent');
            $clickId = $result['click_id'];
            $url     = SubIdBuilder::buildDefault($defaultOfferUrl);
            $template = 'expert_review';
            if ($result['ok']) {
                $logger->recordFingerprint($ip, $uaHash, $clickId);
            }
        }

        // A/B split-тест: проверяем есть ли активный тест для этого ГЕО
        $splitTester = new SplitTester($db);
        $splitVariant = $splitTester->assign($geo->getGeo(), $clickId ?? '');
        if ($splitVariant !== null && !$isTest) {
            // Используем шаблон из варианта split-теста
            $template = $splitVariant['template'] ?? $template;
        }

        $localeCtx = ContentLocaleResolver::resolve($geo);
        $i18n      = TemplateI18n::forTemplate($template, $localeCtx);
        $offerVars = array_merge($localeCtx, ['i18n' => $i18n]);

        TemplateRenderer::renderOffer($template, $url, $delayMs, $offerVars, $geo->getGeo());
        break;
}

exit;

// ── Функции вывода ────────────────────────────────────────────────────────

/**
 * Шаблон клоаки: первый активный рекламодатель под ГЕО или expert_review по умолчанию.
 *
 * @param array<int, array<string, mixed>> $advertisers
 */
function resolveCloakTemplate(array $advertisers, GeoDetector $geo): string
{
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
    return $cloakTemplate;
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
 */
function renderCloak(string $template, string $geo = ''): void
{
    if ($geo !== '') {
        TemplateRenderer::renderCloakedGeo($template, $geo);
    } else {
        TemplateRenderer::renderCloaked($template);
    }
}
