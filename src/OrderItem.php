<?php

namespace GlpiPlugin\Storefront;

use CommonDBTM;
use Session;

/** Строка заказа: запрошенное, утверждённое и выданное количество. */
class OrderItem extends CommonDBTM
{
    public static $rightname = 'plugin_storefront_order';

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? __('Строки заказа', 'storefront') : __('Строка заказа', 'storefront');
    }

    /** Сумма строки: по утверждённому, а до утверждения — по запрошенному. */
    public function amount(): float
    {
        $qty = (int) $this->fields['qty_approved'] ?: (int) $this->fields['qty_requested'];
        return round($qty * (float) $this->fields['price_snapshot'], 2);
    }

    /** Насколько урезали: доля утверждённого от запрошенного, в процентах. */
    public function fulfilment(): float
    {
        $req = (int) $this->fields['qty_requested'];
        if ($req <= 0) {
            return 0.0;
        }
        return round(100 * (int) $this->fields['qty_approved'] / $req, 1);
    }
}
