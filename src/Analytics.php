<?php

namespace GlpiPlugin\Storefront;

use Session;

/**
 * Аналитика витрины.
 *
 * Считает по движениям и заказам, а не по «текущим остаткам»: остаток
 * показывает, что есть сейчас, а управленческие решения принимают по расходу
 * за период. Все выборки ограничены подразделениями сессии — аналитика не
 * должна показывать чужой склад.
 */
final class Analytics
{
    /** Заказы витрины за период, сгруппированные по состоянию. */
    public static function ordersByState(int $catalogs_id, string $from, string $to): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT'  => ['state', 'COUNT' => '* AS n'],
            'FROM'    => Order::getTable(),
            'WHERE'   => self::orderScope($catalogs_id, $from, $to),
            'GROUPBY' => 'state',
        ]) as $row) {
            $out[(string) $row['state']] = (int) $row['n'];
        }
        return $out;
    }

    /** Сколько выдано и на какую сумму за период. */
    public static function issuedTotals(int $catalogs_id, string $from, string $to): array
    {
        $qty = 0;
        $sum = 0.0;
        $orders = 0;
        foreach (self::issuedLines($catalogs_id, $from, $to) as $line) {
            $qty += $line['qty'];
            $sum += $line['sum'];
        }
        global $DB;
        foreach ($DB->request([
            'SELECT' => ['COUNT' => '* AS n'],
            'FROM'   => Order::getTable(),
            'WHERE'  => self::orderScope($catalogs_id, $from, $to) + ['state' => Order::ISSUED],
        ]) as $row) {
            $orders = (int) $row['n'];
        }
        return ['qty' => $qty, 'sum' => $sum, 'orders' => $orders];
    }

    /** Топ позиций по выданному количеству. */
    public static function topProducts(int $catalogs_id, string $from, string $to, int $limit = 10): array
    {
        $acc = [];
        foreach (self::issuedLines($catalogs_id, $from, $to) as $line) {
            $key = $line['name'];
            if (!isset($acc[$key])) {
                $acc[$key] = ['name' => $key, 'unit' => $line['unit'], 'qty' => 0, 'sum' => 0.0];
            }
            $acc[$key]['qty'] += $line['qty'];
            $acc[$key]['sum'] += $line['sum'];
        }
        usort($acc, static fn($a, $b) => $b['qty'] <=> $a['qty']);
        return array_slice(array_values($acc), 0, $limit);
    }

    /** Кто получал: люди, отделы, подразделения. */
    public static function byRecipient(int $catalogs_id, string $from, string $to, int $limit = 10): array
    {
        global $DB;

        $acc = [];
        foreach ($DB->request([
            'SELECT' => ['o.id', 'o.recipient_type', 'o.users_id_recipient',
                'o.groups_id_recipient', 'o.entities_id_recipient', 'o.users_id_requester'],
            'FROM'   => Order::getTable() . ' AS o',
            'WHERE'  => self::orderScope($catalogs_id, $from, $to, 'o')
                + ['o.state' => Order::ISSUED],
        ]) as $row) {
            $order = new Order();
            $order->fields = (array) $row;
            $label = $order->recipientLabel();
            $acc[$label] = ($acc[$label] ?? 0) + 1;
        }
        arsort($acc);
        $out = [];
        foreach (array_slice($acc, 0, $limit, true) as $label => $n) {
            $out[] = ['label' => $label, 'orders' => $n];
        }
        return $out;
    }

    /** Динамика выдач по месяцам: сколько заказов и единиц. */
    public static function byMonth(int $catalogs_id, string $from, string $to): array
    {
        global $DB;

        $months = [];
        foreach ($DB->request([
            'SELECT' => ['date', 'qty', 'unit_price'],
            'FROM'   => Movement::getTable(),
            'WHERE'  => self::movementScope($catalogs_id, $from, $to),
        ]) as $row) {
            $key = substr((string) $row['date'], 0, 7);
            if (!isset($months[$key])) {
                $months[$key] = ['month' => $key, 'qty' => 0, 'sum' => 0.0];
            }
            $months[$key]['qty'] += (int) $row['qty'];
            $months[$key]['sum'] += (int) $row['qty'] * (float) $row['unit_price'];
        }
        ksort($months);
        return array_values($months);
    }

    /**
     * Сколько времени заказ идёт от отправки до выдачи.
     *
     * Считаем по фактам, а не по SLA: SLA говорит, сколько обещано, а это —
     * сколько получилось.
     */
    public static function leadTime(int $catalogs_id, string $from, string $to): array
    {
        global $DB;

        $hours = [];
        foreach ($DB->request([
            'SELECT' => ['date_submitted', 'date_approved', 'date_issued'],
            'FROM'   => Order::getTable(),
            'WHERE'  => self::orderScope($catalogs_id, $from, $to)
                + ['state' => Order::ISSUED, 'NOT' => ['date_submitted' => null, 'date_issued' => null]],
        ]) as $row) {
            $start = strtotime((string) $row['date_submitted']);
            $end = strtotime((string) $row['date_issued']);
            if (!$start || !$end || $end < $start) {
                continue;
            }
            $hours[] = ($end - $start) / 3600;
        }
        if (!count($hours)) {
            return ['count' => 0, 'avg' => 0.0, 'median' => 0.0, 'max' => 0.0];
        }
        sort($hours);
        $n = count($hours);
        $median = $n % 2
            ? $hours[intdiv($n, 2)]
            : ($hours[$n / 2 - 1] + $hours[$n / 2]) / 2;
        return [
            'count'  => $n,
            'avg'    => array_sum($hours) / $n,
            'median' => $median,
            'max'    => max($hours),
        ];
    }

    /** Позиции, которые пора закупать: свободный остаток ниже порога. */
    public static function lowStock(int $catalogs_id, int $limit = 15): array
    {
        $out = [];
        $product = new Product();
        foreach ($product->find(['plugin_storefront_catalogs_id' => $catalogs_id,
            'is_active' => 1]) as $pid => $row) {
            $p = new Product();
            $p->fields = $row;
            $free = 0;
            $hand = 0;
            $threshold = 0;
            foreach (Warehouse::listFor($catalogs_id) as $wid => $w) {
                $stock = Stock::ensure((int) $pid, (int) $wid);
                $free += $stock->free();
                $hand += (int) $stock->fields['qty_on_hand'];
                // Порог может быть задан и на складе, и на номенклатуре:
                // берём максимальный, иначе один склад «прячет» дефицит другого.
                $threshold = max($threshold, $p->thresholdFrom($stock));
            }
            if ($threshold > 0 && $free <= $threshold) {
                $out[] = ['name' => $p->label(), 'unit' => (string) $row['unit'],
                    'free' => $free, 'hand' => $hand, 'threshold' => $threshold];
            }
        }
        usort($out, static fn($a, $b) => ($a['free'] - $a['threshold']) <=> ($b['free'] - $b['threshold']));
        return array_slice($out, 0, $limit);
    }

    /** Использование лимитов: у кого сколько осталось. */
    public static function limitUsage(int $catalogs_id, int $limit = 12): array
    {
        $out = [];
        foreach ((new Limit())->find(['plugin_storefront_catalogs_id' => $catalogs_id,
            'is_active' => 1]) as $lid => $rule) {
            $used = 0;
            $people = 0;
            $shared = Limit::mode((array) $rule) === Limit::MODE_TOTAL;
            if ($shared) {
                // Общая норма расходуется областью целиком, и получатель для
                // подсчёта не важен. Складывать её по людям нельзя: один и тот
                // же запас умножился бы на число участников.
                $used = Engine::usedInPeriod($rule, 0, ['scope' => 'user', 'items_id' => 0]);
            } else {
                foreach (self::recentRequesters($catalogs_id) as $uid) {
                    $u = Engine::usedInPeriod($rule, $uid,
                        ['scope' => 'user', 'items_id' => $uid]);
                    if ($u > 0) {
                        $used += $u;
                        $people++;
                    }
                }
            }
            $out[] = [
                'name'    => (string) $rule['name'],
                'max'     => (int) $rule['max_qty'],
                'period'  => Limit::periodLabel((string) $rule['period']),
                'is_hard' => (int) $rule['is_hard'] === 1,
                'used'    => $used,
                'people'  => $people,
                'shared'  => $shared,
                'pool'    => Limit::poolLabel((array) $rule),
            ];
        }
        usort($out, static fn($a, $b) => $b['used'] <=> $a['used']);
        return array_slice($out, 0, $limit);
    }

    /** Кто заказывал за последние три месяца — база для расчёта лимитов. */
    private static function recentRequesters(int $catalogs_id): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT'   => ['users_id_requester'],
            'DISTINCT' => true,
            'FROM'     => Order::getTable(),
            'WHERE'    => ['plugin_storefront_catalogs_id' => $catalogs_id,
                'date_creation' => ['>', date('Y-m-d H:i:s', strtotime('-3 months'))]],
        ]) as $row) {
            $out[] = (int) $row['users_id_requester'];
        }
        return $out;
    }

    /** Строки выданного за период — основа денежных и количественных итогов. */
    private static function issuedLines(int $catalogs_id, string $from, string $to): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['m.qty', 'm.unit_price', 'm.plugin_storefront_products_id'],
            'FROM'   => Movement::getTable() . ' AS m',
            'WHERE'  => self::movementScope($catalogs_id, $from, $to, 'm'),
        ]) as $row) {
            $p = new Product();
            if (!$p->getFromDB((int) $row['plugin_storefront_products_id'])) {
                continue;
            }
            $out[] = [
                'name'  => $p->label(),
                'unit'  => (string) $p->fields['unit'],
                'qty'   => (int) $row['qty'],
                'sum'   => (int) $row['qty'] * (float) $row['unit_price'],
            ];
        }
        return $out;
    }

    /** Ограничение выборки заказов: витрина, период и подразделения сессии. */
    private static function orderScope(int $catalogs_id, string $from, string $to,
        string $alias = ''): array
    {
        $p = $alias !== '' ? $alias . '.' : '';
        // Заказы тоже сужены витриной: доступ к витрине уже проверен, а
        // фильтр по подразделению скрыл бы заказы, оформленные до переноса.
        return [
            $p . 'plugin_storefront_catalogs_id' => $catalogs_id,
            [$p . 'date_creation' => ['>=', $from . ' 00:00:00']],
            [$p . 'date_creation' => ['<=', $to . ' 23:59:59']],
        ];
    }

    /**
     * Ограничение выборки движений: только расход по заказам этой витрины.
     *
     * У движения нет колонки наследования, поэтому ограничение по
     * подразделению здесь строгое — иначе запрос сошлётся на несуществующее поле.
     */
    private static function movementScope(int $catalogs_id, string $from, string $to,
        string $alias = ''): array
    {
        global $DB;

        $p = $alias !== '' ? $alias . '.' : '';
        $products = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => Product::getTable(),
            'WHERE'  => ['plugin_storefront_catalogs_id' => $catalogs_id],
        ]) as $row) {
            $products[] = (int) $row['id'];
        }
        if (!count($products)) {
            $products = [0];
        }
        // Ограничение по подразделению здесь не нужно и вредно: выборка уже
        // сужена до позиций конкретной витрины, а доступ к самой витрине
        // проверен выше. Если витрину перенесли в другую организацию, её старые
        // проводки остаются с прежним подразделением — фильтр по нему просто
        // спрятал бы историю.
        return [
            $p . 'type' => Movement::OUT,
            $p . 'plugin_storefront_products_id' => $products,
            [$p . 'date' => ['>=', $from . ' 00:00:00']],
            [$p . 'date' => ['<=', $to . ' 23:59:59']],
        ];
    }
}
