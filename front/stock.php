<?php

/**
 * Склад: остатки по позициям, приход, корректировка по инвентаризации.
 */

use GlpiPlugin\Storefront\Catalog;
use GlpiPlugin\Storefront\Engine;
use GlpiPlugin\Storefront\Movement;
use GlpiPlugin\Storefront\Product;
use GlpiPlugin\Storefront\Stock;
use GlpiPlugin\Storefront\Warehouse;

Session::checkRight('plugin_storefront_stock', READ);

$self = Plugin::getWebDir('storefront') . '/front/stock.php';
$catalogs_id = (int) ($_REQUEST['catalog'] ?? 0);
$warehouses_id = (int) ($_REQUEST['warehouse'] ?? 0);

/* ------------------------------------------------------------ действия */
if (isset($_POST['receive'])) {
    Session::checkRight('plugin_storefront_stock', UPDATE);
    // CSRF проверяет ядро GLPI 11 до контроллера (CheckCsrfListener),
    // и при успехе токен удаляется. Повторная проверка здесь
    // не нашла бы его и вернула бы «Доступ запрещён».
    Engine::receive(
        (int) $_POST['products_id'],
        (int) $_POST['warehouses_id'],
        (int) $_POST['qty'],
        [
            'document_no' => (string) ($_POST['document_no'] ?? ''),
            'unit_price'  => (float) ($_POST['unit_price'] ?? 0),
            'comment'     => (string) ($_POST['comment'] ?? ''),
        ]
    );
    Html::back();
}
if (isset($_POST['writeoff'])) {
    Session::checkRight('plugin_storefront_stock', UPDATE);
    Engine::writeOff(
        (int) $_POST['products_id'],
        (int) $_POST['warehouses_id'],
        (int) $_POST['qty'],
        [
            'document_no' => (string) ($_POST['document_no'] ?? ''),
            'comment'     => trim((string) ($_POST['reason'] ?? '')),
        ]
    );
    Html::back();
}
if (isset($_POST['transfer'])) {
    Session::checkRight('plugin_storefront_stock', UPDATE);
    Engine::transfer(
        (int) $_POST['products_id'],
        (int) $_POST['warehouses_id'],
        (int) $_POST['to_warehouses_id'],
        (int) $_POST['qty'],
        ['comment' => trim((string) ($_POST['comment'] ?? ''))]
    );
    Html::back();
}
if (isset($_POST['adjust'])) {
    Session::checkRight('plugin_storefront_stock', UPDATE);
    Engine::adjust(
        (int) $_POST['products_id'],
        (int) $_POST['warehouses_id'],
        (int) $_POST['qty_fact'],
        ['comment' => __('Инвентаризация: ', 'storefront') . (string) ($_POST['comment'] ?? '')]
    );
    Html::back();
}

Html::header(__('Склад', 'storefront'), $_SERVER['PHP_SELF'], 'management', Catalog::class, 'stock');

$esc = static fn(?string $s): string => htmlescape((string) $s);
// Один токен на страницу: см. пояснение в shop.php.
$csrf = Session::getNewCSRFToken();
global $DB;

/* ------------------------------------------------------------ выбор витрины */
$catalogs = Catalog::availableFor((int) Session::getLoginUserID());
echo '<div class="container-fluid mt-3">';
echo '<div class="d-flex gap-2 align-items-center flex-wrap mb-3">';
echo '<form method="get" action="' . $esc($self) . '" class="d-flex gap-2 align-items-center">';
echo __('<label class="form-label mb-0">Витрина</label>', 'storefront');
echo '<select name="catalog" class="form-select form-select-sm" style="max-width:280px" '
    . __('onchange="this.form.submit()"><option value="0">— выберите —</option>', 'storefront');
foreach ($catalogs as $id => $c) {
    printf('<option value="%d"%s>%s</option>', (int) $id,
        (int) $id === $catalogs_id ? ' selected' : '', $esc($c['name']));
}
echo '</select></form>';
echo '</div>';

// Витрина из адреса обязана быть среди доступных: иначе страница показала бы
// остатки и движения чужого склада.
if ($catalogs_id > 0 && !isset($catalogs[$catalogs_id])) {
    Session::addMessageAfterRedirect(
        __('Витрина недоступна в вашем подразделении.', 'storefront'), false, ERROR
    );
    $catalogs_id = 0;
}

if ($catalogs_id <= 0) {
    echo __('<div class="alert alert-info">Выберите витрину, чтобы увидеть остатки.</div>', 'storefront');
    echo '</div>';
    Html::footer();
    return;
}

$warehouses = Warehouse::listFor($catalogs_id);
if (!count($warehouses)) {
    echo __('<div class="alert alert-warning">У витрины нет складов. ', 'storefront')
        . __('Добавьте склад на вкладке витрины.</div></div>', 'storefront');
    Html::footer();
    return;
}
if ($warehouses_id <= 0 || !isset($warehouses[$warehouses_id])) {
    $warehouses_id = (int) array_key_first($warehouses);
}

echo '<ul class="nav nav-tabs mb-3">';
foreach ($warehouses as $wid => $w) {
    printf('<li class="nav-item"><a class="nav-link%s" href="%s?catalog=%d&warehouse=%d">%s</a></li>',
        (int) $wid === $warehouses_id ? ' active' : '',
        $esc($self), $catalogs_id, (int) $wid, $esc($w['name']));
}
echo '</ul>';

/* ------------------------------------------------------------ остатки */
$products = (new Product())->find(
    ['plugin_storefront_catalogs_id' => $catalogs_id],
    ['ranking ASC', 'name ASC']
);

$low = 0;
$zero = 0;
$needTotal = 0;
$rows = [];
foreach ($products as $pid => $r) {
    $p = new Product();
    $p->getFromDB((int) $pid);
    $s = Stock::ensure((int) $pid, $warehouses_id, (int) $r['entities_id']);
    $free = $s->free();
    $threshold = $s->threshold();
    $target = $p->targetFrom($s);
    $need = $target > $free ? $target - $free : 0;
    if ($free <= 0) {
        $zero++;
    } elseif ($threshold > 0 && $free < $threshold) {
        $low++;
    }
    $needTotal += $need;
    $rows[] = ['p' => $p, 's' => $s, 'free' => $free, 'threshold' => $threshold,
        'target' => $target, 'need' => $need];
}

printf(
    '<div class="d-flex gap-2 flex-wrap mb-3 align-items-center">'
    . __('<span class="badge bg-orange-lt">ниже порога: %d</span>', 'storefront')
    . __('<span class="badge bg-red-lt">нулевой остаток: %d</span>', 'storefront')
    . __('<span class="badge bg-blue-lt">к закупке: %d ед.</span>', 'storefront')
    . '<span class="ms-auto btn-group">'
    . '<a class="btn btn-sm btn-outline-secondary" href="%s?catalog=%d&amp;format=xlsx">'
    . '<i class="ti ti-file-spreadsheet me-1"></i>Excel</a>'
    . '<a class="btn btn-sm btn-outline-secondary" href="%s?catalog=%d&amp;format=csv">'
    . '<i class="ti ti-file-text me-1"></i>CSV</a>'
    . '<a class="btn btn-sm btn-outline-primary" href="%s?catalog=%d&amp;warehouse=%d">'
    . __('<i class="ti ti-upload me-1"></i>Загрузить списком</a>', 'storefront')
    . '</span></div>',
    $low, $zero, $needTotal,
    $esc(Plugin::getWebDir('storefront') . '/front/export.php'), $catalogs_id,
    $esc(Plugin::getWebDir('storefront') . '/front/export.php'), $catalogs_id,
    $esc(Plugin::getWebDir('storefront') . '/front/import.php'), $catalogs_id, $warehouses_id
);

echo '<div class="table-responsive"><table class="table table-sm">';
echo __('<thead><tr><th>Позиция</th><th>Ед.</th><th class="text-end">На руках</th>', 'storefront')
    . __('<th class="text-end">Резерв</th><th class="text-end">Свободно</th>', 'storefront')
    . __('<th class="text-end">Порог</th><th class="text-end">Цель</th>', 'storefront')
    . __('<th class="text-end">К закупке</th><th>Состояние</th><th></th></tr></thead><tbody>', 'storefront');

foreach ($rows as $r) {
    $tone = 'green';
    $label = __('норма', 'storefront');
    if ($r['free'] <= 0) {
        $tone = 'red';
        $label = __('нет', 'storefront');
    } elseif ($r['threshold'] > 0 && $r['free'] < $r['threshold']) {
        $tone = 'orange';
        $label = __('ниже порога', 'storefront');
    }
    printf(
        '<tr><td>%s <span class="text-muted small font-monospace">%s</span></td>'
        . '<td>%s</td><td class="text-end">%d</td><td class="text-end">%d</td>'
        . '<td class="text-end fw-bold">%d</td><td class="text-end">%s</td>'
        . '<td class="text-end">%s</td><td class="text-end">%s</td>'
        . '<td><span class="badge bg-%s-lt">%s</span></td>'
        . __('<td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="%s">карточка</a></td></tr>', 'storefront'),
        $esc($r['p']->label()),
        $esc($r['p']->ref()),
        $esc($r['p']->fields['unit']),
        (int) $r['s']->fields['qty_on_hand'],
        (int) $r['s']->fields['qty_reserved'],
        $r['free'],
        $r['threshold'] > 0 ? $r['threshold'] : '—',
        $r['target'] > 0 ? $r['target'] : '—',
        $r['need'] > 0 ? $r['need'] : '—',
        $tone,
        $label,
        $esc($r['p']->getItem() !== null
            ? $r['p']->getItem()->getLinkURL() : '#')
    );
}
if (!count($rows)) {
    echo __('<tr><td colspan="10" class="text-muted">В витрине нет позиций.</td></tr>', 'storefront');
}
echo '</tbody></table></div>';

/* ------------------------------------------------------------ приход */
if (Session::haveRight('plugin_storefront_stock', UPDATE) && count($rows)) {
    echo '<div class="row g-3 mt-2">';

    echo '<div class="col-12 col-lg-6"><div class="card"><div class="card-body">';
    echo __('<div class="fw-bold mb-2">Оформить приход</div>', 'storefront');
    echo '<form method="post" action="' . $esc($self) . '?catalog=' . $catalogs_id
        . '&warehouse=' . $warehouses_id . '">';
    echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
    echo Html::hidden('warehouses_id', ['value' => $warehouses_id]);
    echo '<select name="products_id" class="form-select form-select-sm mb-2">';
    foreach ($rows as $r) {
        printf('<option value="%d">%s</option>', $r['p']->getID(), $esc($r['p']->label()));
    }
    echo '</select>';
    echo '<div class="row g-2 mb-2">';
    echo '<div class="col"><input type="number" name="qty" min="1" step="1" '
        . __('class="form-control form-control-sm" placeholder="Количество" required></div>', 'storefront');
    echo '<div class="col"><input type="number" name="unit_price" min="0" step="0.01" '
        . __('class="form-control form-control-sm" placeholder="Цена за ед."></div>', 'storefront');
    echo '</div>';
    echo '<input type="text" name="document_no" class="form-control form-control-sm mb-2" '
        . __('placeholder="Номер документа">', 'storefront');
    echo '<input type="text" name="comment" class="form-control form-control-sm mb-2" '
        . __('placeholder="Комментарий">', 'storefront');
    echo __('<button class="btn btn-sm btn-primary" name="receive" value="1">Оприходовать</button>', 'storefront');
    echo '</form></div></div></div>';

    echo '<div class="col-12 col-lg-6"><div class="card"><div class="card-body">';
    echo __('<div class="fw-bold mb-2">Корректировка по инвентаризации</div>', 'storefront');
    echo __('<div class="text-muted small mb-2">Укажите фактическое количество: ', 'storefront')
        . __('расхождение с учётным будет записано в движения.</div>', 'storefront');
    echo '<form method="post" action="' . $esc($self) . '?catalog=' . $catalogs_id
        . '&warehouse=' . $warehouses_id . '">';
    echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
    echo Html::hidden('warehouses_id', ['value' => $warehouses_id]);
    echo '<select name="products_id" class="form-select form-select-sm mb-2">';
    foreach ($rows as $r) {
        printf(__('<option value="%d">%s — учтено %d</option>', 'storefront'), $r['p']->getID(),
            $esc($r['p']->label()), (int) $r['s']->fields['qty_on_hand']);
    }
    echo '</select>';
    echo '<input type="number" name="qty_fact" min="0" step="1" '
        . __('class="form-control form-control-sm mb-2" placeholder="Фактическое количество" required>', 'storefront');
    echo '<input type="text" name="comment" class="form-control form-control-sm mb-2" '
        . __('placeholder="Основание">', 'storefront');
    echo '<button class="btn btn-sm btn-outline-primary" name="adjust" value="1">'
        . __('Провести корректировку</button>', 'storefront');
    echo '</form></div></div></div>';

    echo '</div>';

    echo '<div class="row g-3 mt-0">';

    /* ---- списание */
    echo '<div class="col-12 col-lg-6"><div class="card"><div class="card-body">';
    echo __('<div class="fw-bold mb-2">Списать со склада</div>', 'storefront');
    echo __('<div class="text-muted small mb-2">Порча, утрата, истёк срок. Это не выдача: ', 'storefront')
        . __('в отчёт по сотрудникам и в лимиты списанное не попадает. ', 'storefront')
        . __('Основание обязательно.</div>', 'storefront');
    echo '<form method="post" action="' . $esc($self) . '?catalog=' . $catalogs_id
        . '&warehouse=' . $warehouses_id . '">';
    echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
    echo Html::hidden('warehouses_id', ['value' => $warehouses_id]);
    echo '<select name="products_id" class="form-select form-select-sm mb-2">';
    foreach ($rows as $r) {
        printf(__('<option value="%d">%s — свободно %d</option>', 'storefront'),
            $r['p']->getID(), $esc($r['p']->label()), $r['free']);
    }
    echo '</select>';
    echo '<div class="row g-2 mb-2">';
    echo '<div class="col"><input type="number" name="qty" min="1" step="1" '
        . __('class="form-control form-control-sm" placeholder="Количество" required></div>', 'storefront');
    echo '<div class="col"><input type="text" name="document_no" '
        . __('class="form-control form-control-sm" placeholder="Номер акта"></div>', 'storefront');
    echo '</div>';
    echo '<input type="text" name="reason" class="form-control form-control-sm mb-2" '
        . __('placeholder="Основание: порча, утрата, срок годности" required>', 'storefront');
    echo '<button class="btn btn-sm btn-outline-danger" name="writeoff" value="1">'
        . __('Списать</button>', 'storefront');
    echo '</form></div></div></div>';

    /* ---- перемещение */
    echo '<div class="col-12 col-lg-6"><div class="card"><div class="card-body">';
    echo __('<div class="fw-bold mb-2">Переместить на другой склад</div>', 'storefront');
    if (count($warehouses) < 2) {
        echo __('<div class="text-muted">У витрины один склад — перемещать некуда.</div>', 'storefront');
    } else {
        echo __('<div class="text-muted small mb-2">Записывается двумя движениями: ', 'storefront')
            . __('расход у одного склада и приход у другого, чтобы история сходилась ', 'storefront')
            . __('с обеих сторон.</div>', 'storefront');
        echo '<form method="post" action="' . $esc($self) . '?catalog=' . $catalogs_id
            . '&warehouse=' . $warehouses_id . '">';
        echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
        echo Html::hidden('warehouses_id', ['value' => $warehouses_id]);
        echo '<select name="products_id" class="form-select form-select-sm mb-2">';
        foreach ($rows as $r) {
            printf(__('<option value="%d">%s — свободно %d</option>', 'storefront'),
                $r['p']->getID(), $esc($r['p']->label()), $r['free']);
        }
        echo '</select>';
        echo '<div class="row g-2 mb-2">';
        echo '<div class="col-4"><input type="number" name="qty" min="1" step="1" '
            . __('class="form-control form-control-sm" placeholder="Кол-во" required></div>', 'storefront');
        echo '<div class="col-8"><select name="to_warehouses_id" '
            . 'class="form-select form-select-sm">';
        foreach ($warehouses as $wid => $w) {
            if ((int) $wid === $warehouses_id) {
                continue;
            }
            printf(__('<option value="%d">на склад: %s</option>', 'storefront'), (int) $wid, $esc($w['name']));
        }
        echo '</select></div>';
        echo '</div>';
        echo '<input type="text" name="comment" class="form-control form-control-sm mb-2" '
            . __('placeholder="Комментарий">', 'storefront');
        echo '<button class="btn btn-sm btn-outline-primary" name="transfer" value="1">'
            . __('Переместить</button>', 'storefront');
        echo '</form>';
    }
    echo '</div></div></div>';

    echo '</div>';
}

/* ------------------------------------------------------------ движения */
echo '<div class="card mt-3"><div class="card-body">';
echo __('<div class="fw-bold mb-2">Последние движения по складу</div>', 'storefront');
echo '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr>'
    . __('<th>Дата</th><th>Позиция</th><th>Тип</th><th class="text-end">Кол-во</th>', 'storefront')
    . __('<th class="text-end">Стало</th><th>Заказ</th><th>Комментарий</th>', 'storefront')
    . '</tr></thead><tbody>';
$n = 0;
foreach ($DB->request([
    'FROM'  => Movement::getTable(),
    'WHERE' => ['plugin_storefront_warehouses_id' => $warehouses_id],
    'ORDER' => 'id DESC',
    'LIMIT' => 30,
]) as $m) {
    $n++;
    $p = new Product();
    $pname = $p->getFromDB((int) $m['plugin_storefront_products_id'])
        ? $p->label() : (__('позиция #', 'storefront') . (int) $m['plugin_storefront_products_id']);
    printf(
        '<tr><td class="text-nowrap">%s</td><td>%s</td><td>%s</td>'
        . '<td class="text-end">%d</td><td class="text-end">%d</td><td>%s</td>'
        . '<td class="text-muted small">%s</td></tr>',
        $esc(Html::convDateTime((string) $m['date'])),
        $esc($pname),
        $esc(Movement::typeLabel((string) $m['type'])),
        (int) $m['qty'],
        (int) $m['qty_after'],
        (int) $m['plugin_storefront_orders_id'] > 0
            ? ('№' . (int) $m['plugin_storefront_orders_id']) : '—',
        $esc((string) $m['comment'])
    );
}
if ($n === 0) {
    echo __('<tr><td colspan="7" class="text-muted">Движений пока нет.</td></tr>', 'storefront');
}
echo '</tbody></table></div></div></div>';

echo '</div>';
Html::footer();
