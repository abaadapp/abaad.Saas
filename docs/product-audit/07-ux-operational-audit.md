# 07 — UX & Operational Audit

Judged against the standard **Fast + Clear + Safe + Commercially Practical**, from six operator
seats. Visual design is deliberately out of scope — it is strong, consistent, documented in
`design-system/`, RTL-native, and not the problem.

---

## What is already right (worth protecting)

- **RTL-first with full LTR support**, 3,090 translation keys, coverage enforced by a test. Arabic is
  not an afterthought here; it is the primary language and it reads naturally.
- **Blind cash count.** The expected drawer total is withheld from the cashier at close — and
  withheld from the *payload*, not just the screen.
- **`ReceiptVisibility`.** Money is stripped server-side for staff without `finance`, including in
  search results. Most products get this wrong by hiding a column in CSS.
- **Undo-in-the-toast** for delete actions, instead of burying recovery in a Trash screen the user
  doesn't know exists.
- **Delete confirmations that state consequences** — "this branch holds 412 invoices and 2
  registers".
- **Filtered totals, not page totals**, on the orders list.
- **Import preview + column remap + batch undo.**
- **Arabic-Indic digit and separator normalisation** on every money field.
- **iOS 16px minimum font on inputs** to stop Safari zoom-lock.
- **`prefers-reduced-motion` honoured** across all animation.
- **`Reports::ALL` refuses to advertise a report that does not exist** — a rule most products break.

---

## Persona 1 — Business owner (`admin`)

**Job:** know whether the shop made money, whether stock is right, whether staff are honest.

| Issue | Impact |
|---|---|
| **No P&L.** The dashboard shows revenue, expenses and a computed profit from `transactions` + `expenses`; the accounting section shows a trial balance with no revenue in it. Two answers, neither complete. | High |
| **No shift review screen.** Cannot see yesterday's variances or a per-cashier variance trend — the entire point of running shifts. | High |
| **No VAT return.** Collects VAT, cannot declare it. | High |
| **No returns view.** Cannot answer "what came back this month and why". | High |
| **No onboarding checklist.** Lands on an empty dashboard with 14 sections. | High |
| **No supplier balance.** "What do I owe Al-Nahda?" needs a different screen and manual reading. | Medium |
| **Marketing screens promise things that don't happen** (WhatsApp, SEO). Discovering one dead toggle makes every other setting suspect. | High |
| Reports index is honest but thin; six report *data functions* exist with no screens. | Medium |
| Dashboard has no period comparison (`Demo::periodComparison()` exists, orphaned). | Low |

## Persona 2 — Store manager (`manager`)

**Job:** keep the shelves right, cover the floor, fix what the staff got wrong.

| Issue | Impact |
|---|---|
| **No branch transfer.** The single most common multi-branch task requires two unlinked manual movements. | High |
| **Two adjustment screens with different rules**, adjacent in the menu, with no guidance on which to use. | High |
| **No partial receiving.** Must record a delivery that didn't fully arrive. | High |
| **Cannot void a wrong invoice.** Must edit it down line by line, and cannot remove the last line. | High |
| No stock valuation report, no slow-mover report, no stock movement report. | Medium |
| Reorder suggestions exist but name no supplier and cannot create a PO in one click. | Medium |
| No low-stock action from the alert email — it reports, it does not link into a purchase. | Medium |
| Product delete is offered with no stock check. | Medium |

## Persona 3 — Cashier

**Job:** serve the queue fast, don't get blamed for the drawer.

| Issue | Impact |
|---|---|
| **Cannot give a discount.** No manual discount field exists at all. Shops will improvise with fake coupons or after-the-fact invoice edits. | High |
| **Cannot process a return** — the most common counter interaction after a sale. | High |
| **Cannot split a payment** ("30 card, 20 cash"). | High |
| Held carts don't reserve stock and give no signal that a held quantity exists. | Medium |
| Barcode field is focused only when a scanner peripheral is registered — a shop that plugs in a scanner without registering it loses auto-focus. | Medium |
| Drawer cash-out is recorded but never becomes an expense, so the cashier is later asked "where did the 5 rials go?" and the reason they typed is not visible to the person asking (no admin shift screen). | Medium |
| No customer-facing display, no drawer-kick trigger visible despite peripherals being registered. | Low |
| Product grid has no favourites / quick keys for high-frequency items. | Low |

**What works well for this persona:** offline queue, idempotent replay, live stock feed, blind
count, PIN login, screen lock, cashier attribution independent of the login. These are the details
that separate a real POS from a web form, and they are all present.

## Persona 4 — Inventory employee

| Issue | Impact |
|---|---|
| **Stocktake will time out on any real catalogue** (O(n²)) and is not atomic. | Critical |
| The count screen sends every product for every branch in one payload — correct for branch switching, but unusable at 3,000+ SKUs with no pagination, no category filter, no "count by section", no partial save. | High |
| No count sheet to print, no barcode-driven counting, no variance approval step. | High |
| **No transfer.** | High |
| `inventory_movements.quantity` is a `+`/`-` string, so no screen can total it. | Medium |
| Movement reasons are free text on one screen and a fixed list on the other. | Medium |

## Persona 5 — Purchasing employee

| Issue | Impact |
|---|---|
| **No partial receiving.** | High |
| PO has no draft, no approval, no expected date, no cancel — three statuses in the filter that can never occur. | High |
| Supplier invoice totals are typed by hand rather than derived from received lines; nothing reconciles the two. | High |
| No supplier statement, no ageing, no payment schedule / "due this week". | High |
| Reorder suggestions do not group by supplier and cannot become a PO. | Medium |
| No landed cost (freight, customs) — cost is the PO line cost only. | Medium |

## Persona 6 — Accountant / finance

| Issue | Impact |
|---|---|
| **Sales, COGS, expenses and cash receipts never post to the ledger.** The trial balance is technically balanced and materially empty. | Critical |
| **No P&L, no balance sheet** — only a trial balance. | Critical |
| **No VAT return.** | Critical |
| Deleting a supplier invoice **erases** posted journal entries rather than reversing them. | High |
| Two disconnected financial worlds (`transactions` vs `journal_entries`) with no reconciliation between them and no explanation in the UI of which is which. | High |
| Sidebar calls one section "المالية" and another sits inside Settings/Finance as "الحسابات" — the distinction is invisible to a user. | High |
| Expenses never reach the ledger, so the accountant must re-key them. | High |
| No period close / lock. | High |
| Bank reconciliation is genuinely good, but reconciles against `transactions`, not the ledger. | Medium |

---

## Cross-cutting UX findings

### Terminology

| Current | Problem | Suggested |
|---|---|---|
| «مصاريف شهرية» (monthly expenses) | Expenses are not monthly and there is no recurrence feature | «المصروفات» |
| «المالية» vs «الحسابات» | Two finance sections, indistinguishable to a merchant | «الصندوق والحركة المالية» vs «الدفاتر المحاسبية» |
| «تعديل فاتورة» used as the return mechanism | Hides a return behind the word "edit" | Introduce «مرتجع» / «إشعار دائن» |
| «مرتجع» as an inventory-movement type | Overloads the word for a stock addition that has nothing to do with a customer return | «إدخال مرتجع مورّد» or remove |
| `shifts.returns` column | Populated from cash-in drawer movements | Rename or repurpose |
| PO statuses «مسودة» / «مستلم جزئيًا» / «ملغي» in the filter | None can occur | Remove or implement |
| Order statuses «قيد التجهيز» / «جاهز» / «خرج للتوصيل» | None can be set | Remove or implement (F-17) |
| «الفرع الرئيسي» as a literal default string on orders | Not a real branch reference | Always use `branch_id` |

### Navigation

- 14 sidebar sections with nested children is a lot for a shop with three employees. Marketing alone
  has five children, three of which do nothing.
- **Settings is a container for unrelated things**: business profile, VAT, currency, receipt
  templates, branches, employees, POS devices, custom alerts, chart of accounts, activity log, trash,
  backup. Branches and Employees in particular are daily-operations screens buried in Settings, and
  their permission aliases (`branches → settings`, `payroll → employees`, `devices → settings`) have
  already had to be patched to make permissions work.
- The report index and the sidebar are two different paths to the same screens.

### Missing actions

- Cancel/void an order · process a return · transfer stock · partially receive a PO · give a manual
  discount · split a payment · reprint a Z-report · convert a reorder suggestion to a PO · create a
  supplier invoice from a receipt · move an order through fulfilment statuses.

### Missing information

- Margin on the product list · supplier balance on the supplier list · held-stock indicator on POS
  tiles · "who last edited this invoice" on the order list (it's on the detail page) · stock on hand
  on the PO create screen · expected vs counted running total on the stocktake screen.

### Missing filters / search

- Products: no filter by stock status, category, or "below cost".
- Inventory: no filter by branch (branch comes from the header switcher only) or by "low stock only".
- Movements: no filters at all.
- Expenses: no type filter alongside date.
- Suppliers: no search on the index (the screen renders a plain list from `Demo::suppliers()`).
- Journal: no filter by account or source type.

### Bulk actions

The shared `DataTable` has **no row selection**. Bulk exists only for products
(`ProductController::bulk`) through a separate UI. Missing bulk: price update by percentage,
category reassignment, activate/deactivate, customer tagging, expense categorisation, mark POs
received.

### Confirmation & safety

Good: branch delete states its contents; product/expense delete offers undo; supplier invoice with
payments cannot be deleted; delivered notes cannot be cancelled; shift over-withdrawal blocked; last
invoice line cannot be removed.

Missing: **Restore** (the most destructive action in the product) has no consequence statement and
no dry run. Product delete with stock. PO delete after receipt. Journal-affecting deletions.

### History / audit

`activity_logs` is comprehensive and impersonation-aware; `order_edits` records invoice corrections
with mandatory reasons. Missing: no per-record history view (to see a product's price history you
must read the global activity log); no stock ledger per product (the movements table exists but has
no per-product drill-down screen); no "who changed this setting" surfaced next to the setting.

### Actions that should be automated

- Sales → ledger posting (F-04).
- Reorder suggestion → draft PO.
- Low-stock email → link that opens a pre-filled PO.
- Supplier invoice → pre-filled from the goods received.
- Recurring expenses (rent, utilities, salaries).
- Shrinkage → one account, one document, regardless of screen.

---

## Priority summary for this document

**P0 (UX-level, launch-blocking):** no return path for a cashier · no void · no manual discount ·
stocktake unusable at scale · dead marketing toggles · no VAT return · no shift review.

**P1:** onboarding checklist · branch transfer · partial receiving · supplier balances · merge the
two adjustment screens · terminology cleanup · restore safety.

**P2:** bulk actions · missing filters · per-record history · margin visibility · split payments.
