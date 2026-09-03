<?php

use GlpiPlugin\Storefront\Warehouse;

Session::checkRight('plugin_storefront_catalog', READ);

$item = new Warehouse();

if (isset($_POST['add'])) {
    Session::checkRight('plugin_storefront_catalog', UPDATE);
    $cid = (int) ($_POST['plugin_storefront_catalogs_id'] ?? 0);
    $newid = (new Warehouse())->add($_POST);
    if ($newid) {
        Session::addMessageAfterRedirect(__('Склад добавлен.', 'storefront'), false, INFO);
    }
    Html::redirect(Html::getPrefixedUrl('/plugins/storefront/front/catalog.form.php?id=' . $cid));
}

if (isset($_POST['add_legacy'])) {
    $item->check(-1, CREATE, $_POST);
    $newid = $item->add($_POST);
    if ($newid) {
        Html::redirect(Plugin::getWebDir('storefront') . '/front/warehouse.form.php?id=' . $newid);
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
    Warehouse::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'management',
    \GlpiPlugin\Storefront\Catalog::class,
    'catalog'
);

$item->display(['id' => $id] + $_GET);

Html::footer();
