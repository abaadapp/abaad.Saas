# 11 — Launch Gap Analysis

Four lists. Every item cross-references its detail elsewhere in this audit.

---

# A. MUST FIX BEFORE LAUNCH
*Problems that will damage business operations or customer trust in the first weeks of real use.*

| # | Item | Why it damages the business | Ref |
|---|---|---|---|
| **A1** | **Sales returns & credit notes** | A cashier will face a return in week one and there is no path. Every workaround corrupts stock, VAT, or the drawer. | F-01, A-1 |
| **A2** | **Invoice void / cancel** | Duplicate ring-ups are permanent. `Order::CANCELLED` is filterable and unreachable — the merchant can see the state and never enter it. | F-02, R-03 |
| **A3** | **Backup & restore** — disable restore now, then rebuild | Restore covers 17 of ~40 tables, cascades away every POS register and all branch stock, **fails outright for any merchant with a supplier invoice**, and reports success in every case. Invoked exactly when the merchant is already in trouble. | R-01 |
| **A4** | **Purchase receiving: wrap in a transaction, lock rows, allow partial receipt** | A short delivery cannot be recorded, so stock over-states and the POS sells air. Cost averaging races concurrent sales. | F-07, R-04, R-05 |
| **A5** | **Purchase order number collision** | `random_int(10000,99999)` with a non-unique index — ~50% duplicate probability by the 350th PO. The same bug was correctly fixed for invoices and transactions. One-line fix. | R-06 |
| **A6** | **Stocktake: atomicity + O(n²)** | Times out and half-applies on any catalogue above ~500 SKUs, double-booking shrinkage for the applied half. The count is the moment a merchant decides whether to trust stock figures forever. | F-06, R-07 |
| **A7** | **Wire sales & COGS to the ledger — or hide the accounting section** | Today the trial balance shows zero revenue, zero COGS, and an inventory asset that grows forever. The demo store shows correct books that a real account can never produce. | F-04, R-02 |
| **A8** | **VAT return report** | Abaad collects VAT on every invoice and offers no way to declare it. `Demo::vatReport()` already exists, orphaned. Legal exposure for the merchant. | F-11, A-4 |
| **A9** | **Remove (or implement) the WhatsApp and SEO screens** | Settings that save and are read by nothing. A merchant who enables "notify when ready", stops phoning customers, and loses them will never trust another toggle. | F-10, A-8 |
| **A10** | **Manual discount at POS + discount permission** | "Take a rial off" is universal. Without it shops create fake coupons or edit invoices after the fact — both corrupt data. Must ship with the permission. | A-9, A-10, F-14 |
| **A11** | **Stock adjustment: require an explicit branch and guard the branch balance** | In "All branches" mode (the session default) an adjustment moves the company total and no branch, permanently breaking the stock invariant; the negative guard checks the wrong scope. | R-11, R-12 |
| **A12** | **Never delete posted journal entries** | Deleting a supplier invoice erases numbered ledger entries, leaving a gap in the journal sequence. The one thing a general ledger must never do. | R-17 |
| **A13** | **Branch-to-branch transfer** *(only if multi-branch plans ship at launch)* | Multi-branch is a priced, enforced feature whose core operation does not exist. | F-03, OD-03 |
| **A14** | **Remove unreachable statuses from filters** | PO `مسودة`/`مستلم جزئيًا`/`ملغي` and order `قيد التجهيز`/`جاهز`/`خرج للتوصيل` are offered and can never occur. Cheap; protects credibility. | §3.4 of doc 09 |
| **A15** | **Block deletion of products holding stock** | Deleting a product with 200 units silently removes 200 × cost from valuation with an undo toast as the only signal. | R-15 |

---

# B. SHOULD FIX BEFORE LAUNCH
*What makes Abaad feel like a finished commercial product rather than a capable internal tool.*

| # | Item | Why | Ref |
|---|---|---|---|
| **B1** | Onboarding checklist on the dashboard | Time-to-first-sale is the strongest activation predictor; today the setup path is nine undocumented screens. | F-13, B-3 |
| **B2** | Back-office shift review + Z-report | Shift data is collected meticulously and shown to no one. A cashier 2 rials short nightly is invisible. Also removes the dead `admin.shifts.*` branch. | F-09, B-2 |
| **B3** | Profit & Loss (and Balance Sheet) | The question the owner opens the app to answer. `trialBalance()` already returns the data. Depends on A7. | B-1 |
| **B4** | Merge the two stock-adjustment screens | Same event, different data, depending on which adjacent menu item was clicked; reports double-count one path. | F-05, R-09 |
| **B5** | Supplier balance, statement & payables ageing | "What do I owe and what's due this week?" — daily question, no answer. Data already exists. | F-08, B-4 |
| **B6** | Stocktake overage → an inventory-variance account | Currently increases assets with no counterpart. | R-08 |
| **B7** | Period lock for filed VAT / closed months | Without it, A1 and A8 remain untrustworthy — a declared period can be silently restated. | R-03, B-11 |
| **B8** | Split & partial payments | "30 card, 20 cash" and deposits on special orders are routine. | F-16, B-6 |
| **B9** | Drawer cash-out → optional expense | Petty cash leaves the business and never appears in finance. | R-23 |
| **B10** | Coupon `used_count` decrement on void/return + per-customer limit | "First 100 customers" campaigns miscount; one customer can reuse a welcome code forever. | R-18 |
| **B11** | Terminology pass | "monthly expenses", "edit" used for returns, two indistinguishable finance sections, `shifts.returns` meaning cash-in. | Doc 07 |
| **B12** | Unique index on `transactions.reference` | Concurrent manual entries can share a reference in a financial book. | R-19 |
| **B13** | Delivery note: decimal quantities, in-transaction branch-scoped check | 2.5 kg is silently delivered as 2; the check runs outside the transaction against the company total. | R-13, R-14 |
| **B14** | Decide the fate of the eight orphaned `Demo` functions | VAT report, profitability, sales by category, period comparison, sales by hour/weekday are cheap reports whose data layer already exists. | §3.1 of doc 09 |
| **B15** | Refuse deletion of a received purchase order | Stock stays; the document justifying it disappears. | R-16 |
| **B16** | Bulk actions in the shared `DataTable` | Repricing a category means 40 individual edits today. | B-14 |
| **B17** | Receipt template: presets + live preview | 11 boolean flags across three paper sizes is a configuration wall. | §4 of doc 09 |
| **B18** | Reorder suggestion → draft PO | Turns an existing report into a workflow. Needs a supplier link on the product. | B-10 |

---

# C. CAN WAIT
*Safe to ship after launch; real value, no operational damage from their absence.*

- **C1** Units of measure & fractional quantities *(unless weight retail is a launch segment — see OD-04)* — B-8
- **C2** Product variants (size/colour) — B-12
- **C3** WhatsApp notifications, actually implemented — B-7
- **C4** Recurring expenses — B-13
- **C5** Stock valuation, movement, and slow-mover reports — B-9
- **C6** Sales by category / hour / weekday, period comparison (data already exists) — D-4
- **C7** Order fulfilment lifecycle + delivery queue *(OD-05)* — F-17
- **C8** Held-cart stock visibility on POS tiles — F-18
- **C9** Price-change warning on cart resume — R-24
- **C10** "Selling below cost" warning on save and on import preview — R-25
- **C11** Employee commission calculation (fields already collected) — C-5
- **C12** Landed cost on purchases — C-4
- **C13** Quotations / proforma (extends the existing `SAVE-` cart) — C-12
- **C14** Category nesting UI (`parent_id` already exists) — §3.5 of doc 09
- **C15** Per-record history views (price history, stock ledger per product) — Doc 07
- **C16** Missing list filters (products by stock status; expenses by type; supplier search; journal by account) — Doc 07
- **C17** Payment-gateway integration for self-service renewal — C-9
- **C18** Two-factor authentication for owners and the platform operator — Doc 10 §6
- **C19** Tenant-scope `BranchStock::ensureAllocated` — R-21
- **C20** Re-read the open shift inside the checkout transaction — R-22
- **C21** Pagination for the remaining unbounded `Demo` reads — Doc 10 §8

---

# D. DO NOT BUILD YET
*Would add complexity before the core is coherent. Each has a reason, not just a "no".*

| # | Item | Why not yet |
|---|---|---|
| **D1** | **Customer credit / receivables** | Already removed by deliberate owner decision. Returning it before returns, void, and the ledger wiring exist would add a second unreconciled money system. Revisit only when a real customer asks — and then build it as a module, not a column. *(OD-02)* |
| **D2** | **Multi-warehouse distinct from branch** | Branches already serve this. A separate warehouse entity doubles the stock model before branch transfer even exists. |
| **D3** | **Purchase approval workflows** | Aimed at organisations with segregated purchasing roles. Abaad's customer is a shop where the owner buys. |
| **D4** | **Serial / batch / expiry tracking** | Genuine need for pharmacy and food, but it multiplies every inventory path — and there are six of them, three of which need repair first. |
| **D5** | **Native mobile app** | The POS is already a responsive web app with an offline queue. A native shell adds a build pipeline, a release process, and store review before it adds a single capability. |
| **D6** | **E-commerce storefront inside Abaad** | The website settings imply one; the storefront belongs in the separate `abaadapp/Website` repository. Building a second one here duplicates the catalogue. |
| **D7** | **Public API / webhooks** | The data model still has two disconnected financial systems and three stock-write rule sets. Exposing that publicly freezes it. |
| **D8** | **Advanced analytics / forecasting** | Restore the six *basic* reports whose data functions already exist before predicting anything. |
| **D9** | **Loyalty tiers & point expiry** | The base program works and is used. Tiers optimise retention for shops that already have a loyal base — none exist yet. |
| **D10** | **Franchise / multi-company consolidation** | Multi-branch does not yet support transfers. Consolidation across companies is two levels above where the product is. |
| **D11** | **Self-service merchant signup** | Onboarding is nine undocumented screens. Self-signup would manufacture abandoned tenants. Build B1 first. |
| **D12** | **Multi-currency accounting** (as opposed to display) | Consider *reducing* the current display conversion instead — historical amounts re-converted at today's rate is closer to a liability than a feature for a single-country product. |
| **D13** | **AI features of any kind** | No customer problem here is an AI problem. |

---

## Suggested sequencing

**Phase 1 — Stop the bleeding (small, mostly mechanical):**
A5 (PO numbering), A3 (disable restore), A4 (transaction + locks), A6 (stocktake), A11 (branch
scope), A12 (no journal deletes), A14 (dead statuses), A15 (product delete guard), A9 (remove dead
screens).
*These are defect fixes, not features. Most are hours, not days.*

**Phase 2 — The three missing primitives:**
A1 (returns), A2 (void), A10 (discount + permissions), A13 (transfer, if multi-branch ships).
*This is the phase that turns Abaad into a POS a shop can actually run.*

**Phase 3 — Make the money side true:**
A7 (ledger wiring or hide), A8 (VAT return), B3 (P&L), B7 (period lock), B2 (shift review).

**Phase 4 — Make it feel finished:**
B1 (onboarding), B4, B5, B8, B11, B16, B17.

Phases 1 and 2 together are the realistic definition of "ready to sell".
