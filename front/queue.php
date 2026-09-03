<?php

/**
 * Очередь исполнителя: заказы витрины, корректировка количеств, выдача.
 *
 * Отдельный экран, а не список поиска: исполнителю нужно видеть состав заказа
 * и править количества, не открывая каждую запись по очереди.
 */

use GlpiPlugin\Storefront\Catalog;
use GlpiPlugin\Storefront\Order;
use GlpiPlugin\Storefront\OrderItem;
use GlpiPlugin\Storefront\Product;
use GlpiPlugin\Storefront\Stock;

Session::checkRight('plugin_storefront_order', READ);

$self = Plugin::getWebDir('storefront') . '/front/queue.php';
$esc = static fn(?string $s): string => htmlescape((string) $s);

/* ------------------------------------------------------------ действия */
if (isset($_POST['approve_qty'])) {
    Session::checkRight('plugin_storefront_order', UPDATE);
    $o = new Order();
    if ($o->getFromDB((int) $_POST['orders_id'])) {
        $qty = [];
        $reasons = [];
        foreach ((array) ($_POST['qty'] ?? []) as $lid => $v) {
            $qty[(int) $lid] = (int) $v;
        }
        foreach ((array) ($_POST['reason'] ?? []) as $lid => $v) {
            $reasons[(int) $lid] = (string) $v;
        }
        $o->approveQuantities($qty, $reasons);
    }
    Html::back();
}
if (isset($_POST['mark_ready'])) {
    Session::checkRight('plugin_storefront_order', UPDATE);
    $o = new Order();
    if ($o->getFromDB((int) $_POST['orders_id']) && !$o->markReady()) {
        Session::addMessageAfterRedirect(
            __('Отметить готовым можно только заказ, утверждённый к выдаче.', 'storefront'), false, ERROR
        );
    }
    Html::back();
}
if (isset($_POST['issue'])) {
    Session::checkRight('plugin_storefront_order', UPDATE);
    $o = new Order();
    if ($o->getFromDB((int) $_POST['orders_id'])) {
        $o->issue((string) ($_POST['waybill_no'] ?? ''));
    }
    Html::back();
}
if (isset($_POST['cancel_order'])) {
    Session::checkRight('plugin_storefront_order', UPDATE);
    $o = new Order();
    if ($o->getFromDB((int) $_POST['orders_id'])) {
        $o->cancel((string) ($_POST['cancel_reason'] ?? ''));
    }
    Html::back();
}

Html::header(__('Очередь заказов', 'storefront'), $_SERVER['PHP_SELF'], 'management', Catalog::class, 'queue');

$csrf = Session::getNewCSRFToken();
$catalogs_id = (int) ($_GET['catalog'] ?? 0);
$state = (string) ($_GET['state'] ?? 'open');
$open_id = (int) ($_GET['open'] ?? 0);

$states = [
    'open'          => __('В работе (все незакрытые)', 'storefront'),
    Order::APPROVAL => Order::stateLabel(Order::APPROVAL),
    Order::QUEUE    => Order::stateLabel(Order::QUEUE),
    Order::APPROVED => Order::stateLabel(Order::APPROVED),
    Order::READY    => Order::stateLabel(Order::READY),
    Order::ISSUED   => Order::stateLabel(Order::ISSUED),
    'all'           => __('Все', 'storefront'),
];

echo '<div class="container-fluid mt-3">';
echo __('<h2 class="mb-3">Очередь заказов</h2>', 'storefront');

/* ------------------------------------------------------------ фильтр */
echo '<form method="get" action="' . $esc($self) . '" class="row g-2 align-items-end mb-3">';
echo __('<div class="col-auto"><label class="form-label">Витрина</label>', 'storefront')
    . __('<select name="catalog" class="form-select form-select-sm"><option value="0">все</option>', 'storefront');
foreach (Catalog::availableFor((int) Session::getLoginUserID()) as $id => $c) {
    printf('<option value="%d"%s>%s</option>', (int) $id,
        (int) $id === $catalogs_id ? ' selected' : '', $esc($c['name']));
}
echo '</select></div>';
echo __('<div class="col-auto"><label class="form-label">Состояние</label>', 'storefront')
    . '<select name="state" class="form-select form-select-sm">';
foreach ($states as $k => $lb) {
    printf('<option value="%s"%s>%s</option>', $esc($k),
        $k === $state ? ' selected' : '', $esc($lb));
}
echo '</select></div>';
echo __('<div class="col-auto"><button class="btn btn-sm btn-primary">Показать</button></div>', 'storefront');
echo '</form>';

/* ------------------------------------------------------------ список */
$crit = [];
if ($catalogs_id > 0) {
    $crit['plugin_storefront_catalogs_id'] = $catalogs_id;
}
if ($state === 'open') {
    $crit['state'] = [Order::APPROVAL, Order::QUEUE, Order::APPROVED, Order::READY];
} elseif ($state !== 'all') {
    $crit['state'] = $state;
}
// find() не ограничивает выборку доступными подразделениями — добавляем
// штатный фильтр сами, иначе очередь показывает заказы чужих подразделений.
$crit += getEntitiesRestrictCriteria(Order::getTable(), '', '', true);
$orders = (new Order())->find($crit, ['id DESC'], 100);

echo '<div class="table-responsive"><table class="table table-sm table-hover">';
echo __('<thead><tr><th>Заказ</th><th>Заказчик</th><th>Для кого</th><th>Витрина</th><th>Склад</th>', 'storefront')
    . __('<th class="text-end">Позиций</th><th class="text-end">Запрошено</th>', 'storefront')
    . __('<th class="text-end">Утверждено</th><th>Состояние</th><th>Создан</th>', 'storefront')
    . '<th></th></tr></thead><tbody>';
foreach ($orders as $id => $o) {
    $cat = new Catalog();
    $wh = new \GlpiPlugin\Storefront\Warehouse();
    $row = new Order();
    $row->getFromDB((int) $id);
    printf(
        '<tr%s><td><b>№%d</b></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td>'
        . '<td class="text-end">%d</td><td class="text-end">%d</td>'
        . '<td class="text-end">%d</td><td><span class="badge bg-%s-lt">%s</span></td>'
        . '<td class="text-nowrap">%s</td>'
        . '<td class="text-end"><a class="btn btn-sm btn-outline-primary" href="%s?catalog=%d'
        . __('&state=%s&open=%d">Открыть</a></td></tr>', 'storefront'),
        (int) $id === $open_id ? ' class="table-active"' : '',
        (int) $id,
        $esc((int) $o['users_id_requester'] > 0 ? getUserName((int) $o['users_id_requester']) : '—'),
        $esc($row->recipientLabel()),
        $esc($cat->getFromDB((int) $o['plugin_storefront_catalogs_id'])
            ? (string) $cat->fields['name'] : '—'),
        $esc($wh->getFromDB((int) $o['plugin_storefront_warehouses_id'])
            ? (string) $wh->fields['name'] : '—'),
        (int) $o['lines_count'], (int) $o['qty_requested'], (int) $o['qty_approved'],
        Order::stateTone((string) $o['state']),
        $esc(Order::stateLabel((string) $o['state'])),
        $esc(Html::convDate((string) $o['date_creation'])),
        $esc($self), $catalogs_id, $esc($state), (int) $id
    );
}
if (!count($orders)) {
    echo __('<tr><td colspan="11" class="text-muted">Заказов в этом состоянии нет.</td></tr>', 'storefront');
}
echo '</tbody></table></div>';

/* ------------------------------------------------------------ карточка */
if ($open_id > 0) {
    $o = new Order();
    // Проверяем не только существование, но и доступ к подразделению заказа:
    // идентификатор в адресе строки не мешает подставить чужой.
    if (!$o->getFromDB($open_id) || !$o->canViewItem()) {
        echo __('<div class="alert alert-warning mt-3">Заказ не найден ', 'storefront')
            . __('или недоступен в вашем подразделении.</div>', 'storefront');
        echo '</div>';
        Html::footer();
        return;
    }

    $cat = $o->getCatalog();
    $wid = (int) $o->fields['plugin_storefront_warehouses_id'];
    $canEdit = Session::haveRight('plugin_storefront_order', UPDATE);

    echo '<div class="card mt-4"><div class="card-body">';
    printf(
        '<div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2 mb-2">'
        . __('<div><span class="fs-4 fw-bold">Заказ №%d</span> ', 'storefront')
        . __('<span class="text-muted">%s · заказчик %s</span></div>', 'storefront')
        . '<span class="badge bg-%s-lt">%s</span></div>',
        $o->getID(),
        $esc($cat !== null ? (string) $cat->fields['name'] : ''),
        $esc(getUserName((int) $o->fields['users_id_requester'])),
        Order::stateTone($o->state()),
        $esc(Order::stateLabel($o->state()))
    );

    printf(__('<div class="mb-2">%s. Расписывается в накладной: <b>%s</b>.%s</div>', 'storefront'),
        $esc($o->recipientLabel()),
        $esc(getUserName($o->recipientId())),
        trim((string) ($o->fields['recipient_note'] ?? '')) !== ''
            ? __(' Уточнение: ', 'storefront') . $esc((string) $o->fields['recipient_note']) : '');

    $ticket = $o->getTicket();
    if ($ticket !== null) {
        printf(__('<div class="mb-2"><a href="%s">Заявка №%d</a></div>', 'storefront'),
            $esc($ticket->getLinkURL()), $ticket->getID());
    }

    // Накладную печатают по факту выдачи — тогда же и появляется кнопка.
    if ($o->state() === Order::ISSUED) {
        printf(
            '<div class="mb-2 d-flex align-items-center gap-2 flex-wrap">'
            . __('<span>Накладная № <b>%s</b></span>', 'storefront')
            . '<a class="btn btn-sm btn-outline-secondary" target="_blank" href="%s">'
            . __('<i class="ti ti-printer me-1"></i>Печать накладной</a></div>', 'storefront'),
            $esc($o->waybillNumber()),
            $esc(Plugin::getWebDir('storefront') . '/front/waybill.php?id=' . $o->getID()
                . '&print=1')
        );
    }

    echo '<form method="post" action="' . $esc($self) . '?catalog=' . $catalogs_id
        . '&state=' . $esc($state) . '&open=' . $o->getID() . '">';
    echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
    echo Html::hidden('orders_id', ['value' => $o->getID()]);

    echo '<div class="table-responsive"><table class="table table-sm">';
    echo __('<thead><tr><th>Позиция</th><th>Ед.</th><th class="text-end">Запрошено</th>', 'storefront')
        . __('<th class="text-end">Свободно</th><th class="text-end">К выдаче</th>', 'storefront')
        . __('<th class="text-end">Выдано</th><th>Причина изменения</th></tr></thead><tbody>', 'storefront');

    foreach ($o->lines() as $lid => $l) {
        $free = 0;
        $pid = (int) $l['plugin_storefront_products_id'];
        if ($pid > 0 && $wid > 0) {
            $free = Stock::ensure($pid, $wid)->free();
        }
        $editable = $canEdit && in_array($o->state(), [Order::QUEUE, Order::APPROVED], true);
        printf(
            '<tr><td>%s</td><td>%s</td><td class="text-end">%d</td>'
            . '<td class="text-end%s">%d</td><td class="text-end">%s</td>'
            . '<td class="text-end">%d</td><td>%s</td></tr>',
            $esc((string) $l['name_snapshot']),
            $esc((string) $l['unit_snapshot']),
            (int) $l['qty_requested'],
            $free < (int) $l['qty_requested'] ? ' text-danger fw-bold' : '',
            $free,
            $editable
                ? sprintf('<input type="number" min="0" max="%d" name="qty[%d]" value="%d" '
                    . 'class="form-control form-control-sm text-end" style="max-width:90px;'
                    . 'display:inline-block">',
                    (int) $l['qty_requested'], (int) $lid,
                    (int) ($l['qty_approved'] ?: $l['qty_requested']))
                : (int) $l['qty_approved'],
            (int) $l['qty_issued'],
            $editable
                ? sprintf('<input type="text" name="reason[%d]" value="%s" '
                    . __('class="form-control form-control-sm" placeholder="обязательна при уменьшении">', 'storefront'),
                    (int) $lid, $esc((string) $l['change_reason']))
                : $esc((string) $l['change_reason'])
        );
    }
    echo '</tbody></table></div>';

    echo __('<div class="alert alert-info py-2 small">Увеличить сверх запрошенного нельзя. ', 'storefront')
        . __('При уменьшении причина обязательна — она попадёт в накладную и в ленту заявки.</div>', 'storefront');

    if ($canEdit) {
        echo '<div class="d-flex gap-2 flex-wrap justify-content-end">';
        if (in_array($o->state(), [Order::QUEUE, Order::APPROVED], true)) {
            echo '<button class="btn btn-primary" name="approve_qty" value="1">'
                . __('Утвердить количества</button>', 'storefront');
        }
        if ($o->state() === Order::APPROVED) {
            echo '<button class="btn btn-outline-primary" name="mark_ready" value="1">'
                . __('Готово к получению</button>', 'storefront');
        }
        if (in_array($o->state(), [Order::APPROVED, Order::READY], true)) {
            echo '<input type="text" name="waybill_no" class="form-control form-control-sm" '
                . __('style="max-width:180px" placeholder="№ накладной">', 'storefront');
            echo '<button class="btn btn-success" name="issue" value="1">'
                . __('Выдать и списать</button>', 'storefront');
        }
        if ($o->isOpen()) {
            echo '<input type="text" name="cancel_reason" class="form-control form-control-sm" '
                . __('style="max-width:200px" placeholder="причина отмены">', 'storefront');
            echo '<button class="btn btn-outline-danger" name="cancel_order" value="1">'
                . __('Отменить заказ</button>', 'storefront');
        }
        echo '</div>';
    }
    echo '</form></div></div>';
}

echo '</div>';
Html::footer();
