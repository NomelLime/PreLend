<?php
/**
 * tests/test_content_locale.php — ContentLocaleResolver, GeoDetector Accept-Language, TemplateI18n.
 */
require_once __DIR__ . '/bootstrap.php';
require_once ROOT . '/src/GeoDetector.php';
require_once ROOT . '/src/ContentLocaleResolver.php';
require_once ROOT . '/src/TemplateI18n.php';

echo "=== Content locale ===\n";

test('Accept-Language de-DE rescue → de-DE', function (): void {
    unset($_SERVER['HTTP_CF_IPCOUNTRY']);
    unset($_SERVER['REMOTE_ADDR']);
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,de;q=0.9';
    $geo = new GeoDetector();
    $r   = ContentLocaleResolver::resolve($geo);
    assert_eq('de-DE', $r['content_locale']);
    assert_eq('de', $r['locale_lang']);
    assert_eq('country', $r['resolve_source']);
});

test('Accept-Language ru-RU rescue → ru-RU', function (): void {
    unset($_SERVER['HTTP_CF_IPCOUNTRY']);
    unset($_SERVER['REMOTE_ADDR']);
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'ru-RU,ru;q=0.9';
    $geo = new GeoDetector();
    $r   = ContentLocaleResolver::resolve($geo);
    assert_eq('ru-RU', $r['content_locale']); // country=RU из rescue региона
    assert_eq('country', $r['resolve_source']);
});

test('Accept-Language es-BO rescue → es-419', function (): void {
    unset($_SERVER['HTTP_CF_IPCOUNTRY']);
    unset($_SERVER['REMOTE_ADDR']);
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'es-BO,es;q=0.9';
    $geo = new GeoDetector();
    $r   = ContentLocaleResolver::resolve($geo);
    assert_eq('es-419', $r['content_locale']);
    assert_eq('es', $r['locale_lang']);
    assert_eq('country_latam', $r['resolve_source']);
});

test('без региона в Accept-Language -> default en-US', function (): void {
    unset($_SERVER['HTTP_CF_IPCOUNTRY']);
    unset($_SERVER['REMOTE_ADDR']);
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'pl,ru;q=0.9';
    $geo = new GeoDetector();
    $r   = ContentLocaleResolver::resolve($geo);
    assert_eq('en-US', $r['content_locale']);
    assert_eq('default', $r['resolve_source']);
});

test('TemplateI18n expert_review DE содержит немецкий заголовок', function (): void {
    $i18n = TemplateI18n::forTemplate('expert_review', [
        'content_locale' => 'de-DE',
        'locale_lang'    => 'de',
    ]);
    assert_contains('Exklusiv', $i18n['page_title'] ?? '', 'German title');
});

test_summary('Content locale');
