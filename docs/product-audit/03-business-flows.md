# 03 — Business Flows As They Exist Today

This document traces what actually happens in the code, step by step, for each core retail process.
It is descriptive, not prescriptive — the judgement is in `04-module-audit.md`.

Legend: **✅** works · **⚠️** works with a caveat · **❌** does not exist · **🩸** writes data that
will later be wrong.

---

## Flow 1 — Store setup (first-run)

```
Platform operator creates Business (name, type, plan, starts_at/ends_at, owner account)
        ↓  SuperAdmin\BusinessController::store
   ✅ business row + owner user + default branch + default currency + default job titles
        ↓
Owner logs in → Permissions::homeFor() → /admin/dashboard
        ↓
   ⚠️  NO onboarding wizard. The owner lands on an empty dashboard with 14 sidebar
       sections and no guidance about what to do first.
        ↓
Settings → business profile, VAT (rate / number / inclusive), currency, invoice prefix,
           payment methods, receipt template                                        ✅
Settings → Branches                                                                  ✅
Settings → Employees (job title → role, PIN or email, branches, permissions)          ✅
Settings → POS devices → activate register on the till (token cookie)                 ✅
Products → create manually, or Import from Excel (map columns, preview, confirm)       ✅
        ↓
Opening stock:
   Option A — the `quantity` column in the product import               ✅
   Option B — Inventory → manual movement `إضافة كمية` per product      ⚠️ one at a time
   Option C — Purchase order → receive                                  ⚠️ needs a supplier
   ❌ There is no "opening balance" concept. Opening stock enters as an ordinary
      addition, indistinguishable from a purchase, and with no accounting counterpart:
      inventory exists physically and is worth 0 in the ledger.
        ↓
Chart of accounts is auto-seeded on first visit to /admin/finance/chart  ✅
   ❌ No opening equity / capital entry. The books start unbalanced against reality.
        ↓
Ready to sell.
```

**Steps a real shop must perform: ~9 screens, no wizard, no checklist, no "you are ready" signal.**

---

## Flow 2 — Purchasing

```
Suppliers → create supplier (name, phone, email, contact, notes)                    ✅
        ↓
Purchases → new PO: choose branch (required), supplier (optional), lines
            (product OR free-text name, cost, qty), optional receipt image attachment
        ↓  PurchaseOrderController::store
   ✅ purchase_orders (status = 'مُرسل') + purchase_order_items
   🩸 number = 'PO-' . random_int(10000,99999)  — no unique index
   ❌ no expected delivery date, no PO approval, no draft state (despite 'مسودة' in the UI filter)
        ↓
Goods arrive → Purchases → "Receive"
        ↓  PurchaseOrderController::receive
   ✅ products.quantity  +=  remaining
   ✅ branch_stocks       +=  remaining   (branch = PO branch)
   ✅ products.cost recalculated as a **weighted average** — correct and well-reasoned
   ✅ inventory_movements  (type 'إضافة كمية')
   ⚠️ received_quantity is forced to the full ordered quantity — partial delivery is impossible
   🩸 no DB transaction, no row locking
   ❌ no financial effect whatsoever: no payable, no ledger entry, no transaction
        ↓
Supplier bill arrives → Purchases → Invoices → record it
        ↓  SupplierInvoiceController::store
   ✅ supplier_invoices (subtotal + tax typed by hand, duplicate-ref guard)
   ✅ Ledger: Dr Inventory (total incl. tax)  /  Cr Payable
   ❌ the amount is NOT derived from what was received — the two numbers are independent
        ↓
Pay the supplier → "Pay" (full or partial, from cash or bank)
        ↓
   ✅ Ledger: Dr Payable / Cr Cash|Bank ; supplier_invoices.paid += ; status synced
   ✅ over-payment blocked
   ❌ no `transactions` row → **the payment does not appear in the Finance screen the
      merchant actually looks at**
        ↓
Where does the merchant see "what I owe supplier X"?
   ⚠️ Only on the Supplier Invoices screen, aggregated across all suppliers.
   ❌ The Suppliers screen shows no balance. There is no supplier statement.
```

---

## Flow 3 — Sales (POS)

```
Cashier opens /pos
   → device token cookie identifies the register and pins the branch      ✅
   → PosCashier::required() may ask "who is on the till?"                  ✅
   → shift may be open or not — selling is NEVER blocked (see below)       ⚠️
        ↓
Add items: grid tap / search / barcode scan                                ✅
   → client shows per-line VAT using each product's own rate               ✅
   → stock warning if the line exceeds the last known branch quantity      ✅
        ↓
Attach customer (optional) → points balance shown                          ✅
Apply coupon → server validates → discount returned                        ✅
Redeem points → capped by loyalty_redeem_max_pct and loyalty_redeem_min    ✅
        ↓
Payment dialog → method (only enabled ones) → amount tendered → change     ✅
   ❌ single payment method only — no split payment (cash + card)
   ❌ no partial payment / deposit
        ↓  POST /pos/checkout   (one DB transaction)
   1. shift gate — currently always passes (require_open_shift is '0')      ⚠️
   2. client_uuid idempotency check → returns the original invoice          ✅
   3. priceItems(lock: true) — prices re-read from DB, rows locked          ✅
   4. assertStock() against the **branch** balance, with FOR UPDATE         ✅
   5. coupon re-validated server-side; discount recomputed from our prices  ✅
   6. tax computed **line by line** with each product's rate, with the
      invoice discount apportioned across lines by value                    ✅
   7. inclusive-VAT: subtotal reduced by extracted tax                      ✅
   8. order number = prefix + zero-padded sequence, unique index + retry    ✅
   9. order_items written with a **cost snapshot** (profit history is frozen) ✅
  10. products.quantity −, branch_stocks −, inventory_movements ('بيع')     ✅
  11. transactions row (type 'دخل', method, tax_amount)                     ✅
  12. loyalty points earned + point_transactions                            ✅
  13. resumed hold deleted                                                  ✅
        ↓
   ❌ NO journal entry. No Dr Cash / Cr Sales / Cr VAT payable. No Dr COGS / Cr Inventory.
        ↓
Receipt prints (58mm / 80mm / A4 per template), QR if a VAT number is set  ✅
Owner may receive a "new order" email                                      ✅
```

**Offline:** if the network is down, the cart is queued in `localStorage` with a UUID and flushed on
reconnect; the server de-duplicates. ✅ This is genuinely good.

---

## Flow 4 — Returns

```
Customer walks in with a receipt and wants to return an item.
        ↓
❌ THERE IS NO RETURN FLOW.
```

**What exists instead:** the cashier opens the original invoice
(`/pos/orders/{number}`) and **edits it** via `Support\OrderCorrection::setQuantity()`:

```
   ✅ stock returned to the branch, inventory_movements (type 'تعديل فاتورة')
   ✅ invoice totals, discount cap, and per-line VAT recomputed
   ✅ linked `transactions` row amount + tax updated
   ✅ loyalty points adjusted by the delta (never below zero)
   ✅ an OrderEdit audit row with a mandatory reason
   ✅ the last line cannot be removed (that would be a disguised cancellation)
        ↓
🩸 The tax invoice already handed to the customer no longer matches the stored record.
🩸 A VAT period that has been declared is silently restated.
🩸 No credit note is produced; the customer has no document.
🩸 No cash is recorded as leaving the drawer — the shift's expected cash is now wrong.
🩸 `orders.shift_id` still points at the original (possibly closed) shift.
🩸 `sales_returns` (account 4900) exists in the chart and receives nothing, ever.
🩸 `shifts.returns` column is populated from **cash-in drawer movements**, not from returns.
```

**A full refund of a single-line invoice is impossible.** The only remaining option is to delete
nothing and leave the sale standing, or to manually record a cash-out drawer movement with a note.

---

## Flow 5 — Inventory

```
Receive stock              → PO receive  (branch of the PO)                 ✅
                           → or manual movement 'إضافة كمية'                ✅
Transfer between branches  → ❌ DOES NOT EXIST
Adjust (damage / loss)     → two competing screens:
     • Inventory → movement 'تلف' / 'خصم كمية' :
          guards the **branch** balance, writes a movement, **no ledger entry**
     • Inventory → Adjustments :
          guards the **company** total, writes stock_adjustments + movement
          + a journal entry (Dr other_expenses / Cr inventory),
          branch = whatever branch is selected in the header session
Stock count (stocktake)    → per-branch counted qty vs branch book;
                             delta applied to branch and company;
                             **shortage booked as an `Expense` row**;
                             **overage silently absorbed with no counterpart**   ⚠️🩸
Damaged / lost             → reason list on Adjustments: covered
Delivery note (no order)   → decrements stock                              ✅
Current stock              → Stock::availabilityResolver:
                             product has branch rows → this branch's balance;
                             product has no branch rows → company total     ✅
```

**Six write paths, three different rule sets, two different accounting treatments.**

---

## Flow 6 — Cash / shift

```
Cashier → /pos/shift → open with opening float                             ✅
   → one open shift per branch (locked, race-safe)                          ✅
   → a stale shift (> shift_max_hours, default 18) is auto-closed without a
     count rather than inherited                                            ✅
        ↓
Sales attributed via orders.shift_id                                        ✅
Cash in / cash out with a mandatory reason; cannot withdraw more than the
drawer holds                                                                ✅
   ❌ a cash-out does not create an Expense or a Transaction — petty cash
      leaves the business and never appears in Finance
        ↓
Close → cashier types the counted amount **without seeing the expected**    ✅
   → expected = opening + cash sales + net movements
   → difference frozen into the row; later invoice edits do not restate it   ✅
        ↓
❌ The owner has no screen to review shifts, variances, or a Z-report.
   Permissions::sectionFromRoute() routes `admin.shifts.*` to the `finance`
   section — but no such routes are registered.
```

---

## Flow 7 — Expenses

```
Expenses → new (type, amount, method, date, reference, due date, status, attachment) ✅
   → if status = مدفوع: an `Expense` row + (from the Finance screen path) a
     `transactions` row                                                     ✅
   → if unpaid: recorded as a liability-ish "bill" that does NOT reduce profit ✅
Mark paid → writes the transaction then                                     ✅
Delete → soft-deletes both the expense and its transaction together         ✅
Restore → brings both back with the same reference                          ✅
❌ No journal entry. Expenses never reach the double-entry books.
❌ No recurring expenses (rent, salaries) despite the sidebar calling the
   section "مصاريف شهرية" (monthly expenses).
```

---

## Flow 8 — Subscription lifecycle (platform)

```
Business created with plan + starts_at/ends_at                              ✅
Plan limits enforced at creation time (branches / employees / products)      ✅
07:30 daily → renewal warning emails as expiry nears                        ✅
Expiry day itself is still fully usable                                     ✅
After expiry → grace_days (default 7, operator-configurable)                ✅
   → red banner counting down                                              ✅
After grace → auto_suspend (operator-configurable) → soft hold:
   the merchant still logs in and lands on a page telling them what is owed
   and whom to contact — deliberately not thrown out at the door            ✅
Operator records a bank-transfer payment → renew() extends from the existing
   end date (early renewal is never punished) and issues an invoice          ✅
❌ No self-service renewal, no payment gateway, no card on file.
```

---

## Flow 9 — Multi-branch operation

```
Header branch switcher (session `current_branch`); "All branches" = a viewing
mode, never a selling location                                              ✅
POS register is hard-bound to its branch by device token                     ✅
Employees can be restricted to specific branches via branch_user             ✅
Orders, movements, adjustments, POs, shifts all carry branch_id              ✅
⚠️  `orders.branch` (varchar name) is stored **alongside** `branch_id` and can
    diverge if a branch is renamed.
⚠️  `users.branch` (varchar) exists alongside the `branch_user` pivot.
⚠️  `businesses.branches_count` is a manually-typed number shown in the platform
    console while the real branches live in the `branches` table.
❌ No stock transfer between branches.
❌ No per-branch pricing.
❌ No consolidated vs per-branch P&L (there is no P&L at all).
❌ Expenses have no branch_id — the stocktake shrinkage workaround writes the
   branch name into the description string.
```

---

## Flow 10 — Backup & restore

```
Settings → Backup → Download  → a JSON file of 17 tables                     ⚠️
02:00 nightly → the same payload stored for every business                   ⚠️
Settings → Backup → Restore   → deletes and re-inserts those 17 tables       🩸
```

**The backup covers 17 of ~40 tenant tables.** Branch stocks, the entire chart of accounts and
journal, supplier invoices, stock adjustments, delivery notes, payroll, fixed assets, bank accounts
and statements, point transactions, order edits, POS devices, peripherals, shift movements, addons,
job titles, custom alerts, reviews, customer addresses and branch assignments are **not backed up
and not restored** — while branches, products and customers *are* force-deleted during restore,
cascading several of those tables away. Details and the failure modes are in
`06-data-integrity-risks.md` R-01.

---

## Additional flows that exist

| Flow | Status |
|---|---|
| Hold / save cart and resume | ✅ Complete, coupon re-validated on resume |
| Product import with undo | ✅ Complete — best-in-class in this codebase |
| Customer / supplier import | ✅ Complete |
| Bank statement import & reconciliation | ✅ Complete |
| Fixed-asset depreciation run | ✅ Complete |
| Payroll draft → approve → pay | ✅ Complete |
| Impersonation (operator → merchant) | ✅ Complete, audited |
| Demo store creation / reseed | ✅ Complete, guarded against real tenants |
| Trash restore & scheduled purge | ✅ Complete |
| Invoice correction (qty + payment method) | ✅ Complete, audited — but used as a return substitute |
