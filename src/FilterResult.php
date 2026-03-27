<?php
declare(strict_types=1);
/**
 * FilterResult.php — PHP 8.1 backed enum для результата BotFilter::check().
 *
 * Заменяет строковые константы BotFilter::PASS, ::BOT и т.д.
 * Строковое значение (->value) совместимо с прежними константами —
 * ClickLogger по-прежнему пишет строку в БД через $result->value.
 */
enum FilterResult: string
{
    case PASS     = 'PASS';
    case BOT      = 'BOT';
    case CLOAK    = 'CLOAK';
    case VPN      = 'VPN';
    case OFFGEO   = 'OFFGEO';
    case OFFHOURS = 'OFFHOURS';
    case TOR      = 'TOR';
    /** curl/wget/мониторинги — не пишем в clicks (см. index.php) */
    case PROBE    = 'PROBE';
}
