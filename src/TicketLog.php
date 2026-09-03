<?php

namespace GlpiPlugin\Storefront;

use ITILFollowup;
use ITILSolution;
use Session;
use Ticket;

/**
 * Запись хода заказа в заявку GLPI.
 *
 * Заявка — единственное место, куда смотрит и сотрудник, и руководитель, и
 * аудит. Поэтому каждый шаг склада пишется в её ленту обычным комментарием,
 * а не остаётся во внутренних полях плагина: иначе человек видит «на
 * согласовании» и не понимает, кто и чего от него ждёт.
 */
final class TicketLog
{
    /**
     * Добавить запись в ленту заявки.
     *
     * @param bool $private true — видно только исполнителям
     */
    public static function note(?Ticket $ticket, string $html, bool $private = false): bool
    {
        if ($ticket === null || $ticket->isNewItem()) {
            return false;
        }
        $was = Engine::isInside();
        return (bool) (new ITILFollowup())->add([
            'itemtype'        => Ticket::class,
            'items_id'        => $ticket->getID(),
            'content'         => $html,
            'is_private'      => $private ? 1 : 0,
            'requesttypes_id' => 0,
            // Без уведомлений: на каждый шаг склада письмо сотруднику —
            // это четыре письма на один заказ канцелярии.
            '_do_not_compute_status' => true,
        ]) || $was;
    }

    /**
     * Закрыть заявку решением.
     *
     * Именно решением, а не сменой статуса: в решении остаётся текст того,
     * что выдано, и штатные правила GLPI переводят такую заявку в «Закрыта»
     * по своему расписанию.
     *
     * Вызывается на всех концах процесса: выдача, отмена, отказ в
     * согласовании. Последний случай особый — там в сессии сидит
     * руководитель с правами самообслуживания, и GLPI отбрасывает смену
     * статуса заявки у того, кто не вправе её менять. Поэтому статус
     * проставляется от имени процесса, см. ниже.
     */
    public static function solve(?Ticket $ticket, string $html): bool
    {
        if ($ticket === null || $ticket->isNewItem()) {
            return false;
        }
        $ticket->getFromDB($ticket->getID());
        if (in_array((int) $ticket->fields['status'], [Ticket::SOLVED, Ticket::CLOSED], true)) {
            return true;
        }
        // GLPI сужает список полей, которые разрешено менять, до одного
        // идентификатора, если у сидящего в сессии нет права на изменение
        // заявок. Решение при этом записывается, а статус заявки молча
        // остаётся прежним: по отказу в согласовании заявка оставалась в
        // работе и продолжала накапливать просрочку по SLA, хотя заказ уже
        // мёртв. Заявку переводит в «Решена» не человек, а процесс по
        // настройке витрины, поэтому право поднимаем на одну операцию и
        // сразу возвращаем как было — в том числе при исключении.
        $elevated = !Session::isCron()
            && is_array($_SESSION['glpiactiveprofile'] ?? null)
            && !Session::haveRight('ticket', UPDATE);
        $had = $elevated && array_key_exists('ticket', $_SESSION['glpiactiveprofile']);
        $saved = $had ? $_SESSION['glpiactiveprofile']['ticket'] : null;
        if ($elevated) {
            $_SESSION['glpiactiveprofile']['ticket'] = (int) ($saved ?? 0) | UPDATE;
        }
        try {
            $added = (bool) (new ITILSolution())->add([
                'itemtype' => Ticket::class,
                'items_id' => $ticket->getID(),
                'content'  => $html,
                // Не назначать исполнителем того, кто в этот момент в сессии:
                // решение добавляет плагин, а не человек.
                '_disable_auto_assign' => true,
            ]);
            if ($added) {
                self::keepSolved($ticket);
            }
            return $added;
        } finally {
            // Возвращаем ровно то, что было: и значение, и само отсутствие ключа.
            if ($elevated) {
                if ($had) {
                    $_SESSION['glpiactiveprofile']['ticket'] = $saved;
                } else {
                    unset($_SESSION['glpiactiveprofile']['ticket']);
                }
            }
        }
    }

    /**
     * Оставить заявку в «Решена», а закрытие отдать GLPI.
     *
     * Если у организации выставлено «закрывать сразу», GLPI при добавлении
     * решения ставит заявке сразу «Закрыта». Для заказа это неверный итог:
     * человек должен успеть увидеть решение и, если он не согласен, вернуть
     * заявку в работу. Поэтому статус возвращаем в «Решена» — закроет её
     * штатное задание GLPI по своему расписанию.
     */
    private static function keepSolved(Ticket $ticket): void
    {
        $ticket->getFromDB($ticket->getID());
        if ((int) $ticket->fields['status'] !== Ticket::CLOSED) {
            return;
        }
        $ticket->update([
            'id'        => $ticket->getID(),
            'status'    => Ticket::SOLVED,
            'closedate' => 'NULL',
        ]);
        $ticket->getFromDB($ticket->getID());
    }

    /** Человек с должностью и подразделением — как это принято в служебке. */
    public static function person(int $users_id): string
    {
        if ($users_id <= 0) {
            return __('не указан', 'storefront');
        }
        $u = new \User();
        if (!$u->getFromDB($users_id)) {
            return __('не указан', 'storefront');
        }
        $parts = [getUserName($users_id)];

        $title = (int) ($u->fields['usertitles_id'] ?? 0);
        if ($title > 0) {
            $parts[] = \Dropdown::getDropdownName('glpi_usertitles', $title);
        }
        $loc = (int) ($u->fields['locations_id'] ?? 0);
        if ($loc > 0) {
            $parts[] = __('объект: ', 'storefront') . \Dropdown::getDropdownName('glpi_locations', $loc);
        }
        $groups = self::groupsOf($users_id);
        if ($groups !== '') {
            $parts[] = $groups;
        }
        if (trim((string) ($u->fields['phone'] ?? '')) !== '') {
            $parts[] = __('тел. ', 'storefront') . $u->fields['phone'];
        }
        return implode(', ', $parts);
    }

    /** Отделы сотрудника одной строкой. */
    public static function groupsOf(int $users_id): string
    {
        global $DB;

        $names = [];
        foreach ($DB->request([
            'SELECT'    => ['g.name'],
            'FROM'      => 'glpi_groups_users AS gu',
            'INNER JOIN' => ['glpi_groups AS g' => ['ON' => ['gu' => 'groups_id', 'g' => 'id']]],
            'WHERE'     => ['gu.users_id' => $users_id],
            'ORDER'     => 'g.name ASC',
            'LIMIT'     => 5,
        ]) as $r) {
            $names[] = (string) $r['name'];
        }
        return count($names) ? implode(', ', $names) : '';
    }

    /** Объект сотрудника: расположение из карточки. */
    public static function locationOf(int $users_id): string
    {
        $u = new \User();
        if (!$u->getFromDB($users_id)) {
            return '';
        }
        $loc = (int) ($u->fields['locations_id'] ?? 0);
        return $loc > 0 ? (string) \Dropdown::getDropdownName('glpi_locations', $loc) : '';
    }

    /** Текущее состояние согласования заявки — человеку и в ленту. */
    public static function approvalLine(?Ticket $ticket): string
    {
        if ($ticket === null || $ticket->isNewItem()) {
            return '';
        }
        $rows = (new \TicketValidation())->find(['tickets_id' => $ticket->getID()], ['id ASC']);
        if (!count($rows)) {
            return __('Согласование не требуется.', 'storefront');
        }
        $out = [];
        foreach ($rows as $v) {
            $target = (string) $v['itemtype_target'] === \Group::class
                ? __('группа «', 'storefront') . \Dropdown::getDropdownName('glpi_groups',
                    (int) $v['items_id_target']) . '»'
                : self::person((int) $v['items_id_target']);

            // Кто именно ответил, не показываем: GLPI 11 этого не хранит,
            // а на запрос к группе отвечает любой её участник. Домысливать
            // автора по адресату запроса — врать в документе.
            switch ((int) $v['status']) {
                case \CommonITILValidation::ACCEPTED:
                    $line = __('согласовано', 'storefront');
                    if (trim((string) $v['comment_validation']) !== '') {
                        $line .= ': ' . $v['comment_validation'];
                    }
                    break;
                case \CommonITILValidation::REFUSED:
                    $line = __('отказано', 'storefront');
                    if (trim((string) $v['comment_validation']) !== '') {
                        $line .= ': ' . $v['comment_validation'];
                    }
                    break;
                default:
                    $line = __('ожидает ответа', 'storefront');
            }
            $out[] = $target . ' — ' . $line;
        }
        return implode('; ', $out);
    }
}
