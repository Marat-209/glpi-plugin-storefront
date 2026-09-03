<?php

namespace GlpiPlugin\Storefront;

use CommonDBTM;
use Session;

/** Набор: готовая корзина одной кнопкой, например комплект нового сотрудника. */
class Kit extends CommonDBTM
{
    public static $rightname = 'plugin_storefront_catalog';
    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? __('Наборы', 'storefront') : __('Набор', 'storefront');
    }

    public static function getIcon()
    {
        return 'ti ti-briefcase';
    }

    /** Состав набора. */
    public function items(): array
    {
        return (new KitItem())->find(
            ['plugin_storefront_kits_id' => $this->getID()],
            ['ranking ASC', 'id ASC']
        );
    }

    /** Стоимость набора по текущим ценам позиций. */
    public function price(): float
    {
        $sum = 0.0;
        foreach ($this->items() as $row) {
            $p = new Product();
            if ($p->getFromDB((int) $row['plugin_storefront_products_id'])) {
                $sum += $p->price() * (int) $row['qty'];
            }
        }
        return $sum;
    }

    /**
     * Положить набор в корзину сотрудника.
     *
     * Количества складываются с тем, что уже лежит: сотрудник мог взять набор
     * и добавить что-то сверху, и повторное нажатие не должно молча стирать
     * его правки. Неактивные и удалённые позиции пропускаются — набор мог
     * быть собран раньше, чем позицию убрали из витрины.
     *
     * @return array{added:int, skipped:int}
     */
    public function addToCart(int $users_id): array
    {
        if (!$this->availableFor($users_id)) {
            \Session::addMessageAfterRedirect(
                $this->unavailableReason($users_id), false, ERROR
            );
            return ['added' => 0, 'skipped' => count($this->items())];
        }
        $catalogs_id = (int) $this->fields['plugin_storefront_catalogs_id'];
        $have = [];
        foreach (CartItem::forUser($users_id, $catalogs_id) as $row) {
            $have[(int) $row['plugin_storefront_products_id']] = (int) $row['qty'];
        }

        $added = 0;
        $skipped = 0;
        foreach ($this->items() as $row) {
            $pid = (int) $row['plugin_storefront_products_id'];
            $qty = max(1, (int) $row['qty']);
            $p = new Product();
            if (!$p->getFromDB($pid) || (int) $p->fields['is_active'] !== 1) {
                $skipped++;
                continue;
            }
            if (CartItem::put($users_id, $catalogs_id, $pid,
                ($have[$pid] ?? 0) + $qty, $this->getID())) {
                $added++;
            }
        }
        return ['added' => $added, 'skipped' => $skipped];
    }

    /** Все включённые наборы витрины. */
    public static function activeFor(int $catalogs_id): array
    {
        return (new self())->find([
            'plugin_storefront_catalogs_id' => $catalogs_id,
            'is_active'                     => 1,
        ], ['name ASC']);
    }

    /**
     * Наборы, которые видит конкретный сотрудник.
     *
     * Разовый набор исчезает после того, как заказ с ним выдан: показывать
     * кнопку, которая всё равно откажет, хуже, чем не показывать её вовсе.
     * Вернуть набор человеку может администратор — разрешением на повтор.
     */
    public static function visibleFor(int $catalogs_id, int $users_id): array
    {
        $out = [];
        foreach (self::activeFor($catalogs_id) as $id => $row) {
            $kit = new self();
            $kit->getFromDB((int) $id);
            if ($kit->availableFor($users_id)) {
                $out[(int) $id] = $row;
            }
        }
        return $out;
    }

    /** Разовый ли набор. */
    public function isOnce(): bool
    {
        return (int) ($this->fields['is_once'] ?? 0) === 1;
    }

    /** Получал ли сотрудник этот набор (по выданным заказам). */
    public function issuedTo(int $users_id): bool
    {
        return countElementsInTable(Order::getTable(), [
            'plugin_storefront_kits_id' => $this->getID(),
            'users_id_requester'        => $users_id,
            'state'                     => Order::ISSUED,
        ]) > 0;
    }

    /** Доступен ли набор сотруднику прямо сейчас. */
    public function availableFor(int $users_id): bool
    {
        if ((int) $this->fields['is_active'] !== 1) {
            return false;
        }
        if (!$this->isOnce()) {
            return true;
        }
        if (!$this->issuedTo($users_id)) {
            return true;
        }
        return KitGrant::has($this->getID(), $users_id);
    }

    /** Почему набор недоступен — человеку и в журнал. */
    public function unavailableReason(int $users_id): string
    {
        if ((int) $this->fields['is_active'] !== 1) {
            return __('Набор отключён.', 'storefront');
        }
        if ($this->isOnce() && $this->issuedTo($users_id)) {
            return sprintf(
                __('Набор «%s» выдаётся один раз, и вы его уже получали. Если он нужен '
                    . 'снова — обратитесь к администратору витрины за разрешением.',
                    'storefront'),
                $this->fields['name']
            );
        }
        return '';
    }

    /** Кому уже выдан разовый набор. @return array<int,string> id => дата */
    public function recipients(int $limit = 200): array
    {
        $out = [];
        foreach ((new Order())->find([
            'plugin_storefront_kits_id' => $this->getID(),
            'state'                     => Order::ISSUED,
        ], ['date_issued DESC'], $limit) as $row) {
            $out[(int) $row['users_id_requester']] = (string) $row['date_issued'];
        }
        return $out;
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
        AdminUi::kits($item);
        return true;
    }
}
