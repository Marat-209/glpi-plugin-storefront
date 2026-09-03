<?php

namespace GlpiPlugin\Storefront;

use Glpi\DBAL\QuerySubQuery;
use Session;

/**
 * Ядро магазина: вычисление согласующего, проверка лимитов, движение остатков
 * и переходы состояний заказа.
 *
 * Своих статусов заявки и своих часов не заводит: заявка и согласование —
 * штатные объекты GLPI, состояние заказа живёт в документе заказа.
 */
final class Engine
{
    /** Защита от рекурсии: объекты, которые создаёт сам движок. */
    private static bool $inside = false;

    public static function isInside(): bool
    {
        return self::$inside;
    }

    private static function enter(): void
    {
        self::$inside = true;
    }

    private static function leave(): void
    {
        self::$inside = false;
    }

    public static function now(): string
    {
        return $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    }

    public static function nowTs(): int
    {
        return strtotime(self::now()) ?: time();
    }

    private static function msg(string $text, int $level = INFO): void
    {
        Session::addMessageAfterRedirect($text, false, $level);
    }

    // ==================================================== согласующий

    /**
     * Должности, дающие право согласования на этой витрине.
     *
     * Возвращает идентификаторы для условия выпадающего списка. Пустой массив
     * означает «не фильтровать»: либо порог не задан, либо должности в базе
     * не заполнены и фильтровать не по чему.
     *
     * @return int[]
     */
    public static function approverTitleIds(Catalog $catalog): array
    {
        global $DB;

        $min = (int) $catalog->fields['min_title_level'];
        if ($min <= 0) {
            return [];
        }
        // Если должностями никто не размечен, фильтр сделал бы список пустым.
        if (countElementsInTable('glpi_usertitles') === 0) {
            return [];
        }

        $ids = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_usertitles']) as $r) {
            $tid = (int) $r['id'];
            if (TitleLevel::forTitle($tid) >= $min) {
                $ids[] = $tid;
            }
        }
        return $ids;
    }

    /**
     * Условие для штатного выпадающего списка пользователей.
     *
     * Обычный select здесь не годится: в реальной базе сорок пять тысяч
     * сотрудников. Берём штатный ajax-поиск GLPI и сужаем его должностями,
     * дающими право согласования, — иначе в списке окажутся и те, кто
     * согласовывать не вправе, и те, у кого должность не заполнена.
     */
    public static function approverCondition(Catalog $catalog, int $exclude_users_id = 0): array
    {
        $cond = ['is_active' => 1, 'is_deleted' => 0];
        $titles = self::approverTitleIds($catalog);
        if (count($titles)) {
            $cond['usertitles_id'] = $titles;
        }
        if ($exclude_users_id > 0) {
            $cond[] = ['NOT' => ['id' => $exclude_users_id]];
        }
        return $cond;
    }

    /**
     * Кандидаты в согласующие списком. Используется для проверок и небольших
     * установок; в интерфейсе выбор идёт через ajax-поиск, а не через этот
     * массив — на сорока пяти тысячах пользователей его строить нельзя.
     *
     * @return array<int,string> идентификатор => подпись
     */
    public static function approverCandidates(
        Catalog $catalog,
        int $for_users_id = 0,
        int $limit = 500
    ): array {
        global $DB;

        $titles = self::approverTitleIds($catalog);
        $where = ['u.is_deleted' => 0, 'u.is_active' => 1];
        if ($for_users_id > 0) {
            $where[] = ['NOT' => ['u.id' => $for_users_id]];
        }
        if (count($titles)) {
            $where['u.usertitles_id'] = $titles;
        }

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['u.id', 'u.name', 'u.realname', 'u.firstname', 't.name AS title'],
            'FROM'   => 'glpi_users AS u',
            'LEFT JOIN' => [
                'glpi_usertitles AS t' => ['ON' => ['u' => 'usertitles_id', 't' => 'id']],
            ],
            'WHERE'  => $where,
            'ORDER'  => ['u.realname ASC', 'u.firstname ASC'],
            'LIMIT'  => $limit,
        ]) as $r) {
            $label = trim(($r['realname'] ?? '') . ' ' . ($r['firstname'] ?? ''));
            if ($label === '') {
                $label = (string) $r['name'];
            }
            if (!empty($r['title'])) {
                $label .= ' — ' . $r['title'];
            }
            $out[(int) $r['id']] = $label;
        }
        return $out;
    }

    /**
     * Кого предложить по умолчанию: руководителя сотрудника, если он подходит.
     * Это подсказка для выпадающего списка, а не окончательное решение —
     * выбирает сотрудник.
     */
    public static function suggestApprover(Catalog $catalog, int $users_id): int
    {
        $sup = self::supervisorOf($users_id);
        if ($sup <= 0) {
            return 0;
        }
        $min = (int) $catalog->fields['min_title_level'];
        if ($min > 0 && TitleLevel::forUser($sup) < $min) {
            return 0;
        }
        return $sup;
    }

    /**
     * Кто согласует заказ этого сотрудника, когда согласующий не выбран вручную.
     *
     * Цепочка: руководитель из карточки → выше по цепочке руководителей, пока
     * должность не дотянет до порога витрины → группа согласующих витрины.
     * Последний шаг обязателен: в реальной базе руководитель заполнен у одной
     * седьмой сотрудников, без фолбэка заказ завис бы без согласующего.
     *
     * @return array{users_id:int, groups_id:int, source:string, level:int}
     */
    public static function resolveApprover(Catalog $catalog, int $users_id, int $chosen = 0): array
    {
        $none = [
            'users_id'  => 0,
            'groups_id' => (int) $catalog->fields['groups_id_approver'],
            'source'    => 'group',
            'level'     => 0,
        ];

        $mode = (string) $catalog->fields['approval_mode'];
        if ($mode === 'none') {
            return ['users_id' => 0, 'groups_id' => 0, 'source' => 'none', 'level' => 0];
        }

        // Выбор сотрудника имеет приоритет над любой автоматикой.
        if ($chosen > 0) {
            return ['users_id' => $chosen, 'groups_id' => 0, 'source' => 'chosen',
                'level' => TitleLevel::forUser($chosen)];
        }
        if ($mode === Catalog::APPROVE_MANUAL) {
            // Согласующего должен был выбрать сотрудник. Если не выбрал —
            // не роняем заказ, а уходим в группу витрины.
            return $none;
        }
        if ($mode === 'group') {
            return $none;
        }

        $min = (int) $catalog->fields['min_title_level'];

        // Идём вверх по руководителям. Глубину ограничиваем: в данных
        // встречаются циклы, когда руководитель указан сам на себя.
        $seen = [$users_id => true];
        $current = $users_id;
        for ($depth = 0; $depth < 8; $depth++) {
            $sup = self::supervisorOf($current);
            if ($sup <= 0 || isset($seen[$sup])) {
                break;
            }
            $seen[$sup] = true;
            $level = TitleLevel::forUser($sup);
            if ($level >= $min) {
                return [
                    'users_id'  => $sup,
                    'groups_id' => 0,
                    'source'    => $depth === 0 ? 'supervisor' : 'chain',
                    'level'     => $level,
                ];
            }
            $current = $sup;
        }

        return $none;
    }

    /** Руководитель сотрудника из карточки пользователя. */
    public static function supervisorOf(int $users_id): int
    {
        global $DB;
        foreach ($DB->request([
            'SELECT' => ['users_id_supervisor'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['id' => $users_id, 'is_deleted' => 0],
        ]) as $r) {
            return (int) $r['users_id_supervisor'];
        }
        return 0;
    }

    // ==================================================== лимиты

    /**
     * Проверить строки заказа на лимиты.
     *
     * @param array $lines [['products_id'=>int,'qty'=>int,'price'=>float], ...]
     * @return array список нарушений: [['products_id'=>..,'limit'=>Limit,
     *               'used'=>int,'max'=>int,'requested'=>int,'is_hard'=>bool], ...]
     */
    /**
     * Сверить резерв складов с тем, что реально держат заказы.
     *
     * Резерв — вычисляемая величина: сумма занятого по строкам незакрытых
     * заказов. Если склад показывает больше, значит где-то остался «мёртвый»
     * резерв: заказ удалили в обход плагина, процесс оборвался на выдаче,
     * либо данные пришли из версии, где резерв считался по-другому.
     * Такой остаток не даёт заказать товар, который на самом деле есть.
     *
     * @param bool $fix править или только показать расхождения
     * @return array<int,array{stocks_id:int, products_id:int, warehouses_id:int,
     *                         stock:int, held:int}>
     */
    public static function reconcileReserves(bool $fix = false): array
    {
        global $DB;

        $held = [];
        foreach ($DB->request([
            'SELECT' => [
                'oi.plugin_storefront_products_id AS pid',
                'o.plugin_storefront_warehouses_id AS wid',
                'oi.qty_reserved AS qty',
            ],
            'FROM'   => OrderItem::getTable() . ' AS oi',
            'INNER JOIN' => [
                Order::getTable() . ' AS o' => [
                    'ON' => ['oi' => 'plugin_storefront_orders_id', 'o' => 'id'],
                ],
            ],
            'WHERE'  => [['oi.qty_reserved' => ['>', 0]]],
        ]) as $r) {
            $key = (int) $r['pid'] . ':' . (int) $r['wid'];
            $held[$key] = ($held[$key] ?? 0) + (int) $r['qty'];
        }

        $diffs = [];
        foreach ($DB->request([
            'FROM'  => Stock::getTable(),
            'WHERE' => [['qty_reserved' => ['>', 0]]],
        ]) as $row) {
            $pid = (int) $row['plugin_storefront_products_id'];
            $wid = (int) $row['plugin_storefront_warehouses_id'];
            $want = $held[$pid . ':' . $wid] ?? 0;
            if ($want === (int) $row['qty_reserved']) {
                continue;
            }
            $diffs[] = [
                'stocks_id'     => (int) $row['id'],
                'products_id'   => $pid,
                'warehouses_id' => $wid,
                'stock'         => (int) $row['qty_reserved'],
                'held'          => $want,
            ];
            if (!$fix) {
                continue;
            }
            self::enter();
            try {
                $stock = new Stock();
                if ($stock->getFromDB((int) $row['id'])) {
                    $stock->update([
                        'id'           => $stock->getID(),
                        'qty_reserved' => $want,
                        'date_mod'     => self::now(),
                    ]);
                }
            } finally {
                self::leave();
            }
        }
        return $diffs;
    }

    public static function checkLimits(
        Catalog $catalog,
        int $users_id,
        array $lines,
        array $recipient = []
    ): array {
        $rules = (new Limit())->find([
            'plugin_storefront_catalogs_id' => $catalog->getID(),
            'is_active'                     => 1,
        ]);
        if (!count($rules)) {
            return [];
        }

        // По умолчанию заказ ложится на самого заказчика.
        if (!isset($recipient['scope'])) {
            $recipient = ['scope' => 'user', 'items_id' => $users_id];
        }
        if ((int) ($recipient['items_id'] ?? 0) <= 0) {
            $recipient = ['scope' => 'user', 'items_id' => $users_id];
        }
        $violations = [];

        foreach ($rules as $rid => $rule) {
            if (!self::limitAppliesTo($rule, $recipient)) {
                continue;
            }
            // Какие строки заказа попадают под правило.
            $affected = [];
            foreach ($lines as $line) {
                if (self::limitCovers($rule, (int) $line['products_id'])) {
                    $affected[] = $line;
                }
            }
            if (!count($affected)) {
                continue;
            }

            $requested = 0;
            foreach ($affected as $line) {
                $requested += (int) $line['qty'];
            }
            $used = self::usedInPeriod($rule, $users_id, $recipient);
            $max = (int) $rule['max_qty'];
            if ($max > 0 && $used + $requested > $max) {
                $violations[] = [
                    'limit'     => $rule,
                    'limits_id' => (int) $rid,
                    'pool'      => Limit::poolLabel($rule),
                    'mode'      => Limit::mode($rule),
                    'used'      => $used,
                    'max'       => $max,
                    'requested' => $requested,
                    'remaining' => max(0, $max - $used),
                    'is_hard'   => (bool) $rule['is_hard'],
                    'scope'     => (string) $recipient['scope'],
                ];
            }
        }
        return $violations;
    }

    /** Применимо ли правило к этому сотруднику. */
    /**
     * Применимо ли правило к тому, на кого ложится заказ.
     *
     * Разбор идёт не по заказчику, а по получателю: заказ на отдел расходует
     * лимит отдела, а личные правила по должности или по человеку к нему
     * отношения не имеют — иначе один заказ списался бы дважды.
     */
    private static function limitAppliesTo(array $rule, array $recipient): bool
    {
        global $DB;

        $scope = (string) ($recipient['scope'] ?? 'user');
        $id    = (int) ($recipient['items_id'] ?? 0);
        $personal = ($scope === 'user');

        switch ((string) $rule['scope']) {
            case 'all':
                return true;
            case 'user':
                return $personal && (int) $rule['scope_items_id'] === $id;
            case 'title':
                if (!$personal) {
                    return false;
                }
                $tid = 0;
                foreach ($DB->request([
                    'SELECT' => ['usertitles_id'], 'FROM' => 'glpi_users',
                    'WHERE'  => ['id' => $id],
                ]) as $r) {
                    $tid = (int) $r['usertitles_id'];
                }
                return $tid === (int) $rule['scope_items_id'];
            case 'group':
                if ($scope === 'group') {
                    return $id === (int) $rule['scope_items_id'];
                }
                return $personal && countElementsInTable('glpi_groups_users', [
                    'users_id'  => $id,
                    'groups_id' => (int) $rule['scope_items_id'],
                ]) > 0;
            case 'entity':
                if ($scope === 'entity') {
                    return $id === (int) $rule['scope_items_id'];
                }
                return $personal && countElementsInTable('glpi_profiles_users', [
                    'users_id'    => $id,
                    'entities_id' => (int) $rule['scope_items_id'],
                ]) > 0;
        }
        return false;
    }

    /** Покрывает ли правило конкретную позицию. */
    private static function limitCovers(array $rule, int $products_id): bool
    {
        switch ((string) $rule['target']) {
            case 'catalog':
                return true;
            case 'product':
                return (int) $rule['target_items_id'] === $products_id;
            case 'category':
                $p = new Product();
                if (!$p->getFromDB($products_id)) {
                    return false;
                }
                return $p->categoryId() === (int) $rule['target_items_id'];
        }
        return false;
    }

    /**
     * Из какого запаса правило с общей нормой берёт расход.
     *
     * Отдел расходует норму и своими заказами, и личными заказами своих
     * сотрудников: для человека это одна и та же выдача, просто оформленная
     * по-разному. Подразделение считаем по организации заказа — так в норму
     * попадает всё, что подразделение получило, кем бы ни был получатель.
     *
     * @return array условия выборки движений
     */
    private static function poolCriteria(array $rule): array
    {
        global $DB;

        $id = (int) ($rule['scope_items_id'] ?? 0);
        switch ((string) ($rule['scope'] ?? 'all')) {
            case 'group':
                // Состав отдела берём подзапросом: в крупной организации список
                // участников — это тысячи идентификаторов в каждом запросе.
                $members = new QuerySubQuery([
                    'SELECT' => ['users_id'],
                    'FROM'   => 'glpi_groups_users',
                    'WHERE'  => ['groups_id' => $id],
                ]);
                return [['OR' => [
                    ['m.groups_id_recipient' => $id],
                    ['m.users_id_recipient'    => $members,
                     'm.groups_id_recipient'   => 0,
                     'm.entities_id_recipient' => 0],
                ]]];
            case 'entity':
                // Подразделение отвечает и за свои под-подразделения: норма
                // головного включает то, что получили дочерние.
                $tree = getSonsOf('glpi_entities', $id);
                return [['OR' => [
                    ['m.entities_id_recipient' => $tree],
                    ['m.entities_id' => $tree],
                ]]];
            case 'title':
                $holders = new QuerySubQuery([
                    'SELECT' => ['id'],
                    'FROM'   => 'glpi_users',
                    'WHERE'  => ['usertitles_id' => $id],
                ]);
                return [['m.users_id_recipient' => $holders]];
        }
        // Норма на витрину целиком: считаем любую выдачу покрытых позиций.
        return [];
    }

    /** Сколько уже выдано сотруднику по этому правилу за текущий период. */
    public static function usedInPeriod(array $rule, int $users_id, array $recipient = []): int
    {
        global $DB;
        $since = Limit::periodStart((string) $rule['period']);

        $scope = (string) ($recipient['scope'] ?? 'user');
        $id    = (int) ($recipient['items_id'] ?? 0) ?: $users_id;

        $where = [
            'm.type'  => Movement::OUT,
            ['m.date' => ['>=', $since]],
        ];
        if (Limit::mode($rule) === Limit::MODE_TOTAL) {
            // Общая норма: область расходует её целиком. Заказ на отдел и
            // личный заказ его сотрудника берут из одного и того же запаса —
            // иначе норму отдела можно обойти, оформив выдачу на себя.
            $where = array_merge($where, self::poolCriteria($rule));
        } elseif ($scope === 'group') {
            // Норма у каждого своя: расход разнесён по получателю. Личное
            // потребление считаем только по заказам без отдела и
            // подразделения — заказ на отдел уже посчитан там и второй раз,
            // на человека, ложиться не должен.
            $where['m.groups_id_recipient'] = $id;
        } elseif ($scope === 'entity') {
            $where['m.entities_id_recipient'] = $id;
        } else {
            $where['m.users_id_recipient'] = $id;
            $where['m.groups_id_recipient'] = 0;
            $where['m.entities_id_recipient'] = 0;
        }

        // Ограничение по цели правила.
        if ((string) $rule['target'] === 'product') {
            $where['m.plugin_storefront_products_id'] = (int) $rule['target_items_id'];
        }

        $sum = 0;
        $req = [
            'SELECT' => ['m.qty', 'm.plugin_storefront_products_id'],
            'FROM'   => Movement::getTable() . ' AS m',
            'WHERE'  => $where,
        ];
        foreach ($DB->request($req) as $r) {
            if ((string) $rule['target'] === 'category') {
                $p = new Product();
                if (!$p->getFromDB((int) $r['plugin_storefront_products_id'])
                    || $p->categoryId() !== (int) $rule['target_items_id']) {
                    continue;
                }
            }
            $sum += abs((int) $r['qty']);
        }
        return $sum;
    }

    // ==================================================== движение остатков

    /**
     * Записать движение и поправить агрегат остатка одной операцией.
     * Единственная точка, меняющая остаток: иначе агрегат и журнал разойдутся.
     */
    /**
     * Можно ли двигать этот склад прямо сейчас.
     *
     * Проверка стоит в единой точке всех движений, а не в страницах: иначе
     * каждая новая точка входа — импорт, задание, будущий экран — заводила бы
     * свою дыру. Право даёт возможность двигать склад вообще, организация
     * склада — двигать именно этот. Задания планировщика работают без сессии,
     * поэтому для них проверка не применяется.
     */
    private static function mayMove(int $warehouses_id, array $extra = []): bool
    {
        if (\Session::isCron()) {
            return true;
        }
        // Движение по заказу делает не человек, а сам процесс: резерв встаёт,
        // когда согласующий нажал «Согласовать», а снимается при отмене
        // заказчиком. Права на склад ни у того, ни у другого нет и быть не
        // должно — авторизация для таких движений уже выполнена на уровне
        // самого заказа, где проверяются и право, и подразделение.
        if ((int) ($extra['orders_id'] ?? 0) > 0) {
            return true;
        }
        if (!\Session::haveRight(Stock::$rightname, UPDATE)) {
            \Session::addMessageAfterRedirect(
                __('Недостаточно прав для движений по складу.', 'storefront'),
                false,
                ERROR
            );
            return false;
        }
        $wh = new Warehouse();
        if (!$wh->getFromDB($warehouses_id)) {
            \Session::addMessageAfterRedirect(__('Склад не найден.', 'storefront'), false, ERROR);
            return false;
        }
        if (!\Session::haveAccessToEntity(
            (int) $wh->fields['entities_id'],
            (int) $wh->fields['is_recursive'] === 1
        )) {
            \Session::addMessageAfterRedirect(
                __('Этот склад относится к другому подразделению — движения по нему ', 'storefront')
                . __('недоступны.', 'storefront'),
                false,
                ERROR
            );
            return false;
        }
        return true;
    }

    private static function move(
        int $products_id,
        int $warehouses_id,
        string $type,
        int $qty,
        array $extra = []
    ): bool {
        if (!self::mayMove($warehouses_id, $extra)) {
            return false;
        }

        $stock = Stock::ensure($products_id, $warehouses_id, (int) ($extra['entities_id'] ?? 0));
        $before = (int) $stock->fields['qty_on_hand'];
        $reserved = (int) $stock->fields['qty_reserved'];
        $after = $before;
        $newReserved = $reserved;

        switch ($type) {
            case Movement::IN:
                $after = $before + abs($qty);
                break;
            case Movement::OUT:
                $after = $before - abs($qty);
                // Резерв снимаем ровно на столько, сколько держал сам заказ.
                // Списывать «в объёме выданного» нельзя: ручное списание со
                // склада съело бы резерв чужих заказов.
                $newReserved = max(0, $reserved - (int) ($extra['release_reserved'] ?? 0));
                break;
            case Movement::ADJUST:
                // qty здесь — фактическое количество, а не дельта.
                $after = max(0, $qty);
                break;
            case Movement::RESERVE:
                $newReserved = $reserved + abs($qty);
                break;
            case Movement::UNRESERVE:
                $newReserved = max(0, $reserved - abs($qty));
                break;
            case Movement::WRITEOFF:
            case Movement::MOVE_OUT:
                // Уходит со склада, но это не выдача сотруднику и резерва
                // чужих заказов не касается.
                $after = $before - abs($qty);
                break;
            case Movement::MOVE_IN:
                $after = $before + abs($qty);
                break;
            default:
                return false;
        }

        if ($after < 0) {
            self::msg(__('Списание больше остатка: на складе ', 'storefront') . $before
                . __(', списывается ', 'storefront') . abs($qty) . '.', ERROR);
            return false;
        }

        self::enter();
        try {
            $stock->update([
                'id'           => $stock->getID(),
                'qty_on_hand'  => $after,
                'qty_reserved' => $newReserved,
                'date_mod'     => self::now(),
            ] + ($type === Movement::ADJUST ? ['date_counted' => self::now()] : []));

            (new Movement())->add([
                'plugin_storefront_products_id'   => $products_id,
                'plugin_storefront_warehouses_id' => $warehouses_id,
                'plugin_storefront_orders_id'     => (int) ($extra['orders_id'] ?? 0),
                'entities_id'                     => (int) ($extra['entities_id'] ?? 0),
                'type'                            => $type,
                'qty'                             => $type === Movement::ADJUST
                    ? ($after - $before) : abs($qty),
                'qty_before'                      => $before,
                'qty_after'                       => $after,
                'users_id'                        => (int) (Session::getLoginUserID() ?: 0),
                'users_id_recipient'              => (int) ($extra['users_id_recipient'] ?? 0),
                'groups_id_recipient'             => (int) ($extra['groups_id_recipient'] ?? 0),
                'entities_id_recipient'           => (int) ($extra['entities_id_recipient'] ?? 0),
                'suppliers_id'                    => (int) ($extra['suppliers_id'] ?? 0),
                'document_no'                     => (string) ($extra['document_no'] ?? ''),
                'unit_price'                      => (float) ($extra['unit_price'] ?? 0),
                'comment'                         => (string) ($extra['comment'] ?? ''),
                'date'                            => self::now(),
            ]);
        } finally {
            self::leave();
        }
        return true;
    }

    /** Приход на склад. */
    public static function receive(
        int $products_id,
        int $warehouses_id,
        int $qty,
        array $extra = []
    ): bool {
        if ($qty <= 0) {
            self::msg(__('Количество прихода должно быть больше нуля.', 'storefront'), ERROR);
            return false;
        }
        return self::move($products_id, $warehouses_id, Movement::IN, $qty, $extra);
    }

    /** Корректировка по инвентаризации: qty_fact — фактическое количество. */
    public static function adjust(
        int $products_id,
        int $warehouses_id,
        int $qty_fact,
        array $extra = []
    ): bool {
        if ($qty_fact < 0) {
            return false;
        }
        return self::move($products_id, $warehouses_id, Movement::ADJUST, $qty_fact, $extra);
    }

    /**
     * Списать со склада мимо заказа: порча, утрата, истёк срок.
     *
     * Отдельный тип движения, а не выдача: иначе списанное попадёт в отчёт
     * по выдачам сотрудникам и в расчёт лимитов.
     */
    public static function writeOff(
        int $products_id,
        int $warehouses_id,
        int $qty,
        array $extra = []
    ): bool {
        if ($qty <= 0) {
            self::msg(__('Количество списания должно быть больше нуля.', 'storefront'), ERROR);
            return false;
        }
        if (trim((string) ($extra['comment'] ?? '')) === '') {
            self::msg(__('Списание без основания не проводится: укажите причину.', 'storefront'), ERROR);
            return false;
        }
        return self::move($products_id, $warehouses_id, Movement::WRITEOFF, $qty, $extra);
    }

    /**
     * Перемещение между складами одной витрины.
     *
     * Двумя движениями, а не переносом числа: у каждого склада должна остаться
     * своя история — откуда взялось и куда ушло.
     */
    public static function transfer(
        int $products_id,
        int $from_warehouses_id,
        int $to_warehouses_id,
        int $qty,
        array $extra = []
    ): bool {
        if ($qty <= 0) {
            self::msg(__('Количество перемещения должно быть больше нуля.', 'storefront'), ERROR);
            return false;
        }
        if ($from_warehouses_id === $to_warehouses_id) {
            self::msg(__('Склад отправления и склад получения совпадают.', 'storefront'), ERROR);
            return false;
        }
        $from = Stock::ensure($products_id, $from_warehouses_id);
        if ($from->free() < $qty) {
            self::msg(sprintf(
                __('На складе отправления свободно только %d — переместить %d нельзя.', 'storefront'),
                $from->free(),
                $qty
            ), ERROR);
            return false;
        }

        $wh = new Warehouse();
        $fromName = $wh->getFromDB($from_warehouses_id) ? (string) $wh->fields['name'] : '?';
        $toName = $wh->getFromDB($to_warehouses_id) ? (string) $wh->fields['name'] : '?';
        $note = trim((string) ($extra['comment'] ?? ''));

        // array_merge, а не «+»: при сложении массивов побеждает левый,
        // и собственный comment из $extra затёр бы пояснение о перемещении.
        $out = self::move($products_id, $from_warehouses_id, Movement::MOVE_OUT, $qty, array_merge($extra, [
            'comment' => __('Передано на склад «', 'storefront') . $toName . '»' . ($note !== '' ? '. ' . $note : ''),
        ]));
        if (!$out) {
            return false;
        }
        $in = self::move($products_id, $to_warehouses_id, Movement::MOVE_IN, $qty, array_merge($extra, [
            'comment' => __('Принято со склада «', 'storefront') . $fromName . '»' . ($note !== '' ? '. ' . $note : ''),
        ]));
        if (!$in) {
            // Возвращаем на место: половина перемещения хуже, чем его отсутствие.
            self::move($products_id, $from_warehouses_id, Movement::MOVE_IN, $qty, [
                'comment' => __('Возврат: перемещение на «', 'storefront') . $toName . __('» не прошло', 'storefront'),
            ]);
            return false;
        }
        return true;
    }

    public static function reserve(int $products_id, int $warehouses_id, int $qty, array $extra = []): bool
    {
        return $qty > 0 && self::move($products_id, $warehouses_id, Movement::RESERVE, $qty, $extra);
    }

    public static function unreserve(int $products_id, int $warehouses_id, int $qty, array $extra = []): bool
    {
        return $qty > 0 && self::move($products_id, $warehouses_id, Movement::UNRESERVE, $qty, $extra);
    }

    /** Выдача: списание строго по утверждённому количеству. */
    public static function issueLine(
        OrderItem $line,
        Order $order,
        int $qty,
        int $release_reserved = 0
    ): bool {
        // Расход разносим ровно по одному признаку — тому, на кого заказ
        // оформлен. Иначе выдача на отдел попала бы и в отчёт по отделу,
        // и в личный расход человека, который её получал.
        $scope = $order->limitScope();
        $extra = [
            'orders_id'   => $order->getID(),
            'entities_id' => (int) $order->fields['entities_id'],
            // Материально ответственный — тот, кто расписался в накладной.
            'users_id_recipient'    => $order->recipientId(),
            'groups_id_recipient'   => 0,
            'entities_id_recipient' => 0,
            'unit_price'            => (float) $line->fields['price_snapshot'],
            'comment'               => __('Выдача по заказу №', 'storefront') . $order->getID(),
            'release_reserved'      => max(0, $release_reserved),
        ];
        if ($scope['scope'] === 'group') {
            $extra['groups_id_recipient'] = $scope['items_id'];
        } elseif ($scope['scope'] === 'entity') {
            $extra['entities_id_recipient'] = $scope['items_id'];
        }

        return self::move(
            (int) $line->fields['plugin_storefront_products_id'],
            (int) $order->fields['plugin_storefront_warehouses_id'],
            Movement::OUT,
            $qty,
            $extra
        );
    }
}
