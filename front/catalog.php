<?php

use GlpiPlugin\Storefront\Catalog;

Session::checkRight('plugin_storefront_catalog', READ);

Html::header(
    Catalog::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'management',
    \GlpiPlugin\Storefront\Catalog::class,
    'catalog'
);

Search::show(Catalog::class);

Html::footer();
