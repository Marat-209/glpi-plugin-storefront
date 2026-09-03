<?php

namespace GlpiPlugin\Storefront;

use CommonDBTM;
use Session;

/**
 * Оценка позиции сотрудником.
 *
 * Оценку ставит только тот, кто позицию действительно получал: иначе это
 * не отзыв о вещи, а голосование за ассортимент. Право проверяется по
 * движениям склада — по факту выдачи, а не по факту заказа.
 */
class Rating extends CommonDBTM
{
    public static $rightname = 'plugin_storefront_order';

    public const MIN = 1;
    public const MAX = 5;

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? __('Оценки', 'storefront') : __('Оценка', 'storefront');
    }

    public static function getIcon()
    {
        return 'ti ti-star';
    }

    /**
     * Получал ли сотрудник эту позицию.
     *
     * Смотрим движения выдачи: получателем считается и тот, на кого заказ
     * оформлен, и тот, кто расписался в накладной за отдел, — оба держали
     * вещь в руках.
     */
    public static function mayRate(int $products_id, int $users_id): bool
    {
        if ($products_id <= 0 || $users_id <= 0) {
            return false;
        }
        return countElementsInTable(Movement::getTable(), [
            'plugin_storefront_products_id' => $products_id,
            'type'                          => Movement::OUT,
            'users_id_recipient'            => $users_id,
        ]) > 0;
    }

    /** Оценка сотрудника по позиции, если она есть. */
    public static function forUser(int $products_id, int $users_id): ?self
    {
        $r = new self();
        $found = $r->find([
            'plugin_storefront_products_id' => $products_id,
            'users_id'                      => $users_id,
        ], [], 1);
        if (!count($found)) {
            return null;
        }
        $r->getFromDB((int) array_key_first($found));
        return $r;
    }

    /**
     * Поставить или изменить оценку.
     *
     * Одна оценка на человека и позицию: повторная не добавляет строку,
     * а заменяет прежнюю — мнение может измениться, но весить оно должно
     * столько же.
     */
    public static function rate(
        int $products_id,
        int $users_id,
        int $stars,
        string $comment = ''
    ): bool {
        $stars = max(self::MIN, min(self::MAX, $stars));
        if (!self::mayRate($products_id, $users_id)) {
            Session::addMessageAfterRedirect(
                __('Оценить можно только то, что вы получали.', 'storefront'), false, ERROR
            );
            return false;
        }

        $existing = self::forUser($products_id, $users_id);
        $r = new self();
        if ($existing !== null) {
            return (bool) $r->update([
                'id'       => $existing->getID(),
                'stars'    => $stars,
                'comment'  => $comment,
                'date_mod' => Engine::now(),
            ]);
        }
        return (bool) $r->add([
            'plugin_storefront_products_id' => $products_id,
            'users_id'                      => $users_id,
            'stars'                         => $stars,
            'comment'                       => $comment,
            'date_creation'                 => Engine::now(),
            'date_mod'                      => Engine::now(),
        ]);
    }

    /**
     * Сводка по позиции.
     *
     * @return array{avg:float, count:int, stars:array<int,int>}
     */
    public static function summary(int $products_id): array
    {
        global $DB;

        $out = ['avg' => 0.0, 'count' => 0, 'stars' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0]];
        $sum = 0;
        foreach ($DB->request([
            'SELECT' => ['stars'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_storefront_products_id' => $products_id],
        ]) as $row) {
            $n = (int) $row['stars'];
            if ($n < self::MIN || $n > self::MAX) {
                continue;
            }
            $out['stars'][$n]++;
            $out['count']++;
            $sum += $n;
        }
        if ($out['count'] > 0) {
            $out['avg'] = round($sum / $out['count'], 1);
        }
        return $out;
    }

    /** Отзывы позиции с текстом, новые сверху. */
    public static function reviews(int $products_id, int $limit = 20): array
    {
        return (new self())->find(
            ['plugin_storefront_products_id' => $products_id],
            ['date_mod DESC'],
            $limit
        );
    }

    /** Звёзды строкой: и в списке, и в письме читается одинаково. */
    public static function stars(float $avg): string
    {
        $full = (int) floor($avg);
        return str_repeat('★', $full) . str_repeat('☆', self::MAX - $full);
    }
}
