<?php

/**
 * Печатная накладная на выдачу.
 *
 * Отдельная страница без интерфейса GLPI: её печатают и подшивают. Реквизиты
 * и состав графов задаются на витрине, потому что у разных складов свои бланки.
 * Смотреть накладную вправе тот, кто ведёт заказ, и сам заказчик — своей.
 */

use GlpiPlugin\Storefront\Order;

Session::checkLoginUser();

$id = (int) ($_GET['id'] ?? 0);
$order = new Order();
if ($id <= 0 || !$order->getFromDB($id)) {
    Html::displayNotFoundError();
}

$me = (int) (Session::getLoginUserID() ?: 0);
$isOwner = $me > 0 && $me === (int) $order->fields['users_id_requester'];
$mayRead = Session::haveRight('plugin_storefront_order', READ)
    && Session::haveAccessToEntity((int) $order->fields['entities_id'], true);
if (!$isOwner && !$mayRead) {
    Html::displayRightError();
}

// Язык страницы — как у пользователя: браузер переносит и печатает
// по правилам того языка, который объявлен в разметке.
$lang = substr((string) ($_SESSION['glpilanguage'] ?? 'ru_RU'), 0, 2);

if ($order->state() !== Order::ISSUED) {
    // Накладная — документ о совершённой выдаче. Печатать её раньше выдачи
    // нельзя: количества ещё могут измениться, а бланк уже уйдёт в дело.
    // Страница ошибки GLPI своего текста не показывает, поэтому объясняем сами.
    http_response_code(409);
    header('Content-Type: text/html; charset=UTF-8');
    printf(
        '<!DOCTYPE html><html lang="' . $lang . '"><head><meta charset="UTF-8">'
        . __('<title>Накладная ещё не готова</title></head>', 'storefront')
        . '<body style="font:14px/1.5 Arial,Helvetica,sans-serif;margin:0;padding:40px;'
        . 'color:#111;background:#fff">'
        . __('<h1 style="font-size:20px;margin:0 0 12px">Накладная печатается по факту выдачи</h1>', 'storefront')
        . __('<p style="margin:0 0 8px">Заказ №%d пока в состоянии «%s». Пока заказ ', 'storefront')
        . __('не выдан, количества могут измениться, а накладная — это документ ', 'storefront')
        . __('о состоявшейся выдаче.</p>', 'storefront')
        . __('<p style="margin:0 0 20px">Проведите выдачу в очереди склада, и кнопка ', 'storefront')
        . __('печати появится там же.</p>', 'storefront')
        . __('<p><a href="%s/front/queue.php">К очереди выдачи</a></p>', 'storefront')
        . '</body></html>',
        $id,
        htmlescape(Order::stateLabel($order->state())),
        htmlescape(Plugin::getWebDir('storefront'))
    );
    return;
}

$catalog = $order->getCatalog();
if ($catalog === null) {
    Html::displayErrorAndDie(__('Витрина заказа не найдена.', 'storefront'));
}

$showPrices = $catalog->waybillShowsPrices();
$showRequested = $catalog->waybillShowsRequested();
$warehouse = $order->getWarehouse();

$lines = [];
$total = 0.0;
$totalQty = 0;
foreach ($order->lines() as $l) {
    $qty = (int) $l['qty_issued'] ?: (int) $l['qty_approved'];
    if ($qty <= 0) {
        continue;
    }
    $price = (float) $l['price_snapshot'];
    $sum = $price * $qty;
    $total += $sum;
    $totalQty += $qty;
    $lines[] = [
        'name'      => (string) $l['name_snapshot'],
        'unit'      => (string) $l['unit_snapshot'],
        'requested' => (int) $l['qty_requested'],
        'qty'       => $qty,
        'price'     => $price,
        'sum'       => $sum,
        'note'      => (string) $l['change_reason'],
    ];
}

/** Деньги по-русски: разряды пробелом, копейки через запятую. */
$money = static fn(float $v): string => number_format($v, 2, ',', ' ');

/** Дата документа человеческим видом. */
$dateHuman = static function (?string $raw): string {
    $ts = strtotime((string) $raw);
    if (!$ts) {
        return '—';
    }
    $months = ['', __('января', 'storefront'), __('февраля', 'storefront'), __('марта', 'storefront'), __('апреля', 'storefront'), __('мая', 'storefront'), __('июня', 'storefront'),
        __('июля', 'storefront'), __('августа', 'storefront'), __('сентября', 'storefront'), __('октября', 'storefront'), __('ноября', 'storefront'), __('декабря', 'storefront')];
    return sprintf(__('«%s» %s %s г.', 'storefront'), date('d', $ts), $months[(int) date('n', $ts)], date('Y', $ts));
};

$esc = static fn(?string $v): string => htmlescape((string) $v);

$recipient = $order->recipientLabel();
$requester = getUserName((int) $order->fields['users_id_requester']);
$issuer = $catalog->waybillSignatory();

header('Content-Type: text/html; charset=UTF-8');
?><!DOCTYPE html>
<html lang="<?= $esc($lang) ?>">
<head>
<meta charset="UTF-8">
<title><?= $esc(sprintf(__('Накладная № %s', 'storefront'), $order->waybillNumber())) ?></title>
<style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        padding: 16mm 14mm;
        background: #fff;
        color: #111;
        font: 11pt/1.45 "PT Astra Serif", "Times New Roman", Georgia, serif;
    }
    .toolbar {
        display: flex; gap: 8px; justify-content: flex-end;
        margin-bottom: 8mm; font-family: Arial, Helvetica, sans-serif; font-size: 10pt;
    }
    .toolbar button, .toolbar a {
        padding: 6px 14px; border: 1px solid #888; background: #f4f4f4;
        color: #111; text-decoration: none; border-radius: 3px; cursor: pointer;
    }
    .org { font-size: 10pt; margin-bottom: 6mm; }
    h1 {
        font-size: 14pt; text-align: center; margin: 0 0 2mm;
        text-transform: uppercase; letter-spacing: .02em;
    }
    .num { text-align: center; margin-bottom: 6mm; font-size: 11pt; }
    .facts { width: 100%; border-collapse: collapse; margin-bottom: 5mm; font-size: 10.5pt; }
    .facts td { padding: 1.2mm 0; vertical-align: top; }
    .facts td:first-child { width: 42mm; color: #444; }
    table.items { width: 100%; border-collapse: collapse; margin-bottom: 6mm; font-size: 10.5pt; }
    table.items th, table.items td { border: 1px solid #333; padding: 1.6mm 2mm; }
    table.items th { background: #f0f0f0; font-weight: bold; text-align: center; font-size: 10pt; }
    table.items td.n { text-align: right; white-space: nowrap; }
    table.items td.c { text-align: center; white-space: nowrap; }
    table.items tfoot td { font-weight: bold; background: #f7f7f7; }
    .signs { width: 100%; border-collapse: collapse; margin-top: 10mm; font-size: 10.5pt; }
    .signs td { width: 50%; padding: 0 6mm 0 0; vertical-align: top; }
    .line { border-bottom: 1px solid #333; height: 7mm; margin-bottom: 1.5mm; }
    .cap { font-size: 8.5pt; color: #555; }
    .footer { margin-top: 8mm; font-size: 9.5pt; color: #333; }
    .note { font-size: 9pt; color: #555; }
    @media print {
        body { padding: 0; }
        .toolbar { display: none; }
        table.items th { background: #eee !important; -webkit-print-color-adjust: exact; }
    }
</style>
</head>
<body>

<div class="toolbar">
    <button type="button" onclick="window.print()"><?= $esc(__('Печать', 'storefront')) ?></button>
    <a href="<?= $esc(Plugin::getWebDir('storefront')) ?>/front/queue.php"><?= $esc(__('К очереди выдачи', 'storefront')) ?></a>
</div>

<div class="org"><?= $esc($catalog->waybillOrg()) ?></div>

<h1><?= $esc($catalog->waybillTitle()) ?></h1>
<div class="num">
    <?= $esc(sprintf(__('№ %s от %s', 'storefront'),
        $order->waybillNumber(), $dateHuman($order->fields['date_issued']))) ?>
</div>

<table class="facts">
    <tr>
        <td><?= $esc(__('Кому выдано:', 'storefront')) ?></td>
        <td><strong><?= $esc($recipient) ?></strong></td>
    </tr>
    <?php if ($recipient !== $requester) { ?>
    <tr>
        <td><?= $esc(__('Заказ оформил:', 'storefront')) ?></td>
        <td><?= $esc($requester) ?></td>
    </tr>
    <?php } ?>
    <tr>
        <td><?= $esc(__('Основание:', 'storefront')) ?></td>
        <td>
            <?= $esc(sprintf(__('заказ № %d', 'storefront'), $order->getID())) ?>
            <?php if ((int) $order->fields['items_id'] > 0) { ?>
                <?= $esc(sprintf(__(', заявка № %d', 'storefront'),
                    (int) $order->fields['items_id'])) ?>
            <?php } ?>
        </td>
    </tr>
    <tr>
        <td><?= $esc(__('Склад выдачи:', 'storefront')) ?></td>
        <td><?= $esc($warehouse !== null ? $warehouse->fields['name'] : '—') ?></td>
    </tr>
    <?php if (trim((string) $order->fields['comment']) !== '') { ?>
    <tr>
        <td><?= $esc(__('Назначение:', 'storefront')) ?></td>
        <td><?= $esc($order->fields['comment']) ?></td>
    </tr>
    <?php } ?>
</table>

<table class="items">
    <thead>
        <tr>
            <th style="width:10mm">№</th>
            <th><?= $esc(__('Наименование', 'storefront')) ?></th>
            <th style="width:16mm"><?= $esc(__('Ед.', 'storefront')) ?></th>
            <?php if ($showRequested) { ?><th style="width:20mm"><?= $esc(__('Заказано', 'storefront')) ?></th><?php } ?>
            <th style="width:20mm"><?= $esc(__('Выдано', 'storefront')) ?></th>
            <?php if ($showPrices) { ?>
            <th style="width:24mm"><?= $esc(__('Цена', 'storefront')) ?></th>
            <th style="width:26mm"><?= $esc(__('Сумма', 'storefront')) ?></th>
            <?php } ?>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($lines as $i => $row) { ?>
        <tr>
            <td class="c"><?= $i + 1 ?></td>
            <td>
                <?= $esc($row['name']) ?>
                <?php if (trim($row['note']) !== '') { ?>
                    <div class="note"><?= $esc(sprintf(
                        __('Выдано меньше: %s', 'storefront'), $row['note'])) ?></div>
                <?php } ?>
            </td>
            <td class="c"><?= $esc($row['unit']) ?></td>
            <?php if ($showRequested) { ?><td class="n"><?= $row['requested'] ?></td><?php } ?>
            <td class="n"><strong><?= $row['qty'] ?></strong></td>
            <?php if ($showPrices) { ?>
            <td class="n"><?= $esc($money($row['price'])) ?></td>
            <td class="n"><?= $esc($money($row['sum'])) ?></td>
            <?php } ?>
        </tr>
    <?php } ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="<?= $showRequested ? 4 : 3 ?>"><?= $esc(__('Итого', 'storefront')) ?></td>
            <td class="n"><?= $totalQty ?></td>
            <?php if ($showPrices) { ?>
            <td></td>
            <td class="n"><?= $esc($money($total)) ?></td>
            <?php } ?>
        </tr>
    </tfoot>
</table>

<?php if ($showPrices) { ?>
<?php printf(__('<div>Всего отпущено на сумму <strong>%s ₽</strong></div>', 'storefront'),
    $esc($money($total))); ?>
<?php } ?>
<?php printf(__('<div>Всего наименований <strong>%d</strong>, единиц <strong>%d</strong></div>',
    'storefront'), count($lines), $totalQty); ?>

<table class="signs">
    <tr>
        <td>
            <div class="cap"><?= $esc(__('Выдал', 'storefront'))
                . ($issuer !== '' ? ' (' . $esc($issuer) . ')' : '') ?></div>
            <div class="line"></div>
            <div class="cap"><?= $esc(__('подпись, расшифровка', 'storefront')) ?></div>
        </td>
        <td>
            <div class="cap"><?= $esc(__('Получил', 'storefront')) ?></div>
            <div class="line"></div>
            <div class="cap"><?= $esc(__('подпись, расшифровка', 'storefront')) ?></div>
        </td>
    </tr>
</table>

<?php if ($catalog->waybillFooter() !== '') { ?>
<div class="footer"><?= nl2br($esc($catalog->waybillFooter())) ?></div>
<?php } ?>

<?php if ((int) ($_GET['print'] ?? 0) === 1) { ?>
<script>window.addEventListener('load', function () { window.print(); });</script>
<?php } ?>

</body>
</html>
