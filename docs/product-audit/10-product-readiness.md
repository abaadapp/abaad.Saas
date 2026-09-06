# 10 — Product Readiness Score

Scored as a **commercial retail SaaS sold to Omani shops**, not as a codebase. A high engineering
score does not lift a low completeness score, and vice versa.

---

## 1. Feature completeness — **58 / 100**

Broad coverage: POS, catalogue, inventory, customers, purchasing, suppliers, expenses, employees,
payroll, fixed assets, banking, loyalty, coupons, multi-branch, multi-currency, import/export, PDF
documents, platform billing. Very few products at this stage have this much surface.

Held down by three absolute holes and three dead screens:
- **No sales return.** No refund. No credit note.
- **No invoice void**, though the state exists and is filterable.
- **No branch transfer**, though multi-branch is a priced feature.
- WhatsApp, SEO and (largely) Website save settings nothing reads.
- No VAT return, no P&L, no shift review screen, no stock valuation report.
- No units of measure / fractional quantities → an entire retail category is excluded.

**58** reflects a product that does most things and cannot do three things that a shop does daily.

---

## 2. Business logic — **62 / 100**

Where logic was written, it is unusually careful:
- Weighted-average costing with an explicit rejection of last-cost, plus a **cost snapshot** at sale
  time so historical profit is stable.
- Per-line VAT with per-product rates, correct inclusive/exclusive handling, and invoice-level
  discount apportioned across lines **before** tax.
- Server-side re-pricing and coupon re-validation — client values never trusted.
- Branch-scoped availability with a documented "allocated vs. never allocated" rule.
- Loyalty adjusted by delta and never pushed negative.
- Shift variance frozen at close so later edits cannot restate history.

Held down by:
- **Sales, COGS, cash receipts and expenses never post to the ledger** — the largest logical gap in
  the system (F-04).
- Shrinkage has **three** different treatments depending on which screen was used.
- Stocktake overage increases assets with no counterpart.
- Purchase receiving cannot represent a partial delivery and has no financial effect.
- Supplier invoice totals are typed by hand rather than derived from what was received.
- Coupon `used_count` only increments.
- Discounts exist only as coupons — no manual discount at the counter.

---

## 3. Retail workflow quality — **55 / 100**

The **sell** workflow is excellent end to end. The **buy**, **return**, **move**, and **count**
workflows are incomplete, and two of them do not exist.

- Sell: ✅ fast, offline-safe, idempotent, branch-correct.
- Buy: ⚠️ PO → receive (all-or-nothing, no financial effect) → separate hand-keyed invoice.
- Return: ❌ absent; substituted by editing a fiscal document.
- Move: ❌ absent.
- Count: ⚠️ present but not atomic and unusable above ~500 SKUs.
- Fulfilment: ❌ statuses, a delivery role, and notification templates exist with no transitions.

---

## 4. UX — **68 / 100**

Genuinely strong: RTL-native, consistent design system, blind cash count, payload-level data
minimisation, undo-in-toast, consequence-stating confirmations, filtered totals, import preview with
undo, Arabic-digit normalisation, reduced-motion support, and a report index that refuses to
advertise what does not exist.

Held down by: no onboarding path; 14 sections for a three-person shop; Settings as a catch-all
containing daily-operations screens; terminology that misleads (monthly expenses, edit-as-return,
two finance sections); no bulk actions in the shared table; missing filters on several lists;
missing actions the operator expects to find (void, return, transfer, discount); and — most costly
of all — **toggles that do nothing**, which teach the merchant to distrust the toggles that work.

---

## 5. Data integrity — **52 / 100**

The hot path is protected properly: checkout is a single transaction with ordered `lockForUpdate`,
branch-scoped stock assertion inside the transaction, unique invoice numbering with retry, and
`client_uuid` idempotency for offline replay. `OrderCorrection` and `Shifts::open` are equally
careful. This is better than most SME systems.

Held down by serious, specific defects:
- **Restore silently corrupts the tenant** (R-01) — 17 of ~40 tables, cascades registers and branch
  stock away, fails outright with supplier invoices, reports success regardless.
- **PO receiving: no transaction, no locks** (R-05).
- **Stocktake: no transaction, O(n²)** (R-07).
- **PO numbers collide** (R-06) — the same bug that was correctly fixed twice elsewhere.
- Stock adjustments in "All branches" mode move the total and no branch (R-11); they guard the
  company total, not the branch (R-12).
- `products.quantity` is clamped at zero while `branch_stocks` is not, breaking the stated invariant
  (R-10).
- Delivery-note quantities are truncated to integers (R-13) and checked outside the transaction
  against the wrong scope (R-14).
- Deleting a supplier invoice **erases** posted journal entries (R-17).
- Products with stock can be deleted with no check (R-15); received POs can be deleted without
  reversal (R-16).
- A declared VAT period can be silently restated by an invoice edit (R-03).

---

## 6. Permissions & security — **72 / 100**

Strong foundations: fail-closed on unknown roles; a single source for role labels; centralised
route→section mapping with an alias table; tenant status enforced at the door with maintenance mode,
suspension and a grace period; `Demo::bid()` returns `0` rather than guessing a tenant — a
deliberate, documented fix for a silent cross-tenant read; PIN uniqueness scoped per tenant with a
documented rationale about the 10,000-code space; POS registers bound by hashed token; impersonation
fully audited; `ReceiptVisibility` strips money server-side; dedicated tenant-isolation tests.

Held down by: **section-level permissions only** — no action grain, so anyone with `products` can
reprice or delete the whole catalogue and anyone at the POS can edit a completed invoice; no
two-factor for the owner or the platform operator; no rate limiting visible on PIN login;
`ensureAllocated` missing a tenant scope (latent, R-21); no period lock.

---

## 7. Reporting — **45 / 100**

The report index is honest and permission-filtered — a real strength. The set behind it is thin for a
paid product, and the most valuable reports are the missing ones: **P&L, VAT return, profitability,
stock valuation, stock movement, shift/Z, returns analysis, sales by category, sales by hour**.
Six of these have working data functions sitting orphaned in `Demo.php`. Exports (PDF + XLSX) are
comprehensive and well built, which partly compensates by letting merchants build their own.

---

## 8. Scalability — **50 / 100**

Fine for a shop with hundreds of products and dozens of daily invoices; several paths break before a
shop with thousands.

- `BranchStock::books()` loads every product and branch-stock row; `bookOf()` calls it **per
  product inside loops** → O(n²) in stocktake and manual movements.
- `Demo::inventory()`, `Demo::products()`, `Demo::suppliers()`, `Demo::purchaseOrders()` and several
  others do `->get()` over whole tables with no pagination.
- `BackupService::payload()` builds every order with items into one in-memory JSON — the nightly job
  will exhaust memory on a busy tenant.
- POS `stockFeed()` returns every product on every poll (quantities only — a good decision, but still
  unbounded).
- `StockAdjustmentController::index` loads all adjustments twice (paginated + `->get()` for a summary).

Mitigating: server-side pagination/sort/filter *is* implemented in the important lists (orders,
customers, products, adjustments, delivery notes, supplier invoices), the DB indexes on hot lookups
are present, and PostgreSQL portability has been thought through in detail.

---

## 9. Maintainability — **82 / 100** — the highest score here, and deservedly

- **1,120 tests, 3,602 assertions, all passing in 68 seconds.** Coverage spans tenant isolation,
  checkout math, checkout security, ledger invariants, VAT switches, permissions, stock integrity,
  silent data loss, dead links, translation coverage, and export integrity.
- Domain logic lives in `Support/` classes with single write doors (`Ledger::post`,
  `OrderCorrection`, `Stock`, `Shifts`, `MarketingSettings`).
- Every non-obvious decision carries a comment explaining the bug it fixes and why the alternative
  was rejected. This is the single best thing about the codebase.
- Consistent conventions; a documented design system extracted into `design-system/`.

Held down by: `Demo.php` at 2,750 lines is a god-object read layer with a misleading name and ~400
lines of dead code; `ProductImportExportController` at 898 lines and `PosController` at 896;
duplicated stock-write logic across six paths; ~23 tenant tables absent from the backup manifest with
no test asserting completeness.

---

## 10. Commercial readiness — **48 / 100**

**Can be sold today to:** a single-branch shop selling discrete items for cash/card, that does not
accept returns, does not need a P&L, and files VAT from a spreadsheet.

**Cannot be sold today to:** anyone who takes returns (all retail), anyone selling by weight, any
multi-branch business, any VAT-registered merchant who expects to file from the system, any business
whose accountant will open the accounting module.

Platform side is genuinely ready: plans with enforced limits, subscriptions, invoicing, grace
periods, maintenance mode, impersonation, demo stores with hard guards, activity audit. Billing is
manual bank transfer — appropriate for the stage and clearly documented as such.

The gap is not polish. It is that a merchant will hit "the customer wants to return this shirt"
within the first week.

---

## Weighted summary

| Dimension | Score |
|---|---|
| Feature completeness | 58 |
| Business logic | 62 |
| Retail workflow quality | 55 |
| UX | 68 |
| Data integrity | 52 |
| Permissions & security | 72 |
| Reporting | 45 |
| Scalability | 50 |
| Maintainability | **82** |
| Commercial readiness | 48 |

# Overall Product Readiness: **59 / 100**

---

## What this number means

**59 is not a bad score for a pre-launch product — it is an honest one for a product that is
engineered above its completeness.** Abaad is roughly 80% of a very good product and 100% of a very
good codebase. The distance to a sellable v1 is unusually *short* precisely because the foundations
are sound: single write doors, a real test suite, correct transaction discipline in the hot path,
and a domain layer that already knows how to do the hard parts.

**What moves the number fastest:**

| Action | Est. effect |
|---|---|
| Returns + void (A-1, A-2) | +8 |
| Wire sales/COGS to the ledger, or hide the ledger (A-3) | +6 |
| Fix restore + PO transaction + stocktake atomicity/perf (A-6, A-7, R-05) | +6 |
| VAT return + P&L (A-4, B-1) | +5 |
| Remove the dead marketing screens (A-8) | +3 |
| Manual discount + action permissions (A-9, A-10) | +3 |
| Partial receiving (A-5) | +2 |
| Branch transfer (A-11) | +2 |

Delivering the Launch-Critical list in `08-missing-features.md` puts Abaad at roughly **80–85**,
which is a genuinely sellable commercial retail product.
