<?php

/**
 * Выгрузка позиций витрины в CSV и Excel.
 *
 * Строится тот же список, что и на вкладке «Позиции», плюс разбивка остатка
 * по складам: такую таблицу можно сразу отдать на инвентаризацию или закупку.
 */

use GlpiPlugin\Storefront\Catalog;
use GlpiPlugin\Storefront\Product;
use GlpiPlugin\Storefront\Stock;
use GlpiPlugin\Storefront\Warehouse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

Session::checkRight('plugin_storefront_catalog', READ);

$catalogs_id = (int) ($_GET['catalog'] ?? 0);
$format = (string) ($_GET['format'] ?? 'csv');

$catalog = new Catalog();
if ($catalogs_id <= 0
    || !$catalog->getFromDB($catalogs_id)
    || !$catalog->can($catalogs_id, READ)
    || !Session::haveAccessToEntity((int) $catalog->fields['entities_id'],
        (int) $catalog->fields['is_recursive'] === 1)) {
    Html::displayRightError();
}

/* ------------------------------------------------------------ данные */
$warehouses = (new Warehouse())->find(
    ['plugin_storefront_catalogs_id' => $catalogs_id],
    ['is_default DESC', 'name ASC']
);

$header = [__('Позиция', 'storefront'), __('Артикул', 'storefront'), __('Категория', 'storefront'), __('Единица', 'storefront'), __('Цена', 'storefront'), __('Учёт', 'storefront'), __('Активна', 'storefront')];
foreach ($warehouses as $w) {
    $header[] = $w['name'] . __(': на руках', 'storefront');
    $header[] = $w['name'] . __(': резерв', 'storefront');
    $header[] = $w['name'] . __(': свободно', 'storefront');
}
$header[] = __('Всего свободно', 'storefront');
$header[] = __('Порог оповещения', 'storefront');
$header[] = __('Целевой запас', 'storefront');
$header[] = __('Нужно докупить', 'storefront');

$rows = [];
foreach ((new Product())->find(
    ['plugin_storefront_catalogs_id' => $catalogs_id],
    ['ranking ASC', 'name ASC']
) as $id => $r) {
    $p = new Product();
    if (!$p->getFromDB((int) $id)) {
        continue;
    }
    $line = [
        $p->label(),
        $p->ref(),
        $p->categoryName(),
        (string) $r['unit'],
        $p->price(),
        $p->isQuantity() ? __('количественный', 'storefront') : __('экземплярный', 'storefront'),
        (int) $r['is_active'] ? __('да', 'storefront') : __('нет', 'storefront'),
    ];

    $freeTotal = 0;
    $threshold = 0;
    $target = 0;
    foreach ($warehouses as $wid => $w) {
        $stock = Stock::ensure((int) $id, (int) $wid, (int) $r['entities_id']);
        $free = $stock->free();
        $freeTotal += $free;
        $threshold = max($threshold, $p->thresholdFrom($stock));
        $target = max($target, $p->targetFrom($stock));
        $line[] = (int) $stock->fields['qty_on_hand'];
        $line[] = (int) $stock->fields['qty_reserved'];
        $line[] = $free;
    }
    $line[] = $freeTotal;
    $line[] = $threshold > 0 ? $threshold : '';
    $line[] = $target > 0 ? $target : '';
    // Докупить нужно только то, что просело ниже порога, — до целевого запаса.
    $line[] = ($threshold > 0 && $freeTotal < $threshold && $target > $freeTotal)
        ? $target - $freeTotal
        : '';

    $rows[] = $line;
}

$stamp = date('Y-m-d');
$base = 'storefront-' . $catalogs_id . '-' . $stamp;

/* ------------------------------------------------------------ CSV */
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $base . '.csv"');
    $out = fopen('php://output', 'w');
    // Метка порядка байтов: без неё Excel открывает кириллицу как «Ð Ñ‡Ð°».
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header, ';');
    foreach ($rows as $line) {
        // Десятичная запятая: русский Excel иначе не считает столбец числовым.
        $line[4] = number_format((float) $line[4], 2, ',', '');
        fputcsv($out, $line, ';');
    }
    fclose($out);
    exit;
}

/* ------------------------------------------------------------ Excel */
$spread = new Spreadsheet();
$sheet = $spread->getActiveSheet();
$sheet->setTitle(mb_substr(__('Позиции витрины', 'storefront'), 0, 31));

$sheet->fromArray([$header], null, 'A1');
if (count($rows)) {
    $sheet->fromArray($rows, null, 'A2');
}

$lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($header));
$sheet->getStyle('A1:' . $lastCol . '1')->getFont()->setBold(true);
$sheet->getStyle('A1:' . $lastCol . '1')->getAlignment()
    ->setWrapText(true)
    ->setVertical(Alignment::VERTICAL_CENTER);
$sheet->getRowDimension(1)->setRowHeight(30);
for ($i = 1; $i <= count($header); $i++) {
    $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
}
if (count($rows)) {
    // Код формата пишется в английской нотации, разделители Excel подставит
    // по языку пользователя. Записать сюда пробел и запятую напрямую нельзя:
    // «# ##0,00» превращает 12 в «0 012,00».
    $sheet->getStyle('E2:E' . (count($rows) + 1))->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->setAutoFilter('A1:' . $lastCol . (count($rows) + 1));
}
$sheet->freezePane('A2');

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $base . '.xlsx"');
header('Cache-Control: max-age=0');
(new Xlsx($spread))->save('php://output');
exit;
