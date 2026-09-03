<?php

namespace GlpiPlugin\Storefront;

use CommonDBTM;
use CommonITILValidation;
use Session;

/**
 * Обработчики штатных точек расширения GLPI.
 *
 * Плагин не перехватывает отрисовку формы заявки и не добавляет своих статусов:
 * он только слушает штатное согласование и убирает за собой при удалении заявки.
 */
final class Hook
{
    /**
     * Согласующий ответил в привычной форме GLPI — двигаем заказ.
     * Отдельного интерфейса согласования у плагина нет намеренно.
     */
    public static function postValidationUpdate(CommonDBTM $validation): void
    {
        if (Engine::isInside()) {
            return;
        }
        if (!in_array('status', $validation->updates ?? [], true)) {
            return;
        }
        $tickets_id = (int) ($validation->fields['tickets_id'] ?? 0);
        if ($tickets_id <= 0) {
            return;
        }
        $order = Order::getForTicket($tickets_id);
        if ($order === null || $order->state() !== Order::APPROVAL) {
            return;
        }

        $status = (int) $validation->fields['status'];
        $comment = (string) ($validation->fields['comment_validation'] ?? '');

        // Кто именно ответил, не выясняем. В GLPI 11 это нигде не хранится:
        // поле users_id_validate объявлено устаревшим и не заполняется,
        // а на запрос к группе отвечает любой её участник. Значение имеет
        // сам факт ответа и его результат — их и записываем.
        $who = 0;

        if ($status === CommonITILValidation::ACCEPTED) {
            $order->onApproved($who, $comment);
            return;
        }
        if ($status === CommonITILValidation::REFUSED) {
            $order->onRejected($who, $comment);
        }
    }

    /**
     * Заявку удаляют навсегда — снимаем резерв и отвязываем заказ.
     * Сам заказ и его движения остаются: это учётные данные.
     */
    public static function prePurgeTicket(CommonDBTM $ticket): void
    {
        $order = Order::getForTicket((int) $ticket->getID());
        if ($order === null) {
            return;
        }
        if ($order->isOpen()) {
            $order->releaseReserve();
        }
        $order->update([
            'id'       => $order->getID(),
            'items_id' => 0,
            'comment'  => trim((string) $order->fields['comment'] . "\n"
                . __('Связанная заявка №', 'storefront') . $ticket->getID() . __(' удалена ', 'storefront')
                . Engine::now() . '.'),
        ]);
    }

    /**
     * Витрины на странице каталога услуг.
     * Штатная точка расширения display_service_catalog: сотрудник видит
     * плитки магазина рядом с обычными формами, отдельного портала нет.
     */
    public static function serviceCatalog(): void
    {
        $users_id = (int) (\Session::getLoginUserID() ?: 0);
        if ($users_id <= 0) {
            return;
        }
        $catalogs = Catalog::availableFor($users_id);
        if (!count($catalogs)) {
            return;
        }
        Ui::showCatalogTiles($catalogs);
    }
}
