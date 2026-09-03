<?php

use GlpiPlugin\Storefront\Limit;

Session::checkRight('plugin_storefront_catalog', READ);

$item = new Limit();

/** Вернуться на карточку витрины, сразу на вкладку лимитов. */
$backToLimits = static function (int $catalogs_id): void {
    Html::redirect(Html::getPrefixedUrl(
        '/plugins/storefront/front/catalog.form.php?id=' . $catalogs_id
        . '&forcetab=' . urlencode(Limit::class . '$1')
    ));
};

if (isset($_POST['add'])) {
    Session::checkRight('plugin_storefront_catalog', UPDATE);
    $cid = (int) ($_POST['plugin_storefront_catalogs_id'] ?? 0);

    $input = $_POST;
    $input['is_active'] = 1;
    $input['is_hard'] = isset($_POST['is_hard']) ? 1 : 0;

    // «На кого» приходит одним значением вида group:30 — вид области и её
    // объект нельзя рассогласовать между собой.
    [$scope, $scope_items_id] = array_pad(
        explode(':', (string) ($_POST['scope_key'] ?? 'all:0'), 2),
        2,
        '0'
    );
    $input['scope'] = in_array($scope, ['all', 'group', 'entity', 'title', 'user'], true)
        ? $scope : 'all';
    $input['scope_items_id'] = (int) $scope_items_id;
    unset($input['scope_key']);

    // Общая норма имеет смысл там, где область объединяет нескольких людей:
    // для правила на одного сотрудника «одна на область» — это его же норма.
    $mode = (string) ($_POST['scope_mode'] ?? Limit::MODE_EACH);
    $input['scope_mode'] = $mode === Limit::MODE_TOTAL && $input['scope'] !== 'user'
        ? Limit::MODE_TOTAL : Limit::MODE_EACH;

    if ($input['scope'] !== 'all' && $input['scope_items_id'] <= 0) {
        Session::addMessageAfterRedirect(
            __('Не выбрано, на кого действует лимит.', 'storefront'), false, ERROR
        );
        $backToLimits($cid);
    }
    if (in_array((string) ($input['target'] ?? ''), ['product', 'category'], true)
        && (int) ($input['target_items_id'] ?? 0) <= 0) {
        Session::addMessageAfterRedirect(
            __('Не выбрано, на что действует лимит: укажите позицию ', 'storefront')
            . __('или выберите «вся витрина».', 'storefront'),
            false,
            ERROR
        );
        $backToLimits($cid);
    }
    if ((int) ($input['max_qty'] ?? 0) <= 0) {
        Session::addMessageAfterRedirect(
            __('Максимум должен быть больше нуля.', 'storefront'), false, ERROR
        );
        $backToLimits($cid);
    }

    if ((new Limit())->add($input)) {
        Session::addMessageAfterRedirect(__('Лимит добавлен.', 'storefront'), false, INFO);
    }
    $backToLimits($cid);
}

if (isset($_POST['add_legacy'])) {
    $item->check(-1, CREATE, $_POST);
    $newid = $item->add($_POST);
    if ($newid) {
        Html::redirect(Plugin::getWebDir('storefront') . '/front/limit.form.php?id=' . $newid);
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $item->check((int) $_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $item->check((int) $_POST['id'], PURGE);
    $item->delete($_POST, true);
    $back = (int) ($_POST['_back_catalog'] ?? 0);
    Html::redirect($back > 0
        ? Html::getPrefixedUrl('/plugins/storefront/front/catalog.form.php?id=' . $back)
        : Plugin::getWebDir('storefront') . '/front/catalog.php');
}

$id = (int) ($_GET['id'] ?? -1);

Html::header(
    Limit::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'management',
    \GlpiPlugin\Storefront\Catalog::class,
    'catalog'
);

$item->display(['id' => $id] + $_GET);

Html::footer();
