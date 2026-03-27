<?php
declare(strict_types=1);
/**
 * Строки оффер-шаблонов по локали. Файлы: templates/i18n/{template}.json
 * Ключ верхнего уровня — язык (en, de, ru, es, uk, pl, pt, …), внутри — плоские ключи строк.
 */
class TemplateI18n
{
    private const I18N_DIR = ROOT . '/templates/i18n/';

    /**
     * @param array{content_locale?: string, locale_lang?: string} $localeCtx
     * @return array<string, string>
     */
    public static function forTemplate(string $template, array $localeCtx): array
    {
        $template = preg_replace('/[^a-z0-9_\-]/', '', strtolower($template));
        if ($template === '') {
            $template = 'expert_review';
        }

        $contentLocale = $localeCtx['content_locale'] ?? 'en-US';
        $localeLang    = $localeCtx['locale_lang'] ?? 'en';

        $path = self::I18N_DIR . $template . '.json';
        $bundles = [];
        if (file_exists($path)) {
            $raw = file_get_contents($path);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $bundles = $decoded;
                }
            }
        }

        $base = self::defaultsFor($template);
        $pick = self::pickBundle($bundles, $contentLocale, $localeLang);

        return array_merge($base, $pick);
    }

    /**
     * Берёт бандл языка и накладывает на en (неполные переводы дополняются английским).
     *
     * @param array<string, array<string, string>> $bundles
     * @return array<string, string>
     */
    private static function pickBundle(array $bundles, string $contentLocale, string $localeLang): array
    {
        $en = $bundles['en'] ?? [];
        if (!is_array($en)) {
            $en = [];
        }

        $lang = strtolower($localeLang);
        $candidates = [
            strtolower($contentLocale),
            $lang,
            explode('-', strtolower($contentLocale))[0] ?? '',
        ];
        foreach ($candidates as $c) {
            if ($c !== '' && isset($bundles[$c]) && is_array($bundles[$c])) {
                return array_merge($en, $bundles[$c]);
            }
        }

        return $en;
    }

    /**
     * @return array<string, string>
     */
    private static function defaultsFor(string $template): array
    {
        if ($template === 'expert_review') {
            return self::defaultsExpertReview();
        }
        return [
            'html_lang' => 'en',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function defaultsExpertReview(): array
    {
        return [
            'html_lang'   => 'en',
            'page_title'  => 'Exclusive Offer for You — Casino Expert',
            'badge'       => '✓ Exclusive Offer',
            'h1_line1'    => 'Claim Your',
            'h1_span'     => 'Welcome Bonus',
            'h1_line2'    => 'Right Now',
            'subtitle'    => 'Our experts selected the best current offer based on your region. Limited spots available today.',
            'perk1'       => 'Up to $500 first deposit bonus',
            'perk2'       => '200 free spins — no wagering on winnings',
            'perk3'       => 'Withdrawals processed within 2 hours',
            'perk4'       => 'Licensed & certified platform',
            'cta'         => 'Claim Bonus Now →',
            'redirecting' => 'Redirecting automatically in',
            'disclaimer'  => '18+ only. Terms & conditions apply. Please gamble responsibly.',
        ];
    }
}
