<?php

/**
 * Витрина магазина в интерфейсе самообслуживания: каталог, корзина, оформление.
 *
 * Раскладка привычна по обычным магазинам: слева каталог с поиском, фильтром
 * и сортировкой, справа корзина, которая едет за экраном. Количество меняется
 * кнопками «минус-плюс» прямо на карточке, а история заказов показывает, на
 * какой стадии заказ и позволяет повторить его одной кнопкой.
 */

use GlpiPlugin\Storefront\CartItem;
use GlpiPlugin\Storefront\Catalog;
use GlpiPlugin\Storefront\Engine;
use GlpiPlugin\Storefront\Kit;
use GlpiPlugin\Storefront\Order;
use GlpiPlugin\Storefront\OrderItem;
use GlpiPlugin\Storefront\Product;
use GlpiPlugin\Storefront\Rating;
use GlpiPlugin\Storefront\Ui;
use GlpiPlugin\Storefront\Stock;
use GlpiPlugin\Storefront\Warehouse;

Session::checkLoginUser();

$me = (int) Session::getLoginUserID();
$catalogs = Catalog::availableFor($me);

$catalogs_id = (int) ($_REQUEST['catalog'] ?? 0);
if ($catalogs_id <= 0 && count($catalogs) === 1) {
    $catalogs_id = (int) array_key_first($catalogs);
}

$self = Plugin::getWebDir('storefront') . '/front/shop.php';

/*
 * Шлюз доступа. Витрину загружаем и проверяем один раз, здесь, — до любого
 * действия ниже. Раньше каждое действие работало по идентификатору из
 * запроса, а витрина проверялась только при выводе: по прямому адресу
 * открывалась витрина чужого подразделения, и через неё можно было оформить
 * заказ на чужой склад.
 *
 * Недоступная витрина приравнивается к невыбранной: catalog обнуляется,
 * все действия ниже выключаются условием $catalogs_id > 0, а сотрудник
 * видит список своих витрин и сообщение.
 */
$catalog = null;
if ($catalogs_id > 0) {
    $candidate = new Catalog();
    if ($candidate->getFromDB($catalogs_id) && $candidate->isAvailableToCurrentUser()) {
        $catalog = $candidate;
    } else {
        Session::addMessageAfterRedirect(
            __('Витрина не найдена, отключена или недоступна в вашем подразделении.', 'storefront'),
            false,
            ERROR
        );
        $catalogs_id = 0;
    }
}

/*
 * Для кого заказ. Выбор живёт в адресе страницы, а не в форме отправки:
 * от него зависит, чей лимит показывать в корзине, а значит корзину надо
 * перерисовать сразу после переключения, до отправки заказа.
 */
$rtype = (string) ($_REQUEST['recipient_type'] ?? Order::FOR_SELF);
if (!isset(Order::recipientTypes()[$rtype])) {
    $rtype = Order::FOR_SELF;
}
$ruser   = $rtype === Order::FOR_USER   ? (int) ($_REQUEST['users_id_recipient'] ?? 0) : 0;
$rgroup  = $rtype === Order::FOR_GROUP  ? (int) ($_REQUEST['groups_id_recipient'] ?? 0) : 0;
$rentity = $rtype === Order::FOR_ENTITY ? (int) ($_REQUEST['entities_id_recipient'] ?? 0) : 0;
// Заказ на подразделение, к которому у сотрудника нет доступа, — это тот же
// обход границы, только через поле получателя.
if ($rentity > 0 && !Session::haveAccessToEntity($rentity)) {
    Session::addMessageAfterRedirect(
        __('Это подразделение вам недоступно.', 'storefront'), false, ERROR
    );
    $rentity = 0;
    $rtype = Order::FOR_SELF;
}
$rnote   = $rtype === Order::FOR_SELF ? '' : trim((string) ($_REQUEST['recipient_note'] ?? ''));
$rscope  = Order::limitScopeFor($rtype, $ruser, $rgroup, $rentity, $me);

/* Поиск, фильтр и сортировка каталога. */
$q        = trim((string) ($_REQUEST['q'] ?? ''));
$catFilter = (int) ($_REQUEST['cat'] ?? 0);
$onlyStock = (int) ($_REQUEST['instock'] ?? 0) === 1;
$sort      = (string) ($_REQUEST['sort'] ?? 'name');
$page      = max(1, (int) ($_REQUEST['page'] ?? 1));
$perPage   = 24;
if (!in_array($sort, ['name', 'price_asc', 'price_desc', 'stock'], true)) {
    $sort = 'name';
}

/** Адрес витрины с сохранением получателя, поиска и фильтров. */
$shopUrl = static function (int $cid, array $override = []) use (
    $self, $rtype, $ruser, $rgroup, $rentity, $rnote, $q, $catFilter, $onlyStock, $sort, $page
): string {
    $params = ['catalog' => $cid, 'recipient_type' => $rtype];
    if ($ruser > 0) {
        $params['users_id_recipient'] = $ruser;
    }
    if ($rgroup > 0) {
        $params['groups_id_recipient'] = $rgroup;
    }
    if ($rentity > 0) {
        $params['entities_id_recipient'] = $rentity;
    }
    if ($rnote !== '') {
        $params['recipient_note'] = $rnote;
    }
    if ($q !== '') {
        $params['q'] = $q;
    }
    if ($catFilter > 0) {
        $params['cat'] = $catFilter;
    }
    if ($onlyStock) {
        $params['instock'] = 1;
    }
    if ($sort !== 'name') {
        $params['sort'] = $sort;
    }
    if ($page > 1) {
        $params['page'] = $page;
    }
    foreach ($override as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return $self . '?' . http_build_query($params);
};

/* ------------------------------------------------------------ действия */
if (isset($_POST['put']) && $catalogs_id > 0) {
    // CSRF проверяет ядро GLPI 11 до контроллера (CheckCsrfListener),
    // и при успехе токен удаляется. Повторная проверка здесь
    // не нашла бы его и вернула бы «Доступ запрещён».
    CartItem::put($me, $catalogs_id, (int) $_POST['products_id'], (int) $_POST['qty']);
    Html::redirect($shopUrl($catalogs_id));
}
if (isset($_POST['drop']) && $catalogs_id > 0) {
    CartItem::drop($me, $catalogs_id, (int) $_POST['products_id']);
    Html::redirect($shopUrl($catalogs_id));
}
if (isset($_POST['rate']) && $catalogs_id > 0) {
    $stars = (int) $_POST['rate'];
    $pidRate = (int) ($_POST['products_id'] ?? 0);
    // Оценивать позицию можно только в той витрине, где она есть. Право
    // «получал ли» проверяет сама Rating; здесь закрываем подстановку
    // чужого products_id.
    if (!$catalog->ownsProduct($pidRate, false)) {
        Session::addMessageAfterRedirect(__('Эта позиция не из этой витрины.', 'storefront'), false, ERROR);
        Html::redirect($shopUrl($catalogs_id));
    }
    // Короткий отзыв — это то, за чем оценка и нужна: звёзды говорят «плохо»,
    // а отзыв говорит, чем именно. Ограничиваем длину: это подпись под
    // оценкой, а не переписка.
    $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255);
    if (Rating::rate($pidRate, $me, $stars, $note)) {
        Session::addMessageAfterRedirect(
            __('Спасибо, оценка учтена: ', 'storefront') . $stars . __(' из ', 'storefront') . Rating::MAX . '.'
            . ($note !== '' ? __(' Отзыв сохранён.', 'storefront') : ''),
            false,
            INFO
        );
    }
    Html::redirect($shopUrl($catalogs_id));
}
if (isset($_POST['clear']) && $catalogs_id > 0) {
    CartItem::clear($me, $catalogs_id);
    Html::redirect($shopUrl($catalogs_id));
}
if (isset($_POST['take_kit']) && $catalogs_id > 0) {
    $kit = new Kit();
    $kits_id = (int) ($_POST['kits_id'] ?? 0);
    if (!$kit->getFromDB($kits_id)
        || (int) $kit->fields['plugin_storefront_catalogs_id'] !== $catalogs_id
        || (int) $kit->fields['is_active'] !== 1) {
        Session::addMessageAfterRedirect(__('Набор не найден.', 'storefront'), false, ERROR);
        Html::redirect($shopUrl($catalogs_id));
    }
    $res = $kit->addToCart($me);
    if ($res['added'] > 0) {
        Session::addMessageAfterRedirect(sprintf(
            __('Набор «%s» добавлен в корзину: позиций %d.%s', 'storefront'),
            $kit->fields['name'],
            $res['added'],
            $res['skipped'] > 0 ? __(' Пропущено недоступных: ', 'storefront') . $res['skipped'] . '.' : ''
        ), false, INFO);
    } else {
        Session::addMessageAfterRedirect(__('В наборе нет доступных позиций.', 'storefront'), false, WARNING);
    }
    Html::redirect($shopUrl($catalogs_id));
}
if (isset($_POST['repeat']) && $catalogs_id > 0) {
    $prev = new Order();
    $oid = (int) ($_POST['orders_id'] ?? 0);
    if (!$prev->getFromDB($oid)
        || (int) $prev->fields['users_id_requester'] !== $me
        || (int) $prev->fields['plugin_storefront_catalogs_id'] !== $catalogs_id) {
        Session::addMessageAfterRedirect(__('Заказ не найден.', 'storefront'), false, ERROR);
        Html::redirect($shopUrl($catalogs_id));
    }
    $res = $prev->repeatToCart();
    if ($res['added'] > 0) {
        Session::addMessageAfterRedirect(sprintf(
            __('Состав заказа №%d добавлен в корзину: позиций %d.%s', 'storefront'),
            $oid,
            $res['added'],
            $res['skipped'] > 0
                ? __(' Пропущено недоступных сейчас: ', 'storefront') . $res['skipped'] . '.' : ''
        ), false, INFO);
    } else {
        Session::addMessageAfterRedirect(
            __('Ни одной позиции того заказа сейчас нет в витрине.', 'storefront'), false, WARNING
        );
    }
    Html::redirect($shopUrl($catalogs_id));
}
if (isset($_POST['checkout']) && $catalogs_id > 0) {

    $cart = CartItem::forUser($me, $catalogs_id);

    if (!count($cart)) {
        Session::addMessageAfterRedirect(__('Корзина пуста.', 'storefront'), false, ERROR);
        Html::redirect($shopUrl($catalogs_id));
    }

    // Состав перепроверяем перед созданием заказа, а не доверяем корзине:
    // строки могли попасть в неё до того, как позицию выключили или
    // перенесли в другую витрину.
    foreach ($cart as $row) {
        if (!$catalog->ownsProduct((int) $row['plugin_storefront_products_id'])) {
            Session::addMessageAfterRedirect(
                __('В корзине есть позиция, которой нет в этой витрине. ', 'storefront')
                . __('Уберите её и отправьте заказ заново.', 'storefront'),
                false,
                ERROR
            );
            Html::redirect($shopUrl($catalogs_id));
        }
    }

    // Комментарий: согласующий должен понимать, зачем заказ, не переспрашивая.
    // Требование включается на витрине, по умолчанию включено.
    $comment = trim((string) ($_POST['comment'] ?? ''));
    if ($catalog->commentRequired() && $comment === '') {
        Session::addMessageAfterRedirect(
            __('Заказ не отправлен: напишите в комментарии, зачем нужен заказ. ', 'storefront')
            . __('Это поле обязательно.', 'storefront'),
            false,
            ERROR
        );
        Html::redirect($shopUrl($catalogs_id));
    }

    // Склад выдачи обязан принадлежать этой витрине: подставленный в форму
    // чужой warehouses_id означал бы списание с чужого склада.
    $wid = (int) ($_POST['warehouses_id'] ?? 0);
    if ($wid > 0 && !$catalog->ownsWarehouse($wid)) {
        Session::addMessageAfterRedirect(
            __('Выбранный склад не относится к этой витрине.', 'storefront'), false, ERROR
        );
        Html::redirect($shopUrl($catalogs_id));
    }
    if ($wid <= 0) {
        $def = Warehouse::getDefaultFor($catalogs_id);
        $wid = $def !== null ? $def->getID() : 0;
    }
    if ($wid <= 0) {
        Session::addMessageAfterRedirect(
            __('У витрины нет склада выдачи — обратитесь к администратору.', 'storefront'), false, ERROR
        );
        Html::redirect($shopUrl($catalogs_id));
    }

    // Жёсткий лимит проверяем здесь, а не только в разметке: кнопку можно
    // разблокировать в браузере, а превышение должно останавливать заказ.
    $preLines = [];
    foreach ($cart as $row) {
        $preLines[] = [
            'products_id' => (int) $row['plugin_storefront_products_id'],
            'qty'         => (int) $row['qty'],
        ];
    }
    foreach (Engine::checkLimits($catalog, $me, $preLines, $rscope) as $v) {
        if (!$v['is_hard']) {
            continue;
        }
        Session::addMessageAfterRedirect(
            sprintf(
                __('Заказ не отправлен: превышен жёсткий лимит «%s» (%s). За период уже ', 'storefront')
                . __('получено %d из %d, в заказе ещё %d.', 'storefront'),
                (string) $v['limit']['name'],
                (string) ($v['pool'] ?? __('ваша норма', 'storefront')),
                $v['used'], $v['max'], $v['requested']
            ),
            false,
            ERROR
        );
        Html::redirect($shopUrl($catalogs_id));
    }

    // Обязательного согласующего проверяем до создания заказа: иначе сотрудник
    // получит ошибку, а в системе останется черновик, которого он не видит.
    if ($catalog->requiresApprover() && (int) ($_POST['users_id_approver'] ?? 0) <= 0) {
        Session::addMessageAfterRedirect(
            __('Выберите согласующего: по правилам этой витрины заказ отправляется ', 'storefront')
            . __('на согласование конкретному человеку. Корзина сохранена.', 'storefront'),
            false,
            ERROR
        );
        Html::redirect($shopUrl($catalogs_id));
    }

    $order = new Order();
    $oid = (int) $order->add([
        'name'                          => __('Заказ по витрине «', 'storefront') . $catalog->fields['name'] . '»',
        // Подразделение берём у витрины, а не из сессии: иначе заказ, заявка
        // и склад оказываются в разных подразделениях, и права с отчётностью
        // GLPI считают по разным основаниям.
        'entities_id'                   => (int) $catalog->fields['entities_id'],
        'plugin_storefront_catalogs_id' => $catalogs_id,
        'plugin_storefront_warehouses_id' => $wid,
        'users_id_requester'            => $me,
        'recipient_type'                => $rtype,
        'users_id_recipient'            => $ruser,
        'groups_id_recipient'           => $rgroup,
        'entities_id_recipient'         => $rentity,
        'recipient_note'                => $rnote,
        'users_id_approver'             => (int) ($_POST['users_id_approver'] ?? 0),
        'plugin_storefront_kits_id'     => CartItem::kitOf($me, $catalogs_id),
        'state'                         => Order::DRAFT,
        'comment'                       => $comment,
    ]);
    if ($oid > 0) {
        $rank = 0;
        foreach ($cart as $row) {
            $p = new Product();
            if (!$p->getFromDB((int) $row['plugin_storefront_products_id'])) {
                continue;
            }
            (new OrderItem())->add([
                'plugin_storefront_orders_id'   => $oid,
                'plugin_storefront_products_id' => $p->getID(),
                'itemtype'                      => (string) $p->fields['itemtype'],
                'items_id'                      => (int) $p->fields['items_id'],
                'name_snapshot'                 => $p->label(),
                'unit_snapshot'                 => (string) $p->fields['unit'],
                'price_snapshot'                => $p->price(),
                'qty_requested'                 => (int) $row['qty'],
                'ranking'                       => ($rank += 10),
            ]);
        }
        $order->getFromDB($oid);
        $order->recalc();
        $order->getFromDB($oid);
        if ($order->submit()) {
            CartItem::clear($me, $catalogs_id);
        } else {
            // Отправка не прошла — причину сотрудник уже увидел. Черновик
            // убираем: он не виден в интерфейсе, а в учёте копится мусором.
            // Корзину не трогаем, чтобы человек мог поправить заказ и повторить.
            foreach ((new OrderItem())->find(['plugin_storefront_orders_id' => $oid]) as $lid => $l) {
                (new OrderItem())->delete(['id' => (int) $lid], true);
            }
            $order->delete(['id' => $oid], true);
        }
    }
    Html::redirect($self . '?catalog=' . $catalogs_id);
}

/* ------------------------------------------------------------ вывод */
$isHelpdesk = Session::getCurrentInterface() === 'helpdesk';
if ($isHelpdesk) {
    Html::helpHeader(__('Магазин', 'storefront'));
} else {
    Html::header(__('Магазин', 'storefront'), $_SERVER['PHP_SELF'], 'management', Catalog::class, 'shop');
}

$esc = static fn(?string $s): string => htmlescape((string) $s);

// Один токен на всю страницу. Отдельный токен в каждой форме товара
// переполняет ограниченный список GLPI: ранние вытесняются, и кнопки
// у верхних позиций начинают отвечать «Доступ запрещён».
$csrf = Session::getNewCSRFToken();

if ($catalogs_id <= 0) {
    echo '<div class="container-fluid mt-3">';
    echo __('<h2 class="mb-3">Магазин</h2>', 'storefront');
    if (!count($catalogs)) {
        // Пусто по двум разным причинам, и путать их нельзя: витрин может не быть
        // вовсе, а может не быть именно в том подразделении, которое выбрано
        // сейчас в шапке. Во втором случае «обратитесь к администратору» уводит
        // в сторону — человеку надо всего лишь переключить подразделение.
        $total = countElementsInTable(Catalog::getTable(), ['is_active' => 1]);
        $current = \Dropdown::getDropdownName(
            'glpi_entities',
            (int) ($_SESSION['glpiactive_entity'] ?? 0)
        );
        if ($total > 0) {
            echo '<div class="alert alert-warning">';
            echo __('<div class="fw-bold mb-1">В подразделении «', 'storefront') . $esc($current)
                . __('» витрин нет.</div>', 'storefront');
            echo __('<div>Витрины настроены в других подразделениях. Переключите ', 'storefront')
                . __('подразделение вверху страницы — или выберите его вместе ', 'storefront')
                . __('с дочерними, чтобы увидеть все доступные вам витрины.</div>', 'storefront');
            if (Session::haveRight('plugin_storefront_catalog', READ)) {
                printf(
                    '<div class="mt-2"><a class="btn btn-sm btn-outline-secondary"'
                    . __(' href="%s/front/catalog.php">Список витрин</a></div>', 'storefront'),
                    $esc(Plugin::getWebDir('storefront'))
                );
            }
            echo '</div>';
        } else {
            echo __('<div class="alert alert-info">Витрины ещё не настроены. ', 'storefront')
                . __('Обратитесь к администратору.</div>', 'storefront');
        }
    } else {
        echo '<div class="row g-3">';
        foreach ($catalogs as $id => $c) {
            printf(
                '<div class="col-12 col-sm-6 col-lg-3"><a class="card h-100 text-decoration-none"'
                . ' href="%s?catalog=%d"><div class="card-body d-flex flex-column gap-2">'
                . '<i class="%s fs-2"></i><div class="fw-bold">%s</div>'
                . '<div class="text-muted small">%s</div></div></a></div>',
                $esc($self),
                (int) $id,
                $esc($c['icon'] ?: 'ti ti-package'),
                $esc($c['name']),
                $esc($c['description'])
            );
        }
        echo '</div>';
    }
    echo '</div>';
    $isHelpdesk ? Html::helpFooter() : Html::footer();
    return;
}

// Витрина уже загружена и проверена шлюзом в начале файла: сюда мы попадаем
// только с доступной витриной.
if ($catalog === null) {
    echo '<div class="container-fluid mt-3"><div class="alert alert-warning">'
        . __('Витрина не найдена или недоступна.</div></div>', 'storefront');
    $isHelpdesk ? Html::helpFooter() : Html::footer();
    return;
}

$allProducts = $catalog->products($me);
$cart = CartItem::forUser($me, $catalogs_id);
$inCart = [];
foreach ($cart as $row) {
    $inCart[(int) $row['plugin_storefront_products_id']] = (int) $row['qty'];
}
$showStock = (bool) $catalog->fields['show_stock'];
$showPrices = (bool) $catalog->fields['show_prices'];

// Склад, с которого сотрудник будет получать. Наличие считаем по нему:
// сумма по всем складам обещала бы товар, которого в нужном месте нет.
$pickWarehouse = Warehouse::getDefaultFor($catalogs_id);
$pickId = $pickWarehouse !== null ? $pickWarehouse->getID() : 0;
$pickName = $pickWarehouse !== null ? (string) $pickWarehouse->fields['name'] : '';

/* ---------------- подготовка каталога: категории, поиск, сортировка ------- */
$items = [];
$categories = [];
foreach ($allProducts as $pid => $row) {
    $p = new Product();
    if (!$p->getFromDB((int) $pid)) {
        continue;
    }
    $cid = $p->categoryId();
    $cname = $p->categoryName();
    if ($cid > 0 && $cname !== '') {
        $categories[$cid] = $cname;
    }
    $items[] = [
        'id'    => (int) $pid,
        'p'     => $p,
        'label' => $p->label(),
        'ref'   => $p->ref(),
        'cat'   => $cid,
        'price' => $p->price(),
        'free'  => Stock::freeAt((int) $pid, $pickId),
        'total' => Stock::freeTotal((int) $pid),
        'pic'   => $p->pictureUrl(),
        'descr' => $p->description(),
        'paid'  => $p->isChargeable(),
        'rate'  => Rating::summary((int) $pid),
        'mine'  => Rating::forUser((int) $pid, $me),
        'may'   => Rating::mayRate((int) $pid, $me),
    ];
}
// Полосу под картинку показываем, только если хоть у одной позиции витрины
// картинка есть. Иначе весь каталог — это ряды одинаковых заглушек, которые
// занимают место и ничего не сообщают.
$withPictures = false;
foreach ($items as $it) {
    if ($it['pic'] !== '') {
        $withPictures = true;
        break;
    }
}
asort($categories, SORT_NATURAL | SORT_FLAG_CASE);

$found = array_filter($items, static function (array $it) use ($q, $catFilter, $onlyStock): bool {
    if ($catFilter > 0 && $it['cat'] !== $catFilter) {
        return false;
    }
    if ($onlyStock && $it['free'] <= 0) {
        return false;
    }
    if ($q === '') {
        return true;
    }
    $needle = mb_strtolower($q);
    return str_contains(mb_strtolower($it['label']), $needle)
        || str_contains(mb_strtolower($it['ref']), $needle);
});

usort($found, static function (array $a, array $b) use ($sort): int {
    switch ($sort) {
        case 'price_asc':
            return $a['price'] <=> $b['price'];
        case 'price_desc':
            return $b['price'] <=> $a['price'];
        case 'stock':
            return $b['free'] <=> $a['free'];
    }
    return strnatcasecmp($a['label'], $b['label']);
});

$total = count($found);
$pages = max(1, (int) ceil($total / $perPage));
if ($page > $pages) {
    $page = $pages;
}
$shown = array_slice($found, ($page - 1) * $perPage, $perPage);

/* ---------------- шапка ---------------- */
$cartQty = 0;
foreach ($cart as $row) {
    $cartQty += (int) $row['qty'];
}

echo '<div class="container-fluid mt-3">';
printf(
    '<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">'
    . '<h2 class="mb-0"><i class="%s me-2"></i>%s</h2>'
    . '<div class="d-flex gap-2 align-items-center">%s'
    . __('<a href="%s" class="btn btn-sm btn-outline-secondary">Все витрины</a></div></div>', 'storefront'),
    $esc($catalog->fields['icon']),
    $esc($catalog->fields['name']),
    $cartQty > 0
        ? sprintf('<span class="badge bg-primary-lt"><i class="ti ti-shopping-cart me-1"></i>'
            . __('в корзине %d</span>', 'storefront'), $cartQty)
        : '',
    $esc($self)
);
if (trim((string) $catalog->fields['description']) !== '') {
    echo '<p class="text-muted mb-2 sf-lead">' . $esc($catalog->fields['description']) . '</p>';
}
if (trim((string) $catalog->fields['header']) !== '') {
    echo '<p class="text-muted sf-lead">' . $esc($catalog->fields['header']) . '</p>';
}

// Кнопки «минус-плюс» на карточках: правят число в поле рядом, ничего
// не отправляя. Отправку делает кнопка «В корзину» — так на медленной
// сети не получается очереди из запросов на каждый щелчок.
echo Html::scriptBlock(<<<'JS'
function sfStep(btn, delta) {
    var input = btn.parentNode.querySelector('input[name="qty"]');
    if (!input) { return; }
    var min = parseInt(input.getAttribute('min') || '0', 10);
    var value = parseInt(input.value || '0', 10);
    if (isNaN(value)) { value = 0; }
    value += delta;
    if (value < min) { value = min; }
    input.value = value;
    if (input.dataset.autosubmit === '1') { input.form.requestSubmit(); }
}
JS);

// Ширина страницы — настройка витрины: правило действует только здесь.
if ($catalog->isWideLayout()) {
    echo Ui::wideLayoutStyle();
}

echo '<div class="row g-3">';

/* ================================================== каталог ============== */
echo '<div class="col-12 col-lg-8 sf-items">';

/* ---------------- доска объявлений ---------------- */
// Над карточками, а не внизу страницы: правила приёма нужно прочитать
// до того, как человек собрал корзину и упёрся в отказ.
if ($catalog->announcement() !== '') {
    printf('<div class="alert alert-%s sf-announce"><div class="d-flex gap-2">'
        . '<i class="ti ti-speakerphone fs-3"></i><div>%s</div></div></div>',
        $esc($catalog->announcementLevel()),
        nl2br($esc($catalog->announcement())));
}

/* ---------------- поиск и фильтры ---------------- */
echo '<div class="card mb-3"><div class="card-body py-2">';
echo '<form method="get" action="' . $esc($self) . '" class="row g-2 align-items-end">';
echo Html::hidden('catalog', ['value' => $catalogs_id]);
echo Html::hidden('recipient_type', ['value' => $rtype]);
if ($ruser > 0) {
    echo Html::hidden('users_id_recipient', ['value' => $ruser]);
}
if ($rgroup > 0) {
    echo Html::hidden('groups_id_recipient', ['value' => $rgroup]);
}
if ($rentity > 0) {
    echo Html::hidden('entities_id_recipient', ['value' => $rentity]);
}
if ($rnote !== '') {
    echo Html::hidden('recipient_note', ['value' => $rnote]);
}

printf(__('<div class="col-12 col-md-3"><label class="form-label mb-1">Поиск</label>', 'storefront')
    . '<input type="search" name="q" value="%s" class="form-control form-control-sm" '
    . __('placeholder="название или артикул"></div>', 'storefront'), $esc($q));

if (count($categories)) {
    echo __('<div class="col-6 col-md-3"><label class="form-label mb-1">Категория</label>', 'storefront')
        . '<select name="cat" class="form-select form-select-sm">'
        . __('<option value="0">все</option>', 'storefront');
    foreach ($categories as $cid => $cname) {
        printf('<option value="%d"%s>%s</option>', (int) $cid,
            (int) $cid === $catFilter ? ' selected' : '', $esc($cname));
    }
    echo '</select></div>';
}

echo __('<div class="col-6 col-md-3"><label class="form-label mb-1">Сортировка</label>', 'storefront')
    . '<select name="sort" class="form-select form-select-sm">';
foreach ([
    'name'       => __('по названию', 'storefront'),
    'price_asc'  => __('сначала дешёвые', 'storefront'),
    'price_desc' => __('сначала дорогие', 'storefront'),
    'stock'      => __('сначала то, чего много', 'storefront'),
] as $k => $lb) {
    if (!$showPrices && str_starts_with($k, 'price')) {
        continue;
    }
    printf('<option value="%s"%s>%s</option>', $esc($k), $k === $sort ? ' selected' : '', $esc($lb));
}
echo '</select></div>';

printf('<div class="col-12 col-md-3"><label class="form-label mb-1 d-block">&nbsp;</label>'
    . '<div class="d-flex gap-2 align-items-center">'
    . '<div class="form-check mb-0 text-nowrap">'
    . '<input class="form-check-input" type="checkbox" name="instock" value="1" '
    . 'id="sf-instock"%s>'
    . __('<label class="form-check-label small" for="sf-instock">в наличии</label></div>', 'storefront')
    . __('<button class="btn btn-sm btn-primary flex-fill">Показать</button>', 'storefront')
    . '</div></div>',
    $onlyStock ? ' checked' : '');
echo '</form>';

if ($showStock && $pickName !== '') {
    printf(__('<div class="text-muted small mt-1">Наличие показано по складу «%s».</div>', 'storefront'),
        $esc($pickName));
}
if ($q !== '' || $catFilter > 0 || $onlyStock || $sort !== 'name') {
    printf('<div class="mt-2 d-flex gap-2 align-items-center flex-wrap">'
        . __('<span class="text-muted small">Найдено позиций: <b>%d</b> из %d.</span>', 'storefront')
        . __('<a class="btn btn-sm btn-link p-0" href="%s">Сбросить фильтр</a></div>', 'storefront'),
        $total,
        count($items),
        $esc($shopUrl($catalogs_id, ['q' => null, 'cat' => null,
            'instock' => null, 'sort' => null, 'page' => null]))
    );
}
echo '</div></div>';

/* ---------------- наборы ---------------- */
$kits = Kit::visibleFor($catalogs_id, $me);
if (count($kits)) {
    echo '<div class="card mb-3"><div class="card-body">';
    echo __('<div class="fw-bold mb-1">Готовые наборы</div>', 'storefront');
    echo __('<div class="text-muted small mb-2">Одна кнопка кладёт в корзину сразу ', 'storefront')
        . __('всё, что входит в набор. Потом можно что-то убрать или добавить.</div>', 'storefront');
    echo '<div class="row g-2">';
    foreach ($kits as $kid => $k) {
        $kit = new Kit();
        $kit->getFromDB((int) $kid);
        $lines = $kit->items();
        if (!count($lines)) {
            continue;
        }
        $what = [];
        foreach ($lines as $l) {
            $kp = new Product();
            if ($kp->getFromDB((int) $l['plugin_storefront_products_id'])) {
                $what[] = $kp->label() . ' × ' . (int) $l['qty'];
            }
        }
        echo '<div class="col-12 col-md-6"><div class="border rounded p-2 h-100 '
            . 'd-flex flex-column gap-1">';
        printf('<div><i class="%s me-1"></i><b>%s</b></div>',
            $esc($k['icon'] ?: 'ti ti-briefcase'), $esc($k['name']));
        if (trim((string) $k['comment']) !== '') {
            printf('<div class="text-muted small">%s</div>', $esc((string) $k['comment']));
        }
        printf('<div class="text-muted small">%s</div>', $esc(implode(', ', $what)));
        if ($showPrices) {
            printf(__('<div class="text-muted small">Ориентировочно %s</div>', 'storefront'),
                Html::formatNumber($kit->price()));
        }
        echo '<form method="post" action="' . $esc($shopUrl($catalogs_id)) . '" class="mt-auto">';
        echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
        echo Html::hidden('kits_id', ['value' => (int) $kid]);
        echo '<button class="btn btn-sm btn-outline-primary mt-1" name="take_kit" value="1">'
            . __('Взять набор</button>', 'storefront');
        echo '</form>';
        echo '</div></div>';
    }
    echo '</div></div></div>';
}

/* ---------------- сетка товаров ---------------- */
echo '<div class="row g-3">';
if (!count($items)) {
    echo '<div class="col-12"><div class="alert alert-info">'
        . __('В этой витрине пока нет позиций.</div></div>', 'storefront');
} elseif (!count($shown)) {
    printf('<div class="col-12"><div class="alert alert-warning">'
        . __('По запросу ничего не нашлось. <a href="%s">Показать все позиции</a>.</div></div>', 'storefront'),
        $esc($shopUrl($catalogs_id, ['q' => null, 'cat' => null,
            'instock' => null, 'page' => null])));
}

foreach ($shown as $it) {
    $p = $it['p'];
    $pid = $it['id'];
    $free = $it['free'];
    $have = $inCart[$pid] ?? 0;

    echo '<div class="col-12 col-sm-6 col-xl-4 sf-card"><div class="card h-100'
        . ($have > 0 ? ' border-primary' : '') . '"><div class="card-body '
        . 'd-flex flex-column gap-2">';

    // Картинка из карточки номенклатуры GLPI. Заглушка рисуется только там,
    // где у соседних позиций картинки есть, — чтобы карточки в ряду
    // не разъезжались по высоте.
    if ($withPictures) {
        if ($it['pic'] !== '') {
            printf('<div class="text-center"><img src="%s" alt="" class="img-fluid" '
                . 'style="height:120px;object-fit:contain"></div>', $esc($it['pic']));
        } else {
            echo '<div class="text-center text-muted d-flex align-items-center '
                . 'justify-content-center" style="height:120px">'
                . '<i class="ti ti-photo-off" style="font-size:2.5rem;opacity:.2"></i></div>';
        }
    }

    printf('<div class="fw-bold">%s</div>', $esc($it['label']));
    printf('<div class="text-muted small font-monospace">%s%s</div>',
        $esc($it['ref']),
        $esc(($it['ref'] !== '' ? ' · ' : '') . $p->fields['unit']));
    if ($it['cat'] > 0) {
        printf('<div><a class="badge bg-secondary-lt text-decoration-none" href="%s">%s</a></div>',
            $esc($shopUrl($catalogs_id, ['cat' => $it['cat'], 'page' => null])),
            $esc($categories[$it['cat']] ?? ''));
    }
    // Цену показываем, если витрина её показывает или позиция платная.
    // Бесплатную выдачу подписываем явно: пустое место читается как
    // «цену забыли заполнить».
    if ($showPrices || $it['paid']) {
        printf(__('<div class="fs-4 fw-bold">%s <span class="fs-6 fw-normal text-muted">₽</span>%s</div>', 'storefront'),
            Html::formatNumber($it['price']),
            $it['paid'] && !$showPrices
                ? __(' <span class="badge bg-orange-lt align-middle">платно</span>', 'storefront') : '');
    } else {
        echo __('<div><span class="badge bg-green-lt">бесплатно</span></div>', 'storefront');
    }
    if ($it['descr'] !== '') {
        printf('<div class="text-muted small">%s</div>', $esc($it['descr']));
    }
    if ($it['rate']['count'] > 0) {
        printf('<div class="small"><span class="text-warning">%s</span> '
            . __('<span class="text-muted">%s · оценок %d</span></div>', 'storefront'),
            $esc(Rating::stars($it['rate']['avg'])),
            $esc(number_format($it['rate']['avg'], 1, ',', '')),
            $it['rate']['count']);
    }
    if ($showStock) {
        $elsewhere = $it['total'] - $free;
        echo '<div>' . ($free <= 0
            ? __('<span class="badge bg-danger-lt">нет в наличии</span>', 'storefront')
            : __('<span class="badge bg-green-lt">в наличии ', 'storefront') . $free . '</span>');
        if ($elsewhere > 0) {
            printf(__(' <span class="text-muted small" title="На других складах витрины">', 'storefront')
                . __('+%d на других складах</span>', 'storefront'), $elsewhere);
        }
        echo '</div>';
    }

    echo '<form method="post" action="' . $esc($shopUrl($catalogs_id)) . '" class="mt-auto">';
    echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
    echo Html::hidden('products_id', ['value' => $pid]);
    echo '<div class="input-group input-group-sm mb-1">';
    echo '<button class="btn btn-outline-secondary" type="button" '
        . __('onclick="sfStep(this,-1)" aria-label="меньше">&minus;</button>', 'storefront');
    printf('<input type="number" class="form-control text-center" name="qty" min="1" step="1" '
        . __('value="%d" aria-label="количество">', 'storefront'), $have > 0 ? $have : 1);
    echo '<button class="btn btn-outline-secondary" type="button" '
        . __('onclick="sfStep(this,1)" aria-label="больше">+</button>', 'storefront');
    echo '</div>';
    printf('<button class="btn btn-sm w-100 %s" type="submit" name="put" value="1">%s</button>',
        $have > 0 ? 'btn-outline-primary' : 'btn-primary',
        $have > 0 ? __('В корзине ', 'storefront') . $have . __(' — изменить', 'storefront') : __('В корзину', 'storefront'));
    echo '</form>';

    // Оценку показываем только тем, кто позицию получал: остальным она
    // ни о чём не говорит, а кнопка, которая всё равно откажет, раздражает.
    if ($it['may']) {
        $mineStars = $it['mine'] !== null ? (int) $it['mine']->fields['stars'] : 0;
        echo '<form method="post" action="' . $esc($shopUrl($catalogs_id))
            . '" class="mt-1 d-flex gap-1 align-items-center">';
        echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
        echo Html::hidden('products_id', ['value' => $pid]);
        printf('<span class="text-muted small">%s</span>',
            $mineStars > 0 ? __('Ваша оценка:', 'storefront') : __('Оценить:', 'storefront'));
        for ($star = 1; $star <= Rating::MAX; $star++) {
            printf('<button class="btn btn-sm btn-link p-0 %s" style="text-decoration:none" '
                . __('name="rate" value="%d" title="%d из 5">%s</button>', 'storefront'),
                $star <= $mineStars ? 'text-warning' : 'text-muted',
                $star, $star,
                $star <= $mineStars ? '★' : '☆');
        }
        // Отзыв уходит вместе с нажатой звездой: отдельная кнопка «сохранить»
        // здесь только мешала бы — оценка ставится одним движением.
        printf('<input type="text" name="note" value="%s" maxlength="255" '
            . 'class="form-control form-control-sm ms-1" style="max-width:220px" '
            . 'placeholder="%s">',
            $esc($it['mine'] !== null ? (string) $it['mine']->fields['comment'] : ''),
            $mineStars > 0 ? __('изменить отзыв', 'storefront') : __('коротко: чем понравилось', 'storefront'));
        echo '</form>';
    }
    echo '</div></div></div>';
}
echo '</div>';

/* ---------------- страницы ---------------- */
if ($pages > 1) {
    echo '<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">';
    for ($i = 1; $i <= $pages; $i++) {
        printf('<li class="page-item%s"><a class="page-link" href="%s">%d</a></li>',
            $i === $page ? ' active' : '',
            $esc($shopUrl($catalogs_id, ['page' => $i])),
            $i);
    }
    echo '</ul></nav>';
    printf(__('<div class="text-center text-muted small">Показано %d–%d из %d</div>', 'storefront'),
        ($page - 1) * $perPage + 1, min($page * $perPage, $total), $total);
}
echo '</div>';

/* ================================================== корзина ============== */
echo '<div class="col-12 col-lg-4 sf-cart"><div style="position:sticky;top:1rem">';
echo '<div class="card"><div class="card-body">';
printf(__('<div class="fw-bold mb-2"><i class="ti ti-shopping-cart me-1"></i>Корзина%s</div>', 'storefront'),
    count($cart) ? __(' <span class="text-muted fw-normal">— позиций ', 'storefront') . count($cart) . '</span>' : '');

if (!count($cart)) {
    echo __('<div class="text-muted">Пока пусто. Добавьте позиции из каталога.</div>', 'storefront');
} else {
    $sum = 0.0;
    $paidSum = 0.0;
    $lines = [];
    foreach ($cart as $row) {
        $p = new Product();
        if (!$p->getFromDB((int) $row['plugin_storefront_products_id'])) {
            continue;
        }
        $qty = (int) $row['qty'];
        $lines[] = ['products_id' => $p->getID(), 'qty' => $qty, 'price' => $p->price()];
        $sum += $p->price() * $qty;
        if ($p->isChargeable()) {
            $paidSum += $p->price() * $qty;
        }
        $free = Stock::freeAt($p->getID(), $pickId);

        echo '<div class="border-bottom py-2">';
        printf('<div class="d-flex justify-content-between align-items-start gap-2">'
            . '<div class="me-2"><div>%s</div>'
            . '<div class="text-muted small">%s%s</div></div>%s</div>',
            $esc($p->label()),
            $esc((string) $p->fields['unit']),
            ($showPrices || $p->isChargeable())
                ? ' · ' . Html::formatNumber($p->price()) . __(' за ед.', 'storefront')
                    . ($p->isChargeable() && !$showPrices ? __(' (платно)', 'storefront') : '')
                : '',
            ($showPrices || $p->isChargeable())
                ? '<div class="text-nowrap fw-bold">'
                    . Html::formatNumber($p->price() * $qty) . '</div>'
                : ''
        );
        if ($showStock && $free < $qty) {
            printf(__('<div class="text-danger small">На складе «%s» свободно %d — ', 'storefront')
                . __('выдадут столько, сколько есть.</div>', 'storefront'), $esc($pickName), $free);
        }
        // Количество меняется здесь же: уходить за этим в каталог неудобно.
        echo '<form method="post" action="' . $esc($shopUrl($catalogs_id))
            . '" class="d-flex gap-1 mt-1">';
        echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
        echo Html::hidden('products_id', ['value' => $p->getID()]);
        echo '<div class="input-group input-group-sm" style="max-width:150px">';
        echo '<button class="btn btn-outline-secondary" type="button" '
            . 'onclick="sfStep(this,-1)">&minus;</button>';
        printf('<input type="number" class="form-control text-center" name="qty" '
            . 'min="1" step="1" value="%d" data-autosubmit="1">', $qty);
        echo '<button class="btn btn-outline-secondary" type="button" '
            . 'onclick="sfStep(this,1)">+</button>';
        echo '</div>';
        echo '<button class="btn btn-sm btn-outline-primary" name="put" value="1" '
            . __('title="Применить количество">OK</button>', 'storefront');
        echo '<button class="btn btn-sm btn-outline-danger" name="drop" value="1" '
            . __('title="Убрать из корзины">&times;</button>', 'storefront');
        echo '</form>';
        echo '</div>';
    }

    /* ---------------- для кого заказ ---------------- */
    // Отдельная форма методом GET: смена получателя перерисовывает корзину,
    // потому что от него зависит, чей лимит показывать. Токен здесь не нужен —
    // форма ничего не меняет, только перечитывает страницу.
    echo '<div class="border rounded p-2 mt-3 mb-2">';
    echo '<form method="get" action="' . $esc($self) . '" id="sf-recipient">';
    echo Html::hidden('catalog', ['value' => $catalogs_id]);
    echo __('<label class="form-label fw-bold mb-1">Для кого заказ</label>', 'storefront');
    echo '<select name="recipient_type" class="form-select form-select-sm mb-2" '
        . 'onchange="this.form.submit()">';
    foreach (Order::recipientTypes() as $k => $label) {
        printf('<option value="%s"%s>%s</option>',
            $esc($k), $k === $rtype ? ' selected' : '', $esc($label));
    }
    echo '</select>';

    if ($rtype === Order::FOR_USER) {
        echo '<div class="mb-2">';
        User::dropdown([
            'name'      => 'users_id_recipient',
            'value'     => $ruser,
            'right'     => 'all',
            'width'     => '100%',
            'display'   => true,
            'comments'  => false,
            'on_change' => 'this.form.submit()',
        ]);
        echo '</div>';
    } elseif ($rtype === Order::FOR_GROUP) {
        echo '<div class="mb-2">';
        Group::dropdown([
            'name'      => 'groups_id_recipient',
            'value'     => $rgroup,
            'entity'    => $_SESSION['glpiactiveentities'] ?? [],
            'width'     => '100%',
            'display'   => true,
            'comments'  => false,
            'on_change' => 'this.form.submit()',
        ]);
        echo '</div>';
    } elseif ($rtype === Order::FOR_ENTITY) {
        echo '<div class="mb-2">';
        Entity::dropdown([
            'name'      => 'entities_id_recipient',
            'value'     => $rentity ?: ($_SESSION['glpiactive_entity'] ?? 0),
            'width'     => '100%',
            'display'   => true,
            'comments'  => false,
            'on_change' => 'this.form.submit()',
        ]);
        echo '</div>';
    }

    if ($rtype !== Order::FOR_SELF) {
        printf('<input type="text" name="recipient_note" value="%s" maxlength="255" '
            . 'class="form-control form-control-sm" '
            . __('placeholder="Уточнение: модуль, кабинет, мероприятие">', 'storefront'), $esc($rnote));
        echo '<button class="btn btn-sm btn-outline-secondary mt-2 w-100">'
            . __('Применить</button>', 'storefront');
    }
    echo '</form>';

    if ($rscope['scope'] !== 'user' || $rscope['items_id'] !== $me) {
        printf(__('<div class="text-muted small mt-2">Лимит расходуется по: %s.</div>', 'storefront'),
            $esc($rscope['scope'] === 'group'
                ? (__('отдел ', 'storefront') . Dropdown::getDropdownName('glpi_groups', $rscope['items_id']))
                : ($rscope['scope'] === 'entity'
                    ? (__('подразделение ', 'storefront') . Dropdown::getDropdownName('glpi_entities', $rscope['items_id']))
                    : (__('сотрудник ', 'storefront') . getUserName($rscope['items_id'])))));
    }
    echo '</div>';

    // Проверка лимитов до отправки — требование задания.
    $violations = Engine::checkLimits($catalog, $me, $lines, $rscope);
    foreach ($violations as $v) {
        printf(
            __('<div class="alert %s mt-2 mb-0 py-2 small">%s «%s»: %s — за период уже ', 'storefront')
            . __('получено %d из %d, в заказе ещё %d. %s</div>', 'storefront'),
            $v['is_hard'] ? 'alert-danger' : 'alert-warning',
            $v['is_hard'] ? __('Превышен жёсткий лимит', 'storefront') : __('Превышен лимит', 'storefront'),
            $esc((string) $v['limit']['name']),
            $esc((string) ($v['pool'] ?? __('ваша норма', 'storefront'))),
            $v['used'], $v['max'], $v['requested'],
            $v['is_hard'] ? __('Отправить нельзя.', 'storefront') : __('Отправить можно, потребуется обоснование.', 'storefront')
        );
    }
    $hardBlocked = false;
    foreach ($violations as $v) {
        if ($v['is_hard']) {
            $hardBlocked = true;
        }
    }

    if ($showPrices) {
        printf('<div class="d-flex justify-content-between mt-2 fs-5 fw-bold">'
            . __('<span>Итого</span><span>%s</span></div>', 'storefront'), Html::formatNumber($sum));
    } elseif ($paidSum > 0) {
        // Витрина цены прячет, но за платные позиции человек всё равно
        // заплатит — сумму по ним показываем отдельно.
        printf('<div class="d-flex justify-content-between mt-2 fs-5 fw-bold">'
            . __('<span>К оплате</span><span>%s</span></div>', 'storefront')
            . __('<div class="text-muted small">Остальное выдаётся бесплатно.</div>', 'storefront'),
            Html::formatNumber($paidSum));
    }

    echo '<form method="post" action="' . $esc($shopUrl($catalogs_id)) . '" class="mt-3">';
    echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
    // Получателя форма отправки берёт из адреса — дублируем, чтобы значение
    // дошло и при отправке методом POST.
    echo Html::hidden('recipient_type', ['value' => $rtype]);
    echo Html::hidden('users_id_recipient', ['value' => $ruser]);
    echo Html::hidden('groups_id_recipient', ['value' => $rgroup]);
    echo Html::hidden('entities_id_recipient', ['value' => $rentity]);
    echo Html::hidden('recipient_note', ['value' => $rnote]);
    printf(__('<div class="mb-2">Получатель: <b>%s</b></div>', 'storefront'), $esc(
        $rtype === Order::FOR_SELF
            ? __('я сам', 'storefront')
            : (Order::recipientTypes()[$rtype] . ': ' . ($rscope['scope'] === 'group'
                ? Dropdown::getDropdownName('glpi_groups', $rgroup)
                : ($rscope['scope'] === 'entity'
                    ? Dropdown::getDropdownName('glpi_entities', $rentity)
                    : ($ruser > 0 ? getUserName($ruser) : __('не указан', 'storefront')))))
    ));

    $warehouses = $catalog->warehouses(true);
    if (count($warehouses) > 1) {
        echo __('<label class="form-label">Где получить</label><select name="warehouses_id" ', 'storefront')
            . 'class="form-select form-select-sm mb-2">';
        foreach ($warehouses as $wid => $w) {
            printf('<option value="%d"%s>%s</option>', (int) $wid,
                (int) $w['is_default'] ? ' selected' : '', $esc($w['name']));
        }
        echo '</select>';
    } elseif (count($warehouses) === 1) {
        $wid = (int) array_key_first($warehouses);
        echo Html::hidden('warehouses_id', ['value' => $wid]);
        printf(__('<div class="text-muted small mb-2">Получение: %s</div>', 'storefront'),
            $esc($warehouses[$wid]['name']));
    }

    // Согласующего выбирает сам сотрудник. Список сужен порогом должности
    // витрины, чтобы заказ не ушёл тому, кто согласовывать не вправе.
    if ($catalog->isManualApproval()) {
        $suggested = Engine::suggestApprover($catalog, $me);
        printf(__('<label class="form-label">Кто согласует%s</label>', 'storefront'),
            $catalog->requiresApprover()
                ? __(' <span class="text-danger" title="Обязательно">*</span>', 'storefront')
                : '');
        // Штатный ajax-поиск: обычный список на сорока пяти тысячах
        // пользователей неработоспособен. Условие сужает выбор до должностей,
        // дающих право согласования.
        User::dropdown([
            'name'      => 'users_id_approver',
            'value'     => $suggested,
            'right'     => 'all',
            'condition' => Engine::approverCondition($catalog, $me),
            'width'     => '100%',
            'display'   => true,
            'comments'  => false,
        ]);
        $titles = Engine::approverTitleIds($catalog);
        echo '<div class="text-muted small mb-2 mt-1">'
            . (count($titles)
                ? __('Показаны сотрудники с должностью не ниже требуемой витриной.', 'storefront')
                : __('Должности сотрудников не заполнены, поэтому список не сужен.', 'storefront'))
            . ($suggested > 0 ? __(' По умолчанию предложен ваш руководитель.', 'storefront') : '')
            . '</div>';
    }

    $needComment = $catalog->commentRequired();
    printf(__('<label class="form-label">Зачем нужен заказ%s</label>', 'storefront'),
        $needComment ? ' <span class="text-danger">*</span>' : '');
    printf('<textarea name="comment" class="form-control form-control-sm" rows="2" %s'
        . 'placeholder="%s">%s</textarea>',
        $needComment ? 'required ' : '',
        $needComment
            ? __('Например: закончились ручки в отделе кадров', 'storefront')
            : __('Комментарий (необязательно)', 'storefront'),
        $esc((string) ($_POST['comment'] ?? '')));
    echo '<div class="text-muted small mb-2">'
        . ($needComment
            ? __('Пояснение видит согласующий и кладовщик — без него заказ не отправить.', 'storefront')
            : __('Пояснение видит согласующий и кладовщик.', 'storefront'))
        . '</div>';

    printf('<button class="btn btn-primary w-100 mb-2" type="submit" name="checkout" value="1"%s>'
        . __('Отправить заказ</button>', 'storefront'), $hardBlocked ? ' disabled' : '');
    echo '<button class="btn btn-sm btn-outline-secondary w-100" type="submit" '
        . __('name="clear" value="1">Очистить корзину</button>', 'storefront');
    echo '</form>';
}
echo '</div></div>';

/* ---------------- мои заказы ---------------- */
$mine = (new Order())->find(
    ['users_id_requester' => $me, 'plugin_storefront_catalogs_id' => $catalogs_id],
    ['id DESC'],
    10
);
if (count($mine)) {
    echo '<div class="card mt-3"><div class="card-body">';
    echo __('<div class="fw-bold mb-2">Мои последние заказы</div>', 'storefront');
    // Ведём на заявку, а не на документ заказа: в самообслуживании своя
    // заявка сотруднику видна всегда, а карточка заказа — только тем,
    // у кого есть право на заказы магазина.
    $canSeeOrder = Session::haveRight('plugin_storefront_order', READ);
    foreach ($mine as $oid => $o) {
        $row = new Order();
        $row->getFromDB((int) $oid);
        $ticket = $row->getTicket();
        $href = '';
        if ($ticket !== null) {
            $href = $ticket->getLinkURL();
        } elseif ($canSeeOrder) {
            $href = Html::getPrefixedUrl(
                '/plugins/storefront/front/order.form.php?id=' . (int) $oid
            );
        }

        echo '<div class="border-bottom py-2">';
        printf('<div class="d-flex justify-content-between align-items-center gap-2">'
            . '<div>%s <span class="text-muted small">%s</span></div>'
            . '<span class="badge bg-%s-lt">%s</span></div>',
            $href !== ''
                ? sprintf('<a href="%s" class="fw-bold">№%d</a>', $esc($href), (int) $oid)
                : sprintf('<b>№%d</b>', (int) $oid),
            $esc(Html::convDate((string) $o['date_creation'])),
            Order::stateTone((string) $o['state']),
            $esc(Order::stateLabel((string) $o['state']))
        );
        printf(__('<div class="text-muted small">%s · позиций %d%s</div>', 'storefront'),
            $esc($row->recipientLabel()),
            (int) $o['lines_count'],
            $showPrices ? ' · ' . Html::formatNumber((float) $o['amount']) : '');

        // Полоса стадий: сотруднику важно видеть, где заказ застрял,
        // а не только его текущее название.
        echo '<div class="d-flex gap-1 mt-1">';
        foreach (Order::progress((string) $o['state']) as $step) {
            printf('<div class="flex-fill" title="%s"><div class="rounded" '
                . 'style="height:4px;background:%s"></div>'
                . '<div class="small text-muted" style="font-size:.7rem">%s</div></div>',
                $esc($step['label']),
                $step['done'] ? 'var(--tblr-primary)' : 'var(--tblr-border-color)',
                $esc($step['label']));
        }
        echo '</div>';

        echo '<div class="d-flex gap-1 mt-1 flex-wrap">';
        echo '<form method="post" action="' . $esc($shopUrl($catalogs_id)) . '">';
        echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
        echo Html::hidden('orders_id', ['value' => (int) $oid]);
        echo '<button class="btn btn-sm btn-outline-secondary" name="repeat" value="1">'
            . __('Повторить заказ</button>', 'storefront');
        echo '</form>';
        // Свою накладную сотрудник может распечатать сам — например, чтобы
        // подшить её у себя или показать в бухгалтерии.
        if ((string) $o['state'] === Order::ISSUED) {
            printf('<a class="btn btn-sm btn-outline-secondary" target="_blank" href="%s">'
                . __('Накладная</a>', 'storefront'),
                $esc(Plugin::getWebDir('storefront') . '/front/waybill.php?id=' . (int) $oid));
        }
        echo '</div>';
        echo '</div>';
    }
    echo '</div></div>';
}
echo '</div></div>';

echo '</div></div>';

$isHelpdesk ? Html::helpFooter() : Html::footer();
