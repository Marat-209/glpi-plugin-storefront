<?php

namespace GlpiPlugin\Storefront;

use CommonDBTM;
use Session;

/**
 * Строка корзины. Живёт в базе, а не в сессии: корзину собирают с телефона,
 * а оформляют с компьютера.
 */
class CartItem extends CommonDBTM
{
    public static $rightname = 'plugin_storefront_order';

    /** Разумный потолок количества в одной строке корзины. */
    public const MAX_QTY = 100000;

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? __('Корзина', 'storefront') : __('Строка корзины', 'storefront');
    }

    /** Корзина пользователя по витрине. */
    public static function forUser(int $users_id, int $catalogs_id): array
    {
        return (new self())->find([
            'users_id'                      => $users_id,
            'plugin_storefront_catalogs_id' => $catalogs_id,
        ], ['id ASC']);
    }

    /** Положить позицию или изменить количество; нулевое количество удаляет строку. */
    public static function put(
        int $users_id,
        int $catalogs_id,
        int $products_id,
        int $qty,
        int $kits_id = 0
    ): bool {
        if ($qty <= 0) {
            return self::drop($users_id, $catalogs_id, $products_id);
        }
        // Потолок количества. Поле формы типа number верхней границы не даёт,
        // и значение из запроса переполняло колонку INT, роняя запрос
        // ошибкой базы вместо понятного отказа.
        if ($qty > self::MAX_QTY) {
            Session::addMessageAfterRedirect(
                sprintf(__('За один раз можно заказать не больше %d единиц позиции.', 'storefront'),
                    self::MAX_QTY),
                false,
                WARNING
            );
            $qty = self::MAX_QTY;
        }
        // Позиция обязана принадлежать этой витрине и быть активной: иначе
        // подставленный в форму чужой products_id попадёт в корзину, а оттуда
        // в заказ и в списание с чужого склада.
        $product = new Product();
        if ($product->getFromDB($products_id)) {
            $cap = $product->maxPerOrder();
            if ($cap > 0 && $qty > $cap) {
                // Не отказываем совсем: человек всё равно хотел эту позицию.
                // Берём разрешённое количество и говорим, почему меньше.
                Session::addMessageAfterRedirect(
                    sprintf(
                        __('Позиции «%s» можно взять не больше %d за один заказ. ', 'storefront')
                        . __('В корзину добавлено %d — за остальным оформите отдельную заявку.', 'storefront'),
                        $product->label(),
                        $cap,
                        $cap
                    ),
                    false,
                    WARNING
                );
                $qty = $cap;
            }
        }

        $catalog = new Catalog();
        if (!$catalog->getFromDB($catalogs_id)
            || !$catalog->isAvailableToCurrentUser()
            || !$catalog->ownsProduct($products_id)) {
            Session::addMessageAfterRedirect(
                __('Эту позицию нельзя добавить в корзину этой витрины.', 'storefront'), false, ERROR
            );
            return false;
        }
        $c = new self();
        $found = $c->find([
            'users_id'                      => $users_id,
            'plugin_storefront_catalogs_id' => $catalogs_id,
            'plugin_storefront_products_id' => $products_id,
        ], [], 1);
        if (count($found)) {
            $id = (int) array_key_first($found);
            $input = ['id' => $id, 'qty' => $qty, 'date_mod' => Engine::now()];
            // Метку набора не стираем при ручной правке количества: человек
            // мог взять набор и добавить одну ручку сверху — это по-прежнему
            // выдача набора.
            if ($kits_id > 0) {
                $input['plugin_storefront_kits_id'] = $kits_id;
            }
            return (bool) $c->update($input);
        }
        return (bool) $c->add([
            'users_id'                      => $users_id,
            'plugin_storefront_catalogs_id' => $catalogs_id,
            'plugin_storefront_products_id' => $products_id,
            'plugin_storefront_kits_id'     => $kits_id,
            'qty'                           => $qty,
            'date_mod'                      => Engine::now(),
        ]);
    }

    /**
     * Набор, из которого собрана корзина.
     *
     * Если строк из разных наборов несколько, берём первый: смысл метки —
     * отметить факт выдачи разового набора, а не построить дерево.
     */
    public static function kitOf(int $users_id, int $catalogs_id): int
    {
        foreach (self::forUser($users_id, $catalogs_id) as $row) {
            if ((int) ($row['plugin_storefront_kits_id'] ?? 0) > 0) {
                return (int) $row['plugin_storefront_kits_id'];
            }
        }
        return 0;
    }

    public static function drop(int $users_id, int $catalogs_id, int $products_id): bool
    {
        $c = new self();
        foreach ($c->find([
            'users_id'                      => $users_id,
            'plugin_storefront_catalogs_id' => $catalogs_id,
            'plugin_storefront_products_id' => $products_id,
        ]) as $id => $row) {
            $c->delete(['id' => $id], true);
        }
        return true;
    }

    public static function clear(int $users_id, int $catalogs_id): int
    {
        $c = new self();
        $n = 0;
        foreach ($c->find([
            'users_id'                      => $users_id,
            'plugin_storefront_catalogs_id' => $catalogs_id,
        ]) as $id => $row) {
            if ($c->delete(['id' => $id], true)) {
                $n++;
            }
        }
        return $n;
    }
}
