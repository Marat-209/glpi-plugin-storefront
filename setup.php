<?php

/**
 * storefront — внутренний магазин для GLPI 11.
 *
 * Витрина заказа запасов в интерфейсе самообслуживания, корзина, документ
 * заказа с согласованием, склады с остатками и движениями, лимиты выдачи,
 * накладная и отчётность. Номенклатура, права, сущности, цены и согласования
 * остаются за GLPI — своих сущностей плагин не заводит там, где есть штатные.
 *
 * -------------------------------------------------------------------------
 * Copyright (C) 2026 storefront contributors
 *
 * Свободное программное обеспечение: распространяется и изменяется на условиях
 * GNU General Public License версии 3 или любой более поздней, опубликованной
 * Free Software Foundation. Полный текст лицензии — в файле LICENSE.
 *
 * Программа распространяется в надежде, что окажется полезной, но БЕЗ КАКИХ
 * ЛИБО ГАРАНТИЙ. Подробности в тексте лицензии.
 * -------------------------------------------------------------------------
 */

use Glpi\Plugin\Hooks;

define('PLUGIN_STOREFRONT_VERSION', '1.0.0-rc7');
define('PLUGIN_STOREFRONT_MIN_GLPI', '11.0.0');
define('PLUGIN_STOREFRONT_MAX_GLPI', '11.99.99');

function plugin_init_storefront(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['storefront'] = true;

    $H = 'GlpiPlugin\\Storefront\\Hook';

    // --- справочники и документы в меню администрирования
    Plugin::registerClass(\GlpiPlugin\Storefront\Catalog::class, ['addtabon' => []]);
    Plugin::registerClass(\GlpiPlugin\Storefront\Warehouse::class);
    Plugin::registerClass(\GlpiPlugin\Storefront\Product::class);
    Plugin::registerClass(\GlpiPlugin\Storefront\Order::class, [
        'addtabon' => ['Ticket'],
    ]);
    Plugin::registerClass(\GlpiPlugin\Storefront\Limit::class);
    Plugin::registerClass(\GlpiPlugin\Storefront\TitleLevel::class);
    Plugin::registerClass(\GlpiPlugin\Storefront\Kit::class);

    // Остатки и движения показываем прямо на карточке штатной номенклатуры,
    // чтобы не было двух правд о запасе.
    Plugin::registerClass(\GlpiPlugin\Storefront\Stock::class, [
        'addtabon' => ['ConsumableItem', 'CartridgeItem'],
    ]);

    // --- витрина в интерфейсе самообслуживания
    //
    // Только плитка на главной и позиция в каталоге услуг. Пункт в верхнем
    // меню намеренно не регистрируем: он заводит в шапке отдельный раздел
    // «Плагины», которого до установки не было, а сотрудник ищет витрину
    // там же, где «Сообщить о проблеме» и «Запрос услуги» — среди плиток.
    $PLUGIN_HOOKS[Hooks::DISPLAY_SERVICE_CATALOG]['storefront'] = [$H, 'serviceCatalog'];

    // --- целостность: заказ живёт вместе со своей заявкой
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['storefront'] = [
        'TicketValidation' => [$H, 'postValidationUpdate'],
    ];
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_PURGE]['storefront'] = [
        'Ticket' => [$H, 'prePurgeTicket'],
    ];

    // --- меню администрирования
    //
    // Регистрируем безусловно. Проверять здесь Session::haveRight нельзя:
    // plugin_init выполняется при загрузке плагинов, и если права сессии
    // на этот момент ещё не подняты, хуки не зарегистрируются вовсе —
    // пункт меню и страница настроек просто исчезнут. Видимость меню GLPI
    // и так определяет по правам класса, а страницы проверяют право сами.
    $PLUGIN_HOOKS['menu_toadd']['storefront'] = [
        'management' => \GlpiPlugin\Storefront\Catalog::class,
    ];
    Plugin::registerClass(\GlpiPlugin\Storefront\Cron::class);
    // Вкладка прав на форме профиля: без неё права магазина нельзя
    // выдать никому, кроме профиля, получившего их при установке.
    Plugin::registerClass(\GlpiPlugin\Storefront\Profile::class, [
        'addtabon' => ['Profile'],
    ]);
    // Без ведущего слеша: GLPI склеивает адрес как
    // {root_doc}/plugins/{каталог}/{значение}, и слеш дал бы двойной.
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['storefront'] = 'front/catalog.php';
}

function plugin_version_storefront(): array
{
    return [
        'name'           => __('Внутренний магазин', 'storefront'),
        'version'        => PLUGIN_STOREFRONT_VERSION,
        'author'         => 'storefront contributors',
        'license'        => 'GPLv3+',
        'homepage'       => '',
        'minGlpiVersion' => PLUGIN_STOREFRONT_MIN_GLPI,
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_STOREFRONT_MIN_GLPI,
                'max' => PLUGIN_STOREFRONT_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_storefront_check_prerequisites(): bool
{
    return true;
}

function plugin_storefront_check_config($verbose = false): bool
{
    return true;
}
