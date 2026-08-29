# CUSTOMER-CORE-001 — Products + Inventory Audit

Status: **AUDIT COMPLETE — NO CODE CHANGED**
Task: `AI_COMMAND_CENTER/ACTIVE_TASK.md` (CUSTOMER-CORE-001)
Date: 2026-08-30
Repository state: `main` @ `43a90ee` (34 commits pulled from origin during this task)
Test baseline before audit: `php artisan test` → **1368 passed, 4382 assertions** (all green)

## How to read this document

Every finding below was traced UI → route → controller → model/service → database.
Findings marked **CONFIRMED (probe)** were reproduced with a throwaway PHPUnit probe that was
run and then deleted; the working tree is unchanged. Findings marked **CONFIRMED (code)** are
provable by reading the source and are stated with exact file/line references. Nothing here is
inferred from documentation — `docs/product-audit/` was read only to cross-reference, and where
it disagreed with the repository the repository won.

---

# 1. Products capability inventory

| # | Capability | Where | State |
|---|---|---|---|
| P-01 | List + paginate (12/page) | `ProductController@index` | PASS |
| P-02 | Search by name / SKU / barcode | `ProductController@index` :86-96 | PASS |
| P-03 | Filter by category | :97-99 | PASS |
| P-04 | Filter by active status | :100-102 | PASS |
| P-05 | Filter by stock state (متوفر/منخفض/نفد) | :103-106 | PASS (company-wide, see D-1) |
| P-06 | Filter "راكد" (no sale in 90 days) | :107-119 | PASS |
| P-07 | Server-side sort (name/price/cost/qty/active) | `Support\Sort` + `SORTS` :20-26 | PASS |
| P-08 | Create product | `@store` :163-220 | BUG (F-01, F-08) |
| P-09 | Edit product | `@update` :222-275 | BUG (F-02) |
| P-10 | View details | `PageController@productsShow` :27-53 | BUG (F-03, F-04) |
| P-11 | Soft delete + undo toast | `@destroy` :392-410 | PASS |
| P-12 | Restore from Trash | `TrashController@restore` :212 | BUG (F-05) |
| P-13 | Permanent purge (+ file + branch_stocks cleanup) | `TrashController@purgeRow` | PASS |
| P-14 | Duplicate product (qty reset to 0) | `@duplicate` :284-300 | PASS |
| P-15 | Inline quick edit (price/qty) | `@quickUpdate` :307-333 | PASS |
| P-16 | Bulk activate / deactivate / recategorise / reprice / delete | `@bulk` :340-390 | PASS |
| P-17 | Auto SKU (`FLW-#####`) + auto barcode (`628##########`) | :62-80 | PASS |
| P-18 | SKU/barcode uniqueness | validation only, **no DB index** | BUG (F-05) |
| P-19 | Per-item VAT override (null = follow shop) | `Product::taxRate` | PASS |
| P-20 | Per-item discount %, clamped 0-100 | `Product::discountRate/sellingPrice` | PASS |
| P-21 | Cost field + weighted-average update on receipt | `PurchaseOrderController@receive` :219-222 | PASS |
| P-22 | Image upload (`max:4096`, public disk) | :204-206 | BUG (F-01) |
| P-23 | Plan limit enforcement (`max_products`) | `PlanLimits::enforce` | BUG (F-09) |
| P-24 | Excel/PDF export (report shape) | `ReportExportController`, `PdfController` | PASS |
| P-25 | Excel/CSV export (round-trip shape) | `ProductImportExportController@exportXlsx/exportPdf` | PASS |
| P-26 | Import: upload → preview → remap → confirm → undo | `ProductImportExportController` | PASS (except F-09) |
| P-27 | Live stock feed for the products table | `@stockFeed` :40-54 | PASS |
| P-28 | **Category CRUD** | — | **UNWIRED (F-06)** |
| P-29 | **Product variants / options** | — | DEFER (does not exist anywhere) |
| P-30 | **Product description round-trip** | `Demo::products()` :743-777 | **BUG (F-02)** |
| P-31 | `active` flag honoured at the till | `Pos/Index.tsx` :140-147 | **BUG (F-07)** |

# 2. Inventory capability inventory

| # | Capability | Where | State |
|---|---|---|---|
| I-01 | Stock overview by product | `PageController@inventoryIndex` → `Demo::inventory()` | BUG (F-10) |
| I-02 | Per-branch quantities + company total | `Demo::inventory()` :1387-1412 | PASS |
| I-03 | Branch-aware availability resolver | `Support\Stock::availabilityResolver` | PASS |
| I-04 | Invariant "Σ branches = product.quantity" | `BranchStock` | BUG (F-11, F-12) |
| I-05 | Physical stocktake (count → variance → shrinkage expense) | `InventoryController@stocktake/applyStocktake` | BUG (F-13, F-14) |
| I-06 | Stock adjustments (damage/loss/correction) + ledger posting | `Inventory\StockAdjustmentController` | BUG (F-11, F-12) |
| I-07 | Manual stock movement (add / deduct / return / manual set) | `InventoryController@store` | **UNWIRED (F-15)** |
| I-08 | Goods receipt notes (read-only paper trail) | `Inventory\GoodsReceiptNoteController` | PASS |
| I-09 | Delivery notes (draft → deliver → cancel) | `Inventory\DeliveryNoteController` | PASS — reference implementation |
| I-10 | Purchase order create | `PurchaseOrderController@store` | BUG (F-16) |
| I-11 | Purchase order partial receive + GRN + weighted-average cost | `@receive` :131-287 | BUG (F-17) |
| I-12 | Purchase order delete | `@destroy` :314-325 | BUG (F-18) |
| I-13 | Payment-receipt attachment on a PO | `@uploadReceipt` | PASS |
| I-14 | POS sale deducts branch stock (locked, transactional) | `PosController@checkout` :607-641 | PASS |
| I-15 | POS invoice correction returns stock (guarded) | `Support\OrderCorrection` | BUG (F-11) |
| I-16 | Reorder suggestions | `Demo::reorderSuggestions()` | PASS |
| I-17 | Low / out-of-stock classification | `Product::statusFor` | PASS |
| I-18 | Negative-stock policy switch | `allow_negative_stock` | **UNWIRED (F-19)** |
| I-19 | **Inventory movement history screen** | — | **MISSING (F-15)** |
| I-20 | Supplier CRUD + import/export (inventory dependency) | `SupplierController`, `SupplierExportController` | PASS |
| I-21 | Supplier invoices (no stock effect by design) | `Purchasing\SupplierInvoiceController` | PASS |
| I-22 | Tenant isolation on every product/inventory query | all controllers scope `business_id` | PASS (except F-08) |
| I-23 | Branch/stock exports (xlsx, pdf, csv) | `ReportExportController`, `PdfController`, `ExportController` | PASS |

# 3. Page / action coverage matrix

51 registered routes fall in scope. All resolve to a real handler (verified against
`php artisan route:list`); `NoDeadLinksTest` and `EveryScreenOpensTest` already guard this.

### Products
| UI action | Route | Handler | Auth | Tenant | Verdict |
|---|---|---|---|---|---|
| Products list | `admin.products.index` | `ProductController@index` | `ability:products` | ✔ | PASS |
| New product form | `admin.products.create` | `PageController@productsCreate` | ✔ | ✔ | PASS |
| Save new product | `admin.products.store` | `ProductController@store` | ✔ | ✔ | F-01, F-08 |
| Product details | `admin.products.show` | `PageController@productsShow` | ✔ | ✔ | F-03, F-04 |
| Edit form | `admin.products.edit` | `PageController@productsEdit` | ✔ | ✔ | **F-02** |
| Save edit | `admin.products.update` | `ProductController@update` | ✔ | ✔ | F-08 |
| Delete | `admin.products.destroy` | `ProductController@destroy` | ✔ | ✔ | PASS |
| Restore | `admin.products.restore` | `TrashController@restore` | ✔ | ✔ | **F-05** |
| Purge | `admin.products.purge` | `TrashController@purge` | ✔ | ✔ | PASS |
| Duplicate | `admin.products.duplicate` | `ProductController@duplicate` | ✔ | ✔ | PASS |
| Inline edit | `admin.products.quick` | `ProductController@quickUpdate` | ✔ | ✔ | PASS |
| Bulk action | `admin.products.bulk` | `ProductController@bulk` | ✔ | ✔ | PASS |
| Live qty poll | `admin.products.stockFeed` | `ProductController@stockFeed` | ✔ | ✔ | PASS |
| Import (6 routes) | `admin.products.import.*` | `ProductImportExportController` | ✔ | ✔ | **F-09** |
| Export (4 routes) | `admin.products.{xlsx,exportPdf,export.xlsx,export.pdf}` | 3 controllers | ✔ | ✔ | PASS |
| CSV export | `admin.export.products` | `ExportController@products` | ✔ | ✔ | PASS |

### Inventory
| UI action | Route | Handler | Auth | Tenant | Verdict |
|---|---|---|---|---|---|
| Stock overview | `admin.inventory.index` | `PageController@inventoryIndex` | `ability:inventory` | ✔ | **F-10** |
| Stocktake screen | `admin.inventory.stocktake` | `InventoryController@stocktake` | ✔ | ✔ | PASS |
| Apply stocktake | `admin.inventory.stocktake.apply` | `InventoryController@applyStocktake` | ✔ | ✔ | **F-13, F-14** |
| Adjustments list + form | `admin.inventory.adjustments` | `StockAdjustmentController@index` | ✔ | ✔ | F-20 |
| Save adjustment | `admin.inventory.adjustments.store` | `StockAdjustmentController@store` | ✔ | ✔ | **F-11, F-12** |
| Goods receipts | `admin.inventory.receipts` | `GoodsReceiptNoteController@index` | ✔ | ✔ | PASS |
| Delivery notes (5 routes) | `admin.inventory.deliveries*` | `DeliveryNoteController` | ✔ | ✔ | PASS |
| Manual movement | `admin.inventory.store` | `InventoryController@store` | ✔ | ✔ | **F-15 — no caller** |
| Exports (3 routes) | `admin.inventory.{xlsx,exportPdf}`, `admin.export.inventory` | ✔ | ✔ | PASS |

### Purchasing (inventory dependency)
| UI action | Route | Handler | Verdict |
|---|---|---|---|
| Purchase register | `admin.purchases.index` | `PurchaseRegisterController@index` | PASS |
| PO list | `admin.purchases.orders` | `PurchaseOrderController@index` | PASS |
| New PO form | `admin.purchases.create` | `PageController@purchasesCreate` | PASS |
| Save PO | `admin.purchases.store` | `PurchaseOrderController@store` | **F-16** |
| Receive PO | `admin.purchases.receive` | `PurchaseOrderController@receive` | **F-17** |
| Attach receipt | `admin.purchases.receipt` | `@uploadReceipt` | PASS |
| Delete PO | `admin.purchases.destroy` | `@destroy` | **F-18** |
| Supplier invoices (3 routes) | `admin.purchases.invoices*` | `SupplierInvoiceController` | PASS |
| Suppliers (9 routes) | `admin.suppliers.*` | `SupplierController`, `SupplierExportController` | PASS |

**Authorization note.** Every route above sits behind `web → auth → tenant → business → panel → ability`.
`Permissions::sectionFromRoute()` derives the ability from the second name segment, so
`admin.products.*` → `products` and `admin.inventory.*` → `inventory`. `admin.purchases.*` → `purchases`,
`admin.suppliers.*` → `suppliers`. The `inventory` role grants exactly
`dashboard, products, inventory, suppliers, purchases, pos, preparation` — coherent. No gap found.

# 4. Full-stack wiring status

Everything in scope is wired end-to-end **except**:

1. `admin.inventory.store` — registered, controller implemented (74 lines, branch-aware, guarded),
   **zero frontend references**. Confirmed by grepping all of `resources/js`.
2. Category create/rename/delete — **no route exists at all**. `Category` is written in only two
   places: `BusinessTypes` (seeded once when a business is created) and the product importer.
3. `allow_negative_stock` — read in 2 places, **written nowhere**; absent from
   `SettingController::KEYS` and from every `.tsx` file.
4. Inventory movement history — no GET route, no screen. `InventoryMovement` rows are written by
   6 different code paths and are readable only as the last 6 entries on a product's detail page.

Residue of removed features found while tracing:
- `PageController` :68-79 — an orphan `/* التصنيفات */` section header and a `private const PALETTE`
  that nothing reads (dead code left behind when category management was removed).
- `categories.parent_id` — column exists, no model relation, no UI. Dead column.

# 5. POS integration points

| # | Point | Mechanism | Verdict |
|---|---|---|---|
| POS-1 | Catalogue load | `Pos/PageController@index` → `Demo::products(activeBranchId())` | **F-07** (returns inactive products) |
| POS-2 | Live quantity poll | `pos.stock-feed` → `PosController@stockFeed` — branch-aware | PASS |
| POS-3 | Cart re-pricing from DB (never trusts client price) | `PosController::priceItems` :154-206 | PASS |
| POS-4 | Row locking before stock check | `priceItems($items, lock: true)` + `orderBy('id')->lockForUpdate()` | PASS |
| POS-5 | Availability assertion against **branch** balance | `assertStock` :208-247 | PASS |
| POS-6 | Stock deduction inside `DB::transaction` | :607-641 | PASS |
| POS-7 | `ensureAllocated` before mutation | :628-630 | PASS (correct order) |
| POS-8 | Per-item VAT parity screen↔server | `Vat::rateFor` used by both | PASS (guarded by `PosTaxParityTest`) |
| POS-9 | Per-item discount applied | `Product::sellingPrice()` | PASS |
| POS-10 | Invoice correction returns stock through the same guard | `OrderCorrection::applyStockDelta` | **F-11** (ordering) |
| POS-11 | Offline queue replay | `PurchaseAndOfflineTest` | PASS |
| POS-12 | Tenant isolation at the till | `PosTenantIsolationTest` | PASS |

The POS write path is the **strongest** code in scope: it locks, it re-prices server-side, it judges
availability on the branch balance, and it wraps everything in one transaction. Two of the three
consumer-facing defects it has (F-07, F-11) originate outside `PosController`.

# 6. Findings

Severity: **P0** = prevents safe launch · **P1** = core operational defect · **P2** = important secondary · **P3** = polish.

---

## P0

### F-11 · `ensureAllocated` called *after* the quantity changes — inflates stock by the whole delta
**CONFIRMED (probe).** `products.quantity=5` vs `Σ branch_stocks=10` after one +5 adjustment.

Seven code paths mutate stock. Five call `BranchStock::ensureAllocated($bid, $id, $preChangeQty)`
**before** the mutation. Two call it **after**, passing the already-mutated quantity:

- `app/Http/Controllers/Admin/Inventory/StockAdjustmentController.php` :150-154
- `app/Support/OrderCorrection.php` :145-149

Correct examples for contrast: `PurchaseOrderController.php` :200-203, `PosController.php` :628-630,
`DeliveryNoteController.php` :322-324, `ProductController.php` :266-269 and :324-326.

**Failure scenario.** A product with no `branch_stocks` row yet (any product created with quantity 0 —
which includes *every* product made with the "duplicate" button, since it deliberately resets quantity
to 0). Adjust it by +5 with a branch selected: `increment` sets `products.quantity = 5`;
`ensureAllocated` then sees no rows and creates one holding **5**; `adjust` adds **5** again → branch
total 10. The invariant that the whole POS depends on is now broken by exactly the delta, silently,
with no error and no report showing it.

**Minimal fix.** Move the `ensureAllocated` call above the `increment`/mutation in both files and pass
the pre-change quantity — i.e. make them match the five call sites that already do it correctly.

---

### F-12 · Stock adjustments in "All branches" mode move the company total and no branch
**CONFIRMED (code).** `StockAdjustmentController@store` :138, :152, :159.

The screen uses `Demo::currentBranchId()`, which returns **null** when the branch selector is on
"كل الفروع" — and that is the session default. The consequences in that mode:
- `StockAdjustment.branch_id` is saved as `null`
- `$product->increment('quantity', $delta)` still runs
- `if ($branchId = Demo::currentBranchId())` is false → **`branch_stocks` is never touched**
- the ledger entry is posted with a null branch

Every other write path in the section requires an explicit branch: `applyStocktake` validates
`branch_id => required`, `InventoryController@store` validates it, `PurchaseOrder` carries one.
`Adjustments.tsx` is the only stock-writing screen with **no branch selector at all** — `branch`
appears there solely as a read-only display column.

**Minimal fix.** Add a required branch field to the adjustments form and validate `branch_id` in
`store` (mirroring `applyStocktake` :46-57), or fall back to `Demo::activeBranchId()`. The negative
guard at :125 must move with it — see F-21.

---

### F-13 · Stocktake is not transactional and is O(n²)
**CONFIRMED (code).** `InventoryController@applyStocktake` :44-134.

Two separate defects in one loop:

1. **No `DB::transaction`.** The loop performs 3-4 writes per product (branch adjust, product save,
   movement insert) with no wrapper. A timeout or error midway leaves the count **half-applied** —
   some products reconciled, some not, and no way to tell which. The shrinkage expense at :116 is
   then written for only the products that made it through.
2. **`BranchStock::bookOf()` is called once per product** (:79), and `bookOf` calls `books()`, which
   loads **every** `branch_stocks` row **and every product** of the business (`BranchStock.php`
   :books). For a 500-SKU catalogue that is 500 full table loads inside one request.

The stocktake is the moment a merchant decides whether to trust the system's stock figures. Both
failure modes corrupt exactly that.

**Minimal fix.** Hoist `BranchStock::books($bid)` to one call before the loop and index into it; wrap
the loop body in `DB::transaction`.

---

### F-07 · A deactivated product can still be sold at the till
**CONFIRMED (probe).** Checkout returned **200** and the inactive product **was sold**.

Three layers all fail to honour `products.active`:
- `Demo::products()` (`app/Support/Demo.php` :743-777) returns every product, active or not.
- `resources/js/Pages/Pos/Index.tsx` :140-147 — `visibleProducts` filters by category and search
  only. Addons *are* filtered (`activeAddons`, :138); products are not.
- `PosController::priceItems` :174-184 — checks the product exists and belongs to the business,
  but never checks `active`. Addons *are* checked (`! $addon->active`, :191).

So the "مفعّل / معطّل" toggle in the product form changes a filter in the admin list and nothing
else. A merchant who deactivates a discontinued line, a seasonal item, or a product under recall
will still see it on the till and still sell it.

**Minimal fix.** Add `active` to the client filter *and* reject inactive products in `priceItems`
(one condition, mirroring the addon branch immediately below it). Server-side is the one that
matters; the client filter is the visible half.

---

## P1

### F-02 · Product descriptions are silently wiped on every edit
**CONFIRMED (probe).** Stored `'وصفٌ كتبه التاجر'`; the edit page sent `''`.

`Demo::products()` (:743-777) builds a 16-key array — `id, name, name_en, label, cat, price, cost,
qty, sku, barcode, image, stock_status, active, alert, tax, discount`. **`description` is not among
them.** `Demo::product($id)` (:2404) is `findById(self::products(), $id)`, so it inherits the gap.

`PageController@productsEdit` :63 does `'description' => $product['description'] ?? ''` → always `''`.
`ProductForm.tsx` :73 initialises `description: description ?? ''` → always empty.
`ProductController@update` accepts and saves `description`.

Net effect: write a description, save, reopen the product, change the price, save — **the description
is gone.** The merchant is never told. This is the exact failure class the repo's own
`SilentDataLossTest` exists to prevent, and it is not covered there.

**Minimal fix.** Add `'description' => $p->description` to the `Demo::products()` map.

---

### F-05 · Restoring from Trash can resurrect a duplicate barcode
**CONFIRMED (probe).** Two live products ended up sharing barcode `6281234567890`.

`ProductController` :179-180 scopes uniqueness with `->whereNull('deleted_at')`, so a soft-deleted
product releases its SKU and barcode. `TrashController@restore` :212-216 calls `$row->restore()`
with **no uniqueness re-check**. And `products` has **no unique index** on `sku` or `barcode` —
verified against all 73 migrations; the constraint exists only in validation.

Sequence: delete product A (barcode X) → create product B with barcode X (validation passes) →
restore A from Trash → two live products share barcode X.

The code comment at `ProductController` :171-177 states precisely why this must not happen: the
scanner picks one of them, stock is deducted from the wrong product, and the difference surfaces
later in a stocktake with no traceable cause.

**Minimal fix.** In `TrashController@restore`, when `$type === 'product'`, null out (or suffix) the
SKU/barcode if a live product already holds it, and tell the user in the toast.

---

### F-16 · Purchase-order numbers are random with no unique index — ~50% collision by the 350th PO
**CONFIRMED (code).** `PurchaseOrderController@store` :92 — `'PO-' . random_int(10000, 99999)`.
`purchase_orders.number` is declared `->index()`, **not** `->unique()`
(`2026_07_19_000060_suppliers_purchases_receivables.php` :27).

`purchase_orders` is the **only** numbered document left on this pattern. Every other one already
uses a sequential `nextNumber()` **plus** `unique(['business_id','number'])`:
`orders`, `transactions`, `journal_entries`, `delivery_notes`, `goods_receipt_notes`,
`stock_adjustments`, `payroll_runs`, `fixed_assets`.

90,000 possible values → P(collision) ≥ 50% at ~353 purchase orders. Two POs then carry the same
number on paper, in the GRN that references them, and in the supplier's records.

**Minimal fix.** Copy the existing `nextNumber()` pattern (e.g. `GoodsReceiptNote::nextNumber`) onto
`PurchaseOrder`, then add `unique(['business_id','number'])` in a migration after de-duplicating any
existing collisions.

---

### F-10 · The inventory screen overwrites branch quantities with company totals seconds after load
**CONFIRMED (code).** `resources/js/Pages/Admin/Inventory/Index.tsx` :40.

The page renders `Demo::inventory()`, whose `qty` is the **selected branch's** balance
(`Demo.php` :1398). It then polls `route('admin.products.stockFeed')` — the **products** feed, whose
`qty` is `$p->quantity`, the **company-wide** total (`ProductController@stockFeed` :42-48).

`useLiveStock` replaces the server snapshot with the feed, and :44-48 recomputes `value` from the
replaced `qty`. So with a branch selected, both the quantity column and the stock-value column jump
from branch figures to company figures a few seconds after the page opens — while the branch
selector still names the branch. The total-value figure at the top changes with them.

A branch-aware feed already exists and is used correctly by the POS: `route('pos.stock-feed')`.

**Minimal fix.** Make `ProductController@stockFeed` branch-aware (it can reuse
`Stock::availabilityResolver` exactly as `PosController@stockFeed` :115 does), or point the inventory
page at a feed that already is. Note `Products/Index.tsx` uses the same feed and *is* company-wide,
so the two callers disagree about what the feed should mean — decide that first.

---

### F-01 · Every product created without an image gets a picsum.photos URL written to the database
**CONFIRMED (code).** Two write sites and one read fallback:
- `ProductController@store` :206 — `Demo::image('prod' . uniqid())`
- `ProductImportExportController@confirm` :414 — same call, for every imported row
- `Product::getImageAttribute` :89 — `https://picsum.photos/seed/prod{id}/400/400`
- `Demo::image()` :156 — `https://picsum.photos/seed/{seed}/{w}/{h}`

This is not a placeholder rendered at display time; it is **persisted** as the product's image. It
then travels wherever the product does: the POS grid, invoices, receipts, PDF exports, and the
XLSX round-trip. A merchant's catalogue is served from a third-party random-image service, over the
network, with the image changing meaning nothing.

This is the same defect already fixed for `Business::getLogoAttribute` (commit history: the fake
logo was removed because `BackupService` persisted it as a real value on restore). The product
equivalent was left in place.

It also contradicts the standing project rule that no fake or demo data may reach production.

**Minimal fix.** Store `null` when no file is uploaded, and let the UI render its own local
placeholder. A data migration should null out existing `picsum.photos` values.

---

### F-09 · Product import bypasses the plan's `max_products` cap
**CONFIRMED (code).** `PlanLimits::enforce(..., 'products')` is called in exactly two places, both in
`ProductController` (`@store` :192, `@duplicate` :287). `ProductImportExportController@confirm`
(:338-497) creates products with `Product::create(...)` at :413 and **never calls it**.

`MAX_ROWS` is 2000. A merchant on a plan capped at 50 products can import 2000 in one upload. The cap
is sold and enforced at the "+ New product" button only.

**Minimal fix.** Check `PlanLimits::cap(...)` against `used + $added` before the transaction in
`confirm()` and refuse (or truncate with an explicit message) when the import would exceed it.

---

### F-15 · Manual stock movements are implemented but unreachable; there is no movement-history screen
**CONFIRMED (code).**

`POST admin/inventory/movements` → `InventoryController@store` (:138-211) is a complete, branch-aware,
guarded implementation supporting `إضافة كمية`, `خصم كمية`, `مرتجع`, `تلف`, and `تعديل يدوي`. **No
file under `resources/js` references `admin.inventory.store`.** There is no form for it.

Separately, `inventory_movements` rows are written by six paths (POS sale, PO receipt, delivery note,
stocktake, adjustment, order correction) and there is **no screen that lists them**. The only reader
is `PageController@productsShow` :45-48, which shows the last 6 for one product — matched by
**product name string**, not `product_id`, over `Demo::movements()` which loads the business's
**entire** movement history on every product page view.

So: "inventory movements/history" — an explicit scope item — has an audit trail being written and
no way to read it, plus a receiving/return form that exists in code and not in the product.

**Minimal fix (two separable pieces).** (a) Decide whether the manual-movement form should ship; if
yes, add the screen, if no, remove the route so it stops implying coverage. (b) Add a paginated
movements list filtered by `product_id`/branch/date, and change the product-detail filter from name
to `product_id`.

---

## P2

### F-08 · `category_id` is accepted without a tenant check
`ProductController@store` :169 and `@update` :229 validate `category_id` as
`['nullable','integer']` only. `@bulk` :362 **does** verify ownership — the three disagree.

A merchant can post another business's `category_id`. The FK only requires the row to exist. Result:
the foreign category's name is displayed on the product, and `Demo::categories()`'s
`withCount('products')` (:712) counts across businesses, so tenant A can inflate tenant B's counts.

**Minimal fix.** `Rule::exists('categories','id')->where('business_id', $this->bid())` in both
methods — the same rule `StockAdjustmentController` :107 already uses for `product_id`.

### F-03 · Every product without a description shows a florist's marketing blurb
`PageController@productsShow` :50-51 falls back to
`'باقة أنيقة من الورود الطبيعية الطازجة … لتمنح لمسة جمالية مميزة.'`

A pharmacy, a bookshop, a hardware store — any tenant — sees this on any product with no
description. Which, because of F-02, is **every** product whose description was ever edited away.

**Minimal fix.** Show nothing (or an empty state) when there is no description.

### F-14 · Stocktake clamps the company total at zero while moving the branch by the full delta
`InventoryController@applyStocktake` :86 — `$product->quantity = max(0, $product->quantity + $delta)`.
Same pattern at `@store` :195. `BranchStock::adjust` deliberately does **not** clamp (its own doc
comment explains why: a clamped branch balance breaks the invariant invisibly). Clamping the company
total reintroduces exactly that asymmetry on the other side of the equation.

**Minimal fix.** Drop the `max(0, …)`; a negative total is a signal that must be visible, which is the
rule `BranchStock` already states for branch balances.

### F-21 · The adjustment negative-guard checks the wrong scope
`StockAdjustmentController@store` :125 compares `abs($delta)` against `$product->quantity`, the
**company** total, while the write at :154 hits a **branch** balance. With a branch selected you can
therefore drive that branch negative as long as the company has enough elsewhere. The equivalent
guard in `InventoryController@store` :182 correctly uses the branch book.

### F-22 · `InventoryController@store` accepts an arbitrary movement type and defaults to deducting
:143 validates `'type' => ['required','string','max:50']` — any string. The `match` at :169-173 has
`default => -abs($quantity)`, so an unrecognised type **silently deducts stock** and writes the
attacker/typo-supplied string into the movement log. Currently mitigated only by F-15 (no UI reaches
it). **Minimal fix:** `Rule::in([...])` over the five real types.

### F-17 · Purchase receipt: the "already received" check sits outside the transaction and takes no lock
`@receive` :136 reads `$po->status` before `DB::transaction` opens at :188, and neither the PO nor its
items are locked. Two concurrent receipts (a double-click, a retried request) can both pass the check
and both increment stock and `received_quantity`.

`DeliveryNoteController@deliver` :279-290 shows the correct pattern in the same codebase: open the
transaction first, `lockForUpdate()->findOrFail()`, *then* test the status. **Minimal fix:** mirror it.

### F-18 · Deleting a purchase order destroys its receipt paper trail but not the stock it added
`@destroy` :314-325 hard-deletes the PO with no status guard. `goods_receipt_notes.purchase_order_id`
is a constrained FK; `purchase_order_items` cascades. So deleting a received PO removes the GRNs that
document the goods, while the stock those goods added stays on the shelf and in the books.

**Minimal fix.** Refuse to delete a PO whose status is `مستلم` or `مستلم جزئيًا` — the same rule
`DeliveryNoteController@destroy` :374 already applies to delivered notes.

### F-19 · `allow_negative_stock` is read but can never be set
Read at `PosController` :210 and `OrderCorrection` :128-129. **Written nowhere** — not in
`SettingController::KEYS` (verified: 34 keys, not among them), not in any `.tsx`. It is permanently
`'0'`. The default is the safe one, so this is a missing capability rather than a hazard: a
made-to-order shop (flowers, bakery, tailoring) has no way to enable back-ordering.

**Minimal fix.** Either add the key to `KEYS` with a switch in the POS settings card, or delete the
two read sites so the code stops implying a setting exists.

### F-06 · Categories cannot be created, renamed, or deleted from anywhere in the product
**CONFIRMED (code).** No `admin.categories.*` route exists (verified against all 316 registered
routes). `Category::create` appears in exactly two places: `BusinessTypes` :99 (seeded once at
business creation) and `ProductImportExportController` :390 (auto-created from a spreadsheet column).

A merchant's category list is therefore fixed at signup unless they import a file. `Category` also
carries `icon`, `color`, and `parent_id` columns that `Demo::categories()` reads and no screen can
edit. `PageController` :68-79 still holds the orphaned `/* التصنيفات */` header and an unused
`PALETTE` constant — the residue of the removed screen.

This is scoped explicitly in the brief ("categories/types/variants/options if implemented"). It is a
capability gap, not a defect; sizing it is an owner decision.

### F-20 · Unbounded queries on hot paths
- `StockAdjustmentController@index` :62 — `StockAdjustment::where(...)->get()` loads **every**
  adjustment ever, on every page load, purely to compute three summary numbers. Should be three
  aggregate queries.
- `Demo::inventory()`, `Demo::movements()`, `Demo::products()` — all `->get()` with no limit.
  `movements()` grows without bound and is loaded in full for a product page that shows 6 rows.
- `Demo::product($id)` :2404 loads the whole catalogue to find one row.
- `ProductController@stockFeed` returns every product every poll cycle.

None of these fail today; all of them degrade linearly with catalogue size and trading history.

## P3

- **F-23** · `ProductForm.tsx` :74 binds the category by **name** (`categories.find(c => c.name === product?.cat)`),
  not by id. Duplicate category names — which the importer can create — bind the wrong one, and a
  product whose category was renamed silently resets to "no category" and is saved that way.
- **F-24** · `StockAdjustmentController` :108 validates `quantity_delta` as `numeric` (fractions
  allowed), then writes it to `products.quantity` (an **integer** column) via `increment`, and to
  `branch_stocks` via `(int) $delta` at :154. A delta of `0.5` casts to `0` for the branch and is
  truncated for the product — a third way for the two to diverge.
- **F-25** · `InventoryController@applyStocktake` :62 tests `$counted === ''` after
  `counts.* => integer` has already run — unreachable branch.
- **F-26** · `PageController` :74-79 — dead `PALETTE` constant; `categories.parent_id` — dead column.
- **F-27** · `PurchaseOrderController@store` :56 validates `supplier_id` with a global
  `exists:suppliers,id`; :86 then re-checks tenancy and silently drops a foreign id to `null`. No
  leak, but the PO saves with no supplier and no message.

# 7. Affected files index

| File | Findings |
|---|---|
| `app/Http/Controllers/Admin/Inventory/StockAdjustmentController.php` | F-11, F-12, F-20, F-21, F-24 |
| `app/Http/Controllers/Admin/InventoryController.php` | F-13, F-14, F-22, F-25 |
| `app/Http/Controllers/Admin/ProductController.php` | F-01, F-08, F-20 |
| `app/Http/Controllers/Admin/ProductImportExportController.php` | F-01, F-09 |
| `app/Http/Controllers/Admin/PageController.php` | F-03, F-15, F-26 |
| `app/Http/Controllers/Admin/PurchaseOrderController.php` | F-16, F-17, F-18, F-27 |
| `app/Http/Controllers/Admin/TrashController.php` | F-05 |
| `app/Http/Controllers/Pos/PosController.php` | F-07 |
| `app/Support/OrderCorrection.php` | F-11 |
| `app/Support/Demo.php` | F-01, F-02, F-07, F-20 |
| `app/Models/Product.php` | F-01 |
| `app/Http/Controllers/Admin/SettingController.php` | F-19 |
| `resources/js/Pages/Pos/Index.tsx` | F-07 |
| `resources/js/Pages/Admin/Inventory/Index.tsx` | F-10 |
| `resources/js/Pages/Admin/Inventory/Adjustments.tsx` | F-12 |
| `resources/js/Pages/Admin/Products/partials/ProductForm.tsx` | F-23 |
| `database/migrations/2026_07_19_000060_suppliers_purchases_receivables.php` | F-16 |
| `database/migrations/2026_07_18_000010_create_abadpos_tables.php` | F-05 (no unique index), F-26 |
| `routes/web.php` | F-06 (no category routes), F-15 (orphan route :396) |

# 8. Existing automated-test coverage

**Full suite: 1368 tests, 4382 assertions, all passing.** In-scope files: 19 test classes, 156 tests,
508 assertions — also all passing. **None of the 27 findings above is caught by any of them.**

Strong coverage already in place: `BranchStockTest` (12), `InventoryIntegrityTest` (13),
`StocktakeTest` (9), `ProductImportSafetyTest` (21), `ProductActionsTest` (14),
`InventoryDocumentsTest` (12), `GoodsReceiptTest` (10), `DeliveryNoteAuditTest` (9),
`ProductPricingTest` (8), `PosStockFeedTest` (5), `PurchaseAndOfflineTest` (10).

## Two existing tests are vacuous — they assert nothing
**CONFIRMED (probe): both requests were rejected before reaching their controller; `orders=0, movements=0`.**

`tests/Feature/StockStaysBalancedTest.php` is the guard for the single most important invariant in
the section. Two of its eight doors never open:

1. `test_a_pos_sale_keeps_it_balanced` (:67) posts
   `items => [['product_id' => …, 'quantity' => 3, 'price' => 10]]`.
   The checkout contract (`PosController` :465-471) is `items.*.id`, `items.*.name` (**required**),
   `items.*.qty`. The request returns **HTTP 422**; no order is created. The assertion then compares
   100 to 100 and passes.
2. `test_a_stocktake_keeps_it_balanced` (:123) posts `counts => [['product_id' => …, 'counted' => 55]]`
   with **no `branch_id`**, which `applyStocktake` :47 requires. The request returns **302** with
   validation errors; nothing is written. Same trivially-passing assertion.

`StocktakeTest` :70-73 uses the correct shape (`branch_id` + `counts: {id => counted}`), which is what
makes the divergence provable rather than a matter of opinion.

Neither test calls `assertSessionHasNoErrors()`, which is what lets both fail open. The other six
doors in that file do exercise their controllers.

## Missing tests (highest value first)

| # | Test | Would have caught |
|---|---|---|
| T-1 | Balance invariant for a product with **no** `branch_stocks` row (all 7 write paths) | **F-11** |
| T-2 | Fix the two vacuous tests + add `assertSessionHasNoErrors()` to all eight | F-13 regressions |
| T-3 | Adjustment posted with no branch selected must not break the invariant | **F-12** |
| T-4 | An inactive product is refused at `pos.checkout` | **F-07** |
| T-5 | Edit page round-trips `description` unchanged | **F-02** |
| T-6 | Restore from Trash cannot produce two live products with one barcode | **F-05** |
| T-7 | Import refuses to exceed `max_products` | **F-09** |
| T-8 | 400 sequential PO creations yield 400 distinct numbers | **F-16** |
| T-9 | `category_id` from another business is rejected | **F-08** |
| T-10 | Concurrent/repeated `purchases.receive` adds stock once | F-17 |
| T-11 | Stocktake of 200 SKUs stays within one transaction and a bounded query count | F-13 |
| T-12 | No product row in any API response carries a `picsum.photos` URL | **F-01** |

# 9. UNVERIFIED RUNTIME ITEMS

These need a browser, a real device, or production-scale data. They are **not** counted as findings.

1. **Stocktake at real catalogue size** — F-13 predicts a timeout above roughly 500 SKUs. Needs
   measuring against MySQL/PostgreSQL with a realistic catalogue; the test suite runs on SQLite,
   which serialises writes and hides both the O(n²) cost and the missing transaction.
2. **Concurrent POS terminals** — `lockForUpdate` behaviour differs between SQLite (whole-database
   lock, so races cannot appear) and PostgreSQL/MySQL. The POS locking looks correct; it has not been
   observed under genuine concurrency. Same for F-17.
3. **F-10 visually** — confirmed by reading both feeds; the several-second delay before the numbers
   change should be seen once on a multi-branch store to confirm the user-visible symptom.
4. **Barcode scanner behaviour with the F-05 duplicate** — which of the two products the hardware
   selects is unobserved; the data defect itself is confirmed.
5. **Image loading with `picsum.photos` blocked** — behaviour on a shop network that blocks the
   domain, and on the printed/PDF invoice path, is unverified.
6. **Import of a 2000-row file** — session-stored raw rows plus `MAX_ROWS` memory behaviour under a
   real PHP memory limit.
7. **Product deep-links after F-02** — whether any merchant descriptions already exist in production
   that would be recovered by fixing the map, or whether they have already been wiped.

# 10. Readiness verdict — PRODUCTS

## NOT READY

Three defects make the section unsafe to hand to a paying merchant:

- **F-07** — the enable/disable switch does nothing at the till. A discontinued or recalled item
  keeps selling. A control that appears to work and does not is worse than no control.
- **F-02** — the product description is destroyed by the act of editing the product, silently.
- **F-05** — the Trash can manufacture duplicate barcodes, and the database has no index to stop it.

**F-01** (fake image URLs written into merchant data) and **F-09** (plan cap bypassed by import) are
close behind: the first is a data-integrity and credibility problem that also breaches the standing
no-fake-data rule; the second is a commercial control that is only half enforced.

Everything else in Products is solid — the list, filters, sorting, bulk actions, quick edit,
duplication, soft delete with undo, purge with file cleanup, and the whole import/preview/remap/undo
pipeline are well built and well tested.

**Products becomes READY AFTER LISTED FIXES once F-07, F-02, F-05, F-01 and F-09 are closed**, with
F-08, F-03 and F-23 following as a second batch. F-06 (no category management) is a **scope decision
for the owner**, not a defect — it should be settled before launch because it is visible on day one.

# 11. Readiness verdict — INVENTORY

## NOT READY

The invariant that the whole module rests on — `Σ branch_stocks = products.quantity` — is breakable
from two ordinary screens, silently, with no error and no report that would surface it:

- **F-11** — reproduced: one +5 adjustment on a product with no branch row leaves the total at 5 and
  the branches at 10. Reachable through the "duplicate product" button, which creates every copy with
  quantity 0 and therefore no branch row.
- **F-12** — an adjustment made in the default "All branches" view moves the company total and no
  branch at all. The adjustments screen is the only stock-writing screen with no branch selector.
- **F-13** — the stocktake, the operation that exists to *restore* trust in stock figures, is neither
  transactional nor scalable, and can leave a count half-applied with no record of where it stopped.

Compounding this, the section's own regression guard has two doors that never open (§8), so the
invariant has been less protected than the suite's green result suggests.

The module also has a **capability hole**: stock movements are recorded by six paths and readable by
none (F-15), and the manual receive/return/adjust form exists in the controller with no screen.

**Inventory becomes READY AFTER LISTED FIXES once F-11, F-12, F-13, F-10 and F-16 are closed and the
two vacuous tests are repaired.** The purchasing chain (F-17, F-18) should follow immediately after.

**What is already strong and should not be touched:** `DeliveryNoteController` is the reference
implementation in this codebase — transaction first, `lockForUpdate`, status check inside the lock,
availability judged on the branch balance, refusal to un-deliver goods that have left. The POS write
path is nearly as good. Partial purchase receipt with weighted-average costing and per-batch GRNs is
correct. `Support\Stock`, `BranchStock::books`, and the `ensureAllocated` design are sound — the two
P0s are call-order mistakes against a correct design, not design faults.

# 12. Proposed implementation batches

Ordered by launch importance. Each batch is independently shippable and independently testable.
**None of this is authorised yet** — per `EXECUTION_PROTOCOL.md` §Planning boundary, these await the
owner's approval.

### Batch 1 — Stop the silent corruption (P0)
F-11 · F-12 · F-13 · F-14
Move two `ensureAllocated` calls above their mutations; add a required branch to the adjustments
screen and validate it; hoist `books()` out of the stocktake loop and wrap it in a transaction; drop
the two `max(0, …)` clamps. Ship with T-1, T-3, T-11 and the T-2 repair of the vacuous tests.
*Small, surgical, high value. Touches 3 files.*

### Batch 2 — Controls that must actually control (P0/P1)
F-07 · F-02 · F-05 · F-09
Honour `active` at the till (server and client); add `description` to the `Demo::products()` map;
re-check SKU/barcode on restore; enforce the plan cap in the importer. Ship with T-4, T-5, T-6, T-7.
*Four independent one-to-three-line fixes. Highest merchant-visible value per line changed.*

### Batch 3 — Merchant data must be the merchant's (P1)
F-01 · F-03
Stop writing `picsum.photos` at both write sites, drop the read fallback, render a local placeholder
instead; remove the florist blurb. Include a data migration nulling existing picsum values.
Ship with T-12.
*Do this before any demo to a real prospect.*

### Batch 4 — Numbering and the purchase chain (P1/P2)
F-16 · F-17 · F-18
Give `PurchaseOrder` a `nextNumber()` and a unique index (de-duplicate first); move the receive
status-check inside the transaction behind a lock; refuse to delete a received PO. Ship with T-8, T-10.

### Batch 5 — Say what the screen is showing (P1/P2)
F-10 · F-20
Decide what `admin.products.stockFeed` means, make the inventory page consistent with it, and replace
the three unbounded summary queries with aggregates.

### Batch 6 — Tenant hygiene and dead surfaces (P2/P3)
F-08 · F-21 · F-22 · F-23 · F-24 · F-25 · F-26 · F-27
Scope `category_id`; fix the adjustment guard's scope; constrain the movement `type` enum; bind the
category by id; make the delta integral; remove the dead `PALETTE`, the unreachable branch, and the
orphan route. Ship with T-9.

### Owner decisions (not batches)
- **F-06** — should merchants be able to manage their own categories before launch? Today they
  cannot, and the seeded list is all they will ever have unless they import a file.
- **F-15** — should the manual stock-movement form ship, and should there be a movement-history
  screen? The audit trail is being written either way.
- **F-19** — should `allow_negative_stock` become a real setting, or should the two read sites be
  removed?

# 13. Deviations from the task brief

1. **The audit ran wider than the two named areas in one respect.** Purchase orders, goods receipts,
   delivery notes, and suppliers were audited because the brief lists "purchase-related stock
   effects" and "supplier/purchase dependencies needed for inventory correctness" in scope. Supplier
   *invoices* were checked only to confirm they have no stock effect (they do not) and were not
   audited further.
2. **No Platform/SaaS code was examined**, per the brief. `SuperAdmin/*` was not opened.
3. **A temporary PHPUnit probe was created, run, and deleted** to convert six findings from
   "provable by reading" to "reproduced". No product code was modified at any point; `git status`
   after the probe shows only the pre-existing untracked `docs/` directory. The probe's payloads and
   outputs are quoted verbatim in the findings above so they can be re-derived.
4. **`docs/product-audit/` was read but not relied upon.** Where it overlaps this audit (its A5, A6,
   A11, R-11, R-12) the findings were re-derived from the repository first. It was left untouched and
   uncommitted, per instruction.
5. **Nothing was committed.** The working tree contains this file and the untracked `docs/` directory.
