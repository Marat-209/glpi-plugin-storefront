<?php

/**
 * Аналитика витрины.
 *
 * Отвечает на вопросы, которые задают о канцелярии: сколько ушло за период,
 * что берут чаще всего, кто получает, за сколько склад успевает выдать, что
 * пора закупать и как расходуются лимиты.
 */

use GlpiPlugin\Storefront\Analytics;
use GlpiPlugin\Storefront\Catalog;
use GlpiPlugin\Storefront\Order;

Session::checkRight('plugin_storefront_order', READ);

Html::header(
    __('Аналитика магазина', 'storefront'),
    $_SERVER['PHP_SELF'],
    'management',
    Catalog::class,
    'analytics'
);

$esc = static fn(?string $v): string => htmlescape((string) $v);
$self = Plugin::getWebDir('storefront') . '/front/analytics.php';

$catalogs = [];
foreach ((new Catalog())->find(['is_active' => 1], ['name ASC']) as $id => $row) {
    $c = new Catalog();
    $c->fields = $row;
    if ($c->isVisibleHere()) {
        $catalogs[(int) $id] = (string) $row['name'];
    }
}

if (!count($catalogs)) {
    echo '<div class="container-fluid mt-3"><div class="alert alert-info">'
        . __('Витрин, доступных в этом подразделении, нет.</div></div>', 'storefront');
    Html::footer();
    return;
}

$catalogs_id = (int) ($_GET['catalog'] ?? array_key_first($catalogs));
if (!isset($catalogs[$catalogs_id])) {
    $catalogs_id = (int) array_key_first($catalogs);
}
$from = (string) ($_GET['from'] ?? date('Y-m-01', strtotime('-5 months')));
$to = (string) ($_GET['to'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-01', strtotime('-5 months'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}

$money = static fn(float $v): string => number_format($v, 2, ',', ' ');
$num = static fn(int $v): string => number_format($v, 0, ',', ' ');

$totals = Analytics::issuedTotals($catalogs_id, $from, $to);
$states = Analytics::ordersByState($catalogs_id, $from, $to);
$top = Analytics::topProducts($catalogs_id, $from, $to);
$recipients = Analytics::byRecipient($catalogs_id, $from, $to);
$months = Analytics::byMonth($catalogs_id, $from, $to);
$lead = Analytics::leadTime($catalogs_id, $from, $to);
$low = Analytics::lowStock($catalogs_id);
$limits = Analytics::limitUsage($catalogs_id);

$catalog = new Catalog();
$catalog->getFromDB($catalogs_id);
$showMoney = $catalog->showsPrices();

echo '<div class="container-fluid mt-3">';
echo __('<h2 class="mb-3">Аналитика магазина</h2>', 'storefront');

/* ---------------------------------------------------------------- фильтр */
echo '<form method="get" action="' . $esc($self) . '" class="card mb-3"><div class="card-body">';
echo '<div class="row g-2 align-items-end">';
echo __('<div class="col-12 col-md-4"><label class="form-label">Витрина</label>', 'storefront');
echo '<select name="catalog" class="form-select">';
foreach ($catalogs as $id => $name) {
    printf('<option value="%d"%s>%s</option>', $id,
        $id === $catalogs_id ? ' selected' : '', $esc($name));
}
echo '</select></div>';
printf(__('<div class="col-6 col-md-3"><label class="form-label">С даты</label>', 'storefront')
    . '<input type="date" name="from" class="form-control" value="%s"></div>', $esc($from));
printf(__('<div class="col-6 col-md-3"><label class="form-label">По дату</label>', 'storefront')
    . '<input type="date" name="to" class="form-control" value="%s"></div>', $esc($to));
echo __('<div class="col-12 col-md-2"><button class="btn btn-primary w-100">Показать</button></div>', 'storefront');
echo '</div></div></form>';

/* ---------------------------------------------------------------- сводка */
$cards = [
    [__('Выдано заказов', 'storefront'), $num((int) $totals['orders']), 'ti ti-package-export'],
    [__('Выдано единиц', 'storefront'), $num((int) $totals['qty']), 'ti ti-stack-2'],
];
if ($showMoney) {
    // Знак валюты — через каталог: в английском интерфейсе рубль не к месту.
    $cards[] = [__('Стоимость выданного', 'storefront'),
        sprintf(__('%s ₽', 'storefront'), $money((float) $totals['sum'])), 'ti ti-cash'];
}
$cards[] = [
    __('Срок выдачи, медиана', 'storefront'),
    $lead['count'] > 0 ? number_format($lead['median'], 1, ',', ' ') . __(' ч', 'storefront') : '—',
    'ti ti-clock-hour-4',
];

echo '<div class="row g-3 mb-3">';
foreach ($cards as [$label, $value, $icon]) {
    printf(
        '<div class="col-12 col-sm-6 col-xl-3"><div class="card h-100"><div class="card-body">'
        . '<div class="d-flex align-items-center gap-2 text-muted small">'
        . '<i class="%s"></i>%s</div>'
        . '<div class="fs-2 fw-bold mt-1">%s</div></div></div></div>',
        $esc($icon), $esc($label), $esc($value)
    );
}
echo '</div>';

/* ---------------------------------------------------------------- динамика */
echo '<div class="row g-3">';
echo '<div class="col-12 col-xl-8"><div class="card h-100"><div class="card-body">';
echo __('<div class="fw-bold mb-2">Расход по месяцам</div>', 'storefront');
if (!count($months)) {
    echo __('<div class="text-muted">За выбранный период выдач не было.</div>', 'storefront');
} else {
    $maxQty = 1;
    foreach ($months as $m) {
        $maxQty = max($maxQty, (int) $m['qty']);
    }
    // Столбики рисуем разметкой, а не картинкой: страница печатается и
    // открывается без внешних библиотек.
    echo '<div class="d-flex align-items-end gap-2" style="height:180px">';
    foreach ($months as $m) {
        $h = (int) round((int) $m['qty'] / $maxQty * 150);
        printf(
            __('<div class="flex-fill text-center" title="%s: %s ед.">', 'storefront')
            . '<div class="mx-auto rounded-top" style="width:70%%;height:%dpx;'
            . 'background:var(--tblr-primary)"></div>'
            . '<div class="small text-muted mt-1">%s</div>'
            . '<div class="small fw-bold">%s</div></div>',
            $esc($m['month']), $esc($num((int) $m['qty'])), max(2, $h),
            $esc(substr((string) $m['month'], 5, 2) . '.' . substr((string) $m['month'], 2, 2)),
            $esc($num((int) $m['qty']))
        );
    }
    echo '</div>';
}
echo '</div></div></div>';

/* ---------------------------------------------------------------- состояния */
echo '<div class="col-12 col-xl-4"><div class="card h-100"><div class="card-body">';
echo __('<div class="fw-bold mb-2">Заказы за период</div>', 'storefront');
$totalOrders = array_sum($states);
if (!$totalOrders) {
    echo __('<div class="text-muted">Заказов не было.</div>', 'storefront');
} else {
    echo '<table class="table table-sm mb-0"><tbody>';
    foreach ([Order::ISSUED, Order::READY, Order::APPROVED, Order::QUEUE,
        Order::APPROVAL, Order::REJECTED, Order::CANCELLED, Order::DRAFT] as $state) {
        $n = (int) ($states[$state] ?? 0);
        if ($n === 0) {
            continue;
        }
        printf('<tr><td><span class="badge bg-%s-lt">%s</span></td>'
            . '<td class="text-end fw-bold">%s</td>'
            . '<td class="text-end text-muted">%d%%</td></tr>',
            Order::stateTone($state), $esc(Order::stateLabel($state)), $esc($num($n)),
            (int) round($n / $totalOrders * 100));
    }
    printf(__('<tr><td class="fw-bold">Всего</td><td class="text-end fw-bold">%s</td><td></td></tr>', 'storefront'),
        $esc($num($totalOrders)));
    echo '</tbody></table>';
}
echo '</div></div></div>';
echo '</div>';

/* ---------------------------------------------------------------- топ и получатели */
echo '<div class="row g-3 mt-1">';

echo '<div class="col-12 col-xl-6"><div class="card h-100"><div class="card-body">';
echo __('<div class="fw-bold mb-2">Что берут чаще всего</div>', 'storefront');
if (!count($top)) {
    echo __('<div class="text-muted">Нет данных за период.</div>', 'storefront');
} else {
    $maxTop = max(array_column($top, 'qty')) ?: 1;
    echo '<table class="table table-sm align-middle mb-0"><thead><tr>'
        . __('<th>Позиция</th><th class="text-end">Выдано</th>', 'storefront')
        . ($showMoney ? __('<th class="text-end">Сумма, ₽</th>', 'storefront') : '') . '</tr></thead><tbody>';
    foreach ($top as $row) {
        printf('<tr><td>%s<div class="progress mt-1" style="height:4px">'
            . '<div class="progress-bar" style="width:%d%%"></div></div></td>'
            . '<td class="text-end fw-bold">%s <span class="text-muted small">%s</span></td>%s</tr>',
            $esc($row['name']),
            (int) round($row['qty'] / $maxTop * 100),
            $esc($num((int) $row['qty'])), $esc($row['unit']),
            $showMoney ? '<td class="text-end">' . $esc($money((float) $row['sum'])) . '</td>' : '');
    }
    echo '</tbody></table>';
}
echo '</div></div></div>';

echo '<div class="col-12 col-xl-6"><div class="card h-100"><div class="card-body">';
echo __('<div class="fw-bold mb-2">Кто получает</div>', 'storefront');
if (!count($recipients)) {
    echo __('<div class="text-muted">Нет данных за период.</div>', 'storefront');
} else {
    echo __('<table class="table table-sm mb-0"><thead><tr><th>Получатель</th>', 'storefront')
        . __('<th class="text-end">Заказов</th></tr></thead><tbody>', 'storefront');
    foreach ($recipients as $row) {
        printf('<tr><td>%s</td><td class="text-end fw-bold">%s</td></tr>',
            $esc($row['label']), $esc($num((int) $row['orders'])));
    }
    echo '</tbody></table>';
}
echo '</div></div></div>';
echo '</div>';

/* ---------------------------------------------------------------- срок и закупка */
echo '<div class="row g-3 mt-1">';

echo '<div class="col-12 col-xl-4"><div class="card h-100"><div class="card-body">';
echo __('<div class="fw-bold mb-2">Сколько ждут выдачи</div>', 'storefront');
if ($lead['count'] === 0) {
    echo __('<div class="text-muted">Выданных заказов за период нет.</div>', 'storefront');
} else {
    printf('<table class="table table-sm mb-0"><tbody>'
        . __('<tr><td>Медиана</td><td class="text-end fw-bold">%s ч</td></tr>', 'storefront')
        . __('<tr><td>Среднее</td><td class="text-end">%s ч</td></tr>', 'storefront')
        . __('<tr><td>Худший случай</td><td class="text-end">%s ч</td></tr>', 'storefront')
        . __('<tr><td>Заказов в расчёте</td><td class="text-end">%d</td></tr>', 'storefront')
        . '</tbody></table>',
        $esc(number_format($lead['median'], 1, ',', ' ')),
        $esc(number_format($lead['avg'], 1, ',', ' ')),
        $esc(number_format($lead['max'], 1, ',', ' ')),
        (int) $lead['count']);
    echo __('<div class="text-muted small mt-2">От отправки заказа сотрудником ', 'storefront')
        . __('до выдачи со склада, в часах календарного времени.</div>', 'storefront');
}
echo '</div></div></div>';

echo '<div class="col-12 col-xl-8"><div class="card h-100"><div class="card-body">';
echo __('<div class="fw-bold mb-2">Пора закупать</div>', 'storefront');
if (!count($low)) {
    echo __('<div class="text-muted">Позиций ниже порога нет.</div>', 'storefront');
} else {
    echo __('<table class="table table-sm mb-0"><thead><tr><th>Позиция</th>', 'storefront')
        . __('<th class="text-end">Свободно</th><th class="text-end">На складах</th>', 'storefront')
        . __('<th class="text-end">Порог</th></tr></thead><tbody>', 'storefront');
    foreach ($low as $row) {
        printf('<tr><td>%s</td><td class="text-end fw-bold text-%s">%s %s</td>'
            . '<td class="text-end">%s</td><td class="text-end text-muted">%s</td></tr>',
            $esc($row['name']),
            $row['free'] <= 0 ? 'danger' : 'warning',
            $esc($num((int) $row['free'])), $esc($row['unit']),
            $esc($num((int) $row['hand'])), $esc($num((int) $row['threshold'])));
    }
    echo '</tbody></table>';
}
echo '</div></div></div>';
echo '</div>';

/* ---------------------------------------------------------------- лимиты */
echo '<div class="card mt-3"><div class="card-body">';
echo __('<div class="fw-bold mb-2">Лимиты витрины</div>', 'storefront');
if (!count($limits)) {
    echo __('<div class="text-muted">Лимиты не настроены.</div>', 'storefront');
} else {
    echo __('<table class="table table-sm align-middle mb-0"><thead><tr><th>Правило</th>', 'storefront')
        . __('<th>Норма</th><th>Период</th><th class="text-end">Предел</th>', 'storefront')
        . __('<th class="text-end">Израсходовано</th><th class="text-end">Людей</th>', 'storefront')
        . __('<th>Вид</th></tr></thead><tbody>', 'storefront');
    foreach ($limits as $row) {
        printf('<tr><td>%s</td><td>%s</td><td>%s</td><td class="text-end">%s</td>'
            . '<td class="text-end fw-bold">%s</td><td class="text-end">%s</td>'
            . '<td><span class="badge bg-%s-lt">%s</span></td></tr>',
            $esc($row['name']),
            $esc((string) ($row['pool'] ?? '')),
            $esc($row['period']), $esc($num((int) $row['max'])),
            $esc($num((int) $row['used'])),
            ($row['shared'] ?? false) ? '—' : $num((int) $row['people']),
            $row['is_hard'] ? 'red' : 'yellow',
            $row['is_hard'] ? __('жёсткий', 'storefront') : __('мягкий', 'storefront'));
    }
    echo '</tbody></table>';
    echo __('<div class="text-muted small mt-2">Для личных норм израсходовано — сумма по ', 'storefront')
        . __('сотрудникам, которые заказывали за последние три месяца, а предел указан на ', 'storefront')
        . __('одного получателя. Для общих норм показан один запас области целиком, ', 'storefront')
        . __('поэтому графа «людей» для них не заполняется.</div>', 'storefront');
}
echo '</div></div>';

echo '</div>';

Html::footer();
