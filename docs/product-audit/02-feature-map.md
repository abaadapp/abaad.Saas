# 02 — Abaad Feature Map

Status vocabulary: **Complete · Mostly Complete · Partially Complete · UI Only · Backend Only ·
Broken · Missing Logic · Needs Review · Potentially Unnecessary**

---

## A. POS / Point of Sale

**Existing features**
- Product grid with category filter, search, barcode scan field (auto-focus when a scanner is
  declared as a peripheral)
- Cart with per-line qty, note, per-line VAT rate
- Customer attach (search / quick-create with phone uniqueness)
- Coupon apply (server re-validates at checkout — client value is never trusted)
- Loyalty points earn + redeem (redeem capped by `%` of invoice and a minimum balance)
- Hold (`HOLD-`) and Save (`SAVE-`) carts, resume, discard
- Payment dialog: cash / card / transfer (only methods the merchant enabled), change due
- **Offline outbox**: `localStorage` queue + `client_uuid` idempotency; flushes on reconnect
- Live stock feed (polls quantities only, every few seconds)
- Receipt reprint, receipt search across full history
- Cashier selection (who the sale is attributed to, independent of the login)
- Register (device) activation via signed token cookie, peripherals registry
- Shift open / cash in-out / close with blind count
- Screen lock, POS-local settings, language & currency switch

**Roles** — `cashier` (POS only), plus any role granted the `pos` section. `admin`/`manager` always.

**Main actions** — sell, hold, resume, apply coupon, redeem points, add customer, reprint, open/close
shift, record drawer movement, lock.

**Dependencies** — Products, Branch stock, Customers, Coupons, Loyalty settings, VAT settings,
Shifts, POS devices, Transactions.

**Data flow** — `checkout()` writes, in one DB transaction: `orders` → `order_items` (with a **cost
snapshot**) → `products.quantity` − → `branch_stocks` − → `inventory_movements` (type `بيع`) →
`transactions` (type `دخل`) → `point_transactions` + `customers.points` → deletes the resumed hold.
Email notification fires **outside** the transaction.

**Status: Mostly Complete.** The best-engineered module in the system. Gaps: no return, no void, no
split payment, no partial payment, integer quantities only, no ledger posting.

---

## B. Sales / Orders (back-office)

**Existing** — order list with server-side sort/filter (search, payment method, status, date range),
filtered totals (not page totals), cancelled count shown separately; order detail with line items,
edit history; PDF receipt; PDF tax invoice; XLSX/PDF export.

**Roles** — `admin`, `manager`, `accountant`, `sales`, `delivery`.

**Main actions** — view, filter, export, print receipt, print tax invoice. **Edit line quantity** and
**correct payment method** happen from the POS order-detail screen (`Pos\OrderEditController`), not
from the back-office.

**Dependencies** — Orders, Order items, Transactions, Order edits, Receipt template, VAT, EInvoice.

**Data flow** — read-only except via `Support\OrderCorrection`, which is the single write door and
correctly re-does stock, movement, transaction, loyalty, and totals.

**Status: Partially Complete.** No cancel/void action exists anywhere despite `Order::CANCELLED`
being defined and offered as a filter. No return. No credit note. Editing a *completed fiscal
document* is the only correction mechanism.

---

## C. Products / Catalogue

**Existing** — CRUD, soft delete + trash restore, duplicate, quick inline edit (price/qty/status),
bulk actions, image upload or external URL, category tree (`parent_id` exists), SKU, barcode,
cost, price, per-product discount %, per-product VAT rate override, reorder alert level,
Arabic + English name (auto-transliteration), Excel/CSV import with column auto-mapping + preview +
remap + **undo batch**, PDF/XLSX export, product profitability data (orphaned), stock feed.

**Roles** — `admin`, `manager`, `inventory`, `sales` (read).

**Dependencies** — Categories, Branch stock, Inventory movements, Order items.

**Status: Mostly Complete** for a simple shop. **Missing Logic** for anything else:
- **No units of measure** (kg / litre / box / piece) — `order_items.quantity` is an `integer`, so
  weight-priced goods cannot be sold at all.
- **No product variants** (size / colour / flavour) — a boutique must create N products.
- **No barcode uniqueness constraint** — duplicate barcodes are accepted silently.
- **No price tiers / wholesale price / price by branch.**
- **No supplier link on the product** — reorder suggestions cannot name a supplier.
- **No product bundles/kits.**
- Category `parent_id` exists in the schema but no nesting UI.

---

## D. Inventory

**Existing (five distinct screens)**
| Screen | Route | What it writes |
|---|---|---|
| Stock list | `admin.inventory.index` | read |
| Manual movement | `admin.inventory.store` | `branch_stocks`, `products.quantity`, `inventory_movements` |
| Stock adjustments | `admin.inventory.adjustments` | `stock_adjustments` + `inventory_movements` + **journal entry** |
| Stocktake (count) | `admin.inventory.stocktake` | branch book delta + movement + **an `Expense` row for shrinkage** |
| Delivery notes | `admin.inventory.deliveries` | movement **only when the note is not linked to an order** |

**Roles** — `admin`, `manager`, `inventory`.

**Status: Partially Complete / Needs Review.**
- **No branch-to-branch transfer exists at all** — verified by exhaustive search. In a multi-branch
  business this is a launch blocker.
- **Manual movement and Stock adjustment are the same feature built twice** with different rules:
  one posts to the ledger and one does not; one guards the branch balance and the other guards the
  company total; one records a reason from a fixed list and the other takes free text.
- Stocktake shrinkage is booked as an **`Expense`**, while stock-adjustment shrinkage is booked as a
  **journal entry to `other_expenses`**. The same economic event lands in two different places
  depending on which screen the user opened.
- Stocktake overage silently increases inventory value with **no counterpart entry at all**.

---

## E. Purchasing

**Existing** — Suppliers CRUD + import/export; Purchase orders (create with lines, attach payment
receipt image, receive, delete); **Purchase register** (`admin.purchases.index`); **Supplier
invoices** (record a supplier bill, pay in full or part, ledger-posted, duplicate-ref guard,
overdue tracking); reorder suggestions.

**Roles** — `admin`, `manager`, `inventory`.

**Data flow** — PO receive → `products.quantity` +, `branch_stocks` +, **weighted-average cost
update**, `inventory_movements`. **No financial effect.** Separately, a Supplier Invoice → journal
`Dr Inventory / Cr Payable`. Payment → `Dr Payable / Cr Cash|Bank`.

**Status: Partially Complete.**
- **No partial receiving** — `received_quantity` exists in the schema but `receive()` always sets it
  to the full ordered quantity and flips status to `مستلم`. A short delivery cannot be recorded.
- **PO number is `'PO-' . random_int(10000,99999)` with no unique index** — ~50% chance of a
  duplicate PO number by the 350th order.
- **`receive()` is not wrapped in a DB transaction and does not lock rows.**
- Supplier invoice totals are **typed by hand**, not derived from received lines — so the
  ledger's inventory value and the physical stock are independent numbers that will drift.
- **Suppliers screen shows no balance owed.** `Demo::suppliers()` returns only name/phone/email/
  contact/notes/PO count. To know what you owe a supplier you must open a different screen.
- PO statuses `مسودة`, `مستلم جزئيًا`, `ملغي` appear in the UI filter but **no code path ever sets
  them**.
- Deleting a received PO does not reverse the stock it added.

---

## F. Customers

**Existing** — CRUD, soft delete + restore, multiple addresses with a default, notes, tax number,
Arabic/English name, phone uniqueness (loyalty follows the phone), loyalty points + manual redeem,
point transaction history, customer profile with order history, PDF statement, import/export,
per-branch assignment, dormant-customer alerts, top-spender report.

**Roles** — `admin`, `manager`, `accountant`, `sales`.

**Status: Mostly Complete** *for cash retail*. **Missing** — customer credit / receivables. Credit
sales existed and were **deliberately removed** in migration
`2026_08_12_150000_drop_credit_sales.php` at the owner's request. See `12-owner-decisions.md`.

---

## G. Finance ("المالية")

**Existing** — Transaction ledger (income/expense rows, filterable by range), payment-method
breakdown, finance stats, manual transaction entry (an expense entry also writes an `Expense` row so
the two screens agree), bank accounts (multi, primary flag, opening balance), **bank statement
import + auto-match + re-match + reconciliation summary**, finance PDF/XLSX export.

**Roles** — `admin`, `manager`, `accountant`.

**Status: Mostly Complete as a cash book.** It is *not* accounting — it is a categorised cash
journal. Fine, provided the merchant is not told otherwise.

---

## H. Accounting ("الحسابات" — Chart / Journal / Assets / Payroll)

**Existing** — Chart of accounts (5 roots, 21 system accounts, editable, contra-account aware,
system-account back-fill for stores created before an account was added), manual journal entry with
balance enforcement, trial balance (with as-of date), fixed assets (straight-line depreciation, run
depreciation, dispose with gain/loss posting), payroll runs (draft → approve → pay, per-employee
lines, ledger-posted).

**Roles** — `admin`, `manager`, `accountant`.

**Status: Backend Only / structurally incomplete.** The engine is genuinely good
(`LedgerTest` covers unbalanced entries, contra accounts, parent-account posting, closed accounts,
dated trial balance). But:
- **Sales never post.** Revenue is permanently zero.
- **COGS never posts.** Inventory is debited by purchases and never relieved — it grows forever.
- **Operating expenses never post.**
- **Cash and bank receipts from sales never post.**
- There is **no P&L, no balance sheet, no income statement screen** — only a trial balance.
- Deleting a supplier invoice **deletes its posted journal entries** rather than reversing them.

A merchant who opens `/admin/finance/chart` after a month of trading will see purchases and salaries
and nothing else. The trial balance will balance and will be meaningless.

---

## I. Expenses

**Existing** — CRUD with soft delete + restore + purge, expense types (per-tenant, unique name),
attachment upload, reference number, due date, paid/unpaid status (**only paid counts as money
out** — well handled), link to a `transactions` row, PDF/XLSX export.

**Roles** — `admin`, `manager`, `accountant`.

**Status: Complete** within its own model. Does not reach the double-entry ledger.

---

## J. Employees & Payroll

**Existing** — Employee CRUD, job titles (per-tenant, mapped to a role), role assignment, **manual
per-section permission override**, branch assignment (multi), 4-digit PIN login (tenant-scoped
uniqueness, strength rule), password reset by owner, activate/suspend, basic salary + allowances,
monthly target + commission rate columns, employee performance leaderboard, payroll run and payment.

**Roles** — `admin`, `manager`; `accountant` can read.

**Status: Mostly Complete.** Gaps: `monthly_target` and `commission_rate` are captured and shown but
**no commission is ever calculated or paid**; no attendance/hours; overtime is a manual number typed
into a payroll line.

---

## K. Shifts / Cash drawer

**Existing** — open with float, sales attributed to the shift, cash in/out with mandatory reason and
an over-withdrawal guard, **blind close** (expected total hidden from anyone without `finance`),
frozen totals at close, automatic hourly close of abandoned shifts with `actual_balance` left NULL
(deliberately — writing 0 would falsely certify a match).

**Roles** — POS users; amounts visible only with `finance`.

**Status: Complete and unusually well-designed — but half-delivered.**
- **There is no back-office shift screen.** `Permissions::sectionFromRoute()` explicitly handles
  `admin.shifts.*` routes that **do not exist**. The owner cannot review yesterday's variances,
  cannot see a Z-report, cannot list shifts by cashier.
- `require_open_shift` (block selling without an open shift) is **read by the code but has no UI
  knob and was force-set to `'0'` by migration `2026_08_22_120000`** — the feature is unreachable.
- Drawer cash-out does not create an expense or transaction, so petty cash leaves the drawer and
  never appears in finance.

---

## L. Marketing

| Screen | Reality |
|---|---|
| Loyalty | **Real.** Settings drive POS earn/redeem; members, points, history shown. |
| Coupons | **Real.** Percent/fixed, min order, max uses, expiry, active toggle, server-validated at checkout, cost tracked separately in `orders.coupon_discount`. |
| Reviews | **Backend Only.** Admin manually types a review, sets status, replies. Nothing collects reviews from customers; nothing publishes them. |
| Website | **UI Only.** Saves `site_*` settings. Nothing in this application renders a storefront. |
| SEO | **UI Only.** `seo_title`, `seo_description`, `seo_keywords`, `seo_index`, `seo_ga_id` are saved and **read by nothing** (verified by exhaustive search). |
| WhatsApp | **UI Only.** `wa_enabled`, `wa_number`, three message templates, three trigger toggles — **no sending code, no API client, no queue job exists.** |

**Status: Partially Complete / UI Only.** Three of six screens are knobs that do nothing. This is
precisely the failure mode the codebase's own comments repeatedly identify as the worst kind.

---

## M. Reports

**Existing** — Report index driven by `Support\Reports::ALL` (14 entries, each either a real route
or a real data key, permission-filtered per user); Sales summary page; a JSON report viewer for
payments / employees / customers; PDF + XLSX exports for orders, products, inventory, expenses,
finance, businesses, invoices, suppliers, customers.

**Status: Partially Complete.** The index is honest — it deliberately refuses to advertise reports
that do not exist, and a comment records that six reports were removed with their screens:
profitability, **VAT return**, shift close (Z), inventory movements, advanced analytics, and sales by
category. Their data functions still sit unused in `Demo.php`.

**The absence of a VAT report is a launch blocker for any VAT-registered merchant** — Abaad
*collects* VAT on every invoice and gives the merchant no way to declare it.

---

## N. Settings

**Existing** — Business profile, logo, VAT (enabled / rate / number / inclusive-exclusive), currency
(code, decimals, symbol position, multi-currency table with rates), payment methods, invoice
numbering prefix + start, paper size, notification toggles, **invoice template (11 flags governing
three paper formats)**, branches, employees, POS devices, custom alerts, chart of accounts entry,
activity log, trash, backup/restore.

**Status: Mostly Complete.** The settings write path is a closed whitelist (`SettingController::KEYS`
= 37 keys) with per-key validation — a deliberate fix for silently-dropped settings. Consequence:
`allow_negative_stock`, `require_open_shift`, `shift_max_hours` and `dormant_customer_days` are read
by code but **cannot be set from anywhere**.

---

## O. Platform / Super-admin

**Existing** — Business CRUD, plan CRUD, subscriptions, invoices + PDF, manual payment recording,
renewals with correct proration-from-expiry, plan limits enforced at creation (branches / employees /
products), impersonation with audit trail, platform users, platform settings (grace days, auto
suspend, maintenance mode, VAT defaults, email test), demo-store creation/reseed/delete with a hard
guard preventing demo data from ever entering a real tenant, platform reports & exports, activity log.

**Status: Mostly Complete.** Payment collection is manual (bank transfer reviewed by the operator) —
documented and intentional; no payment gateway integration.

---

## P. Cross-cutting

| Feature | Status |
|---|---|
| Activity log (who did what) | **Complete** — logged from ~60 call sites, impersonator recorded |
| Trash / soft delete + restore + purge | **Complete** for products, expenses, branches, customers, users |
| Backup (JSON download + nightly) | **Broken** — see `06-data-integrity-risks.md` R-01 |
| Restore | **Broken / dangerous** — R-01 |
| Notifications feed + dismiss | Complete |
| Custom alerts (metric/operator/threshold) | Complete |
| Smart alerts email | Complete |
| Search (unified, permission-filtered) | Complete |
| i18n ar/en + RTL/LTR | **Complete** — 3,090 keys, coverage enforced by `TranslationCoverageTest` |
| Multi-currency display | Partially Complete — display conversion only; no historical rate on transactions |
| E-invoice QR (TLV/Base64) | Complete, correctly byte-guarded, suppressed when no VAT number |
| Health check endpoint | Complete |
| Preflight command | Complete |

---

## Summary table

| Module | Status |
|---|---|
| POS | Mostly Complete |
| Sales / Orders | Partially Complete |
| **Returns / Refunds** | **Missing entirely** |
| **Order void / cancel** | **Missing entirely** |
| Products | Mostly Complete (simple retail only) |
| Product variants / units | Missing entirely |
| Categories | Mostly Complete |
| Inventory — count / adjust | Partially Complete (duplicated) |
| **Inventory — branch transfer** | **Missing entirely** |
| Delivery notes | Complete |
| Purchasing — PO | Partially Complete |
| Purchasing — receiving | Partially Complete (no partial receipt) |
| Supplier invoices & payables | Mostly Complete |
| Supplier balances (visibility) | Missing Logic |
| Customers | Mostly Complete |
| Customer credit / A-R | Removed by owner decision |
| Loyalty | Complete |
| Coupons | Complete |
| Reviews | Backend Only |
| Website / SEO / WhatsApp | **UI Only** |
| Finance (cash book) | Mostly Complete |
| Accounting (double-entry) | Backend Only / unwired |
| **VAT return** | **Missing (removed)** |
| Expenses | Complete |
| Employees | Mostly Complete |
| Payroll | Mostly Complete |
| Commissions | UI Only |
| Shifts (POS) | Complete |
| **Shifts (back-office review)** | **Missing entirely** |
| Reports | Partially Complete |
| Settings | Mostly Complete |
| Platform / billing | Mostly Complete |
| Backup / restore | **Broken** |
| Permissions | Partially Complete (section-level only) |
