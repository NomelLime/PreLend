<?php
/**
 * tests/test_content_locale.php — ContentLocaleResolver, GeoDetector Accept-Language, TemplateI18n.
 */
require_once __DIR__ . '/bootstrap.php';
require_once ROOT . '/src/GeoDetector.php';
require_once ROOT . '/src/ContentLocaleResolver.php';
require_once ROOT . '/src/TemplateI18n.php';

echo "=== Content locale ===\n";

test('DE → de-DE', function (): void {
    $_SERVER['HTTP_CF_IPCOUNTRY']  = 'DE';
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US';
    $geo = new GeoDetector();
    $r   = ContentLocaleResolver::resolve($geo);
    assert_eq('de-DE', $r['content_locale']);
    assert_eq('de', $r['locale_lang']);
    assert_eq('country', $r['resolve_source']);
});

test('XX + Accept-Language ru-RU → ru-RU', function (): void {
    $_SERVER['HTTP_CF_IPCOUNTRY']    = 'XX';
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'ru-RU,ru;q=0.9';
    $geo = new GeoDetector();
    $r   = ContentLocaleResolver::resolve($geo);
    assert_eq('ru-RU', $r['content_locale']);
    assert_eq('accept_language', $r['resolve_source']);
});

test('BO (LATAM) → es-419', function (): void {
    $_SERVER['HTTP_CF_IPCOUNTRY']    = 'BO';
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US';
    $geo = new GeoDetector();
    $r   = ContentLocaleResolver::resolve($geo);
    assert_eq('es-419', $r['content_locale']);
    assert_eq('es', $r['locale_lang']);
    assert_eq('country_latam', $r['resolve_source']);
});

test('неизвестная страна LT → en-US', function (): void {
    $_SERVER['HTTP_CF_IPCOUNTRY']    = 'LT';
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'pl-PL';
    $geo = new GeoDetector();
    $r   = ContentLocaleResolver::resolve($geo);
    assert_eq('en-US', $r['content_locale']);
    assert_eq('country_unknown', $r['resolve_source']);
});

test('TemplateI18n expert_review DE содержит немецкий заголовок', function (): void {
    $i18n = TemplateI18n::forTemplate('expert_review', [
        'content_locale' => 'de-DE',
        'locale_lang'    => 'de',
    ]);
    assert_contains('Exklusiv', $i18n['page_title'] ?? '', 'German title');
});

test_summary('Content locale');
