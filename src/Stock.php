<?php

namespace GlpiPlugin\Storefront;

use CommonDBTM;
use Session;

/**
 * Остаток позиции на складе. Агрегат, а не журнал: число хранится готовым
 * и правится вместе с движением, иначе витрина на 45 тысячах пользователей
 * считала бы суммы движений при каждом показе.
 */
class Stock extends CommonDBTM
{
    public static $rightname = 'plugin_storefront_stock';

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? __('Остатки', 'storefront') : __('Остаток', 'storefront');
    }

    public static function getIcon()
    {
        return 'ti ti-stack-2';
    }

    /** Строка остатка; создаётся при первом обращении. */
    public static function ensure(int $products_id, int $warehouses_id, int $entities_id = 0): self
    {
        $s = new self();
        $found = $s->find([
            'plugin_storefront_products_id'   => $products_id,
            'plugin_storefront_warehouses_id' => $warehouses_id,
        ], [], 1);
        if (count($found)) {
            $s->getFromDB((int) array_key_first($found));
            return $s;
        }
        $id = (int) $s->add([
            'plugin_storefront_products_id'   => $products_id,
            'plugin_storefront_warehouses_id' => $warehouses_id,
            'entities_id'                     => $entities_id,
            'qty_on_hand'                     => 0,
            'qty_reserved'                    => 0,
        ]);
        $s->getFromDB($id);
        return $s;
    }

    /** Свободно к заказу: на руках минус резерв. */
    public function free(): int
    {
        return max(0, (int) $this->fields['qty_on_hand'] - (int) $this->fields['qty_reserved']);
    }

    /**
     * Ниже ли порога.
     *
     * Порог берётся так же, как в остальных местах: свой, а если не задан —
     * штатный alarm_threshold номенклатуры GLPI. Раньше метод смотрел только
     * на своё поле, из-за чего позиция с порогом, заданным штатно, никогда
     * не считалась дефицитной.
     */
    public function isLow(): bool
    {
        $t = $this->threshold();
        return $t > 0 && $this->free() < $t;
    }

    /** Действующий порог оповещения с откатом на штатное поле номенклатуры. */
    public function threshold(): int
    {
        $own = (int) $this->fields['threshold'];
        if ($own > 0) {
            return $own;
        }
        $p = new Product();
        if (!$p->getFromDB((int) $this->fields['plugin_storefront_products_id'])) {
            return 0;
        }
        $item = $p->getItem();
        return (int) ($item->fields['alarm_threshold'] ?? 0);
    }

    /** Суммарно свободно по всем складам. */
    /**
     * Свободный остаток на конкретном складе.
     *
     * Витрина показывает именно его, а не сумму по всем складам: получают
     * заказ на одном складе, и «в наличии 300» при пустой полке в нужном
     * месте — обещание, которое склад не выполнит.
     */
    public static function freeAt(int $products_id, int $warehouses_id): int
    {
        if ($warehouses_id <= 0) {
            return self::freeTotal($products_id);
        }
        return self::ensure($products_id, $warehouses_id)->free();
    }

    public static function freeTotal(int $products_id): int
    {
        global $DB;
        $sum = 0;
        foreach ($DB->request([
            'SELECT' => ['qty_on_hand', 'qty_reserved'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_storefront_products_id' => $products_id],
        ]) as $r) {
            $sum += max(0, (int) $r['qty_on_hand'] - (int) $r['qty_reserved']);
        }
        return $sum;
    }

    /** Вкладка «Остатки» на карточке штатной номенклатуры. */
    public function getTabNameForItem(\CommonGLPI $item, $withtemplate = 0)
    {
        if (!Session::haveRight('plugin_storefront_stock', READ)) {
            return '';
        }
        $p = Product::getByItem($item->getType(), (int) $item->getID());
        if ($p === null) {
            return __('Остатки', 'storefront');
        }
        return self::createTabEntry(__('Остатки', 'storefront'), self::freeTotal($p->getID()));
    }

    public static function displayTabContentForItem(\CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        $p = Product::getByItem($item->getType(), (int) $item->getID());
        if ($p === null) {
            echo '<div class="alert alert-info m-3">'
                . __('Эта номенклатура не заведена ни в одну витрину магазина.</div>', 'storefront');
            return true;
        }
        Ui::showStockForProduct($p);
        return true;
    }
}
