<?php

use GlpiPlugin\Storefront\Catalog;

Session::checkRight('plugin_storefront_catalog', READ);

$item = new Catalog();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $newid = $item->add($_POST);
    if ($newid) {
        Html::redirect(Plugin::getWebDir('storefront') . '/front/catalog.form.php?id=' . $newid);
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $item->check((int) $_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $item->check((int) $_POST['id'], PURGE);
    $item->delete($_POST, true);
    Html::redirect(Plugin::getWebDir('storefront') . '/front/catalog.php');
}

$id = (int) ($_GET['id'] ?? -1);

Html::header(
    Catalog::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'management',
    \GlpiPlugin\Storefront\Catalog::class,
    'catalog'
);

$item->display(['id' => $id] + $_GET);

Html::footer();
