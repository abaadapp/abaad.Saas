# 09 — Overengineering & Simplification Review

The question here is not "is this well built?" (mostly it is) but **"does a shop with three
employees need this, and does its presence make the rest harder to use?"**

---

## 1. Duplicate concepts that should become one

### 1.1 Two stock-adjustment systems — **merge**
`Inventory → Movement` and `Inventory → Adjustments` are the same feature with different rules,
different guards, different reasons, and different accounting. See F-05 / R-09. Keep the
`stock_adjustments` document; retire the free-text movement form; make `inventory_movements` a
read-only audit ledger written by every path and by no user directly.

### 1.2 Two financial systems — **connect or separate honestly**
`transactions` (cash book, drives dashboards and reports) and `journal_entries` (double entry,
drives the accounting screens) are disjoint. This is not necessarily wrong — a cash book that feeds
dashboards is a perfectly good design — but today it is *invisible*, and the accounting side is
missing 100% of revenue. Either wire sales into the ledger (F-04) and make `transactions` a
projection of it, or keep them separate and label them so a merchant understands which is which.

### 1.3 Three representations of "which branch" — **collapse**
- `orders.branch` (name string) alongside `orders.branch_id`
- `users.branch` (name string) alongside the `branch_user` pivot
- `businesses.branches_count` (typed number) alongside the `branches` table

The typed count is shown in the platform console and is simply wrong for any store that added a
branch. Keep `branch_id` / the pivot / a computed count; retain `orders.branch` only as an explicit
immutable historical label and never read it in a filter.

### 1.4 Invoice correction vs. return — **separate properly**
`OrderCorrection` currently does the job of three different documents: keystroke correction, return,
and (attempted) void. Splitting them (F-01, F-02) both fixes the data problems and *shrinks*
`OrderCorrection` to what it is genuinely excellent at.

### 1.5 Loyalty settings had two owners — **already fixed, keep it that way**
`SettingController` deliberately excludes the loyalty keys so `MarketingController::saveLoyalty` is
the sole writer. Correct pattern; apply the same reasoning wherever a key has two write paths.

---

## 2. Screens that should be removed or hidden

| Screen | Recommendation |
|---|---|
| **Marketing → WhatsApp** | Remove until implemented, or implement. Not "leave it saving". |
| **Marketing → SEO** | Remove from Abaad. These keys belong to the `abaadapp/Website` project, which shares the database and can read them there. |
| **Marketing → Website** | Keep only `site_domain` (genuinely consumed by `Demo::websiteUrl()`). The other seven keys are read by nothing in this app. |
| **Marketing → Reviews** | Nothing collects reviews from customers; the merchant types them in by hand. Either connect it to a review request (post-purchase WhatsApp/SMS link) or remove it. Manual review entry has no business purpose. |
| **الحسابات (Chart / Journal / Trial balance)** | Hide behind a plan flag until sales post (F-04). A trial balance without revenue is actively misleading. |
| **Finance → Fixed assets** | Genuinely well built, but it is a mid-market feature in a product aimed at small shops, and it depends on a ledger that is not receiving the rest of the business. Consider gating to a higher plan tier — that also gives the pricing page something to differentiate on. |
| **Payroll** | Same reasoning as fixed assets: correct, complete, and above the needs of a three-person shop. Good plan-tier differentiator. |
| **Delivery notes** | Well-reasoned (the "linked note does not move stock" logic is subtle and right), but it is a wholesale/distribution document. Keep, gate to a higher tier, or fold into the fulfilment flow if F-17 is built. |
| **Bank statement import & reconciliation** | High quality; reconciles against `transactions`, which is the right table. Keep, but it is another higher-tier candidate. |
| **Multi-currency** | Display-only conversion with live rates applied to historical amounts. For a single-country product this is closer to a liability than a feature — a report can change value between two viewings. Consider reducing to "display symbol and decimals" unless a real customer needs it. |

**The pattern:** Abaad has drifted upward into small-ERP territory (chart of accounts, journals,
fixed-asset depreciation, payroll runs, bank reconciliation, delivery notes) while three POS
primitives — return, void, transfer — remain unbuilt. **The mid-market modules are not the problem
in themselves; the ordering is.**

---

## 3. Dead code and orphaned logic

### 3.1 Orphaned functions in `Support/Demo.php`
Verified as called from **nowhere**:
`vatReport()`, `categoryProfitability()`, `profitStats()`, `profitSummary()`, `salesByWeekday()`,
`salesByHour()`, `categorySales()`, `periodComparison()`.

That is roughly 400 lines in a 2,750-line file. **Do not simply delete them** — several are the data
layer for reports that should come back (VAT return is A-4; profitability, sales by category and
period comparison are cheap wins). Decide per function: revive with a screen, or remove.

### 3.2 Dead route handling
`Permissions::sectionFromRoute()` contains explicit branches for `admin.shifts.*` — routes that are
not registered. Either build the shift screen (B-2) or drop the branch.

### 3.3 Unreachable settings
`allow_negative_stock`, `require_open_shift`, `shift_max_hours`, `dormant_customer_days` are read by
code and cannot be set from anywhere (the settings whitelist excludes them). Each is either a feature
that should have a knob or a code path that should be simplified to its constant.

### 3.4 Unreachable statuses
Purchase order: `مسودة`, `مستلم جزئيًا`, `ملغي` — offered in the UI filter, never set.
Order: `جديد`, `قيد التجهيز`, `جاهز`, `خرج للتوصيل` — only seeders set them.
Either implement the lifecycle (F-07, F-17) or remove the filters. A filter for an unreachable state
teaches the merchant that the product's UI cannot be trusted.

### 3.5 Unused columns
`shifts.returns` (populated from cash-*in* movements, not returns), `users.monthly_target` and
`users.commission_rate` (collected, displayed, never computed), `categories.parent_id` (no nesting
UI), `purchase_order_items.received_quantity` (never partial).

### 3.6 `Support/Emojis.php` — 622 lines
An emoji catalogue for the category-icon picker. Meanwhile `ReceiptTemplate::printable()` exists
specifically to **strip emoji** because the PDF font cannot render them. Worth checking whether a
622-line curated set is earning its place against a much smaller list.

### 3.7 `Support/NameTransliterator.php` — 534 lines
Latin→Arabic name transliteration for customers and products. Clever, well-tested — and it silently
rewrites what a merchant typed. Confirm with real users that they want "John Smith" stored as
"جون سميث"; if they do not, this is 534 lines and a surprising behaviour that can go.

---

## 4. Configuration surface

`SettingController::KEYS` holds 37 validated keys; `MarketingSettings` holds 25 more; platform
settings add ~10. **~72 settings for a shop with three employees.**

The receipt template alone has 11 boolean flags (`tpl_show_logo`, `tpl_show_branch`,
`tpl_show_employee`, `tpl_show_customer`, `tpl_show_datetime`, `tpl_show_items_count`,
`tpl_show_vat_no`, `tpl_show_qr`, plus header, footer, font size) governing three paper sizes.

**Recommendation:** replace the flag grid with **two or three named receipt presets** (minimal /
standard / tax invoice) plus a live preview, and keep the individual flags behind an "advanced"
disclosure. Most merchants will never open it, and the ones who do will find it.

Similarly, `decimals` (0–4) and `symbol_pos` (before/after) are presented as top-level currency
settings; for a single-country product the currency choice should imply both.

---

## 5. Workflows with too many steps

| Task | Steps today | Should be |
|---|---|---|
| Buy stock and record what you owe | Create PO → receive → separately create supplier invoice with a hand-typed total → pay | Create PO → receive (with actual quantities) → "create invoice from receipt" pre-filled → pay |
| Record damage | Choose between two screens with different rules | One screen |
| Move stock between branches | Deduct in A, switch branch, add in B, hope | One transfer document |
| Correct a cashier's mistake | Open POS → orders → find invoice → edit each line | Same-shift correction (keep) + a return document for everything else |
| Set up a new store | 9 screens, no guidance | Checklist with deep links |
| Count stock | One giant page, no filter, no partial save | Count by category/section, save progress, review variances, then apply |

---

## 6. Things that are the right amount of complexity — do not "simplify" these

- **`Stock::availabilityResolver`** — the allocated/never-allocated distinction looks like extra
  complexity and is load-bearing. Removing it either empties the POS screen or permits overselling.
- **The cost snapshot on `order_items`** — looks redundant against `products.cost`; it is what makes
  historical profit stable.
- **`Ledger::post` as the single write door** — the balance, side, and postability checks belong in
  exactly one place.
- **`ensureSystemAccounts`** — back-filling only missing system accounts without touching a merchant's
  edited chart is exactly right.
- **Offline outbox + `client_uuid`** — non-negotiable for a POS.
- **`ReceiptVisibility` stripping the payload** — the only correct way to do this.
- **The settings whitelist** — it caused four orphaned keys, and that is a far better failure mode
  than silently-dropped settings.
- **Driver-specific SQL in `nextNumber`** — genuinely necessary for the SQLite→PostgreSQL move.
- **The Arabic explanatory comments** — they are long, and they are the reason this audit could
  reconstruct intent quickly. Keep them.

---

## 7. Summary

**Remove or hide now:** WhatsApp screen, SEO screen, unreachable statuses, dead route branches,
Reviews (unless connected), the accounting section (until wired).

**Merge:** the two adjustment screens; the three branch representations; correction vs. return.

**Decide per item:** the eight orphaned `Demo` functions; the four unreachable settings;
`NameTransliterator`; the 622-line emoji set.

**Gate to higher plan tiers rather than delete:** fixed assets, payroll, delivery notes, bank
reconciliation, multi-currency. They are good code and they are not what a three-person shop needs
in month one — but they are exactly what a pricing page needs to justify a second tier.

**Simplify:** the receipt-template flag grid → presets + preview.

**The core recommendation of this document:** stop adding modules. Abaad's problem is not that it
lacks features; it is that three POS primitives are missing while five ERP modules are finished.
