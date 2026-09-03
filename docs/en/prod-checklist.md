# Production rollout and testing order

A document for whoever installs the plugin on a production GLPI. Version
**1.0.0-rc7** is a release candidate: functionally complete, verified on a
test stand, and intended for pilot use by a limited group of employees.

---

## 1. Requirements

| What | Value |
|---|---|
| GLPI | 11.0 and newer (verified on 11.0.8) |
| PHP | the same version your GLPI build requires |
| Scheduler | GLPI's system cron must be running: the plugin's three tasks run in external mode |
| File permissions | the `plugins/` directory must be writable by the web server user |
| Access | a GLPI administrator with full rights (installing a plugin and editing profiles) |

## 2. Before installing

- [ ] **Back up the database** and the GLPI directory. The plugin does not
      change GLPI's schema, but a rollback is always easier from a backup.
- [ ] Check the GLPI version: **Setup → General → System**.
- [ ] Make sure there is no `plugins/storefront` directory yet (or keep the
      old copy elsewhere).
- [ ] Check that GLPI's cron is running: **Setup → Automatic actions** — the
      built-in tasks have a last-run date.
- [ ] Decide who will be the catalog administrator and who the storekeeper,
      and in which entities their profiles will be assigned.
- [ ] Grant the approvers' profile GLPI's own **Approval of tickets → Approve a
      request** right: without it a manager cannot open the ticket they are
      supposed to answer in.

## 3. Installing

1. Unpack the `storefront` directory into `plugins/` of your GLPI
   installation.
2. Run as the web server user:

```
php bin/console plugin:install storefront
php bin/console plugin:activate storefront
```

The web interface does the same: **Setup → Plugins → Internal store →
Install → Enable**.

### What appears in the system

| What | Details |
|---|---|
| 14 tables | `glpi_plugin_storefront_catalogs`, `…_warehouses`, `…_products`, `…_stocks`, `…_movements`, `…_orders`, `…_orderitems`, `…_limits`, `…_kits`, `…_kititems`, `…_kitgrants`, `…_ratings`, `…_titlelevels`, `…_cartitems` |
| 3 rights | *Catalogs, items, kits and limits*, *Orders: picking queue and issue*, *Warehouse: stock, receipts, write-offs, transfers* — granted on the *Internal store* tab of the profile form |
| 3 tasks | `storefront_lowstock` (daily), `storefront_cartcleanup` (weekly), `storefront_reserves` (daily) |
| Menu entry | **Management → Store** |
| Tab | *Internal store* on the profile form |

GLPI's schema is not modified. Tickets, approval requests, followups,
solutions and consumables are created through GLPI's own API — exactly as a
person would create them in the interface.

## 4. Checks right after installing

| # | What to check | Expected result |
|---|---|---|
| 1 | **Setup → Plugins** | “Internal store”, version 1.0.0-rc7, state “Enabled” |
| 2 | **Management → Store** | the entry is there and opens the (empty) list of catalogs |
| 3 | The sub-entries | Store catalogs, Order queue, Orders, Item import, Warehouse, Analytics, Reports, Job title levels |
| 4 | **Administration → Profiles → any profile** | there is an *Internal store* tab with three rights |
| 5 | **Setup → Automatic actions** | the three `storefront_*` tasks are present and enabled |
| 6 | The `files/_log/php-errors.log` log | no new entries mentioning `storefront` |
| 7 | The `files/_log/sql-errors.log` log | no new entries |
| 8 | Open the plugin's pages one by one | all of them answer without errors; empty lists are normal |

If the menu entry did not appear, reload the page with the browser cache
cleared and check that the plugin is “Enabled” and not merely “Installed”.

## 5. The pilot: how to test

There is no need to roll it out to everybody at once. The order verified on
the test stand:

1. **One catalog.** Set it up following
   [setup-example.md](setup-example.md). Start with the one whose items are
   simple and whose issues are frequent — office supplies suit better than
   equipment.
2. **A limited group.** Give the tile to a single department: 5–10 employees,
   one or two approvers, one storekeeper.
3. **One or two weeks.** That is enough time for every branch to occur: a
   normal issue, a partial issue, a refusal, a cancellation, a stock receipt,
   a limit firing.
4. **A review.** Collect from the storekeeper and the employees whatever was
   unclear: the wording is fixed through the catalog's settings (description,
   announcement, item names) without touching the code.

### What must be checked during the pilot

- [ ] The employee finds the catalog by its tile without asking where it is.
- [ ] An order below the threshold goes to the warehouse without approval; a
      more expensive one reaches the manager.
- [ ] The approver opens the ticket and answers with the buttons (the
      *Approval of tickets* right is granted).
- [ ] An approver's refusal: the ticket gets a solution and the status
      **Solved**, the stock is untouched, and the employee sees the reason.
- [ ] The storekeeper reduces a quantity with a reason — the employee sees
      that reason in the ticket.
- [ ] An issue writes off exactly what was issued; the note is printed and
      signed.
- [ ] A hard limit stops the order and says whose allowance is exhausted.
- [ ] Tickets get SLA targets by the catalog's category.
- [ ] A day after the installation the `storefront_reserves` task has run
      (**Setup → Automatic actions → storefront_reserves**, the last-run date
      is filled in).

### What to look at in the data every few days

| Where | What to look for |
|---|---|
| **Management → Store → Warehouse** | no negative stock; no reservation larger than the stock without a reason |
| **Management → Store → Analytics** | issues land in the report; the time to issue looks realistic |
| `files/_log/php-errors.log` | entries mentioning `storefront` |
| **Setup → Automatic actions** | all three tasks are running |

## 6. Upgrading

1. Replace the `plugins/storefront` directory with the new version.
2. **Always** run the migration:

```
php bin/console plugin:install storefront
php bin/console plugin:activate storefront
```

> Until the migration is run, GLPI considers the plugin to require an update
> and **does not load its classes**: the store's pages return an error and the
> tile disappears. That is GLPI's normal behaviour rather than a fault — just
> run the two commands.

3. Open a catalog's form and check that the new version's fields are there. If
   they are not, GLPI is serving the form from its compiled-template cache:

```
php bin/console cache:clear
```

> From 1.0.0-rc8 the plugin clears that cache itself — during the migration
> and on every enable, so disabling and enabling it in the plugin list works
> too. The manual command is for anyone upgrading from rc7 or earlier: in
> production GLPI does not re-read changed templates, and installing a plugin
> clears only the translations cache.

Data survives an upgrade: the migration adds the missing columns and rights
without deleting anything.

## 7. Rolling back

**Disable while keeping the data:** **Setup → Plugins → Disable**. The catalog
disappears from the menu and from self-service; the orders and the stock stay
in the database. Enabling it brings everything back.

**Uninstall the plugin:** **Setup → Plugins → Uninstall**. What happens:

| Data | What happens to it |
|---|---|
| Orders, order lines, warehouse movements, stock | **kept** in the database as they are — these are accounting records |
| Catalogs, warehouses, items, limits, kits, permissions, ratings, job title levels | the tables are renamed with a date suffix (`…_backup_YYYYMMDD`) |
| Carts | deleted |
| The store's rights | removed from every profile |
| Tickets, solutions, followups | kept: this is GLPI's data, not the plugin's |
| GLPI consumables | kept: the plugin only used them |

A full cleanup (if the data is no longer needed) is done by hand: drop the
`glpi_plugin_storefront_*` tables and their `…_backup_*` copies.

## 8. What to settle before a full launch

**Not implemented** (not defects, but scope that was not built):

- tracking by inventory number — a quantity of a consumable is issued, not a
  specific numbered unit;
- an “not in the catalog” line — ordering something absent from the item list
  as free text is not possible.

**Behaviours worth agreeing on in advance:**

- **A limit counts the whole period**, including the issues made before the
  rule was created. A “one chair a year” rule entered in September will also
  count January's chair.
- **The limit's allowance** is an organisational decision: “one per recipient”
  or “shared across the scope”. For expensive items a shared group allowance
  is usually closer to real life.
- **An order for a group or an entity** spends that group's allowance rather
  than the personal allowance of whoever signed the note. If that is
  undesirable, restrict who may order for others, or set a shared allowance
  for the scope.
- **The reservation is soft**: the promise may exceed the actual stock, and
  the shortage is discovered at the moment of issue. That is a deliberate
  choice, so that one order does not block the warehouse for everybody else.

## 9. Acceptance

The plugin can be considered ready for a full launch once, during the pilot:

- [ ] at least 20 orders have gone through, including a refusal, a
      cancellation and a partial issue;
- [ ] the logs contain no errors mentioning `storefront`;
- [ ] the warehouse stock matches reality on a spot count;
- [ ] the storekeeper works the queue without asking “and how do I…”;
- [ ] the SLA targets on the catalog's tickets are calculated and met;
- [ ] the scheduled tasks run every day.
