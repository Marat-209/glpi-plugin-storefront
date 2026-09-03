<?php

/**
 * Отчёт по выдачам: период, товар, сотрудник, отдел. Выгрузка в CSV.
 */

use GlpiPlugin\Storefront\Catalog;
use GlpiPlugin\Storefront\Movement;
use GlpiPlugin\Storefront\Order;
use GlpiPlugin\Storefront\Product;

Session::checkRight('plugin_storefront_order', READ);

global $DB;

$from = (string) ($_GET['from'] ?? date('Y-m-01'));
$to   = (string) ($_GET['to'] ?? date('Y-m-d'));
$catalogs_id = (int) ($_GET['catalog'] ?? 0);
$groupby = (string) ($_GET['groupby'] ?? 'product');

/** Собрать строки отчёта по выданному. */
$build = static function (string $from, string $to, int $catalogs_id, string $groupby) use ($DB): array {
    // Отчёт считает движения — их тоже надо ограничить подразделениями,
    // иначе сводка по выдачам собирает весь GLPI.
    $where = [
        'm.type' => Movement::OUT,
        ['m.date' => ['>=', $from . ' 00:00:00']],
        ['m.date' => ['<=', $to . ' 23:59:59']],
    // Движение принадлежит одному складу и одному подразделению: колонки
    // is_recursive у него нет, поэтому «наследуемое» ограничение здесь просить
    // нельзя — запрос сошлётся на несуществующее поле и страница упадёт.
    ] + getEntitiesRestrictCriteria('m', '', '', false);
    $rows = [];
    foreach ($DB->request([
        'SELECT' => ['m.qty', 'm.unit_price', 'm.users_id_recipient', 'm.entities_id',
            'm.groups_id_recipient', 'm.entities_id_recipient',
            'm.plugin_storefront_products_id', 'm.plugin_storefront_orders_id'],
        'FROM'   => Movement::getTable() . ' AS m',
        'WHERE'  => $where,
    ]) as $m) {
        $p = new Product();
        if (!$p->getFromDB((int) $m['plugin_storefront_products_id'])) {
            continue;
        }
        if ($catalogs_id > 0
            && (int) $p->fields['plugin_storefront_catalogs_id'] !== $catalogs_id) {
            continue;
        }
        switch ($groupby) {
            case 'user':
                $key = 'u' . (int) $m['users_id_recipient'];
                $label = (int) $m['users_id_recipient'] > 0
                    ? getUserName((int) $m['users_id_recipient']) : __('не указан', 'storefront');
                break;
            case 'group':
                // Личные заказы в разрезе отделов показываем по отделу
                // получателя, а не по всем его группам сразу: иначе один
                // и тот же расход попал бы в несколько строк отчёта.
                $g = (int) $m['groups_id_recipient'];
                $key = 'g' . $g;
                $label = $g > 0
                    ? Dropdown::getDropdownName('glpi_groups', $g)
                    : __('личные заказы', 'storefront');
                break;
            case 'entity':
                // Подразделение получателя, если заказ был на подразделение;
                // иначе — подразделение, в котором оформлен заказ.
                $e = (int) $m['entities_id_recipient'] ?: (int) $m['entities_id'];
                $key = 'e' . $e;
                $label = Dropdown::getDropdownName('glpi_entities', $e);
                break;
            case 'category':
                $key = 'c' . $p->categoryId();
                $label = $p->categoryName() ?: __('без категории', 'storefront');
                break;
            default:
                $key = 'p' . $p->getID();
                $label = $p->label();
        }
        if (!isset($rows[$key])) {
            $rows[$key] = ['label' => $label, 'qty' => 0, 'amount' => 0.0, 'orders' => []];
        }
        $rows[$key]['qty'] += abs((int) $m['qty']);
        $rows[$key]['amount'] += abs((int) $m['qty']) * (float) $m['unit_price'];
        $rows[$key]['orders'][(int) $m['plugin_storefront_orders_id']] = true;
    }
    foreach ($rows as $k => $r) {
        $rows[$k]['orders'] = count($r['orders']);
    }
    uasort($rows, static fn($a, $b) => $b['qty'] <=> $a['qty']);
    return $rows;
};

$rows = $build($from, $to, $catalogs_id, $groupby);

/* ------------------------------------------------------------ выгрузка */
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="storefront-report-'
        . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM, чтобы Excel открыл кириллицу
    fputcsv($out, [__('Наименование', 'storefront'), __('Заказов', 'storefront'), __('Выдано', 'storefront'), __('Сумма', 'storefront')], ';');
    foreach ($rows as $r) {
        fputcsv($out, [$r['label'], $r['orders'], $r['qty'],
            number_format($r['amount'], 2, ',', '')], ';');
    }
    fclose($out);
    exit;
}

Html::header(__('Отчёты магазина', 'storefront'), $_SERVER['PHP_SELF'], 'management', Catalog::class, 'report');
$esc = static fn(?string $s): string => htmlescape((string) $s);
$self = Plugin::getWebDir('storefront') . '/front/report.php';

echo '<div class="container-fluid mt-3">';
echo __('<h2>Отчёт по выдачам</h2>', 'storefront');

echo '<form method="get" action="' . $esc($self) . '" class="row g-2 align-items-end mb-3">';
printf(__('<div class="col-auto"><label class="form-label">С</label>', 'storefront')
    . '<input type="date" name="from" value="%s" class="form-control form-control-sm"></div>',
    $esc($from));
printf(__('<div class="col-auto"><label class="form-label">По</label>', 'storefront')
    . '<input type="date" name="to" value="%s" class="form-control form-control-sm"></div>',
    $esc($to));

echo __('<div class="col-auto"><label class="form-label">Витрина</label>', 'storefront')
    . __('<select name="catalog" class="form-select form-select-sm"><option value="0">все</option>', 'storefront');
foreach (Catalog::availableFor((int) Session::getLoginUserID()) as $id => $c) {
    printf('<option value="%d"%s>%s</option>', (int) $id,
        (int) $id === $catalogs_id ? ' selected' : '', $esc($c['name']));
}
echo '</select></div>';

echo __('<div class="col-auto"><label class="form-label">Группировка</label>', 'storefront')
    . '<select name="groupby" class="form-select form-select-sm">';
foreach (['product' => __('по позиции', 'storefront'), 'category' => __('по категории', 'storefront'),
    'user' => __('по сотруднику', 'storefront'), 'entity' => __('по подразделению', 'storefront'),
    'group' => __('по отделу', 'storefront')] as $k => $lb) {
    printf('<option value="%s"%s>%s</option>', $k, $k === $groupby ? ' selected' : '', $lb);
}
echo '</select></div>';

echo __('<div class="col-auto"><button class="btn btn-sm btn-primary">Показать</button></div>', 'storefront');
printf('<div class="col-auto"><a class="btn btn-sm btn-outline-secondary" href="%s'
    . __('?from=%s&to=%s&catalog=%d&groupby=%s&csv=1">Выгрузить CSV</a></div>', 'storefront'),
    $esc($self), $esc($from), $esc($to), $catalogs_id, $esc($groupby));
echo '</form>';

$totalQty = 0;
$totalAmount = 0.0;
foreach ($rows as $r) {
    $totalQty += $r['qty'];
    $totalAmount += $r['amount'];
}

echo '<div class="table-responsive"><table class="table table-sm">';
echo __('<thead><tr><th>Наименование</th><th class="text-end">Заказов</th>', 'storefront')
    . __('<th class="text-end">Выдано</th><th class="text-end">Сумма</th></tr></thead><tbody>', 'storefront');
foreach ($rows as $r) {
    printf('<tr><td>%s</td><td class="text-end">%d</td><td class="text-end">%d</td>'
        . '<td class="text-end">%s</td></tr>',
        $esc($r['label']), $r['orders'], $r['qty'], Html::formatNumber($r['amount']));
}
if (!count($rows)) {
    echo __('<tr><td colspan="4" class="text-muted">За выбранный период выдач не было.</td></tr>', 'storefront');
}
echo '</tbody>';
if (count($rows)) {
    printf(__('<tfoot><tr class="fw-bold"><td>Итого</td><td></td>', 'storefront')
        . '<td class="text-end">%d</td><td class="text-end">%s</td></tr></tfoot>',
        $totalQty, Html::formatNumber($totalAmount));
}
echo '</table></div>';

echo __('<p class="text-muted small mt-2">В отчёт попадает только фактически выданное: ', 'storefront')
    . __('движения типа «выдача». Заказы, которые ещё не выданы, здесь не учитываются.</p>', 'storefront');

echo '</div>';
Html::footer();
