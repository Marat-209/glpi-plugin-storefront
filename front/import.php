<?php

/**
 * Загрузка номенклатуры в витрину списком: проверка файла и импорт.
 *
 * Два шага в одной форме. «Проверить» показывает, что произойдёт с каждой
 * строкой, ничего не меняя; «Импортировать» применяет то же самое. Файл
 * прикладывается заново на обоих шагах — так не нужно держать его копию
 * на сервере между запросами.
 */

use GlpiPlugin\Storefront\Catalog;
use GlpiPlugin\Storefront\Import;
use GlpiPlugin\Storefront\Warehouse;

Session::checkRight('plugin_storefront_catalog', UPDATE);

$self = Plugin::getWebDir('storefront') . '/front/import.php';
$esc = static fn(?string $s): string => htmlescape((string) $s);

/* ------------------------------------------------------------ образец */
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="storefront-template.csv"');
    echo Import::template();
    exit;
}

$catalogs_id = (int) ($_REQUEST['catalog'] ?? 0);
$warehouses_id = (int) ($_REQUEST['warehouse'] ?? 0);
$delimiter = (string) ($_REQUEST['delimiter'] ?? '');

/* ------------------------------------------------------------ разбор */
$plan = null;
$applied = null;
$raw = '';

if (isset($_POST['check']) || isset($_POST['apply'])) {
    if (isset($_FILES['csv']) && is_uploaded_file($_FILES['csv']['tmp_name'] ?? '')) {
        $raw = (string) file_get_contents($_FILES['csv']['tmp_name']);
    } else {
        $raw = (string) ($_POST['pasted'] ?? '');
    }

    $catalog = new Catalog();
    if ($catalogs_id <= 0 || !$catalog->getFromDB($catalogs_id)) {
        Session::addMessageAfterRedirect(__('Выберите витрину.', 'storefront'), false, ERROR);
    } elseif (trim($raw) === '') {
        Session::addMessageAfterRedirect(
            __('Ни файл не приложен, ни текст не вставлен.', 'storefront'), false, ERROR
        );
    } else {
        $plan = Import::plan($raw, $catalogs_id, $delimiter);
        if (isset($_POST['apply']) && !count($plan['errors'])) {
            $applied = Import::apply(
                $plan['rows'],
                $catalogs_id,
                (int) $catalog->fields['entities_id'],
                $warehouses_id
            );
        }
    }
}

Html::header(__('Загрузка номенклатуры', 'storefront'), $_SERVER['PHP_SELF'], 'management', Catalog::class, 'import');

echo '<div class="container-fluid mt-3">';
echo __('<h2 class="mb-1">Загрузка номенклатуры списком</h2>', 'storefront');
echo __('<p class="text-muted">Таблица из бухгалтерии или прайс поставщика заводит витрину ', 'storefront')
    . __('целиком за один раз. Файл CSV, первая строка — названия столбцов.</p>', 'storefront');

/* ------------------------------------------------------------ подсказка */
echo '<div class="card mb-3"><div class="card-body">';
echo __('<div class="fw-bold mb-2">Какие столбцы понимает загрузка</div>', 'storefront');
echo '<div class="table-responsive"><table class="table table-sm mb-2"><thead><tr>'
    . __('<th>Столбец</th><th>Обязателен</th><th>Что делает</th></tr></thead><tbody>', 'storefront');
foreach ([
    [__('наименование', 'storefront'), __('да', 'storefront'), __('Название позиции. По нему же ищется совпадение с уже ', 'storefront')
        . __('заведённой номенклатурой, если не указан артикул.', 'storefront')],
    [__('артикул', 'storefront'), __('нет', 'storefront'), __('Код позиции. Если он совпал с существующей номенклатурой, ', 'storefront')
        . __('новая не создаётся.', 'storefront')],
    [__('категория', 'storefront'), __('нет', 'storefront'), __('Тип расходного материала. Если такой категории ещё нет, ', 'storefront')
        . __('она будет создана.', 'storefront')],
    [__('единица', 'storefront'), __('нет', 'storefront'), __('Штуки, упаковки, пачки. По умолчанию «шт».', 'storefront')],
    [__('цена', 'storefront'), __('нет', 'storefront'), __('Цена за единицу. Можно писать через запятую: 430,00.', 'storefront')],
    [__('порог', 'storefront'), __('нет', 'storefront'), __('Ниже этого остатка позиция попадает в «ниже порога».', 'storefront')],
    [__('цель', 'storefront'), __('нет', 'storefront'), __('Целевой запас: до него считается потребность к закупке.', 'storefront')],
    [__('остаток', 'storefront'), __('нет', 'storefront'), __('Начальное количество. Проводится как обычный приход ', 'storefront')
        . __('на выбранный склад и попадает в историю движений.', 'storefront')],
] as $r) {
    printf('<tr><td class="font-monospace">%s</td><td>%s</td><td class="text-muted">%s</td></tr>',
        $esc($r[0]), $esc($r[1]), $esc($r[2]));
}
echo '</tbody></table></div>';
printf('<a class="btn btn-sm btn-outline-secondary" href="%s?template=1">'
    . __('<i class="ti ti-download me-1"></i>Скачать образец</a>', 'storefront'), $esc($self));
echo __('<div class="text-muted small mt-2">Кодировка определяется сама: подходит и UTF-8, ', 'storefront')
    . __('и windows-1251, в которой сохраняет русский Excel. Разделителем может быть ', 'storefront')
    . __('точка с запятой, запятая или табуляция.</div>', 'storefront');
echo '</div></div>';

/* ------------------------------------------------------------ форма */
echo '<div class="card mb-3"><div class="card-body">';
echo '<form method="post" enctype="multipart/form-data" action="' . $esc($self) . '">';
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo '<div class="row g-2 align-items-end">';

echo __('<div class="col-12 col-md-4"><label class="form-label">Витрина</label>', 'storefront')
    . '<select name="catalog" class="form-select form-select-sm" required>'
    . __('<option value="0">— выберите —</option>', 'storefront');
foreach ((new Catalog())->find([], ['name ASC']) as $id => $c) {
    printf('<option value="%d"%s>%s</option>', (int) $id,
        (int) $id === $catalogs_id ? ' selected' : '', $esc($c['name']));
}
echo '</select></div>';

echo __('<div class="col-12 col-md-4"><label class="form-label">Склад для начального остатка</label>', 'storefront')
    . '<select name="warehouse" class="form-select form-select-sm">'
    . __('<option value="0">не приходовать</option>', 'storefront');
if ($catalogs_id > 0) {
    foreach (Warehouse::listFor($catalogs_id) as $wid => $w) {
        printf('<option value="%d"%s>%s</option>', (int) $wid,
            (int) $wid === $warehouses_id ? ' selected' : '', $esc($w['name']));
    }
}
echo '</select>'
    . __('<div class="text-muted small">Список складов появится после выбора витрины ', 'storefront')
    . __('и проверки файла.</div></div>', 'storefront');

echo __('<div class="col-12 col-md-4"><label class="form-label">Разделитель</label>', 'storefront')
    . '<select name="delimiter" class="form-select form-select-sm">';
foreach (['' => __('определить самому', 'storefront'), ';' => __('точка с запятой', 'storefront'), ',' => __('запятая', 'storefront'),
    "\t" => __('табуляция', 'storefront')] as $k => $lb) {
    printf('<option value="%s"%s>%s</option>', $esc($k),
        $k === $delimiter ? ' selected' : '', $esc($lb));
}
echo '</select></div>';

echo __('<div class="col-12"><label class="form-label">Файл CSV</label>', 'storefront')
    . '<input type="file" name="csv" accept=".csv,text/csv,text/plain" '
    . 'class="form-control form-control-sm"></div>';
echo __('<div class="col-12"><label class="form-label">…или вставьте таблицу текстом</label>', 'storefront')
    . '<textarea name="pasted" rows="4" class="form-control form-control-sm font-monospace" '
    . __('placeholder="наименование;артикул;единица;цена;остаток">', 'storefront')
    . $esc((string) ($_POST['pasted'] ?? '')) . '</textarea></div>';

echo '<div class="col-12 d-flex gap-2">'
    . __('<button class="btn btn-sm btn-outline-primary" name="check" value="1">Проверить</button>', 'storefront')
    . __('<button class="btn btn-sm btn-primary" name="apply" value="1">Импортировать</button>', 'storefront')
    . '</div>';
echo '</div></form></div></div>';

/* ------------------------------------------------------------ итог импорта */
if ($applied !== null) {
    printf(
        __('<div class="alert alert-success"><b>Импорт выполнен.</b> Создано позиций: %d, ', 'storefront')
        . __('обновлено: %d, пропущено строк: %d, оприходовано единиц: %d.</div>', 'storefront'),
        $applied['created'], $applied['updated'], $applied['skipped'], $applied['stock']
    );
    foreach ($applied['errors'] as $e) {
        echo '<div class="alert alert-danger py-2">' . $esc($e) . '</div>';
    }
}

/* ------------------------------------------------------------ план */
if ($plan !== null) {
    foreach ($plan['errors'] as $e) {
        echo '<div class="alert alert-danger">' . $esc($e) . '</div>';
    }
    if (count($plan['rows'])) {
        $counts = [Import::ACT_CREATE => 0, Import::ACT_UPDATE => 0, Import::ACT_ERROR => 0];
        foreach ($plan['rows'] as $r) {
            $counts[$r['action']] = ($counts[$r['action']] ?? 0) + 1;
        }
        printf('<div class="d-flex gap-2 flex-wrap mb-2">'
            . __('<span class="badge bg-green-lt">создать: %d</span>', 'storefront')
            . __('<span class="badge bg-blue-lt">обновить: %d</span>', 'storefront')
            . __('<span class="badge bg-red-lt">ошибок: %d</span></div>', 'storefront'),
            $counts[Import::ACT_CREATE], $counts[Import::ACT_UPDATE],
            $counts[Import::ACT_ERROR]);

        echo '<div class="table-responsive"><table class="table table-sm">';
        echo __('<thead><tr><th>Строка</th><th>Что будет</th><th>Наименование</th>', 'storefront')
            . __('<th>Артикул</th><th>Категория</th><th>Ед.</th>', 'storefront')
            . __('<th class="text-end">Цена</th><th class="text-end">Порог</th>', 'storefront')
            . __('<th class="text-end">Цель</th><th class="text-end">Остаток</th>', 'storefront')
            . __('<th>Примечание</th></tr></thead><tbody>', 'storefront');
        foreach ($plan['rows'] as $r) {
            printf(
                '<tr><td>%d</td><td><span class="badge bg-%s-lt">%s</span></td>'
                . '<td>%s</td><td class="font-monospace">%s</td><td>%s</td><td>%s</td>'
                . '<td class="text-end">%s</td><td class="text-end">%s</td>'
                . '<td class="text-end">%s</td><td class="text-end">%s</td>'
                . '<td class="text-muted small">%s</td></tr>',
                $r['line'],
                Import::actionTone($r['action']),
                $esc(Import::actionLabel($r['action'])),
                $esc($r['name']),
                $esc($r['ref']),
                $esc($r['category']),
                $esc($r['unit']),
                Html::formatNumber($r['price']),
                $r['threshold'] > 0 ? $r['threshold'] : '—',
                $r['target'] > 0 ? $r['target'] : '—',
                $r['qty'] > 0 ? $r['qty'] : '—',
                $esc($r['note'])
            );
        }
        echo '</tbody></table></div>';
        if ($applied === null) {
            echo __('<div class="alert alert-info">Это только проверка — ничего не изменено. ', 'storefront')
                . __('Приложите файл ещё раз и нажмите «Импортировать».</div>', 'storefront');
        }
    }
}

echo '</div>';
Html::footer();
