# A catalog from scratch: a step-by-step example

A full pass through the setup: the entity, the groups, the ticket category, the
SLA, the rights, a catalog with every setting, the warehouses, six items, kits,
limits, the tile, and an acceptance order all the way from the employee to the
printed note.

The example is an office-supplies catalog for a Facilities department.
Substitute your own names; the order of the steps does not change. Everything
is done with standard GLPI features except steps 6–11, which happen under
**Management → Store**.

Time estimate: 40–60 minutes for the first catalog, 10–15 minutes for each one
after that.

---

## What you will end up with

| Object | Value in this example |
|---|---|
| Entity | Facilities (a child of the root entity) |
| Groups | “Office supplies approvers”, “Office supplies storekeepers” |
| Ticket category | “Office supplies issue” |
| SLA | 4 working hours to take in hand, 3 working days to resolve |
| Profile | “Office supplies storekeeper” |
| Catalog | “Office supplies” — available from the root, tile on the home page |
| Warehouses | “Facilities store” (default), “Site store” |
| Items | 6 items with prices, thresholds and target stock |
| Kits | “New joiner set” (one-off) |
| Limits | 3 rules, including a shared group allowance |

---

## Step 1. The entity

**Administration → Entities → +**

- Name: `Facilities`
- Parent entity: the root entity

The entity owns both the warehouse and the catalog. If the store is run by the
same service that supports everybody, keep the catalog in this entity and turn
on *Available from parent entities* in step 6 — then everyone working higher up
the tree can order, while only the Facilities staff manage the catalog.

## Step 2. The groups

**Administration → Groups → +** — two groups:

| Name | Flags |
|---|---|
| `Office supplies approvers` | *Can be supervisor*, *Can be assigned to tickets*, *Child entities* |
| `Office supplies storekeepers` | *Can be assigned to tickets*, *Child entities* |

The entity of both is Facilities; the recursive flag is required if orders
arrive from child entities. Fill the membership on the *Users* tab: the
managers go into the approvers, the storekeepers into the fulfilment group.

## Step 3. The ticket category

**Setup → Dropdowns → Ticket categories → +**

- Name: `Office supplies issue`
- Visible in: tickets (request)
- Entity: root, *Child entities*: yes
- Technician group: `Office supplies storekeepers`

The category is the anchor the SLA and the routing attach to. Without it the
catalog's tickets are created with no targets.

## Step 4. The SLA and the business rule

**Setup → Service levels → +**

- Name: `Office supplies issue`, calendar: your working schedule (5×8)
- Inside it create two SLAs:
  - `Office supplies: take in hand` — type *Time to own*, 4 working hours
  - `Office supplies: issue` — type *Time to resolve*, 3 working days

**Setup → Rules → Business rules for tickets → +**

- Name: `Office supplies: targets`
- Criterion: `Category` = `Office supplies issue`
- Actions: `Time to own` → `Office supplies: take in hand`;
  `Time to resolve` → `Office supplies: issue`

Check that the rule is active and sits above any rule that could overwrite the
SLA.

## Step 5. The storekeeper's profile and rights

**Administration → Profiles → +**

- Name: `Office supplies storekeeper`, interface: standard

On the **Internal store** tab grant the rights:

| Right | What to grant |
|---|---|
| *Catalogs, items, kits and limits* | read (full rights only for the catalog administrator) |
| *Orders: picking queue and issue* | read, update |
| *Warehouse: stock, receipts, write-offs, transfers* | read, update |

On the other tabs of the profile grant the standard GLPI rights: tickets and
followups — read and update; consumables — read, update, create; ticket
categories, entities, groups, users and job titles — read.

**Give the approvers' profile the *Approval of tickets* right.**
**Administration → Profiles → the profile the managers work under → the
*Assistance* tab → *Approval of tickets* → *Approve a request*.** Without it a
manager receives the approval e-mail but cannot open the ticket: GLPI admits
the requester, the assignee and an approver holding this right. The stock
*Self-Service* profile does not have it.

**Assign the profile to the storekeeper in their entity:**
**Administration → Users → the user → the *Authorizations* tab → add**: the
profile `Office supplies storekeeper`, the entity `Facilities`,
*Recursive* — yes.

> This is exactly where the scope of responsibility is set. The Facilities
> storekeeper will not see another entity's warehouse even if they know its id.

## Step 6. The catalog

**Management → Store → Store catalogs → +**

Fill in the form section by section.

**Name and appearance**

| Field | Value |
|---|---|
| Catalog name | `Office supplies` |
| Description for the employee | `Pens, paper, folders. Issued by the Facilities store; larger orders are approved by your manager.` |
| Catalog entity | `Facilities` |
| Visible in child entities | yes |
| Available from parent entities | **yes** — the employees work from the root |
| Catalog is active | yes |
| Tile on the self-service home page | **yes** |
| Full page width | no — the example has six items and the middle of the page is enough; turn it on when there are many items |
| Tile illustration | any suitable picture |

**Announcement above the catalog**

- Text: `Orders submitted before 15:00 are issued the same day.`
- Style: regular announcement

**Who approves the order**

| Field | Value |
|---|---|
| How the approver is chosen | `The employee picks the approver` |
| Approver at least of job title | `Head of department` (see step 11) |
| Approver group — fallback | `Office supplies approvers` |
| Approver is mandatory | yes |
| Auto-approve orders cheaper than | `500` |
| … and no more items than | `3` |

That way small change up to 500 (in GLPI's currency) and three lines goes to
the warehouse at once, while everything else goes to the manager the employee
picks.

**Who issues**

| Field | Value |
|---|---|
| Fulfilment group | `Office supplies storekeepers` |
| Ticket category | `Office supplies issue` |
| Reserve stock for an approved order | `Soft reservation once work starts` |

**What the employee sees and can do**

| Field | Value |
|---|---|
| Show prices to the employee | yes |
| Show stock level | yes |
| Maximum items per order | `10` |
| Order comment is mandatory | yes |
| Allow ordering for others | yes |
| Move the ticket to “Solved” on refusal | yes |

**Printed issue note**

| Field | Value |
|---|---|
| Organisation in the header | `Facilities` |
| Document title | `Office supplies issue note` |
| Issued by: job title | `Facilities storekeeper` |
| Print prices and total | no (the employee does not need the price on the form) |
| Print the “requested” column | yes |
| Text below the signatures | `Goods received; no complaints as to quantity or quality.` |

Save. After saving, the tabs **Catalog items**, **Warehouses**, **Kits** and
**Issue limits** appear, and so does the tile on the self-service home page.

## Step 7. The warehouses

The **Warehouses** tab of the catalog card → the **Add a warehouse** block:

| Name | Entity | Default |
|---|---|---|
| `Facilities store` | Facilities | yes |
| `Site store` | Facilities | no |

The second warehouse is needed when goods are issued from two places — the
employee then picks the pickup warehouse while ordering.

## Step 8. The items

Two ways: one at a time on the form, or in bulk from a file.

### 8.1. One at a time

The **Catalog items** tab → the **Create a new item** block. If the consumable
already exists in GLPI, use the **Add an existing item** block — the catalog
item will point at it. You can pick a consumable or a cartridge: issuing
specific units with inventory numbers is not supported in this version.

| Name | Reference | Item type | Unit | Price | Threshold | Target stock | Max per order |
|---|---|---|---|---|---|---|---|
| Blue ballpoint pen | ART-0041 | Office supplies | pcs | 12,00 | 50 | 300 | 5 |
| HB pencil | ART-0055 | Office supplies | pcs | 8,00 | 40 | 200 | 5 |
| A4 paper, 500 sheets | ART-0102 | Paper | pack | 430,00 | 10 | 50 | 2 |
| Lever arch file, 70 mm | ART-0210 | Files and archive | pcs | 190,00 | 15 | 60 | 3 |
| Sticky note block 90×90 | ART-0233 | Office supplies | pcs | 95,00 | 20 | 80 | 2 |
| Whiteboard marker | ART-0301 | Office supplies | pcs | 65,00 | 12 | 40 | 4 |

*Description for the employee* is worth filling in wherever the name does not
explain itself: `A4 paper, 80 g/m², for printers and copiers`.

*Alert threshold* and *Target stock* are used by the low-stock task and by the
shortage section of the analytics: once the available stock falls below the
threshold the item lands on the purchase list, and the need is calculated as
“target stock minus available stock”.

*Maximum per order* is the quantity cap: the catalog will not let more into
the cart.

### 8.2. In bulk from a file

**Management → Store → Item import** → download the sample, fill it in and
upload it. The format is CSV with `;` as the separator, UTF-8 or windows-1251:

```
name;reference;category;unit;price;threshold;target;stock
Blue ballpoint pen;ART-0041;Office supplies;pcs;12,00;50;300;100
A4 paper, 500 sheets;ART-0102;Paper;pack;430,00;10;50;20
```

The `stock` column immediately books that quantity into the default warehouse.
Uploading the same file again updates the items instead of creating copies.

## Step 9. Opening stock

**Management → Store → Warehouse** → the **Receive** block:

- The item, the warehouse, the quantity; optionally a document number and a
  unit price.

Book the opening stock for all six items. Check that the warehouse page now
shows the stock and the receipt entries.

The same page offers **Write off**, **Transfer to another warehouse** and
**Stock count** — bringing the stock to the actual figure and recording the
count date.

## Step 10. Kits

The **Kits** tab → the **Create a kit** block:

- Name: `New joiner set`
- Description: `Issued once when joining the company`
- Issued once: **yes**

Then use **Add to the kit** to put the items in with their quantities: pen — 2,
pencil — 1, paper — 1, file — 1, sticky note block — 1.

The employee adds the whole kit with one button. A one-off kit becomes
unavailable once issued; a repeat is only possible if the catalog administrator
grants a permission in the **One-off issue** block on the *Kits* tab.

## Step 11. Job title levels

**Management → Store → Job title levels** → **Mark up the job title
dictionary**.

The plugin walks GLPI's job title dictionary and assigns levels from the names.
Check the result and fix by hand where it matters: a head of department must
come out above a specialist. This level is exactly what the *Approver at least
of job title* setting uses — without the markup the threshold does nothing.

## Step 12. Limits

The **Issue limits** tab → the **Add a limit** block. Three examples that
cover everything the setting can do:

| Name | Whom it applies to | Allowance | What it covers | Period | Max | Hard |
|---|---|---|---|---|---|---|
| `Paper — 2 packs a month` | every employee of the catalog | one per recipient | the item “A4 paper” | month | 2 | yes |
| `Office supplies — 20 units a quarter` | every employee of the catalog | one per recipient | the whole catalog | quarter | 20 | no |
| `Sales — 10 files a year` | a group: Sales | **shared across the scope** | the item “Lever arch file” | year | 10 | yes |

The difference in the allowance:

- **one per recipient** — every employee has their own 2 packs a month, and an
  order for a group spends the group's separate allowance;
- **shared across the scope** — the group has one common pool: 10 files a year
  for everybody together, and the personal orders of the group's employees
  spend it too. That is how the loophole is closed where an employee takes
  more than their personal allowance “for the group”.

A soft limit warns and lets the order through (the approver sees the excess); a
hard one blocks the submission and is checked again at the moment of issue.

## Step 13. The acceptance order

Walk the whole process with real people — that tests the rights, the routing,
the SLA and the printed form all at once.

1. **The employee** (self-service interface) opens the `Office supplies` tile
   on the home page. Check that the items, the prices, the stock levels and
   the announcement are all visible.
2. They put paper and pens in the cart, then try to add more paper than the
   cap allows — the quantity must be limited. They add the kit with the
   **Take the kit** button.
3. They pick the recipient (themselves), the pickup warehouse and the approver,
   write a comment and press **Submit the order**. Without a comment and
   without an approver the order must not go anywhere.
4. **The approver** opens the ticket and answers with the standard approval
   button. Test a refusal on a separate order: the ticket must get the solution
   *Approval refused* and the status *Solved*.
5. **The storekeeper** opens **Management → Store → Order queue**, opens the
   order, presses **Approve the quantities** (reduce one line and state a
   reason — the employee will see it in the ticket), then **Ready for pickup**,
   then **Issue and write off**, leaving the note number empty — it will be
   generated automatically.
6. They print the issue note with the button in the queue. Check the header,
   the contents and the signatures.
7. **The employee** sees the solution listing what was issued and can print the
   note from their own orders. They rate an item — which is only possible for
   somebody who received that item.
8. **The administrator** opens **Analytics** and **Reports**: the issue is in
   the report, the time to issue is calculated, and the limits show what has
   been spent.

## Step 14. Scheduled tasks

**Setup → Automatic actions** — check that the three tasks are enabled and
running: `storefront_lowstock`, `storefront_cartcleanup`,
`storefront_reserves`. They run in external mode, which means GLPI's system
cron is required. Without `storefront_reserves` the reservations gradually get
stuck and block ordering what is in fact on the shelf.

---

## A second catalog

There can be as many catalogs as you need — equipment run by IT alongside
office supplies run by Facilities, for instance. The order of the steps is the
same; the settings differ:

| Setting | Office supplies | Equipment |
|---|---|---|
| Auto-approval | up to 500 and 3 lines | up to 15 000 and 2 lines |
| Approver at least of job title | head of department | head of division |
| Limits | monthly, in units | yearly, “one workstation a year”, hard |
| Cap per order | 2–5 | 1 |
| Warehouses | the main one | the main one and a spare pool |
| Kits | new joiner set | developer workplace, engineer workplace |

Catalogs do not mix: each has its own warehouse, items, limits, queue,
analytics and issue note. The employee sees both tiles and picks the right one.

---

## If something is wrong

| Symptom | The cause and what to do |
|---|---|
| The employee does not see the catalog | the catalog is disabled; the entity does not match and *Available from parent entities* is off; there are no active items |
| There is no tile on the home page | turn on *Tile on the self-service home page* and save the catalog |
| The order will not submit | the comment or the approver is missing while they are mandatory; a hard limit fired — the message names the rule and whose allowance is exhausted |
| Tickets have no targets | the catalog has no ticket category, or there is no business rule for that category |
| The storekeeper does not see the queue | the *Orders: picking queue and issue* right was not granted, or the profile is assigned in the wrong entity |
| “There is stock, but ordering is refused” | available stock = on hand minus reservations; check the reservations on the warehouse page, and a stuck one will be released by the `storefront_reserves` task |
