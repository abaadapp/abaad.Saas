# 12 — OWNER DECISION REQUIRED

Each entry: **what happens today · why it matters · options · recommendation · trade-offs.**
Nothing below should be implemented until you decide.

---

## OD-01 · What is the accounting module for?

> **OWNER DECISION REQUIRED**

**1. What currently happens.**
Abaad has two disconnected financial systems. `transactions` is a cash book fed by POS sales and
expenses; it drives the dashboard, the Finance screens, and every report. `journal_entries` is a real
double-entry ledger with a seeded chart of accounts, and it is fed **only** by supplier invoices,
supplier payments, payroll, fixed assets, and stock adjustments. **Sales, COGS, cash receipts and
operating expenses never post to it.** Revenue account 4100 is permanently zero; the Inventory asset
is debited by every purchase and never relieved. The demo-data generator *does* post sales and COGS
journals, so a prospect's demo shows complete books that their own account will never produce.

**2. Why the decision matters.**
The trial balance balances and is materially false. An accountant opening it will conclude the
product is broken. This is the difference between "Abaad has accounting" and "Abaad has a cash book
plus some purchase journals".

**3. Options.**

| | Approach | Effort | Result |
|---|---|---|---|
| **A** | **Wire it fully.** Post `Dr Cash/Bank, Cr Sales, Cr VAT Payable` and `Dr COGS, Cr Inventory` on every sale (or as a nightly per-branch aggregate); post expenses; post returns once they exist. Add P&L and Balance Sheet on top of `trialBalance()`. | Medium–high | Real books. A genuine differentiator against imported POS software in Oman, and the strongest reason an accountant recommends Abaad. |
| **B** | **Hide it.** Put the الحسابات section behind a plan flag, off by default, until A is done. Keep the cash book as the only financial story. | Very low | No false books. No accounting story either. |
| **C** | **Reposition it.** Keep it visible but relabel it explicitly as "purchase & payroll ledger", and state on the screen that sales are tracked in المالية. | Low | Honest, but confusing — two money systems the merchant must mentally join. |
| **D** | **Delete it.** Remove the chart, journal, fixed assets and payroll ledger entirely; keep the cash book. | Low | Loses genuinely good code and two plan-tier differentiators. |

**4. Recommendation: B now, A next.**
Hide the section for launch (an afternoon), and schedule the wiring as the first post-launch epic.
This removes the worst trust risk immediately without discarding good work.

**5. Trade-offs.**
- A: best product outcome; needs a payment-method → account mapping, a decision between per-invoice
  and nightly-aggregate posting, and a visible "unposted sales" indicator so failures are never
  silent. Aggregate posting is far cheaper at POS volume and is what most SME systems do.
- B: fastest and safest, but the pricing page loses an "accounting" bullet until A lands.
- C: I would avoid it — asking a merchant to mentally reconcile two ledgers is worse than showing one.
- D: irreversible, and fixed assets + payroll are exactly what a second plan tier needs.

---

## OD-02 · Do credit sales come back?

> **OWNER DECISION REQUIRED**

**1. What currently happens.**
Credit sales and receivables were added in v3.39 and removed at your explicit request in migration
`2026_08_12_150000_drop_credit_sales.php` — `customer_payments` dropped, `orders.paid_amount` and
`orders.due_at` dropped, with a note that a leftover column becomes a mystery a year later. Today
every sale is fully paid at the moment of sale; `payment_status` is always `'مدفوع'`.

**2. Why the decision matters.**
It defines Abaad's market. Pure-cash retail (fashion, gifts, groceries, cafés) does not need it. Any
shop with trade customers — hardware, building materials, wholesale, auto parts, catering supply —
cannot use Abaad at all without it. It is also a prerequisite for deposits on special orders, which
matter to florists, tailors and furniture shops.

**3. Options.**

| | Approach | Result |
|---|---|---|
| **A** | **Stay cash-only.** Position Abaad explicitly for retail-to-consumer shops. | Simplest product. Excludes a real segment. |
| **B** | **Deposits only** — an `order_payments` child table allowing partial payment against an invoice, without customer accounts, credit limits, or statements. | Covers split payment and deposits. Does not cover trade accounts. |
| **C** | **Full receivables** — customer accounts, credit limit, ageing, statements, payment allocation, ledger integration. | Opens the B2B segment. Significant module. |

**4. Recommendation: B for launch; C only when a paying customer asks.**
`order_payments` also solves split payments (F-16), which every shop needs, so the work is not wasted
either way. Full receivables should not return as a `paid_amount` column — that is what was removed,
and correctly.

**5. Trade-offs.**
- A: smallest surface; a lost deal every time a wholesaler evaluates.
- B: modest effort, immediately useful to everyone, no new money system to reconcile.
- C: real revenue opportunity, but it adds a second unreconciled balance system unless OD-01 option A
  lands first. **Do not build C before the ledger is wired.**

---

## OD-03 · Is multi-branch a launch capability?

> **OWNER DECISION REQUIRED**

**1. What currently happens.**
Branches are a real, well-scoped entity. `plans.max_branches` is enforced at creation. Orders,
movements, adjustments, POs, shifts and registers all carry `branch_id`. Employees can be restricted
to branches and POS registers are hard-bound to one. **But there is no way to move stock between
branches** — no transfer document, no transfer movement type, nothing. The workaround is two
unlinked manual movements.

**2. Why the decision matters.**
If plans are sold on branch count, a two-branch customer will hit this in week one, and inter-branch
movement will become their largest source of unexplained stock variance. If launch targets
single-branch shops, this drops from blocking to important.

**3. Options.**

| | Approach | Result |
|---|---|---|
| **A** | **Build transfers before launch** (F-03: from/to, lines, in-transit state, destination confirmation with discrepancy handling). | Multi-branch is genuinely operable and can be priced. |
| **B** | **Launch single-branch only**, cap every plan at 1 branch, build transfers for a v1.1 "multi-branch" tier. | Smaller launch scope; a clean, sellable upgrade story later. |
| **C** | **Launch multi-branch without transfers** and document the manual workaround. | Fastest. Guarantees stock-variance complaints from your first multi-branch customer. |

**4. Recommendation: B, unless a signed multi-branch customer is already waiting — then A.**
Capping plans at one branch for launch is a one-row change and turns a gap into a roadmap item you
can charge for.

**5. Trade-offs.**
- A: the transfer document is not large (roughly the delivery-note pattern with two branches), but
  the in-transit state touches availability calculations.
- B: lowest risk; requires resisting the temptation to sell multi-branch early.
- C: I would not do this. It converts a known gap into a data-quality problem you inherit.

---

## OD-04 · Does Abaad sell to weight-based retail?

> **OWNER DECISION REQUIRED**

**1. What currently happens.**
`order_items.quantity`, `purchase_order_items.quantity` and `branch_stocks.quantity` are integers;
POS validates `qty` as `integer|min:1`; `products` has no unit column. Butchers, greengrocers,
coffee roasters, sweet shops, fabric and hardware retailers **cannot use Abaad at all.**

**2. Why the decision matters.**
This is a market-size decision, not a feature request. In Omani small retail, weight-and-measure
shops are a large share of the addressable market. It is also a schema change touching every stock
path, so it gets more expensive with every month of live data.

**3. Options.**

| | Approach | Result |
|---|---|---|
| **A** | **Add units + fractional quantities now** — `products.unit`, `allow_fractional`, widen quantity columns to `decimal(12,3)`, POS decimal keypad. Scale-barcode parsing later. | Opens the segment. Cheapest to do before customers have data. |
| **B** | **Explicitly exclude weight retail.** Target fashion, gifts, electronics, pharmacy, cafés. Keep integers. | Simplest product; a clear positioning statement. |
| **C** | **Defer.** Decide after the first ten customers. | Migration cost rises; positioning stays vague. |

**4. Recommendation: A — decide it now, even if you build it in v1.1.**
The schema widening is far cheaper before live data exists. If the answer is B, say so publicly so
sales does not chase those shops.

**5. Trade-offs.**
- A: touches six stock-write paths and the POS keypad; low conceptual risk, broad mechanical change.
- B: honest and simple, but permanently forecloses a segment.
- C: the worst of both — the cost of A grows and the clarity of B never arrives.

---

## OD-05 · Does Abaad handle order fulfilment, or only counter sales?

> **OWNER DECISION REQUIRED**

**1. What currently happens.**
`orders.status` defaults to `'جديد'`; POS always writes `'مكتمل'`. The statuses
`قيد التجهيز` / `جاهز` / `خرج للتوصيل` exist and are only ever set by seeders. A `delivery` role
exists with its own permission set. WhatsApp templates for "order ready" and "order delivered" exist.
Delivery notes exist. **There is no way to move an order between statuses.** The demo data (a flower
shop, `super-admin/flower-shops` routes) implies a delivery use case throughout.

**2. Why the decision matters.**
An entire half-built fulfilment story is visible in the product — a role, statuses, notification
templates, delivery notes — with no transitions connecting them. Either it becomes a feature or it
becomes clutter that confuses every new merchant.

**3. Options.**

| | Approach | Result |
|---|---|---|
| **A** | **Build it.** Status transitions with permissions, a delivery/prep queue screen, driver assignment, and the WhatsApp triggers whose templates already exist. | A real differentiator for florists, bakeries, restaurants, and any shop that delivers. Makes the `delivery` role and the WhatsApp screen meaningful at once. |
| **B** | **Remove it.** Delete the unused statuses, drop the `delivery` role, drop the ready/delivered WhatsApp templates. Position Abaad as a counter-sales POS. | Smaller, clearer product. |
| **C** | **Leave as is.** | Statuses in a filter that can never occur; a role with no distinct job; templates for events that never fire. |

**4. Recommendation: A, but *after* the Phase-1 and Phase-2 items in doc 11.**
It is the most commercially interesting of the deferred features and it makes three existing
half-features whole. It should not jump ahead of returns and void.

**5. Trade-offs.**
- A: moderate scope (a status machine, one queue screen, one notification hook). Depends on WhatsApp
  actually being implemented (OD-06) for its full value.
- B: fastest path to a coherent product; discards work already half-present and closes the
  florist/restaurant segment the demo data suggests you were aiming at.
- C: not viable — it is the "knob that does nothing" pattern the codebase elsewhere works hard to
  avoid.

---

## OD-06 · WhatsApp: implement, or remove the screen?

> **OWNER DECISION REQUIRED**

**1. What currently happens.**
Marketing → WhatsApp renders a full form — enable toggle, business number, three message templates
with placeholder substitution, three event toggles — validates carefully, and saves eight settings
that **no code anywhere reads**. There is no API client, no queue job, no send path. Identically,
Marketing → SEO saves five keys read by nothing.

**2. Why the decision matters.**
A merchant who enables "notify the customer when the order is ready", stops phoning, and loses
customers will not merely lose trust in that toggle — they will lose trust in every setting in the
product. The codebase's own comments name this failure mode repeatedly as the most dangerous kind.

**3. Options.**

| | Approach | Result |
|---|---|---|
| **A** | **Implement** via WhatsApp Cloud API (or a local Omani gateway): a queued job on order events, a delivery log, and per-message status. | In Oman, WhatsApp *is* the customer channel. Real differentiator versus imported POS software. Depends on OD-05 for the ready/delivered events. |
| **B** | **Remove both screens now**, keep the settings rows harmlessly, restore them when implemented. | Zero risk, one hour of work. |
| **C** | **Downgrade to "click to chat"** — no automation, but a WhatsApp button on the customer record and the order that opens `wa.me` with a pre-filled message the merchant sends manually. | Genuinely useful, no API cost, no delivery guarantees to make. Roughly a day of work. |

**4. Recommendation: B immediately, then C, then A when OD-05 lands.**
Remove the dead screens before anyone sees them; ship the click-to-chat version as a quick win; build
real automation alongside fulfilment.

**5. Trade-offs.**
- A: WhatsApp Business API requires business verification, template pre-approval, and per-message
  cost — real operational overhead you would be taking on for your merchants.
- B: nothing lost but a bullet point that was never true.
- C: excellent effort-to-value ratio and no compliance surface; it does not send anything
  automatically, so it must be labelled as manual.

**SEO specifically:** these keys belong to the separate `abaadapp/Website` project, which shares the
database. Either have that project read them (no work in Abaad beyond a note) or remove the screen.

---

## OD-07 · How much permission granularity?

> **OWNER DECISION REQUIRED**

**1. What currently happens.**
Permissions are **section-level only** — 14 sections, plus per-user manual overrides. Anyone granted
`products` can change every price and delete any product. Anyone at the POS can edit a completed
invoice through `OrderCorrection`. There is no discount permission because there is no discount
action yet.

**2. Why the decision matters.**
Section permissions answer "which screens can you open". Shop owners worry about "how much damage can
you do". Adding a manual discount (A-9) without a permission would hand every cashier an unlimited
discount button.

**3. Options.**

| | Approach | Result |
|---|---|---|
| **A** | **Small fixed action set** — `discount.apply`, `order.edit`, `order.void`, `order.return`, `price.edit`, `product.delete`, `stock.adjust`, `report.financials`. Defaulted by role, overridable per user in the existing manual-permissions UI. | Covers every real complaint. Fits the existing model. Roughly 8 new checkboxes. |
| **B** | **Full CRUD matrix** — view/create/edit/delete per section (14 × 4 = 56 permissions). | Complete and unusable: a shop owner will not configure 56 checkboxes. |
| **C** | **Stay section-level**, and gate risky actions by *role* instead (e.g. only `admin`/`manager` may edit an invoice). | Zero new UI. Inflexible — a shop with one trusted senior cashier cannot express that. |
| **D** | **Approval flow** — a cashier requests a discount/void, a manager approves with their PIN on the same screen. | The right answer for larger shops; more UI, and a manager must be present. |

**4. Recommendation: A, with D as a later addition for the discount and void actions specifically.**
The manager-PIN-approval pattern is well understood in retail and the PIN infrastructure already
exists.

**5. Trade-offs.**
- A: a deliberate, bounded list. The discipline is refusing to grow it.
- B: I would not do this. It is the classic ERP mistake — configurability nobody configures.
- C: cheapest, but re-introduces the "role, not permission" rigidity that the manual-permissions
  feature was built to remove.
- D: best control, most UI; reuse the existing PIN pad.

---

## OD-08 · Same-day correction vs. return — where is the line?

> **OWNER DECISION REQUIRED**

**1. What currently happens.**
`OrderCorrection` edits completed invoices with no time limit, no permission gate, and no distinction
between "the cashier keyed 3 instead of 2 thirty seconds ago" and "the customer brought this back
three weeks later". It is reachable from the POS by anyone. It is well-built and audited — but it is
being used for a job it was not designed for.

**2. Why the decision matters.**
It determines whether the return feature (A-1) replaces `OrderCorrection` or sits beside it, and it
determines what happens to invoices in a filed VAT period.

**3. Options.**

| | Approach | Result |
|---|---|---|
| **A** | **Time-boxed correction + returns for everything else.** Correction allowed only while the originating shift is open, gated by `order.edit`. After close, only a return/credit note. | Matches how retail actually works. Makes period locking trivial. |
| **B** | **Returns only.** Remove `OrderCorrection` entirely; every fix is a return. | Purest audit trail. Painful for a genuine keystroke error caught two seconds later — a return document for a mis-key is noise. |
| **C** | **Keep both unrestricted.** | Fiscal documents stay mutable forever; filed VAT periods stay restatable. |

**4. Recommendation: A.**
"While the shift is open" is a boundary cashiers already understand, it is enforceable with data that
already exists (`orders.shift_id` and shift status), and it makes the period lock (B7 in doc 11)
almost free.

**5. Trade-offs.**
- A: needs the return feature first; until then, removing the correction path would leave no fix at all.
- B: cleanest books, worst counter experience.
- C: not viable — it is the root of risks R-03 and the VAT restatement problem.

---

## OD-09 · Which modules define the plan tiers?

> **OWNER DECISION REQUIRED**

**1. What currently happens.**
`plans` limits only **branches, employees and products** — all enforced correctly at creation time.
Every module is available on every plan. Fixed assets, payroll, bank reconciliation, delivery notes
and multi-currency are all fully built and given away at the base tier.

**2. Why the decision matters.**
Right now there is nothing to sell an upgrade *on* except counts. Several genuinely mid-market
modules already exist and are exactly what a second tier is made of. This also solves the
`09-overengineering-review.md` tension: those modules are too much for a three-person shop but should
not be deleted.

**3. Options.**

| | Approach | Result |
|---|---|---|
| **A** | **Feature-gated tiers.** Basic: POS, catalogue, inventory, customers, expenses, basic reports. Pro: purchasing + supplier invoices, multi-branch + transfers, full reports, VAT return. Business: accounting/ledger, payroll, fixed assets, bank reconciliation, delivery notes. | Clear upgrade ladder built entirely from code that already exists. Also reduces the base product's surface area, which helps onboarding. |
| **B** | **Count-based only** (today). | Simple; nothing to upsell on except growth. |
| **C** | **Everything on every plan, priced by branch/user count.** | Simplest story; leaves money on the table and overwhelms small merchants with 14 sections. |

**4. Recommendation: A.**
`plans.features` is already a JSON column and `Permissions::SECTIONS` already provides the natural
gate keys — the mechanism is largely in place.

**5. Trade-offs.**
- A: needs a feature-gate check alongside the existing permission check, and the sidebar must hide
  ungated sections cleanly (the same pattern `Reports::forUser` already uses).
- B/C: no engineering work; a weaker commercial position and a heavier first-run experience.

---

## Minor decisions worth a yes/no

| # | Question | Recommendation |
|---|---|---|
| **OD-10** | Should `NameTransliterator` keep silently converting "John Smith" → "جون سميث" on save? | Confirm with real merchants. If they do not want it, 534 lines and a surprising behaviour can go. |
| **OD-11** | Keep multi-currency display conversion, given that historical amounts are re-converted at today's rate? | Reduce to symbol/decimals formatting unless a real customer needs it. |
| **OD-12** | Should the Reviews screen exist without any way for customers to submit a review? | Connect it to a post-purchase link, or remove it. Manual review entry has no business purpose. |
| **OD-13** | Rename `Support\Demo` (the production read model) to something honest, e.g. `Support\Query` or `Support\ReadModel`? | Yes — mechanical rename, large clarity gain, protected by 1,120 tests. |
| **OD-14** | Restore the removed `require_open_shift` and `shift_max_hours` settings knobs? | Yes, alongside the back-office shift screen (B2). A shift gate the merchant cannot enable is dead code. |
| **OD-15** | Should the platform's `businesses.branches_count` be replaced with a computed count? | Yes — it is currently displayed to the operator and wrong for any store that added a branch. |
