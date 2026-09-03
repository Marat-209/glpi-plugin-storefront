<?php

namespace GlpiPlugin\Storefront;

use ConsumableItem;
use Session;

/**
 * Загрузка номенклатуры списком.
 *
 * Руками витрину на несколько сотен позиций не наполнить, а выгрузка из
 * бухгалтерии или прайс поставщика почти всегда приходит таблицей. Разбор
 * отделён от интерфейса: сначала строится план с пометками, что произойдёт
 * с каждой строкой, и только потом он применяется.
 */
final class Import
{
    /** Заголовки, которые понимаем. Ключ — поле, значения — варианты написания. */
    private const COLUMNS = [
        'name'      => ['наименование', 'название', 'позиция', 'товар', 'name',
            'item', 'title'],
        'ref'       => ['артикул', 'код', 'ref', 'sku', 'code', 'reference'],
        'category'  => ['категория', 'группа', 'category', 'type', 'group'],
        'unit'      => ['единица', 'ед', 'ед.', 'ед.изм', 'единица измерения', 'unit',
            'uom'],
        'price'     => ['цена', 'стоимость', 'price', 'cost'],
        'threshold' => ['порог', 'минимум', 'порог оповещения',
            'threshold', 'min', 'minimum', 'alarm'],
        'target'    => ['цель', 'целевой запас', 'норма',
            'target', 'target stock'],
        'qty'       => ['остаток', 'количество', 'приход', 'qty', 'stock', 'quantity'],
    ];

    public const ACT_CREATE = 'create';
    public const ACT_UPDATE = 'update';
    public const ACT_SKIP   = 'skip';
    public const ACT_ERROR  = 'error';

    /** Образец файла: с ним не приходится угадывать названия столбцов. */
    public static function template(): string
    {
        $rows = [
            [__('наименование', 'storefront'), __('артикул', 'storefront'), __('категория', 'storefront'), __('единица', 'storefront'), __('цена', 'storefront'), __('порог', 'storefront'), __('цель', 'storefront'), __('остаток', 'storefront')],
            [__('Ручка шариковая синяя', 'storefront'), 'ART-0041', __('Канцелярские товары', 'storefront'), __('шт', 'storefront'), '12,00', '50', '300', '100'],
            [__('Бумага А4, 500 листов', 'storefront'), 'ART-0102', __('Бумага', 'storefront'), __('упак', 'storefront'), '430,00', '10', '50', '20'],
        ];
        $out = "\xEF\xBB\xBF";
        foreach ($rows as $r) {
            $out .= implode(';', $r) . "\r\n";
        }
        return $out;
    }

    /**
     * Привести файл к UTF-8.
     *
     * Русский Excel по умолчанию сохраняет CSV в windows-1251, и без этой
     * проверки половина каталога приезжает вопросительными знаками.
     */
    public static function toUtf8(string $raw): string
    {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }
        $converted = @iconv('windows-1251', 'UTF-8//TRANSLIT', $raw);
        return $converted !== false ? $converted : $raw;
    }

    /** Разделитель столбцов: в русских выгрузках почти всегда точка с запятой. */
    public static function guessDelimiter(string $text): string
    {
        $line = strtok($text, "\n") ?: '';
        $counts = [';' => substr_count($line, ';'), ',' => substr_count($line, ','),
            "\t" => substr_count($line, "\t")];
        arsort($counts);
        $best = array_key_first($counts);
        return $counts[$best] > 0 ? (string) $best : ';';
    }

    /** Число из ячейки: «1 234,50» и «1234.50» — одно и то же. */
    public static function num(string $v): float
    {
        $v = str_replace(["\xC2\xA0", ' ', "\u{202F}"], '', trim($v));
        $v = str_replace(',', '.', $v);
        return is_numeric($v) ? (float) $v : 0.0;
    }

    /**
     * Разобрать таблицу и построить план.
     *
     * @return array{header:array<string,int>, rows:array<int,array>, errors:array<int,string>}
     */
    public static function plan(string $raw, int $catalogs_id, string $delimiter = ''): array
    {
        $text = self::toUtf8($raw);
        $delimiter = $delimiter !== '' ? $delimiter : self::guessDelimiter($text);

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $lines = array_values(array_filter($lines, static fn($l) => trim($l) !== ''));
        if (!count($lines)) {
            return ['header' => [], 'rows' => [], 'errors' => [__('Файл пуст.', 'storefront')]];
        }

        // Шапка
        $head = str_getcsv(array_shift($lines), $delimiter);
        $map = [];
        foreach ($head as $i => $title) {
            $key = mb_strtolower(trim((string) $title));
            foreach (self::COLUMNS as $field => $variants) {
                if (in_array($key, $variants, true)) {
                    $map[$field] = $i;
                }
            }
        }
        if (!isset($map['name'])) {
            return [
                'header' => $map,
                'rows'   => [],
                'errors' => [__('В первой строке не найден столбец «наименование». ', 'storefront')
                    . __('Скачайте образец и приведите шапку к нему.', 'storefront')],
            ];
        }

        $seen = [];
        $rows = [];
        foreach ($lines as $n => $line) {
            $cells = str_getcsv($line, $delimiter);
            $get = static fn(string $f): string => isset($map[$f], $cells[$map[$f]])
                ? trim((string) $cells[$map[$f]]) : '';

            $row = [
                'line'      => $n + 2, // плюс шапка, счёт с единицы
                'name'      => $get('name'),
                'ref'       => $get('ref'),
                'category'  => $get('category'),
                'unit'      => $get('unit') !== '' ? $get('unit') : __('шт', 'storefront'),
                'price'     => self::num($get('price')),
                'threshold' => (int) self::num($get('threshold')),
                'target'    => (int) self::num($get('target')),
                'qty'       => (int) self::num($get('qty')),
                'action'    => self::ACT_CREATE,
                'note'      => '',
                'items_id'  => 0,
                'products_id' => 0,
            ];

            if ($row['name'] === '') {
                $row['action'] = self::ACT_ERROR;
                $row['note'] = __('Пустое наименование.', 'storefront');
                $rows[] = $row;
                continue;
            }
            $key = mb_strtolower($row['name']);
            if (isset($seen[$key])) {
                $row['action'] = self::ACT_ERROR;
                $row['note'] = __('Повтор наименования внутри файла (строка ', 'storefront') . $seen[$key] . ').';
                $rows[] = $row;
                continue;
            }
            $seen[$key] = $row['line'];

            // Что уже есть в базе
            $existing = self::findConsumable($row['name'], $row['ref']);
            if ($existing > 0) {
                $row['items_id'] = $existing;
                $row['note'] = __('Номенклатура уже есть в GLPI.', 'storefront');
                $pr = Product::getByItem('ConsumableItem', $existing);
                if ($pr !== null
                    && (int) $pr->fields['plugin_storefront_catalogs_id'] === $catalogs_id) {
                    $row['products_id'] = $pr->getID();
                    $row['action'] = self::ACT_UPDATE;
                    $row['note'] = __('Позиция уже в витрине — обновим цену и единицу.', 'storefront');
                }
            }
            $rows[] = $row;
        }

        return ['header' => $map, 'rows' => $rows, 'errors' => []];
    }

    /** Найти расходник по артикулу, иначе по имени. */
    private static function findConsumable(string $name, string $ref): int
    {
        $ci = new ConsumableItem();
        if ($ref !== '') {
            $found = $ci->find(['ref' => $ref], [], 1);
            if (count($found)) {
                return (int) array_key_first($found);
            }
        }
        $found = $ci->find(['name' => $name], [], 1);
        return count($found) ? (int) array_key_first($found) : 0;
    }

    /**
     * Применить план.
     *
     * @return array{created:int, updated:int, skipped:int, stock:int, errors:array<int,string>}
     */
    public static function apply(
        array $rows,
        int $catalogs_id,
        int $entities_id,
        int $warehouses_id = 0
    ): array {
        $res = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'stock' => 0, 'errors' => []];

        foreach ($rows as $row) {
            if ($row['action'] === self::ACT_ERROR || $row['action'] === self::ACT_SKIP) {
                $res['skipped']++;
                continue;
            }

            $items_id = (int) $row['items_id'];
            if ($items_id <= 0) {
                $ci = new ConsumableItem();
                $items_id = (int) $ci->add([
                    'name'                   => $row['name'],
                    'ref'                    => $row['ref'],
                    'entities_id'            => $entities_id,
                    'is_recursive'           => 1,
                    'consumableitemtypes_id' => self::categoryId($row['category']),
                    'alarm_threshold'        => $row['threshold'],
                    'stock_target'           => $row['target'],
                ]);
                if ($items_id <= 0) {
                    $res['errors'][] = __('Строка ', 'storefront') . $row['line'] . __(': не удалось создать номенклатуру.', 'storefront');
                    continue;
                }
            }

            $product = new Product();
            if ((int) $row['products_id'] > 0 && $product->getFromDB((int) $row['products_id'])) {
                $product->update([
                    'id'                => $product->getID(),
                    'unit'              => $row['unit'],
                    'price'             => $row['price'],
                    'use_infocom_price' => $row['price'] > 0 ? 0 : 1,
                    'is_active'         => 1,
                ]);
                $res['updated']++;
            } else {
                $pid = (int) $product->add([
                    'plugin_storefront_catalogs_id' => $catalogs_id,
                    'entities_id'       => $entities_id,
                    'is_recursive'      => 1,
                    'itemtype'          => 'ConsumableItem',
                    'items_id'          => $items_id,
                    'unit'              => $row['unit'],
                    'price'             => $row['price'],
                    'use_infocom_price' => $row['price'] > 0 ? 0 : 1,
                    'is_active'         => 1,
                ]);
                if ($pid <= 0) {
                    $res['errors'][] = __('Строка ', 'storefront') . $row['line'] . __(': не удалось добавить в витрину.', 'storefront');
                    continue;
                }
                $product->getFromDB($pid);
                $res['created']++;
            }

            // Начальный остаток — обычный приход, чтобы он попал в движения
            // и был виден в истории склада наравне с остальными поступлениями.
            if ($warehouses_id > 0 && (int) $row['qty'] > 0) {
                if (Engine::receive($product->getID(), $warehouses_id, (int) $row['qty'], [
                    'entities_id' => $entities_id,
                    'unit_price'  => (float) $row['price'],
                    'comment'     => __('Загрузка номенклатуры списком', 'storefront'),
                ])) {
                    $res['stock'] += (int) $row['qty'];
                }
            }
        }

        return $res;
    }

    /** Категория расходника по названию: найти или завести. */
    private static function categoryId(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            return 0;
        }
        $type = new \ConsumableItemType();
        $found = $type->find(['name' => $name], [], 1);
        if (count($found)) {
            return (int) array_key_first($found);
        }
        return (int) $type->add(['name' => $name]);
    }

    public static function actionLabel(string $action): string
    {
        return [
            self::ACT_CREATE => __('создать', 'storefront'),
            self::ACT_UPDATE => __('обновить', 'storefront'),
            self::ACT_SKIP   => __('пропустить', 'storefront'),
            self::ACT_ERROR  => __('ошибка', 'storefront'),
        ][$action] ?? $action;
    }

    public static function actionTone(string $action): string
    {
        return [
            self::ACT_CREATE => 'green',
            self::ACT_UPDATE => 'blue',
            self::ACT_SKIP   => 'secondary',
            self::ACT_ERROR  => 'red',
        ][$action] ?? 'secondary';
    }
}
