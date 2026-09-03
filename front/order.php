<?php

use GlpiPlugin\Storefront\Order;

Session::checkRight('plugin_storefront_order', READ);

Html::header(
    Order::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'management',
    \GlpiPlugin\Storefront\Catalog::class,
    'order'
);

Search::show(Order::class);

Html::footer();
