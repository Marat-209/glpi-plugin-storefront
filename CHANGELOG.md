# Changelog

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
