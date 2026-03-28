<?php
/**
 * tests/test_subid_builder.php — SubIdBuilder: массивы в $_GET не дают TypeError.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "=== SubIdBuilder ===\n";

$adv = [
    'url' => 'https://offer.example.com/path',
    'subid_param' => 'click_id',
];

$saveGet = $_GET;

test('строковый UTM прокидывается', function () use ($adv): void {
    $_GET = ['utm_source' => 'tiktok', 'click_id' => 'skip-me'];
    $url = SubIdBuilder::build($adv, 'cid-1');
    assert_contains('utm_source=tiktok', $url);
    assert_contains('click_id=cid-1', $url);
});

test('массив в query (utm_campaign[]) не падает и сериализуется', function () use ($adv): void {
    $_GET = ['utm_campaign' => ['a', 'b'], 'foo' => 'bar'];
    $url = SubIdBuilder::build($adv, 'cid-2');
    assert_contains('utm_campaign=a%2Cb', $url, 'http_build_query кодирует запятую');
    assert_contains('foo=bar', $url);
});

test('вложенный массив обрабатывается', function () use ($adv): void {
    $_GET = ['x' => ['y' => ['z']]];
    $url = SubIdBuilder::build($adv, 'cid-3');
    assert_true(str_contains($url, 'x='), 'параметр x присутствует');
});

test('buildDefault: массив в UTM не падает', function (): void {
    $_GET = [
        'utm_source' => ['ig', 'tt'],
        'utm_medium' => 'bio',
    ];
    $url = SubIdBuilder::buildDefault('https://default.example.com/offer');
    assert_contains('utm_source', $url);
    assert_contains('utm_medium=bio', $url);
});

$_GET = $saveGet;

test_summary('SubIdBuilder');
