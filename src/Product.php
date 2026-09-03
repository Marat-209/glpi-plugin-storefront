<?php

namespace GlpiPlugin\Storefront;

use CommonDBTM;
use Infocom;

/**
 * Позиция витрины — обёртка над штатной номенклатурой GLPI.
 *
 * Плагин не дублирует справочник: название, артикул, категория, изображение
 * и склад берутся из ConsumableItem, CartridgeItem или актива. Здесь живёт
 * только то, чего в GLPI нет: единица измерения, признак активности в витрине,
 * размер упаковки, вид учёта и порог должности для скрытых позиций.
 */
class Product extends CommonDBTM
{
    public static $rightname = 'plugin_storefront_catalog';
    public $dohistory = true;

    /** Вид учёта. */
    public const TRACK_QTY  = 'quantity'; // количественный: канцелярия, картриджи
    public const TRACK_UNIT = 'unit';     // экземплярный: техника с инвентарным номером

    /** Типы номенклатуры, которые можно продавать. */
    public const QTY_TYPES  = ['ConsumableItem', 'CartridgeItem'];
    public const UNIT_TYPES = ['Computer', 'Monitor', 'Peripheral', 'Phone', 'Printer'];

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? __('Позиции витрины', 'storefront') : __('Позиция витрины', 'storefront');
    }

    public static function getIcon()
    {
        return 'ti ti-box';
    }

    public static function trackingModes(): array
    {
        return [
            self::TRACK_QTY  => __('Количественный (расходуемое)', 'storefront'),
            self::TRACK_UNIT => __('Экземплярный (инвентарное)', 'storefront'),
        ];
    }

    /**
     * Типы номенклатуры, которые можно завести в витрину.
     *
     * Только количественные: экземплярный учёт (выдача конкретного объекта
     * с инвентарным номером) в этой версии не реализован. Позиция такого типа
     * получила бы вид учёта «экземплярный», а выдавалась бы количеством без
     * инвентарных номеров — то есть подпись обещала бы то, чего нет.
     * UNIT_TYPES оставлены для будущей реализации.
     */
    public static function itemtypes(): array
    {
        $out = [];
        foreach (self::QTY_TYPES as $t) {
            if (class_exists($t)) {
                $out[$t] = $t::getTypeName(1);
            }
        }
        return $out;
    }

    /** Умеет ли плагин выдавать номенклатуру этого типа. */
    public static function isSellableType(string $itemtype): bool
    {
        return in_array($itemtype, self::QTY_TYPES, true);
    }

    /** Вид учёта, подразумеваемый типом номенклатуры. */
    public static function trackingFor(string $itemtype): string
    {
        return in_array($itemtype, self::QTY_TYPES, true) ? self::TRACK_QTY : self::TRACK_UNIT;
    }

    /** Позиция витрины по штатному объекту, если он куда-то заведён. */
    public static function getByItem(string $itemtype, int $items_id): ?self
    {
        if ($items_id <= 0) {
            return null;
        }
        $p = new self();
        $found = $p->find(['itemtype' => $itemtype, 'items_id' => $items_id], [], 1);
        if (!count($found)) {
            return null;
        }
        $p->getFromDB((int) array_key_first($found));
        return $p;
    }

    /** Штатный объект номенклатуры. */
    public function getItem(): ?CommonDBTM
    {
        $type = (string) $this->fields['itemtype'];
        $id = (int) $this->fields['items_id'];
        if ($type === '' || $id <= 0 || !class_exists($type)) {
            return null;
        }
        $obj = new $type();
        if (!$obj->getFromDB($id)) {
            return null;
        }
        return $obj;
    }

    /** Отображаемое название: своё, иначе из номенклатуры. */
    public function label(): string
    {
        $own = trim((string) $this->fields['name']);
        if ($own !== '') {
            return $own;
        }
        $item = $this->getItem();
        return $item ? (string) $item->fields['name'] : (__('Позиция #', 'storefront') . $this->getID());
    }

    /**
     * Картинка позиции из карточки номенклатуры GLPI.
     *
     * Своих файлов плагин не хранит: изображение уже загружено в карточку
     * расходника или актива, и второе место хранения означало бы вторую правду.
     * Пустая строка — картинки нет, витрина покажет значок-заглушку.
     */
    public function pictureUrl(): string
    {
        $item = $this->getItem();
        if ($item === null) {
            return '';
        }
        $raw = $item->fields['pictures'] ?? '';
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [$raw];
        }
        if (!is_array($raw) || !count($raw)) {
            return '';
        }
        $first = (string) reset($raw);
        if ($first === '') {
            return '';
        }
        return \Toolbox::getPictureUrl($first);
    }

    /**
     * Описание для сотрудника.
     *
     * Своё, а не комментарий из карточки номенклатуры: там администратор
     * пишет служебное («заказывать у поставщика Б, минимальная партия 50»),
     * а на витрине нужно «пишет синим, не течёт в самолёте».
     */
    public function description(): string
    {
        return trim((string) ($this->fields['description'] ?? ''));
    }

    /**
     * Платная ли позиция.
     *
     * Основная масса канцелярии выдаётся бесплатно, и цену сотруднику
     * показывать незачем. Но отдельные вещи могут быть за деньги — у них
     * цена видна всегда, даже когда витрина цены скрывает.
     */
    public function isChargeable(): bool
    {
        return (int) ($this->fields['is_chargeable'] ?? 0) === 1;
    }

    /** Показывать ли цену этой позиции сотруднику. */
    public function priceVisibleTo(Catalog $catalog): bool
    {
        return $catalog->showsPrices() || $this->isChargeable();
    }

    /** Артикул из номенклатуры. */
    public function ref(): string
    {
        $item = $this->getItem();
        if ($item === null) {
            return '';
        }
        return (string) ($item->fields['ref'] ?? $item->fields['otherserial'] ?? '');
    }

    /** Категория из номенклатуры: тип расходника или картриджа. */
    public function categoryId(): int
    {
        $item = $this->getItem();
        if ($item === null) {
            return 0;
        }
        foreach (['consumableitemtypes_id', 'cartridgeitemtypes_id'] as $f) {
            if (isset($item->fields[$f])) {
                return (int) $item->fields[$f];
            }
        }
        return 0;
    }

    public function categoryName(): string
    {
        $cid = $this->categoryId();
        if ($cid <= 0) {
            return '';
        }
        $item = $this->getItem();
        $table = ($item instanceof \CartridgeItem)
            ? 'glpi_cartridgeitemtypes' : 'glpi_consumableitemtypes';
        return (string) \Dropdown::getDropdownName($table, $cid);
    }

    /**
     * Цена позиции.
     * По умолчанию берётся из финансовой информации GLPI — там она уже есть
     * вместе с поставщиком и бюджетом. Своя цена нужна, когда Infocom не ведут.
     */
    /**
     * Сколько единиц позиции можно взять в один заказ.
     *
     * Ноль — без ограничения. Нужно для дорогих и учётных позиций: партию
     * лучше согласовать заявкой, а не набрать одной кнопкой в корзине.
     */
    public function maxPerOrder(): int
    {
        return max(0, (int) ($this->fields['max_qty'] ?? 0));
    }

    public function price(): float
    {
        if (!(bool) $this->fields['use_infocom_price']) {
            return (float) $this->fields['price'];
        }
        $type = (string) $this->fields['itemtype'];
        $id = (int) $this->fields['items_id'];
        if ($type !== '' && $id > 0) {
            $ic = new Infocom();
            if ($ic->getFromDBforDevice($type, $id)) {
                $v = (float) $ic->fields['value'];
                if ($v > 0) {
                    return $v;
                }
            }
        }
        return (float) $this->fields['price'];
    }

    /** Порог оповещения: свой из остатка, иначе штатный из номенклатуры. */
    public function thresholdFrom(?Stock $stock = null): int
    {
        if ($stock !== null && (int) $stock->fields['threshold'] > 0) {
            return (int) $stock->fields['threshold'];
        }
        $item = $this->getItem();
        return (int) ($item->fields['alarm_threshold'] ?? 0);
    }

    /** Целевой запас: свой из остатка, иначе штатный из номенклатуры. */
    public function targetFrom(?Stock $stock = null): int
    {
        if ($stock !== null && (int) $stock->fields['target'] > 0) {
            return (int) $stock->fields['target'];
        }
        $item = $this->getItem();
        return (int) ($item->fields['stock_target'] ?? 0);
    }

    public function isQuantity(): bool
    {
        return (string) $this->fields['tracking'] === self::TRACK_QTY;
    }

    public function prepareInputForAdd($input)
    {
        $input = $this->prepareInput($input);
        if ($input === false) {
            return false;
        }
        // Уникальность есть в индексе, но полагаться только на него нельзя:
        // база выбросит SQL-исключение и пользователь увидит страницу ошибки
        // вместо понятного объяснения.
        $itemtype = (string) ($input['itemtype'] ?? '');
        $items_id = (int) ($input['items_id'] ?? 0);
        $catalogs_id = (int) ($input['plugin_storefront_catalogs_id'] ?? 0);
        if ($itemtype !== '' && $items_id > 0 && $catalogs_id > 0) {
            $exists = countElementsInTable(self::getTable(), [
                'plugin_storefront_catalogs_id' => $catalogs_id,
                'itemtype'                      => $itemtype,
                'items_id'                      => $items_id,
            ]);
            if ($exists > 0) {
                \Session::addMessageAfterRedirect(
                    __('Эта номенклатура уже заведена в витрину. ', 'storefront')
                    . __('Одна позиция номенклатуры может входить в витрину только один раз — ', 'storefront')
                    . __('иначе остаток по ней считался бы дважды.', 'storefront'),
                    false,
                    ERROR
                );
                return false;
            }
        }
        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        return $this->prepareInput($input);
    }

    /** Вид учёта не выбирают вручную: он следует из типа номенклатуры. */
    private function prepareInput($input)
    {
        if (!is_array($input)) {
            return $input;
        }
        if (isset($input['itemtype']) && (string) $input['itemtype'] !== '') {
            $input['tracking'] = self::trackingFor((string) $input['itemtype']);
        }
        if (isset($input['pack_size']) && (int) $input['pack_size'] < 1) {
            $input['pack_size'] = 1;
        }
        return $input;
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();
        $tab[] = ['id' => '3', 'table' => $this->getTable(), 'field' => 'unit',
            'name' => __('Единица измерения', 'storefront'), 'datatype' => 'string'];
        $tab[] = ['id' => '4', 'table' => $this->getTable(), 'field' => 'is_active',
            'name' => __('Активна в витрине', 'storefront'), 'datatype' => 'bool'];
        $tab[] = ['id' => '5', 'table' => $this->getTable(), 'field' => 'tracking',
            'name' => __('Вид учёта', 'storefront'), 'datatype' => 'string'];
        $tab[] = ['id' => '6', 'table' => $this->getTable(), 'field' => 'itemtype',
            'name' => __('Тип номенклатуры', 'storefront'), 'datatype' => 'itemtypename'];
        $tab[] = ['id' => '7', 'table' => $this->getTable(), 'field' => 'pack_size',
            'name' => __('В упаковке', 'storefront'), 'datatype' => 'number'];
        return $tab;
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
        AdminUi::products($item);
        return true;
    }
}
