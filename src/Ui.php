<?php

namespace GlpiPlugin\Storefront;

use Html;
use Session;

/**
 * Отрисовка. Пока простыми средствами: задача первой версии — рабочий сквозной
 * сценарий, оформление доводится после проверки механики.
 */
final class Ui
{
    /**
     * Вид витрины во всю ширину страницы.
     *
     * В самообслуживании GLPI держит содержимое в 1320 пикселях (класс
     * container-xl), поэтому на широком мониторе витрина занимает середину.
     * Настройка витрины снимает это ограничение — но только на своей
     * странице: класс ставится на <html> текущей страницы, поэтому остальное
     * самообслуживание не меняется.
     *
     * Вместе с шириной правится и сетка, иначе стало бы хуже: длинная строка
     * текста плохо читается, корзина превратилась бы в пустое поле, а карточки
     * остались бы по три в ряду. Правила включаются от 1400 пикселей — на
     * планшете и телефоне вид штатный.
     */
    public static function wideLayoutStyle(): string
    {
        $css = <<<'CSS'
@media (min-width: 1400px) {
    html.sf-wide .page-body.container-xl,
    html.sf-wide .header-container.container-xl { max-width: none; }
    html.sf-wide .page-body.container-xl { padding-left: 1.75rem; padding-right: 1.75rem; }
    /* Длинную строку не растягиваем: читать её иначе тяжело. */
    html.sf-wide .sf-lead,
    html.sf-wide .sf-announce { max-width: 104ch; }
    /* Каталог шире, корзина уже: пустое поле справа никому не нужно. */
    html.sf-wide .sf-items { flex: 0 0 auto; width: 78%; }
    html.sf-wide .sf-cart { flex: 0 0 auto; width: 22%; }
    /* Четыре карточки в ряду вместо трёх. */
    html.sf-wide .sf-card { flex: 0 0 auto; width: 25%; }
}
@media (min-width: 1900px) {
    html.sf-wide .sf-card { width: 20%; }
}
@media (min-width: 2400px) {
    html.sf-wide .sf-items { width: 82%; }
    html.sf-wide .sf-cart { width: 18%; }
    html.sf-wide .sf-card { width: 16.6666%; }
}
CSS;

        return '<style>' . $css . '</style>'
            . '<script>document.documentElement.classList.add("sf-wide");</script>';
    }

    private static function esc(?string $s): string
    {
        return htmlescape((string) $s);
    }

    /** Плитки витрин на странице каталога услуг. */
    public static function showCatalogTiles(array $catalogs): void
    {
        echo '<div class="storefront-tiles row g-3 mb-4">';
        foreach ($catalogs as $id => $c) {
            $url = Html::getPrefixedUrl('/plugins/storefront/front/shop.php?catalog=' . (int) $id);
            echo '<div class="col-12 col-sm-6 col-lg-3">';
            echo '<a class="card h-100 text-decoration-none" href="' . self::esc($url) . '">';
            echo '<div class="card-body d-flex flex-column gap-2">';
            echo '<i class="' . self::esc($c['icon'] ?: 'ti ti-package') . ' fs-2"></i>';
            echo '<div class="fw-bold">' . self::esc($c['name']) . '</div>';
            if (trim((string) $c['description']) !== '') {
                echo '<div class="text-muted small">' . self::esc($c['description']) . '</div>';
            }
            echo '</div></a></div>';
        }
        echo '</div>';
    }

    /** Остатки позиции по складам — вкладка на карточке номенклатуры. */
    public static function showStockForProduct(Product $product): void
    {
        global $DB;

        echo '<div class="m-3">';
        echo '<div class="fw-bold mb-2">' . self::esc($product->label()) . '</div>';
        echo '<table class="table table-sm"><thead><tr>'
            . __('<th>Склад</th><th class="text-end">На руках</th>', 'storefront')
            . __('<th class="text-end">Резерв</th><th class="text-end">Свободно</th>', 'storefront')
            . __('<th class="text-end">Порог</th><th class="text-end">Цель</th>', 'storefront')
            . __('<th>Состояние</th></tr></thead><tbody>', 'storefront');

        $any = false;
        foreach ($DB->request([
            'FROM'  => Stock::getTable(),
            'WHERE' => ['plugin_storefront_products_id' => $product->getID()],
        ]) as $row) {
            $any = true;
            $stock = new Stock();
            $stock->getFromDB((int) $row['id']);
            $wh = new Warehouse();
            $whName = $wh->getFromDB((int) $row['plugin_storefront_warehouses_id'])
                ? (string) $wh->fields['name'] : (__('Склад #', 'storefront') . (int) $row['plugin_storefront_warehouses_id']);

            $threshold = $product->thresholdFrom($stock);
            $target = $product->targetFrom($stock);
            $free = $stock->free();
            $tone = 'success';
            $label = __('норма', 'storefront');
            if ($free <= 0) {
                $tone = 'danger';
                $label = __('нет', 'storefront');
            } elseif ($threshold > 0 && $free < $threshold) {
                $tone = 'warning';
                $label = __('ниже порога', 'storefront');
            }

            printf(
                '<tr><td>%s</td><td class="text-end">%d</td><td class="text-end">%d</td>'
                . '<td class="text-end fw-bold">%d</td><td class="text-end">%s</td>'
                . '<td class="text-end">%s</td>'
                . '<td><span class="badge bg-%s-lt">%s</span></td></tr>',
                self::esc($whName),
                (int) $stock->fields['qty_on_hand'],
                (int) $stock->fields['qty_reserved'],
                $free,
                $threshold > 0 ? $threshold : '—',
                $target > 0 ? $target : '—',
                $tone,
                $label
            );
        }
        if (!$any) {
            echo __('<tr><td colspan="7" class="text-muted">Остатков по этой позиции ещё нет.</td></tr>', 'storefront');
        }
        echo '</tbody></table>';

        // Последние движения
        echo __('<div class="fw-bold mt-4 mb-2">Последние движения</div>', 'storefront');
        echo '<table class="table table-sm"><thead><tr>'
            . __('<th>Дата</th><th>Тип</th><th class="text-end">Кол-во</th>', 'storefront')
            . __('<th class="text-end">Было</th><th class="text-end">Стало</th>', 'storefront')
            . __('<th>Заказ</th><th>Комментарий</th></tr></thead><tbody>', 'storefront');
        $n = 0;
        foreach ($DB->request([
            'FROM'  => Movement::getTable(),
            'WHERE' => ['plugin_storefront_products_id' => $product->getID()],
            'ORDER' => 'date DESC',
            'LIMIT' => 20,
        ]) as $m) {
            $n++;
            printf(
                '<tr><td>%s</td><td>%s</td><td class="text-end">%s%d</td>'
                . '<td class="text-end">%d</td><td class="text-end">%d</td>'
                . '<td>%s</td><td class="text-muted small">%s</td></tr>',
                self::esc(Html::convDateTime((string) $m['date'])),
                self::esc(Movement::typeLabel((string) $m['type'])),
                (int) $m['qty'] > 0 && (string) $m['type'] === Movement::IN ? '+' : '',
                (int) $m['qty'],
                (int) $m['qty_before'],
                (int) $m['qty_after'],
                (int) $m['plugin_storefront_orders_id'] > 0
                    ? ('№' . (int) $m['plugin_storefront_orders_id']) : '—',
                self::esc((string) $m['comment'])
            );
        }
        if ($n === 0) {
            echo __('<tr><td colspan="7" class="text-muted">Движений пока нет.</td></tr>', 'storefront');
        }
        echo '</tbody></table></div>';
    }

    /** Заказ: состав, количества, состояние. */
    public static function showOrder(Order $order, bool $editable = false): void
    {
        $catalog = $order->getCatalog();
        $showPrices = $catalog !== null && $catalog->showsPrices();
        if (Session::haveRight('plugin_storefront_order', UPDATE)) {
            $showPrices = true;
        }

        echo '<div class="m-3">';
        printf(
            '<div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2 mb-3">'
            . __('<div><span class="fw-bold fs-4">Заказ №%d</span> ', 'storefront')
            . '<span class="text-muted">%s</span></div>'
            . '<span class="badge bg-%s-lt">%s</span></div>',
            $order->getID(),
            self::esc($catalog !== null ? (string) $catalog->fields['name'] : ''),
            Order::stateTone($order->state()),
            self::esc(Order::stateLabel($order->state()))
        );

        echo '<table class="table table-sm"><thead><tr>'
            . __('<th>Позиция</th><th>Ед.</th>', 'storefront')
            . __('<th class="text-end">Запрошено</th><th class="text-end">Утверждено</th>', 'storefront')
            . __('<th class="text-end">Выдано</th>', 'storefront')
            . ($showPrices ? __('<th class="text-end">Сумма</th>', 'storefront') : '')
            . __('<th>Причина изменения</th></tr></thead><tbody>', 'storefront');

        foreach ($order->lines() as $id => $l) {
            $line = new OrderItem();
            $line->getFromDB((int) $id);
            printf(
                '<tr><td>%s%s</td><td>%s</td><td class="text-end">%d</td>'
                . '<td class="text-end fw-bold">%d</td><td class="text-end">%d</td>%s'
                . '<td class="text-muted small">%s</td></tr>',
                self::esc((string) $l['name_snapshot']),
                (int) $l['is_free_text'] === 1
                    ? __(' <span class="badge bg-secondary-lt">нет в каталоге</span>', 'storefront') : '',
                self::esc((string) $l['unit_snapshot']),
                (int) $l['qty_requested'],
                (int) $l['qty_approved'],
                (int) $l['qty_issued'],
                $showPrices ? '<td class="text-end">'
                    . Html::formatNumber($line->amount()) . '</td>' : '',
                self::esc((string) $l['change_reason'])
            );
        }
        echo '</tbody></table>';

        printf(
            '<div class="d-flex justify-content-end gap-4 mt-2">'
            . __('<span>Позиций: <b>%d</b></span>', 'storefront')
            . __('<span>Запрошено: <b>%d</b></span>', 'storefront')
            . __('<span>Утверждено: <b>%d</b></span>', 'storefront')
            . __('<span>Выдано: <b>%d</b></span>%s</div>', 'storefront'),
            (int) $order->fields['lines_count'],
            (int) $order->fields['qty_requested'],
            (int) $order->fields['qty_approved'],
            (int) $order->fields['qty_issued'],
            $showPrices ? __('<span>Сумма: <b>', 'storefront')
                . Html::formatNumber((float) $order->fields['amount']) . '</b></span>' : ''
        );

        if ((string) $order->fields['approval_source'] === 'none') {
            echo __('<div class="alert alert-warning mt-3">Согласующий не определён автоматически: ', 'storefront')
                . __('у сотрудника не заполнен руководитель, а у витрины нет группы согласующих.</div>', 'storefront');
        }
        if ((int) $order->fields['is_auto_approved'] === 1) {
            echo __('<div class="alert alert-info mt-3">Заказ согласован автоматически: ', 'storefront')
                . __('он в пределах порога рутинных заказов витрины.</div>', 'storefront');
        }
        unset($editable);
        echo '</div>';
    }

    /**
     * Состав заказа текстом — уходит в описание заявки GLPI.
     *
     * Описание должно отвечать на вопросы согласующего и кладовщика без
     * открытия других экранов: кто просит, откуда он, для кого, где забирать,
     * что именно и зачем. Короткая табличка «позиция — количество» этого
     * не даёт, и человек идёт переспрашивать.
     */
    public static function orderAsText(Order $order): string
    {
        $catalog = $order->getCatalog();
        $requester = (int) $order->fields['users_id_requester'];
        $showPrices = $catalog !== null && $catalog->showsPrices();

        $out = [];
        $out[] = __('<p><strong>Заказ №', 'storefront') . $order->getID() . __('</strong> по витрине «', 'storefront')
            . self::esc($catalog !== null ? (string) $catalog->fields['name'] : '—') . '»</p>';

        // --- кто и откуда
        $rows = [];
        $rows[] = [__('Заказчик', 'storefront'), TicketLog::person($requester)];

        $sup = Engine::supervisorOf($requester);
        if ($sup > 0) {
            $rows[] = [__('Руководитель заказчика', 'storefront'), getUserName($sup)];
        }
        $rows[] = [__('Для кого заказ', 'storefront'), $order->recipientLabel()];
        if ($order->recipientId() !== $requester) {
            $rows[] = [__('Расписывается в накладной', 'storefront'), getUserName($order->recipientId())];
        }
        if (trim((string) ($order->fields['recipient_note'] ?? '')) !== '') {
            $rows[] = [__('Уточнение', 'storefront'), (string) $order->fields['recipient_note']];
        }

        // --- где получать
        $wh = $order->getWarehouse();
        if ($wh !== null) {
            $where = (string) $wh->fields['name'];
            $loc = (int) ($wh->fields['locations_id'] ?? 0);
            if ($loc > 0) {
                $where .= ' (' . \Dropdown::getDropdownName('glpi_locations', $loc) . ')';
            }
            $rows[] = [__('Место получения', 'storefront'), $where];
            if ((int) $wh->fields['users_id_tech'] > 0) {
                $rows[] = [__('Ответственный склада', 'storefront'), getUserName((int) $wh->fields['users_id_tech'])];
            }
        }
        $rows[] = [__('Подразделение заявки', 'storefront'),
            \Dropdown::getDropdownName('glpi_entities', (int) $order->fields['entities_id'])];

        // --- согласование
        if ((int) $order->fields['is_auto_approved'] === 1) {
            $rows[] = [__('Согласование', 'storefront'),
                __('не требуется — заказ в пределах порога рутинных заказов витрины', 'storefront')];
        } elseif ((int) $order->fields['users_id_approver'] > 0) {
            $rows[] = [__('Согласует', 'storefront'), TicketLog::person((int) $order->fields['users_id_approver'])];
        } elseif ($catalog !== null && (int) $catalog->fields['groups_id_approver'] > 0) {
            $rows[] = [__('Согласует', 'storefront'), __('группа «', 'storefront') . \Dropdown::getDropdownName(
                'glpi_groups', (int) $catalog->fields['groups_id_approver']) . '»'];
        }

        $out[] = '<table border="1" cellpadding="4" cellspacing="0">';
        foreach ($rows as $r) {
            $out[] = sprintf('<tr><td><strong>%s</strong></td><td>%s</td></tr>',
                self::esc($r[0]), self::esc($r[1]));
        }
        $out[] = '</table>';

        // --- состав
        $out[] = __('<p><strong>Состав заказа</strong></p>', 'storefront');
        $out[] = '<table border="1" cellpadding="4" cellspacing="0">';
        $out[] = __('<tr><th>№</th><th>Позиция</th><th>Артикул</th><th>Ед.</th>', 'storefront')
            . __('<th>Запрошено</th>', 'storefront') . ($showPrices ? __('<th>Цена</th><th>Сумма</th>', 'storefront') : '') . '</tr>';
        $n = 0;
        foreach ($order->lines() as $l) {
            $n++;
            $p = new Product();
            $ref = $p->getFromDB((int) $l['plugin_storefront_products_id']) ? $p->ref() : '';
            $out[] = sprintf(
                '<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%d</td>%s</tr>',
                $n,
                self::esc((string) $l['name_snapshot']),
                self::esc($ref),
                self::esc((string) $l['unit_snapshot']),
                (int) $l['qty_requested'],
                $showPrices
                    ? sprintf('<td>%s</td><td>%s</td>',
                        Html::formatNumber((float) $l['price_snapshot']),
                        Html::formatNumber((float) $l['price_snapshot'] * (int) $l['qty_requested']))
                    : ''
            );
        }
        $out[] = sprintf(
            __('<tr><td colspan="4"><strong>Итого</strong></td><td><strong>%d</strong></td>%s</tr>', 'storefront'),
            (int) $order->fields['qty_requested'],
            $showPrices
                ? '<td></td><td><strong>'
                    . Html::formatNumber((float) $order->fields['amount']) . '</strong></td>'
                : ''
        );
        $out[] = '</table>';

        if (trim((string) $order->fields['comment']) !== '') {
            $out[] = __('<p><strong>Зачем нужен заказ:</strong> ', 'storefront')
                . self::esc((string) $order->fields['comment']) . '</p>';
        }

        $out[] = __('<p><em>Ход выдачи фиксируется в ленте заявки: согласование, ', 'storefront')
            . __('корректировка количеств, готовность и выдача.</em></p>', 'storefront');
        return implode("\n", $out);
    }
}
