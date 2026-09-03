# Changelog

## 1.0.0-rc8 — 2026-09-03

### Fixed

- **An upgrade no longer keeps the old catalog form.** GLPI compiles its Twig
  templates and, in the production environment, does not re-read a changed
  template file; installing a plugin clears only the translations cache. So
  after replacing the plugin's files and running the migration the catalog form
  was still served from the compiled cache — without the fields the new version
  adds, the *Full page width* switch among them, and the only cure was
  `php bin/console cache:clear` run by hand. The plugin now drops the compiled
  templates itself, as part of its own migration — and again whenever it is
  enabled, which covers the case where the files were replaced by the same
  version and no migration runs at all: disabling and enabling the plugin is
  then enough.

### Packaging

- The release archive carries `docs/img`, so the screenshots in the manuals are
  there when the documentation is read from the unpacked archive rather than
  from GitHub.

## 1.0.0-rc7 — 2026-09-03

### Added

- **Catalog setting “Full page width”.** GLPI's self-service keeps page content
  within 1320 px, so on a wide monitor the catalog occupied the middle third of
  the screen. The catalog administrator can now lift that limit for their own
  catalog: the rule is printed only on that catalog's page and only from
  1400 px, so a tablet and a phone keep the standard layout. Widening alone
  would have made things worse, so the grid changes with it — long text stays
  within a readable line (104 characters), the cart column narrows instead of
  stretching, and the item grid goes from three cards per row to four at
  1600 px, five at 1920 px and six at 2560 px. Measured at 375, 768, 1366,
  1600, 1920 and 2560 px: no horizontal scrolling at any of them.

## 1.0.0-rc6 — 2026-09-03

Fixes found by walking the whole route through a browser.

### Fixed

- **The catalog form now saves the entity and “Visible in child entities”.**
  GLPI prints its own hidden `entities_id` and `is_recursive` fields in the
  form footer — after the plugin's own fields — so the administrator's choice
  was silently dropped and a catalog stayed in the active entity. The plugin's
  fields now have their own names and are applied when the input is prepared.
- **A new catalog is born working.** GLPI fills a new record's fields with
  empty strings rather than the schema defaults, so a catalog created without
  touching the switches came out disabled, without a tile, without inheritance
  and with the job title threshold at “intern”. Defaults now match the
  documented ones.
- **The issue note and the “who receives” report name the recipient.** Both
  used the card label, so an order for oneself printed “For myself” on a
  document that a person signs, and every personal order collapsed into one
  report row.

### Documentation

- The approver's profile needs GLPI's own **Approval of tickets → Approve a
  request** right: without it GLPI does not let them into the ticket they are
  supposed to answer in. Added to the manual, the setup example and the
  production checklist.
- Described how GLPI scopes self-service tiles: it shows the tile set of the
  nearest entity up the tree that has any tiles, so the tile of a catalog
  published to parent entities is visible to everyone while the catalog itself
  stays closed.

## 1.0.0-rc5 — 2026-09-03

The first public release candidate: functionally complete, verified on test
stands, intended for pilot use on a production GLPI before the 1.0.0 tag.

### Features

- **Catalogs.** Several catalogs per installation, each with its own entity,
  warehouses, items, kits, limits, ticket category and printed issue note.
  A self-service tile per catalog, an announcement above the item list, and
  per-catalog control over what the employee sees and may do.
- **Ordering.** Search, categories, stock levels, a cart with per-item caps, an
  order for yourself, a colleague, a group or an entity, a choice of pickup
  warehouse, repeating a previous order, and kits (optionally once per person,
  with an administrator's permission for a repeat).
- **Approval.** The employee picks the approver, or it is determined up the
  manager chain with a job title threshold, or the request goes to the
  catalog's approver group. Auto-approval below an amount and a line count.
  A refusal adds a solution and moves the ticket to *Solved*, leaving the
  stock untouched.
- **Warehouse.** An order queue, quantity approval with a mandatory reason for
  a reduction, a ready-for-pickup mark, an indivisible issue, receipts,
  write-offs with grounds, transfers between warehouses, stock counts, and a
  movement log that backs every stock figure.
- **Issue note.** A printed note with an organisation header, a number unique
  within the catalog, the item table, totals and signatures — available only
  once the issue has happened.
- **Limits.** Per person, group, job title, entity or the whole catalog; per
  month, quarter or year; soft or hard; with the allowance either one per
  recipient or shared across the scope. A hard limit is checked both on
  submission and at the moment of issue.
- **Analytics and reports.** Orders, units and value issued, average and
  median time to issue, consumption by month, most requested items,
  recipients, purchase needs, and limit usage. Reports group consumption by
  item, recipient or entity, with CSV export.
- **Item import.** Bulk load from CSV (UTF-8 or windows-1251), creating GLPI
  consumables, catalog items and opening stock; a repeated import updates
  instead of duplicating.
- **Rights.** Three rights on the profile form (catalogs, orders, warehouse),
  scoped by the entity the profile is assigned in.
- **Scheduled tasks.** `storefront_lowstock`, `storefront_cartcleanup`,
  `storefront_reserves`.
- **Languages.** Russian and English interface (`locales/en_GB.mo`,
  `en_US.mo`); the job title markup and the import headers understand both
  languages.

### Known scope not built

- Tracking by inventory number: a quantity of a consumable is issued, not a
  specific numbered unit — only consumables and cartridges can be put in a
  catalog.
- An “not in the catalog” free-text line.
- A limit counts the whole period, including issues made before the rule was
  created.

---

# История изменений

## 1.0.0-rc7 — 3 сентября 2026

### Добавлено

- **Настройка витрины «Во всю ширину страницы».** Самообслуживание GLPI держит
  содержимое страницы в 1320 точках, поэтому на широком мониторе витрина
  занимала середину экрана. Теперь администратор витрины может снять это
  ограничение для своей витрины: правило печатается только на её странице и
  только с 1400 точек, поэтому на планшете и телефоне вид штатный. Одной
  ширины было бы мало, поэтому вместе с ней меняется сетка: длинный текст
  остаётся в читаемой строке (104 знака), корзина сужается вместо растяжения,
  а карточки идут по четыре в ряду на 1600, по пять на 1920 и по шесть на
  2560 точках. Замерено на 375, 768, 1366, 1600, 1920 и 2560: горизонтальной
  прокрутки нет ни на одном разрешении.

## 1.0.0-rc6 — 3 сентября 2026

Правки, найденные проходом всего маршрута в браузере.

### Исправлено

- **Форма витрины сохраняет подразделение и «Видна в дочерних
  подразделениях».** GLPI печатает свои скрытые поля `entities_id` и
  `is_recursive` в подвале формы — после полей плагина, — поэтому выбор
  администратора молча терялся, а витрина оставалась в активной организации.
  Поля плагина получили свои имена и применяются при разборе входа.
- **Новая витрина рождается работающей.** GLPI заполняет поля новой записи
  пустыми строками, а не значениями по умолчанию из схемы, поэтому витрина,
  созданная без правки переключателей, выходила выключенной, без плитки, без
  наследования вниз и с порогом должности «стажёр». Значения по умолчанию
  приведены к тем, что описаны в руководстве.
- **В накладной и в отчёте «кто получает» стоит имя получателя.** Обе страницы
  брали подпись карточки, поэтому в подписываемом документе печаталось «Для
  себя», а в отчёте все личные заказы сливались в одну строку.

### Документация

- Профилю согласующих нужно штатное право GLPI **«Согласование заявок» →
  «Согласовать запрос»**: без него GLPI не пускает его в заявку, в которой он
  должен ответить. Добавлено в руководство, пример настройки и чек-лист
  выкатки.
- Описано, как GLPI выбирает плитки самообслуживания: показывается набор
  ближайшей организации вверх по дереву, у которой плитки есть, — поэтому
  плитка витрины, открытой для родительских организаций, видна всем, а сама
  витрина остаётся закрытой.

## 1.0.0-rc5 — 3 сентября 2026

Первый публичный кандидат: функционально полный, проверенный на стендах,
предназначенный для опытной эксплуатации на рабочем GLPI до выпуска 1.0.0.

### Возможности

- **Витрины.** Несколько витрин на установку, у каждой своё подразделение,
  склады, номенклатура, наборы, лимиты, категория заявок и печатная накладная.
  Плитка в самообслуживании на витрину, объявление над каталогом, отдельная
  настройка того, что видит и может сотрудник.
- **Заказ.** Поиск, категории, остатки, корзина с потолком по позиции, заказ
  для себя, коллеги, отдела или подразделения, выбор склада получения, повтор
  прошлого заказа, наборы (при необходимости — один раз на человека, с
  разрешением администратора на повтор).
- **Согласование.** Согласующего выбирает сотрудник, либо он определяется по
  цепочке руководителей с порогом должности, либо запрос уходит группе
  согласующих витрины. Автосогласование по сумме и числу строк. Отказ
  прикладывает решение и переводит заявку в «Решена», склад не тронут.
- **Склад.** Очередь заказов, утверждение количеств с обязательной причиной при
  уменьшении, отметка готовности, неделимая выдача, приход, списание с
  основанием, перемещение между складами, инвентаризация и журнал движений, на
  который опирается каждый остаток.
- **Накладная.** Печатный бланк с шапкой организации, номером, уникальным в
  пределах витрины, таблицей позиций, итогами и подписями — доступен только
  после состоявшейся выдачи.
- **Лимиты.** На человека, отдел, должность, подразделение или всю витрину; за
  месяц, квартал или год; мягкие и жёсткие; норма — у каждого своя или одна на
  всю область. Жёсткий лимит проверяется и при отправке, и в момент выдачи.
- **Аналитика и отчёты.** Выдано заказов, единиц и на какую сумму, средний и
  медианный срок выдачи, расход по месяцам, что берут чаще всего, получатели,
  потребность к закупке, расход лимитов. Отчёты группируют расход по позиции,
  получателю или подразделению, с выгрузкой в CSV.
- **Загрузка номенклатуры.** Списком из CSV (UTF-8 или windows-1251) с
  созданием расходных материалов GLPI, позиций витрины и начальных остатков;
  повторная загрузка обновляет, а не плодит копии.
- **Права.** Три права на форме профиля (витрины, заказы, склад), область
  ответственности задаётся организацией, в которой назначен профиль.
- **Задания планировщика.** `storefront_lowstock`, `storefront_cartcleanup`,
  `storefront_reserves`.
- **Языки.** Русский и английский интерфейс (`locales/en_GB.mo`, `en_US.mo`);
  разметка должностей и шапка файла загрузки понимают оба языка.

### Что не реализовано

- Учёт по инвентарным номерам: выдаётся расходное количество, а не конкретный
  экземпляр — в витрину заводятся только расходные материалы и картриджи.
- Позиция «нет в каталоге» свободным текстом.
- Лимит считает период целиком, включая выдачи до создания правила.
