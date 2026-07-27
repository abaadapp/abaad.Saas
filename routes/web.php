<?php

use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\ExpenseController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Pos\PosController;
use App\Http\Controllers\SuperAdmin\BusinessController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| مسارات Abad POS
|--------------------------------------------------------------------------
| صفحات العرض تُقدّم عبر Route::view وتقرأ بياناتها الحقيقية من قاعدة البيانات
| عبر App\Support\Demo (حسب المستأجر). أفعال الحفظ/التعديل/الحذف عبر Controllers.
*/

/* ----------------------------- المصادقة ----------------------------- */
Route::view('/', 'auth.login')->name('login');
Route::view('/login', 'auth.login')->name('login.form');
Route::post('/login', [LoginController::class, 'attempt'])->name('login.attempt');
Route::get('/pin-login', [LoginController::class, 'pinForm'])->name('pin.form');
Route::post('/pin-login', [LoginController::class, 'pinAttempt'])->name('pin.attempt');
Route::get('/demo-login/{role}', [LoginController::class, 'demo'])->name('demo.login');
Route::match(['get', 'post'], '/logout', [LoginController::class, 'logout'])->name('logout');

/* ------------------------- الملف الشخصي (كل الأدوار) ------------------------- */
Route::middleware('auth')->group(function () {
    Route::get('/profile', [\App\Http\Controllers\ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [\App\Http\Controllers\ProfileController::class, 'update'])->name('profile.update');
});

/* --------------------------- Super Admin --------------------------- */
Route::prefix('super-admin')->name('super-admin.')->middleware(['auth', 'role:super_admin'])->group(function () {
    Route::view('/dashboard', 'super-admin.dashboard')->name('dashboard');
    Route::get('/dashboard/stats', [\App\Http\Controllers\DashboardController::class, 'superStats'])->name('dashboard.stats');

    // الشركات
    Route::get('/businesses', [BusinessController::class, 'index'])->name('businesses.index');
    // تصدير الشركات (قبل businesses/{id} حتى لا يبتلعها نمط المعرّف)
    Route::get('/businesses/xlsx', [\App\Http\Controllers\Admin\ReportExportController::class, 'businessesXlsx'])->name('businesses.xlsx');
    Route::get('/businesses/export-pdf', [\App\Http\Controllers\PdfController::class, 'businessesReport'])->name('businesses.exportPdf');
    Route::view('/businesses/create', 'super-admin.businesses.create')->name('businesses.create');
    Route::post('/businesses', [BusinessController::class, 'store'])->name('businesses.store');
    Route::view('/businesses/{id}', 'super-admin.businesses.show')->name('businesses.show');
    Route::view('/businesses/{id}/edit', 'super-admin.businesses.edit')->name('businesses.edit');
    Route::put('/businesses/{id}', [BusinessController::class, 'update'])->name('businesses.update');
    Route::delete('/businesses/{id}', [BusinessController::class, 'destroy'])->name('businesses.destroy');

    // محلات الورود
    Route::view('/flower-shops', 'super-admin.flower-shops.index')->name('flower-shops.index');
    Route::view('/flower-shops/create', 'super-admin.flower-shops.create')->name('flower-shops.create');
    Route::post('/flower-shops', [\App\Http\Controllers\SuperAdmin\FlowerShopController::class, 'store'])->name('flower-shops.store');
    Route::view('/flower-shops/{id}', 'super-admin.flower-shops.show')->name('flower-shops.show');
    Route::view('/flower-shops/{id}/edit', 'super-admin.flower-shops.edit')->name('flower-shops.edit');
    Route::put('/flower-shops/{id}', [\App\Http\Controllers\SuperAdmin\FlowerShopController::class, 'update'])->name('flower-shops.update');

    // الاشتراكات والباقات
    Route::view('/subscriptions', 'super-admin.subscriptions.index')->name('subscriptions.index');
    Route::view('/subscriptions/plans', 'super-admin.subscriptions.plans')->name('subscriptions.plans');
    Route::view('/subscriptions/invoices', 'super-admin.subscriptions.invoices')->name('subscriptions.invoices');
    Route::get('/invoices/{number}/pdf', [\App\Http\Controllers\PdfController::class, 'platformInvoice'])->name('invoices.pdf');
    Route::get('/subscriptions/invoices/xlsx', [\App\Http\Controllers\Admin\ReportExportController::class, 'invoicesXlsx'])->name('invoices.xlsx');
    Route::get('/subscriptions/invoices/pdf', [\App\Http\Controllers\PdfController::class, 'invoicesReport'])->name('invoices.exportPdf');

    // تصدير CSV
    Route::get('/export/businesses', [\App\Http\Controllers\ExportController::class, 'businesses'])->name('export.businesses');
    Route::get('/export/invoices', [\App\Http\Controllers\ExportController::class, 'invoices'])->name('export.invoices');

    // البحث الموحّد
    Route::get('/search', [\App\Http\Controllers\SearchController::class, 'super'])->name('search');

    // المستخدمون
    Route::get('/users', [\App\Http\Controllers\SuperAdmin\UserController::class, 'index'])->name('users.index');
    Route::post('/users', [\App\Http\Controllers\SuperAdmin\UserController::class, 'store'])->name('users.store');
    Route::put('/users/{id}', [\App\Http\Controllers\SuperAdmin\UserController::class, 'update'])->name('users.update');
    Route::post('/users/{id}/toggle', [\App\Http\Controllers\SuperAdmin\UserController::class, 'toggleStatus'])->name('users.toggle');
    Route::view('/users/{id}', 'super-admin.users.show')->name('users.show');

    // إنشاء باقة
    Route::post('/plans', [\App\Http\Controllers\SuperAdmin\PlanController::class, 'store'])->name('plans.store');

    // التقارير والإعدادات وسجل النشاط
    Route::view('/reports', 'super-admin.reports.index')->name('reports.index');
    Route::get('/reports/pdf', [\App\Http\Controllers\PdfController::class, 'platformReport'])->name('reports.pdf');
    Route::get('/activity', [\App\Http\Controllers\ActivityController::class, 'superIndex'])->name('activity.index');
    Route::view('/settings', 'super-admin.settings.index')->name('settings.index');
    Route::post('/settings', [\App\Http\Controllers\SuperAdmin\SettingController::class, 'update'])->name('settings.update');
    Route::post('/settings/test-email', [\App\Http\Controllers\SuperAdmin\SettingController::class, 'testEmail'])->name('settings.testEmail');
});

/* ------------------------------- Admin ----------------------------- */
Route::prefix('admin')->name('admin.')->middleware(['auth', 'role:admin,manager,accountant,inventory,sales,delivery', 'ability'])->group(function () {
    Route::view('/dashboard', 'admin.dashboard')->name('dashboard');
    Route::get('/dashboard/stats', [\App\Http\Controllers\DashboardController::class, 'adminStats'])->name('dashboard.stats');

    // الفروع
    Route::view('/branches', 'admin.branches.index')->name('branches.index');
    Route::get('/branch/{id}/switch', [\App\Http\Controllers\Admin\BranchController::class, 'switch'])->name('branch.switch');
    Route::post('/branches', [\App\Http\Controllers\Admin\BranchController::class, 'store'])->name('branches.store');
    Route::delete('/branches/{id}', [\App\Http\Controllers\Admin\BranchController::class, 'destroy'])->name('branches.destroy');

    // البحث الموحّد
    Route::get('/search', [\App\Http\Controllers\SearchController::class, 'admin'])->name('search');

    // المنتجات
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::view('/products/create', 'admin.products.create')->name('products.create');
    Route::view('/products/barcodes', 'admin.products.barcodes')->name('products.barcodes');
    // يجب أن يسبق products/{id} وإلا التقطه كمعرّف
    Route::get('/products/xlsx', [\App\Http\Controllers\Admin\ReportExportController::class, 'productsXlsx'])->name('products.xlsx');
    Route::get('/products/export-pdf', [\App\Http\Controllers\PdfController::class, 'productsReport'])->name('products.exportPdf');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::view('/products/{id}', 'admin.products.show')->name('products.show');
    Route::view('/products/{id}/edit', 'admin.products.edit')->name('products.edit');
    Route::put('/products/{id}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('/products/{id}', [ProductController::class, 'destroy'])->name('products.destroy');

    // التصنيفات
    Route::view('/categories', 'admin.categories.index')->name('categories.index');
    Route::view('/categories/create', 'admin.categories.create')->name('categories.create');
    Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::put('/categories/{id}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    // الإضافات
    Route::view('/addons', 'admin.addons.index')->name('addons.index');
    Route::post('/addons', [\App\Http\Controllers\Admin\AddonController::class, 'store'])->name('addons.store');
    Route::put('/addons/{id}', [\App\Http\Controllers\Admin\AddonController::class, 'update'])->name('addons.update');
    Route::delete('/addons/{id}', [\App\Http\Controllers\Admin\AddonController::class, 'destroy'])->name('addons.destroy');

    // الطلبات
    Route::get('/orders', [\App\Http\Controllers\Admin\OrderController::class, 'index'])->name('orders.index');
    // تصدير قائمة الطلبات (قبل /orders/{id} حتى لا يبتلعها نمط المعرّف)
    Route::get('/orders/export-xlsx', [\App\Http\Controllers\Admin\ReportExportController::class, 'ordersXlsx'])->name('orders.xlsx');
    Route::get('/orders/export-pdf', [\App\Http\Controllers\PdfController::class, 'ordersReport'])->name('orders.exportPdf');
    Route::view('/orders/{id}', 'admin.orders.show')->name('orders.show');
    Route::get('/orders/{id}/pdf', [\App\Http\Controllers\PdfController::class, 'orderReceipt'])->name('orders.pdf');

    // العملات وأسعار الصرف
    Route::get('/currency/{code}/switch', [\App\Http\Controllers\Admin\CurrencyController::class, 'switch'])->name('currency.switch');

    // العملاء
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    // تصدير/استيراد العملاء (Excel/PDF + معاينة قبل التأكيد)
    Route::get('/customers/export/xlsx', [\App\Http\Controllers\Admin\CustomerImportExportController::class, 'exportXlsx'])->name('customers.export.xlsx');
    Route::get('/customers/export/pdf', [\App\Http\Controllers\Admin\CustomerImportExportController::class, 'exportPdf'])->name('customers.export.pdf');
    Route::post('/customers/import', [\App\Http\Controllers\Admin\CustomerImportExportController::class, 'upload'])->name('customers.import.upload');
    Route::get('/customers/import/preview', [\App\Http\Controllers\Admin\CustomerImportExportController::class, 'preview'])->name('customers.import.preview');
    Route::post('/customers/import/confirm', [\App\Http\Controllers\Admin\CustomerImportExportController::class, 'confirm'])->name('customers.import.confirm');
    Route::post('/customers/import/cancel', [\App\Http\Controllers\Admin\CustomerImportExportController::class, 'cancel'])->name('customers.import.cancel');
    Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::post('/customers/{id}/note', [CustomerController::class, 'saveNote'])->name('customers.note');
    Route::post('/customers/{id}/redeem', [CustomerController::class, 'redeem'])->name('customers.redeem');
    Route::get('/customers/{id}/statement', [\App\Http\Controllers\PdfController::class, 'customerStatement'])->name('customers.statement');
    Route::view('/customers/{id}', 'admin.customers.show')->name('customers.show');

    // الموظفون
    Route::view('/employees', 'admin.employees.index')->name('employees.index');
    Route::view('/employees/create', 'admin.employees.create')->name('employees.create');

    // الوظائف
    Route::post('/job-titles', [\App\Http\Controllers\Admin\JobTitleController::class, 'store'])->name('jobTitles.store');
    Route::delete('/job-titles/{id}', [\App\Http\Controllers\Admin\JobTitleController::class, 'destroy'])->name('jobTitles.destroy');
    Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::get('/employees/{id}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
    Route::put('/employees/{id}', [EmployeeController::class, 'update'])->name('employees.update');
    Route::post('/employees/{id}/toggle', [EmployeeController::class, 'toggleStatus'])->name('employees.toggle');
    Route::post('/employees/{id}/reset-password', [EmployeeController::class, 'resetPassword'])->name('employees.resetPassword');
    Route::view('/employees/{id}', 'admin.employees.show')->name('employees.show');

    // المخزون
    Route::view('/inventory', 'admin.inventory.index')->name('inventory.index');
    Route::get('/inventory/xlsx', [\App\Http\Controllers\Admin\ReportExportController::class, 'inventoryXlsx'])->name('inventory.xlsx');
    Route::get('/inventory/export-pdf', [\App\Http\Controllers\PdfController::class, 'inventoryReport'])->name('inventory.exportPdf');
    Route::get('/inventory/reorder', [InventoryController::class, 'reorder'])->name('inventory.reorder');
    Route::get('/inventory/stocktake', [InventoryController::class, 'stocktake'])->name('inventory.stocktake');
    Route::post('/inventory/stocktake', [InventoryController::class, 'applyStocktake'])->name('inventory.stocktake.apply');
    Route::view('/inventory/movements', 'admin.inventory.movements')->name('inventory.movements');
    Route::post('/inventory/movements', [InventoryController::class, 'store'])->name('inventory.store');

    // المورّدون
    Route::view('/suppliers', 'admin.suppliers.index')->name('suppliers.index');
    Route::post('/suppliers', [\App\Http\Controllers\Admin\SupplierController::class, 'store'])->name('suppliers.store');
    Route::put('/suppliers/{id}', [\App\Http\Controllers\Admin\SupplierController::class, 'update'])->name('suppliers.update');
    Route::delete('/suppliers/{id}', [\App\Http\Controllers\Admin\SupplierController::class, 'destroy'])->name('suppliers.destroy');

    // أوامر الشراء
    Route::view('/purchases', 'admin.purchases.index')->name('purchases.index');
    Route::view('/purchases/create', 'admin.purchases.create')->name('purchases.create');
    Route::post('/purchases', [\App\Http\Controllers\Admin\PurchaseOrderController::class, 'store'])->name('purchases.store');
    Route::post('/purchases/{id}/receipt', [\App\Http\Controllers\Admin\PurchaseOrderController::class, 'uploadReceipt'])->name('purchases.receipt');
    Route::post('/purchases/{id}/receive', [\App\Http\Controllers\Admin\PurchaseOrderController::class, 'receive'])->name('purchases.receive');
    Route::delete('/purchases/{id}', [\App\Http\Controllers\Admin\PurchaseOrderController::class, 'destroy'])->name('purchases.destroy');

    // تحليلات الربحية
    Route::view('/profitability', 'admin.profitability')->name('profitability.index');

    // التسويق والكوبونات
    Route::view('/marketing', 'admin.marketing')->name('marketing.index');
    Route::post('/coupons', [\App\Http\Controllers\Admin\CouponController::class, 'store'])->name('coupons.store');
    Route::post('/coupons/{id}/toggle', [\App\Http\Controllers\Admin\CouponController::class, 'toggle'])->name('coupons.toggle');
    Route::delete('/coupons/{id}', [\App\Http\Controllers\Admin\CouponController::class, 'destroy'])->name('coupons.destroy');

    // ضريبة القيمة المضافة
    Route::view('/vat', 'admin.vat')->name('vat.index');
    Route::get('/vat/pdf', [\App\Http\Controllers\PdfController::class, 'vatReport'])->name('vat.pdf');
    Route::get('/vat/xlsx', [\App\Http\Controllers\Admin\ReportExportController::class, 'vatXlsx'])->name('vat.xlsx');
    Route::get('/vat/csv', [\App\Http\Controllers\ExportController::class, 'vat'])->name('vat.csv');
    Route::get('/orders/{id}/tax-invoice', [\App\Http\Controllers\PdfController::class, 'taxInvoice'])->name('orders.taxInvoice');

    // المالية والمصروفات والتقارير والإعدادات
    Route::view('/finance', 'admin.finance.index')->name('finance.index');
    // كشف الحساب البنكي والمطابقة
    Route::view('/finance/statement', 'admin.finance.statement')->name('finance.statement');
    Route::post('/bank/account', [\App\Http\Controllers\Admin\BankStatementController::class, 'updateAccount'])->name('bank.account');
    Route::post('/bank/import', [\App\Http\Controllers\Admin\BankStatementController::class, 'import'])->name('bank.import');
    Route::post('/bank/rematch', [\App\Http\Controllers\Admin\BankStatementController::class, 'rematch'])->name('bank.rematch');
    Route::delete('/bank/clear', [\App\Http\Controllers\Admin\BankStatementController::class, 'clear'])->name('bank.clear');
    Route::get('/finance/xlsx', [\App\Http\Controllers\Admin\ReportExportController::class, 'financeXlsx'])->name('finance.xlsx');
    Route::get('/finance/pdf', [\App\Http\Controllers\PdfController::class, 'financeReport'])->name('finance.pdf');
    Route::post('/finance/transactions', [FinanceController::class, 'store'])->name('finance.store');
    Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::get('/expenses/xlsx', [\App\Http\Controllers\Admin\ReportExportController::class, 'expensesXlsx'])->name('expenses.xlsx');
    Route::get('/expenses/export-pdf', [\App\Http\Controllers\PdfController::class, 'expensesReport'])->name('expenses.exportPdf');
    Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
    Route::delete('/expenses/{id}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
    // أنواع المصروفات
    Route::post('/expense-types', [\App\Http\Controllers\Admin\ExpenseTypeController::class, 'store'])->name('expenseTypes.store');
    Route::delete('/expense-types/{id}', [\App\Http\Controllers\Admin\ExpenseTypeController::class, 'destroy'])->name('expenseTypes.destroy');
    Route::view('/reports', 'admin.reports.index')->name('reports.index');
    Route::get('/reports/pdf', [\App\Http\Controllers\PdfController::class, 'salesReport'])->name('reports.pdf');
    Route::get('/reports/data/{key}', [\App\Http\Controllers\Admin\ReportDataController::class, 'show'])->name('reports.data');
    Route::get('/reports/xlsx', [\App\Http\Controllers\Admin\ReportExportController::class, 'xlsx'])->name('reports.xlsx');
    Route::view('/analytics', 'admin.analytics')->name('analytics.index');
    Route::get('/analytics/xlsx', [\App\Http\Controllers\Admin\ReportExportController::class, 'analyticsXlsx'])->name('analytics.xlsx');
    Route::get('/analytics/pdf', [\App\Http\Controllers\PdfController::class, 'analyticsReport'])->name('analytics.pdf');
    Route::post('/goals', [\App\Http\Controllers\Admin\GoalController::class, 'update'])->name('goals.update');

    // إشعارات المتصفح (Polling)
    Route::get('/notifications/feed', [\App\Http\Controllers\NotificationController::class, 'feed'])->name('notifications.feed');
    Route::post('/notifications/dismiss', [\App\Http\Controllers\NotificationController::class, 'dismiss'])->name('notifications.dismiss');
    Route::post('/notifications/clear', [\App\Http\Controllers\NotificationController::class, 'clear'])->name('notifications.clear');

    // النسخ الاحتياطي والاستعادة
    Route::get('/backup/download', [\App\Http\Controllers\BackupController::class, 'download'])->name('backup.download');
    Route::post('/backup/restore', [\App\Http\Controllers\BackupController::class, 'restore'])->name('backup.restore');

    // تصدير CSV
    Route::get('/export/products', [\App\Http\Controllers\ExportController::class, 'products'])->name('export.products');
    Route::get('/export/orders', [\App\Http\Controllers\ExportController::class, 'orders'])->name('export.orders');
    Route::get('/export/customers', [\App\Http\Controllers\ExportController::class, 'customers'])->name('export.customers');
    Route::get('/export/transactions', [\App\Http\Controllers\ExportController::class, 'transactions'])->name('export.transactions');
    Route::get('/export/analytics', [\App\Http\Controllers\ExportController::class, 'analytics'])->name('export.analytics');
    Route::get('/export/reports', [\App\Http\Controllers\ExportController::class, 'reports'])->name('export.reports');
    Route::get('/export/expenses', [\App\Http\Controllers\ExportController::class, 'expenses'])->name('export.expenses');
    Route::get('/export/inventory', [\App\Http\Controllers\ExportController::class, 'inventory'])->name('export.inventory');

    Route::get('/activity', [\App\Http\Controllers\ActivityController::class, 'adminIndex'])->name('activity.index');
    Route::view('/settings', 'admin.settings.index')->name('settings.index');
    Route::post('/language', [\App\Http\Controllers\Admin\LanguageController::class, 'update'])->name('language.update');
    Route::post('/settings', [\App\Http\Controllers\Admin\SettingController::class, 'update'])->name('settings.update');
});

/* -------------------------------- POS ------------------------------ */
Route::prefix('pos')->name('pos.')->middleware('auth')->group(function () {
    Route::view('/', 'pos.index')->name('index');
    Route::get('/stock-feed', [PosController::class, 'stockFeed'])->name('stock-feed');
    Route::get('/currency/{code}/switch', [\App\Http\Controllers\Admin\CurrencyController::class, 'switch'])->name('currency.switch');
    Route::post('/checkout', [PosController::class, 'checkout'])->name('checkout');
    Route::post('/coupon', [PosController::class, 'applyCoupon'])->name('coupon.apply');
    Route::post('/hold', [PosController::class, 'hold'])->name('hold');
    Route::view('/orders', 'pos.orders')->name('orders');
    Route::get('/orders/{id}/resume', [PosController::class, 'resume'])->name('orders.resume');
    Route::delete('/orders/{id}', [PosController::class, 'discard'])->name('orders.discard');
    Route::view('/orders/{id}', 'pos.order-details')->name('order-details');
    Route::view('/payments', 'pos.payments')->name('payments');
    Route::view('/receipts', 'pos.receipts')->name('receipts');
    Route::get('/receipts/search', [PosController::class, 'searchReceipts'])->name('receipts.search');
    Route::get('/receipt/{id}/pdf', [\App\Http\Controllers\PdfController::class, 'orderReceipt'])->name('receipt.pdf');
    Route::view('/customers', 'pos.customers')->name('customers');
    Route::post('/customers', [PosController::class, 'storeCustomer'])->name('customers.store');
    Route::view('/settings', 'pos.settings')->name('settings');
    Route::post('/language', [\App\Http\Controllers\Admin\LanguageController::class, 'update'])->name('language.update');
});
