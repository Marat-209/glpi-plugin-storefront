<?php

namespace GlpiPlugin\Storefront;

use CommonDBTM;
use Session;

/**
 * Витрина — единица настройки магазина.
 *
 * У каждой витрины свои владелец, склады, маршрут согласования, лимиты
 * и состав номенклатуры. Это то, что превращает «портал одного отдела»
 * в магазин организации: канцелярия у одного отдела, оборудование у другого,
 * спецодежда у третьего — механика одна, настройки разные.
 */
class Catalog extends CommonDBTM
{
    public static $rightname = 'plugin_storefront_catalog';
    public $dohistory = true;

    /** Режимы выбора согласующего. */
    public const APPROVE_NONE   = 'none';    // без согласования
    public const APPROVE_MANUAL = 'manual';  // сотрудник выбирает согласующего сам
    public const APPROVE_CHAIN  = 'chain';   // по цепочке руководителей с порогом должности
    public const APPROVE_GROUP  = 'group';   // всегда группа согласующих

    /** Режимы резерва остатка. */
    public const RESERVE_NONE = 'none';
    public const RESERVE_SOFT = 'soft';

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? __('Витрины магазина', 'storefront') : __('Витрина магазина', 'storefront');
    }

    public static function getIcon()
    {
        return 'ti ti-shopping-cart';
    }

    public static function getMenuName()
    {
        return __('Магазин', 'storefront');
    }

    /** Подпункты меню администрирования. */
    public static function getMenuContent()
    {
        $menu = parent::getMenuContent() ?: [];
        $menu['title'] = self::getMenuName();
        $menu['page']  = '/plugins/storefront/front/catalog.php';
        $menu['icon']  = self::getIcon();

        $menu['options']['catalog'] = [
            'title' => self::getTypeName(2),
            'page'  => '/plugins/storefront/front/catalog.php',
            'icon'  => self::getIcon(),
        ];
        $menu['options']['queue'] = [
            'title' => __('Очередь заказов', 'storefront'),
            'page'  => '/plugins/storefront/front/queue.php',
            'icon'  => 'ti ti-clipboard-check',
        ];
        $menu['options']['order'] = [
            'title' => Order::getTypeName(2),
            'page'  => '/plugins/storefront/front/order.php',
            'icon'  => Order::getIcon(),
        ];
        $menu['options']['import'] = [
            'title' => __('Загрузка номенклатуры', 'storefront'),
            'page'  => '/plugins/storefront/front/import.php',
            'icon'  => 'ti ti-upload',
        ];
        $menu['options']['stock'] = [
            'title' => __('Склад', 'storefront'),
            'page'  => '/plugins/storefront/front/stock.php',
            'icon'  => Stock::getIcon(),
        ];
        $menu['options']['analytics'] = [
            'title' => __('Аналитика', 'storefront'),
            'page'  => '/plugins/storefront/front/analytics.php',
            'icon'  => 'ti ti-chart-histogram',
        ];
        $menu['options']['report'] = [
            'title' => __('Отчёты', 'storefront'),
            'page'  => '/plugins/storefront/front/report.php',
            'icon'  => 'ti ti-chart-bar',
        ];
        $menu['options']['titlelevel'] = [
            'title' => TitleLevel::getTypeName(2),
            'page'  => '/plugins/storefront/front/titlelevel.php',
            'icon'  => TitleLevel::getIcon(),
        ];
        return $menu;
    }

    public static function approvalModes(): array
    {
        return [
            self::APPROVE_MANUAL => __('Сотрудник выбирает согласующего сам', 'storefront'),
            self::APPROVE_CHAIN  => __('Автоматически по цепочке руководителей', 'storefront'),
            self::APPROVE_GROUP  => __('Всегда группа согласующих витрины', 'storefront'),
            self::APPROVE_NONE   => __('Без согласования', 'storefront'),
        ];
    }

    /** Выбирает ли согласующего сам сотрудник. */
    public function isManualApproval(): bool
    {
        return (string) $this->fields['approval_mode'] === self::APPROVE_MANUAL;
    }

    public static function reserveModes(): array
    {
        return [
            self::RESERVE_SOFT => __('Мягкий резерв с момента взятия в работу', 'storefront'),
            self::RESERVE_NONE => __('Без резерва', 'storefront'),
        ];
    }

    /**
     * Картинки для плитки на главной странице.
     *
     * В GLPI их 173 штуки, и списком это неудобно. Отобраны те, что подходят
     * складу и заказу; если у витрины сохранена другая — она остаётся в списке,
     * чтобы сохранение не подменило картинку молча.
     */
    public function illustrations(): array
    {
        $list = [
            'request-service'   => __('Запрос услуги', 'storefront'),
            'request-support'   => __('Обращение в поддержку', 'storefront'),
            'browse-kb'         => __('База знаний', 'storefront'),
            'tickets'           => __('Заявки', 'storefront'),
            'reservation'       => __('Бронирование', 'storefront'),
            'asset-cartridge'   => __('Картриджи', 'storefront'),
            'asset-printer'     => __('Принтер', 'storefront'),
            'asset-laptop'      => __('Ноутбук', 'storefront'),
            'asset-desktop-1'   => __('Рабочее место', 'storefront'),
            'asset-peripheral'  => __('Периферия', 'storefront'),
            'asset-phone'       => __('Телефон', 'storefront'),
            'building'          => __('Здание', 'storefront'),
            'calendar'          => __('Календарь', 'storefront'),
            'bank'              => __('Финансы', 'storefront'),
        ];
        $current = (string) ($this->fields['illustration'] ?? '');
        if ($current !== '' && !isset($list[$current])) {
            $list[$current] = $current;
        }
        return $list;
    }

    /** Уровни доски объявлений: от справки до предупреждения. */
    public static function announcementLevels(): array
    {
        return [
            'info'    => __('Обычное объявление (синее)', 'storefront'),
            'warning' => __('Важное предупреждение (жёлтое)', 'storefront'),
            'danger'  => __('Ограничение приёма (красное)', 'storefront'),
        ];
    }

    /** Текст доски объявлений витрины. */
    public function announcement(): string
    {
        return trim((string) ($this->fields['announcement'] ?? ''));
    }

    public function announcementLevel(): string
    {
        $lvl = (string) ($this->fields['announcement_level'] ?? 'info');
        return isset(self::announcementLevels()[$lvl]) ? $lvl : 'info';
    }

    /** Требует ли витрина комментарий к заказу. */
    public function commentRequired(): bool
    {
        return (int) ($this->fields['comment_required'] ?? 1) === 1;
    }

    /**
     * Витрины, доступные сотруднику.
     *
     * Только активные и только в подразделениях, к которым у сотрудника есть
     * доступ. Без второго условия любой сотрудник видел бы витрины всех
     * подразделений, а вместе с ними чужую номенклатуру, цены и склады.
     * Фильтр берём штатный — тот же, которым пользуется весь GLPI.
     */
    public static function availableFor(int $users_id): array
    {
        $out = [];
        foreach ((new self())->find(['is_active' => 1], ['name ASC']) as $id => $row) {
            $cat = new self();
            $cat->fields = $row;
            if ($cat->isVisibleHere()) {
                $out[(int) $id] = $row;
            }
        }
        unset($users_id);
        return $out;
    }

    /**
     * Организации, витрины которых видны при текущем выборе организации.
     *
     * Обычное правило GLPI: своя организация и, если витрина рекурсивная, всё
     * ниже. Дополнительно — витрины дочерних организаций, помеченные как
     * доступные родителям: без этого витрина отдела не видна тем, кто работает
     * из корня, а именно так работает сотрудник самообслуживания.
     */
    public function isVisibleHere(): bool
    {
        if ((int) ($this->fields['is_active'] ?? 0) !== 1) {
            return false;
        }
        $entity = (int) ($this->fields['entities_id'] ?? 0);
        if (Session::haveAccessToEntity($entity, (int) ($this->fields['is_recursive'] ?? 0) === 1)) {
            return true;
        }
        if ((int) ($this->fields['show_to_parents'] ?? 0) !== 1) {
            return false;
        }
        // Публикация действует только вверх по дереву: соседняя организация
        // предком не является, поэтому её витрину так не увидеть.
        foreach ((array) ($_SESSION['glpiactiveentities'] ?? []) as $active) {
            if ((int) $active === $entity) {
                return true;
            }
            $sons = getSonsOf('glpi_entities', (int) $active);
            if (isset($sons[$entity])) {
                return true;
            }
        }
        return false;
    }

    /** Опубликована ли витрина для родительских организаций. */
    public function isPublishedUp(): bool
    {
        return (int) ($this->fields['show_to_parents'] ?? 0) === 1;
    }

    /**
     * Занимать ли всю ширину страницы.
     *
     * В самообслуживании GLPI держит содержимое в 1320 пикселях. Для
     * витрины с большим ассортиментом это втрое длиннее прокрутка, поэтому
     * администратор витрины может снять ограничение — но только для своей
     * витрины и только на широких экранах.
     */
    public function isWideLayout(): bool
    {
        return (int) ($this->fields['wide_layout'] ?? 0) === 1;
    }

    /** Обязан ли сотрудник выбрать согласующего сам. */
    public function requiresApprover(): bool
    {
        return (int) ($this->fields['require_approver'] ?? 0) === 1
            && (string) $this->fields['approval_mode'] === self::APPROVE_MANUAL;
    }

    /** Заголовок печатной накладной. */
    public function waybillTitle(): string
    {
        $own = trim((string) ($this->fields['waybill_title'] ?? ''));
        return $own !== '' ? $own : __('Накладная на выдачу материальных ценностей', 'storefront');
    }

    /** Организация в шапке накладной: своя надпись или название подразделения. */
    public function waybillOrg(): string
    {
        $own = trim((string) ($this->fields['waybill_org'] ?? ''));
        if ($own !== '') {
            return $own;
        }
        return (string) \Dropdown::getDropdownName(
            'glpi_entities',
            (int) $this->fields['entities_id']
        );
    }

    /** Текст под подписями: порядок приёмки, претензии, ссылка на регламент. */
    public function waybillFooter(): string
    {
        return trim((string) ($this->fields['waybill_footer'] ?? ''));
    }

    /** Должность и ФИО того, кто выдаёт, если бланк заполняется заранее. */
    public function waybillSignatory(): string
    {
        return trim((string) ($this->fields['waybill_signatory'] ?? ''));
    }

    /** Печатать ли цены и сумму. */
    public function waybillShowsPrices(): bool
    {
        return (int) ($this->fields['waybill_show_prices'] ?? 1) === 1
            && $this->showsPrices();
    }

    /** Печатать ли графу «заказано» рядом с «выдано». */
    public function waybillShowsRequested(): bool
    {
        return (int) ($this->fields['waybill_show_requested'] ?? 1) === 1;
    }

    /** Переводить ли заявку в «Решена» при отказе согласующего. */
    public function closesOnReject(): bool
    {
        return (int) ($this->fields['close_on_reject'] ?? 1) === 1;
    }

    /**
     * Может ли текущий сотрудник пользоваться этой витриной.
     *
     * Единственная точка, через которую страница магазина решает, показывать
     * витрину и принимать по ней действия. Прямой адрес чужой витрины должен
     * упираться в отказ так же, как её отсутствие в списке.
     */
    public function isAvailableToCurrentUser(): bool
    {
        if ($this->isNewItem() || (int) $this->fields['is_active'] !== 1) {
            return false;
        }
        return $this->isVisibleHere();
    }

    /**
     * Принадлежит ли позиция этой витрине.
     *
     * Проверяется при каждом обращении к позиции по идентификатору из формы:
     * подставить чужой products_id ничего не стоит.
     */
    public function ownsProduct(int $products_id, bool $active_only = true): bool
    {
        if ($products_id <= 0) {
            return false;
        }
        $crit = [
            'id'                            => $products_id,
            'plugin_storefront_catalogs_id' => $this->getID(),
        ];
        if ($active_only) {
            $crit['is_active'] = 1;
        }
        return countElementsInTable(Product::getTable(), $crit) > 0;
    }

    /** Принадлежит ли склад этой витрине. */
    public function ownsWarehouse(int $warehouses_id): bool
    {
        if ($warehouses_id <= 0) {
            return false;
        }
        return countElementsInTable(Warehouse::getTable(), [
            'id'                            => $warehouses_id,
            'plugin_storefront_catalogs_id' => $this->getID(),
        ]) > 0;
    }

    /** Активные позиции витрины с учётом порога должности сотрудника. */
    public function products(int $for_users_id = 0): array
    {
        $crit = [
            'plugin_storefront_catalogs_id' => $this->getID(),
            'is_active'                     => 1,
        ];
        $rows = (new Product())->find($crit, ['ranking ASC', 'name ASC']);
        if ($for_users_id <= 0) {
            return $rows;
        }
        $level = TitleLevel::forUser($for_users_id);
        return array_filter(
            $rows,
            static fn(array $r): bool => (int) $r['min_title_level'] <= 0
                || $level >= (int) $r['min_title_level']
        );
    }

    /** Склады витрины. */
    public function warehouses(bool $pickup_only = false): array
    {
        return Warehouse::listFor($this->getID(), $pickup_only);
    }

    /** Наборы витрины. */
    public function kits(): array
    {
        return (new Kit())->find([
            'plugin_storefront_catalogs_id' => $this->getID(),
            'is_active'                     => 1,
        ], ['name ASC']);
    }

    /** Показывать ли сотруднику цены. */
    public function showsPrices(): bool
    {
        return (bool) $this->fields['show_prices'];
    }

    /**
     * Подходит ли заказ под автосогласование.
     * Порог по сумме и по числу строк: рутинный заказ на две ручки
     * не должен ждать решения руководителя.
     */
    public function qualifiesForAutoApproval(float $amount, int $lines): bool
    {
        $maxAmount = (float) $this->fields['auto_approve_amount'];
        $maxLines  = (int) $this->fields['auto_approve_lines'];
        if ($maxAmount <= 0 && $maxLines <= 0) {
            return false;
        }
        if ($maxAmount > 0 && $amount > $maxAmount) {
            return false;
        }
        if ($maxLines > 0 && $lines > $maxLines) {
            return false;
        }
        return true;
    }

    // ==================================================== плитка на главной

    /**
     * Плитка витрины на главной странице самообслуживания.
     *
     * Это то место, куда сотрудник смотрит в первую очередь: рядом с «Сообщить
     * о проблеме» и «Запрос услуги». Пункт в выпадающем меню сверху для этого
     * не годится — его не видно. Используется штатный механизм плиток GLPI 11,
     * поэтому администратор может переставить и переименовать её обычными
     * средствами.
     */
    public function syncHomeTile(): void
    {
        global $DB;

        $tiles_id = (int) ($this->fields['tiles_id'] ?? 0);
        $wanted = (int) ($this->fields['show_on_home'] ?? 0) === 1
            && (int) $this->fields['is_active'] === 1;

        $tileClass = \Glpi\Helpdesk\Tile\ExternalPageTile::class;

        if (!$wanted) {
            if ($tiles_id > 0) {
                self::dropHomeTile($tiles_id);
                $DB->update(self::getTable(), ['tiles_id' => 0], ['id' => $this->getID()]);
            }
            return;
        }

        $data = [
            'title'        => (string) $this->fields['name'],
            'description'  => (string) $this->fields['description'],
            'illustration' => (string) ($this->fields['illustration'] ?: 'request-support'),
            'url'          => \Html::getPrefixedUrl(
                '/plugins/storefront/front/shop.php?catalog=' . $this->getID()
            ),
        ];

        $tile = new $tileClass();
        if ($tiles_id > 0 && $tile->getFromDB($tiles_id)) {
            $tile->update(['id' => $tiles_id] + $data);
            self::bindHomeTile($tiles_id, $this->tileEntity());
            return;
        }

        $entity = \Entity::getById($this->tileEntity());
        if ($entity === false) {
            $entity = \Entity::getById(0);
        }
        $manager = \Glpi\Helpdesk\Tile\TilesManager::getInstance();
        $manager->addTile($entity, $tileClass, $data);

        // Идентификатор созданной плитки менеджер не возвращает, поэтому
        // находим её по адресу — он уникален для витрины.
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_helpdesks_tiles_externalpagetiles',
            'WHERE'  => ['url' => $data['url']],
            'ORDER'  => 'id DESC',
            'LIMIT'  => 1,
        ]) as $r) {
            $DB->update(self::getTable(), ['tiles_id' => (int) $r['id']], ['id' => $this->getID()]);
        }
    }

    /**
     * В какой организации живёт плитка витрины.
     *
     * Плитки, как и витрины, наследуются вниз: плитка организации не видна тем,
     * кто работает выше. Поэтому у витрины, опубликованной для родительских
     * организаций, плитка живёт в корне — иначе сотрудник, который организацию
     * не переключает, витрину на главной не увидит. У обычной витрины плитка
     * остаётся в её собственной организации.
     */
    private function tileEntity(): int
    {
        return $this->isPublishedUp() ? 0 : (int) $this->fields['entities_id'];
    }

    /**
     * Привязать плитку к организации витрины.
     *
     * Привязка — отдельная запись, и она не следует за витриной сама. Порядок
     * внутри организации уникален, поэтому при переносе плитка встаёт в конец
     * списка целевой организации, а не занимает чужое место.
     */
    private static function bindHomeTile(int $tiles_id, int $entities_id): void
    {
        global $DB;

        $tileClass = \Glpi\Helpdesk\Tile\ExternalPageTile::class;
        $current = null;
        foreach ($DB->request([
            'FROM'  => 'glpi_helpdesks_tiles_items_tiles',
            'WHERE' => ['itemtype_tile' => $tileClass, 'items_id_tile' => $tiles_id],
            'LIMIT' => 1,
        ]) as $row) {
            $current = $row;
        }

        if ($current !== null
            && (string) $current['itemtype_item'] === \Entity::class
            && (int) $current['items_id_item'] === $entities_id) {
            return;
        }

        $rank = 1;
        foreach ($DB->request([
            'SELECT' => ['MAX' => 'rank AS maxrank'],
            'FROM'   => 'glpi_helpdesks_tiles_items_tiles',
            'WHERE'  => ['itemtype_item' => \Entity::class, 'items_id_item' => $entities_id],
        ]) as $r) {
            $rank = (int) $r['maxrank'] + 1;
        }

        $values = [
            'itemtype_item' => \Entity::class,
            'items_id_item' => $entities_id,
            'rank'          => $rank,
        ];
        if ($current !== null) {
            $DB->update('glpi_helpdesks_tiles_items_tiles', $values,
                ['id' => (int) $current['id']]);
            return;
        }
        // Привязки нет вовсе — плитка была бы невидимой ни в одной организации.
        $DB->insert('glpi_helpdesks_tiles_items_tiles', $values + [
            'itemtype_tile' => $tileClass,
            'items_id_tile' => $tiles_id,
        ]);
    }


    /** Убрать плитку и её привязку. */
    private static function dropHomeTile(int $tiles_id): void
    {
        global $DB;
        $tileClass = \Glpi\Helpdesk\Tile\ExternalPageTile::class;
        $DB->delete('glpi_helpdesks_tiles_items_tiles', [
            'itemtype_tile' => $tileClass,
            'items_id_tile' => $tiles_id,
        ]);
        $t = new $tileClass();
        if ($t->getFromDB($tiles_id)) {
            $t->delete(['id' => $tiles_id], true);
        }
    }

    public function post_addItem()
    {
        $this->syncHomeTile();
    }

    public function post_updateItem($history = true)
    {
        // Организацию витрины меняют редко, но когда меняют — за ней обязаны
        // переехать склады, номенклатура, остатки, история и заказы. Иначе
        // отчёты по организации разъезжаются: витрина здесь, движения там.
        if (in_array('entities_id', (array) $this->updates, true)) {
            $moved = $this->moveBelongings((int) $this->fields['entities_id']);
            Session::addMessageAfterRedirect(
                sprintf(
                    __('Витрина переведена в подразделение «%s». Вместе с ней переехали: ', 'storefront')
                    . __('складов %d, позиций %d, наборов %d, лимитов %d, остатков %d, ', 'storefront')
                    . __('проводок склада %d, заказов %d.', 'storefront'),
                    \Dropdown::getDropdownName('glpi_entities',
                        (int) $this->fields['entities_id']),
                    $moved['warehouses'], $moved['products'], $moved['kits'],
                    $moved['limits'], $moved['stocks'], $moved['movements'],
                    $moved['orders']
                ),
                false,
                INFO
            );
        }
        $this->syncHomeTile();
    }

    /**
     * Перевести всё хозяйство витрины в её новую организацию.
     *
     * Пишем прямыми запросами: это не изменение сути записей, а перенос
     * принадлежности, и проводить его через update() каждой записи значило бы
     * засыпать историю тысячами записей об изменении одного поля.
     *
     * @return array<string,int> сколько записей переехало по каждой таблице
     */
    private function moveBelongings(int $entities_id): array
    {
        global $DB;

        $cid = $this->getID();
        $out = ['warehouses' => 0, 'products' => 0, 'kits' => 0, 'limits' => 0,
            'stocks' => 0, 'movements' => 0, 'orders' => 0];

        // позиции витрины нужны для остатков и проводок
        $products = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Product::getTable(),
            'WHERE' => ['plugin_storefront_catalogs_id' => $cid]]) as $row) {
            $products[] = (int) $row['id'];
        }

        $byCatalog = [
            'warehouses' => Warehouse::getTable(),
            'products'   => Product::getTable(),
            'kits'       => Kit::getTable(),
            'limits'     => Limit::getTable(),
            'orders'     => Order::getTable(),
        ];
        foreach ($byCatalog as $key => $table) {
            $crit = ['plugin_storefront_catalogs_id' => $cid,
                ['NOT' => ['entities_id' => $entities_id]]];
            $out[$key] = countElementsInTable($table, $crit);
            if ($out[$key] > 0) {
                $DB->update($table, ['entities_id' => $entities_id], $crit);
            }
        }

        if (count($products)) {
            foreach (['stocks' => Stock::getTable(), 'movements' => Movement::getTable()] as $key => $table) {
                $crit = ['plugin_storefront_products_id' => $products,
                    ['NOT' => ['entities_id' => $entities_id]]];
                $out[$key] = countElementsInTable($table, $crit);
                if ($out[$key] > 0) {
                    $DB->update($table, ['entities_id' => $entities_id], $crit);
                }
            }
        }

        return $out;
    }

    /**
     * Удаление витрины.
     *
     * Витрину с заказами не удаляем: вместе с ней ушли бы позиции, склады и
     * остатки, на которые ссылается история выдач. Такую витрину выключают
     * признаком «не работает» — она исчезает у сотрудников, а учёт остаётся.
     * У пустой витрины уносим настроечные записи, иначе они остаются в базе
     * сиротами и всплывают в списках.
     */
    public function pre_deleteItem()
    {
        $tiles_id = (int) ($this->fields['tiles_id'] ?? 0);
        if ($tiles_id > 0) {
            self::dropHomeTile($tiles_id);
        }

        $orders = countElementsInTable(
            Order::getTable(),
            ['plugin_storefront_catalogs_id' => $this->getID()]
        );
        if ($orders > 0) {
            \Session::addMessageAfterRedirect(
                sprintf(
                    __('Витрину нельзя удалить: по ней есть заказы (%d). ', 'storefront')
                    . __('История выдач ссылается на её позиции и склады. ', 'storefront')
                    . __('Выключите витрину — она пропадёт у сотрудников, а учёт сохранится.', 'storefront'),
                    $orders
                ),
                false,
                ERROR
            );
            return false;
        }

        $this->dropChildren();
        return true;
    }

    /** Унести за витриной её настроечные записи. */
    private function dropChildren(): void
    {
        $id = $this->getID();

        $kit = new Kit();
        $kitItem = new KitItem();
        foreach ($kit->find(['plugin_storefront_catalogs_id' => $id]) as $kid => $row) {
            foreach ($kitItem->find(['plugin_storefront_kits_id' => $kid]) as $lid => $l) {
                $kitItem->delete(['id' => (int) $lid], true);
            }
            $kit->delete(['id' => (int) $kid], true);
        }

        $limit = new Limit();
        foreach ($limit->find(['plugin_storefront_catalogs_id' => $id]) as $lid => $row) {
            $limit->delete(['id' => (int) $lid], true);
        }

        $cart = new CartItem();
        foreach ($cart->find(['plugin_storefront_catalogs_id' => $id]) as $cid => $row) {
            $cart->delete(['id' => (int) $cid], true);
        }

        $stock = new Stock();
        $movement = new Movement();
        $product = new Product();
        foreach ($product->find(['plugin_storefront_catalogs_id' => $id]) as $pid => $row) {
            foreach ($stock->find(['plugin_storefront_products_id' => $pid]) as $sid => $sr) {
                $stock->delete(['id' => (int) $sid], true);
            }
            foreach ($movement->find(['plugin_storefront_products_id' => $pid]) as $mid => $mr) {
                $movement->delete(['id' => (int) $mid], true);
            }
            // Сама номенклатура — объект GLPI, её не трогаем: она могла
            // использоваться и вне магазина.
            $product->delete(['id' => (int) $pid], true);
        }

        $warehouse = new Warehouse();
        foreach ($warehouse->find(['plugin_storefront_catalogs_id' => $id]) as $wid => $row) {
            $warehouse->delete(['id' => (int) $wid], true);
        }

    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        \Glpi\Application\View\TemplateRenderer::getInstance()->display(
            '@storefront/catalog.html.twig',
            [
                'item'            => $this,
                'params'          => $options,
                'approval_modes'  => self::approvalModes(),
                'reserve_modes'   => self::reserveModes(),
                'illustrations'   => $this->illustrations(),
                'announcement_levels' => self::announcementLevels(),
                'levels'          => TitleLevel::levels(),
            ]
        );
        return true;
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();
        $tab[] = ['id' => '3', 'table' => $this->getTable(), 'field' => 'is_active',
            'name' => __('Активна', 'storefront'), 'datatype' => 'bool'];
        $tab[] = ['id' => '4', 'table' => $this->getTable(), 'field' => 'approval_mode',
            'name' => __('Режим согласования', 'storefront'), 'datatype' => 'string'];
        $tab[] = ['id' => '5', 'table' => $this->getTable(), 'field' => 'min_title_level',
            'name' => __('Порог должности', 'storefront'), 'datatype' => 'number'];
        $tab[] = ['id' => '6', 'table' => $this->getTable(), 'field' => 'auto_approve_amount',
            'name' => __('Автосогласование до суммы', 'storefront'), 'datatype' => 'decimal'];
        $tab[] = ['id' => '7', 'table' => $this->getTable(), 'field' => 'comment',
            'name' => __('Описание', 'storefront'), 'datatype' => 'text'];
        return $tab;
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(Warehouse::class, $tabs, $options);
        $this->addStandardTab(Product::class, $tabs, $options);
        $this->addStandardTab(Kit::class, $tabs, $options);
        $this->addStandardTab(Limit::class, $tabs, $options);
        $this->addStandardTab(\Log::class, $tabs, $options);
        return $tabs;
    }

    /**
     * Значения по умолчанию для новой витрины.
     *
     * GLPI заполняет поля новой записи пустыми строками, а не значениями по
     * умолчанию из схемы. Без этого метода витрина, созданная «как есть»,
     * получалась выключенной, без плитки, без наследования вниз и с порогом
     * должности «стажёр» — то есть не такой, как описано в руководстве.
     */
    public function post_getEmpty()
    {
        $this->fields['is_active'] = 1;
        $this->fields['is_recursive'] = 1;
        $this->fields['icon'] = 'ti ti-package';
        $this->fields['illustration'] = 'request-support';
        $this->fields['show_on_home'] = 1;
        $this->fields['wide_layout'] = 0;
        $this->fields['announcement_level'] = 'info';
        $this->fields['approval_mode'] = self::APPROVE_CHAIN;
        $this->fields['min_title_level'] = TitleLevel::L_CHIEF;
        $this->fields['reserve_mode'] = self::RESERVE_SOFT;
        $this->fields['show_stock'] = 1;
        $this->fields['comment_required'] = 1;
        $this->fields['allow_recipient'] = 1;
        $this->fields['close_on_reject'] = 1;
        $this->fields['waybill_show_requested'] = 1;
        $this->fields['auto_approve_amount'] = 0;
        $this->fields['auto_approve_lines'] = 0;
        $this->fields['max_lines'] = 0;
        parent::post_getEmpty();
    }

    public function prepareInputForAdd($input)
    {
        return $this->prepareInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->prepareInput($input);
    }

    /**
     * Числовые поля формы, которые приходят пустыми, если список не выбран.
     *
     * Пустая строка в целочисленной колонке роняет запрос, и вместо
     * «не сохранилось» администратор получает страницу ошибки. Такое бывает
     * не только от кривого запроса: если на странице не отработал скрипт,
     * выпадающие списки уходят пустыми.
     */
    private const NUMERIC_FIELDS = [
        'groups_id_approver', 'groups_id_fulfil', 'itilcategories_id', 'tiles_id',
        'min_title_level', 'auto_approve_amount', 'auto_approve_lines', 'max_lines',
        'announcement_level', 'is_active', 'is_recursive', 'show_prices', 'show_stock',
        'show_on_home', 'wide_layout', 'comment_required', 'show_to_parents',
        'require_approver',
        'close_on_reject', 'waybill_show_prices', 'waybill_show_requested',
    ];

    /** Витрина без группы согласующих опасна: заказы зависнут без согласующего. */
    private function prepareInput($input)
    {
        if (!is_array($input)) {
            return $input;
        }
        // Свои имена полей подразделения и рекурсии переносим в настоящие:
        // одноимённые скрытые поля GLPI стоят в подвале формы и иначе
        // затирают выбор администратора.
        foreach (['_entities_id' => 'entities_id',
            '_is_recursive' => 'is_recursive'] as $own => $real) {
            if (array_key_exists($own, $input)) {
                if (trim((string) $input[$own]) !== '') {
                    $input[$real] = (int) $input[$own];
                }
                unset($input[$own]);
            }
        }
        foreach (self::NUMERIC_FIELDS as $field) {
            if (array_key_exists($field, $input) && trim((string) $input[$field]) === '') {
                $input[$field] = 0;
            }
        }
        // Пустое подразделение — это не «в корень», а «поле не пришло»:
        // принадлежность витрины таким запросом менять нельзя.
        if (array_key_exists('entities_id', $input)
            && trim((string) $input['entities_id']) === '') {
            unset($input['entities_id']);
        }
        // Сотрудник самообслуживания организацию не переключает и работает из
        // корневой: витрина, положенная в дочернюю организацию, ему не видна —
        // наследование в GLPI идёт вниз, а не вверх. Молча это не оставляем.
        if (array_key_exists('entities_id', $input) && (int) $input['entities_id'] !== 0) {
            Session::addMessageAfterRedirect(
                __('Витрина сохранена не в корневой организации. Сотрудники, которые ', 'storefront')
                . __('работают из корня, её не увидят: витрина видна только в своей ', 'storefront')
                . __('организации и ниже. Обычно витрину размещают в корневой.', 'storefront'),
                false,
                WARNING
            );
        }

        $mode = (string) ($input['approval_mode']
            ?? ($this->fields['approval_mode'] ?? self::APPROVE_CHAIN));
        $group = (int) ($input['groups_id_approver']
            ?? ($this->fields['groups_id_approver'] ?? 0));

        if ($mode !== self::APPROVE_NONE && $group <= 0) {
            self::warnNoApproverGroup();
        }

        $fulfil = (int) ($input['groups_id_fulfil']
            ?? ($this->fields['groups_id_fulfil'] ?? 0));
        if ($fulfil <= 0) {
            Session::addMessageAfterRedirect(
                __('У витрины не указана группа исполнителей. Очередь склада в плагине ', 'storefront')
                . __('работает и без неё, но заявка создаётся без назначенной группы: ', 'storefront')
                . __('в списках GLPI её никто не увидит своей.', 'storefront'),
                false,
                WARNING
            );
        }
        return $input;
    }

    private static function warnNoApproverGroup(): void
    {
        Session::addMessageAfterRedirect(
            __('Внимание: у витрины не указана группа согласующих. ', 'storefront')
            . __('Руководитель заполнен далеко не у всех сотрудников, и без группы ', 'storefront')
            . __('такие заказы зависнут без согласующего.', 'storefront'),
            false,
            WARNING
        );
    }
}
