<?php

namespace GlpiPlugin\Storefront;

use CommonDBTM;
use Session;

/** Строка набора. */
class KitItem extends CommonDBTM
{
    public static $rightname = 'plugin_storefront_catalog';

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? __('Строки набора', 'storefront') : __('Строка набора', 'storefront');
    }
}
