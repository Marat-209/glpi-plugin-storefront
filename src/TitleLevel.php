<?php

namespace GlpiPlugin\Storefront;

use CommonDBTM;

/**
 * Уровень должности в грейдовой лестнице.
 *
 * Правило согласования формулируется как «не ниже главного специалиста»,
 * но в GLPI должность — плоский справочник без порядка. В реальной базе
 * таких должностей около девятисот, размечать их руками никто не станет,
 * поэтому уровень выводится из названия по шаблону, а администратор правит
 * только исключения — у них поднимается признак ручной правки, и пересчёт
 * их больше не трогает.
 */
class TitleLevel extends CommonDBTM
{
    public static $rightname = 'plugin_storefront_catalog';

    /** Ступени лестницы. Значения с промежутками, чтобы можно было вставить свою. */
    public const L_INTERN   = 10;  // стажёр, практикант
    public const L_JUNIOR   = 20;  // младший
    public const L_REGULAR  = 30;  // без префикса
    public const L_SENIOR   = 40;  // старший
    public const L_LEAD     = 50;  // ведущий
    public const L_CHIEF    = 60;  // главный
    public const L_HEAD     = 70;  // начальник, руководитель, директор
    public const L_TOP      = 80;  // заместитель директора и выше

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? __('Уровни должностей', 'storefront') : __('Уровень должности', 'storefront');
    }

    public static function getIcon()
    {
        return 'ti ti-stairs-up';
    }

    /** Человекочитаемое имя ступени. */
    public static function levelLabel(int $level): string
    {
        $map = [
            self::L_INTERN  => __('Стажёр', 'storefront'),
            self::L_JUNIOR  => __('Младший', 'storefront'),
            self::L_REGULAR => __('Специалист', 'storefront'),
            self::L_SENIOR  => __('Старший', 'storefront'),
            self::L_LEAD    => __('Ведущий', 'storefront'),
            self::L_CHIEF   => __('Главный', 'storefront'),
            self::L_HEAD    => __('Руководитель', 'storefront'),
            self::L_TOP     => __('Высшее руководство', 'storefront'),
        ];
        if (isset($map[$level])) {
            return $map[$level];
        }
        // Промежуточное значение, заданное вручную.
        return sprintf(__('Уровень %d', 'storefront'), $level);
    }

    /** Все ступени для выпадающего списка. */
    public static function levels(): array
    {
        $out = [];
        foreach ([self::L_INTERN, self::L_JUNIOR, self::L_REGULAR, self::L_SENIOR,
            self::L_LEAD, self::L_CHIEF, self::L_HEAD, self::L_TOP] as $l) {
            $out[$l] = self::levelLabel($l) . ' (' . $l . ')';
        }
        return $out;
    }

    /**
     * Вывести уровень из названия должности.
     *
     * Порядок проверок важен: сначала руководящие должности, потом префиксы
     * лестницы, иначе «Начальник отдела - Руководитель проекта 1 уровня»
     * попадёт в «Специалист» из-за отсутствия префикса.
     */
    public static function deriveLevel(string $title): int
    {
        $t = mb_strtolower(trim($title));
        if ($t === '') {
            return self::L_REGULAR;
        }

        // Убираем уточнения в скобках: «Преподаватель (Младший специалист)»
        // нередко описывает грейд именно в скобках, поэтому скобки НЕ режем,
        // а наоборот учитываем — они часто и есть носитель уровня.

        $has = static function (array $words) use ($t): bool {
            foreach ($words as $w) {
                if (mb_strpos($t, $w) !== false) {
                    return true;
                }
            }
            return false;
        };

        // Обслуживающие должности при руководителе руководителями не являются.
        // Проверяем до руководящих: иначе «Помощник руководителя» получил бы
        // право согласования, которого у него быть не должно. При этом грейд
        // в названии учитываем — «Помощник педагога (Младший специалист)»
        // остаётся младшим, — но выше специалиста такая должность не поднимается.
        $isAide = $has(['помощник', 'ассистент', 'секретар', 'референт',
            'assistant', 'secretar', 'aide', 'clerk']);

        // Высшее руководство
        if (!$isAide && $has(['заместитель директора', 'вице-президент', 'президент',
            'генеральный директор', 'первый заместитель',
            'deputy director', 'vice president', 'vice-president', 'president',
            'chief executive', 'ceo', 'cto', 'cfo', 'cio'])) {
            return self::L_TOP;
        }
        // Руководящие
        if (!$isAide && $has(['начальник', 'руководител', 'директор', 'управляющий',
            'заведующ', 'супервайзер', 'предсе',
            'head of', 'director', 'manager', 'supervisor', 'chairman'])) {
            return self::L_HEAD;
        }

        // Ступени лестницы по префиксу названия
        $level = self::L_REGULAR;
        if ($has(['главн', 'principal', 'chief '])) {
            $level = self::L_CHIEF;
        } elseif ($has(['ведущ', 'lead'])) {
            $level = self::L_LEAD;
        } elseif ($has(['старш', 'senior'])) {
            $level = self::L_SENIOR;
        } elseif ($has(['младш', 'junior'])) {
            $level = self::L_JUNIOR;
        } elseif ($has(['стажер', 'стажёр', 'практикант', 'студент', 'ученик',
            'intern', 'trainee', 'apprentice', 'student'])) {
            $level = self::L_INTERN;
        }

        // Обслуживающая должность не может дать право согласования.
        return $isAide ? min($level, self::L_REGULAR) : $level;
    }

    /** Уровень должности пользователя; ноль, если должность не указана. */
    public static function forUser(int $users_id): int
    {
        global $DB;
        $tid = 0;
        foreach ($DB->request([
            'SELECT' => ['usertitles_id'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['id' => $users_id],
        ]) as $r) {
            $tid = (int) $r['usertitles_id'];
        }
        if ($tid <= 0) {
            return 0;
        }
        return self::forTitle($tid);
    }

    /** Уровень должности по идентификатору справочника. */
    public static function forTitle(int $usertitles_id): int
    {
        if ($usertitles_id <= 0) {
            return 0;
        }
        $tl = new self();
        $found = $tl->find(['usertitles_id' => $usertitles_id], [], 1);
        if (count($found)) {
            $row = reset($found);
            return (int) $row['level'];
        }
        // Строки ещё нет — выводим на лету и запоминаем, чтобы не считать заново.
        $name = '';
        global $DB;
        foreach ($DB->request([
            'SELECT' => ['name'],
            'FROM'   => 'glpi_usertitles',
            'WHERE'  => ['id' => $usertitles_id],
        ]) as $r) {
            $name = (string) $r['name'];
        }
        $level = self::deriveLevel($name);
        $tl->add([
            'usertitles_id' => $usertitles_id,
            'level'         => $level,
            'is_manual'     => 0,
            'can_approve'   => $level >= self::L_CHIEF ? 1 : 0,
        ]);
        return $level;
    }

    /**
     * Разметить весь справочник должностей.
     * Ручные правки не затрагиваются. Возвращает счётчики.
     */
    public static function rebuild(bool $include_manual = false): array
    {
        global $DB;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $removed = 0;

        $existing = [];
        foreach ($DB->request(['FROM' => self::getTable()]) as $r) {
            $existing[(int) $r['usertitles_id']] = $r;
        }

        $alive = [];
        $tl = new self();
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_usertitles']) as $r) {
            $tid = (int) $r['id'];
            $alive[$tid] = true;
            $level = self::deriveLevel((string) $r['name']);
            $approve = $level >= self::L_CHIEF ? 1 : 0;

            if (!isset($existing[$tid])) {
                if ($tl->add([
                    'usertitles_id' => $tid,
                    'level'         => $level,
                    'is_manual'     => 0,
                    'can_approve'   => $approve,
                ])) {
                    $created++;
                }
                continue;
            }
            $row = $existing[$tid];
            if ((int) $row['is_manual'] === 1 && !$include_manual) {
                $skipped++;
                continue;
            }
            if ((int) $row['level'] !== $level) {
                if ($tl->update([
                    'id'          => (int) $row['id'],
                    'level'       => $level,
                    'can_approve' => $approve,
                ])) {
                    $updated++;
                }
            }
        }
        // Должность могли удалить из справочника — снимок по ней больше не о чем.
        // Без этой уборки сводка по разбору должностей считает несуществующее.
        foreach ($existing as $tid => $row) {
            if (isset($alive[$tid])) {
                continue;
            }
            if ($tl->delete(['id' => (int) $row['id']], true)) {
                $removed++;
            }
        }

        return ['created' => $created, 'updated' => $updated,
            'skipped' => $skipped, 'removed' => $removed];
    }

    /** Сводка по ступеням: сколько должностей и сколько людей на каждой. */
    public static function summary(): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request([
            'SELECT'   => [
                'l.level',
                'COUNT DISTINCT' => 'l.usertitles_id AS titles',
            ],
            'FROM'     => self::getTable() . ' AS l',
            'GROUPBY'  => 'l.level',
            'ORDER'    => 'l.level ASC',
        ]) as $r) {
            $out[(int) $r['level']] = [
                'level'  => (int) $r['level'],
                'label'  => self::levelLabel((int) $r['level']),
                'titles' => (int) $r['titles'],
                'people' => 0,
            ];
        }
        // Людей считаем отдельным запросом: соединение с сорока тысячами
        // пользователей в одном запросе с группировкой ощутимо медленнее.
        foreach ($DB->request([
            'SELECT'  => ['l.level', 'COUNT' => 'u.id AS people'],
            'FROM'    => 'glpi_users AS u',
            'INNER JOIN' => [
                self::getTable() . ' AS l' => [
                    'ON' => ['u' => 'usertitles_id', 'l' => 'usertitles_id'],
                ],
            ],
            'WHERE'   => ['u.is_deleted' => 0, 'u.is_active' => 1],
            'GROUPBY' => 'l.level',
        ]) as $r) {
            $lv = (int) $r['level'];
            if (isset($out[$lv])) {
                $out[$lv]['people'] = (int) $r['people'];
            }
        }
        return $out;
    }
}
