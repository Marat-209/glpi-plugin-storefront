<?php

namespace GlpiPlugin\Storefront;

use CommonGLPI;
use Html;
use ProfileRight;
use Session;

/**
 * Права магазина на форме профиля GLPI.
 *
 * Форма профиля в ядре рисует только свои права: права плагинов туда не
 * попадают. Без этой вкладки права магазина существуют в базе, но выдать их
 * кладовщику или исполнителю нечем — работать с очередью выдачи может только
 * тот профиль, которому права достались при установке.
 */
class Profile extends \Profile
{
    public static $rightname = 'profile';

    public static function getTypeName($nb = 0)
    {
        return __('Внутренний магазин', 'storefront');
    }

    /** Права магазина, как они показываются в матрице. */
    public static function rightsMatrix(): array
    {
        return [
            ['itemtype' => Catalog::class,
                'label' => __('Витрины, номенклатура, наборы и лимиты', 'storefront'),
                'field' => 'plugin_storefront_catalog',
                'scope' => 'entity'],
            ['itemtype' => Order::class,
                'label'  => __('Заказы: очередь комплектования и выдача', 'storefront'),
                'field'  => 'plugin_storefront_order',
                'rights' => [READ => __('Просмотр', 'storefront'), UPDATE => __('Комплектование и выдача', 'storefront')],
                'scope'  => 'entity'],
            ['itemtype' => Stock::class,
                'label'  => __('Склад: остатки, приход, списание, перемещение', 'storefront'),
                'field'  => 'plugin_storefront_stock',
                'rights' => [READ => __('Просмотр', 'storefront'), UPDATE => __('Движения склада', 'storefront')],
                'scope'  => 'entity'],
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof \Profile && (int) $item->getID() > 0) {
            return self::createTabEntry(self::getTypeName(), 0, $item::getType());
        }
        return '';
    }

    public static function displayTabContentForItem(
        CommonGLPI $item,
        $tabnum = 1,
        $withtemplate = 0
    ) {
        if ($item instanceof \Profile && (int) $item->getID() > 0) {
            (new self())->showRightsForm((int) $item->getID());
        }

        return true;
    }

    /**
     * Матрица прав магазина для одного профиля.
     *
     * Профиль загружаем штатным классом GLPI, а не этим: имя класса плагина
     * увело бы запрос в таблицу glpi_plugin_storefront_profiles, которой нет
     * и быть не должно — права магазина живут в общей таблице прав профилей.
     * Штатный профиль при загрузке подмешивает права в fields, а матрица
     * читает значения именно оттуда.
     */
    public function showRightsForm(int $profiles_id): void
    {
        if ($profiles_id <= 0) {
            return;
        }
        $canedit = Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, PURGE]);

        $profile = new \Profile();
        if (!$profile->getFromDB($profiles_id)) {
            return;
        }

        if ($canedit) {
            echo "<form method='post' action='"
                . htmlescape(\Plugin::getWebDir('storefront')) . "/front/profile.form.php'>";
            echo Html::hidden('profiles_id', ['value' => $profiles_id]);
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        }

        $profile->displayRightsChoiceMatrix(self::rightsMatrix(), [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => self::getTypeName(),
        ]);

        if ($canedit) {
            echo "<div class='card-footer mx-n2 mb-n2 mt-4 text-center'>";
            echo "<button type='submit' name='update' class='btn btn-primary'>"
                . htmlescape(_sx('button', 'Save')) . '</button>';
            echo '</div>';
            echo '</form>';
        }
    }

    /**
     * Сохранить права магазина у профиля.
     *
     * Матрица прав присылает по одному массиву на право: ключ «_имя_права»,
     * внутри — отмеченные разряды вида «2_0». Берём только свои три права:
     * чужие ключи из формы игнорируем, иначе одна вкладка затирала бы другую.
     */
    public static function save(int $profiles_id, array $input): bool
    {
        if ($profiles_id <= 0) {
            return false;
        }
        $mine = [];
        foreach (self::rightsMatrix() as $row) {
            $field = (string) $row['field'];
            $posted = $input['_' . $field] ?? null;
            $value = 0;
            if (is_array($posted)) {
                foreach ($posted as $bit => $on) {
                    if (!$on) {
                        continue;
                    }
                    $value |= (int) explode('_', (string) $bit)[0];
                }
            }
            // Снятая последняя галочка не приходит в POST вовсе, поэтому
            // отсутствие ключа — это ноль, а не «не менять».
            $mine[$field] = $value;
        }
        ProfileRight::updateProfileRights($profiles_id, $mine);
        return true;
    }
}
