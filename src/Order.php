<?php

namespace GlpiPlugin\Storefront;

use CommonDBTM;
use CommonITILActor;
use CommonITILValidation;
use Session;
use Ticket;
use TicketValidation;

/**
 * Документ заказа.
 *
 * Семь статусов из технического задания — это состояния заказа, а не статусы
 * заявки: свои статусы заявки в GLPI ломают штатную отчётность и настройку
 * жизненного цикла в профилях. Заявка при этом обычная, и её видно во всех
 * штатных списках и дашбордах.
 */
class Order extends CommonDBTM
{
    public static $rightname = 'plugin_storefront_order';
    public $dohistory = true;

    public const DRAFT     = 'draft';      // черновик, корзина оформлена но не отправлена
    public const APPROVAL  = 'approval';   // На согласовании
    public const REJECTED  = 'rejected';   // Отклонена руководителем
    public const QUEUE     = 'queue';      // В работе отдела-исполнителя
    public const APPROVED  = 'approved';   // Утверждена к выдаче
    public const READY     = 'ready';      // Готово к получению
    public const ISSUED    = 'issued';     // Выдано
    public const CANCELLED = 'cancelled';  // Отменена

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? __('Заказы', 'storefront') : __('Заказ', 'storefront');
    }

    public static function getIcon()
    {
        return 'ti ti-clipboard-list';
    }

    /** Формулировки статусов ровно как в техническом задании. */
    public static function states(): array
    {
        return [
            self::DRAFT     => __('Черновик', 'storefront'),
            self::APPROVAL  => __('На согласовании', 'storefront'),
            self::REJECTED  => __('Отклонена руководителем', 'storefront'),
            self::QUEUE     => __('В работе', 'storefront'),
            self::APPROVED  => __('Утверждена к выдаче', 'storefront'),
            self::READY     => __('Готово к получению', 'storefront'),
            self::ISSUED    => __('Выдано', 'storefront'),
            self::CANCELLED => __('Отменена', 'storefront'),
        ];
    }

    public static function stateLabel(string $state): string
    {
        return self::states()[$state] ?? $state;
    }

    /** Цвет метки состояния для интерфейса. */
    public static function stateTone(string $state): string
    {
        $map = [
            self::DRAFT     => 'secondary',
            self::APPROVAL  => 'info',
            self::REJECTED  => 'danger',
            self::QUEUE     => 'info',
            self::APPROVED  => 'primary',
            self::READY     => 'primary',
            self::ISSUED    => 'success',
            self::CANCELLED => 'secondary',
        ];
        return $map[$state] ?? 'secondary';
    }

    /** Состояния, в которых заказ ещё можно отменить сотруднику. */
    public static function cancellableByRequester(): array
    {
        return [self::DRAFT, self::APPROVAL];
    }

    /**
     * Вправе ли текущий пользователь вести этот заказ.
     *
     * Проверка стоит в самих действиях, а не только на страницах: иначе
     * достаточно знать номер заказа, чтобы вмешаться в процесс чужого
     * подразделения — снять резерв, закрыть заявку, подтвердить количества.
     * Заказчику отдельно оставлено право отказаться от своего заказа, пока он
     * не ушёл на склад: права на очередь у сотрудника нет и быть не должно.
     */
    private function mayManage(string $action = ''): bool
    {
        if (Session::isCron()) {
            return true;
        }
        $me = (int) (Session::getLoginUserID() ?: 0);
        if ($action === 'cancel'
            && $me > 0
            && $me === (int) $this->fields['users_id_requester']
            && in_array($this->state(), self::cancellableByRequester(), true)) {
            return true;
        }
        if (!Session::haveRight(self::$rightname, UPDATE)) {
            Session::addMessageAfterRedirect(
                __('Недостаточно прав для работы с заказами.', 'storefront'), false, ERROR
            );
            return false;
        }
        if (!Session::haveAccessToEntity((int) $this->fields['entities_id'], true)) {
            Session::addMessageAfterRedirect(
                __('Заказ относится к другому подразделению — работать с ним нельзя.', 'storefront'),
                false,
                ERROR
            );
            return false;
        }
        return true;
    }

    public function state(): string
    {
        return (string) $this->fields['state'];
    }

    public function isOpen(): bool
    {
        return !in_array($this->state(), [self::ISSUED, self::CANCELLED, self::REJECTED], true);
    }

    /** Строки заказа. */
    public function lines(): array
    {
        return (new OrderItem())->find(
            ['plugin_storefront_orders_id' => $this->getID()],
            ['ranking ASC', 'id ASC']
        );
    }

    public function getCatalog(): ?Catalog
    {
        $c = new Catalog();
        return $c->getFromDB((int) $this->fields['plugin_storefront_catalogs_id']) ? $c : null;
    }

    public function getWarehouse(): ?Warehouse
    {
        $w = new Warehouse();
        return $w->getFromDB((int) $this->fields['plugin_storefront_warehouses_id']) ? $w : null;
    }

    /** Связанная заявка GLPI. */
    public function getTicket(): ?Ticket
    {
        $id = (int) $this->fields['items_id'];
        if ($id <= 0) {
            return null;
        }
        $t = new Ticket();
        return $t->getFromDB($id) ? $t : null;
    }

    /** Заказ по заявке. */
    public static function getForTicket(int $tickets_id): ?self
    {
        $o = new self();
        $found = $o->find(['itemtype' => 'Ticket', 'items_id' => $tickets_id], [], 1);
        if (!count($found)) {
            return null;
        }
        $o->getFromDB((int) array_key_first($found));
        return $o;
    }

    /**
     * Приводим поля получателя к одному виду.
     *
     * Из формы могут прийти значения всех трёх списков сразу: пользователь
     * переключал «для кого», а скрытые поля остались заполненными. Оставляем
     * только то, что относится к выбранному варианту, — иначе отчёт и лимиты
     * получат два основания вместо одного.
     */
    private function normalizeRecipient(array $input): array
    {
        if (!array_key_exists('recipient_type', $input)) {
            return $input;
        }
        $type = (string) $input['recipient_type'];
        if (!isset(self::recipientTypes()[$type])) {
            $type = self::FOR_SELF;
            $input['recipient_type'] = $type;
        }
        if ($type !== self::FOR_USER) {
            $input['users_id_recipient'] = 0;
        }
        if ($type !== self::FOR_GROUP) {
            $input['groups_id_recipient'] = 0;
        }
        if ($type !== self::FOR_ENTITY) {
            $input['entities_id_recipient'] = 0;
        }
        return $input;
    }

    public function prepareInputForAdd($input)
    {
        return $this->normalizeRecipient((array) $input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->normalizeRecipient((array) $input);
    }

    /** Для кого заказ. */
    public const FOR_SELF   = 'self';
    public const FOR_USER   = 'user';
    public const FOR_GROUP  = 'group';
    public const FOR_ENTITY = 'entity';

    public static function recipientTypes(): array
    {
        return [
            self::FOR_SELF   => __('Для себя', 'storefront'),
            self::FOR_USER   => __('Для сотрудника', 'storefront'),
            self::FOR_GROUP  => __('Для отдела или модуля', 'storefront'),
            self::FOR_ENTITY => __('Для подразделения', 'storefront'),
        ];
    }

    public function recipientType(): string
    {
        $t = (string) ($this->fields['recipient_type'] ?? self::FOR_SELF);
        return isset(self::recipientTypes()[$t]) ? $t : self::FOR_SELF;
    }

    /**
     * Кто получатель как человек.
     *
     * Для заказа на отдел материально ответственным остаётся заказчик: именно
     * он расписывается в накладной. Отдел при этом фиксируется отдельно —
     * по нему считаются лимиты и строится отчётность.
     */
    public function recipientId(): int
    {
        if ($this->recipientType() === self::FOR_USER
            && (int) $this->fields['users_id_recipient'] > 0) {
            return (int) $this->fields['users_id_recipient'];
        }
        return (int) $this->fields['users_id_requester'];
    }

    /** Человекочитаемо: для кого заказ. */
    public function recipientLabel(): string
    {
        switch ($this->recipientType()) {
            case self::FOR_USER:
                $u = (int) $this->fields['users_id_recipient'];
                return __('Для сотрудника: ', 'storefront') . ($u > 0 ? getUserName($u) : __('не указан', 'storefront'));
            case self::FOR_GROUP:
                $g = (int) $this->fields['groups_id_recipient'];
                return __('Для отдела: ', 'storefront')
                    . ($g > 0 ? \Dropdown::getDropdownName('glpi_groups', $g) : __('не указан', 'storefront'));
            case self::FOR_ENTITY:
                $e = (int) $this->fields['entities_id_recipient'];
                return __('Для подразделения: ', 'storefront')
                    . ($e > 0 ? \Dropdown::getDropdownName('glpi_entities', $e) : __('не указано', 'storefront'));
        }
        return __('Для себя', 'storefront');
    }

    /**
     * На чей лимит ложится заказ.
     *
     * @return array{scope:string, items_id:int}
     */
    public function limitScope(): array
    {
        return self::limitScopeFor(
            $this->recipientType(),
            (int) $this->fields['users_id_recipient'],
            (int) ($this->fields['groups_id_recipient'] ?? 0),
            (int) ($this->fields['entities_id_recipient'] ?? 0),
            (int) $this->fields['users_id_requester']
        );
    }

    /**
     * То же самое, но по сырым значениям формы: витрина проверяет лимиты
     * до того, как заказ создан.
     *
     * @return array{scope:string, items_id:int}
     */
    public static function limitScopeFor(
        string $type,
        int $users_id_recipient,
        int $groups_id_recipient,
        int $entities_id_recipient,
        int $users_id_requester
    ): array {
        if ($type === self::FOR_GROUP && $groups_id_recipient > 0) {
            return ['scope' => 'group', 'items_id' => $groups_id_recipient];
        }
        if ($type === self::FOR_ENTITY && $entities_id_recipient > 0) {
            return ['scope' => 'entity', 'items_id' => $entities_id_recipient];
        }
        if ($type === self::FOR_USER && $users_id_recipient > 0) {
            return ['scope' => 'user', 'items_id' => $users_id_recipient];
        }
        // Вариант выбран, но получатель не указан — заказ остаётся на заказчике.
        return ['scope' => 'user', 'items_id' => $users_id_requester];
    }

    /**
     * Стадии заказа для полосы прогресса.
     *
     * Сотруднику важнее видеть, где заказ сейчас и что впереди, чем читать
     * одно слово состояния. Отменённый и отклонённый заказ дальше не идут —
     * для них полоса гаснет на первой стадии.
     *
     * @return array<int,array{label:string, done:bool}>
     */
    public static function progress(string $state): array
    {
        $order = [self::APPROVAL, self::QUEUE, self::APPROVED, self::READY, self::ISSUED];
        $labels = [
            self::APPROVAL => __('Оформлен', 'storefront'),
            self::QUEUE    => __('Согласован', 'storefront'),
            self::APPROVED => __('Собирается', 'storefront'),
            self::READY    => __('Готов', 'storefront'),
            self::ISSUED   => __('Выдан', 'storefront'),
        ];
        $reached = array_search($state, $order, true);
        if ($state === self::DRAFT) {
            $reached = -1;
        }
        if (in_array($state, [self::REJECTED, self::CANCELLED], true)) {
            $reached = 0;
        }
        $out = [];
        foreach ($order as $i => $key) {
            $out[] = [
                'label' => $labels[$key],
                'done'  => $reached !== false && $i <= (int) $reached,
            ];
        }
        return $out;
    }

    /**
     * Повторить заказ: положить его состав в корзину заказчика.
     *
     * Берём запрошенные количества, а не выданные: человек просил столько,
     * сколько ему нужно, и урезание прошлого раза не должно закрепляться
     * как новая норма. Позиции, которых уже нет в витрине или которые
     * выключены, пропускаем.
     *
     * @return array{added:int, skipped:int}
     */
    public function repeatToCart(): array
    {
        $catalogs_id = (int) $this->fields['plugin_storefront_catalogs_id'];
        $users_id = (int) $this->fields['users_id_requester'];

        $have = [];
        foreach (CartItem::forUser($users_id, $catalogs_id) as $row) {
            $have[(int) $row['plugin_storefront_products_id']] = (int) $row['qty'];
        }

        $added = 0;
        $skipped = 0;
        foreach ($this->lines() as $line) {
            $pid = (int) $line['plugin_storefront_products_id'];
            $qty = max(1, (int) $line['qty_requested']);
            $p = new Product();
            if ($pid <= 0
                || !$p->getFromDB($pid)
                || (int) $p->fields['is_active'] !== 1
                || (int) $p->fields['plugin_storefront_catalogs_id'] !== $catalogs_id) {
                $skipped++;
                continue;
            }
            if (CartItem::put($users_id, $catalogs_id, $pid, ($have[$pid] ?? 0) + $qty)) {
                $added++;
            }
        }
        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * Текст решения по завершённому заказу.
     *
     * Один на все пути завершения: и когда решение оформляет склад сразу,
     * и когда его дописывает автоматическое действие.
     */
    public function closingText(): string
    {
        $note = trim((string) $this->fields['approval_comment']);
        switch ($this->state()) {
            case self::REJECTED:
                return sprintf(
                    __('<p>Заказ №%d отклонён при согласовании, ничего не выдавалось, ', 'storefront')
                    . __('резерв со склада снят.%s</p>', 'storefront'),
                    $this->getID(),
                    $note !== '' ? __(' Причина: ', 'storefront') . htmlescape($note) : ''
                );
            case self::CANCELLED:
                return sprintf(
                    __('<p>Заказ №%d отменён, ничего не выдавалось, резерв со склада снят.%s</p>', 'storefront'),
                    $this->getID(),
                    $note !== '' ? __(' Причина: ', 'storefront') . htmlescape($note) : ''
                );
            case self::ISSUED:
                return sprintf(
                    __('<p>Заказ №%d выдан по накладной %s. Выдано позиций: %d, единиц: %d.</p>', 'storefront'),
                    $this->getID(),
                    htmlescape($this->waybillNumber()),
                    (int) $this->fields['lines_count'],
                    (int) $this->fields['qty_issued']
                );
        }
        return sprintf(__('<p>Работа по заказу №%d завершена.</p>', 'storefront'), $this->getID());
    }

    /** Пересчитать итоги по строкам. */
    public function recalc(): void
    {
        $lines = $this->lines();
        $qtyReq = 0;
        $qtyApp = 0;
        $qtyIss = 0;
        $amount = 0.0;
        foreach ($lines as $l) {
            $qtyReq += (int) $l['qty_requested'];
            $qtyApp += (int) $l['qty_approved'];
            $qtyIss += (int) $l['qty_issued'];
            $q = (int) $l['qty_approved'] ?: (int) $l['qty_requested'];
            $amount += $q * (float) $l['price_snapshot'];
        }
        $this->update([
            'id'            => $this->getID(),
            'lines_count'   => count($lines),
            'qty_requested' => $qtyReq,
            'qty_approved'  => $qtyApp,
            'qty_issued'    => $qtyIss,
            'amount'        => round($amount, 2),
        ]);
    }

    /** Сводка по обеспеченности: сколько процентов запроса утверждено. */
    public function fulfilment(): float
    {
        $req = (int) $this->fields['qty_requested'];
        if ($req <= 0) {
            return 0.0;
        }
        $base = (int) ($this->fields['qty_issued'] ?: $this->fields['qty_approved']);
        return round(100 * $base / $req, 1);
    }

    /** Печатный номер накладной. */
    /**
     * Вернуть на склад то, что успели списать при прерванной выдаче.
     *
     * Возврат оформляется приходом с основанием: движения — это учёт, и
     * «отменить» списание можно только встречной проводкой, а не удалением.
     */
    private function rollbackIssued(array $lines): void
    {
        $wid = (int) $this->fields['plugin_storefront_warehouses_id'];
        $oi = new OrderItem();
        foreach ($lines as $done) {
            Engine::receive(
                (int) $done['products_id'],
                $wid,
                (int) $done['qty'],
                [
                    'orders_id'   => $this->getID(),
                    'entities_id' => (int) $this->fields['entities_id'],
                    'comment'     => __('Возврат: выдача по заказу №', 'storefront') . $this->getID()
                        . __(' прервана', 'storefront'),
                ]
            );
            $oi->update(['id' => (int) $done['line'], 'qty_issued' => 0]);
        }
    }

    public function waybillNumber(): string
    {
        $own = trim((string) $this->fields['waybill_no']);
        if ($own !== '') {
            return $own;
        }
        // Номер строится из года и номера заказа — он уникален по построению,
        // потому что номер заказа не повторяется.
        return sprintf('%s-%04d', date('Y', strtotime((string) $this->fields['date_creation'])
            ?: Engine::nowTs()), $this->getID());
    }

    /** Занят ли номер накладной другим заказом. */
    public static function waybillTaken(string $number, int $except_id = 0): bool
    {
        $number = trim($number);
        if ($number === '') {
            return false;
        }
        $crit = ['waybill_no' => $number];
        if ($except_id > 0) {
            $crit[] = ['NOT' => ['id' => $except_id]];
        }
        return countElementsInTable(self::getTable(), $crit) > 0;
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();
        $tab[] = ['id' => '3', 'table' => $this->getTable(), 'field' => 'state',
            'name' => __('Состояние', 'storefront'), 'datatype' => 'string'];
        $tab[] = ['id' => '4', 'table' => $this->getTable(), 'field' => 'qty_requested',
            'name' => __('Запрошено', 'storefront'), 'datatype' => 'number'];
        $tab[] = ['id' => '5', 'table' => $this->getTable(), 'field' => 'qty_approved',
            'name' => __('Утверждено', 'storefront'), 'datatype' => 'number'];
        $tab[] = ['id' => '6', 'table' => $this->getTable(), 'field' => 'qty_issued',
            'name' => __('Выдано', 'storefront'), 'datatype' => 'number'];
        $tab[] = ['id' => '7', 'table' => $this->getTable(), 'field' => 'amount',
            'name' => __('Сумма', 'storefront'), 'datatype' => 'decimal'];
        $tab[] = ['id' => '8', 'table' => $this->getTable(), 'field' => 'date_issued',
            'name' => __('Дата выдачи', 'storefront'), 'datatype' => 'datetime'];
        $tab[] = ['id' => '9', 'table' => 'glpi_users', 'field' => 'name',
            'linkfield' => 'users_id_requester', 'name' => __('Заказчик', 'storefront'), 'datatype' => 'dropdown'];
        $tab[] = ['id' => '10', 'table' => 'glpi_users', 'field' => 'name',
            'linkfield' => 'users_id_recipient', 'name' => __('Получатель', 'storefront'), 'datatype' => 'dropdown'];
        return $tab;
    }

    /** Вкладка «Заказ магазина» на карточке заявки. */
    public function getTabNameForItem(\CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof Ticket) || !Session::haveRight(self::$rightname, READ)) {
            return '';
        }
        $o = self::getForTicket((int) $item->getID());
        return $o === null ? '' : self::createTabEntry(__('Заказ магазина', 'storefront'));
    }

    public static function displayTabContentForItem(\CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        $o = self::getForTicket((int) $item->getID());
        if ($o === null) {
            return false;
        }
        Ui::showOrder($o, false);
        return true;
    }

    // ================================================== жизненный цикл

    /**
     * Отправить заказ на согласование.
     * Создаёт заявку GLPI, запрашивает согласование штатными средствами,
     * а при попадании в порог автосогласования сразу передаёт исполнителю.
     */
    public function submit(): bool
    {
        if ($this->state() !== self::DRAFT) {
            Session::addMessageAfterRedirect(__('Заказ уже отправлен.', 'storefront'), false, ERROR);
            return false;
        }
        $catalog = $this->getCatalog();
        if ($catalog === null) {
            Session::addMessageAfterRedirect(__('Витрина заказа не найдена.', 'storefront'), false, ERROR);
            return false;
        }
        $lines = $this->lines();
        if (!count($lines)) {
            Session::addMessageAfterRedirect(__('В заказе нет ни одной позиции.', 'storefront'), false, ERROR);
            return false;
        }
        if ($catalog->requiresApprover()
            && (int) $this->fields['users_id_approver'] <= 0) {
            Session::addMessageAfterRedirect(
                __('Выберите согласующего: по правилам этой витрины заказ ', 'storefront')
                . __('отправляется на согласование конкретному человеку.', 'storefront'),
                false,
                ERROR
            );
            return false;
        }

        $this->recalc();
        $this->getFromDB($this->getID());

        // Заявка GLPI — обычная, чтобы работали штатные списки и отчётность.
        $ticket = new Ticket();
        $content = Ui::orderAsText($this);
        $tid = (int) $ticket->add([
            'name'                => $this->fields['name'] ?: (__('Заказ по витрине «', 'storefront')
                . $catalog->fields['name'] . '»'),
            'content'             => $content,
            'entities_id'         => (int) $this->fields['entities_id'],
            'itilcategories_id'   => (int) $catalog->fields['itilcategories_id'],
            'type'                => Ticket::DEMAND_TYPE,
            '_users_id_requester' => (int) $this->fields['users_id_requester'],
            '_groups_id_assign'   => (int) $catalog->fields['groups_id_fulfil'],
        ]);
        if (!$tid) {
            Session::addMessageAfterRedirect(__('Не удалось создать заявку по заказу.', 'storefront'), false, ERROR);
            return false;
        }

        // Витрина может быть настроена вообще без согласования. Тогда порог
        // рутинных заказов не при чём: заказ идёт на склад независимо от суммы.
        $no_approval = (string) $catalog->fields['approval_mode'] === Catalog::APPROVE_NONE;
        $auto = $no_approval || $catalog->qualifiesForAutoApproval(
            (float) $this->fields['amount'],
            (int) $this->fields['lines_count']
        );

        $this->update([
            'id'               => $this->getID(),
            'itemtype'         => 'Ticket',
            'items_id'         => $tid,
            'state'            => $auto ? self::QUEUE : self::APPROVAL,
            'is_auto_approved' => $auto ? 1 : 0,
            'date_submitted'   => Engine::now(),
            'date_approved'    => $auto ? Engine::now() : null,
        ]);
        $this->getFromDB($this->getID());

        if ($auto) {
            $why = $no_approval
                ? __('<p>Витрина работает без согласования: заказ сразу передан на склад ', 'storefront')
                    . __('для комплектования.</p>', 'storefront')
                : __('<p>Заказ в пределах порога рутинных заказов витрины и сразу передан ', 'storefront')
                    . __('на склад для комплектования.</p>', 'storefront');
            TicketLog::note($this->getTicket(),
                __('<p><strong>Согласование не требуется.</strong></p>', 'storefront') . $why);
            Session::addMessageAfterRedirect(
                __('Заказ №', 'storefront') . $this->getID() . ($no_approval
                    ? __(' передан исполнителю: витрина работает без согласования.', 'storefront')
                    : __(' согласован автоматически: он в пределах порога рутинных заказов', 'storefront')
                        . __(' витрины. Передан исполнителю.', 'storefront')),
                false,
                INFO
            );
            $this->update(['id' => $this->getID(),
                'approval_source' => $no_approval ? 'none' : 'auto']);
            $this->onEnterQueue();
            return true;
        }

        // Согласование — штатное: согласующий отвечает привычными кнопками
        // в ленте заявки, никакого отдельного интерфейса учить не нужно.
        // Если сотрудник выбрал согласующего при оформлении, его выбор главнее
        // любой автоматики; иначе включается цепочка руководителей или группа.
        $who = Engine::resolveApprover(
            $catalog,
            (int) $this->fields['users_id_requester'],
            (int) $this->fields['users_id_approver']
        );
        $val = new TicketValidation();
        $input = [
            'tickets_id'         => $tid,
            'entities_id'        => (int) $this->fields['entities_id'],
            'comment_submission' => __('Согласование заказа №', 'storefront') . $this->getID()
                . __(' по витрине «', 'storefront') . $catalog->fields['name'] . __('». Позиций: ', 'storefront')
                . (int) $this->fields['lines_count'] . '.',
        ];
        if ($who['users_id'] > 0) {
            $input['itemtype_target'] = \User::class;
            $input['items_id_target'] = $who['users_id'];
        } elseif ($who['groups_id'] > 0) {
            $input['itemtype_target'] = \Group::class;
            $input['items_id_target'] = $who['groups_id'];
        } else {
            Session::addMessageAfterRedirect(
                __('Согласующий не определён: у сотрудника не заполнен руководитель, ', 'storefront')
                . __('а у витрины не указана группа согласующих. Заказ создан, ', 'storefront')
                . __('но согласование нужно назначить вручную.', 'storefront'),
                false,
                WARNING
            );
            // Сообщение в сессии увидит только сотрудник и только один раз,
            // поэтому тупик фиксируется в ленте заявки: иначе заказ повиснет
            // на согласовании, о котором никто не знает.
            TicketLog::note($this->getTicket(),
                __('<p><strong>Согласующий не определён.</strong></p>', 'storefront')
                . __('<p>У сотрудника не заполнен руководитель, а у витрины не указана ', 'storefront')
                . __('группа согласующих. Заказ ждёт, пока согласование не будет ', 'storefront')
                . __('назначено вручную в этой заявке.</p>', 'storefront'));
            $this->update(['id' => $this->getID(), 'approval_source' => 'none']);
            return true;
        }

        if (!$val->add($input)) {
            Session::addMessageAfterRedirect(__('Не удалось создать запрос на согласование.', 'storefront'), false, ERROR);
            return false;
        }
        $this->update([
            'id'              => $this->getID(),
            'users_id_approver' => $who['users_id'],
            'approval_source' => $who['source'],
        ]);
        // В ленту — кто согласует. Иначе сотрудник видит «на согласовании»
        // и не понимает, от кого ждать ответа.
        TicketLog::note($this->getTicket(), sprintf(
            __('<p><strong>Заказ отправлен на согласование.</strong></p>', 'storefront')
            . __('<p>Согласующий: %s</p><p>Пока согласование не получено, склад заказ ', 'storefront')
            . __('не комплектует.</p>', 'storefront'),
            htmlescape($who['users_id'] > 0
                ? TicketLog::person($who['users_id'])
                : __('группа «', 'storefront') . \Dropdown::getDropdownName('glpi_groups', $who['groups_id']) . '»')
        ));

        Session::addMessageAfterRedirect(
            __('Заказ №', 'storefront') . $this->getID() . __(' отправлен на согласование.', 'storefront'), false, INFO
        );
        return true;
    }

    /** Согласующий одобрил: заказ уходит исполнителю. */
    public function onApproved(int $users_id = 0, string $comment = ''): bool
    {
        if (!in_array($this->state(), [self::APPROVAL], true)) {
            return false;
        }
        // users_id_approver — это тот, кому отправили запрос, и он таким
        // и остаётся: подменять его «тем, кто в сессии» значило бы записать
        // догадку вместо факта.
        $this->update([
            'id'               => $this->getID(),
            'state'            => self::QUEUE,
            'approval_comment' => $comment,
            'date_approved'    => Engine::now(),
        ]);
        $this->getFromDB($this->getID());
        TicketLog::note($this->getTicket(), sprintf(
            __('<p><strong>Заказ согласован.</strong>%s</p>', 'storefront')
            . __('<p>Передан на склад для комплектования. Позиции зарезервированы ', 'storefront')
            . __('за этим заказом.</p>', 'storefront'),
            trim($comment) !== '' ? __(' Комментарий: ', 'storefront') . htmlescape($comment) : ''
        ));
        $this->onEnterQueue();
        return true;
    }

    /** Согласующий отклонил. */
    public function onRejected(int $users_id = 0, string $comment = ''): bool
    {
        if ($this->state() !== self::APPROVAL) {
            return false;
        }
        $this->update([
            'id'               => $this->getID(),
            'state'            => self::REJECTED,
            'approval_comment' => $comment,
        ]);
        // Пишем только факт: кто ответил, GLPI не хранит, а на запрос
        // к группе отвечает любой её участник.
        TicketLog::note($this->getTicket(), sprintf(
            __('<p><strong>В согласовании отказано.</strong>%s</p>', 'storefront')
            . __('<p>Заказ дальше не идёт, склад его не комплектует, резерв снят.</p>', 'storefront'),
            trim($comment) !== '' ? __(' Причина: ', 'storefront') . htmlescape($comment) : ''
        ));

        // Работы по заявке больше нет ни у кого: заказ мёртв, склад его не
        // увидит. Оставлять заявку в работе — значит копить просрочку по SLA
        // на том, чего никто не сделает. Поэтому прикладываем решение и
        // оставляем заявку в «Решена»: сотрудник успевает увидеть причину и
        // при несогласии вернуть заявку в работу, а закрывает её потом
        // штатное автозакрытие GLPI — как и при отмене заказа.
        $catalog = $this->getCatalog();
        if ($catalog === null || $catalog->closesOnReject()) {
            TicketLog::solve($this->getTicket(), sprintf(
                __('<p><strong>Отказано в согласовании.</strong>%s</p>', 'storefront')
                . __('<p>Заказ не выдавался, склад не тронут. Если потребность ', 'storefront')
                . __('осталась — оформите заказ заново.</p>', 'storefront'),
                trim($comment) !== '' ? __(' Причина: ', 'storefront') . htmlescape($comment) : ''
            ));
        }
        return true;
    }

    /**
     * Заказ попал в очередь исполнителя: ставим мягкий резерв.
     * Резерв не запрещает выдать позицию другому заказу, но показывает,
     * что количество уже обещано.
     */
    private function onEnterQueue(): void
    {
        $catalog = $this->getCatalog();
        if ($catalog === null
            || (string) $catalog->fields['reserve_mode'] !== Catalog::RESERVE_SOFT) {
            return;
        }
        $wid = (int) $this->fields['plugin_storefront_warehouses_id'];
        if ($wid <= 0) {
            return;
        }
        $oi = new OrderItem();
        foreach ($this->lines() as $id => $l) {
            $pid = (int) $l['plugin_storefront_products_id'];
            $qty = (int) $l['qty_requested'];
            if ($pid <= 0 || $qty <= 0) {
                continue;
            }
            if (Engine::reserve($pid, $wid, $qty, [
                'orders_id'   => $this->getID(),
                'entities_id' => (int) $this->fields['entities_id'],
                'comment'     => __('Резерв под заказ №', 'storefront') . $this->getID(),
            ])) {
                // Помним занятое количество: дальше именно его и освобождаем,
                // сколько бы раз ни правили утверждённые количества.
                $oi->update(['id' => (int) $id, 'qty_reserved' => $qty]);
            }
        }
    }

    /**
     * Привести резерв строки к нужному количеству.
     *
     * Единственное место, где резерв заказа меняется после постановки в
     * очередь: и утверждение количеств, и выдача, и отмена ходят сюда.
     */
    private function reserveLineTo(int $lines_id, int $want): void
    {
        $wid = (int) $this->fields['plugin_storefront_warehouses_id'];
        $line = new OrderItem();
        if ($wid <= 0 || !$line->getFromDB($lines_id)) {
            return;
        }
        $pid = (int) $line->fields['plugin_storefront_products_id'];
        $held = (int) $line->fields['qty_reserved'];
        $want = max(0, $want);
        if ($pid <= 0 || $held === $want) {
            return;
        }
        $extra = [
            'orders_id'   => $this->getID(),
            'entities_id' => (int) $this->fields['entities_id'],
            'comment'     => __('Изменение резерва по заказу №', 'storefront') . $this->getID(),
        ];
        $done = $want > $held
            ? Engine::reserve($pid, $wid, $want - $held, $extra)
            : Engine::unreserve($pid, $wid, $held - $want, $extra);
        if ($done) {
            $line->update(['id' => $lines_id, 'qty_reserved' => $want]);
        }
    }

    /** Снять резерв целиком: при отмене или после выдачи остатка. */
    /**
     * Полностью освободить склад от этого заказа.
     *
     * Снимаем ровно то, что заказ держит по учёту строк, а не расчётное
     * количество: после правки утверждённых количеств они расходятся, и
     * расчёт оставил бы «мёртвый» резерв, который никто уже не снимет.
     * Повторный вызов ничего не портит — держать становится нечего.
     */
    public function releaseReserve(): void
    {
        foreach (array_keys($this->lines()) as $id) {
            $this->reserveLineTo((int) $id, 0);
        }
    }

    /**
     * Исполнитель утвердил количества.
     * Увеличивать сверх запрошенного нельзя — это требование задания.
     *
     * @param array $quantities [orderitems_id => qty]
     * @param array $reasons    [orderitems_id => причина изменения]
     */
    public function approveQuantities(array $quantities, array $reasons = []): bool
    {
        if (!$this->mayManage()) {
            return false;
        }
        if (!in_array($this->state(), [self::QUEUE, self::APPROVED], true)) {
            Session::addMessageAfterRedirect(
                __('Утверждать количества можно только у заказа в работе.', 'storefront'), false, ERROR
            );
            return false;
        }
        $oi = new OrderItem();
        foreach ($this->lines() as $id => $l) {
            if (!array_key_exists($id, $quantities)) {
                continue;
            }
            $req = (int) $l['qty_requested'];
            $want = (int) $quantities[$id];
            if ($want > $req) {
                Session::addMessageAfterRedirect(
                    __('Нельзя утвердить больше запрошенного по позиции «', 'storefront')
                    . $l['name_snapshot'] . __('»: запрошено ', 'storefront') . $req . '.',
                    false,
                    ERROR
                );
                return false;
            }
            if ($want < 0) {
                $want = 0;
            }
            $reason = trim((string) ($reasons[$id] ?? ''));
            if ($want < $req && $reason === '') {
                Session::addMessageAfterRedirect(
                    __('Уменьшение количества по позиции «', 'storefront') . $l['name_snapshot']
                    . __('» требует указания причины.', 'storefront'),
                    false,
                    ERROR
                );
                return false;
            }
            $oi->update([
                'id'            => (int) $id,
                'qty_approved'  => $want,
                'change_reason' => $reason,
            ]);
            // Резерв держим ровно под утверждённое: урезали заказ — вернули
            // разницу на склад сразу, а не после выдачи.
            if ((int) $l['qty_reserved'] > 0 || $want > 0) {
                $this->reserveLineTo((int) $id, $want);
            }
        }
        $this->update(['id' => $this->getID(), 'state' => self::APPROVED]);
        $this->getFromDB($this->getID());
        $this->recalc();
        $this->getFromDB($this->getID());

        // В ленту — что именно урезали и почему. Иначе сотрудник увидит
        // в накладной другое число и придёт выяснять к кладовщику.
        $rows = [];
        $changed = 0;
        foreach ($this->lines() as $l) {
            $req = (int) $l['qty_requested'];
            $app = (int) $l['qty_approved'];
            if ($app !== $req) {
                $changed++;
            }
            $rows[] = sprintf(
                '<tr><td>%s</td><td>%d</td><td>%s%d</td><td>%s</td></tr>',
                htmlescape((string) $l['name_snapshot']),
                $req,
                $app < $req ? '<strong>' : '',
                $app,
                htmlescape((string) $l['change_reason'])
            );
        }
        TicketLog::note($this->getTicket(),
            __('<p><strong>Склад подтвердил количества к выдаче.</strong>', 'storefront')
            . ($changed > 0 ? __(' Часть позиций изменена.', 'storefront') : __(' Изменений нет.', 'storefront')) . '</p>'
            . '<table border="1" cellpadding="4" cellspacing="0">'
            . __('<tr><th>Позиция</th><th>Запрошено</th><th>К выдаче</th>', 'storefront')
            . __('<th>Причина изменения</th></tr>', 'storefront')
            . implode('', $rows) . '</table>');
        return true;
    }

    public function markReady(): bool
    {
        if (!$this->mayManage()) {
            return false;
        }
        if ($this->state() !== self::APPROVED) {
            return false;
        }
        $ok = (bool) $this->update(['id' => $this->getID(), 'state' => self::READY]);
        if ($ok) {
            $wh = $this->getWarehouse();
            TicketLog::note($this->getTicket(), sprintf(
                __('<p><strong>Заказ собран и готов к получению.</strong></p>', 'storefront')
                . __('<p>Забрать: %s. Получатель: %s.</p>', 'storefront'),
                htmlescape($wh !== null ? (string) $wh->fields['name'] : __('склад витрины', 'storefront')),
                htmlescape(getUserName($this->recipientId()))
            ));
        }
        return $ok;
    }

    /**
     * Зафиксировать выдачу и списать остаток.
     * Списание возможно только здесь и только на утверждённое количество —
     * ровно как требует задание.
     */
    public function issue(string $waybill = ''): bool
    {
        if (!$this->mayManage()) {
            return false;
        }
        if (!in_array($this->state(), [self::APPROVED, self::READY], true)) {
            Session::addMessageAfterRedirect(
                __('Выдать можно только заказ, утверждённый к выдаче.', 'storefront'), false, ERROR
            );
            return false;
        }
        $wid = (int) $this->fields['plugin_storefront_warehouses_id'];
        if ($wid <= 0) {
            Session::addMessageAfterRedirect(__('У заказа не указан склад выдачи.', 'storefront'), false, ERROR);
            return false;
        }

        // Накладная — первичный документ учёта: два заказа с одним номером
        // делают выдачу неразличимой в отчётности, поэтому номер, введённый
        // кладовщиком вручную, проверяем до того, как тронем склад.
        $waybill = trim($waybill);
        if ($waybill !== '' && self::waybillTaken($waybill, $this->getID())) {
            Session::addMessageAfterRedirect(
                __('Накладная № ', 'storefront') . $waybill . __(' уже выдана по другому заказу. ', 'storefront')
                . __('Укажите другой номер или оставьте поле пустым — номер ', 'storefront')
                . __('сформируется автоматически.', 'storefront'),
                false,
                ERROR
            );
            return false;
        }

        // Лимит считает выданное, поэтому проверять его на входе недостаточно:
        // между оформлением и выдачей человек мог получить норму по другому
        // заказу. Два заказа, каждый в пределах остатка лимита, вместе его
        // пробивают — и обнаруживается это уже в отчётах.
        $catalog = $this->getCatalog();
        if ($catalog !== null) {
            $lines = [];
            foreach ($this->lines() as $l) {
                $qty = (int) $l['qty_approved'];
                if ($qty > 0) {
                    $lines[] = ['products_id' => (int) $l['plugin_storefront_products_id'],
                        'qty' => $qty];
                }
            }
            $hardViolations = [];
            foreach (Engine::checkLimits($catalog, (int) $this->fields['users_id_requester'],
                $lines, $this->limitScope()) as $v) {
                if ((bool) $v['is_hard']) {
                    $hardViolations[] = sprintf(
                        __('%s (%s): получено %d из %d, к выдаче ещё %d', 'storefront'),
                        (string) $v['limit']['name'],
                        (string) ($v['pool'] ?? __('норма получателя', 'storefront')),
                        (int) $v['used'],
                        (int) $v['max'],
                        (int) $v['requested']
                    );
                }
            }
            if (count($hardViolations)) {
                Session::addMessageAfterRedirect(
                    __('Выдача не проведена: превышен жёсткий лимит. ', 'storefront')
                    . implode('; ', $hardViolations)
                    . __('. Уменьшите количество к выдаче или дождитесь нового периода — ', 'storefront')
                    . __('склад не тронут.', 'storefront'),
                    false,
                    ERROR
                );
                return false;
            }
        }

        // Сначала убеждаемся, что хватит всего по всем позициям, и только
        // потом трогаем склад. Иначе выдача, споткнувшаяся на третьей строке,
        // оставляла первые две списанными, а заказ — невыданным: остаток
        // уменьшился, накладной нет, и найти это можно только сверкой.
        $shortage = [];
        foreach ($this->lines() as $id => $l) {
            $qty = (int) $l['qty_approved'];
            if ($qty <= 0) {
                continue;
            }
            $pid = (int) $l['plugin_storefront_products_id'];
            $stock = Stock::ensure($pid, $wid);
            // Считаем по остатку на руках: именно его уменьшает выдача.
            // Резерв других заказов выдачу не запрещает — он мягкий, — но
            // и «свободное» тут не годится: оно уходит в ноль и создаёт
            // видимость наличия там, где физически позиции нет.
            $onHand = (int) $stock->fields['qty_on_hand'];
            if ($onHand < $qty) {
                $shortage[] = sprintf(
                    __('%s: нужно %d, на складе %d', 'storefront'),
                    (string) $l['name_snapshot'],
                    $qty,
                    max(0, $onHand)
                );
            }
        }
        if (count($shortage)) {
            Session::addMessageAfterRedirect(
                __('Выдача не проведена: на складе не хватает позиций. ', 'storefront')
                . implode('; ', $shortage) . __('. Уменьшите количество к выдаче ', 'storefront')
                . __('или дождитесь поступления — склад не тронут.', 'storefront'),
                false,
                ERROR
            );
            return false;
        }

        $oi = new OrderItem();
        $issuedAny = false;
        $doneLines = [];
        foreach ($this->lines() as $id => $l) {
            $qty = (int) $l['qty_approved'];
            if ($qty <= 0) {
                continue;
            }
            $line = new OrderItem();
            if (!$line->getFromDB((int) $id)) {
                continue;
            }
            // Излишек резерва (утвердили меньше, чем заняли) возвращаем до
            // выдачи, иначе он повиснет на складе навсегда.
            $held = (int) $line->fields['qty_reserved'];
            if ($held > $qty) {
                $this->reserveLineTo((int) $id, $qty);
                $line->getFromDB((int) $id);
                $held = (int) $line->fields['qty_reserved'];
            }
            if (!Engine::issueLine($line, $this, $qty, min($held, $qty))) {
                // Предпроверка прошла, а списание всё же не удалось — значит
                // остаток забрали параллельно. Возвращаем то, что уже списали,
                // чтобы заказ и склад остались в согласованном состоянии.
                $this->rollbackIssued($doneLines);
                Session::addMessageAfterRedirect(
                    __('Выдача прервана: позицию «', 'storefront') . $l['name_snapshot'] . __('» не удалось ', 'storefront')
                    . __('списать. Уже списанное возвращено на склад, заказ остался ', 'storefront')
                    . __('в работе.', 'storefront'),
                    false,
                    ERROR
                );
                return false;
            }
            $oi->update(['id' => (int) $id, 'qty_issued' => $qty, 'qty_reserved' => 0]);
            $doneLines[] = ['products_id' => (int) $l['plugin_storefront_products_id'],
                'qty' => $qty, 'line' => (int) $id];
            $issuedAny = true;
        }
        if (!$issuedAny) {
            Session::addMessageAfterRedirect(
                __('Ни одна позиция не имеет утверждённого количества.', 'storefront'), false, ERROR
            );
            return false;
        }

        // Автоматический номер тоже может оказаться занятым: кладовщик мог
        // ввести такой же вручную по другому заказу. Тогда добавляем суффикс,
        // а не выдаём вторую накладную с тем же номером.
        $number = $waybill !== '' ? $waybill : $this->waybillNumber();
        if (self::waybillTaken($number, $this->getID())) {
            $suffix = 1;
            do {
                $candidate = $number . '-' . (++$suffix);
            } while (self::waybillTaken($candidate, $this->getID()) && $suffix < 100);
            $number = $candidate;
        }
        $this->update([
            'id'          => $this->getID(),
            'state'       => self::ISSUED,
            'waybill_no'  => $number,
            'date_issued' => Engine::now(),
        ]);
        $this->getFromDB($this->getID());
        $this->recalc();

        // Разовый набор: если заказ шёл по разрешению на повтор, оно гасится
        // именно здесь — по факту выдачи, а не по факту заказа.
        $kits_id = (int) ($this->fields['plugin_storefront_kits_id'] ?? 0);
        if ($kits_id > 0) {
            KitGrant::consume($kits_id, (int) $this->fields['users_id_requester']);
        }

        // Итог — в ленту и в решение заявки. Дальше штатные правила GLPI
        // переводят решённую заявку в «Закрыта» по своему расписанию.
        $wh = $this->getWarehouse();
        $rows = [];
        foreach ($this->lines() as $l) {
            $rows[] = sprintf(
                '<tr><td>%s</td><td>%s</td><td>%d</td></tr>',
                htmlescape((string) $l['name_snapshot']),
                htmlescape((string) $l['unit_snapshot']),
                (int) $l['qty_issued']
            );
        }
        $summary = sprintf(
            __('<p><strong>Заказ выдан.</strong></p>', 'storefront')
            . '<table border="1" cellpadding="4" cellspacing="0">'
            . __('<tr><th>Позиция</th><th>Ед.</th><th>Выдано</th></tr>%s</table>', 'storefront')
            . __('<p>Накладная: %s. Место выдачи: %s. Получил: %s. Выдал: %s.</p>', 'storefront'),
            implode('', $rows),
            htmlescape($this->waybillNumber()),
            htmlescape($wh !== null ? (string) $wh->fields['name'] : '—'),
            htmlescape(getUserName($this->recipientId())),
            htmlescape(getUserName((int) (Session::getLoginUserID() ?: 0)))
        );

        // Подробности — в ленту, короткий итог — в решение. Один и тот же
        // текст в обоих местах читается как повтор: решение GLPI и так
        // показывает в ленте отдельной записью.
        $ticket = $this->getTicket();
        TicketLog::note($ticket, $summary);
        TicketLog::solve($ticket, $this->closingText());

        Session::addMessageAfterRedirect(
            __('Заказ №', 'storefront') . $this->getID() . __(' выдан, накладная ', 'storefront') . $this->waybillNumber()
            . __('. Остатки списаны.', 'storefront'),
            false,
            INFO
        );
        return true;
    }

    /** Отмена до факта выдачи. */
    public function cancel(string $reason = ''): bool
    {
        if (!$this->isOpen()) {
            return false;
        }
        if (!$this->mayManage('cancel')) {
            return false;
        }
        $this->releaseReserve();
        $this->update([
            'id'               => $this->getID(),
            'state'            => self::CANCELLED,
            'approval_comment' => $reason,
        ]);
        $ticket = $this->getTicket();
        TicketLog::note($ticket, sprintf(
            __('<p><strong>Заказ отменён.</strong>%s</p><p>Резерв со склада снят, ', 'storefront')
            . __('ничего не выдавалось.</p>', 'storefront'),
            trim($reason) !== '' ? __(' Причина: ', 'storefront') . htmlescape($reason) : ''
        ));
        // Отмену тоже оформляем решением, а не прямым переводом в «Закрыта»:
        // так в заявке остаётся текст причины, и правила закрытия GLPI
        // работают одинаково для всех завершённых заказов.
        $this->getFromDB($this->getID());
        TicketLog::solve($ticket, $this->closingText());
        return true;
    }
}
