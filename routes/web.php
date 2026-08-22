<?php

use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\ExpenseController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Pos\PosController;
use App\Http\Controllers\SuperAdmin\BusinessController;
use App\Http\Controllers\SuperAdmin\PageController as SuperAdminPageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| مسارات Abad POS
|--------------------------------------------------------------------------
| صفحات العرض تُقدّم عبر Route::view وتقرأ بياناتها الحقيقية من قاعدة البيانات
| عبر App\Support\Demo (حسب المستأجر). أفعال الحفظ/التعديل/الحذف عبر Controllers.
*/

/*
 * كل {id} في هذا الملف مفتاحٌ أساسيٌّ صحيح. بلا هذا القيد يصل نصٌّ مثل
 * "HOLD-JAR-1" إلى findOrFail، فيبتلعه SQLite صامتًا (يحوّله إلى 0 فيرجع 404)
 * بينما PostgreSQL يرمي 22P02 فيصير 500 — أي أن عنوانًا معطوبًا واحدًا يُظهر
 * صفحة خطأ للتاجر في الإنتاج بدل «غير موجود». القيد يجعل السلوك واحدًا على
 * المحرّكين: المسار لا يُطابَق أصلًا، فيرجع 404 كما ينبغي.
 * ونتيجةً جانبية: /products/export ونظائرها لم تعد تُبتلع كمعرّفات.
 */
Route::pattern('id', '[0-9]+');
Route::pattern('addressId', '[0-9]+');

/* ----------------------------- المصادقة ----------------------------- */
Route::get('/', [LoginController::class, 'showLogin'])->name('login');
Route::get('/login', [LoginController::class, 'showLogin'])->name('login.form');
Route::post('/login', [LoginController::class, 'attempt'])->name('login.attempt');
Route::get('/pin-login', [LoginController::class, 'pinForm'])->name('pin.form');
Route::post('/pin-login', [LoginController::class, 'pinAttempt'])->name('pin.attempt');

/*
 * نسيان الجهاز — المخرج من شاشةٍ صارت مقفلة على متجرٍ واحد.
 *
 * بلا حارس عمدًا: لا يمسّ إلا كوكي الطالب نفسه، والإلغاء الحقيقي في
 * الإعدادات خلف صلاحيته. انظر LoginController::forgetDevice.
 */
Route::post('/forget-device', [LoginController::class, 'forgetDevice'])->name('device.forget');

/*
 * نقطة صحّة لمراقبٍ خارجي — بلا مصادقة.
 *
 * من يراقب لا يملك حسابًا، والعطب الذي نريد اكتشافه يمنع الدخول أصلًا. وهي
 * تفحص القاعدة والتخزين والذاكرة وتردّ 503 إن سقط واحد: صفحةٌ تعيد 200 لأن
 * nginx حيّ لا تقول شيئًا عن قاعدةٍ ساقطة، والمتجر بلا قاعدة متجرٌ ساقط.
 */
Route::get('/health', \App\Http\Controllers\HealthController::class)->name('health');

/*
 * استعادة كلمة المرور — الباب الذي لا يمرّ بالدعم.
 *
 * الرمز في المسار لا في الاستعلام: الرابط يُنسخ من الرسالة كاملًا، وحصرُه
 * بستّين محرفًا يمنع أن يبتلع مسارًا آخر.
 */
Route::get('/forgot-password', [\App\Http\Controllers\Auth\PasswordResetController::class, 'request'])->name('password.request');
Route::post('/forgot-password', [\App\Http\Controllers\Auth\PasswordResetController::class, 'send'])->name('password.email');
Route::get('/reset-password/{token}', [\App\Http\Controllers\Auth\PasswordResetController::class, 'reset'])
    ->where('token', '[A-Za-z0-9]{32,128}')->name('password.reset');
Route::post('/reset-password', [\App\Http\Controllers\Auth\PasswordResetController::class, 'store'])->name('password.update');
/*
 * الدخول التجريبي: يمنح جلسة كاملة بلا كلمة مرور، فلا يُسجَّل إلا حيث يُسمح به صراحةً.
 * تركُه مفتوحًا يعني أن أي زائر مجهول يصير مدير منصة بطلب GET واحد.
 * يُفعَّل محليًا افتراضيًا، ولا يُسجَّل في الإنتاج مهما كانت قيمة المتغيّر.
 */
if (config('app.demo_login')) {
    Route::get('/demo-login/{role}', [LoginController::class, 'demo'])->name('demo.login');
}

// تبديل لغة شاشة الدخول — متاح للزائر، ويكتب في الجلسة فقط
Route::post('/language', [\App\Http\Controllers\Admin\LanguageController::class, 'guest'])->name('language.guest');

Route::match(['get', 'post'], '/logout', [LoginController::class, 'logout'])->name('logout');

/*
 * الخروج من انتحال التاجر — خارج حارس role:super_admin عمدًا.
 *
 * المنتحِل يحمل أثناء الانتحال دور التاجر لا دور المنصة، فوضعُ هذا المسار
 * داخل مجموعة المنصة كان يعني بابًا لا يُفتح من الداخل: يدخل ولا يخرج إلا
 * بتسجيل خروجٍ كامل. والحارس هنا مفتاح الجلسة نفسه.
 */
Route::post('/stop-impersonating', [\App\Http\Controllers\SuperAdmin\ImpersonationController::class, 'stop'])
    ->middleware('auth')->name('impersonate.stop');

/*
 * صفحة انتهاء الاشتراك — خارج حارس المستأجر عمدًا.
 *
 * هي وجهةُ الحارس نفسه، فلو وقعت تحته لدارت الإحالة على نفسها إلى الأبد.
 * وحارسها الخاصّ في المتحكّم: من لم ينتهِ اشتراكه يُعاد إلى لوحته.
 */
Route::get('/subscription-expired', \App\Http\Controllers\SubscriptionExpiredController::class)
    ->middleware('auth')->name('subscription.expired');

/* ------------------------- الملف الشخصي (كل الأدوار) ------------------------- */
Route::middleware('auth')->group(function () {
    Route::get('/profile', [\App\Http\Controllers\ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [\App\Http\Controllers\ProfileController::class, 'update'])->name('profile.update');
});

/* --------------------------- Super Admin --------------------------- */
Route::prefix('super-admin')->name('super-admin.')->middleware(['auth', 'role:super_admin'])->group(function () {
    Route::get('/dashboard', [SuperAdminPageController::class, 'dashboard'])->name('dashboard');
    Route::get('/dashboard/stats', [\App\Http\Controllers\DashboardController::class, 'superStats'])->name('dashboard.stats');

    /*
     * الديمو — بناء المتاجر التجريبيّة ومحوُها.
     *
     * الحذف هنا يمحو متجرًا بكلّ صفوفه بضغطة، ولذلك لا يُقبل إلا معرّفُ
     * متجرٍ موسوم: الحارس في المتحكّم لا في الشاشة (انظر DemoGuard).
     */
    Route::get('/demo', [\App\Http\Controllers\SuperAdmin\DemoController::class, 'index'])->name('demo.index');
    Route::post('/demo', [\App\Http\Controllers\SuperAdmin\DemoController::class, 'store'])->name('demo.store');
    Route::post('/demo/{id}/reseed', [\App\Http\Controllers\SuperAdmin\DemoController::class, 'reseed'])->name('demo.reseed');
    Route::delete('/demo/{id}', [\App\Http\Controllers\SuperAdmin\DemoController::class, 'destroy'])->name('demo.destroy');

    // الشركات
    Route::get('/businesses', [BusinessController::class, 'index'])->name('businesses.index');
    // تصدير الشركات (قبل businesses/{id} حتى لا يبتلعها نمط المعرّف)
    Route::get('/businesses/xlsx', [\App\Http\Controllers\Admin\ReportExportController::class, 'businessesXlsx'])->name('businesses.xlsx');
    Route::get('/businesses/export-pdf', [\App\Http\Controllers\PdfController::class, 'businessesReport'])->name('businesses.exportPdf');
    Route::get('/businesses/create', [SuperAdminPageController::class, 'businessesCreate'])->name('businesses.create');
    Route::post('/businesses', [BusinessController::class, 'store'])->name('businesses.store');
    Route::get('/businesses/{id}', [SuperAdminPageController::class, 'businessesShow'])->name('businesses.show');
    Route::get('/businesses/{id}/edit', [SuperAdminPageController::class, 'businessesEdit'])->name('businesses.edit');
    Route::put('/businesses/{id}', [BusinessController::class, 'update'])->name('businesses.update');
    Route::delete('/businesses/{id}', [BusinessController::class, 'destroy'])->name('businesses.destroy');
    // الطرف الآخر من التعطيل — بلا هذا المسار كان الباب يُغلق ولا يُفتح
    Route::post('/businesses/{id}/activate', [BusinessController::class, 'activate'])->name('businesses.activate');

    // دخول كتاجر — الخروج منه خارج هذه المجموعة (انظر أسفل الملف)
    Route::post('/businesses/{id}/impersonate', [\App\Http\Controllers\SuperAdmin\ImpersonationController::class, 'start'])->name('businesses.impersonate');
    // حساب دخول التاجر وحده — لا يمرّ بنموذج الشركة كاملًا
    Route::post('/businesses/{id}/account', [BusinessController::class, 'account'])->name('businesses.account');

    /*
     * محلات الورود = شركات نوعها «محل ورود».
     *
     * الشاشات حُذفت — كانت تكرّر شاشات الشركات على الجدول نفسه. والمسارات
     * تبقى تحويلات لا حذفًا: روابط محفوظة ومرجعيّات قديمة تصل إلى وجهتها
     * الجديدة بدل 404. أربعة أسطر ثمنُها صفر، وحذفُها يكسر إشارةً محفوظة عند
     * أحدهم لا نعرف بها.
     */
    Route::get('/flower-shops', fn () => redirect()->route('super-admin.businesses.index', ['type' => 'محل ورود']))->name('flower-shops.index');
    Route::get('/flower-shops/create', fn () => redirect()->route('super-admin.businesses.create'))->name('flower-shops.create');
    Route::get('/flower-shops/{id}', fn ($id) => redirect()->route('super-admin.businesses.show', $id))->name('flower-shops.show');
    Route::get('/flower-shops/{id}/edit', fn ($id) => redirect()->route('super-admin.businesses.edit', $id))->name('flower-shops.edit');


    // الاشتراكات والباقات
    Route::get('/subscriptions', [SuperAdminPageController::class, 'subscriptionsIndex'])->name('subscriptions.index');
    Route::get('/subscriptions/plans', [SuperAdminPageController::class, 'plans'])->name('subscriptions.plans');
    Route::get('/subscriptions/invoices', [SuperAdminPageController::class, 'invoices'])->name('subscriptions.invoices');
    Route::get('/invoices/{number}/pdf', [\App\Http\Controllers\PdfController::class, 'platformInvoice'])->name('invoices.pdf');
    Route::get('/subscriptions/invoices/xlsx', [\App\Http\Controllers\Admin\ReportExportController::class, 'invoicesXlsx'])->name('invoices.xlsx');
    Route::get('/subscriptions/invoices/pdf', [\App\Http\Controllers\PdfController::class, 'invoicesReport'])->name('invoices.exportPdf');
    // التجديد وتسجيل السداد — الجدولان كانا يُقرآن ولا يُكتب فيهما
    Route::post('/businesses/{id}/renew', [\App\Http\Controllers\SuperAdmin\BillingController::class, 'renew'])->name('businesses.renew');
    Route::post('/invoices/{id}/pay', [\App\Http\Controllers\SuperAdmin\BillingController::class, 'pay'])->name('invoices.pay');
    Route::put('/subscriptions/{id}', [\App\Http\Controllers\SuperAdmin\SubscriptionController::class, 'update'])->name('subscriptions.update');
    Route::delete('/subscriptions/{id}', [\App\Http\Controllers\SuperAdmin\SubscriptionController::class, 'destroy'])->name('subscriptions.destroy');

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
    Route::delete('/users/{id}', [\App\Http\Controllers\SuperAdmin\UserController::class, 'destroy'])->name('users.destroy');
    Route::post('/users/{id}/restore', [\App\Http\Controllers\SuperAdmin\UserController::class, 'restore'])->name('users.restore');
    Route::get('/users/{id}', [SuperAdminPageController::class, 'usersShow'])->name('users.show');

    // الباقات
    Route::post('/plans', [\App\Http\Controllers\SuperAdmin\PlanController::class, 'store'])->name('plans.store');
    Route::put('/plans/{id}', [\App\Http\Controllers\SuperAdmin\PlanController::class, 'update'])->name('plans.update');

    // اللغة — مسار المنصة، فمسار لوحة التاجر يحرسه middleware أدوار لا يشمل مدير المنصة
    Route::post('/language', [\App\Http\Controllers\Admin\LanguageController::class, 'update'])->name('language.update');

    // التقارير والإعدادات وسجل النشاط
    Route::get('/reports', [SuperAdminPageController::class, 'reports'])->name('reports.index');
    Route::get('/reports/pdf', [\App\Http\Controllers\PdfController::class, 'platformReport'])->name('reports.pdf');
    Route::get('/activity', [\App\Http\Controllers\ActivityController::class, 'superIndex'])->name('activity.index');
    Route::get('/settings', [SuperAdminPageController::class, 'settings'])->name('settings.index');
    Route::post('/settings', [\App\Http\Controllers\SuperAdmin\SettingController::class, 'update'])->name('settings.update');
    Route::post('/settings/test-email', [\App\Http\Controllers\SuperAdmin\SettingController::class, 'testEmail'])->name('settings.testEmail');
});

/* ------------------------------- Admin ----------------------------- */
Route::prefix('admin')->name('admin.')->middleware(['auth', 'tenant', 'business', 'panel', 'ability'])->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'admin'])->name('dashboard');
    Route::get('/dashboard/stats', [\App\Http\Controllers\DashboardController::class, 'adminStats'])->name('dashboard.stats');

    // الفروع
    Route::get('/branches', [\App\Http\Controllers\Admin\PageController::class, 'branchesIndex'])->name('branches.index');
    Route::get('/branch/{branch}/switch', [\App\Http\Controllers\Admin\BranchController::class, 'switch'])->name('branch.switch');
    Route::post('/branches', [\App\Http\Controllers\Admin\BranchController::class, 'store'])->name('branches.store');

    // أجهزة نقطة البيع — تسقط على صلاحية الإعدادات (انظر Permissions::ALIASES)
    Route::get('/devices', [\App\Http\Controllers\Pos\DeviceController::class, 'index'])->name('devices.index');
    Route::put('/devices/{id}', [\App\Http\Controllers\Pos\DeviceController::class, 'update'])->name('devices.update');
    Route::delete('/devices/{id}', [\App\Http\Controllers\Pos\DeviceController::class, 'revoke'])->name('devices.revoke');

    // الأجهزة الملحقة بكل صندوق: طابعة، ماسح، درج… — تحت صلاحية الإعدادات
    // نفسها: من يبدّل طابعة صندوق يوجّه إيصالات فرعٍ إلى ورق فرعٍ آخر
    Route::post('/devices/{device}/peripherals', [\App\Http\Controllers\Pos\PeripheralController::class, 'store'])->name('devices.peripherals.store');
    Route::put('/devices/{device}/peripherals/{id}', [\App\Http\Controllers\Pos\PeripheralController::class, 'update'])->name('devices.peripherals.update');
    Route::delete('/devices/{device}/peripherals/{id}', [\App\Http\Controllers\Pos\PeripheralController::class, 'destroy'])->name('devices.peripherals.destroy');
    Route::delete('/branches/{id}', [\App\Http\Controllers\Admin\BranchController::class, 'destroy'])->name('branches.destroy');
    /*
     * التراجع تحت صلاحية القسم الذي حُذف منه، لا تحت «الإعدادات».
     *
     * وضعُه تحت الإعدادات كان يجعل زرّ «تراجع» في الإشعار عديم النفع لمن
     * ضغط الحذف: من يملك حذف الفروع ولا يملك الإعدادات يرى الزرّ ويُردّ ٤٠٣.
     * ومن يُؤذن له بالحذف يُؤذن له بردّه — الردّ أقلّ خطرًا من الحذف نفسه.
     */
    Route::post('/branches/{id}/restore', [\App\Http\Controllers\Admin\TrashController::class, 'restore'])
        ->defaults('type', 'branch')->name('branches.restore');

    // البحث الموحّد
    Route::get('/search', [\App\Http\Controllers\SearchController::class, 'admin'])->name('search');

    // المنتجات
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/create', [\App\Http\Controllers\Admin\PageController::class, 'productsCreate'])->name('products.create');
    // يجب أن يسبق products/{id} وإلا التقطه كمعرّف
    Route::get('/products/xlsx', [\App\Http\Controllers\Admin\ReportExportController::class, 'productsXlsx'])->name('products.xlsx');
    Route::get('/products/export-pdf', [\App\Http\Controllers\PdfController::class, 'productsReport'])->name('products.exportPdf');
    // تغذية كميات للوحة — بإجمالي الشركة كما تعرضه جداولها، لا برصيد فرع
    Route::get('/products/stock-feed', [ProductController::class, 'stockFeed'])->name('products.stockFeed');
    // تصدير/استيراد المنتجات — بيانات قابلة للدوران، لنقل التاجر من نظامه السابق
    Route::get('/products/export/xlsx', [\App\Http\Controllers\Admin\ProductImportExportController::class, 'exportXlsx'])->name('products.export.xlsx');
    Route::get('/products/export/pdf', [\App\Http\Controllers\Admin\ProductImportExportController::class, 'exportPdf'])->name('products.export.pdf');
    Route::post('/products/import', [\App\Http\Controllers\Admin\ProductImportExportController::class, 'upload'])->name('products.import.upload');
    Route::get('/products/import/preview', [\App\Http\Controllers\Admin\ProductImportExportController::class, 'preview'])->name('products.import.preview');
    Route::post('/products/import/remap', [\App\Http\Controllers\Admin\ProductImportExportController::class, 'remap'])->name('products.import.remap');
    Route::post('/products/import/undo', [\App\Http\Controllers\Admin\ProductImportExportController::class, 'undo'])->name('products.import.undo');
    Route::post('/products/import/confirm', [\App\Http\Controllers\Admin\ProductImportExportController::class, 'confirm'])->name('products.import.confirm');
    Route::post('/products/import/cancel', [\App\Http\Controllers\Admin\ProductImportExportController::class, 'cancel'])->name('products.import.cancel');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    // الإجراء الجماعي قبل نمط المعرّف حتى لا يبتلعه
    Route::post('/products/bulk', [ProductController::class, 'bulk'])->name('products.bulk');
    Route::get('/products/{id}', [\App\Http\Controllers\Admin\PageController::class, 'productsShow'])->name('products.show');
    Route::post('/products/{id}/duplicate', [ProductController::class, 'duplicate'])->name('products.duplicate');
    Route::patch('/products/{id}/quick', [ProductController::class, 'quickUpdate'])->name('products.quick');
    Route::get('/products/{id}/edit', [\App\Http\Controllers\Admin\PageController::class, 'productsEdit'])->name('products.edit');
    Route::put('/products/{id}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('/products/{id}', [ProductController::class, 'destroy'])->name('products.destroy');
    Route::post('/products/{id}/restore', [\App\Http\Controllers\Admin\TrashController::class, 'restore'])
        ->defaults('type', 'product')->name('products.restore');
    // المحو النهائي يتبع صلاحية الحذف نفسها: من أخفاه يمحوه، ولا أحد سواه
    Route::delete('/products/{id}/purge', [\App\Http\Controllers\Admin\TrashController::class, 'purge'])
        ->defaults('type', 'product')->name('products.purge');

    // التصنيفات

    // الإضافات

    // الطلبات
    Route::get('/orders', [\App\Http\Controllers\Admin\OrderController::class, 'index'])->name('orders.index');
    // تصدير قائمة الطلبات (قبل /orders/{id} حتى لا يبتلعها نمط المعرّف)
    Route::get('/orders/export-xlsx', [\App\Http\Controllers\Admin\ReportExportController::class, 'ordersXlsx'])->name('orders.xlsx');
    Route::get('/orders/export-pdf', [\App\Http\Controllers\PdfController::class, 'ordersReport'])->name('orders.exportPdf');
    Route::get('/orders/{number}', [\App\Http\Controllers\Admin\PageController::class, 'ordersShow'])->name('orders.show');
    Route::get('/orders/{number}/pdf', [\App\Http\Controllers\PdfController::class, 'orderReceipt'])->name('orders.pdf');

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
    Route::put('/customers/{id}', [CustomerController::class, 'update'])->name('customers.update');
    Route::delete('/customers/{id}', [CustomerController::class, 'destroy'])->name('customers.destroy');
    Route::post('/customers/{id}/restore', [\App\Http\Controllers\Admin\TrashController::class, 'restore'])
        ->defaults('type', 'customer')->name('customers.restore');
    Route::delete('/customers/{id}/purge', [\App\Http\Controllers\Admin\TrashController::class, 'purge'])
        ->defaults('type', 'customer')->name('customers.purge');
    Route::post('/customers/{id}/redeem', [CustomerController::class, 'redeem'])->name('customers.redeem');
    Route::post('/customers/{id}/addresses', [CustomerController::class, 'saveAddress'])->name('customers.addresses.save');
    Route::post('/customers/{id}/addresses/{addressId}/default', [CustomerController::class, 'defaultAddress'])->name('customers.addresses.default');
    Route::delete('/customers/{id}/addresses/{addressId}', [CustomerController::class, 'deleteAddress'])->name('customers.addresses.delete');
    Route::get('/customers/{id}/statement', [\App\Http\Controllers\PdfController::class, 'customerStatement'])->name('customers.statement');
    Route::get('/customers/{id}', [\App\Http\Controllers\Admin\PageController::class, 'customersShow'])->name('customers.show');

    // الموظفون
    Route::get('/employees', [\App\Http\Controllers\Admin\PageController::class, 'employeesIndex'])->name('employees.index');
    Route::get('/employees/create', [\App\Http\Controllers\Admin\PageController::class, 'employeesCreate'])->name('employees.create');

    /*
     * الرواتب — مسيرةٌ تُعتمد ثمّ تُصرف.
     *
     * الاعتماد يقيّد المستحقّ والصرف يُخرج المال، وهما قيدان لا واحد: راتبُ
     * شهرٍ اعتُمد ولم يُصرف التزامٌ قائم، ودمجُهما يُخفيه حتى يخرج المال
     * فيُقرأ الشهر ربحًا وهو مدين برواتبه.
     */
    Route::get('/payroll', [\App\Http\Controllers\Admin\Payroll\PayrollRunController::class, 'index'])->name('payroll.index');
    Route::post('/payroll', [\App\Http\Controllers\Admin\Payroll\PayrollRunController::class, 'store'])->name('payroll.store');
    Route::post('/payroll/{id}/approve', [\App\Http\Controllers\Admin\Payroll\PayrollRunController::class, 'approve'])->name('payroll.approve');
    Route::delete('/payroll/{id}', [\App\Http\Controllers\Admin\Payroll\PayrollRunController::class, 'destroy'])->name('payroll.destroy');
    Route::put('/payroll/lines/{id}', [\App\Http\Controllers\Admin\Payroll\PayrollRunController::class, 'updateLine'])->name('payroll.lines.update');
    Route::delete('/payroll/lines/{id}', [\App\Http\Controllers\Admin\Payroll\PayrollRunController::class, 'destroyLine'])->name('payroll.lines.destroy');
    Route::get('/payroll/payments', [\App\Http\Controllers\Admin\Payroll\PayrollPaymentController::class, 'index'])->name('payroll.payments');
    Route::post('/payroll/{id}/pay', [\App\Http\Controllers\Admin\Payroll\PayrollPaymentController::class, 'pay'])->name('payroll.pay');

    // الوظائف
    Route::post('/job-titles', [\App\Http\Controllers\Admin\JobTitleController::class, 'store'])->name('jobTitles.store');
    Route::put('/job-titles/{id}', [\App\Http\Controllers\Admin\JobTitleController::class, 'update'])->name('jobTitles.update');
    Route::delete('/job-titles/{id}', [\App\Http\Controllers\Admin\JobTitleController::class, 'destroy'])->name('jobTitles.destroy');
    Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::get('/employees/{id}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
    Route::put('/employees/{id}', [EmployeeController::class, 'update'])->name('employees.update');
    Route::post('/employees/{id}/toggle', [EmployeeController::class, 'toggleStatus'])->name('employees.toggle');
    Route::post('/employees/{id}/reset-password', [EmployeeController::class, 'resetPassword'])->name('employees.resetPassword');
    Route::get('/employees/{id}', [\App\Http\Controllers\Admin\PageController::class, 'employeesShow'])->name('employees.show');

    // المخزون
    // نظرة عامة على المخزون — لا تسبق inventory.index في المطابقة لأن مسارها أخصّ
    Route::get('/inventory', [\App\Http\Controllers\Admin\PageController::class, 'inventoryIndex'])->name('inventory.index');
    Route::get('/inventory/xlsx', [\App\Http\Controllers\Admin\ReportExportController::class, 'inventoryXlsx'])->name('inventory.xlsx');
    Route::get('/inventory/export-pdf', [\App\Http\Controllers\PdfController::class, 'inventoryReport'])->name('inventory.exportPdf');
    Route::get('/inventory/stocktake', [InventoryController::class, 'stocktake'])->name('inventory.stocktake');
    Route::post('/inventory/stocktake', [InventoryController::class, 'applyStocktake'])->name('inventory.stocktake.apply');
    // التحويل بين الفروع — حركة واحدة بدل «صرف» ثم «إضافة»

    /*
     * تعديلات المخزون وإشعارات التسليم.
     *
     * التعديل يُنقص المخزون ويُقيّد الخسارة معًا: الاكتفاء بتنقيص العدد يُبقي
     * قيمة المخزون في الميزانية كما كانت، فيظهر المتجر أغنى ممّا هو بقيمة كلّ
     * ما تلف عنده. والإشعار مستند حركةٍ لا مال — ولا يمسّ المخزون إن كان
     * مربوطًا بطلبٍ أنقصه يوم البيع.
     */
    Route::get('/inventory/adjustments', [\App\Http\Controllers\Admin\Inventory\StockAdjustmentController::class, 'index'])->name('inventory.adjustments');
    Route::post('/inventory/adjustments', [\App\Http\Controllers\Admin\Inventory\StockAdjustmentController::class, 'store'])->name('inventory.adjustments.store');
    Route::get('/inventory/deliveries', [\App\Http\Controllers\Admin\Inventory\DeliveryNoteController::class, 'index'])->name('inventory.deliveries');
    Route::post('/inventory/deliveries', [\App\Http\Controllers\Admin\Inventory\DeliveryNoteController::class, 'store'])->name('inventory.deliveries.store');
    Route::post('/inventory/deliveries/{id}/deliver', [\App\Http\Controllers\Admin\Inventory\DeliveryNoteController::class, 'deliver'])->name('inventory.deliveries.deliver');
    Route::post('/inventory/deliveries/{id}/cancel', [\App\Http\Controllers\Admin\Inventory\DeliveryNoteController::class, 'cancel'])->name('inventory.deliveries.cancel');
    Route::delete('/inventory/deliveries/{id}', [\App\Http\Controllers\Admin\Inventory\DeliveryNoteController::class, 'destroy'])->name('inventory.deliveries.destroy');
    Route::post('/inventory/movements', [InventoryController::class, 'store'])->name('inventory.store');

    // المورّدون
    Route::get('/suppliers', [\App\Http\Controllers\Admin\PageController::class, 'suppliersIndex'])->name('suppliers.index');
    // التصدير قبل {id}: لو جاء بعده لابتلع «export» مسارَ المورّد الواحد
    Route::get('/suppliers/export/xlsx', [\App\Http\Controllers\Admin\SupplierExportController::class, 'xlsx'])->name('suppliers.export.xlsx');
    Route::get('/suppliers/export/pdf', [\App\Http\Controllers\Admin\SupplierExportController::class, 'pdf'])->name('suppliers.export.pdf');
    Route::post('/suppliers/import', [\App\Http\Controllers\Admin\SupplierExportController::class, 'upload'])->name('suppliers.import.upload');
    Route::get('/suppliers/import/preview', [\App\Http\Controllers\Admin\SupplierExportController::class, 'preview'])->name('suppliers.import.preview');
    Route::post('/suppliers/import/confirm', [\App\Http\Controllers\Admin\SupplierExportController::class, 'confirm'])->name('suppliers.import.confirm');
    Route::post('/suppliers/import/cancel', [\App\Http\Controllers\Admin\SupplierExportController::class, 'cancel'])->name('suppliers.import.cancel');
    Route::post('/suppliers', [\App\Http\Controllers\Admin\SupplierController::class, 'store'])->name('suppliers.store');
    Route::put('/suppliers/{id}', [\App\Http\Controllers\Admin\SupplierController::class, 'update'])->name('suppliers.update');
    Route::delete('/suppliers/{id}', [\App\Http\Controllers\Admin\SupplierController::class, 'destroy'])->name('suppliers.destroy');

    /*
     * المشتريات — ثلاث شاشات على بابين.
     *
     * القائمة تجمع ما اشتُري من البابين معًا (أمرٌ استُلم، وسندٌ بلا أمر)،
     * والسندات تحمل ما على المتجر لمورّديه، والأوامر تحمل ما طُلب ولم يصل.
     */
    Route::get('/purchases', [\App\Http\Controllers\Admin\Purchasing\PurchaseRegisterController::class, 'index'])->name('purchases.index');
    Route::get('/purchases/invoices', [\App\Http\Controllers\Admin\Purchasing\SupplierInvoiceController::class, 'index'])->name('purchases.invoices');
    Route::post('/purchases/invoices', [\App\Http\Controllers\Admin\Purchasing\SupplierInvoiceController::class, 'store'])->name('purchases.invoices.store');
    Route::post('/purchases/invoices/{id}/pay', [\App\Http\Controllers\Admin\Purchasing\SupplierInvoiceController::class, 'pay'])->name('purchases.invoices.pay');
    Route::delete('/purchases/invoices/{id}', [\App\Http\Controllers\Admin\Purchasing\SupplierInvoiceController::class, 'destroy'])->name('purchases.invoices.destroy');
    Route::get('/purchases/orders', [\App\Http\Controllers\Admin\PurchaseOrderController::class, 'index'])->name('purchases.orders');
    Route::get('/purchases/create', [\App\Http\Controllers\Admin\PageController::class, 'purchasesCreate'])->name('purchases.create');
    Route::post('/purchases', [\App\Http\Controllers\Admin\PurchaseOrderController::class, 'store'])->name('purchases.store');
    Route::post('/purchases/{id}/receipt', [\App\Http\Controllers\Admin\PurchaseOrderController::class, 'uploadReceipt'])->name('purchases.receipt');
    Route::post('/purchases/{id}/receive', [\App\Http\Controllers\Admin\PurchaseOrderController::class, 'receive'])->name('purchases.receive');
    Route::delete('/purchases/{id}', [\App\Http\Controllers\Admin\PurchaseOrderController::class, 'destroy'])->name('purchases.destroy');

    // تحليلات الربحية

    /*
     * أدوات التسويق — ستّ أدوات تُفتح من قائمةٍ منسدلة لا من صفحةٍ جامعة.
     *
     * لكلٍّ منها عنوانها: من يريد إعدادات الموقع لا يمرّ بالكوبونات، ورابطُ
     * كلٍّ منها يُحفظ ويُشارَك وحده.
     */
    Route::get('/marketing', fn () => redirect()->route('admin.marketing.loyalty'))->name('marketing.index');
    /*
     * الموقع انتقل إلى الإعدادات ‹ المتجر — والمسار يبقى موجِّهًا لا شاشة.
     *
     * ما زال في العالم روابط محفوظة وإشاراتٌ مرجعيّة إليه، و404 على تاجرٍ
     * حفظ رابط نطاقه ليس نقلًا بل فقدان. والحفظ يبقى هنا لأن مخزنه مجموعة
     * `website` في `MarketingSettings` — الاسم يصف البيانات لا الشاشة.
     */
    Route::get('/marketing/website', fn () => redirect()->route('admin.settings.index', ['section' => 'domain']))->name('marketing.website');
    Route::post('/marketing/website', [\App\Http\Controllers\Admin\Marketing\MarketingController::class, 'saveWebsite'])->name('marketing.website.save');
    Route::get('/marketing/loyalty', [\App\Http\Controllers\Admin\Marketing\MarketingController::class, 'loyalty'])->name('marketing.loyalty');
    Route::post('/marketing/loyalty', [\App\Http\Controllers\Admin\Marketing\MarketingController::class, 'saveLoyalty'])->name('marketing.loyalty.save');
    Route::get('/marketing/reviews', [\App\Http\Controllers\Admin\Marketing\ReviewController::class, 'index'])->name('marketing.reviews');
    Route::post('/marketing/reviews', [\App\Http\Controllers\Admin\Marketing\ReviewController::class, 'store'])->name('marketing.reviews.store');
    Route::post('/marketing/reviews/{id}/status', [\App\Http\Controllers\Admin\Marketing\ReviewController::class, 'status'])->name('marketing.reviews.status');
    Route::post('/marketing/reviews/{id}/reply', [\App\Http\Controllers\Admin\Marketing\ReviewController::class, 'reply'])->name('marketing.reviews.reply');
    Route::delete('/marketing/reviews/{id}', [\App\Http\Controllers\Admin\Marketing\ReviewController::class, 'destroy'])->name('marketing.reviews.destroy');
    Route::get('/marketing/coupons', [\App\Http\Controllers\Admin\Marketing\MarketingController::class, 'coupons'])->name('marketing.coupons');
    Route::get('/marketing/seo', [\App\Http\Controllers\Admin\Marketing\MarketingController::class, 'seo'])->name('marketing.seo');
    Route::post('/marketing/seo', [\App\Http\Controllers\Admin\Marketing\MarketingController::class, 'saveSeo'])->name('marketing.seo.save');
    Route::get('/marketing/whatsapp', [\App\Http\Controllers\Admin\Marketing\MarketingController::class, 'whatsapp'])->name('marketing.whatsapp');
    Route::post('/marketing/whatsapp', [\App\Http\Controllers\Admin\Marketing\MarketingController::class, 'saveWhatsapp'])->name('marketing.whatsapp.save');

    Route::post('/coupons', [\App\Http\Controllers\Admin\CouponController::class, 'store'])->name('coupons.store');
    Route::post('/coupons/{id}/toggle', [\App\Http\Controllers\Admin\CouponController::class, 'toggle'])->name('coupons.toggle');
    Route::delete('/coupons/{id}', [\App\Http\Controllers\Admin\CouponController::class, 'destroy'])->name('coupons.destroy');

    // ضريبة القيمة المضافة
    Route::get('/orders/{number}/tax-invoice', [\App\Http\Controllers\PdfController::class, 'taxInvoice'])->name('orders.taxInvoice');

    /*
     * المالية — خمس شاشات على دفترٍ واحد.
     *
     * الحسابات البنكية والقيود اليومية وشجرة الحسابات والمصاريف الشهرية
     * والأصول الثابتة. وكلّها تكتب من باب `Ledger::post` وحده، فلا يدخل
     * الدفترَ قيدٌ لم يُفحص توازنه.
     */
    Route::get('/finance', [\App\Http\Controllers\Admin\Finance\BankAccountController::class, 'index'])->name('finance.index');
    Route::post('/finance/banks', [\App\Http\Controllers\Admin\Finance\BankAccountController::class, 'store'])->name('finance.banks.store');
    Route::put('/finance/banks/{id}', [\App\Http\Controllers\Admin\Finance\BankAccountController::class, 'update'])->name('finance.banks.update');
    Route::post('/finance/banks/{id}/primary', [\App\Http\Controllers\Admin\Finance\BankAccountController::class, 'primary'])->name('finance.banks.primary');
    Route::delete('/finance/banks/{id}', [\App\Http\Controllers\Admin\Finance\BankAccountController::class, 'destroy'])->name('finance.banks.destroy');
    // كشف الحساب البنكي والمطابقة — بلا معرّف: الحساب الرئيسيّ
    Route::get('/finance/statement/{id?}', [\App\Http\Controllers\Admin\Finance\BankAccountController::class, 'statement'])->name('finance.statement');

    // شجرة الحسابات
    Route::get('/finance/chart', [\App\Http\Controllers\Admin\Finance\ChartController::class, 'index'])->name('finance.chart');
    Route::post('/finance/chart', [\App\Http\Controllers\Admin\Finance\ChartController::class, 'store'])->name('finance.chart.store');
    Route::put('/finance/chart/{id}', [\App\Http\Controllers\Admin\Finance\ChartController::class, 'update'])->name('finance.chart.update');
    Route::post('/finance/chart/{id}/toggle', [\App\Http\Controllers\Admin\Finance\ChartController::class, 'toggle'])->name('finance.chart.toggle');
    Route::delete('/finance/chart/{id}', [\App\Http\Controllers\Admin\Finance\ChartController::class, 'destroy'])->name('finance.chart.destroy');

    // القيود اليومية
    Route::get('/finance/journal', [\App\Http\Controllers\Admin\Finance\JournalController::class, 'index'])->name('finance.journal');
    Route::post('/finance/journal', [\App\Http\Controllers\Admin\Finance\JournalController::class, 'store'])->name('finance.journal.store');

    // الأصول الثابتة وإهلاكها
    Route::get('/finance/assets', [\App\Http\Controllers\Admin\Finance\FixedAssetController::class, 'index'])->name('finance.assets');
    Route::post('/finance/assets', [\App\Http\Controllers\Admin\Finance\FixedAssetController::class, 'store'])->name('finance.assets.store');
    Route::post('/finance/assets/depreciate', [\App\Http\Controllers\Admin\Finance\FixedAssetController::class, 'depreciate'])->name('finance.assets.depreciate');
    Route::post('/finance/assets/{id}/dispose', [\App\Http\Controllers\Admin\Finance\FixedAssetController::class, 'dispose'])->name('finance.assets.dispose');
    Route::delete('/finance/assets/{id}', [\App\Http\Controllers\Admin\Finance\FixedAssetController::class, 'destroy'])->name('finance.assets.destroy');
    // الورديات المُقفلة وفروقها — يقرؤها من يملك «المالية» (sectionFromRoute)
    // تقرير إقفال الوردية (Z) — على ورق الإيصال، يُوقَّع عند تسليم الدرج
    // إقفال وردية نسيها الكاشير — بلا عدّ، وفرقُها يبقى مجهولًا
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
    Route::post('/expenses/{id}/paid', [ExpenseController::class, 'markPaid'])->name('expenses.paid');
    Route::delete('/expenses/{id}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
    Route::post('/expenses/{id}/restore', [\App\Http\Controllers\Admin\TrashController::class, 'restore'])
        ->defaults('type', 'expense')->name('expenses.restore');
    Route::delete('/expenses/{id}/purge', [\App\Http\Controllers\Admin\TrashController::class, 'purge'])
        ->defaults('type', 'expense')->name('expenses.purge');
    // أنواع المصروفات
    Route::post('/expense-types', [\App\Http\Controllers\Admin\ExpenseTypeController::class, 'store'])->name('expenseTypes.store');
    Route::delete('/expense-types/{id}', [\App\Http\Controllers\Admin\ExpenseTypeController::class, 'destroy'])->name('expenseTypes.destroy');
    // ملخّص المبيعات — كان محتوى /reports نفسه قبل أن يصير الفهرس بابها

    // تغذية التقارير (Polling) — صفحة تُترك مفتوحة لا يجوز أن تتجمّد على أرقام الصباح

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
    Route::get('/export/suppliers', [\App\Http\Controllers\ExportController::class, 'suppliers'])->name('export.suppliers');
    Route::get('/export/transactions', [\App\Http\Controllers\ExportController::class, 'transactions'])->name('export.transactions');
    Route::get('/export/expenses', [\App\Http\Controllers\ExportController::class, 'expenses'])->name('export.expenses');
    Route::get('/export/inventory', [\App\Http\Controllers\ExportController::class, 'inventory'])->name('export.inventory');

    Route::get('/activity', [\App\Http\Controllers\ActivityController::class, 'adminIndex'])->name('activity.index');
    Route::get('/settings', [\App\Http\Controllers\Admin\PageController::class, 'settingsIndex'])->name('settings.index');
    Route::post('/language', [\App\Http\Controllers\Admin\LanguageController::class, 'update'])->name('language.update');
    Route::post('/settings', [\App\Http\Controllers\Admin\SettingController::class, 'update'])->name('settings.update');
    // الشعار وحده: ملفٌّ يحتاج multipart، وخلطُه بنموذج الإعدادات يُرسل كل مقبضٍ نصًّا
    Route::post('/settings/logo', [\App\Http\Controllers\Admin\SettingController::class, 'logo'])->name('settings.logo');

    // المحذوفات — استعادة ما أذهبته ضغطة (انظر TrashController)
    Route::get('/settings/trash', [\App\Http\Controllers\Admin\TrashController::class, 'index'])->name('settings.trash');

    // تنبيهات يعرّفها صاحب النشاط — قواعد على مقاييس النظام، وتذكيرات بموعد
    Route::post('/alerts', [\App\Http\Controllers\Admin\CustomAlertController::class, 'store'])->name('alerts.store');
    Route::put('/alerts/{id}', [\App\Http\Controllers\Admin\CustomAlertController::class, 'update'])->name('alerts.update');
    Route::delete('/alerts/{id}', [\App\Http\Controllers\Admin\CustomAlertController::class, 'destroy'])->name('alerts.destroy');
});

/* -------------------------------- POS ------------------------------ */
/*
 * «ability» على المجموعة كلها.
 *
 * كانت على شاشة المدفوعات وحدها، لأن نقطة البيع كانت مفتوحة للجميع. وحين
 * صارت صلاحيةً تُمنح، بقي المربّع بلا حارس: يرفع صاحب النشاط علامة «نقطة
 * البيع» عن موظف فلا يتغيّر شيء — يكتب العنوان فتُفتح له.
 */
Route::prefix('pos')->name('pos.')->middleware(['auth', 'tenant', 'business', 'ability', 'pos.branch'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Pos\PageController::class, 'index'])->name('index');

    /*
     * إعداد الجهاز: يقع مرّةً واحدة يوم التركيب، بيد مديرٍ يملك الإعدادات.
     * بعده لا يرى الكاشير هذه الشاشة أبدًا — يفتح فيجد لوحة الأرقام.
     */
    Route::get('/setup', [\App\Http\Controllers\Pos\DeviceController::class, 'setup'])->name('setup');
    Route::post('/setup', [\App\Http\Controllers\Pos\DeviceController::class, 'activate'])->name('setup.activate');

    /*
     * قفل الشاشة: يُنهي جلسة الموظف ويُبقي تفعيل الجهاز.
     * تبديل الكاشير عشر ثوانٍ، لا دخول مديرٍ من جديد.
     */
    Route::post('/lock', [\App\Http\Controllers\Pos\PageController::class, 'lock'])->name('lock');

    // اختيار الموظف الواقف على الصندوق. ليس دخولًا ولا خروجًا — الصلاحيات
    // تبقى للمستخدم المسجَّل، وهذا يحدّد من تُنسب إليه البيعة فقط.
    Route::get('/cashier', [\App\Http\Controllers\Pos\CashierController::class, 'choose'])->name('cashier');
    Route::post('/cashier', [\App\Http\Controllers\Pos\CashierController::class, 'select'])->name('cashier.select');
    Route::post('/cashier/leave', [\App\Http\Controllers\Pos\CashierController::class, 'leave'])->name('cashier.leave');
    Route::get('/currency/{code}/switch', [\App\Http\Controllers\Admin\CurrencyController::class, 'switch'])->name('currency.switch');

    // وردية الصندوق: فتحٌ برصيد ابتدائي، وإقفالٌ بعدٍّ فعليّ
    Route::get('/shift', [\App\Http\Controllers\Pos\ShiftController::class, 'show'])->name('shift');
    Route::post('/shift/open', [\App\Http\Controllers\Pos\ShiftController::class, 'open'])->name('shift.open');
    Route::post('/shift/close', [\App\Http\Controllers\Pos\ShiftController::class, 'close'])->name('shift.close');
    Route::post('/shift/move', [\App\Http\Controllers\Pos\ShiftController::class, 'move'])->name('shift.move');
    Route::post('/checkout', [PosController::class, 'checkout'])->name('checkout');
    Route::post('/coupon', [PosController::class, 'applyCoupon'])->name('coupon.apply');
    Route::post('/hold', [PosController::class, 'hold'])->name('hold');
    Route::get('/stock-feed', [PosController::class, 'stockFeed'])->name('stock-feed');
    Route::get('/orders', [\App\Http\Controllers\Pos\PageController::class, 'orders'])->name('orders');
    Route::get('/orders/{id}/resume', [PosController::class, 'resume'])->name('orders.resume');
    Route::delete('/orders/{id}', [PosController::class, 'discard'])->name('orders.discard');
    /*
     * تصحيح بندٍ في فاتورة — لا إلغاء لها.
     *
     * الكاشير يُخطئ أمام الزبون فيُصلح، والأثر يبقى: ما كان وما صار ومن
     * غيّره ولماذا. والكتابة كلّها في `App\Support\OrderCorrection`.
     * ويسبق مسارَ العرض: «orders/{number}» يبتلع ما بعده لو تأخّر.
     */
    Route::put('/orders/{number}/items/{item}', [\App\Http\Controllers\Pos\OrderEditController::class, 'update'])->name('orders.items.update');
    Route::put('/orders/{number}/payment', [\App\Http\Controllers\Pos\OrderEditController::class, 'payment'])->name('orders.payment.update');
    Route::get('/orders/{number}', [\App\Http\Controllers\Pos\PageController::class, 'orderDetails'])->name('order-details');
    // المدفوعات تسقط على صلاحية finance لا pos (انظر sectionFromRoute):
    // شاشةٌ مالية تعرض حصيلة الصندوق، فيراها صاحب النشاط والمدير والمحاسب
    // ويُمنع منها الكاشير — وإن كان يملك نقطة البيع.
    Route::get('/payments', [\App\Http\Controllers\Pos\PageController::class, 'payments'])->name('payments');
    Route::get('/receipts', [\App\Http\Controllers\Pos\PageController::class, 'receipts'])->name('receipts');
    Route::get('/receipts/search', [PosController::class, 'searchReceipts'])->name('receipts.search');
    // تفصيل فاتورة واحدة — تُطلب عند النقر، فلا تُرسَل مبالغ الثلاثين دفعةً
    Route::get('/receipts/{number}', [PosController::class, 'showReceipt'])->name('receipts.show');
    Route::get('/receipt/{number}/pdf', [\App\Http\Controllers\PdfController::class, 'orderReceipt'])->name('receipt.pdf');
    Route::get('/customers', [\App\Http\Controllers\Pos\PageController::class, 'customers'])->name('customers');
    Route::post('/customers', [PosController::class, 'storeCustomer'])->name('customers.store');
    Route::get('/settings', [\App\Http\Controllers\Pos\PageController::class, 'settings'])->name('settings');
    Route::post('/language', [\App\Http\Controllers\Admin\LanguageController::class, 'update'])->name('language.update');
});
