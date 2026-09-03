<?php

namespace GlpiPlugin\Storefront;

use CommonDBTM;
use CronTask;

/**
 * Автоматические действия магазина. Регистрируются штатным CronTask::register
 * и видны в «Настройки → Автоматические действия» наравне с остальными.
 */
class Cron extends CommonDBTM
{
    public static function getTypeName($nb = 0)
    {
        return __('Магазин', 'storefront');
    }

    public static function cronInfo($name): array
    {
        $map = [
            'storefront_lowstock' => [
                'description' => __('Остатки ниже порога: расчёт потребности к закупке', 'storefront'),
            ],
            'storefront_cartcleanup' => [
                'description' => __('Очистка брошенных корзин', 'storefront'),
            ],
            'storefront_reserves' => [
                'description' => __('Сверка резерва складов с составом заказов', 'storefront'),
            ],
        ];
        return $map[$name] ?? [];
    }

    /**
     * Найти позиции ниже порога и посчитать потребность к закупке
     * как «целевой запас минус свободный остаток».
     *
     * @return int -1 ошибка, 0 нечего делать, 1 что-то сделано
     */
    public static function cronStorefront_lowstock(CronTask $task): int
    {
        global $DB;
        $low = 0;
        $need = 0;

        foreach ($DB->request([
            'FROM'  => Stock::getTable(),
            'ORDER' => 'plugin_storefront_warehouses_id ASC',
        ]) as $row) {
            $stock = new Stock();
            if (!$stock->getFromDB((int) $row['id'])) {
                continue;
            }
            $product = new Product();
            if (!$product->getFromDB((int) $row['plugin_storefront_products_id'])) {
                continue;
            }
            $threshold = $product->thresholdFrom($stock);
            if ($threshold <= 0) {
                continue;
            }
            $free = $stock->free();
            if ($free >= $threshold) {
                continue;
            }
            $low++;
            $target = $product->targetFrom($stock);
            if ($target > $free) {
                $need += $target - $free;
            }
            $task->addVolume(1);
        }

        if ($low > 0) {
            $task->log(sprintf(
                __('Позиций ниже порога: %d. Суммарная потребность к закупке: %d ед.', 'storefront'),
                $low,
                $need
            ));
            return 1;
        }
        return 0;
    }

    /**
     * Привести резерв складов к тому, что держат заказы.
     *
     * Оборвавшаяся выдача или удаление заказа мимо плагина оставляют резерв,
     * который никто уже не снимет: товар лежит на складе, но заказать его
     * нельзя. Сверка возвращает такие остатки в оборот.
     */
    public static function cronStorefront_reserves(CronTask $task): int
    {
        $diffs = Engine::reconcileReserves(true);
        if (!count($diffs)) {
            return 0;
        }
        foreach ($diffs as $d) {
            $task->addVolume(1);
            $task->log(sprintf(
                __('Позиция %d на складе %d: в остатках зарезервировано %d, заказы держат %d.', 'storefront'),
                $d['products_id'],
                $d['warehouses_id'],
                $d['stock'],
                $d['held']
            ));
        }
        $task->log(sprintf(__('Исправлено строк остатков: %d.', 'storefront'), count($diffs)));
        return 1;
    }

    /** Корзины старше тридцати дней — брошенные, их можно убрать. */
    public static function cronStorefront_cartcleanup(CronTask $task): int
    {
        global $DB;
        $cutoff = date('Y-m-d H:i:s', Engine::nowTs() - 30 * 86400);
        $n = 0;
        $c = new CartItem();
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => CartItem::getTable(),
            'WHERE'  => [['date_mod' => ['<', $cutoff]]],
        ]) as $row) {
            if ($c->delete(['id' => (int) $row['id']], true)) {
                $n++;
                $task->addVolume(1);
            }
        }
        if ($n > 0) {
            $task->log(sprintf(__('Удалено строк брошенных корзин: %d', 'storefront'), $n));
            return 1;
        }
        return 0;
    }
}
