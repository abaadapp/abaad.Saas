# 01 — System Overview

> Audit date: 2026-08-25 · Branch `main` @ `de751d4` · Discovery only, nothing modified.

---

## 1. What Abaad actually is

Abaad is a **multi-tenant retail management SaaS** for Omani shops, sold by a platform operator
(super-admin) to merchants (businesses) on subscription plans. It bundles four products in one
codebase:

| Product | Reality |
|---|---|
| **POS terminal** (`/pos`) | Real, mature, offline-capable. The strongest part of the system. |
| **Merchant back-office** (`/admin`) | Broad: catalogue, inventory, customers, purchasing, finance, payroll, marketing, reports. Depth varies enormously by module. |
| **Platform console** (`/super-admin`) | Businesses, plans, subscriptions, invoices, impersonation, demo stores. Solid. |
| **Accounting sub-system** (`/admin/finance/*`) | Real double-entry ledger, chart of accounts, fixed assets, payroll. **Structurally disconnected from sales.** See §5. |

---

## 2. Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.4 · Laravel 13.8 |
| Frontend | React 19 · Inertia.js 3 · TypeScript 7 · Tailwind CSS v4 |
| UI kit | Radix primitives + custom `Components/ui` (10 primitives) |
| Charts | Hand-rolled SVG (`Components/charts`) — no chart library |
| Motion | framer-motion 12 |
| PDF | mpdf 8.3 + mpdf/qrcode (ZATCA-style TLV QR) |
| Spreadsheets | phpoffice/phpspreadsheet 5.9 |
| Routing helper | Ziggy 2.6 |
| DB (dev) | SQLite · **PostgreSQL is the stated production target** (driver-specific SQL exists in `PosController::nextNumber`) |
| Queue / cache / session | `database` driver |
| Mail | log driver in dev; SMTP in production |

## 3. Scale

| Metric | Count |
|---|---|
| Registered routes | **302** |
| PHP application code | ~27,000 lines / 182 files |
| TypeScript / TSX | ~30,800 lines / 146 files |
| Test code | ~20,600 lines / 97 feature tests + 2 unit |
| **Test suite** | **1,120 tests · 3,602 assertions · all passing (68s)** |
| Migrations | 73 |
| Eloquent models | 48 |
| Controllers | 68 |
| DB tables (tenant-scoped) | ~40 |
| Arabic→English translation keys | 3,090 |
| Inertia pages | 93 |

---

## 4. Architecture map

```
routes/web.php (610 lines, 302 routes)
  ├── auth            → LoginController (password + 4-digit PIN + device cookie)
  ├── /pos            → Pos\*            [RequiresBusiness, BindPosBranch, CheckTenantStatus]
  ├── /admin          → Admin\*          [RequiresBusiness, EntersPanel, CheckAbility, CheckTenantStatus]
  └── /super-admin    → SuperAdmin\*     [CheckRole:super_admin]

app/Http/Middleware/
  CheckTenantStatus   ─ maintenance mode, suspended user, disabled business, expired subscription
  RequiresBusiness    ─ blocks business-less users (super-admin) out of tenant screens
  EntersPanel         ─ role/permission gate for the back-office shell
  CheckAbility        ─ per-section permission derived from the route name
  BindPosBranch       ─ pins the POS session to the register's branch
  NormalizeMoneyInput ─ Arabic-Indic digits, ٫ ، , . thousands separators → canonical decimal
  SetLocale           ─ ar/en, RTL by default

app/Support/  ← the real domain layer (39 classes)
  Demo.php (2,750 lines)   ── THE read model for nearly every screen
  DemoStore.php (943)      ── demo-data generator
  Ledger, Vat, Stock, Shifts, OrderCorrection, PosCashier, PosTerminal,
  Permissions, Roles, Tenancy, Billing, PlanLimits, ReceiptTemplate,
  ReceiptVisibility, EInvoice, BackupService, AlertMetrics, …
```

### Notable architectural facts

**a. `App\Support\Demo` is the production read model, not demo code.**
Despite its name, this 2,750-line class is the query layer behind almost every merchant screen —
`Demo::orders()`, `Demo::inventory()`, `Demo::adminStats()`, `Demo::receipts()`. The name is a
historical accident (the app began as a demo shell). It is tenant-safe (`Demo::bid()` returns `0`
rather than guessing a business — a deliberate, well-documented fix), but the naming is a
maintainability hazard and ~400 lines of it are now dead (see `09-overengineering-review.md`).

**b. Two parallel financial systems that never meet.**

```
  Sales / Expenses  →  transactions table   →  "المالية" screens, dashboards, reports
  Purchases /       →  journal_entries      →  "الحسابات" screens (chart, journal,
  Payroll /            + journal_lines          trial balance, fixed assets)
  Fixed assets /
  Stock adjustments
```
`Ledger::post()` is called from **8 places — none of them is a sale, a cash receipt, or an
operating expense** (verified: `SupplierInvoiceController`, `FixedAssetController`,
`PayrollRunController`, `PayrollPaymentController`, `StockAdjustmentController`,
`JournalController`, `BankAccountController`, `DemoStore`). The demo-data generator *does* post
sales and COGS journals — so **the demo store shows a complete, balanced set of books that a real
merchant's account can never produce.** This is the single most consequential finding in the audit.

**c. Two sources of truth for stock.**
`products.quantity` (company total) and `branch_stocks.quantity` (per branch). `Support\Stock`
mediates reads correctly and thoughtfully, but **six different code paths write stock**
(POS checkout, PO receive, inventory movement, stock adjustment, stocktake, unbound delivery note)
and they do not all apply the same rules.

**d. Excellent commit discipline and self-documentation.**
Almost every non-obvious decision carries a multi-paragraph Arabic comment explaining the bug it
fixes and why the alternative was rejected. The recurring theme of these comments — *"a knob that
reassures and does nothing is worse than no knob"* — is the correct instinct, and it is applied
rigorously in some modules and not at all in others (see Marketing, §07).

---

## 5. Tenancy & security model

- **Isolation:** every tenant table carries `business_id`; every controller scopes on it. There is a
  dedicated `TenantIsolationTest`, `PosTenantIsolationTest`, `DemoIsolationTest`.
- **Roles (8):** `super_admin`, `admin` (owner), `manager`, `accountant`, `inventory`, `sales`,
  `delivery`, `cashier` — single source in `Support\Roles`.
- **Permissions:** **section-level only** (14 sections). A user either has `orders` or does not.
  There is no action-level grain (view / create / edit / delete / discount-override / void).
  Per-user manual overrides exist (`users.permissions` JSON).
- **Unknown role → zero abilities** (deliberately fail-closed).
- **Branch scoping:** `branch_user` pivot + `User::worksAt()`; POS registers are bound to a branch by
  a signed cookie token (`pos_devices.token_hash`).
- **Tenant lifecycle:** suspended user / disabled business → hard logout. Expired subscription →
  soft hold on a dedicated page with a configurable grace period (`grace_days`).
- **Cashier data minimisation:** `ReceiptVisibility` strips money fields from the *payload*, not just
  the UI — a genuinely well-thought-out control.

## 6. Background processes

| Schedule | Command | Purpose |
|---|---|---|
| 00:10 daily | `subscriptions:expire` | Flip lapsed subscriptions |
| 02:00 daily | `backup:run` | JSON backup per store |
| 02:30 daily | `trash:purge` | Hard-delete expired soft-deletes |
| 07:30 daily | `subscriptions:notify` | Renewal warnings |
| 08:00 daily | `alerts:low-stock` | Low-stock email |
| 08:30 daily | `alerts:smart` | Sales-drop / dormant-customer / dead-stock email |
| 23:55 daily | `report:daily-summary` | Owner's daily digest |
| Monthly 1st 07:00 | `reports:email` | Monthly performance report |
| **Hourly** | `shifts:auto-close` | Close abandoned shifts without a count |

All use `withoutOverlapping()`. No queue workers are required for these (all synchronous commands);
`QUEUE_CONNECTION=database` is configured but only mail is queued.

---

## 7. Overall character of the system

Abaad is **not** an unfinished prototype. It is a carefully engineered, well-tested, thoughtfully
localised application with unusually high internal quality in the paths that were finished. Its
problems are **not code quality** — they are **product completeness and coherence**:

1. Three retail primitives are entirely absent: **sales returns, invoice void, and branch transfer**.
2. The accounting module is real but **unwired from the operational side**.
3. Several modules are **screens with no engine behind them** (WhatsApp, SEO, website).
4. Several concepts are **implemented twice with different rules** (stock adjustment, invoice
   correction vs. return, `transactions` vs. `journal_entries`, `orders.branch` vs `branch_id`).

The rest of this audit is organised around those four themes.
