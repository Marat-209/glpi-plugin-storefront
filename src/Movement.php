<?php

namespace GlpiPlugin\Storefront;

use CommonDBTM;
use Session;

/** Движение по складу: приход, выдача, списание, перемещение, корректировка, резерв. */
class Movement extends CommonDBTM
{
    public static $rightname = 'plugin_storefront_stock';

    public const IN        = 'in';
    public const OUT       = 'out';
    public const ADJUST    = 'adjust';
    public const RESERVE   = 'reserve';
    public const UNRESERVE = 'unreserve';
    // Списание и перемещение — тоже расход и приход, но отдельными типами:
    // иначе порча и пересорт растворятся в отчёте по выдачам сотрудникам.
    public const WRITEOFF  = 'writeoff';
    public const MOVE_OUT  = 'move_out';
    public const MOVE_IN   = 'move_in';

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? __('Движения', 'storefront') : __('Движение', 'storefront');
    }

    public static function getIcon()
    {
        return 'ti ti-arrows-exchange';
    }

    public static function typeLabel(string $t): string
    {
        $map = [
            self::IN        => __('Приход', 'storefront'),
            self::OUT       => __('Выдача', 'storefront'),
            self::ADJUST    => __('Корректировка', 'storefront'),
            self::RESERVE   => __('Резерв', 'storefront'),
            self::UNRESERVE => __('Снятие резерва', 'storefront'),
            self::WRITEOFF  => __('Списание', 'storefront'),
            self::MOVE_OUT  => __('Передано на другой склад', 'storefront'),
            self::MOVE_IN   => __('Принято с другого склада', 'storefront'),
        ];
        return $map[$t] ?? $t;
    }
}
