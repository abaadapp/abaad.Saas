# 05 — Retail Logic Audit

One entry per retail concept. Only what exists is evaluated; genuinely absent modules are listed
in §22 and in `08-missing-features.md`.

Verdict scale: **Sound · Adequate · Weak · Wrong · Absent**

---

## 1. Products — **Adequate**

Name (ar+en), SKU, barcode, category, price, cost, quantity, alert level, per-product VAT rate,
per-product discount %, image, active flag, soft delete.

- ✅ `sellingPrice()` applies the product discount and is used by POS — a knob that was previously
  saved and ignored, now wired.
- ✅ `taxRate()` clamps to 0–100 and falls back to the store rate.
- ✅ `discountRate()` clamps historical bad data to 0–100.
- ⚠️ **Barcode is not unique.** Two products may share a barcode; `scanBarcode()` returns whichever
  matches first. Scanning becomes non-deterministic.
- ⚠️ **SKU is not unique** either.
- ❌ No unit of measure. No fractional quantity (F-15).
- ❌ No variants.
- ❌ No supplier association.
- ❌ No min/max stock (only a single `alert_qty`).
- ❌ No product-level branch pricing.

## 2. Product variants — **Absent**

No table, no column, no UI. A shop selling one shirt in 4 sizes × 5 colours must create 20 products
with 20 barcodes and no relationship between them. Reports cannot group them. Reorder suggestions
list 20 rows.

## 3. Categories — **Adequate**

Per-tenant, icon + colour, `parent_id` column present. Auto-created during product import.
❌ No nesting UI despite the column. ❌ No category-level reporting screen (`Demo::categorySales()`
exists and is orphaned).

## 4. Units of measure — **Absent** (see F-15)

## 5. Pricing — **Weak**

One `price` per product, one optional discount %.
❌ No wholesale/retail tiers, no customer-group pricing, no time-limited promotional price, no
buy-X-get-Y, no branch pricing. Coupons are the only promotional mechanism.

## 6. Cost price — **Sound** (the best-reasoned logic in the system)

- ✅ Weighted average maintained on PO receipt, with a documented rejection of last-cost.
- ✅ Zero/negative on-hand resets to the purchase cost rather than averaging against nothing.
- ✅ **Cost is snapshotted onto `order_items.cost` at sale time**, so historical profit does not
  change when a supplier raises a price. This is genuinely correct and many commercial systems get
  it wrong.
- ✅ `stock_adjustments.cost_at_time` snapshots too.
- ⚠️ The stocktake shrinkage value reads live cost, not a snapshot.
- ⚠️ Cost is company-wide, not per-branch — acceptable for SME retail, worth stating explicitly.
- ❌ Cost is not updated by the supplier *invoice* (which may carry freight/duty), only by the PO
  line cost. Landed cost is not representable.

## 7. Selling price / margin — **Adequate**

`Demo::productProfitability()` exists and is used on the product page. There is no margin column in
the product list, no low-margin alert, and no "selling below cost" guard at POS.

## 8. Discounts — **Weak**

Three mechanisms: product discount %, coupon, loyalty redemption.
❌ **No manual discount at the point of sale.** A cashier cannot take 1 OMR off for a regular
customer, cannot round down, cannot apply a manager-approved discount. Verified: `usePosCart` exposes
only `couponDiscount` and `redeemDiscount`; `checkout()` accepts no discount field.
- Consequence: shops improvise by creating coupons (`SORRY10`) or editing the invoice afterwards.
- Consequence: there is no discount permission because there is no discount action — so when one is
  added, F-14 becomes a prerequisite.
- ✅ When discounts *do* apply, they are **apportioned across lines by value before VAT**, which is
  correct and non-obvious. Deducting from a single pool would have under-taxed zero-rated items.

## 9. Taxes / VAT — **Sound in calculation, Absent in compliance**

- ✅ Master on/off switch, honoured in `Vat::rate()`, `Vat::rateFor()`, `PosController::taxFor()`,
  and `OrderCorrection::recompute()` — including the subtle case of a product with its own rate in a
  store that disabled VAT.
- ✅ Inclusive vs exclusive handled correctly, including the `subtotal -= tax` adjustment so the
  displayed total equals the charged total.
- ✅ Per-product rates (zero-rated bread/milk/medicine in Oman).
- ✅ Platform-level defaults with tenant override.
- ✅ ZATCA-style TLV QR with correct byte-length handling for Arabic; suppressed entirely when no VAT
  number is set, and the invoice does not claim to be a tax invoice in that case.
- ❌ **No VAT return / filing report** (F-11).
- ❌ **No period locking.** An invoice in a declared quarter can be edited today (F-01).
- ❌ Input VAT is deliberately folded into inventory cost (correct for non-registered merchants,
  wrong for registered ones — the code comment acknowledges the trade-off but the system has no way
  to know which the merchant is, even though `vat_number` tells it).
- ❌ No tax on delivery fee (`delivery_fee` is added after tax, untaxed).

## 10. Suppliers — **Weak**

Name, phone, email, contact person, notes. Import/export.
❌ No payment terms, no tax number, no address, no default currency, no balance, no statement,
no ageing (F-08).

## 11. Purchases / Purchase orders — **Weak** (F-07)

Detailed above: random number without a unique index, no partial receipt, no transaction wrapper,
no financial effect, three unreachable statuses, deletion does not reverse stock, no approval, no
expected date, no landed cost.

## 12. Receiving — **Weak** (F-07)

All-or-nothing. `received_quantity` written but never partial. No GRN document.

## 13. Sales — **Sound** (except for what it cannot undo)

The checkout path is the strongest code in the system: server-side re-pricing, row locking,
branch-scoped stock assertion inside the transaction, per-line tax, coupon re-validation,
idempotent offline replay, sequential numbering with a unique index and retry, cost snapshot,
seven coordinated writes in one transaction. ✅

Gaps: no split payment (F-16), no partial payment, no manual discount (§8), integer quantities
(F-15), no ledger posting (F-04), no order-level notes surfaced to the kitchen/prep, no
"sold by weight" flow.

## 14. POS — **Sound**

Offline outbox, idempotency, live stock feed, register binding, cashier attribution, blind shift
close, receipt visibility control, PIN login, screen lock, barcode focus management, Arabic-digit
input normalisation. This is a professionally-built POS.

Gaps: no cash-drawer kick command (peripherals are registered but there is no fire path visible),
no customer display, no offline product catalogue if the page is loaded cold while offline.

## 15. Returns — **Absent** (F-01) — the single largest hole in the product.

## 16. Refunds — **Absent.** Money returned to a customer has no representation anywhere.

## 17. Inventory — **Weak**

Two sources of truth mediated correctly on read; six write paths with three rule sets on write
(F-05, F-06). `Stock::availabilityResolver` is a genuinely good piece of design — the
"allocated vs never-allocated" distinction correctly prevents both phantom stock and empty screens.

- ⚠️ `products.quantity` is clamped with `max(0, …)` in two places while `branch_stocks` is
  deliberately allowed to go negative. When the clamp fires, the invariant *sum(branches) = total*
  breaks silently — the exact failure the `BranchStock` comment says it removed a clamp to avoid.
- ⚠️ `Demo::inventory()` shows `qty` for the selected branch but computes `value` from the
  **company** quantity — one row mixing two scopes.
- ❌ No stock valuation report (FIFO/average/total by branch).
- ❌ No stock ageing / slow-mover report (`Demo` has dead-stock alert logic but no screen).
- ❌ No serial/batch/expiry tracking (relevant for pharmacy, food).

## 18. Stock movements — **Adequate as an audit log**

`inventory_movements` records every path with type, branch, employee, and a signed quantity string.
⚠️ `quantity` is stored as a **varchar** with a `+`/`-` prefix, so it cannot be summed in SQL. Any
"total in / total out" report must parse strings in PHP.
⚠️ The Adjustments screen writes to *both* `stock_adjustments` and `inventory_movements`, so a
report over movements double-counts relative to a report over adjustments.
❌ The movements report screen was deleted; `Demo::movements()` still exists and is used only on the
inventory index.

## 19. Transfers — **Absent** (F-03).

## 20. Adjustments — **Weak** (F-05) — implemented twice, incompatibly.

## 21. Stock counts — **Weak** (F-06) — not atomic, O(n²), overage unaccounted, no document.

## 22. Damaged inventory — **Adequate**

`StockAdjustment::REASONS` covers damage/loss/count correction, with a ledger entry — but to a
generic `other_expenses` account, and only via one of the two adjustment screens.

## 23. Warehouses — **Absent as a distinct concept.** Branches double as warehouses. For SME retail
this is a reasonable simplification and should stay that way (see `09-overengineering-review.md`).

## 24. Branches — **Adequate**

Real entity, scoped everywhere, soft-deletable with a delete-confirmation that states how many
invoices and registers it holds (a nice touch). Employees assignable to multiple branches, enforced
at POS login.
⚠️ Three redundant representations: `orders.branch` (name string) alongside `branch_id`;
`users.branch` (string) alongside `branch_user`; `businesses.branches_count` (typed number) alongside
the `branches` table. The platform console displays the typed number, so it can be wrong.

## 25. Cash management — **Adequate** (F-09)

Shifts, opening float, in/out with reasons, over-withdrawal guard, blind close, frozen variance,
auto-close of abandoned shifts. Excellent — with no owner-facing screen and no link between drawer
cash-out and expenses.

## 26. Expenses — **Adequate**

Types, attachments, due dates, paid/unpaid discipline (only paid reduces profit — correct), soft
delete with the linked transaction, restore, purge.
❌ No `branch_id` (the stocktake workaround writes the branch into the description string).
❌ No recurring expenses despite the section being labelled "monthly expenses".
❌ Never reaches the double-entry ledger.

## 27. Payments (received) — **Weak**

One method per invoice, always fully paid, no partial, no split, no payment reference/auth code for
card transactions, no settlement/reconciliation of card takings against the bank.

## 28. Credit sales — **Removed by owner decision** (migration `2026_08_12_150000`). See OD-02.

## 29. Supplier balances — **Weak** (F-08). Data exists; visibility does not.

## 30. Customer balances — **Absent** by the same decision as §28.

## 31. Reports — **Weak**

The report index is honest and permission-filtered. But the set is thin for a paid retail product:
present are sales summary, finance movement, expenses, payment methods, bank statement, orders,
products, inventory, purchase orders, suppliers, staff performance, activity, top customers,
coupons. Absent are: **P&L, VAT return, profitability by product/category, stock valuation, stock
movement, shift/Z report, returns analysis, hourly/weekday sales, sales by category** — and for six
of these the *data functions still exist* in `Demo.php`, orphaned.

## 32. Employees — **Adequate**

Job titles → roles, PIN or email login, branch assignment, manual permissions, salary fields,
activate/suspend, password reset by owner, performance leaderboard.
❌ `monthly_target` and `commission_rate` are captured and displayed and **never computed** — a
fourth dead knob.
❌ No attendance, no shift rostering.

## 33. Roles — **Sound.** Single source, eight roles, sensible defaults, fail-closed on unknown.

## 34. Permissions — **Weak** (F-14). Section grain only.

## 35. Settings — **Adequate**

Closed whitelist with per-key validation (an excellent pattern). Consequence: four settings that the
code reads (`allow_negative_stock`, `require_open_shift`, `shift_max_hours`,
`dormant_customer_days`) have no way to be set.

## 36. Coupons — **Adequate**

Percent/fixed, min order, max uses, expiry (correctly end-of-day — a well-caught bug), active
toggle, server-side re-validation, cost tracked separately from loyalty in `orders.coupon_discount`.
- ⚠️ **`used_count` is incremented at checkout and never decremented** when the invoice is
  subsequently edited or would be voided/returned. A "first 100 customers" campaign will miscount.
- ❌ No per-customer usage limit — one customer can use `WELCOME20` unlimited times.
- ❌ No product/category restriction.

## 37. Loyalty — **Sound**

Earn rate, redemption cap as a % of the invoice, minimum redeemable balance, full
`point_transactions` ledger with `balance_after`, identity anchored to the phone number (with
uniqueness enforced and a clear error message), correct delta-based adjustment on invoice edit that
never pushes a balance negative.
❌ No point expiry. ❌ No tiers.

## 38. Multi-currency — **Adequate for display**

Base currency + additional currencies with rates; display-only conversion held in the session;
money stored in base. ⚠️ Historical amounts are re-converted at *today's* rate when viewed in a
foreign currency, so a printed report can change value between viewings. Acceptable if positioned as
a display convenience; not acceptable if positioned as multi-currency accounting.

## 39. Activity log — **Sound.** ~60 call sites, impersonator captured, per-tenant, searchable.

## 40. Data import — **Sound**

Product import is the best feature in the back-office: header auto-detection with Arabic and English
synonyms, column remapping, preview before commit, per-branch quantity columns, prices-include-tax
handling, category auto-creation, and a **full undo of the batch**. Customer and supplier import
follow the same pattern.
