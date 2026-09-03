<?php

use GlpiPlugin\Storefront\Order;

Session::checkRight('plugin_storefront_order', READ);

$item = new Order();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $newid = $item->add($_POST);
    if ($newid) {
        Html::redirect(Plugin::getWebDir('storefront') . '/front/order.form.php?id=' . $newid);
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $item->check((int) $_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $item->check((int) $_POST['id'], PURGE);
    $item->delete($_POST, true);
    Html::redirect(Plugin::getWebDir('storefront') . '/front/order.php');
}

$id = (int) ($_GET['id'] ?? -1);

Html::header(
    Order::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'management',
    \GlpiPlugin\Storefront\Catalog::class,
    'order'
);

$item->display(['id' => $id] + $_GET);

Html::footer();
