<?php
declare(strict_types=1);
/**
 * TemplateRenderer.php
 *
 * Загружает PHP-шаблон из templates/ и рендерит его с переданными переменными.
 *
 * Два типа шаблонов:
 *   cloaked/ — статичные SEO-страницы-легенды для платформенных ботов
 *   offers/  — страницы-прокладки для живых пользователей (с CTA + авторедиректом)
 *
 * [FIX#17] При отсутствии шаблона (включая fallback на default) → 404, не 200.
 *   Было: http_response_code(200) → поисковики индексировали пустые страницы,
 *         мониторинг не замечал проблему (HTTP 200 = "всё ок").
 *   Стало: http_response_code(404) → корректный сигнал для логов и мониторинга.
 */
class TemplateRenderer
{
    private const TEMPLATE_DIR    = ROOT . '/templates/';
    private const DEFAULT_TEMPLATE = 'expert_review';

    /**
     * Рендерит шаблон легенды (для платформенных ботов).
     * Без редиректа, чистый статичный HTML.
     *
     * @param string $template  Имя шаблона (expert_review | sports_news)
     * @param array  $vars      Переменные в шаблон
     */
    public static function renderCloaked(string $template, array $vars = []): void
    {
        // Клоака: язык контента всегда English (политика продукта).
        $vars['content_locale'] = 'en-US';
        $vars['locale_lang']    = 'en';
        $vars['resolve_source'] = 'cloak';
        $vars['i18n'] = TemplateI18n::forTemplate($template, [
            'content_locale' => 'en-US',
            'locale_lang'    => 'en',
        ]);
        self::render('cloaked', $template, $vars);
    }

    /**
     * Рендерит страницу-прокладку для живого пользователя.
     * Содержит CTA-кнопку + JS-авторедирект.
     *
     * @param string $template   Имя шаблона
     * @param string $targetUrl  URL для редиректа
     * @param int    $delayMs    Задержка в миллисекундах
     * @param array  $vars       Дополнительные переменные
     * @param string $geo        ISO-2 код страны для гео-адаптации
     */
    public static function renderOffer(
        string $template,
        string $targetUrl,
        int    $delayMs = 1500,
        array  $vars    = [],
        string $geo     = ''
    ): void {
        $vars['target_url'] = $targetUrl;
        $vars['delay_ms']   = max(500, $delayMs);
        $vars['delay_sec']  = (int) ceil($delayMs / 1000);
        if ($geo !== '') {
            $geoCtx = GeoAdapter::context($geo);
            $vars   = array_merge($geoCtx, $vars);
        }
        if (!isset($vars['i18n']) || !is_array($vars['i18n'])) {
            $vars['i18n'] = TemplateI18n::forTemplate($template, [
                'content_locale' => $vars['content_locale'] ?? 'en-US',
                'locale_lang'    => $vars['locale_lang'] ?? 'en',
            ]);
        }
        self::render('offers', $template, $vars);
    }

    /**
     * Рендерит cloaked-страницу с гео-адаптацией.
     */
    public static function renderCloakedGeo(string $template, string $geo, array $vars = []): void
    {
        if ($geo !== '') {
            $geoCtx = GeoAdapter::context($geo);
            $vars   = array_merge($geoCtx, $vars);
        }
        // Клоака: текст всегда на английском; GEO остаётся для валюты/города в данных.
        $vars['content_locale'] = 'en-US';
        $vars['locale_lang']    = 'en';
        $vars['resolve_source'] = 'cloak';
        $vars['i18n'] = TemplateI18n::forTemplate($template, [
            'content_locale' => 'en-US',
            'locale_lang'    => 'en',
        ]);
        self::render('cloaked', $template, $vars);
    }

    // ── Приватные методы ──────────────────────────────────────────────────

    private static function render(string $type, string $template, array $vars): void
    {
        $template = preg_replace('/[^a-z0-9_\-]/', '', strtolower($template));
        if ($template === '') {
            $template = self::DEFAULT_TEMPLATE;
        }

        $path = self::TEMPLATE_DIR . $type . '/' . $template . '.php';

        if (!file_exists($path)) {
            // Fallback на дефолтный шаблон
            $path = self::TEMPLATE_DIR . $type . '/' . self::DEFAULT_TEMPLATE . '.php';
        }

        if (!file_exists($path)) {
            // [FIX#17] Шаблон не найден даже после fallback → 404, не 200.
            // Было: http_response_code(200) — поисковики индексировали пустые страницы,
            //       мониторинг не замечал проблему.
            // Стало: http_response_code(404) — корректный HTTP-статус для отсутствия ресурса.
            error_log('[PreLend][TemplateRenderer] Template not found: ' . $type . '/' . $template . '.php');
            http_response_code(404);
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>Not Found</body></html>';
            return;
        }

        // Экспортируем переменные в область видимости шаблона
        extract($vars, EXTR_SKIP);

        ob_start();
        include $path;
        $html = ob_get_clean();

        http_response_code(200);
        echo $html;
    }
}
