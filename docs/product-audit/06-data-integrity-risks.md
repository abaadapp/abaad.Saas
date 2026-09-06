# 06 — Data Integrity Risks

Format: **Scenario → Current behaviour → Risk → Recommended rule.** Severity uses the same P0–P3
scale as `04-module-audit.md`.

---

## R-01 · Restore silently corrupts the tenant — **P0**

**Scenario.** A merchant uses Settings → Backup → Restore after a bad Excel import.

**Current behaviour.** `BackupService::payload()` serialises 17 tables. `BackupController::restore()`
force-deletes those tables for the tenant and re-inserts from the file. Roughly 23 other
`business_id`-scoped tables are neither exported nor deleted nor restored, while several of their
parents *are* force-deleted, triggering cascades.

**Risk.**
| Effect | Consequence |
|---|---|
| `supplier_invoices.supplier_id` is `ON DELETE RESTRICT` | The `Supplier::where(...)->delete()` step **throws a foreign-key violation**. On PostgreSQL the transaction aborts — *after* products, customers and orders have already been deleted inside the same transaction, so it rolls back — but any merchant with a supplier invoice simply cannot restore, with a 500 error and no explanation. |
| `branch_stocks` not deleted, not restored | Survives with pre-restore quantities while `products.quantity` comes from the file → **guaranteed permanent divergence** between company total and branch balances. |
| `pos_devices` cascade from `branches` force-delete | **Every register is de-activated** and never restored. Tills stop working. |
| `branch_user` cascade | Every employee's branch assignment lost. |
| `customer_addresses` cascade | Lost. |
| `stock_adjustments` cascade from product force-delete | Lost — while their journal entries survive as orphans referencing a deleted `sourceable_id`. |
| `accounts` / `journal_entries` / `journal_lines` untouched | The entire ledger now references deleted products and branches and disagrees with the restored operational data. |
| payroll, fixed assets, bank accounts, bank statement lines, delivery notes, point transactions, order edits, addons, job titles, custom alerts, reviews, shift movements, import batches | Not exported, not restored. |
| Whole payload built in memory via `json_encode` | A store with ~100k invoices will exhaust memory in the **nightly** `backup:run` for every tenant. |
| Upload cap `max:20480` (20 MB) | A large store's own backup cannot be uploaded back. |

The success toast says "تمت استعادة البيانات بنجاح" in every partial case.

**Recommended rule.**
1. **Immediately:** hide/disable the Restore action; relabel Download as "Export (not a restore
   point)". Keep the nightly job but exclude very large tenants until streamed.
2. **Before launch:** enumerate backup tables from a single manifest that is asserted against
   `Schema::getTables()` in a test, so a new tenant table cannot be added without appearing in the
   backup. Restore in FK dependency order, delete in reverse order, stream to/from a file, take an
   automatic pre-restore snapshot, and show a manifest ("this file contains X products, Y invoices,
   Z journal entries — restoring replaces all of them") requiring explicit confirmation.

---

## R-02 · Sales never post to the ledger; the trial balance is meaningless — **P0**

**Scenario.** Merchant trades for a quarter and opens الحسابات → ميزان المراجعة.

**Current behaviour.** `Ledger::post()` is never called by a sale, a cash receipt, or an operating
expense. Inventory is debited by every supplier invoice and never relieved.

**Risk.** Revenue = 0 forever. COGS = 0 forever. VAT payable = 0 while VAT is collected on every
invoice. Inventory asset grows monotonically and diverges from the physical valuation shown on the
inventory screen. The trial balance *balances* (the `Ledger` enforces that rigorously) and is
entirely false. Because the demo store *does* post sales journals, a prospect's demo shows correct
books that their own account will never produce — which is a sales-integrity problem as well as a
data one.

**Recommended rule.** Either (a) post sale, COGS, and expense journals from the same transaction
(or a nightly aggregate) — see F-04 for the entries — or (b) hide the الحسابات section behind a
feature flag until it is wired, so the product never shows books it cannot fill. Do not ship it
half-wired.

---

## R-03 · A completed tax invoice is mutable, including in a declared VAT period — **P0**

**Scenario.** A customer returns an item three weeks later, after the VAT quarter was filed.

**Current behaviour.** `OrderCorrection::setQuantity()` edits the original invoice: totals, tax,
transaction row, loyalty and stock are all restated. An `order_edits` audit row is written. No
period lock exists.

**Risk.** The customer's printed tax invoice no longer matches the stored record. A filed VAT return
no longer reconciles to the data. Historical reports change retroactively. There is no credit note
for the customer, and no cash movement is recorded, so the shift that day is unexplainably short.

**Recommended rule.** Introduce a return/credit-note document (F-01). Restrict `OrderCorrection` to
same-shift keystroke corrections behind an `order.edit` permission. Add a **period lock**: once a VAT
period is marked filed, any write touching an invoice in it is refused with a message pointing to the
credit-note flow.

---

## R-04 · Partial receiving is impossible, so stock records a delivery that did not happen — **P0**

**Scenario.** 80 of 100 cartons arrive.

**Current behaviour.** `receive()` sets `received_quantity = quantity` for every line and adds the
full remaining quantity to stock.

**Risk.** Inventory over-states by 20 units. POS sells units that do not exist. The weighted-average
cost is computed on a quantity that never arrived, so unit cost is wrong for every subsequent sale.

**Recommended rule.** Per-line received quantity entered at receiving time; status derived; a GRN
document per receiving event.

---

## R-05 · `PurchaseOrder::receive()` has no transaction and no locks — **P0**

**Scenario.** Receiving a 60-line PO; the request fails at line 35 (timeout, deadlock, deploy).

**Current behaviour.** Lines 1–34 have already incremented stock, mutated cost, and written
movements. The PO is still `مُرسل`. The storekeeper retries and lines 1–34 are received **again**
(`$item->remaining` is only zero if `received_quantity` was written — which happens at the end of
each line iteration, so it *is* written per line; but `$po->status` is only set at the end, so a
retry re-enters the loop and skips completed lines correctly). The real exposure is the cost
recalculation and the concurrent-sale race.

**Risk.** Concurrent POS sales read `products.quantity` and `cost` while `receive()` is mid-loop.
`$product->increment()` is atomic per statement, but the weighted-average computation is
read-compute-write with no lock: two simultaneous receipts of the same product produce a cost that
reflects only one of them. Inventory value drifts permanently.

**Recommended rule.** Wrap the whole receipt in `DB::transaction()`; `lockForUpdate()` the affected
product rows in a stable id order (the pattern already used in `PosController::priceItems`).

---

## R-06 · Purchase order numbers collide — **P0**

**Scenario.** A busy wholesaler creates its 350th purchase order.

**Current behaviour.** `'PO-' . random_int(10000, 99999)`, with only a **non-unique** index on
`purchase_orders.number`.

**Risk.** ~50% probability of at least one duplicate PO number by the 350th order (~5% by the 100th).
Two different purchase orders carry the same reference; the supplier invoice that links to a PO
becomes ambiguous; the merchant cannot tell which document a delivery relates to.

This is the identical bug that was correctly diagnosed and fixed for `orders.number` and
`transactions.reference` — with detailed comments explaining why — and left in place here.

**Recommended rule.** Reuse the existing sequential generator with a unique index on
`(business_id, number)` and the retry-on-conflict loop from `PosController::createNumbered`.

---

## R-07 · Stocktake is not atomic and is O(n²) — **P0 for stores > ~500 SKUs**

**Scenario.** A 4,000-SKU supermarket applies its quarterly count.

**Current behaviour.** `InventoryController::applyStocktake()` loops over counted products with **no
`DB::transaction`**, and calls `BranchStock::bookOf()` inside the loop — which calls
`BranchStock::books()`, which loads **every product and every branch-stock row** for the tenant on
each iteration.

**Risk.**
- Performance: 4,000 full table loads in one request → guaranteed timeout.
- Atomicity: a timeout leaves the count half-applied with no record of where it stopped. A retry
  compares the new (already-adjusted) book against the same counted numbers, finds no variance for
  the applied half, and produces a shrinkage `Expense` for only part of the count — while the first
  run's `Expense` already exists. Shrinkage is recorded twice for some products and never for others.

**Recommended rule.** Call `books()` once before the loop; wrap in a transaction; for counts above a
threshold, process in a queued job with a resumable Stocktake document (header + lines + status).

---

## R-08 · Stocktake overage inflates assets with no counterpart — **P1**

**Scenario.** The count finds 12 units where the book says 10.

**Current behaviour.** Quantity is increased in both `branch_stocks` and `products.quantity`.
Shortage creates an `Expense`; **overage creates nothing** (a comment explains that booking it as
income would inflate profit — correct reasoning, incomplete conclusion).

**Risk.** Inventory value increases by 2 × cost with no offsetting entry anywhere. Repeated over
many counts, inventory valuation drifts upward and the merchant's balance sheet (once F-04 lands)
will not balance against reality.

**Recommended rule.** Book both directions to a single `inventory_variance` account:
shortage `Dr Variance / Cr Inventory`, overage `Dr Inventory / Cr Variance`. Net variance is then a
single, meaningful, reviewable number rather than an asymmetric one.

---

## R-09 · The same shrinkage lands in three different places — **P1**

**Scenario.** Five units are broken. Three employees record it three different ways.

**Current behaviour.**
| Path | Financial effect |
|---|---|
| Inventory → Movement `تلف` | **none** |
| Inventory → Adjustments | journal entry to `other_expenses` |
| Stocktake shortage | an `Expense` row |

**Risk.** Shrinkage cost is unknowable. Reports built on `expenses` and reports built on
`journal_lines` disagree. `inventory_movements` double-counts the Adjustments path (which writes to
both tables).

**Recommended rule.** One adjustment door (F-05), one account, one document.

---

## R-10 · `products.quantity` is clamped while `branch_stocks` is not — **P1**

**Scenario.** A correction takes the company total below zero.

**Current behaviour.** `InventoryController::store()` and `applyStocktake()` both write
`$product->quantity = max(0, $old + $delta)`. `BranchStock::adjust()` deliberately does **not** clamp
(with a comment explaining that hiding negatives breaks the invariant).

**Risk.** When the clamp fires, `sum(branch_stocks) ≠ products.quantity` permanently and silently —
the exact failure the `BranchStock` comment says it removed a clamp to avoid. `Stock::availability`
then returns branch numbers that do not reconcile to the total shown on the same screen.

**Recommended rule.** Remove the clamp; let the total go negative so the error is visible, and add a
"stock integrity" check in `Preflight` / an admin diagnostic that reports any product where
`sum(branches) ≠ total`.

---

## R-11 · Stock adjustments in "All branches" mode change the total but no branch — **P1**

**Scenario.** The header branch switcher is on "كل الفروع" (the default for a new session) and the
user records a damage adjustment.

**Current behaviour.** `StockAdjustmentController::store()` reads `Demo::currentBranchId()`, which
returns **null** in All-branches mode. `$product->increment('quantity', $delta)` runs unconditionally;
`BranchStock::adjust()` is skipped by the `if ($branchId = …)` guard.

**Risk.** Company total moves, no branch moves. Invariant broken. Also `stock_adjustments.branch_id`
and `inventory_movements.branch_id` are NULL, so the adjustment appears in no branch's history.

Note the contrast: `InventoryController::store()` **requires and validates** `branch_id` explicitly,
and `Shifts::open()` deliberately uses `activeBranchId()` (never null) precisely because "all
branches is a viewing mode, not an operating location". The Adjustments screen missed that rule.

**Recommended rule.** Use `Demo::activeBranchId()` or require an explicit, validated `branch_id` —
consistent with every other write path.

---

## R-12 · Stock adjustment guards the company total, not the branch — **P1**

**Current behaviour.** `if ($delta < 0 && abs($delta) > (float) $product->quantity)` — the company
total. `InventoryController::store()` correctly guards the branch book.

**Risk.** Writing off 8 units in Salalah (which holds 3) is allowed because Muscat holds 20.
Salalah's branch balance goes to −5. The POS in Salalah then refuses sales of a product that is on
the shelf, or (worse) the negative propagates into valuation.

**Recommended rule.** Guard the branch, via `Stock::availabilityResolver(..., lock: true)` — the same
helper the POS and `OrderCorrection` already use.

---

## R-13 · Delivery-note quantity is truncated to an integer — **P1**

**Scenario.** A delivery note for 2.5 kg is marked delivered.

**Current behaviour.** `delivery_note_items.quantity` is `numeric` and accepts decimals. `deliver()`
does `$qty = (int) $item->quantity;` — **2.5 becomes 2**. The availability pre-check compares the
float against `products.quantity`; the decrement uses the truncated integer.

**Risk.** The paper says 2.5 went out, the system removes 2. The 0.5 accumulates as phantom stock
forever.

**Recommended rule.** Either forbid fractional quantities on delivery notes, or (better) do F-15 and
make quantities decimal end-to-end.

---

## R-14 · Delivery-note availability check is against the wrong scope and outside the transaction — **P1**

**Current behaviour.** The pre-check compares `item->quantity` against `products.quantity` (company
total) and runs **before** `DB::transaction()`. The decrement inside then reduces the note's branch.

**Risk.** Classic TOCTOU plus a scope mismatch: a note delivered from Salalah passes because Muscat
has stock; and a concurrent sale between the check and the decrement is not serialised.

**Recommended rule.** Move the check inside the transaction and resolve availability with
`Stock::availabilityResolver($bid, $note->branch_id, …, lock: true)`.

---

## R-15 · Deleting a product with stock silently destroys inventory value — **P1**

**Current behaviour.** `ProductController::destroy()` soft-deletes with no checks.

**Risk.** 200 units × cost vanish from every valuation instantly, with an "undo" toast as the only
signal. `branch_stocks` rows survive invisibly; on eventual `trash:purge` the hard delete cascades
`stock_adjustments` away while their journal entries remain as orphans.

**Recommended rule.** Refuse deletion when stock ≠ 0 or open POs exist; steer to `active = false`
(which already exists and is what merchants usually mean). If deletion must be allowed, require a
zeroing adjustment first so the write-off is recorded.

---

## R-16 · Deleting a purchase order does not reverse the stock it created — **P1**

**Current behaviour.** `PurchaseOrderController::destroy()` deletes any PO, including a received one,
and only removes the receipt attachment file.

**Risk.** Stock stays, the document justifying it disappears. The average-cost history that the
receipt produced is unexplainable.

**Recommended rule.** Refuse deletion of a PO with any received quantity; offer cancel (for
unreceived) and a reversing GRN (for received).

---

## R-17 · Deleting a supplier invoice deletes posted journal entries — **P1**

**Current behaviour.**
`JournalEntry::where('sourceable_type', …)->where('sourceable_id', …)->delete();` then the invoice is
deleted. Guarded so that an invoice with any payment cannot be deleted — good — but the ledger rows
are **erased**, not reversed.

**Risk.** A posted, numbered accounting entry disappears, leaving a gap in the journal number
sequence and no record that it ever existed. This is the one thing a general ledger must never do.

**Recommended rule.** Never delete a posted entry. Post a reversing entry (same date or today's,
description "عكس قيد رقم …"), and mark the source document void.

---

## R-18 · Coupon `used_count` only ever goes up — **P2**

**Current behaviour.** Incremented inside `checkout()`. Never decremented by `OrderCorrection`, and
there is nothing to decrement it on a void/return (which do not exist).

**Risk.** "First 100 customers" campaigns exhaust early. A merchant testing a coupon burns uses.
`coupon_stats` over-reports redemption.

**Recommended rule.** Decrement on void/return; recompute `used_count` from
`orders.coupon_code` on a schedule as a self-healing measure.

---

## R-19 · `Transaction::nextReference()` can produce duplicates under concurrency — **P2**

**Current behaviour.** Reads the last `TRX-%` reference by `id DESC`, adds one. The index on
`transactions.reference` is **not unique**.

**Risk.** Two simultaneous manual finance entries (or a manual entry racing a scheduled job) receive
the same reference. The commit for `orders.number` explicitly calls out why duplicate references in a
financial book are dangerous — the same reasoning applies here and the unique index is missing.

**Recommended rule.** Unique index on `(business_id, reference)` plus the retry loop already used for
orders.

---

## R-20 · `orders.branch` is a denormalised string that can go stale — **P2**

**Current behaviour.** Both `branch_id` and `branch` (name) are written. `OrderController::index`
filters on `branch_id`; several report paths and the receipt read the string.

**Risk.** Renaming a branch leaves every historical order labelled with the old name while filters
use the id. Two screens disagree about the same order's branch.

**Recommended rule.** Keep the string only as an immutable historical label and say so, or drop it
and always join. Do not read it in filters. Same for `users.branch` (superseded by `branch_user`) and
`businesses.branches_count` (superseded by the `branches` table but still displayed in the platform
console, where it is simply wrong for any store that added a branch).

---

## R-21 · `BranchStock::ensureAllocated` is not tenant-scoped — **P2 (latent)**

**Current behaviour.** `if (static::where('product_id', $productId)->exists()) return;` — no
`business_id` filter.

**Risk.** Correct today only because `products.id` is globally unique. If product ids ever become
tenant-scoped (a plausible sharding or import-migration change), this becomes a cross-tenant read.
Every other query in the file is scoped.

**Recommended rule.** Add `->where('business_id', $businessId)`.

---

## R-22 · Shift attribution is read outside the checkout transaction — **P2**

**Current behaviour.** `Shifts::current()` is called before `DB::transaction()`; the resulting
`shift_id` is written to the order inside it.

**Risk.** A shift closed between the read and the write attributes a sale to a closed shift, whose
frozen totals will never include it. Rare, but it is exactly the kind of drawer discrepancy the
shift module exists to detect.

**Recommended rule.** Re-read the open shift inside the transaction, or reject with a clear message
if it closed.

---

## R-23 · Drawer cash-out leaves the business with no financial record — **P2**

**Current behaviour.** `Shifts::move(OUT)` writes a `shift_movements` row with a mandatory reason and
adjusts expected cash. No `Expense`, no `Transaction`.

**Risk.** Petty cash (a delivery driver's fuel, a supplier paid in cash from the till) reduces the
drawer correctly and never appears in expenses, profit, or the finance screen.

**Recommended rule.** Offer an optional expense category on cash-out; when chosen, create the linked
`Expense` + `Transaction` exactly as the Finance screen already does.

---

## R-24 · Held carts are priced at hold time and re-priced at checkout, with no signal — **P3**

**Current behaviour.** `hold()` stores line prices from the DB; `resume()` returns them to the cart;
`checkout()` re-prices from the DB. The coupon is deliberately re-validated (well-reasoned).

**Risk.** A cart held before a price change is resumed after it; the cashier reads the old total on
screen and the customer is charged the new one. Small, but it is a "the screen said X and we charged
Y" event, which is the class of bug the VAT work went to great lengths to eliminate.

**Recommended rule.** On resume, compare stored line prices with current prices and warn if any
changed.

---

## R-25 · No guard against selling below cost, and no negative-price protection at the product level — **P3**

**Current behaviour.** POS never trusts a client price (excellent). But `products.price` accepts any
non-negative number and there is no warning when `price < cost`.

**Risk.** A mis-keyed import (price and cost columns swapped) sells the whole catalogue at a loss
with no alert.

**Recommended rule.** Warn on save and on import preview when price < cost; surface a "selling below
cost" list in reports.

---

## Concurrency summary

| Path | Transaction | Row locks | Verdict |
|---|---|---|---|
| POS checkout | ✅ | ✅ ordered `lockForUpdate` | **Correct** |
| Order correction | ✅ | ✅ | **Correct** |
| Shift open | ✅ | ✅ | **Correct** |
| Stock adjustment | ✅ | ❌ | Adequate |
| Delivery note deliver | ✅ (check outside) | ❌ | Weak |
| Supplier invoice / payment | ✅ | ❌ | Adequate |
| Payroll | ✅ | ❌ | Adequate |
| **PO receive** | ❌ | ❌ | **Weak** |
| **Stocktake apply** | ❌ | ❌ | **Weak** |
| Backup restore | ✅ | n/a | Broken for other reasons (R-01) |

The two unprotected paths are both inventory writers, which is the worst place for it.

## Duplicate-submission protection

- POS checkout: ✅ `client_uuid` idempotency — genuinely well done.
- Everything else: ❌ no idempotency keys. Double-clicking "Receive", "Apply stocktake", "Pay
  supplier invoice", or "Record expense" will submit twice. The supplier-invoice **duplicate-ref
  guard** catches the most dangerous one; the others do not have equivalents. Inertia's default
  form handling disables the button during flight, which mitigates but does not prevent (browser
  back + resubmit, flaky network retry).
