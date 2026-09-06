# 08 — Missing Features

Every item carries a **business reason**. Nothing here is recommended because a competitor has it.

---

## A. Launch Critical
*Required before Abaad can be sold seriously to a real shop.*

### A-1 · Sales returns & credit notes
**Reason.** Returns are 3–10% of retail transactions. A POS that cannot process one forces the shop
to improvise, and every improvisation corrupts stock, VAT, or the drawer. It also blocks: refund
tracking, return-rate reporting, `sales_returns` accounting, and the credit note the customer is
legally entitled to. **This is the single largest gap in the product.**
*Scope:* return document with its own number series, line selection with quantity ≤ sold and ≤ not
already returned, reason, refund method, stock return, loyalty reversal, credit-note PDF, shift cash
movement when refunded in cash.

### A-2 · Invoice void / cancel
**Reason.** Duplicate ring-ups and test sales happen on day one. Without void, the number series
becomes untrustworthy and phantom sales are permanent. `Order::CANCELLED` already exists and is
already filterable — the merchant can see the state and never reach it.

### A-3 · Sales & COGS posting to the general ledger — or hide the ledger
**Reason.** Today the accounting module shows books with zero revenue. Either it produces real books
or it must not be visible. Shipping a believable-but-empty trial balance is worse than shipping no
accounting at all, and the demo store *does* show correct books, which makes it a sales-integrity
problem too.

### A-4 · VAT return report
**Reason.** Abaad collects VAT on every invoice, prints a compliant QR, and gives the merchant no way
to declare it. VAT compliance is one of the strongest reasons an Omani SME buys software.
`Demo::vatReport()` already exists, unused.

### A-5 · Partial goods receipt
**Reason.** Short deliveries are normal. Forcing "all or nothing" means either inventory over-states
(and the POS sells air) or a whole delivery goes unrecorded. `received_quantity` and the
`مستلم جزئيًا` status already exist in the schema and the UI filter.

### A-6 · Safe backup & restore
**Reason.** The current restore covers 17 of ~40 tenant tables, cascades registers and branch stock
away, and fails outright for any merchant using supplier invoices — while reporting success. It is
invoked precisely when the merchant is already in trouble. Minimum: disable restore, relabel the
download, and fix the nightly job's memory profile before launch.

### A-7 · Stocktake that works at real catalogue size
**Reason.** The current implementation is O(n²) and non-atomic; it will time out and half-apply on
any store above ~500 SKUs. The quarterly count is the moment a merchant decides whether to trust the
system's stock figures forever.

### A-8 · Remove or implement the dead marketing screens
**Reason.** WhatsApp and SEO save settings that nothing reads. A merchant who enables "notify
customer when ready", stops phoning, and loses customers will never trust another toggle in the
product. Removing them costs nothing; leaving them costs the merchant relationship.

### A-9 · Manual discount at the point of sale (with a permission)
**Reason.** "Take a rial off for a regular" is universal in Omani retail. Without it, shops create
throwaway coupons or edit invoices after the fact — both of which corrupt data. This must arrive
together with the discount permission (A-10) so it can be controlled.

### A-10 · Action-level permissions (a small, fixed set)
**Reason.** Section permissions answer "which screens"; a shop owner worries about "how much damage".
Today anyone with the `products` section can change every price and delete products; anyone at the
POS can edit a completed invoice. Needed set: `discount.apply`, `order.edit`, `order.void`,
`order.return`, `price.edit`, `product.delete`, `stock.adjust`, `report.financials`. Resist anything
larger.

### A-11 · Branch-to-branch stock transfer
**Reason.** Multi-branch is a priced plan feature (`max_branches` is enforced), and the core
multi-branch operation is missing. If launch is single-branch only this drops to Commercially
Valuable — see OD-03.

---

## B. Commercially Valuable
*Significantly improves win rate, retention, or daily usefulness.*

### B-1 · Profit & Loss and Balance Sheet
**Reason.** "Did I make money this month?" is the question the owner opens the app to answer.
`Ledger::trialBalance()` already returns everything needed; only the presentation is missing.
Depends on A-3.

### B-2 · Back-office shift review + Z-report
**Reason.** Shifts are collected meticulously and never shown to the person who needs them. A
cashier 2 rials short every night is invisible without a variance list.

### B-3 · Onboarding checklist
**Reason.** Time-to-first-sale predicts activation more than any feature. A merchant currently has to
discover a nine-screen setup path unaided.

### B-4 · Supplier statement, balance & payables ageing
**Reason.** "What do I owe, and what's due this week?" is a daily question with no answer today. All
data already exists in `supplier_invoices`.

### B-5 · Merge the two stock-adjustment systems
**Reason.** The same event produces different data depending on which menu item was clicked, and
reports over `inventory_movements` double-count one of the paths. One door, one account, one document.

### B-6 · Split & partial payments
**Reason.** "30 card, 20 cash" is routine; deposits on special orders are common in florists,
tailors, and furniture — visible target segments given the demo data. An `order_payments` child table
covers both without reintroducing full receivables.

### B-7 · WhatsApp order notifications (actually implemented)
**Reason.** In Oman, WhatsApp *is* the customer channel. The templates and toggles are already
designed; only the sending is missing. Strong differentiator versus generic imported POS software —
but only if it works.

### B-8 · Units of measure & fractional quantities
**Reason.** Butchers, greengrocers, coffee roasters, fabric and hardware shops are a large share of
Omani small retail and cannot use Abaad at all today. This is a market-size decision — see OD-04.

### B-9 · Stock valuation & movement reports
**Reason.** Inventory is usually the merchant's largest asset and there is no report of its value by
branch, its ageing, or its movement. `Demo::movements()` exists; the screen was deleted.

### B-10 · Reorder → draft purchase order in one click
**Reason.** `Demo::reorderSuggestions()` already computes what to buy. Turning a suggestion into a PO
(grouped by supplier) converts a report into a workflow. Requires a supplier link on the product.

### B-11 · Period lock (VAT / accounting close)
**Reason.** Without it, a filed VAT period can be silently restated by an invoice edit. Cheap to
implement, and it is what makes A-1 and A-4 trustworthy.

### B-12 · Product variants
**Reason.** Fashion, footwear, and accessories — the segments most likely to pay for a nice POS —
cannot represent size/colour without creating N products with no relationship, N barcodes, and
unusable reports.

### B-13 · Recurring expenses
**Reason.** Rent and utilities are every shop's largest fixed costs and must be re-keyed monthly. The
sidebar already calls the section "monthly expenses".

### B-14 · Bulk actions in lists
**Reason.** Repricing a category by 5%, deactivating a discontinued line, or recategorising 40
products currently means 40 individual edits. The shared `DataTable` has no row selection at all.

---

## C. Advanced
*Valuable for larger or more specialised customers; not needed for the first cohort.*

- **C-1 · Customer credit / receivables** — removed by owner decision (OD-02). Wholesalers and B2B
  shops will ask for it. If it returns, it should return as a deliberate module (credit limit,
  ageing, statements, payment allocation), not as a `paid_amount` column.
- **C-2 · Price tiers / wholesale pricing / customer groups** — for shops with both retail and trade
  counters.
- **C-3 · Serial, batch & expiry tracking** — pharmacy, food, electronics with warranty.
- **C-4 · Landed cost** (freight, customs apportioned across a shipment) — importers.
- **C-5 · Employee commission calculation** — `commission_rate` and `monthly_target` are already
  collected and displayed and never computed. Either compute them or remove the fields.
- **C-6 · Attendance / rostering** — links to payroll, which already exists.
- **C-7 · Purchase approval workflow** — for businesses with a purchasing employee separate from the
  owner.
- **C-8 · Multi-warehouse distinct from branch** — only when a customer actually has a non-selling
  warehouse.
- **C-9 · Payment-gateway integration** (Thawani / Amwal) for self-service subscription renewal —
  currently manual bank transfer reviewed by the operator. Worth it once the merchant count makes
  manual reconciliation expensive.
- **C-10 · Public API / webhooks** — for accountants' systems and e-commerce.
- **C-11 · Customer-facing display & drawer-kick** — peripherals are already registered; the fire
  paths are not there.
- **C-12 · Quotations / proforma invoices** — a natural extension of the existing `SAVE-` held-cart
  mechanism.

---

## D. Future
*Good ideas whose time is not now.*

- **D-1 · Native mobile app** — the POS is already a well-built responsive web app with an offline
  queue; a native shell adds cost before it adds value.
- **D-2 · E-commerce storefront** — the website settings imply one; it belongs in the separate
  `abaadapp/Website` repository, not here.
- **D-3 · Loyalty tiers and point expiry** — the base program works; tiers are a retention
  optimisation for shops that already have a loyal base.
- **D-4 · Advanced analytics / forecasting / demand planning** — `Demo` still contains orphaned
  `salesByHour`, `salesByWeekday`, `categorySales`, `periodComparison` functions. Restore the basic
  ones as reports (cheap) long before building anything predictive.
- **D-5 · Multi-currency accounting** (as opposed to display conversion) — only if Abaad sells
  outside Oman.
- **D-6 · Franchise / multi-company consolidation.**
- **D-7 · Self-service merchant signup** — while onboarding requires a nine-screen setup and the
  operator creates accounts manually, self-signup would produce abandoned tenants.
- **D-8 · AI features of any kind** — nothing here is a customer problem yet.
