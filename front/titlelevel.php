<?php

/**
 * Уровни должностей: автоматическая разметка справочника и ручные правки.
 */

use GlpiPlugin\Storefront\Catalog;
use GlpiPlugin\Storefront\TitleLevel;

Session::checkRight('plugin_storefront_catalog', READ);

$self = Plugin::getWebDir('storefront') . '/front/titlelevel.php';

if (isset($_POST['rebuild'])) {
    Session::checkRight('plugin_storefront_catalog', UPDATE);
    // CSRF проверяет ядро GLPI 11 до контроллера (CheckCsrfListener),
    // и при успехе токен удаляется. Повторная проверка здесь
    // не нашла бы его и вернула бы «Доступ запрещён».
    $r = TitleLevel::rebuild(isset($_POST['include_manual']));
    Session::addMessageAfterRedirect(sprintf(
        __('Разметка завершена. Создано: %d, обновлено: %d, пропущено ручных: %d,', 'storefront')
        . __(' убрано по удалённым должностям: %d.', 'storefront'),
        $r['created'],
        $r['updated'],
        $r['skipped'],
        $r['removed']
    ), false, INFO);
    Html::back();
}
if (isset($_POST['setlevel'])) {
    Session::checkRight('plugin_storefront_catalog', UPDATE);
    $tl = new TitleLevel();
    if ($tl->getFromDB((int) $_POST['id'])) {
        $tl->update([
            'id'        => (int) $_POST['id'],
            'level'     => (int) $_POST['level'],
            'is_manual' => 1,
        ]);
        Session::addMessageAfterRedirect(__('Уровень изменён вручную и не будет ', 'storefront')
            . __('перезаписан автоматической разметкой.', 'storefront'), false, INFO);
    }
    Html::back();
}

Html::header(__('Уровни должностей', 'storefront'), $_SERVER['PHP_SELF'], 'management',
    Catalog::class, 'titlelevel');

$esc = static fn(?string $s): string => htmlescape((string) $s);
global $DB;

$totalTitles = countElementsInTable('glpi_usertitles');
$marked = countElementsInTable(TitleLevel::getTable());

echo '<div class="container-fluid mt-3">';
echo __('<h2>Уровни должностей</h2>', 'storefront');
echo __('<p class="text-muted" style="max-width:76ch">Правило согласования формулируется ', 'storefront')
    . __('как «не ниже главного специалиста», но должность в GLPI — плоский справочник ', 'storefront')
    . __('без порядка. Уровень выводится из названия по шаблону: «главный» — шестьдесят, ', 'storefront')
    . __('«ведущий» — пятьдесят, «старший» — сорок, «младший» — двадцать, стажёр — десять, ', 'storefront')
    . __('руководящие должности — семьдесят. Обслуживающие должности вроде помощника ', 'storefront')
    . __('или секретаря выше специалиста не поднимаются, даже если в названии есть ', 'storefront')
    . __('слово «руководитель».</p>', 'storefront');

printf('<div class="d-flex gap-2 flex-wrap mb-3">'
    . __('<span class="badge bg-blue-lt">должностей в справочнике: %d</span>', 'storefront')
    . __('<span class="badge bg-green-lt">размечено: %d</span></div>', 'storefront'),
    $totalTitles, $marked);

echo '<form method="post" action="' . $esc($self) . '" class="mb-4">';
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo '<button class="btn btn-primary" name="rebuild" value="1">'
    . __('Разметить справочник должностей</button> ', 'storefront');
echo '<label class="form-check form-check-inline ms-2">'
    . '<input class="form-check-input" type="checkbox" name="include_manual" value="1">'
    . __('<span class="form-check-label">перезаписать и ручные правки</span></label>', 'storefront');
echo '</form>';

/* --------------------------------------------------- сводка по ступеням */
$summary = TitleLevel::summary();
if (count($summary)) {
    echo '<div class="card mb-4"><div class="card-body">';
    echo __('<div class="fw-bold mb-2">Распределение по ступеням</div>', 'storefront');
    echo __('<table class="table table-sm mb-0"><thead><tr><th>Ступень</th>', 'storefront')
        . __('<th class="text-end">Должностей</th><th class="text-end">Сотрудников</th>', 'storefront')
        . __('<th>Вправе согласовывать</th></tr></thead><tbody>', 'storefront');
    foreach ($summary as $lvl => $d) {
        printf('<tr><td>%s <span class="text-muted">(%d)</span></td>'
            . '<td class="text-end">%d</td><td class="text-end">%d</td><td>%s</td></tr>',
            $esc($d['label']), $lvl, $d['titles'], $d['people'],
            $lvl >= TitleLevel::L_CHIEF
                ? __('<span class="badge bg-green-lt">да</span>', 'storefront')
                : __('<span class="text-muted">нет</span>', 'storefront'));
    }
    echo '</tbody></table></div></div>';
}

/* --------------------------------------------------- список должностей */
$filter = trim((string) ($_GET['q'] ?? ''));
echo '<form method="get" action="' . $esc($self) . '" class="d-flex gap-2 mb-2" '
    . 'style="max-width:520px">';
printf('<input type="text" name="q" value="%s" class="form-control form-control-sm" '
    . __('placeholder="Поиск по названию должности">', 'storefront'), $esc($filter));
echo __('<button class="btn btn-sm btn-outline-secondary">Найти</button></form>', 'storefront');

$where = [];
if ($filter !== '') {
    $where['t.name'] = ['LIKE', '%' . $filter . '%'];
}

echo '<div class="table-responsive"><table class="table table-sm">';
echo __('<thead><tr><th>Должность</th><th class="text-end">Сотрудников</th>', 'storefront')
    . __('<th>Уровень</th><th>Источник</th><th></th></tr></thead><tbody>', 'storefront');

$shown = 0;
foreach ($DB->request([
    'SELECT' => ['t.id', 't.name'],
    'FROM'   => 'glpi_usertitles AS t',
    'WHERE'  => $where,
    'ORDER'  => 't.name ASC',
    'LIMIT'  => 200,
]) as $t) {
    $shown++;
    $tid = (int) $t['id'];
    $people = countElementsInTable('glpi_users',
        ['usertitles_id' => $tid, 'is_deleted' => 0, 'is_active' => 1]);

    $row = null;
    foreach ($DB->request(['FROM' => TitleLevel::getTable(),
        'WHERE' => ['usertitles_id' => $tid], 'LIMIT' => 1]) as $x) {
        $row = $x;
    }
    $level = $row ? (int) $row['level'] : TitleLevel::deriveLevel((string) $t['name']);
    $manual = $row ? (int) $row['is_manual'] : 0;

    echo '<tr><td>' . $esc($t['name']) . '</td>';
    printf('<td class="text-end">%d</td>', $people);
    printf('<td>%s <span class="text-muted">(%d)</span>%s</td>',
        $esc(TitleLevel::levelLabel($level)), $level,
        $level >= TitleLevel::L_CHIEF
            ? __(' <span class="badge bg-green-lt">согласует</span>', 'storefront') : '');
    printf('<td>%s</td>', $manual
        ? __('<span class="badge bg-purple-lt">вручную</span>', 'storefront')
        : __('<span class="text-muted small">по шаблону</span>', 'storefront'));

    echo '<td class="text-end">';
    if ($row && Session::haveRight('plugin_storefront_catalog', UPDATE)) {
        echo '<form method="post" action="' . $esc($self) . '" class="d-flex gap-1 '
            . 'justify-content-end">';
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::hidden('id', ['value' => (int) $row['id']]);
        echo '<select name="level" class="form-select form-select-sm" style="max-width:190px">';
        foreach (TitleLevel::levels() as $lv => $lb) {
            printf('<option value="%d"%s>%s</option>', $lv,
                $lv === $level ? ' selected' : '', $esc($lb));
        }
        echo '</select>';
        echo '<button class="btn btn-sm btn-outline-primary" name="setlevel" value="1">'
            . __('Задать</button>', 'storefront');
        echo '</form>';
    }
    echo '</td></tr>';
}
if ($shown === 0) {
    echo __('<tr><td colspan="5" class="text-muted">Должности не найдены. ', 'storefront')
        . __('Справочник заполняется при синхронизации пользователей.</td></tr>', 'storefront');
}
echo '</tbody></table></div>';
if ($shown >= 200) {
    echo __('<div class="text-muted small">Показаны первые 200 должностей. ', 'storefront')
        . __('Уточните поиск, чтобы найти нужную.</div>', 'storefront');
}

echo '</div>';
Html::footer();
