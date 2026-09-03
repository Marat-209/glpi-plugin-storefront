<?php

namespace GlpiPlugin\Storefront;

use CommonDBTM;
use Session;

/** Склад витрины. Точка хранения и получения; витрина может иметь несколько. */
class Warehouse extends CommonDBTM
{
    public static $rightname = 'plugin_storefront_catalog';
    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? __('Склады', 'storefront') : __('Склад', 'storefront');
    }

    public static function getIcon()
    {
        return 'ti ti-building-warehouse';
    }

    /** Склад по умолчанию для витрины, иначе первый активный. */
    public static function getDefaultFor(int $catalogs_id): ?self
    {
        $w = new self();
        $rows = $w->find(
            ['plugin_storefront_catalogs_id' => $catalogs_id, 'is_active' => 1],
            ['is_default DESC', 'name ASC'],
            1
        );
        if (!count($rows)) {
            return null;
        }
        $w->getFromDB((int) array_key_first($rows));
        return $w;
    }

    /** Активные склады витрины. */
    public static function listFor(int $catalogs_id, bool $pickup_only = false): array
    {
        $crit = ['plugin_storefront_catalogs_id' => $catalogs_id, 'is_active' => 1];
        if ($pickup_only) {
            $crit['is_pickup'] = 1;
        }
        return (new self())->find($crit, ['is_default DESC', 'name ASC']);
    }

    /** Вкладка на карточке витрины. */
    public function getTabNameForItem(\CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof Catalog)) {
            return '';
        }
        $n = countElementsInTable(
            self::getTable(),
            ['plugin_storefront_catalogs_id' => $item->getID()]
        );
        return self::createTabEntry(self::getTypeName(2), $n);
    }

    public static function displayTabContentForItem(\CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof Catalog)) {
            return false;
        }
        AdminUi::warehouses($item);
        return true;
    }
}
