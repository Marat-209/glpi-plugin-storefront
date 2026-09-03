<?php

/**
 * Наборы: создание, состав, удаление.
 *
 * Набор и его позиции заводятся прямо с карточки витрины, поэтому все действия
 * возвращаются на неё же — отдельная страница «редактировать набор» была бы
 * лишним шагом.
 */

use GlpiPlugin\Storefront\Kit;
use GlpiPlugin\Storefront\KitGrant;
use GlpiPlugin\Storefront\KitItem;
use GlpiPlugin\Storefront\Product;

Session::checkRight('plugin_storefront_catalog', READ);

$item = new Kit();

/** Вернуться на карточку витрины, сразу на вкладку наборов. */
$back = static function (int $catalogs_id): void {
    Html::redirect($catalogs_id > 0
        ? Html::getPrefixedUrl('/plugins/storefront/front/catalog.form.php?id=' . $catalogs_id
            . '&forcetab=' . urlencode(Kit::class . '$1'))
        : Plugin::getWebDir('storefront') . '/front/catalog.php');
};

/* ------------------------------------------------------------ создать набор */
if (isset($_POST['add'])) {
    Session::checkRight('plugin_storefront_catalog', UPDATE);
    $cid = (int) ($_POST['plugin_storefront_catalogs_id'] ?? 0);
    $input = $_POST;
    $input['is_active'] = 1;
    $input['is_recursive'] = 1;
    $input['is_once'] = (int) ($_POST['is_once'] ?? 0);
    if ($item->add($input)) {
        Session::addMessageAfterRedirect(
            __('Набор создан. Теперь добавьте в него позиции.', 'storefront'), false, INFO
        );
    }
    $back($cid);
}

/* ------------------------------------------------------------ повторная выдача */
if (isset($_POST['grant'])) {
    Session::checkRight('plugin_storefront_catalog', UPDATE);
    $ok = KitGrant::grant(
        (int) ($_POST['plugin_storefront_kits_id'] ?? 0),
        (int) ($_POST['users_id'] ?? 0),
        trim((string) ($_POST['reason'] ?? ''))
    );
    if ($ok) {
        Session::addMessageAfterRedirect(
            __('Разрешение выдано: набор снова доступен этому сотруднику один раз.', 'storefront'),
            false,
            INFO
        );
    }
    $back((int) ($_POST['_back_catalog'] ?? 0));
}

/* ------------------------------------------------------------ строка набора */
if (isset($_POST['add_item'])) {
    Session::checkRight('plugin_storefront_catalog', UPDATE);
    $kits_id = (int) ($_POST['plugin_storefront_kits_id'] ?? 0);
    $pid = (int) ($_POST['plugin_storefront_products_id'] ?? 0);
    $qty = max(1, (int) ($_POST['qty'] ?? 1));

    $kit = new Kit();
    $product = new Product();
    if (!$kit->getFromDB($kits_id) || !$product->getFromDB($pid)) {
        Session::addMessageAfterRedirect(__('Набор или позиция не найдены.', 'storefront'), false, ERROR);
        $back((int) ($_POST['_back_catalog'] ?? 0));
    }
    if ((int) $product->fields['plugin_storefront_catalogs_id']
        !== (int) $kit->fields['plugin_storefront_catalogs_id']) {
        Session::addMessageAfterRedirect(
            __('Позиция из другой витрины: набор выдаётся с одного склада.', 'storefront'), false, ERROR
        );
        $back((int) ($_POST['_back_catalog'] ?? 0));
    }

    $line = new KitItem();
    // Повторное добавление той же позиции не плодит строки, а меняет количество:
    // в базе на пару «набор + позиция» стоит ограничение уникальности.
    $found = $line->find([
        'plugin_storefront_kits_id'     => $kits_id,
        'plugin_storefront_products_id' => $pid,
    ], [], 1);
    if (count($found)) {
        $line->update(['id' => (int) array_key_first($found), 'qty' => $qty]);
        Session::addMessageAfterRedirect(__('Количество в наборе изменено.', 'storefront'), false, INFO);
    } else {
        $line->add([
            'plugin_storefront_kits_id'     => $kits_id,
            'plugin_storefront_products_id' => $pid,
            'qty'                           => $qty,
            'ranking'                       => 10 * (count($kit->items()) + 1),
        ]);
        Session::addMessageAfterRedirect(__('Позиция добавлена в набор.', 'storefront'), false, INFO);
    }
    $back((int) ($_POST['_back_catalog'] ?? 0));
}

if (isset($_POST['del_item'])) {
    Session::checkRight('plugin_storefront_catalog', UPDATE);
    $line = new KitItem();
    if ($line->getFromDB((int) ($_POST['kititems_id'] ?? 0))) {
        $line->delete(['id' => $line->getID()], true);
        Session::addMessageAfterRedirect(__('Позиция убрана из набора.', 'storefront'), false, INFO);
    }
    $back((int) ($_POST['_back_catalog'] ?? 0));
}

/* ------------------------------------------------------------ удалить набор */
if (isset($_POST['purge'])) {
    $item->check((int) $_POST['id'], PURGE);
    $kits_id = (int) $_POST['id'];
    $line = new KitItem();
    foreach ($line->find(['plugin_storefront_kits_id' => $kits_id]) as $lid => $row) {
        $line->delete(['id' => (int) $lid], true);
    }
    $item->delete($_POST, true);
    Session::addMessageAfterRedirect(__('Набор удалён.', 'storefront'), false, INFO);
    $back((int) ($_POST['_back_catalog'] ?? 0));
}

if (isset($_POST['update'])) {
    $item->check((int) $_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
}

$id = (int) ($_GET['id'] ?? -1);

Html::header(
    Kit::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'management',
    \GlpiPlugin\Storefront\Catalog::class,
    'catalog'
);

$item->display(['id' => $id] + $_GET);

Html::footer();
