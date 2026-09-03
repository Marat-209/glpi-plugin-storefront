<?php

/**
 * Установка, обновление и удаление схемы плагина storefront.
 *
 * Принцип: номенклатура, склады-расположения, цены, права и согласования —
 * штатные объекты GLPI. Плагин добавляет только витрину, документ заказа,
 * количественный учёт и правила.
 */

function plugin_storefront_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_STOREFRONT_VERSION);
    $charset = 'utf8mb4';
    $collate = 'utf8mb4_unicode_ci';

    /* ======================================================== витрины */
    if (!$DB->tableExists('glpi_plugin_storefront_catalogs')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_storefront_catalogs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(255) DEFAULT NULL,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_recursive` TINYINT NOT NULL DEFAULT 1,
            `is_active` TINYINT NOT NULL DEFAULT 1,
            `icon` VARCHAR(60) NOT NULL DEFAULT 'ti ti-package',
            `description` TEXT DEFAULT NULL,
            `header` TEXT DEFAULT NULL,

            -- кто исполняет и кто согласует
            `groups_id_fulfil` INT UNSIGNED NOT NULL DEFAULT 0,
            `groups_id_approver` INT UNSIGNED NOT NULL DEFAULT 0,
            `approval_mode` VARCHAR(20) NOT NULL DEFAULT 'chain',
            `min_title_level` INT NOT NULL DEFAULT 60,

            -- автосогласование рутинных заказов
            `auto_approve_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `auto_approve_lines` INT NOT NULL DEFAULT 0,

            -- маршрут этапов, если плагин itilflow установлен
            `plugin_itilflow_processes_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `itilcategories_id` INT UNSIGNED NOT NULL DEFAULT 0,

            -- что видит и что может сотрудник
            `show_prices` TINYINT NOT NULL DEFAULT 0,
            `show_stock` TINYINT NOT NULL DEFAULT 1,
            `allow_free_text` TINYINT NOT NULL DEFAULT 1,
            `allow_recipient` TINYINT NOT NULL DEFAULT 1,
            -- пояснение «зачем» экономит согласующему переписку
            `comment_required` TINYINT NOT NULL DEFAULT 1,
            `max_lines` INT NOT NULL DEFAULT 0,
            `reserve_mode` VARCHAR(20) NOT NULL DEFAULT 'soft',

            -- плитка на главной странице самообслуживания
            `show_on_home` TINYINT NOT NULL DEFAULT 1,
            -- витрина во всю ширину страницы: выбор администратора витрины
            `wide_layout` TINYINT NOT NULL DEFAULT 0,
            `illustration` VARCHAR(60) NOT NULL DEFAULT 'request-support',
            `tiles_id` INT UNSIGNED NOT NULL DEFAULT 0,

            -- доска объявлений над карточками: правила приёма, места выдачи
            `announcement` TEXT DEFAULT NULL,
            `announcement_level` VARCHAR(20) NOT NULL DEFAULT 'info',

            `comment` TEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `entities_id` (`entities_id`),
            KEY `is_recursive` (`is_recursive`),
            KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate}");
    }

    /* ======================================================== склады витрины */
    if (!$DB->tableExists('glpi_plugin_storefront_warehouses')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_storefront_warehouses` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(255) DEFAULT NULL,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_recursive` TINYINT NOT NULL DEFAULT 0,
            `plugin_storefront_catalogs_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `locations_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id_tech` INT UNSIGNED NOT NULL DEFAULT 0,
            `groups_id_tech` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_default` TINYINT NOT NULL DEFAULT 0,
            `is_active` TINYINT NOT NULL DEFAULT 1,
            `is_pickup` TINYINT NOT NULL DEFAULT 1,
            `comment` TEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `catalog` (`plugin_storefront_catalogs_id`,`is_active`),
            KEY `locations_id` (`locations_id`),
            KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate}");
    }

    /* ======================================================== позиции витрины */
    // Обёртка над штатной номенклатурой: добавляет то, чего в GLPI нет —
    // единицу измерения, признак активности в витрине, размер упаковки.
    if (!$DB->tableExists('glpi_plugin_storefront_products')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_storefront_products` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(255) DEFAULT NULL,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_recursive` TINYINT NOT NULL DEFAULT 1,
            `plugin_storefront_catalogs_id` INT UNSIGNED NOT NULL DEFAULT 0,

            -- ссылка на штатный объект GLPI: ConsumableItem, CartridgeItem,
            -- Computer, Monitor, Peripheral и т. п.
            `itemtype` VARCHAR(100) NOT NULL DEFAULT 'ConsumableItem',
            `items_id` INT UNSIGNED NOT NULL DEFAULT 0,

            -- вид учёта: количественный или экземплярный
            `tracking` VARCHAR(20) NOT NULL DEFAULT 'quantity',

            `unit` VARCHAR(30) NOT NULL DEFAULT __('шт', 'storefront'),
            `pack_size` INT NOT NULL DEFAULT 1,
            `is_active` TINYINT NOT NULL DEFAULT 1,
            -- своё описание витрины: в карточке номенклатуры GLPI лежит
            -- служебный комментарий, а сотруднику нужен человеческий текст
            `description` TEXT DEFAULT NULL,
            -- платная позиция: цену видно даже там, где витрина цены прячет
            `is_chargeable` TINYINT NOT NULL DEFAULT 0,
            `price` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `use_infocom_price` TINYINT NOT NULL DEFAULT 1,
            `max_qty` INT NOT NULL DEFAULT 0,
            `ranking` INT NOT NULL DEFAULT 0,
            `min_title_level` INT NOT NULL DEFAULT 0,
            `comment` TEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_storefront_catalogs_id`,`itemtype`,`items_id`),
            KEY `catalog_active` (`plugin_storefront_catalogs_id`,`is_active`,`ranking`),
            KEY `item` (`itemtype`,`items_id`),
            KEY `name` (`name`),
            KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate}");
    }

    /* ======================================================== остатки */
    // Агрегат, а не журнал: одна строка на позицию × склад. На 45 тысячах
    // пользователей считать остаток суммой движений на каждый показ витрины
    // нельзя, поэтому храним готовое число и правим его вместе с движением.
    if (!$DB->tableExists('glpi_plugin_storefront_stocks')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_storefront_stocks` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_storefront_products_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `plugin_storefront_warehouses_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `qty_on_hand` INT NOT NULL DEFAULT 0,
            `qty_reserved` INT NOT NULL DEFAULT 0,
            `threshold` INT NOT NULL DEFAULT 0,
            `target` INT NOT NULL DEFAULT 0,
            `date_counted` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_storefront_products_id`,`plugin_storefront_warehouses_id`),
            KEY `warehouse` (`plugin_storefront_warehouses_id`),
            KEY `low` (`plugin_storefront_warehouses_id`,`qty_on_hand`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate}");
    }

    /* ======================================================== движения */
    if (!$DB->tableExists('glpi_plugin_storefront_movements')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_storefront_movements` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `plugin_storefront_products_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `plugin_storefront_warehouses_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `plugin_storefront_orders_id` INT UNSIGNED NOT NULL DEFAULT 0,

            -- in приход | out выдача | adjust инвентаризация | reserve | unreserve
            `type` VARCHAR(20) NOT NULL DEFAULT 'in',
            `qty` INT NOT NULL DEFAULT 0,
            `qty_before` INT NOT NULL DEFAULT 0,
            `qty_after` INT NOT NULL DEFAULT 0,

            `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id_recipient` INT UNSIGNED NOT NULL DEFAULT 0,
            `groups_id_recipient` INT UNSIGNED NOT NULL DEFAULT 0,
            `entities_id_recipient` INT UNSIGNED NOT NULL DEFAULT 0,
            `suppliers_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `document_no` VARCHAR(100) DEFAULT NULL,
            `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `comment` TEXT DEFAULT NULL,
            `date` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `product_date` (`plugin_storefront_products_id`,`date`),
            KEY `warehouse_date` (`plugin_storefront_warehouses_id`,`date`),
            KEY `order` (`plugin_storefront_orders_id`),
            KEY `type_date` (`type`,`date`),
            KEY `recipient_date` (`users_id_recipient`,`date`),
            KEY `group_recipient_date` (`groups_id_recipient`,`date`),
            KEY `entity_recipient_date` (`entities_id_recipient`,`date`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate}");
    }

    /* ======================================================== заказы */
    if (!$DB->tableExists('glpi_plugin_storefront_orders')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_storefront_orders` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(255) DEFAULT NULL,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_recursive` TINYINT NOT NULL DEFAULT 0,
            `plugin_storefront_catalogs_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `plugin_storefront_warehouses_id` INT UNSIGNED NOT NULL DEFAULT 0,

            -- связанная заявка GLPI
            `itemtype` VARCHAR(100) NOT NULL DEFAULT 'Ticket',
            `items_id` INT UNSIGNED NOT NULL DEFAULT 0,

            `users_id_requester` INT UNSIGNED NOT NULL DEFAULT 0,

            -- для кого заказ: self себе | user сотруднику | group отделу | entity подразделению
            `recipient_type` VARCHAR(20) NOT NULL DEFAULT 'self',
            `users_id_recipient` INT UNSIGNED NOT NULL DEFAULT 0,
            `groups_id_recipient` INT UNSIGNED NOT NULL DEFAULT 0,
            `entities_id_recipient` INT UNSIGNED NOT NULL DEFAULT 0,
            `recipient_note` VARCHAR(255) DEFAULT NULL,
            -- из какого набора собран заказ: по нему считается «выдан ли
            -- стартовый набор этому человеку»
            `plugin_storefront_kits_id` INT UNSIGNED NOT NULL DEFAULT 0,

            -- draft | approval | queue | approved | ready | issued | cancelled | rejected
            `state` VARCHAR(20) NOT NULL DEFAULT 'draft',
            `users_id_approver` INT UNSIGNED NOT NULL DEFAULT 0,
            `approval_source` VARCHAR(20) DEFAULT NULL,
            `approval_comment` TEXT DEFAULT NULL,
            `is_auto_approved` TINYINT NOT NULL DEFAULT 0,

            `lines_count` INT NOT NULL DEFAULT 0,
            `qty_requested` INT NOT NULL DEFAULT 0,
            `qty_approved` INT NOT NULL DEFAULT 0,
            `qty_issued` INT NOT NULL DEFAULT 0,
            `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,

            `waybill_no` VARCHAR(60) DEFAULT NULL,
            `date_submitted` TIMESTAMP NULL DEFAULT NULL,
            `date_approved` TIMESTAMP NULL DEFAULT NULL,
            `date_issued` TIMESTAMP NULL DEFAULT NULL,
            `comment` TEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `state_date` (`state`,`date_creation`),
            KEY `requester_state` (`users_id_requester`,`state`),
            KEY `recipient` (`users_id_recipient`),
            KEY `catalog_state` (`plugin_storefront_catalogs_id`,`state`),
            KEY `item` (`itemtype`,`items_id`),
            KEY `entities_id` (`entities_id`),
            KEY `date_issued` (`date_issued`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate}");
    }

    /* ======================================================== строки заказа */
    if (!$DB->tableExists('glpi_plugin_storefront_orderitems')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_storefront_orderitems` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_storefront_orders_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `plugin_storefront_products_id` INT UNSIGNED NOT NULL DEFAULT 0,

            -- снимок на момент заказа: номенклатуру могут переименовать
            `itemtype` VARCHAR(100) DEFAULT NULL,
            `items_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `name_snapshot` VARCHAR(255) DEFAULT NULL,
            `unit_snapshot` VARCHAR(30) DEFAULT NULL,
            `price_snapshot` DECIMAL(12,2) NOT NULL DEFAULT 0,

            `qty_requested` INT NOT NULL DEFAULT 0,
            `qty_approved` INT NOT NULL DEFAULT 0,
            `qty_issued` INT NOT NULL DEFAULT 0,
            -- сколько единиц этот заказ сейчас держит в резерве склада:
            -- единственный источник правды, чтобы снятие резерва не гадало
            `qty_reserved` INT NOT NULL DEFAULT 0,
            `change_reason` TEXT DEFAULT NULL,

            -- позиция «нет в каталоге»
            `is_free_text` TINYINT NOT NULL DEFAULT 0,
            `free_text` TEXT DEFAULT NULL,

            -- инвентарные номера при экземплярной выдаче
            `issued_items` TEXT DEFAULT NULL,

            `ranking` INT NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `order_rank` (`plugin_storefront_orders_id`,`ranking`),
            KEY `product` (`plugin_storefront_products_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate}");
    }

    /* ======================================================== лимиты */
    if (!$DB->tableExists('glpi_plugin_storefront_limits')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_storefront_limits` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(255) DEFAULT NULL,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_recursive` TINYINT NOT NULL DEFAULT 1,
            `plugin_storefront_catalogs_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_active` TINYINT NOT NULL DEFAULT 1,

            -- на кого: all | user | group | entity | title
            `scope` VARCHAR(20) NOT NULL DEFAULT 'all',
            `scope_items_id` INT UNSIGNED NOT NULL DEFAULT 0,

            -- чья норма: each — у каждого своя, total — одна на всю область
            `scope_mode` VARCHAR(10) NOT NULL DEFAULT 'each',

            -- на что: catalog | category | product
            `target` VARCHAR(20) NOT NULL DEFAULT 'product',
            `target_items_id` INT UNSIGNED NOT NULL DEFAULT 0,

            `period` VARCHAR(20) NOT NULL DEFAULT 'month',
            `max_qty` INT NOT NULL DEFAULT 0,
            `max_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `is_hard` TINYINT NOT NULL DEFAULT 0,
            `comment` TEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `catalog_active` (`plugin_storefront_catalogs_id`,`is_active`),
            KEY `scope` (`scope`,`scope_items_id`),
            KEY `target` (`target`,`target_items_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate}");
    }

    /* ======================================================== уровни должностей */
    // На проде 916 должностей и 77 % заполненности. Размечать руками нельзя,
    // поэтому уровень выводится из названия шаблоном, а здесь хранятся
    // результат и правки администратора.
    if (!$DB->tableExists('glpi_plugin_storefront_titlelevels')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_storefront_titlelevels` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `usertitles_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `level` INT NOT NULL DEFAULT 30,
            `is_manual` TINYINT NOT NULL DEFAULT 0,
            `can_approve` TINYINT NOT NULL DEFAULT 0,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `usertitles_id` (`usertitles_id`),
            KEY `level` (`level`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate}");
    }

    /* ======================================================== наборы */
    if (!$DB->tableExists('glpi_plugin_storefront_kits')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_storefront_kits` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(255) DEFAULT NULL,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_recursive` TINYINT NOT NULL DEFAULT 1,
            `plugin_storefront_catalogs_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_active` TINYINT NOT NULL DEFAULT 1,
            -- стартовый набор: выдаётся один раз, потом исчезает у сотрудника
            `is_once` TINYINT NOT NULL DEFAULT 0,
            `icon` VARCHAR(60) NOT NULL DEFAULT 'ti ti-briefcase',
            `min_title_level` INT NOT NULL DEFAULT 0,
            `comment` TEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `catalog_active` (`plugin_storefront_catalogs_id`,`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate}");
    }
    if (!$DB->tableExists('glpi_plugin_storefront_kititems')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_storefront_kititems` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_storefront_kits_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `plugin_storefront_products_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `qty` INT NOT NULL DEFAULT 1,
            `ranking` INT NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_storefront_kits_id`,`plugin_storefront_products_id`),
            KEY `kit_rank` (`plugin_storefront_kits_id`,`ranking`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate}");
    }

    /* ======================================================== оценки позиций */
    // Оценку ставит только тот, кто позицию получал: иначе это не отзыв
    // о вещи, а голосование за ассортимент.
    if (!$DB->tableExists('glpi_plugin_storefront_ratings')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_storefront_ratings` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_storefront_products_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `stars` TINYINT NOT NULL DEFAULT 0,
            `comment` TEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_storefront_products_id`,`users_id`),
            KEY `product_stars` (`plugin_storefront_products_id`,`stars`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate}");
    }

    /* ======================================================== разрешения на повтор набора */
    // Разовый набор иногда нужен повторно: перевод в другое подразделение,
    // утрата. Разрешение выдаёт администратор и оно гасится при выдаче.
    if (!$DB->tableExists('glpi_plugin_storefront_kitgrants')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_storefront_kitgrants` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_storefront_kits_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id_author` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_used` TINYINT NOT NULL DEFAULT 0,
            `reason` VARCHAR(255) DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `kit_user` (`plugin_storefront_kits_id`,`users_id`,`is_used`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate}");
    }

    /* ======================================================== корзина */
    // Черновик заказа держим в своей таблице, а не в сессии: сотрудник должен
    // иметь возможность собрать корзину с телефона и оформить с компьютера.
    if (!$DB->tableExists('glpi_plugin_storefront_cartitems')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_storefront_cartitems` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `plugin_storefront_catalogs_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `plugin_storefront_products_id` INT UNSIGNED NOT NULL DEFAULT 0,
            -- из какого набора строка: заказ унаследует это, и разовый набор
            -- отметится выданным. В сессии такую метку держать нельзя —
            -- корзину собирают с телефона, а оформляют с компьютера.
            `plugin_storefront_kits_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `qty` INT NOT NULL DEFAULT 1,
            `is_free_text` TINYINT NOT NULL DEFAULT 0,
            `free_text` TEXT DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `user_catalog` (`users_id`,`plugin_storefront_catalogs_id`),
            KEY `product` (`plugin_storefront_products_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate}");
    }

    /* ======================================================== права и cron */
    // обновление уже установленных копий
    $migration->addField('glpi_plugin_storefront_orders', 'recipient_type', 'string',
        ['value' => 'self']);
    // Внешние ключи заводим типом fkey: GLPI требует беззнаковые целые
    // и предупреждает при обновлении, если тип обычный integer.
    $migration->addField('glpi_plugin_storefront_orders', 'entities_id_recipient', 'fkey');
    $migration->addField('glpi_plugin_storefront_orders', 'recipient_note', 'string',
        ['value' => null]);
    $migration->addField('glpi_plugin_storefront_movements', 'groups_id_recipient', 'fkey');
    $migration->addField('glpi_plugin_storefront_movements', 'entities_id_recipient', 'fkey');
    $migration->addField('glpi_plugin_storefront_orderitems', 'qty_reserved', 'integer',
        ['value' => 0]);
    $migration->addKey('glpi_plugin_storefront_movements',
        ['groups_id_recipient', 'date'], 'group_recipient_date');
    $migration->addKey('glpi_plugin_storefront_movements',
        ['entities_id_recipient', 'date'], 'entity_recipient_date');
    $migration->addField('glpi_plugin_storefront_catalogs', 'show_on_home', 'bool', ['value' => 1]);
    $migration->addField('glpi_plugin_storefront_catalogs', 'illustration', 'string',
        ['value' => 'request-support']);
    $migration->addField('glpi_plugin_storefront_catalogs', 'tiles_id', 'fkey');
    // Витрина организации видна только внутри неё и ниже: наследование в GLPI
    // идёт вниз. Сотрудник самообслуживания организацию не переключает и
    // работает из корня, поэтому витрину нужно уметь публиковать вверх.
    // Печатная накладная: реквизиты и вид документа настраиваются на витрине,
    // потому что у разных складов свои бланки и свои подписанты.
    $migration->addField('glpi_plugin_storefront_catalogs', 'waybill_org', 'string');
    $migration->addField('glpi_plugin_storefront_catalogs', 'waybill_title', 'string');
    $migration->addField('glpi_plugin_storefront_catalogs', 'waybill_footer', 'text');
    $migration->addField('glpi_plugin_storefront_catalogs', 'waybill_signatory', 'string');
    $migration->addField('glpi_plugin_storefront_catalogs', 'waybill_show_prices', 'bool',
        ['value' => 1]);
    $migration->addField('glpi_plugin_storefront_catalogs', 'waybill_show_requested', 'bool',
        ['value' => 1]);
    // Норма лимита: у каждого своя или одна общая на отдел, подразделение,
    // должность или витрину целиком. Умолчание сохраняет прежнее поведение.
    $migration->addField('glpi_plugin_storefront_catalogs', 'wide_layout', 'bool',
        ['value' => 0, 'after' => 'show_on_home']);
    $migration->addField('glpi_plugin_storefront_limits', 'scope_mode', 'string',
        ['value' => 'each', 'after' => 'scope_items_id']);

    $migration->addField('glpi_plugin_storefront_catalogs', 'show_to_parents', 'bool',
        ['value' => 0]);
    $migration->addField('glpi_plugin_storefront_catalogs', 'require_approver', 'bool',
        ['value' => 0]);
    $migration->addField('glpi_plugin_storefront_catalogs', 'close_on_reject', 'bool',
        ['value' => 1]);
    $migration->addField('glpi_plugin_storefront_catalogs', 'comment_required', 'bool',
        ['value' => 1]);
    $migration->addField('glpi_plugin_storefront_catalogs', 'announcement', 'text');
    $migration->addField('glpi_plugin_storefront_catalogs', 'announcement_level', 'string',
        ['value' => 'info']);
    $migration->addField('glpi_plugin_storefront_products', 'description', 'text');
    $migration->addField('glpi_plugin_storefront_products', 'is_chargeable', 'bool',
        ['value' => 0]);
    $migration->addField('glpi_plugin_storefront_kits', 'is_once', 'bool', ['value' => 0]);
    $migration->addField('glpi_plugin_storefront_orders', 'plugin_storefront_kits_id', 'fkey');
    $migration->addField('glpi_plugin_storefront_cartitems', 'plugin_storefront_kits_id', 'fkey');

    // Копии, обновлённые ранней 0.3.0, получили эти поля знаковыми — правим.
    foreach ([
        'glpi_plugin_storefront_orders'    => ['entities_id_recipient'],
        'glpi_plugin_storefront_movements' => ['groups_id_recipient', 'entities_id_recipient'],
        'glpi_plugin_storefront_catalogs'  => ['tiles_id'],
    ] as $table => $fields) {
        foreach ($fields as $field) {
            $migration->changeField($table, $field, $field, 'fkey');
        }
    }

    $migration->addRight('plugin_storefront_catalog', ALLSTANDARDRIGHT);
    $migration->addRight('plugin_storefront_order', READ | UPDATE);
    $migration->addRight('plugin_storefront_stock', READ | UPDATE);
    $migration->executeMigration();

    CronTask::register(
        'GlpiPlugin\\Storefront\\Cron',
        'storefront_lowstock',
        86400,
        [
            'comment' => __('Оповещение об остатках ниже порога и расчёт потребности к закупке', 'storefront'),
            'mode'    => CronTask::MODE_EXTERNAL,
            'state'   => CronTask::STATE_WAITING,
        ]
    );
    CronTask::register(
        'GlpiPlugin\\Storefront\\Cron',
        'storefront_cartcleanup',
        604800,
        [
            'comment' => __('Очистка брошенных корзин старше 30 дней', 'storefront'),
            'mode'    => CronTask::MODE_EXTERNAL,
            'state'   => CronTask::STATE_WAITING,
        ]
    );
    CronTask::register(
        'GlpiPlugin\\Storefront\\Cron',
        'storefront_reserves',
        86400,
        [
            'comment' => __('Возврат в оборот резерва, который не держит ни один заказ', 'storefront'),
            'mode'    => CronTask::MODE_EXTERNAL,
            'state'   => CronTask::STATE_WAITING,
        ]
    );

    return true;
}

function plugin_storefront_uninstall(): bool
{
    global $DB;

    // История выдач — учётные данные, они не удаляются вместе с плагином.
    // Справочники переименовываются с суффиксом даты, движения и заказы остаются.
    $keep = [
        'glpi_plugin_storefront_orders',
        'glpi_plugin_storefront_orderitems',
        'glpi_plugin_storefront_movements',
        'glpi_plugin_storefront_stocks',
    ];
    $rename = [
        'glpi_plugin_storefront_catalogs',
        'glpi_plugin_storefront_warehouses',
        'glpi_plugin_storefront_products',
        'glpi_plugin_storefront_limits',
        'glpi_plugin_storefront_kits',
        'glpi_plugin_storefront_kititems',
        'glpi_plugin_storefront_kitgrants',
        'glpi_plugin_storefront_ratings',
        'glpi_plugin_storefront_titlelevels',
    ];
    $drop = ['glpi_plugin_storefront_cartitems'];

    foreach ($rename as $t) {
        if ($DB->tableExists($t)) {
            $DB->doQuery("RENAME TABLE `{$t}` TO `{$t}_backup_" . date('Ymd') . "`");
        }
    }
    foreach ($drop as $t) {
        if ($DB->tableExists($t)) {
            $DB->doQuery("DROP TABLE `{$t}`");
        }
    }
    unset($keep);

    ProfileRight::deleteProfileRights([
        'plugin_storefront_catalog',
        'plugin_storefront_order',
        'plugin_storefront_stock',
    ]);

    return true;
}
