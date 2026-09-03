<?php

namespace GlpiPlugin\Storefront;

use CommonDBTM;
use Session;

/** Правило лимита выдачи: на кого, на что, за какой период. */
class Limit extends CommonDBTM
{
    public static $rightname = 'plugin_storefront_catalog';
    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? __('Лимиты выдачи', 'storefront') : __('Лимит выдачи', 'storefront');
    }

    public static function getIcon()
    {
        return 'ti ti-gauge';
    }

    /** У каждого своя норма: сотрудник, отдел и подразделение считаются порознь. */
    public const MODE_EACH = 'each';

    /** Одна норма на всю область: отдел расходует её вместе со своими людьми. */
    public const MODE_TOTAL = 'total';

    /** Режим нормы правила, с умолчанием для записей, созданных до появления поля. */
    public static function mode(array $rule): string
    {
        $mode = (string) ($rule['scope_mode'] ?? self::MODE_EACH);
        if ($mode !== self::MODE_TOTAL) {
            return self::MODE_EACH;
        }
        // Для правила на одного человека общая норма — то же самое, что личная.
        return (string) ($rule['scope'] ?? 'all') === 'user'
            ? self::MODE_EACH : self::MODE_TOTAL;
    }

    /** Как называется режим нормы для человека. */
    public static function modeLabel(string $mode, string $scope): string
    {
        if ($mode !== self::MODE_TOTAL) {
            return __('у каждого своя', 'storefront');
        }
        switch ($scope) {
            case 'group':
                return __('одна на отдел', 'storefront');
            case 'entity':
                return __('одна на подразделение', 'storefront');
            case 'title':
                return __('одна на должность', 'storefront');
        }
        return __('одна на витрину', 'storefront');
    }

    /** Чья норма расходуется — для сообщений человеку. */
    public static function poolLabel(array $rule): string
    {
        if (self::mode($rule) !== self::MODE_TOTAL) {
            return __('ваша норма', 'storefront');
        }
        $id = (int) ($rule['scope_items_id'] ?? 0);
        switch ((string) ($rule['scope'] ?? 'all')) {
            case 'group':
                return sprintf(__('общая норма отдела «%s»', 'storefront'),
                    \Dropdown::getDropdownName('glpi_groups', $id));
            case 'entity':
                return sprintf(__('общая норма подразделения «%s»', 'storefront'),
                    \Dropdown::getDropdownName('glpi_entities', $id));
            case 'title':
                return sprintf(__('общая норма должности «%s»', 'storefront'),
                    \Dropdown::getDropdownName('glpi_usertitles', $id));
        }
        return __('общая норма витрины', 'storefront');
    }

    public static function periodLabel(string $p): string
    {
        $map = ['month' => __('месяц', 'storefront'), 'quarter' => __('квартал', 'storefront'), 'year' => __('год', 'storefront')];
        return $map[$p] ?? $p;
    }

    /** Начало текущего периода в формате даты GLPI. */
    public static function periodStart(string $period, ?int $now = null): string
    {
        $now = $now ?? Engine::nowTs();
        $y = (int) date('Y', $now);
        $m = (int) date('n', $now);
        if ($period === 'year') {
            return sprintf('%04d-01-01 00:00:00', $y);
        }
        if ($period === 'quarter') {
            $qm = 3 * intdiv($m - 1, 3) + 1;
            return sprintf('%04d-%02d-01 00:00:00', $y, $qm);
        }
        return sprintf('%04d-%02d-01 00:00:00', $y, $m);
    }

    /** На кого действует правило — человекочитаемо. */
    public static function scopeLabel(string $scope, int $items_id): string
    {
        switch ($scope) {
            case 'group':
                return sprintf(__('отдел: %s', 'storefront'),
                    \Dropdown::getDropdownName('glpi_groups', $items_id));
            case 'entity':
                return sprintf(__('подразделение: %s', 'storefront'),
                    \Dropdown::getDropdownName('glpi_entities', $items_id));
            case 'title':
                return sprintf(__('должность: %s', 'storefront'),
                    \Dropdown::getDropdownName('glpi_usertitles', $items_id));
            case 'user':
                return sprintf(__('сотрудник: %s', 'storefront'),
                    $items_id > 0 ? getUserName($items_id) : __('не указан', 'storefront'));
        }
        return __('все сотрудники', 'storefront');
    }

    /** На что действует правило — человекочитаемо. */
    public static function targetLabel(string $target, int $items_id): string
    {
        if ($target === 'product') {
            $p = new Product();
            return $p->getFromDB($items_id) ? $p->label() : __('позиция удалена', 'storefront');
        }
        if ($target === 'category') {
            return sprintf(__('категория: %s', 'storefront'),
                \Dropdown::getDropdownName('glpi_consumableitemtypes', $items_id));
        }
        return __('вся витрина', 'storefront');
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
        AdminUi::limits($item);
        return true;
    }
}
