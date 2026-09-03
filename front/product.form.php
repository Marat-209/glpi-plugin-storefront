<?php

use GlpiPlugin\Storefront\Product;

Session::checkRight('plugin_storefront_catalog', READ);

$item = new Product();

/** Куда вернуться после добавления с вкладки витрины. */
$backToCatalog = static function (int $cid): void {
    Html::redirect(Html::getPrefixedUrl(
        '/plugins/storefront/front/catalog.form.php?id=' . $cid
    ));
};

// Завести новую номенклатуру в справочнике GLPI и сразу положить в витрину.
if (isset($_POST['add_new'])) {
    Session::checkRight('plugin_storefront_catalog', UPDATE);
    $cid = (int) ($_POST['plugin_storefront_catalogs_id'] ?? 0);
    $ent = (int) ($_POST['entities_id'] ?? 0);

    $ci = new ConsumableItem();
    $items_id = (int) $ci->add([
        'name'                   => trim((string) ($_POST['new_name'] ?? '')),
        'ref'                    => trim((string) ($_POST['new_ref'] ?? '')),
        'entities_id'            => $ent,
        'is_recursive'           => 1,
        'consumableitemtypes_id' => (int) ($_POST['consumableitemtypes_id'] ?? 0),
        'alarm_threshold'        => (int) ($_POST['alarm_threshold'] ?? 0),
        'stock_target'           => (int) ($_POST['stock_target'] ?? 0),
    ]);
    if ($items_id <= 0) {
        Session::addMessageAfterRedirect(__('Не удалось создать номенклатуру.', 'storefront'), false, ERROR);
        $backToCatalog($cid);
    }

    $price = (float) ($_POST['price'] ?? 0);
    $newid = (new Product())->add([
        'plugin_storefront_catalogs_id' => $cid,
        'entities_id'                   => $ent,
        'is_recursive'                  => 1,
        'itemtype'                      => 'ConsumableItem',
        'items_id'                      => $items_id,
        'unit'                          => trim((string) ($_POST['unit'] ?? __('шт', 'storefront'))) ?: __('шт', 'storefront'),
        'price'                         => $price,
        'use_infocom_price'             => $price > 0 ? 0 : 1,
        'is_active'                     => 1,
        'is_chargeable'                 => (int) ($_POST['is_chargeable'] ?? 0),
        // Ноль — без ограничения; для дорогих позиций администратор ставит
        // предел, чтобы партию согласовывали заявкой, а не набирали в корзине.
        'max_qty'                       => max(0, (int) ($_POST['max_qty'] ?? 0)),
        'description'                   => trim((string) ($_POST['description'] ?? '')),
    ]);
    Session::addMessageAfterRedirect(
        $newid ? __('Позиция заведена и добавлена в витрину.', 'storefront') : __('Позиция не добавлена.', 'storefront'),
        false,
        $newid ? INFO : ERROR
    );
    $backToCatalog($cid);
}

// Взять уже существующую номенклатуру GLPI.
if (isset($_POST['add_existing'])) {
    Session::checkRight('plugin_storefront_catalog', UPDATE);
    $cid = (int) ($_POST['plugin_storefront_catalogs_id'] ?? 0);
    $itemtype = (string) ($_POST['itemtype'] ?? '');
    $items_id = (int) ($_POST['items_id'] ?? 0);

    if (!class_exists($itemtype) || $items_id <= 0) {
        Session::addMessageAfterRedirect(__('Тип или идентификатор указаны неверно.', 'storefront'), false, ERROR);
        $backToCatalog($cid);
    }
    // Проверка не только в форме: подставленный в запрос тип актива создал бы
    // позицию с видом учёта «экземплярный», которого плагин не поддерживает.
    if (!Product::isSellableType($itemtype)) {
        Session::addMessageAfterRedirect(
            __('В витрину можно завести только расходные материалы и картриджи: ', 'storefront')
            . __('выдача конкретных экземпляров с инвентарными номерами пока ', 'storefront')
            . __('не поддерживается.', 'storefront'),
            false,
            ERROR
        );
        $backToCatalog($cid);
    }
    $obj = new $itemtype();
    if (!$obj->getFromDB($items_id)) {
        Session::addMessageAfterRedirect(
            __('В справочнике GLPI нет записи ', 'storefront') . $itemtype . __(' с номером ', 'storefront') . $items_id . '.',
            false,
            ERROR
        );
        $backToCatalog($cid);
    }
    $newid = (new Product())->add([
        'plugin_storefront_catalogs_id' => $cid,
        'entities_id'                   => (int) ($_POST['entities_id'] ?? 0),
        'is_recursive'                  => 1,
        'itemtype'                      => $itemtype,
        'items_id'                      => $items_id,
        'unit'                          => trim((string) ($_POST['unit'] ?? __('шт', 'storefront'))) ?: __('шт', 'storefront'),
        'is_active'                     => 1,
    ]);
    if ($newid) {
        Session::addMessageAfterRedirect(__('Позиция добавлена в витрину.', 'storefront'), false, INFO);
    }
    $backToCatalog($cid);
}

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $newid = $item->add($_POST);
    if ($newid) {
        Html::redirect(Plugin::getWebDir('storefront') . '/front/product.form.php?id=' . $newid);
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $item->check((int) $_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $item->check((int) $_POST['id'], PURGE);
    $item->delete($_POST, true);
    $back = (int) ($_POST['_back_catalog'] ?? 0);
    Html::redirect($back > 0
        ? Html::getPrefixedUrl('/plugins/storefront/front/catalog.form.php?id=' . $back)
        : Plugin::getWebDir('storefront') . '/front/catalog.php');
}

$id = (int) ($_GET['id'] ?? -1);

Html::header(
    Product::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'management',
    \GlpiPlugin\Storefront\Catalog::class,
    'catalog'
);

$item->display(['id' => $id] + $_GET);

Html::footer();
