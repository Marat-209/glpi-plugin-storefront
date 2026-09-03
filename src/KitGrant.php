<?php

namespace GlpiPlugin\Storefront;

use CommonDBTM;
use Session;

/**
 * Разрешение получить разовый набор повторно.
 *
 * Стартовый набор по смыслу выдаётся один раз, но жизнь шире правила:
 * перевод в другое подразделение, утрата, ошибка выдачи. Вместо того чтобы
 * ослаблять правило для всех, администратор выдаёт разрешение конкретному
 * человеку; оно гасится в момент выдачи и не действует второй раз.
 */
class KitGrant extends CommonDBTM
{
    public static $rightname = 'plugin_storefront_catalog';

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? __('Разрешения на повторную выдачу', 'storefront') : __('Разрешение на повторную выдачу', 'storefront');
    }

    /** Есть ли у сотрудника непогашенное разрешение. */
    public static function has(int $kits_id, int $users_id): bool
    {
        return countElementsInTable(self::getTable(), [
            'plugin_storefront_kits_id' => $kits_id,
            'users_id'                  => $users_id,
            'is_used'                   => 0,
        ]) > 0;
    }

    /** Выдать разрешение. */
    public static function grant(
        int $kits_id,
        int $users_id,
        string $reason = ''
    ): bool {
        if ($kits_id <= 0 || $users_id <= 0) {
            return false;
        }
        if (self::has($kits_id, $users_id)) {
            Session::addMessageAfterRedirect(
                __('У сотрудника уже есть непогашенное разрешение на этот набор.', 'storefront'), false, WARNING
            );
            return false;
        }
        return (bool) (new self())->add([
            'plugin_storefront_kits_id' => $kits_id,
            'users_id'                  => $users_id,
            'users_id_author'           => (int) (Session::getLoginUserID() ?: 0),
            'is_used'                   => 0,
            'reason'                    => $reason,
            'date_creation'             => Engine::now(),
            'date_mod'                  => Engine::now(),
        ]);
    }

    /**
     * Погасить разрешение при выдаче.
     *
     * Гасим ровно одно и самое старое: если разрешений почему-то несколько,
     * выдача должна списать одно, а не обнулить все сразу.
     */
    public static function consume(int $kits_id, int $users_id): bool
    {
        $g = new self();
        $found = $g->find([
            'plugin_storefront_kits_id' => $kits_id,
            'users_id'                  => $users_id,
            'is_used'                   => 0,
        ], ['id ASC'], 1);
        if (!count($found)) {
            return false;
        }
        return (bool) $g->update([
            'id'       => (int) array_key_first($found),
            'is_used'  => 1,
            'date_mod' => Engine::now(),
        ]);
    }

    /** Разрешения набора: и погашенные, и нет — это история решений. */
    public static function forKit(int $kits_id, int $limit = 100): array
    {
        return (new self())->find(
            ['plugin_storefront_kits_id' => $kits_id],
            ['is_used ASC', 'id DESC'],
            $limit
        );
    }
}
