# 04 — Flow Audit

Each finding: **Current Flow → Problem → Real-World Scenario → Recommended Flow → Reason →
Priority.**

Priorities: **P0** critical before launch · **P1** important before commercial rollout ·
**P2** improvement · **P3** future.

---

## F-01 · Sales returns — the flow does not exist

**Current flow.** There is no return document, no return screen, no credit note. The only
correction mechanism is `Support\OrderCorrection::setQuantity()`, which **mutates the original
completed invoice**: it reduces the line quantity, returns stock, recomputes totals and tax, updates
the linked `transactions` row, adjusts loyalty points, and writes an `order_edits` audit row with a
mandatory reason. Removing the last remaining line is refused.

**Problem.**
1. A completed tax invoice is a fiscal document. Editing it after issue means the paper in the
   customer's hand and the record in the system say different things, with no third document
   reconciling them.
2. A VAT period that has already been declared is silently restated.
3. A **full** return of a single-line invoice is impossible — the guard that prevents emptying an
   invoice makes the most common return case unreachable.
4. No money movement is recorded. Cash physically leaves the drawer and the shift's expected balance
   does not know it, so the close shows an unexplained shortage.
5. `sales_returns` (account 4900) is seeded in every chart of accounts and receives nothing.
6. `shifts.returns` is populated from cash-*in* drawer movements — the column name is a lie.
7. Returns are invisible in every report. A merchant cannot answer "what is my return rate?" or
   "which product comes back most?"

**Real-world scenario.** A customer buys a shirt for 12 OMR on Sunday, VAT-registered shop. On
Tuesday they return it. The cashier opens the invoice and cannot delete the only line. She improvises:
sells a new invoice for −12? Not possible (quantities are `min:1`). Records a drawer cash-out with the
reason "return"? Then stock is never returned to the shelf, revenue stays at 12, VAT stays declared,
and the shirt is physically on the rack but absent from the system. Whatever she does, the books,
the stock, and the drawer disagree — and the discrepancy is discovered at month-end stocktake with
no trace of its cause.

**Recommended flow.**
```
POS → Receipts → find invoice → "Return"
  → select lines and quantities to return (≤ sold, ≤ not-already-returned)
  → reason (defective / wrong item / changed mind / …)
  → refund method: cash from drawer | card reversal | store credit
  → creates a RETURN document (its own number series, e.g. RET-000001)
      • links to the original order id (return_for_order_id)
      • order_items-style lines with the ORIGINAL cost snapshot
      • stock += to the branch, inventory_movements type 'مرتجع'
      • transactions row type 'مصروف'/negative income, method = refund method
      • shift_movements OUT if refunded in cash (so the drawer balances)
      • loyalty points reversed proportionally
      • journal: Dr Sales Returns, Dr VAT Payable / Cr Cash|Bank
                 Dr Inventory / Cr COGS
      • prints a CREDIT NOTE, not a modified invoice
  → the original invoice is never altered
```
Keep `OrderCorrection` for what it is genuinely good at — **same-day cashier keystroke errors**
(wrong quantity keyed, wrong payment method) — and gate it behind a manager permission and a time
window (e.g. same shift, before close). Everything after that is a return.

**Reason.** Returns are not an edge case in retail; they are 3–10% of transactions. A POS that
cannot process one is not a POS. Separating "correct a mistake at the moment it happens" from
"reverse a completed sale later" is the standard retail model precisely because they have different
legal, tax, cash, and audit consequences.

**Priority: P0.**

---

## F-02 · Invoice void / cancel — defined, filterable, unreachable

**Current flow.** `Order::CANCELLED = 'ملغي'` is defined. `Order::scopeSold()` excludes it. The
orders list has a status filter offering it, and shows a "cancelled" count. `AlertMetrics` and
`Demo` respect it. **No controller anywhere sets it.** Only the demo seeder creates cancelled orders.

**Problem.** The merchant sees a filter for a state their system can never enter. A mis-keyed
invoice (wrong customer, duplicate ring-up, test sale) is permanent.

**Real-world scenario.** A cashier accidentally rings the same basket twice. The second invoice must
be voided: stock returned, revenue reversed, VAT reversed, invoice number retained (gaps in the
series are worse than voided entries). Today the only option is to edit the duplicate down line by
line until one line remains — and then it can't be removed, so a phantom 1-unit sale is permanent.

**Recommended flow.** A `Void` action, permission-gated, reason mandatory, allowed only while the
originating shift is open (after that it is a return). Sets `status = 'ملغي'`, reverses stock,
soft-deletes/reverses the `transactions` row, reverses loyalty, records an `order_edits` row of
kind `إلغاء`, and posts the reversing journal entry once sales posting exists (F-04). The invoice
number is retained and the document is reprintable stamped "VOID".

**Reason.** Number-series integrity plus operational reality. Every POS on the market has this.

**Priority: P0.**

---

## F-03 · Branch-to-branch stock transfer — does not exist

**Current flow.** None. Verified by exhaustive search of the codebase: `inventory_movements.type`
takes `إضافة كمية`, `خصم كمية`, `مرتجع`, `تلف`, `تعديل يدوي`, `بيع`, `تسوية جرد`, `تعديل فاتورة`.
There is no transfer type, no transfer document, no transfer screen.

**Problem.** Abaad sells multi-branch as a plan feature (`plans.max_branches`) and enforces it, but
the fundamental multi-branch operation is missing. The workaround — a manual deduction in branch A
and a manual addition in branch B — is two unlinked movements with no document, no in-transit state,
no receiving confirmation, and no protection against one half being forgotten.

**Real-world scenario.** Muscat branch has 20 of an item, Salalah has 0 and a customer waiting. The
manager ships 5. Today: open Inventory, select the product, choose Muscat, type "خصم كمية 5", note
"to Salalah". Then switch branch, select the product, "إضافة كمية 5". If the second step is
forgotten or done for the wrong quantity, 5 units vanish from the company with no trace linking the
two entries. At year-end stocktake, Salalah shows +5 unexplained and Muscat −5, and nobody can
reconstruct why.

**Recommended flow.**
```
Inventory → Transfers → new
  from branch, to branch, lines (product, qty ≤ available at source)
  → status: مرسل (sent)  — source decrements immediately, destination does NOT increment
  → in-transit quantity is visible and belongs to neither branch's sellable stock
  → destination confirms receipt (with a discrepancy field: received qty may differ)
  → status: مستلم — destination increments by the received quantity
  → any discrepancy becomes a stock adjustment at the destination with reason 'فرق نقل'
  → two linked inventory_movements referencing one transfer document
  → no ledger entry (value stays inside the Inventory account) unless branch-level
    inventory sub-accounts are introduced
```

**Reason.** Without this, the multi-branch feature that the plans are priced on cannot actually be
operated, and inter-branch movements become the largest single source of unexplained stock variance.

**Priority: P0** if multi-branch plans are sold at launch; **P1** if launch targets single-branch
shops only. *(See owner decision OD-03.)*

---

## F-04 · Sales never reach the general ledger

**Current flow.** `Ledger::post()` is invoked from 8 places. None is a sale, a cash receipt, or an
operating expense. The double-entry books receive purchases (Dr Inventory / Cr Payable), supplier
payments, payroll, fixed assets, and stock adjustments — nothing else. The `DemoStore` generator
*does* post monthly sales and COGS journals, so the demo store looks complete.

**Problem.** For any real merchant:
- **Revenue account 4100 is permanently zero.**
- **COGS account 5100 is permanently zero** — inventory is debited by every purchase and never
  relieved, so the Inventory asset grows monotonically forever and diverges from the physical stock
  value on the inventory screen.
- **VAT Payable (2300) is never credited**, even though VAT is collected on every invoice.
- **Cash (1100) and Bank (1200) never receive sales receipts.**
- The trial balance will *balance* (the `Ledger` enforces that rigorously) and will be *meaningless*.
- A merchant who buys Abaad partly for its accounting screens will discover this only when their
  accountant asks for a P&L.

**Real-world scenario.** A shop trades for three months: 40,000 OMR of sales, 25,000 of purchases,
6,000 of salaries. The owner opens الحسابات → ميزان المراجعة. It shows Inventory 25,000, Payable
whatever is unpaid, Salaries 6,000, Cash −6,000. No revenue. The owner's accountant concludes the
system is broken and asks for CSV exports instead — and the accounting module's entire value
proposition evaporates.

**Recommended flow.** Post from the same transaction that writes the sale, through `Ledger::post()`:

```
On checkout (per invoice):
   Dr Cash | Bank | Card-clearing      total
      Cr Sales Revenue                 subtotal − discount
      Cr VAT Payable                   tax
   Dr COGS                             Σ(line cost snapshot × qty)
      Cr Inventory                     same
On expense payment:
   Dr <expense account>                amount
      Cr Cash | Bank
On return (F-01): the reversing pair.
```
Map `orders.payment_method` → account via a small, editable mapping (نقدي→cash, بطاقة→bank or a
card-clearing account, تحويل بنكي→bank). Post asynchronously per invoice **or** as a nightly
aggregate per branch per day — an aggregate is far cheaper at POS scale and is what most SME systems
do. Failure to post must be visible (a "unposted sales" counter), never silent.

Then add a **Profit & Loss** and **Balance Sheet** screen on top of `Ledger::trialBalance()` — the
data structure already supports both.

**Reason.** Either the accounting module produces real books or it should not be shipped. A ledger
that silently omits 100% of revenue is worse than no ledger, because it is *believable*.

**Priority: P0** (either wire it, or hide the الحسابات section behind a flag until it is wired —
see owner decision OD-01).

---

## F-05 · Two adjustment systems, two rule sets, two accounting treatments

**Current flow.**

| | Inventory → Movement | Inventory → Adjustments |
|---|---|---|
| Route | `admin.inventory.store` | `admin.inventory.adjustments.store` |
| Branch | explicit, validated, **required** | `Demo::currentBranchId()` — silently null in "All branches" |
| Negative guard | against the **branch** balance ✅ | against the **company** total ⚠️ |
| Reason | free text, 50 chars | fixed list (`StockAdjustment::REASONS`) |
| Records | `inventory_movements` | `stock_adjustments` **and** `inventory_movements` |
| Ledger | none | Dr/Cr `other_expenses` ↔ `inventory` |
| Cost snapshot | none | `cost_at_time` ✅ |
| Transaction wrapper | none | yes ✅ |

**Problem.** The same business event (5 units broken) produces completely different data depending
on which of two adjacent sidebar entries the employee clicked. One version is financially invisible;
the other is booked to a generic "other expenses" account. Neither is discoverable as "the right
one". Reports that read `inventory_movements` double-count adjustments made through the Adjustments
screen (which writes to both tables).

Additionally, in "All branches" view mode, `Demo::currentBranchId()` returns `null`, so an
adjustment made from the Adjustments screen **changes `products.quantity` but no branch row** —
permanently breaking the invariant "sum of branches = company total".

**Real-world scenario.** The inventory clerk uses Movements (it's higher in the menu). The
accountant later asks why shrinkage doesn't appear in the accounts. Nobody can tell them that the
answer depends on which screen was used.

**Recommended flow.** One screen, one document, one rule set:
- Keep `stock_adjustments` as the document (numbered, reasoned, cost-snapshotted, ledger-posted).
- Make branch **explicit and required**, validated against the tenant, exactly as the Movements
  screen already does.
- Guard against the **branch** balance.
- Split the ledger target by reason: damage/loss → a dedicated `inventory_shrinkage` expense
  account, not `other_expenses`; count correction → `inventory_variance`.
- Retire the free-text Movements screen; `inventory_movements` becomes a pure read-only audit ledger
  written by every path, never by a user directly.

**Reason.** "One door for each kind of write" is a principle this codebase already applies well
(`Ledger::post`, `OrderCorrection`, `Stock::availabilityResolver`, `MarketingSettings`). Inventory
adjustment is the place it was not applied.

**Priority: P1.**

---

## F-06 · Stocktake — shrinkage lands somewhere different again

**Current flow.** Counted quantity per branch is compared to the branch book. Deltas are applied to
both `branch_stocks` and `products.quantity`, a movement is recorded, and — if there is a net
shortage — a single **`Expense` row** is created (`type = 'فاقد جرد'`, `method = 'قيد داخلي'`) with
the branch name embedded in the description string because `expenses` has no `branch_id`. Overage is
deliberately not recorded as income, but the **quantity increase is still applied** with no
counterpart.

**Problem.**
1. A third accounting treatment for the same economic event (shrinkage) — after Movements (none) and
   Adjustments (journal entry).
2. Overage inflates inventory value with no offsetting entry — the merchant's assets grow from a
   counting correction.
3. The whole apply loop is **not wrapped in a transaction**: a failure at product 300 of 500 leaves
   half the branch counted and half not, with no way to tell which.
4. `BranchStock::bookOf()` calls `BranchStock::books()`, which loads **every product and every
   branch-stock row** for the tenant. It is called **once per counted product inside the loop** —
   O(n²). For a 3,000-product shop that is 3,000 full table loads in one request.
5. Per-product costs used for the shrinkage value are read live (`$product->cost`), not snapshotted.

**Real-world scenario.** A supermarket with 4,000 SKUs runs its quarterly count. The apply request
times out at product ~600. The clerk retries; the first 600 are now already adjusted, so the second
pass compares counted-vs-new-book and finds no variance for them — the shortage for the first 600 is
recorded twice into the `Expense` row on the first run and never reconciled.

**Recommended flow.** Wrap in a transaction; pre-load the branch book once (`books()` already returns
the full map — call it once outside the loop); batch-process with a job for counts above a threshold;
create a **Stocktake document** (header + counted lines + variance lines) instead of a loose
`Expense`; post variances through the *same* door as F-05; snapshot cost per line; treat overage
symmetrically (Dr Inventory / Cr Inventory Variance).

**Reason.** A stocktake is the one moment a merchant *trusts* the system's number over the shelf.
It must be atomic, resumable, and auditable.

**Priority: P1** (the O(n²) and the missing transaction are **P0** for any store above ~500 SKUs).

---

## F-07 · Purchase receiving cannot record reality

**Current flow.** `receive()` iterates every line, adds `remaining` to stock, updates the weighted
average cost, writes a movement, sets `received_quantity = quantity`, and flips the PO to `مستلم`.

**Problem.**
- **Partial delivery is not representable.** The `received_quantity` column and the `مستلم جزئيًا`
  status both exist and are both unreachable. If 80 of 100 arrive, the merchant must either record
  100 (inventory now lies by 20) or nothing (inventory lies by 80).
- **No `DB::transaction`, no `lockForUpdate`.** A failure mid-loop leaves some products received and
  the PO still open; a concurrent sale of the same product races the cost recalculation.
- **No financial counterpart.** Goods physically arrive and the merchant owes money, and the system
  records neither a payable nor a transaction until someone separately keys a supplier invoice with
  a hand-typed total.
- **Deleting a received PO does not reverse the stock it created.**
- PO number collision (`random_int(10000,99999)`, no unique index) — the exact bug that was
  correctly fixed for orders and transactions and left in place here.

**Real-world scenario.** A supplier delivers 80 of 100 cartons and promises the rest Thursday. The
storekeeper receives the PO. Stock says 100. The POS lets a customer buy the 95th carton. It isn't
there. The merchant now distrusts the stock figure, and once a merchant distrusts stock figures they
stop using the inventory module entirely.

**Recommended flow.** Per-line received quantity, entered at receiving time; status derived
(`مُرسل` → `مستلم جزئيًا` → `مستلم`); receipt wrapped in a transaction with row locks; a **Goods
Received Note** document per receiving event (a PO can have several); optional "create supplier
invoice from this GRN" pre-filled with the received lines and costs; unique index on
`(business_id, number)` with the sequential generator already used for orders.

**Reason.** Receiving is where inventory truth enters the system. If it cannot represent what
actually arrived, every downstream number inherits the error.

**Priority: P0** (number collision + missing transaction + no partial receipt).

---

## F-08 · Supplier balances are invisible

**Current flow.** `Demo::suppliers()` returns name, phone, email, contact, notes, PO count. The
Suppliers screen shows exactly that. Payables live on a different screen (Purchases → Invoices),
aggregated across all suppliers.

**Problem.** "How much do I owe Al-Nahda Trading?" — one of the three questions a shop owner asks
daily — has no answer in the Suppliers module. There is no supplier statement, no ageing, no
per-supplier outstanding total.

**Recommended flow.** Add outstanding balance and last-payment date to the supplier row; a supplier
detail page with a statement (invoices, payments, running balance) and an ageing bucket
(current / 30 / 60 / 90+); a payables ageing report. All the data already exists in
`supplier_invoices`.

**Reason.** Low effort, high daily value, and it makes the existing supplier-invoice work visible.

**Priority: P1.**

---

## F-09 · The shift is closed and then never looked at

**Current flow.** The POS shift module is excellent: race-safe opening, mandatory reasons on drawer
movements, over-withdrawal guard, blind close, frozen totals, hourly auto-close of abandoned shifts
with a deliberately NULL variance. And then the data goes nowhere the owner can see.

**Problem.**
- No back-office shift list, no variance history, no Z-report, no per-cashier variance trend.
- `Permissions::sectionFromRoute()` contains explicit handling for `admin.shifts.*` routes that are
  not registered — a leftover from a removed screen.
- `require_open_shift` has no UI knob and was force-set to `'0'` by migration, so the "no selling
  without an open shift" rule is dead code.
- `shift_max_hours` has no UI knob.
- Drawer cash-out does not create an expense/transaction, so money leaves the business unrecorded.

**Real-world scenario.** A cashier is 2 OMR short every evening for a month. Each individual close
looks trivial. Nobody ever sees the pattern, because there is no screen that lists closes.

**Recommended flow.** `/admin/shifts` under Finance: list (branch, cashier, open/close time,
expected, counted, variance, closed-by, kind), filters, variance-over-time chart per cashier,
printable Z-report per shift. Restore the `require_open_shift` and `shift_max_hours` knobs to the
settings whitelist. Make a cash-out optionally categorised as an expense.

**Reason.** The reason to run shifts at all is to *detect* variance. Collecting it and never showing
it converts a control into a ritual.

**Priority: P1.**

---

## F-10 · Three marketing screens are knobs that do nothing

**Current flow.** Marketing → Website, SEO, WhatsApp each render a form, validate carefully, and
persist to `settings` via `MarketingSettings`. **`seo_*` and `wa_*` keys are read by nothing** in the
codebase (verified by exhaustive search). There is no storefront rendered by this application and no
WhatsApp API client, message job, or send path of any kind.

**Problem.** A merchant configures WhatsApp order confirmations with three message templates and
three trigger toggles, saves successfully, and no message is ever sent. They will assume their
customers received notifications. This is the exact failure mode the codebase's own comments
repeatedly and correctly identify as the worst class of bug (*"a knob that reassures and does
nothing is worse than its absence, in the most dangerous place"*) — applied everywhere except here.

**Real-world scenario.** A florist enables "notify on ready", stops phoning customers, and loses
them. The system reports success at every step.

**Recommended flow.** Choose one per screen:
- **WhatsApp:** either implement (WhatsApp Cloud API or a local gateway; queued job on order
  status change; delivery log) or **remove the screen** until implemented.
- **SEO:** these fields belong to the separate `abaadapp/Website` repository. Either have that app
  read them (they are in the same database), or remove the screen from Abaad.
- **Website:** `site_domain` *is* consumed (`Demo::websiteUrl()` powers the "visit site" button). The
  other seven keys are not. Keep the domain, mark the rest as "used by your Abaad website" only if
  the website actually reads them.

**Reason.** Shipping non-functional configuration destroys trust in every *other* setting in the
product. A merchant burned once by a dead toggle stops believing the working ones.

**Priority: P0** (remove or gate the screens) / **P1** (implement WhatsApp — it has real commercial
value in Oman).

---

## F-11 · No VAT return, while VAT is collected on every invoice

**Current flow.** VAT is computed per line with per-product rates, inclusive/exclusive handled
correctly, the ZATCA-style QR is generated, and the tax invoice PDF is proper. `Demo::vatReport()`
exists as a complete function and **is called from nowhere** — its screen was deleted.

**Problem.** Abaad charges the customer VAT, prints it, stores it in `orders.tax` and
`transactions.tax_amount`, and offers the merchant no way to produce a return. The merchant must
export orders to Excel and sum a column — and there is no output VAT / input VAT reconciliation at
all, because purchase VAT is deliberately folded into inventory cost.

**Real-world scenario.** Quarterly filing. The merchant needs output VAT by rate, exempt/zero-rated
sales separated, credit notes deducted, and input VAT on purchases. Abaad can give them one number
(sum of `orders.tax`) via a spreadsheet export, and it will be wrong the moment anyone edits an old
invoice (F-01) because edits restate closed periods.

**Recommended flow.** Restore a VAT report screen on top of the existing `Demo::vatReport()`: period
selector (month/quarter), output VAT by rate, zero-rated and exempt sales split out, returns/credit
notes deducted, input VAT from supplier invoices, net payable. **Lock closed VAT periods** so edits
to invoices in a declared period are refused and must go through a credit note.

**Reason.** VAT compliance is a legal obligation, and it is one of the strongest reasons a small
Omani shop pays for software at all.

**Priority: P0.**

---

## F-12 · Backup and restore lose data silently

**Current flow.** `BackupService::payload()` serialises **17 tables**. `BackupController::restore()`
force-deletes those tables' rows for the tenant and re-inserts from the file.

**Problem.** ~23 tenant tables are neither backed up nor restored, while their parents *are* deleted:

| Not in backup | What happens on restore |
|---|---|
| `branch_stocks` | Not deleted, not restored → **survives with pre-restore quantities while `products.quantity` comes from the file.** Guaranteed divergence. |
| `pos_devices`, `pos_peripherals` | `ON DELETE CASCADE` from `branches` → **every register is silently de-activated** and never comes back. |
| `branch_user` | Cascade → all employee branch assignments lost. |
| `customer_addresses` | Cascade from customer force-delete → lost. |
| `stock_adjustments` | Cascade from product force-delete → lost, but their journal entries survive as orphans. |
| `accounts`, `journal_entries`, `journal_lines` | Untouched → the entire ledger now references deleted products/branches and disagrees with the restored operational data. |
| `supplier_invoices` | FK to `suppliers` is `ON DELETE RESTRICT` → **the restore transaction throws a foreign-key violation** and the whole restore aborts for any merchant who has ever recorded a supplier bill. |
| `payroll_runs/lines`, `fixed_assets`, `bank_accounts`, `bank_statement_lines`, `delivery_notes`, `point_transactions`, `order_edits`, `addons`, `job_titles`, `custom_alerts`, `reviews`, `shift_movements`, `import_batches` | Not backed up, not restored, not deleted → stale references. |

Additionally: the whole payload is built in memory as one JSON document (`json_encode` of every
order with items) — a store with 100k invoices will exhaust memory during the *nightly* job, and the
restore upload cap is 20 MB.

**Real-world scenario.** A merchant restores after a bad import. Registers stop working, branch
stock is wrong for every product, and if they use supplier invoices the restore fails halfway
through a transaction that has already deleted their products. The toast says "restored
successfully".

**Recommended flow.** Short term: **disable the restore button** and label the download as "data
export, not a restore point". Medium term: replace with a proper per-tenant dump/restore covering
every `business_id` table in dependency order, streamed to a file rather than built in memory, with
a dry-run manifest ("this file contains X products, Y invoices, Z journal entries — restoring will
replace…") and an automatic pre-restore snapshot.

**Reason.** A restore that half-works is worse than no restore, because it is invoked precisely when
the merchant is already in trouble.

**Priority: P0.**

---

## F-13 · No onboarding path

**Current flow.** A new merchant lands on an empty dashboard with 14 sidebar sections.

**Problem.** Time-to-first-sale is the single strongest predictor of SaaS activation. Abaad requires
a merchant to independently discover: settings → VAT, currency, invoice prefix, payment methods →
branches → employees → POS device activation → products (or import) → opening stock. Nothing tells
them the order, nothing tells them what is still missing, and several of these are silently required
(e.g. selling with no branch works but produces `orders.branch = 'الفرع الرئيسي'` as a literal
string).

**Recommended flow.** A dismissible setup checklist on the dashboard driven by real state:
store details ✓, VAT configured ✓, first branch ✓, first product ✓ (with "import from Excel" as the
primary CTA), register activated ✓, first employee ✓, opening stock entered ✓, first sale ✓.
Each item deep-links to the exact screen. Hide it permanently once complete.

**Reason.** The product is already capable; the first hour is where merchants decide whether it is.

**Priority: P1.**

---

## F-14 · Permissions are section-level only

**Current flow.** 14 sections; a user either has a section or does not. Manual per-user overrides
exist. Unknown roles fail closed. Route→section mapping is centralised with an alias table.

**Problem.** Retail's real permission questions are about **actions inside a section**:
- Can this cashier give a discount? (Today: any cashier can apply any coupon.)
- Can this cashier edit a completed invoice? (Today: yes — `OrderCorrection` is reachable from POS
  with no extra gate.)
- Can this cashier reprint a receipt / open the drawer / see totals?
  (Totals: correctly gated by `ReceiptVisibility`. The rest: no.)
- Can this employee delete a product / a supplier / an expense? (Today: whoever has the section.)
- Can this employee change a price? (Today: whoever has `products`.)

**Real-world scenario.** A shop owner gives an employee the `products` section so they can add new
items. That employee can now change every price in the store, delete products, and bulk-edit.

**Recommended flow.** Add a small, fixed set of **action permissions** on top of sections — not a
generic ACL. Something like: `discount.apply`, `order.edit`, `order.void`, `order.return`,
`price.edit`, `product.delete`, `stock.adjust`, `shift.close_admin`, `report.financials`. Default
them by role, allow per-user override in the existing manual-permissions UI. Resist anything larger.

**Reason.** Section permissions answer "which screens", not "how much damage". The second is what a
shop owner actually worries about.

**Priority: P1.**

---

## F-15 · Integer quantities exclude an entire retail category

**Current flow.** `order_items.quantity` is `integer`; POS validates `items.*.qty` as
`integer|min:1`; `products` has no unit column.

**Problem.** Any shop selling by weight, length, or volume — butcher, greengrocer, fabric,
hardware, coffee, sweets, spices — cannot use Abaad at all. In Oman these are a very large share of
small retail.

**Recommended flow.** Add `products.unit` (piece / kg / g / litre / metre / box) and
`products.allow_fractional`; widen `order_items.quantity`, `purchase_order_items.quantity`, and
`branch_stocks.quantity` to `decimal(12,3)`; POS numeric keypad accepts decimals for fractional
units; scale-barcode parsing (embedded weight in EAN-13) as a follow-up.

**Reason.** This is a market-size decision, not a feature request. *(See owner decision OD-04.)*

**Priority: P1** if food/weight retail is in scope; **P3** if Abaad targets fashion/electronics/
gifts only.

---

## F-16 · No split or partial payment

**Current flow.** One `payment_method` per order. `payment_status` is always `'مدفوع'`.

**Problem.** "50 cash, 30 card" is routine. So is a deposit on a special order. Neither is
representable. Since credit sales were removed (OD-02), an order is either fully paid in one method
or does not exist.

**Recommended flow.** An `order_payments` child table (method, amount, reference, at) with the
invoice total validated against the sum. Handles split payment, deposits, and later instalments
without reintroducing the full receivables module.

**Priority: P2** (P1 if deposits/special orders are a target use case).

---

## F-17 · Order status lifecycle is inert

**Current flow.** `orders.status` defaults to `'جديد'`; POS always writes `'مكتمل'`. Seeders create
`قيد التجهيز`, `جاهز`, `خرج للتوصيل`. The delivery role exists. WhatsApp templates reference
"ready" and "delivered" events.

**Problem.** An entire order-fulfilment lifecycle is half-present: statuses exist, a delivery role
exists, notification templates for the transitions exist, and **there is no way to move an order
between statuses.** For a walk-in shop this is fine. For the florist/delivery use case the demo
data implies, it is a hole.

**Recommended flow.** Decide whether Abaad supports fulfilment. If yes: status transitions with
permissions, a delivery queue screen, driver assignment, and the WhatsApp triggers that already have
templates. If no: remove the unused statuses and the `delivery` role.
*(See owner decision OD-05.)*

**Priority: P2.**

---

## F-18 · Held carts hold no stock

**Current flow.** `hold()` prices lines from the DB and saves an order with `is_held = true`. A
deliberate comment states that holding does not reserve stock and the guard runs at payment.

**Problem.** Correct as a default, but with no visibility: a cashier holds a cart with the last 3
units, another cashier sells them, and the first cart fails at payment with the customer standing
there. There is no "reserved" indication anywhere.

**Recommended flow.** Keep the no-reservation rule, but show held quantities on the POS product tile
("2 held") and warn on resume if a line is no longer available. Optionally, a merchant setting for
short-lived soft reservations (e.g. 30 minutes) for shops that take orders at a counter.

**Priority: P2.**

---

## F-19 · Products can be deleted while holding stock and history

**Current flow.** `ProductController::destroy()` soft-deletes with an undo toast. No check for
on-hand stock, open POs, or held carts.

**Problem.** Deleting a product with 200 units on hand removes 200 × cost from inventory valuation
instantly and silently. `branch_stocks` rows remain and are invisible. `stock_adjustments` cascade
away on eventual purge. Sales history keeps `order_items.name`, so reports survive — but the
valuation jump is unexplained.

**Recommended flow.** Block (or hard-confirm with the number stated) deletion of a product with
non-zero stock; offer "deactivate" as the primary action instead — `products.active` already exists
and is what merchants almost always mean.

**Priority: P1.**

---

## F-20 · Customer-facing document set is thin

**Current flow.** Receipt (3 paper sizes, 11 template flags), tax invoice PDF, customer statement
PDF.

**Problem.** Missing: quotation/proforma, credit note (blocked by F-01), delivery note *to the
customer* (the internal one exists), and any emailed/WhatsApped copy of a receipt.

**Recommended flow.** Credit note comes free with F-01. Quotation is a natural extension of the
existing `SAVE-` held-cart mechanism (a saved cart that can be printed and later converted to a
sale). Email/WhatsApp receipt is a small addition once F-10 is resolved.

**Priority: P2.**
