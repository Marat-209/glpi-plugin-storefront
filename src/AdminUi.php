<?php

namespace GlpiPlugin\Storefront;

use Html;
use Session;

/**
 * Административные вкладки витрины: склады, позиции, лимиты, наборы.
 *
 * Каждая вкладка — список плюс форма добавления прямо под ним. Отдельные
 * страницы «создать склад» здесь были бы лишним шагом: администратор
 * настраивает витрину целиком, не уходя с её карточки.
 */
final class AdminUi
{
    private static function esc(?string $s): string
    {
        return htmlescape((string) $s);
    }

    private static function url(string $page): string
    {
        return Html::getPrefixedUrl('/plugins/storefront/front/' . $page);
    }

    private static function canEdit(): bool
    {
        return Session::haveRight('plugin_storefront_catalog', UPDATE);
    }

    // ==================================================== склады

    public static function warehouses(Catalog $catalog): void
    {
        $rows = (new Warehouse())->find(
            ['plugin_storefront_catalogs_id' => $catalog->getID()],
            ['is_default DESC', 'name ASC']
        );

        echo '<div class="m-3">';
        echo __('<table class="table table-sm"><thead><tr><th>Склад</th><th>Расположение</th>', 'storefront')
            . __('<th>Ответственный</th><th>По умолчанию</th><th>Активен</th>', 'storefront')
            . __('<th>Выдача</th><th></th></tr></thead><tbody>', 'storefront');
        foreach ($rows as $id => $r) {
            printf(
                '<tr><td><a href="%s?id=%d">%s</a></td><td>%s</td><td>%s</td>'
                . '<td>%s</td><td>%s</td><td>%s</td>'
                . '<td class="text-end">%s</td></tr>',
                self::esc(self::url('warehouse.form.php')),
                (int) $id,
                self::esc($r['name']),
                self::esc(\Dropdown::getDropdownName('glpi_locations', (int) $r['locations_id'])),
                (int) $r['users_id_tech'] > 0
                    ? self::esc(getUserName((int) $r['users_id_tech'])) : '—',
                (int) $r['is_default'] ? __('<span class="badge bg-blue-lt">да</span>', 'storefront') : '',
                (int) $r['is_active']
                    ? __('<span class="badge bg-green-lt">да</span>', 'storefront')
                    : __('<span class="badge bg-secondary-lt">нет</span>', 'storefront'),
                (int) $r['is_pickup'] ? __('да', 'storefront') : __('нет', 'storefront'),
                self::deleteButton('warehouse.form.php', (int) $id, $catalog->getID())
            );
        }
        if (!count($rows)) {
            echo __('<tr><td colspan="7" class="text-muted">Складов ещё нет. ', 'storefront')
                . __('Без склада заказы негде выдавать.</td></tr>', 'storefront');
        }
        echo '</tbody></table>';

        if (self::canEdit()) {
            echo '<div class="card mt-3"><div class="card-body">';
            echo __('<div class="fw-bold mb-2">Добавить склад</div>', 'storefront');
            echo '<form method="post" action="' . self::esc(self::url('warehouse.form.php')) . '">';
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo Html::hidden('plugin_storefront_catalogs_id', ['value' => $catalog->getID()]);
            echo Html::hidden('entities_id', ['value' => (int) $catalog->fields['entities_id']]);
            echo '<div class="row g-2 align-items-end">';
            echo __('<div class="col-md-4"><label class="form-label">Название</label>', 'storefront')
                . '<input type="text" name="name" class="form-control form-control-sm" required></div>';
            echo __('<div class="col-md-4"><label class="form-label">Расположение</label>', 'storefront');
            \Location::dropdown(['name' => 'locations_id', 'width' => '100%',
                'entity' => (int) $catalog->fields['entities_id'], 'entity_sons' => true]);
            echo '</div>';
            echo __('<div class="col-md-3"><label class="form-label">Ответственный</label>', 'storefront');
            \User::dropdown(['name' => 'users_id_tech', 'right' => 'all', 'width' => '100%']);
            echo '</div>';
            echo '<div class="col-md-1"><button class="btn btn-sm btn-primary w-100" '
                . __('name="add" value="1">Добавить</button></div>', 'storefront');
            echo '</div></form></div></div>';
        }
        echo '</div>';
    }

    // ==================================================== позиции

    public static function products(Catalog $catalog): void
    {
        $rows = (new Product())->find(
            ['plugin_storefront_catalogs_id' => $catalog->getID()],
            ['ranking ASC', 'name ASC']
        );

        echo '<div class="m-3">';
        printf(
            '<div class="d-flex justify-content-between align-items-center '
            . 'flex-wrap gap-2 mb-2">'
            . __('<span class="text-muted">Позиций в витрине: <b>%d</b></span>', 'storefront')
            . '<span class="btn-group">'
            . '<a class="btn btn-sm btn-outline-secondary" href="%s?catalog=%d&amp;format=xlsx">'
            . '<i class="ti ti-file-spreadsheet me-1"></i>Excel</a>'
            . '<a class="btn btn-sm btn-outline-secondary" href="%s?catalog=%d&amp;format=csv">'
            . '<i class="ti ti-file-text me-1"></i>CSV</a>'
            . '<a class="btn btn-sm btn-outline-primary" href="%s?catalog=%d">'
            . __('<i class="ti ti-upload me-1"></i>Загрузить списком</a>', 'storefront')
            . '</span></div>',
            count($rows),
            self::esc(self::url('export.php')),
            $catalog->getID(),
            self::esc(self::url('export.php')),
            $catalog->getID(),
            self::esc(self::url('import.php')),
            $catalog->getID()
        );
        echo __('<div class="text-muted small mb-2">В выгрузку попадают цены, ', 'storefront')
            . __('остатки по каждому складу и расчёт «нужно докупить» — ', 'storefront')
            . __('до целевого запаса по позициям ниже порога.</div>', 'storefront');

        echo '<div class="table-responsive"><table class="table table-sm"><thead><tr>'
            . __('<th>Позиция</th><th>Артикул</th><th>Категория</th><th>Ед.</th>', 'storefront')
            . __('<th class="text-end">Цена</th><th>Оплата</th><th class="text-end">Остаток</th>', 'storefront')
            . __('<th>Оценка</th><th>Учёт</th><th>Активна</th><th></th></tr></thead><tbody>', 'storefront');
        foreach ($rows as $id => $r) {
            $p = new Product();
            $p->getFromDB((int) $id);
            $rate = Rating::summary((int) $id);
            printf(
                '<tr><td><a href="%s?id=%d">%s</a>%s</td><td class="font-monospace">%s</td>'
                . '<td>%s</td><td>%s</td><td class="text-end">%s</td><td>%s</td>'
                . '<td class="text-end fw-bold">%d</td><td>%s</td><td>%s</td><td>%s</td>'
                . '<td class="text-end">%s</td></tr>',
                self::esc(self::url('product.form.php')),
                (int) $id,
                self::esc($p->label()),
                $p->description() !== ''
                    ? '<div class="text-muted small">' . self::esc($p->description()) . '</div>'
                    : '',
                self::esc($p->ref()),
                self::esc($p->categoryName()),
                self::esc($r['unit']),
                Html::formatNumber($p->price()),
                $p->isChargeable()
                    ? __('<span class="badge bg-orange-lt">платно</span>', 'storefront')
                    : __('<span class="badge bg-green-lt">бесплатно</span>', 'storefront'),
                Stock::freeTotal((int) $id),
                $rate['count'] > 0
                    ? sprintf('<span class="text-warning">%s</span> <span class="text-muted '
                        . 'small">%s (%d)</span>', self::esc(Rating::stars($rate['avg'])),
                        number_format($rate['avg'], 1, ',', ''), $rate['count'])
                    : __('<span class="text-muted small">нет</span>', 'storefront'),
                $p->isQuantity() ? __('количественный', 'storefront') : __('экземплярный', 'storefront'),
                (int) $r['is_active']
                    ? __('<span class="badge bg-green-lt">да</span>', 'storefront')
                    : __('<span class="badge bg-secondary-lt">нет</span>', 'storefront'),
                self::deleteButton('product.form.php', (int) $id, $catalog->getID())
            );
        }
        if (!count($rows)) {
            echo __('<tr><td colspan="11" class="text-muted">Позиций ещё нет.</td></tr>', 'storefront');
        }
        echo '</tbody></table></div>';

        if (!self::canEdit()) {
            echo '</div>';
            return;
        }

        echo '<div class="row g-3 mt-2">';

        /* -------- завести новую номенклатуру и сразу положить в витрину */
        echo '<div class="col-12 col-xl-7"><div class="card"><div class="card-body">';
        echo __('<div class="fw-bold mb-1">Завести новую позицию</div>', 'storefront');
        echo __('<div class="text-muted small mb-2">Создаст расходный материал в справочнике ', 'storefront')
            . __('GLPI и сразу добавит его в витрину. Так заводят номенклатуру с нуля.</div>', 'storefront');
        echo '<form method="post" action="' . self::esc(self::url('product.form.php')) . '">';
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::hidden('plugin_storefront_catalogs_id', ['value' => $catalog->getID()]);
        echo Html::hidden('entities_id', ['value' => (int) $catalog->fields['entities_id']]);
        echo '<div class="row g-2">';
        echo __('<div class="col-md-5"><label class="form-label">Наименование</label>', 'storefront')
            . '<input type="text" name="new_name" class="form-control form-control-sm" required></div>';
        echo __('<div class="col-md-3"><label class="form-label">Артикул</label>', 'storefront')
            . '<input type="text" name="new_ref" class="form-control form-control-sm"></div>';
        echo __('<div class="col-md-2"><label class="form-label">Единица</label>', 'storefront')
            . __('<input type="text" name="unit" value="шт" class="form-control form-control-sm"></div>', 'storefront');
        echo __('<div class="col-md-2"><label class="form-label">Цена</label>', 'storefront')
            . '<input type="number" step="0.01" min="0" name="price" '
            . 'class="form-control form-control-sm"></div>';
        echo __('<div class="col-md-4"><label class="form-label">Категория</label>', 'storefront');
        \ConsumableItemType::dropdown(['name' => 'consumableitemtypes_id', 'width' => '100%']);
        echo '</div>';
        echo __('<div class="col-md-3"><label class="form-label">Порог оповещения</label>', 'storefront')
            . '<input type="number" min="0" name="alarm_threshold" '
            . 'class="form-control form-control-sm"></div>';
        echo __('<div class="col-md-3"><label class="form-label">Целевой запас</label>', 'storefront')
            . '<input type="number" min="0" name="stock_target" '
            . 'class="form-control form-control-sm"></div>';
        echo __('<div class="col-md-2"><label class="form-label">Оплата</label>', 'storefront')
            . '<select name="is_chargeable" class="form-select form-select-sm">'
            . __('<option value="0">бесплатно</option>', 'storefront')
            . __('<option value="1">платно</option></select></div>', 'storefront');
        echo __('<div class="col-md-3"><label class="form-label">Максимум в одном заказе</label>', 'storefront')
            . '<input type="number" min="0" name="max_qty" value="0" '
            . 'class="form-control form-control-sm" '
            . __('title="0 — без ограничения"></div>', 'storefront');
        echo __('<div class="col-md-10"><label class="form-label">Описание для сотрудника</label>', 'storefront')
            . '<input type="text" name="description" class="form-control form-control-sm" '
            . __('maxlength="255" placeholder="Пишет синим, толщина 0,7 мм"></div>', 'storefront');
        echo '<div class="col-md-2 d-flex align-items-end">'
            . '<button class="btn btn-sm btn-primary w-100" name="add_new" value="1">'
            . __('Завести</button></div>', 'storefront');
        echo '</div></form></div></div></div>';

        /* -------- взять уже существующую номенклатуру */
        echo '<div class="col-12 col-xl-5"><div class="card"><div class="card-body">';
        echo __('<div class="fw-bold mb-1">Добавить существующую номенклатуру</div>', 'storefront');
        echo __('<div class="text-muted small mb-2">Если позиция уже заведена в GLPI — ', 'storefront')
            . __('расходный материал, картридж или актив.</div>', 'storefront');
        echo '<form method="post" action="' . self::esc(self::url('product.form.php')) . '">';
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::hidden('plugin_storefront_catalogs_id', ['value' => $catalog->getID()]);
        echo Html::hidden('entities_id', ['value' => (int) $catalog->fields['entities_id']]);
        echo '<div class="row g-2">';
        echo __('<div class="col-12"><label class="form-label">Тип номенклатуры</label>', 'storefront')
            . '<select name="itemtype" class="form-select form-select-sm">';
        foreach (Product::itemtypes() as $t => $lb) {
            printf('<option value="%s">%s</option>', self::esc($t), self::esc($lb));
        }
        echo '</select></div>';
        echo __('<div class="col-12"><label class="form-label">Идентификатор объекта</label>', 'storefront')
            . '<input type="number" min="1" name="items_id" class="form-control form-control-sm" '
            . __('placeholder="номер записи в справочнике GLPI" required></div>', 'storefront');
        echo __('<div class="col-6"><label class="form-label">Единица</label>', 'storefront')
            . __('<input type="text" name="unit" value="шт" class="form-control form-control-sm"></div>', 'storefront');
        echo '<div class="col-6 d-flex align-items-end">'
            . '<button class="btn btn-sm btn-outline-primary w-100" name="add_existing" value="1">'
            . __('Добавить</button></div>', 'storefront');
        echo '</div></form></div></div></div>';

        echo '</div></div>';
    }

    // ==================================================== лимиты

    public static function limits(Catalog $catalog): void
    {
        global $DB;

        $rows = (new Limit())->find(
            ['plugin_storefront_catalogs_id' => $catalog->getID()],
            ['name ASC']
        );

        echo '<div class="m-3">';
        echo '<div class="accordion mb-3" id="sf-limit-help">'
            . '<div class="accordion-item"><h2 class="accordion-header">'
            . '<button class="accordion-button collapsed" type="button" '
            . 'data-bs-toggle="collapse" data-bs-target="#sf-limit-help-body">'
            . __('Как считается лимит</button></h2>', 'storefront')
            . '<div id="sf-limit-help-body" class="accordion-collapse collapse" '
            . 'data-bs-parent="#sf-limit-help"><div class="accordion-body">'
            . __('<p class="mb-2">Правило отвечает на три вопроса: <b>на кого</b> оно ', 'storefront')
            . __('распространяется, <b>на что</b> и <b>сколько</b> можно получить за период.</p>', 'storefront')
            . '<ul class="mb-2">'
            . __('<li><b>Считается только выданное.</b> Заказ, который ещё согласуют или ', 'storefront')
            . __('комплектуют, лимит не расходует. В счёт идут проводки списания со склада, ', 'storefront')
            . __('то есть фактически полученное сотрудником.</li>', 'storefront')
            . __('<li><b>Период считается от календарной границы</b>, а не «за последние ', 'storefront')
            . __('30 дней»: месяц — с первого числа, квартал — с начала квартала, ', 'storefront')
            . __('год — с первого января. Первого числа счётчик обнуляется.</li>', 'storefront')
            . __('<li><b>Лимит расходует получатель, а не заказчик.</b> Заказ на отдел ', 'storefront')
            . __('списывается с лимита отдела; личные правила «по должности» и ', 'storefront')
            . __('«на сотрудника» к нему не применяются. Один и тот же расход не может ', 'storefront')
            . __('попасть и в отдел, и в личный счёт.</li>', 'storefront')
            . __('<li><b>Считаются штуки, а не деньги.</b> Складываются количества, ', 'storefront')
            . __('единица измерения берётся из позиции витрины.</li>', 'storefront')
            . __('<li><b>Правила складываются.</b> Если на позицию действуют несколько ', 'storefront')
            . __('правил, проверяется каждое; сработает то, которое исчерпано раньше.</li>', 'storefront')
            . __('<li><b>Проверка идёт дважды:</b> в корзине — предупреждением, и ещё раз ', 'storefront')
            . __('при отправке заказа. Жёсткий лимит остановит заказ на сервере, ', 'storefront')
            . __('даже если кнопку разблокировали в браузере.</li>', 'storefront')
            . '</ul>'
            . __('<p><b>Норма</b> решает, чей запас расходуется. <i>У каждого своя</i> — ', 'storefront')
            . __('сотрудник, отдел и подразделение считаются порознь: заказ на отдел ', 'storefront')
            . __('личную норму не трогает. <i>Одна на всю область</i> — отдел (или ', 'storefront')
            . __('подразделение, или должность) расходует общий запас вместе со своими ', 'storefront')
            . __('людьми, и получить сверх него, оформив выдачу на себя, уже нельзя.</p>', 'storefront')
            . __('<p class="mb-0"><b>Мягкий лимит</b> предупреждает, но отправить заказ ', 'storefront')
            . __('разрешает — превышение увидит согласующий. <b>Жёсткий</b> запрещает ', 'storefront')
            . __('отправку до конца периода.</p>', 'storefront')
            . '</div></div></div></div>';

        echo __('<table class="table table-sm"><thead><tr><th>Правило</th><th>На кого</th>', 'storefront')
            . __('<th>Норма</th><th>На что</th><th>Период</th><th class="text-end">Максимум</th>', 'storefront')
            . __('<th>Жёсткий</th><th>Активно</th><th></th></tr></thead><tbody>', 'storefront');
        foreach ($rows as $id => $r) {
            $scope = Limit::scopeLabel((string) $r['scope'], (int) $r['scope_items_id']);
            $target = Limit::targetLabel((string) $r['target'], (int) $r['target_items_id']);
            $mode = Limit::modeLabel(Limit::mode((array) $r), (string) $r['scope']);
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td>'
                . '<td class="text-end">%d</td><td>%s</td><td>%s</td>'
                . '<td class="text-end">%s</td></tr>',
                self::esc($r['name']),
                self::esc($scope),
                self::esc($mode),
                self::esc($target),
                self::esc(Limit::periodLabel((string) $r['period'])),
                (int) $r['max_qty'],
                (int) $r['is_hard']
                    ? __('<span class="badge bg-red-lt">запрет</span>', 'storefront')
                    : __('<span class="badge bg-orange-lt">предупреждение</span>', 'storefront'),
                (int) $r['is_active'] ? __('да', 'storefront') : __('нет', 'storefront'),
                self::deleteButton('limit.form.php', (int) $id, $catalog->getID())
            );
        }
        if (!count($rows)) {
            echo __('<tr><td colspan="9" class="text-muted">Лимитов нет: ', 'storefront')
                . __('заказывать можно сколько угодно.</td></tr>', 'storefront');
        }
        echo '</tbody></table>';

        if (self::canEdit()) {
            echo '<div class="card mt-3"><div class="card-body">';
            echo __('<div class="fw-bold mb-2">Добавить лимит</div>', 'storefront');
            echo '<form method="post" action="' . self::esc(self::url('limit.form.php')) . '">';
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo Html::hidden('plugin_storefront_catalogs_id', ['value' => $catalog->getID()]);
            echo Html::hidden('entities_id', ['value' => (int) $catalog->fields['entities_id']]);
            echo '<div class="row g-2 align-items-end">';
            echo __('<div class="col-md-3"><label class="form-label">Название</label>', 'storefront')
                . '<input type="text" name="name" class="form-control form-control-sm" '
                . __('placeholder="Бумага — 4 пачки в месяц" required></div>', 'storefront');
            echo __('<div class="col-md-3"><label class="form-label">На кого</label>', 'storefront')
                . '<select name="scope_key" class="form-select form-select-sm">'
                . __('<option value="all:0">на всех сотрудников витрины</option>', 'storefront');
            // Одно поле вместо пары «вид области» + «значение»: так нельзя
            // выбрать должность, а в виде области оставить отдел.
            foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_groups',
                'ORDER' => 'name ASC', 'LIMIT' => 300]) as $g) {
                printf(__('<option value="group:%d">на отдел: %s</option>', 'storefront'),
                    (int) $g['id'], self::esc((string) $g['name']));
            }
            foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_usertitles',
                'ORDER' => 'name ASC', 'LIMIT' => 300]) as $t) {
                printf(__('<option value="title:%d">на должность: %s</option>', 'storefront'),
                    (int) $t['id'], self::esc((string) $t['name']));
            }
            foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_entities',
                'ORDER' => 'completename ASC', 'LIMIT' => 300]) as $e) {
                printf(__('<option value="entity:%d">на подразделение: %s</option>', 'storefront'),
                    (int) $e['id'], self::esc((string) $e['completename']));
            }
            echo '</select></div>';
            echo __('<div class="col-md-2"><label class="form-label">Норма</label>', 'storefront')
                . '<select name="scope_mode" class="form-select form-select-sm">'
                . __('<option value="each">у каждого своя</option>', 'storefront')
                . __('<option value="total">одна на всю область</option></select></div>', 'storefront');
            echo __('<div class="col-md-2"><label class="form-label">На что</label>', 'storefront')
                . '<select name="target" class="form-select form-select-sm">'
                . __('<option value="catalog">вся витрина</option>', 'storefront')
                . __('<option value="product" selected>позиция</option>', 'storefront')
                . __('<option value="category">категория</option></select></div>', 'storefront');
            echo __('<div class="col-md-3"><label class="form-label">Позиция</label>', 'storefront')
                . '<select name="target_items_id" class="form-select form-select-sm">'
                . __('<option value="0">— не выбрано —</option>', 'storefront');
            foreach ((new Product())->find(
                ['plugin_storefront_catalogs_id' => $catalog->getID()], ['name ASC']) as $pid => $pr) {
                $p = new Product();
                $p->getFromDB((int) $pid);
                printf('<option value="%d">%s</option>', (int) $pid, self::esc($p->label()));
            }
            echo '</select></div>';
            echo __('<div class="col-md-2"><label class="form-label">Период</label>', 'storefront')
                . '<select name="period" class="form-select form-select-sm">'
                . __('<option value="month">месяц</option><option value="quarter">квартал</option>', 'storefront')
                . __('<option value="year">год</option></select></div>', 'storefront');
            echo __('<div class="col-md-1"><label class="form-label">Макс.</label>', 'storefront')
                . '<input type="number" min="1" name="max_qty" value="1" '
                . 'class="form-control form-control-sm"></div>';
            echo '<div class="col-md-1"><button class="btn btn-sm btn-primary w-100" '
                . __('name="add" value="1">Добавить</button></div>', 'storefront');
            echo '</div>';
            echo '<div class="form-check mt-2"><input class="form-check-input" type="checkbox" '
                . 'name="is_hard" value="1" id="sf_hard">'
                . __('<label class="form-check-label" for="sf_hard">Жёсткий запрет: ', 'storefront')
                . __('заказ сверх лимита нельзя отправить</label></div>', 'storefront');
            echo '</form></div></div>';
        }
        echo '</div>';
    }

    // ==================================================== наборы

    public static function kits(Catalog $catalog): void
    {
        $rows = (new Kit())->find(
            ['plugin_storefront_catalogs_id' => $catalog->getID()],
            ['name ASC']
        );
        $products = (new Product())->find(
            ['plugin_storefront_catalogs_id' => $catalog->getID(), 'is_active' => 1],
            ['name ASC']
        );
        $canEdit = self::canEdit();
        $csrf = Session::getNewCSRFToken();

        echo '<div class="m-3">';
        echo __('<div class="text-muted mb-3">Набор — готовая корзина одной кнопкой. ', 'storefront')
            . __('Сотрудник нажимает «Взять набор» в магазине, и все позиции набора ', 'storefront')
            . __('ложатся в корзину в нужных количествах; дальше он может что-то убрать ', 'storefront')
            . __('или добавить. Типичный пример — комплект нового сотрудника.</div>', 'storefront');

        if (!count($rows)) {
            echo __('<div class="alert alert-info">Наборов пока нет. Создайте набор формой ', 'storefront')
                . __('внизу страницы, затем добавьте в него позиции.</div>', 'storefront');
        }

        foreach ($rows as $id => $r) {
            $kit = new Kit();
            $kit->getFromDB((int) $id);
            $lines = $kit->items();

            echo '<div class="card mb-3"><div class="card-body">';
            printf(
                '<div class="d-flex justify-content-between align-items-baseline '
                . 'flex-wrap gap-2 mb-2"><div><i class="%s me-1"></i>'
                . '<span class="fw-bold fs-4">%s</span> '
                . __('<span class="text-muted">позиций: %d, на сумму %s</span></div>', 'storefront')
                . '<div class="d-flex gap-2 align-items-center">%s%s</div></div>',
                self::esc($r['icon'] ?: 'ti ti-briefcase'),
                self::esc($r['name']),
                count($lines),
                Html::formatNumber($kit->price()),
                ((int) $r['is_active']
                    ? __('<span class="badge bg-green-lt">выдаётся</span>', 'storefront')
                    : __('<span class="badge bg-secondary-lt">скрыт</span>', 'storefront'))
                . ($kit->isOnce()
                    ? __(' <span class="badge bg-blue-lt">один раз на человека</span>', 'storefront') : ''),
                self::deleteButton('kit.form.php', (int) $id, $catalog->getID())
            );
            if (trim((string) $r['comment']) !== '') {
                printf(
                    '<div class="text-muted small mb-2">%s</div>',
                    self::esc((string) $r['comment'])
                );
            }

            echo __('<table class="table table-sm mb-2"><thead><tr><th>Позиция</th>', 'storefront')
                . __('<th>Ед.</th><th class="text-end">Количество</th>', 'storefront')
                . __('<th class="text-end">Сумма</th><th></th></tr></thead><tbody>', 'storefront');
            foreach ($lines as $lid => $l) {
                $p = new Product();
                if (!$p->getFromDB((int) $l['plugin_storefront_products_id'])) {
                    printf(
                        __('<tr><td colspan="4" class="text-danger">Позиция удалена из ', 'storefront')
                        . __('витрины, строку нужно убрать.</td><td class="text-end">%s</td></tr>', 'storefront'),
                        $canEdit ? self::kitLineDelete((int) $lid, $catalog->getID(), $csrf) : ''
                    );
                    continue;
                }
                printf(
                    '<tr><td>%s</td><td>%s</td><td class="text-end">%d</td>'
                    . '<td class="text-end">%s</td><td class="text-end">%s</td></tr>',
                    self::esc($p->label()),
                    self::esc((string) $p->fields['unit']),
                    (int) $l['qty'],
                    Html::formatNumber($p->price() * (int) $l['qty']),
                    $canEdit ? self::kitLineDelete((int) $lid, $catalog->getID(), $csrf) : ''
                );
            }
            if (!count($lines)) {
                echo __('<tr><td colspan="5" class="text-muted">Набор пуст: ', 'storefront')
                    . __('пока в нём нечего выдавать.</td></tr>', 'storefront');
            }
            echo '</tbody></table>';

            if ($kit->isOnce()) {
                self::kitOnceBlock($kit, $catalog, $canEdit, $csrf);
            }

            if ($canEdit) {
                echo '<form method="post" action="' . self::esc(self::url('kit.form.php')) . '">';
                echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
                echo Html::hidden('plugin_storefront_kits_id', ['value' => (int) $id]);
                echo Html::hidden('_back_catalog', ['value' => $catalog->getID()]);
                echo '<div class="row g-2 align-items-end">';
                echo __('<div class="col-md-6"><label class="form-label">Позиция витрины</label>', 'storefront')
                    . '<select name="plugin_storefront_products_id" '
                    . 'class="form-select form-select-sm">';
                foreach ($products as $pid => $pr) {
                    $p = new Product();
                    $p->getFromDB((int) $pid);
                    printf('<option value="%d">%s</option>', (int) $pid, self::esc($p->label()));
                }
                echo '</select></div>';
                echo __('<div class="col-md-2"><label class="form-label">Количество</label>', 'storefront')
                    . '<input type="number" min="1" name="qty" value="1" '
                    . 'class="form-control form-control-sm"></div>';
                echo '<div class="col-md-3"><button class="btn btn-sm btn-primary w-100" '
                    . __('name="add_item" value="1">Добавить в набор</button></div>', 'storefront');
                echo '</div></form>';
                if (!count($products)) {
                    echo __('<div class="alert alert-warning mt-2 mb-0">В витрине нет активных ', 'storefront')
                        . __('позиций — сначала заведите их на вкладке «Позиции».</div>', 'storefront');
                }
            }
            echo '</div></div>';
        }

        if ($canEdit) {
            echo '<div class="card"><div class="card-body">';
            echo __('<div class="fw-bold mb-1">Создать набор</div>', 'storefront');
            echo __('<div class="text-muted small mb-2">Сначала заводится сам набор, ', 'storefront')
                . __('потом в него добавляются позиции.</div>', 'storefront');
            echo '<form method="post" action="' . self::esc(self::url('kit.form.php')) . '">';
            echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
            echo Html::hidden('plugin_storefront_catalogs_id', ['value' => $catalog->getID()]);
            echo Html::hidden('entities_id', ['value' => (int) $catalog->fields['entities_id']]);
            echo Html::hidden('_back_catalog', ['value' => $catalog->getID()]);
            echo '<div class="row g-2 align-items-end">';
            echo __('<div class="col-md-4"><label class="form-label">Название набора</label>', 'storefront')
                . '<input type="text" name="name" class="form-control form-control-sm" '
                . __('placeholder="Комплект нового сотрудника" required></div>', 'storefront');
            echo __('<div class="col-md-4"><label class="form-label">Пояснение</label>', 'storefront')
                . '<input type="text" name="comment" class="form-control form-control-sm" '
                . __('placeholder="Что и кому выдаётся"></div>', 'storefront');
            echo __('<div class="col-md-2"><label class="form-label">Значок</label>', 'storefront')
                . '<input type="text" name="icon" value="ti ti-briefcase" '
                . 'class="form-control form-control-sm"></div>';
            echo __('<div class="col-md-2"><label class="form-label">Выдача</label>', 'storefront')
                . '<select name="is_once" class="form-select form-select-sm">'
                . __('<option value="0">сколько угодно раз</option>', 'storefront')
                . __('<option value="1">один раз на человека</option></select></div>', 'storefront');
            echo '<div class="col-md-2"><button class="btn btn-sm btn-primary w-100" '
                . __('name="add" value="1">Создать</button></div>', 'storefront');
            echo '</div></form></div></div>';
        }
        echo '</div>';
    }

    /**
     * Разовый набор: кому уже выдан и кому разрешён повторно.
     *
     * Администратору нужно видеть обе стороны сразу: и что человек набор
     * получал, и что ему выписано разрешение, — иначе разрешения выдаются
     * вслепую и по второму разу.
     */
    private static function kitOnceBlock(
        Kit $kit,
        Catalog $catalog,
        bool $canEdit,
        string $csrf
    ): void {
        $issued = $kit->recipients();
        $grants = KitGrant::forKit($kit->getID());

        echo '<div class="border rounded p-2 mb-2 bg-light-subtle">';
        echo __('<div class="fw-bold mb-1">Разовая выдача</div>', 'storefront');
        echo __('<div class="text-muted small mb-2">Набор пропадает у сотрудника после того, ', 'storefront')
            . __('как заказ с ним выдан. Вернуть его можно разрешением — оно гасится ', 'storefront')
            . __('при следующей выдаче.</div>', 'storefront');

        // Имена показываем только у существующих учётных записей: уволенный
        // сотрудник остаётся в истории заказов, но его имени в базе уже нет,
        // и список превращается в цепочку запятых.
        $names = [];
        $gone = 0;
        foreach (array_keys($issued) as $uid) {
            $u = new \User();
            if ($u->getFromDB($uid) && trim(getUserName($uid)) !== '') {
                $names[] = getUserName($uid);
            } else {
                $gone++;
            }
        }
        printf(__('<div class="mb-1">Уже получили: <b>%d</b>%s%s</div>', 'storefront'),
            count($issued),
            count($names) ? ' — ' . self::esc(implode(', ', $names)) : '',
            $gone > 0 ? sprintf(__(' <span class="text-muted">(и ещё %d удалённых ', 'storefront')
                . __('учётных записей)</span>', 'storefront'), $gone) : '');

        $open = array_filter($grants, static fn($g) => (int) $g['is_used'] === 0);
        printf(__('<div class="mb-2">Действующие разрешения: <b>%d</b>%s</div>', 'storefront'),
            count($open),
            count($open)
                ? ' — ' . self::esc(implode(', ', array_map(
                    static fn($g) => getUserName((int) $g['users_id']), $open)))
                : '');

        if ($canEdit) {
            echo '<form method="post" action="' . self::esc(self::url('kit.form.php'))
                . '" class="row g-2 align-items-end">';
            echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
            echo Html::hidden('plugin_storefront_kits_id', ['value' => $kit->getID()]);
            echo Html::hidden('_back_catalog', ['value' => $catalog->getID()]);
            echo __('<div class="col-md-4"><label class="form-label">Сотрудник</label>', 'storefront');
            \User::dropdown(['name' => 'users_id', 'right' => 'all', 'width' => '100%']);
            echo '</div>';
            echo __('<div class="col-md-5"><label class="form-label">Основание</label>', 'storefront')
                . '<input type="text" name="reason" class="form-control form-control-sm" '
                . __('placeholder="Перевод в другое подразделение, утрата"></div>', 'storefront');
            echo '<div class="col-md-3"><button class="btn btn-sm btn-outline-primary w-100" '
                . __('name="grant" value="1">Разрешить повторно</button></div>', 'storefront');
            echo '</form>';
        }
        echo '</div>';
    }

    /** Кнопка удаления строки набора. */
    private static function kitLineDelete(int $lines_id, int $catalogs_id, string $csrf): string
    {
        return sprintf(
            '<form method="post" action="%s" class="d-inline">%s%s%s'
            . '<button class="btn btn-sm btn-outline-danger" name="del_item" value="1" '
            . __('title="Убрать из набора">&times;</button></form>', 'storefront'),
            self::esc(self::url('kit.form.php')),
            Html::hidden('_glpi_csrf_token', ['value' => $csrf]),
            Html::hidden('kititems_id', ['value' => $lines_id]),
            Html::hidden('_back_catalog', ['value' => $catalogs_id])
        );
    }

    /** Кнопка удаления дочерней записи. */
    private static function deleteButton(string $page, int $id, int $catalogs_id): string
    {
        if (!self::canEdit()) {
            return '';
        }
        return sprintf(
            '<form method="post" action="%s" class="d-inline">%s%s%s'
            . '<button class="btn btn-sm btn-outline-danger" name="purge" value="1" '
            . __('title="Удалить">&times;</button></form>', 'storefront'),
            self::esc(self::url($page)),
            Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]),
            Html::hidden('id', ['value' => $id]),
            Html::hidden('_back_catalog', ['value' => $catalogs_id])
        );
    }
}
