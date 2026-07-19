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
Route::get('/demo-login/{role}', [LoginController::class, 'demo'])->name('demo.login');
Route::match(['get', 'post'], '/logout', [LoginController::class, 'logout'])->name('logout');

/* ------------------------- الملف الشخصي (كل الأدوار) ------------------------- */
Route::middleware('auth')->group(function () {
    Route::get('/profile', [\App\Http\Controllers\ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [\App\Http\Controllers\ProfileController::class, 'update'])->name('profile.update');

    // المرتجعات (متاح للنشاط ونقطة البيع — محصور بالمستأجر)
    Route::post('/orders/{id}/return', [\App\Http\Controllers\ReturnController::class, 'store'])->name('orders.return');
});

/* --------------------------- Super Admin --------------------------- */
Route::prefix('super-admin')->name('super-admin.')->middleware(['auth', 'role:super_admin'])->group(function () {
    Route::view('/dashboard', 'super-admin.dashboard')->name('dashboard');
    Route::get('/dashboard/stats', [\App\Http\Controllers\DashboardController::class, 'superStats'])->name('dashboard.stats');

    // الشركات
    Route::get('/businesses', [BusinessController::class, 'index'])->name('businesses.index');
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

    // تصدير CSV
    Route::get('/export/businesses', [\App\Http\Controllers\ExportController::class, 'businesses'])->name('export.businesses');
    Route::get('/export/invoices', [\App\Http\Controllers\ExportController::class, 'invoices'])->name('export.invoices');

    // البحث الموحّد
    Route::get('/search', [\App\Http\Controllers\SearchController::class, 'super'])->name('search');

    // المستخدمون
    Route::get('/users', [\App\Http\Controllers\SuperAdmin\UserController::class, 'index'])->name('users.index');
    Route::view('/users/{id}', 'super-admin.users.show')->name('users.show');

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
    Route::get('/branch/{id}/switch', [\App\Http\Controllers\Admin\BranchController::class, 'switch'])->name('branch.switch');
    Route::post('/branches', [\App\Http\Controllers\Admin\BranchController::class, 'store'])->name('branches.store');
    Route::delete('/branches/{id}', [\App\Http\Controllers\Admin\BranchController::class, 'destroy'])->name('branches.destroy');

    // البحث الموحّد
    Route::get('/search', [\App\Http\Controllers\SearchController::class, 'admin'])->name('search');

    // المنتجات
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::view('/products/create', 'admin.products.create')->name('products.create');
    Route::view('/products/barcodes', 'admin.products.barcodes')->name('products.barcodes');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::view('/products/{id}', 'admin.products.show')->name('products.show');
    Route::view('/products/{id}/edit', 'admin.products.edit')->name('products.edit');
    Route::put('/products/{id}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('/products/{id}', [ProductController::class, 'destroy'])->name('products.destroy');

    // التصنيفات
    Route::view('/categories', 'admin.categories.index')->name('categories.index');
    Route::view('/categories/create', 'admin.categories.create')->name('categories.create');
    Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    // الطلبات
    Route::get('/orders', [\App\Http\Controllers\Admin\OrderController::class, 'index'])->name('orders.index');
    Route::view('/orders/{id}', 'admin.orders.show')->name('orders.show');
    Route::put('/orders/{id}/status', [\App\Http\Controllers\Admin\OrderController::class, 'updateStatus'])->name('orders.updateStatus');
    Route::get('/orders/{id}/pdf', [\App\Http\Controllers\PdfController::class, 'orderReceipt'])->name('orders.pdf');

    // المرتجعات
    Route::view('/returns', 'admin.returns.index')->name('returns.index');

    // العملات وأسعار الصرف
    Route::get('/currency/{code}/switch', [\App\Http\Controllers\Admin\CurrencyController::class, 'switch'])->name('currency.switch');
    Route::post('/currencies', [\App\Http\Controllers\Admin\CurrencyController::class, 'store'])->name('currencies.store');
    Route::put('/currencies/{id}', [\App\Http\Controllers\Admin\CurrencyController::class, 'update'])->name('currencies.update');
    Route::post('/currencies/{id}/base', [\App\Http\Controllers\Admin\CurrencyController::class, 'setBase'])->name('currencies.setBase');
    Route::delete('/currencies/{id}', [\App\Http\Controllers\Admin\CurrencyController::class, 'destroy'])->name('currencies.destroy');

    // العملاء
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::post('/customers/{id}/note', [CustomerController::class, 'saveNote'])->name('customers.note');
    Route::post('/customers/{id}/redeem', [CustomerController::class, 'redeem'])->name('customers.redeem');
    Route::get('/customers/{id}/statement', [\App\Http\Controllers\PdfController::class, 'customerStatement'])->name('customers.statement');
    Route::view('/customers/{id}', 'admin.customers.show')->name('customers.show');

    // الموظفون
    Route::view('/employees', 'admin.employees.index')->name('employees.index');
    Route::view('/employees/create', 'admin.employees.create')->name('employees.create');
    Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::get('/employees/{id}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
    Route::put('/employees/{id}', [EmployeeController::class, 'update'])->name('employees.update');
    Route::post('/employees/{id}/toggle', [EmployeeController::class, 'toggleStatus'])->name('employees.toggle');
    Route::post('/employees/{id}/reset-password', [EmployeeController::class, 'resetPassword'])->name('employees.resetPassword');
    Route::view('/employees/{id}', 'admin.employees.show')->name('employees.show');

    // المخزون
    Route::view('/inventory', 'admin.inventory.index')->name('inventory.index');
    Route::view('/inventory/movements', 'admin.inventory.movements')->name('inventory.movements');
    Route::post('/inventory/movements', [InventoryController::class, 'store'])->name('inventory.store');

    // المالية والمصروفات والتقارير والإعدادات
    Route::view('/finance', 'admin.finance.index')->name('finance.index');
    Route::post('/finance/transactions', [FinanceController::class, 'store'])->name('finance.store');
    Route::view('/expenses', 'admin.expenses.index')->name('expenses.index');
    Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
    Route::view('/reports', 'admin.reports.index')->name('reports.index');
    Route::get('/reports/pdf', [\App\Http\Controllers\PdfController::class, 'salesReport'])->name('reports.pdf');
    Route::view('/analytics', 'admin.analytics')->name('analytics.index');

    // إشعارات المتصفح (Polling)
    Route::get('/notifications/feed', [\App\Http\Controllers\NotificationController::class, 'feed'])->name('notifications.feed');

    // النسخ الاحتياطي والاستعادة
    Route::get('/backup/download', [\App\Http\Controllers\BackupController::class, 'download'])->name('backup.download');
    Route::post('/backup/restore', [\App\Http\Controllers\BackupController::class, 'restore'])->name('backup.restore');

    // تصدير CSV
    Route::get('/export/orders', [\App\Http\Controllers\ExportController::class, 'orders'])->name('export.orders');
    Route::get('/export/products', [\App\Http\Controllers\ExportController::class, 'products'])->name('export.products');
    Route::get('/export/customers', [\App\Http\Controllers\ExportController::class, 'customers'])->name('export.customers');
    Route::get('/export/transactions', [\App\Http\Controllers\ExportController::class, 'transactions'])->name('export.transactions');

    Route::get('/activity', [\App\Http\Controllers\ActivityController::class, 'adminIndex'])->name('activity.index');
    Route::view('/settings', 'admin.settings.index')->name('settings.index');
    Route::post('/settings', [\App\Http\Controllers\Admin\SettingController::class, 'update'])->name('settings.update');
});

/* -------------------------------- POS ------------------------------ */
Route::prefix('pos')->name('pos.')->middleware('auth')->group(function () {
    Route::view('/', 'pos.index')->name('index');
    Route::get('/currency/{code}/switch', [\App\Http\Controllers\Admin\CurrencyController::class, 'switch'])->name('currency.switch');
    Route::post('/checkout', [PosController::class, 'checkout'])->name('checkout');
    Route::post('/hold', [PosController::class, 'hold'])->name('hold');
    Route::view('/orders', 'pos.orders')->name('orders');
    Route::view('/orders/{id}', 'pos.order-details')->name('order-details');
    Route::view('/payments', 'pos.payments')->name('payments');
    Route::view('/receipts', 'pos.receipts')->name('receipts');
    Route::get('/receipt/{id}/pdf', [\App\Http\Controllers\PdfController::class, 'orderReceipt'])->name('receipt.pdf');
    Route::view('/customers', 'pos.customers')->name('customers');
    Route::post('/customers', [PosController::class, 'storeCustomer'])->name('customers.store');
    Route::view('/shift', 'pos.shift')->name('shift');
    Route::post('/shift/close', [PosController::class, 'closeShift'])->name('shift.close');
});
