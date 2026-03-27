<?php
declare(strict_types=1);
/**
 * PostbackAuth — проверка глобального токена постбэка (ENV PL_POSTBACK_TOKEN или settings).
 */
final class PostbackAuth
{
    /**
     * @param array<string, mixed> $settings Config::settings()
     * @param array<string, mixed> $input    GET/POST merge
     */
    public static function globalTokenValid(array $settings, array $input): bool
    {
        $envToken = getenv('PL_POSTBACK_TOKEN');
        $required = is_string($envToken) && $envToken !== ''
            ? $envToken
            : (string)($settings['postback_token'] ?? '');
        if ($required === '') {
            return true;
        }
        $inputToken = trim((string)($input['token'] ?? ''));

        return hash_equals($required, $inputToken);
    }
}
