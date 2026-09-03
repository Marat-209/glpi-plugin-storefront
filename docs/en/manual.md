# Internal store — manual

A GLPI 11 plugin for issuing goods to employees: a catalog of items, a cart,
approval, a warehouse with stock and reservations, issue against a printed
note, limits and analytics.

An order lives as an ordinary GLPI ticket, so the standard lists, search,
notifications, SLA and business rules all keep working. There is no separate
portal to learn: the employee finds the store as a tile on the self-service
home page.

This document describes what each role sees and does, and every object the
plugin adds. Step-by-step setup of a catalog from scratch is in
[setup-example.md](setup-example.md). The rollout and verification order for a
production environment is in [prod-checklist.md](prod-checklist.md).

---

## 1. Roles and what each of them does

| Role | Rights | What they do |
|---|---|---|
| **Employee** | self-service interface, no plugin rights | orders from the catalog, follows the order in the ticket, prints the issue note, rates items |
| **Approver** | the same rights as an employee | answers the approval request with the standard buttons in the ticket |
| **Storekeeper** | *Orders: picking queue and issue* and *Warehouse: stock, receipts, write-offs, transfers* — read and update | runs the order queue, approves quantities, issues, receives, writes off, transfers, counts stock |
| **Catalog administrator** | *Catalogs, items, kits and limits* — all rights | creates catalogs, items, warehouses, kits and limits; reads analytics and reports |

Rights are granted on the profile form: **Administration → Profiles → the
profile → the *Internal store* tab**. A profile is assigned to a user **in a
particular entity** — and that is what bounds responsibility: the storekeeper
of one entity neither sees nor touches another entity's warehouse.

The storekeeper additionally needs standard GLPI rights: tickets and followups
(read and update), consumables (read, update, create), and read access to
ticket categories, entities, groups, users and job titles.

---

## 2. Where everything lives

Administration lives under **Management → Store**:

| Entry | Purpose |
|---|---|
| **Store catalogs** | the list of catalogs; a catalog card with all its settings and tabs |
| **Order queue** | the storekeeper's workplace: orders to pick and issue |
| **Orders** | every order as a list, with GLPI search and filters |
| **Item import** | bulk load of items from CSV, plus a sample file |
| **Warehouse** | stock, receipts, write-offs, transfers, stock counts, movement log |
| **Analytics** | what was issued over a period, time to issue, shortages, limit usage |
| **Reports** | consumption over a period, grouped, with CSV export |
| **Job title levels** | the job title dictionary marked up with levels for the approval threshold |

**Setup → Plugins → Internal store** opens the list of catalogs.

An employee opens the catalog from the tile on the self-service home page. The
tile appears when *Tile on the self-service home page* is enabled for the
catalog.

---

## 3. The plugin's objects

### Catalog
The entry point for the employee and the owner of every process setting. There
can be several catalogs: office supplies for one entity, equipment for another.
Each catalog has its own warehouses, items, limits and ticket category.

The catalog card (**Management → Store → Store catalogs → a catalog**) has the
tabs **Store catalog** (settings), **Catalog items**, **Warehouses**, **Kits**
and **Issue limits**.

#### Catalog settings

**Name and appearance**

| Field | What it does |
|---|---|
| Catalog name | the heading of the ordering page and the caption on the tile |
| Description for the employee | one or two lines under the name: what is ordered here |
| Catalog entity | the owning entity; it decides whose warehouse this is |
| Visible in child entities | the standard GLPI inheritance down the tree |
| Available from parent entities | opens the catalog to people working higher up the tree; sibling entities still do not see it |
| Catalog is active | a disabled catalog cannot be ordered from |
| Tile on the self-service home page | the catalog's square next to *Report a problem* and *Request a service*; created and removed when the catalog is saved |
| Catalog icon, Tile illustration | appearance |

**Announcement above the catalog** — a text and its style (*Regular
announcement (blue)*, *Important warning (yellow)*, *Intake restriction
(red)*). The employee sees it above the item list: issue schedules, rules,
temporary restrictions.

**Who approves the order**

| Field | What it does |
|---|---|
| How the approver is chosen | *The employee picks the approver*, *Automatically up the manager chain*, *Always the catalog approver group*, *No approval* |
| Approver at least of job title | the job title level threshold: only people at or above that level are offered; see *Job title levels* |
| Approver is mandatory | without an approver the order cannot be submitted |
| Approver group — fallback | who receives the request when no approver was determined: the employee did not pick one, or the manager field of their user record is empty |
| Auto-approve orders cheaper than | an order below the threshold goes straight to the warehouse without approval; zero means everything is approved |
| … and no more items than | the second auto-approval condition: both must hold at once |

**Who issues**

| Field | What it does |
|---|---|
| Fulfilment group | assigned to the ticket; the storekeepers watch it |
| Ticket category | the category the catalog's tickets are created in; a GLPI business rule assigns the SLA by it |
| Reserve stock for an approved order | *Soft reservation once work starts* or *No reservation* |

**What the employee sees and can do**

| Field | What it does |
|---|---|
| Show prices to the employee | prices and the order total in the catalog |
| Show stock level | “N available” next to an item |
| Maximum items per order | a cap on the number of lines |
| Order comment is mandatory | without a comment the order cannot be submitted |
| Allow ordering for others | ordering for a colleague, a group or an entity |
| Move the ticket to “Solved” on refusal | when the approver refuses, a solution is added and the ticket becomes *Solved*; GLPI's auto-close finishes it later |

**Printed issue note** — the organisation in the header, the document title,
the job title of the person issuing, whether prices and the “requested” column
are printed, and the text below the signatures.

### Catalog item
Points at a GLPI item — **a consumable or a cartridge** — and adds the
commercial properties on top of it: unit, price, description for the employee,
alert threshold, target stock, maximum per order, whether it is charged for,
and whether it is active.

The price comes either from the catalog item's own field or from GLPI's
financial information. The threshold and the target stock are used by the
*low stock* task and by the shortage section of the analytics.

### Warehouse
A catalog may have several warehouses: the main one, a spare pool, a site
store. Each has its own entity and a “default warehouse” flag. The employee
picks the pickup warehouse while ordering when there is more than one.

### Stock and movements
Stock is kept as an aggregate (on hand, reserved) and backed by a movement
log. The movement types are: receipt, issue, write-off, transfer between
warehouses (as a pair of entries), stock count, reservation and reservation
release. Every entry knows who made it, when, for which order, on what
grounds, and what the stock level was before and after.

The invariant: the stock in a warehouse equals receipts minus issues. A daily
task reconciles the warehouses' reservations against what open orders actually
hold.

### Kits
A ready-made cart for a role: “employee workplace”, “business trip set”. The
employee adds a kit with a single button. A kit can be made one-off — then it
is issued once per person, and a repeat is only possible with the catalog
administrator's permission (the *Repeat issue permission* block on the *Kits*
tab).

### Issue limits
The rule for “how much may be received per period”.

| Parameter | Values |
|---|---|
| Whom it applies to (scope) | every employee of the catalog, a group, a job title, an entity, one particular employee |
| **Allowance** | **one per recipient** — the employee, the group and the entity are counted separately; **shared across the scope** — the group (entity, job title) spends one pool together with its people |
| What it covers | the whole catalog, an item category, one particular item |
| Period | month, quarter, year |
| Maximum | the quantity per period |
| Hard | yes — an order above the allowance cannot be submitted; no — a warning, and the order goes through with a justification |

The shared allowance closes a loophole: without it an employee with a personal
allowance of “one workstation a year” could get a second one by ordering it
for the group.

A hard limit is checked twice: when the order is submitted and again at the
moment of issue — between those two events the person may have received their
allowance through another order. A parent entity's allowance includes the
issues of its child entities.

### Job title levels
GLPI's job title dictionary does not know by itself who outranks whom. The
plugin marks job titles up with numeric levels (the *Mark up automatically*
button parses the names; the levels are then edited by hand). The level is
what the *Approver at least of job title* setting and the automatic choice up
the manager chain rely on.

### Ratings
An employee rates an item and writes a review — but only an item they have
actually received. The average rating and the reviews are shown in the
catalog; for the storekeeper that is a hint about what to buy and what to
replace.

---

## 4. The ordering process

```
draft → awaiting approval → in the warehouse queue → approved for issue
      → ready for pickup → issued
```

The branches: **refused** by the approver, **cancelled** by the requester or
by the warehouse.

1. **The employee** opens the catalog, searches for items, puts them in the
   cart (within the item's cap), picks the recipient and the warehouse, writes
   a comment, picks an approver and submits the order. A GLPI ticket is
   created in the catalog's category.
2. **Approval.** If the order is below the routine threshold and has few
   lines, it goes to the warehouse at once. Otherwise an approval request
   appears in the ticket and the manager answers with the standard buttons.
   A refusal ends the order: a solution *Approval refused* is added to the
   ticket, the ticket becomes *Solved*, and the stock is untouched.
3. **The warehouse.** The order shows up in the queue. The storekeeper
   approves the quantities (reducing one requires a reason — the employee will
   see it in the ticket), marks the order *ready for pickup* if needed, and
   then issues it. An issue is indivisible: if any one item is short of stock,
   nothing is written off at all.
4. **The issue note.** Once issued, the note is printed: the organisation
   header, number and date, who received it and who issued it, the grounds
   with the order and ticket numbers, the warehouse, the item table, the
   totals and the signatures. The number is unique within the catalog: it is
   generated automatically or entered by hand. Before the issue the form does
   not open at all — an issue note documents an issue that has happened.
5. **Completion.** A solution listing what was issued is added to the ticket,
   the ticket becomes *Solved*, and GLPI's auto-close finishes it later.

What the employee sees in the ticket at each step: the submission for
approval, the approver's answer, the warehouse taking the order in hand, any
quantity change with its reason, and the issue with the note number.

---

## 5. Tickets, SLA and notifications

- The ticket is created in the **ticket category** named in the catalog
  settings.
- Response and resolution targets are assigned by GLPI's standard **business
  rules for tickets** by that category. Without a category, tickets are
  created with no targets and no routing — the catalog warns about that when
  saved.
- Notifications are GLPI's standard templates for ticket and approval events.
  The warehouse's intermediate steps are written as followups without e-mail,
  so that one order does not produce four notifications.
- The ticket and the order live together: deleting the ticket does not leave
  the order orphaned.

---

## 6. The warehouse: operations

| Operation | What it needs | What it does |
|---|---|---|
| Receipt | item, warehouse, quantity; a document and a price may be given | increases the stock, writes an entry |
| Write-off | mandatory grounds, optionally a document number | decreases the stock, does not touch other orders' reservations |
| Transfer | the destination warehouse | a pair of entries; the total across warehouses is preserved |
| Stock count | the actual quantity | brings the stock to the actual figure and records the count date |

You cannot write off more than there is. Every operation requires the
*Warehouse: stock, receipts, write-offs, transfers* right — update — and
access to the warehouse's entity.

**Item import** (**Management → Store → Item import**) accepts CSV with `;` as
the separator, in UTF-8 or windows-1251. The columns:

```
name;reference;category;unit;price;threshold;target;stock
Blue ballpoint pen;ART-0041;Office supplies;pcs;12,00;50;300;100
```

The header may also be written in Russian; both spellings are recognised. The
sample file is downloaded from the same page. The import creates GLPI
consumables, catalog items and, when a stock figure is given, a receipt into
the default warehouse. Repeating the import updates what already exists
instead of multiplying copies.

---

## 7. Analytics and reports

**Analytics** (over the chosen period): orders, units and value issued; the
average and the median time from order to issue; consumption by month; what is
taken most often; who receives it; what needs purchasing (available stock
below the threshold); and how the limits are being spent — with the allowance
named: personal allowances are summed per employee, a shared allowance of a
scope is shown once.

**Reports**: consumption over a period grouped by item, recipient or entity,
with CSV export.

Everything is counted from the warehouse movements rather than from the
current stock: the stock says what is there now, while decisions are made
from the consumption over a period.

---

## 8. Scheduled tasks

Three tasks are enabled on installation (**Setup → Automatic actions**):

| Task | Period | What it does |
|---|---|---|
| `storefront_lowstock` | daily | finds items below the threshold and calculates the purchase need |
| `storefront_cartcleanup` | weekly | removes carts abandoned more than 30 days ago |
| `storefront_reserves` | daily | releases reservations that no order holds any more |

The tasks run in external mode, so a working GLPI scheduler (the system cron)
is required. Without it reservations gradually get stuck and block ordering
things that are in fact on the shelf.

---

## 9. Entities and visibility

A catalog belongs to one entity and, by GLPI's rules, is visible inside it and
below. The **Available from parent entities** setting opens the catalog to
people working higher up the tree — sibling entities still do not see it. That
is the working arrangement when self-service users never switch entity and
work from the root.

The catalog's entity can be changed on its form: the warehouses, items, kits,
limits, stock, warehouse movements, orders and the home page tile all move
with it.

---

## 10. What the plugin creates, and what it does not do

It creates: 14 tables of its own (`glpi_plugin_storefront_*`), three rights,
three scheduled tasks, a rights tab on the profile form, and one self-service
tile per catalog. Through GLPI's own API it creates tickets, approval
requests, followups, solutions and consumables.

It does not do:

- **tracking by inventory number** — a quantity of a consumable is issued, not
  a specific numbered unit; that is why only consumables and cartridges can be
  put in a catalog, and assets (computers, monitors, phones) cannot be picked;
- **an “not in the catalog” line** — ordering something absent from the item
  list as free text is not possible.

One behaviour worth knowing about: a limit counts the whole period, including
the issues made before the rule was created. Enter “one chair a year” in
September, and an employee who got a chair in January is already over their
allowance.

---

## 11. Frequently asked questions

**The employee does not see the catalog.** Check that: the catalog is active;
the catalog's entity matches the employee's entity, or *Available from parent
entities* is on; the catalog has active items.

**There is no tile on the home page.** Turn on *Tile on the self-service home
page* and save the catalog — the tile is created on save and moves together
with the catalog.

**An order hangs with no approver.** The catalog is in *The employee picks the
approver* mode with neither *Approver is mandatory* nor an approver group.
Make the approver mandatory, or set a group.

**Tickets have no targets.** The catalog has no ticket category, or there is
no business rule assigning an SLA for that category.

**The storekeeper cannot issue.** The rights for orders or for the warehouse
are missing, or the profile is assigned in an entity other than the one the
order belongs to.

**There is stock, but ordering is refused.** Available stock is “on hand”
minus reservations. Look at the reservations on the warehouse page; if no
order is holding them, the `storefront_reserves` task will release them.

---

## 12. Licence

GPLv3 or later.
