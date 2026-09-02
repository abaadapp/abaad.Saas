<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Admin\BankStatementController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\CatalogQuickAddController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\CurrencyController;
use App\Http\Controllers\Admin\CustomAlertController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\CustomerImportExportController;
use App\Http\Controllers\Admin\DomainController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\ExpenseController;
use App\Http\Controllers\Admin\ExpenseTypeController;
use App\Http\Controllers\Admin\Finance\BankAccountController;
use App\Http\Controllers\Admin\Finance\ChartController;
use App\Http\Controllers\Admin\Finance\FixedAssetController;
use App\Http\Controllers\Admin\Finance\JournalController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\GoalController;
use App\Http\Controllers\Admin\Inventory\GoodsReceiptNoteController;
use App\Http\Controllers\Admin\Inventory\StockAdjustmentController;
use App\Http\Controllers\Admin\Inventory\StockTransferController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\JobTitleController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\Marketing\MarketingController;
use App\Http\Controllers\Admin\Marketing\ReviewController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\OrderDetailController;
use App\Http\Controllers\Admin\PageController;
use App\Http\Controllers\Admin\Payroll\PayrollPaymentController;
use App\Http\Controllers\Admin\Payroll\PayrollRunController;
use App\Http\Controllers\Admin\PreparationController;
use App\Http\Controllers\Admin\ProductCompositionController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductImageController;
use App\Http\Controllers\Admin\ProductImportExportController;
use App\Http\Controllers\Admin\PurchaseOrderController;
use App\Http\Controllers\Admin\Purchasing\PurchaseRegisterController;
use App\Http\Controllers\Admin\Purchasing\SupplierInvoiceController;
use App\Http\Controllers\Admin\RecoveryEmailController;
use App\Http\Controllers\Admin\ReportDownloadController;
use App\Http\Controllers\Admin\ReportExportController;
use App\Http\Controllers\Admin\ReportFeedController;
use App\Http\Controllers\Admin\ReportPageController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\SupplierExportController;
use App\Http\Controllers\Admin\TrashController;
use App\Http\Controllers\Admin\WasteAnalyticsController;
use App\Http\Controllers\Auth\AccountRecoveryController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\Pos\CashierController;
use App\Http\Controllers\Pos\DeviceController;
use App\Http\Controllers\Pos\MeController;
use App\Http\Controllers\Pos\OrderEditController;
use App\Http\Controllers\Pos\PeripheralController;
use App\Http\Controllers\Pos\PosController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SubscriptionExpiredController;
use App\Http\Controllers\SuperAdmin\BillingController;
use App\Http\Controllers\SuperAdmin\BusinessController;
use App\Http\Controllers\SuperAdmin\DemoController;
use App\Http\Controllers\SuperAdmin\DomainRequestController;
use App\Http\Controllers\SuperAdmin\ImpersonationController;
use App\Http\Controllers\SuperAdmin\PageController as SuperAdminPageController;
use App\Http\Controllers\SuperAdmin\PlanController;
use App\Http\Controllers\SuperAdmin\RecoveryController;
use App\Http\Controllers\SuperAdmin\SettingController;
use App\Http\Controllers\SuperAdmin\SubscriptionController;
use App\Http\Controllers\SuperAdmin\UserController;
use App\Http\Controllers\SuperAdmin\WhatsAppController;
use App\Http\Controllers\WhatsApp\WebhookController;
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
Route::get('/health', HealthController::class)->name('health');

/*
 * استعادة كلمة المرور — الباب الذي لا يمرّ بالدعم.
 *
 * الرمز في المسار لا في الاستعلام: الرابط يُنسخ من الرسالة كاملًا، وحصرُه
 * بستّين محرفًا يمنع أن يبتلع مسارًا آخر.
 */
Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
Route::post('/forgot-password', [PasswordResetController::class, 'send'])->name('password.email');
Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])
    ->where('token', '[A-Za-z0-9]{32,128}')->name('password.reset');
Route::post('/reset-password', [PasswordResetController::class, 'store'])->name('password.update');

/*
 * استعادة الحساب برمزٍ إلى بريدٍ موثّق — الطريق الأحدث.
 *
 * وتبقى مسارات الرابط أعلاه: من في صندوقه رسالةٌ قديمة يجد رابطها يعمل.
 * والباب الجديد لا يقبل عنوانًا يكتبه الطالب — انظر AccountRecoveryController.
 */
Route::post('/recovery/start', [AccountRecoveryController::class, 'start'])->name('recovery.start');
Route::get('/recovery/verify/{challenge}', [AccountRecoveryController::class, 'verify'])
    ->where('challenge', '[A-Za-z0-9]{32,64}')->name('recovery.verify');
Route::post('/recovery/verify', [AccountRecoveryController::class, 'check'])->name('recovery.check');
Route::post('/recovery/resend', [AccountRecoveryController::class, 'resend'])->name('recovery.resend');
Route::get('/recovery/password/{challenge}', [AccountRecoveryController::class, 'password'])
    ->where('challenge', '[A-Za-z0-9]{32,64}')->name('recovery.password');
Route::post('/recovery/password', [AccountRecoveryController::class, 'store'])->name('recovery.password.store');
/*
 * الدخول التجريبي: يمنح جلسة كاملة بلا كلمة مرور، فلا يُسجَّل إلا حيث يُسمح به صراحةً.
 * تركُه مفتوحًا يعني أن أي زائر مجهول يصير مدير منصة بطلب GET واحد.
 * يُفعَّل محليًا افتراضيًا، ولا يُسجَّل في الإنتاج مهما كانت قيمة المتغيّر.
 */
if (config('app.demo_login')) {
    Route::get('/demo-login/{role}', [LoginController::class, 'demo'])->name('demo.login');
}

// تبديل لغة شاشة الدخول — متاح للزائر، ويكتب في الجلسة فقط
Route::post('/language', [LanguageController::class, 'guest'])->name('language.guest');

Route::match(['get', 'post'], '/logout', [LoginController::class, 'logout'])->name('logout');

/*
 * الخروج من انتحال التاجر — خارج حارس role:super_admin عمدًا.
 *
 * المنتحِل يحمل أثناء الانتحال دور التاجر لا دور المنصة، فوضعُ هذا المسار
 * داخل مجموعة المنصة كان يعني بابًا لا يُفتح من الداخل: يدخل ولا يخرج إلا
 * بتسجيل خروجٍ كامل. والحارس هنا مفتاح الجلسة نفسه.
 */
Route::post('/stop-impersonating', [ImpersonationController::class, 'stop'])
    ->middleware('auth')->name('impersonate.stop');

/*
 * صفحة انتهاء الاشتراك — خارج حارس المستأجر عمدًا.
 *
 * هي وجهةُ الحارس نفسه، فلو وقعت تحته لدارت الإحالة على نفسها إلى الأبد.
 * وحارسها الخاصّ في المتحكّم: من لم ينتهِ اشتراكه يُعاد إلى لوحته.
 */
Route::get('/subscription-expired', SubscriptionExpiredController::class)
    ->middleware('auth')->name('subscription.expired');

/* ------------------------- الملف الشخصي (كل الأدوار) ------------------------- */
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

/* --------------------------- Super Admin --------------------------- */
Route::prefix('super-admin')->name('super-admin.')->middleware(['auth', 'role:super_admin'])->group(function () {
    Route::get('/dashboard', [SuperAdminPageController::class, 'dashboard'])->name('dashboard');
    Route::get('/dashboard/stats', [DashboardController::class, 'superStats'])->name('dashboard.stats');

    /*
     * الديمو — بناء المتاجر التجريبيّة ومحوُها.
     *
     * الحذف هنا يمحو متجرًا بكلّ صفوفه بضغطة، ولذلك لا يُقبل إلا معرّفُ
     * متجرٍ موسوم: الحارس في المتحكّم لا في الشاشة (انظر DemoGuard).
     */
    Route::get('/demo', [DemoController::class, 'index'])->name('demo.index');
    Route::post('/demo', [DemoController::class, 'store'])->name('demo.store');
    Route::post('/demo/{id}/reseed', [DemoController::class, 'reseed'])->name('demo.reseed');
    Route::delete('/demo/{id}', [DemoController::class, 'destroy'])->name('demo.destroy');

    // الشركات
    Route::get('/businesses', [BusinessController::class, 'index'])->name('businesses.index');
    // تصدير الشركات (قبل businesses/{id} حتى لا يبتلعها نمط المعرّف)
    Route::get('/businesses/xlsx', [ReportExportController::class, 'businessesXlsx'])->name('businesses.xlsx');
    Route::get('/businesses/export-pdf', [PdfController::class, 'businessesReport'])->name('businesses.exportPdf');
    Route::get('/businesses/create', [SuperAdminPageController::class, 'businessesCreate'])->name('businesses.create');
    Route::post('/businesses', [BusinessController::class, 'store'])->name('businesses.store');
    Route::get('/businesses/{id}', [SuperAdminPageController::class, 'businessesShow'])->name('businesses.show');
    Route::get('/businesses/{id}/edit', [SuperAdminPageController::class, 'businessesEdit'])->name('businesses.edit');
    Route::put('/businesses/{id}', [BusinessController::class, 'update'])->name('businesses.update');
    Route::delete('/businesses/{id}', [BusinessController::class, 'destroy'])->name('businesses.destroy');
    // الطرف الآخر من التعطيل — بلا هذا المسار كان الباب يُغلق ولا يُفتح
    Route::post('/businesses/{id}/activate', [BusinessController::class, 'activate'])->name('businesses.activate');

    // دخول كتاجر — الخروج منه خارج هذه المجموعة (انظر أسفل الملف)
    Route::post('/businesses/{id}/impersonate', [ImpersonationController::class, 'start'])->name('businesses.impersonate');
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
    Route::get('/invoices/{number}/pdf', [PdfController::class, 'platformInvoice'])->name('invoices.pdf');
    Route::get('/subscriptions/invoices/xlsx', [ReportExportController::class, 'invoicesXlsx'])->name('invoices.xlsx');
    Route::get('/subscriptions/invoices/pdf', [PdfController::class, 'invoicesReport'])->name('invoices.exportPdf');
    // التجديد وتسجيل السداد — الجدولان كانا يُقرآن ولا يُكتب فيهما
    Route::post('/businesses/{id}/renew', [BillingController::class, 'renew'])->name('businesses.renew');
    Route::post('/invoices/{id}/pay', [BillingController::class, 'pay'])->name('invoices.pay');
    Route::put('/subscriptions/{id}', [SubscriptionController::class, 'update'])->name('subscriptions.update');
    Route::delete('/subscriptions/{id}', [SubscriptionController::class, 'destroy'])->name('subscriptions.destroy');

    // تصدير CSV
    Route::get('/export/businesses', [ExportController::class, 'businesses'])->name('export.businesses');
    Route::get('/export/invoices', [ExportController::class, 'invoices'])->name('export.invoices');

    // البحث الموحّد
    Route::get('/search', [SearchController::class, 'super'])->name('search');

    // المستخدمون
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::put('/users/{id}', [UserController::class, 'update'])->name('users.update');
    Route::post('/users/{id}/toggle', [UserController::class, 'toggleStatus'])->name('users.toggle');
    Route::delete('/users/{id}', [UserController::class, 'destroy'])->name('users.destroy');
    Route::post('/users/{id}/restore', [UserController::class, 'restore'])->name('users.restore');
    Route::get('/users/{id}', [SuperAdminPageController::class, 'usersShow'])->name('users.show');

    // الباقات
    /*
     * طلبات النطاقات — الطرف الثاني لزرٍّ في لوحة التاجر.
     *
     * لا مسجّل نطاقاتٍ موصولٌ بالنظام، فالشراء عملُ إنسان: يقف الطلب هنا
     * حتى يراه المشغّل. وبدون هذه الشاشة يكون زرّ التاجر مقبضًا لا يُمسك.
     */
    Route::get('/domains', [DomainRequestController::class, 'index'])->name('domains.index');
    Route::post('/domains/{id}/status', [DomainRequestController::class, 'status'])->name('domains.status');

    Route::post('/plans', [PlanController::class, 'store'])->name('plans.store');
    Route::put('/plans/{id}', [PlanController::class, 'update'])->name('plans.update');

    // اللغة — مسار المنصة، فمسار لوحة التاجر يحرسه middleware أدوار لا يشمل مدير المنصة
    Route::post('/language', [LanguageController::class, 'update'])->name('language.update');

    // التقارير والإعدادات وسجل النشاط
    Route::get('/reports', [SuperAdminPageController::class, 'reports'])->name('reports.index');
    Route::get('/reports/pdf', [PdfController::class, 'platformReport'])->name('reports.pdf');
    Route::get('/activity', [ActivityController::class, 'superIndex'])->name('activity.index');
    Route::get('/settings', [SuperAdminPageController::class, 'settings'])->name('settings.index');
    Route::post('/settings', [SettingController::class, 'update'])->name('settings.update');
    Route::post('/settings/test-email', [SettingController::class, 'testEmail'])->name('settings.testEmail');

    /*
     * واتساب — الرقم المشترك وأذونات المتاجر.
     *
     * داخل حارس `role:super_admin` لا خارجه: من يرفع حدّ متجرٍ أو يمنحه رقمًا
     * خاصًّا هو مدير المنصّة وحده، والحارس على المجموعة لا في شاشةٍ تُخفي زرًّا.
     */
    /*
     * وسيلة الاستعادة للمتاجر القديمة — المرّة الواحدة التي يمرّ فيها المتجر
     * بإنسان. ومدير المنصّة يكتب العنوان ولا يختمه: الختم لا يضعه إلا رمزٌ
     * عاد من الصندوق.
     */
    Route::post('/businesses/{id}/recovery-email', [RecoveryController::class, 'setEmail'])->name('businesses.recovery.set');
    Route::post('/businesses/{id}/recovery-email/resend', [RecoveryController::class, 'resend'])->name('businesses.recovery.resend');
    Route::delete('/businesses/{id}/recovery-email', [RecoveryController::class, 'clear'])->name('businesses.recovery.clear');

    Route::post('/whatsapp/shared', [WhatsAppController::class, 'connectShared'])->name('whatsapp.shared.connect');
    Route::delete('/whatsapp/shared', [WhatsAppController::class, 'disconnectShared'])->name('whatsapp.shared.disconnect');
    Route::put('/businesses/{id}/whatsapp', [WhatsAppController::class, 'updateBusiness'])->name('businesses.whatsapp.update');
});

/* ------------------------------- Admin ----------------------------- */
Route::prefix('admin')->name('admin.')->middleware(['auth', 'tenant', 'business', 'panel', 'ability', 'plan'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'admin'])->name('dashboard');
    Route::get('/dashboard/stats', [DashboardController::class, 'adminStats'])->name('dashboard.stats');

    /*
     * لوحة التجهيز — قسمُها `preparation` يُشتقّ من اسم المسار.
     *
     * شاشةُ من يصنع الباقة لا من يحاسب عليها، فلا تتبع «المبيعات».
     */
    Route::get('/preparation', [PreparationController::class, 'index'])->name('preparation.index');
    Route::post('/preparation/{number}/move', [PreparationController::class, 'move'])->name('preparation.move');

    // الفروع
    Route::get('/branches', [PageController::class, 'branchesIndex'])->name('branches.index');
    Route::get('/branch/{branch}/switch', [BranchController::class, 'switch'])->name('branch.switch');
    Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');

    // أجهزة نقطة البيع — تسقط على صلاحية الإعدادات (انظر Permissions::ALIASES)
    Route::get('/devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::put('/devices/{id}', [DeviceController::class, 'update'])->name('devices.update');
    Route::delete('/devices/{id}', [DeviceController::class, 'revoke'])->name('devices.revoke');

    // الأجهزة الملحقة بكل صندوق: طابعة، ماسح، درج… — تحت صلاحية الإعدادات
    // نفسها: من يبدّل طابعة صندوق يوجّه إيصالات فرعٍ إلى ورق فرعٍ آخر
    Route::post('/devices/{device}/peripherals', [PeripheralController::class, 'store'])->name('devices.peripherals.store');
    Route::put('/devices/{device}/peripherals/{id}', [PeripheralController::class, 'update'])->name('devices.peripherals.update');
    Route::delete('/devices/{device}/peripherals/{id}', [PeripheralController::class, 'destroy'])->name('devices.peripherals.destroy');
    Route::delete('/branches/{id}', [BranchController::class, 'destroy'])->name('branches.destroy');
    /*
     * التراجع تحت صلاحية القسم الذي حُذف منه، لا تحت «الإعدادات».
     *
     * وضعُه تحت الإعدادات كان يجعل زرّ «تراجع» في الإشعار عديم النفع لمن
     * ضغط الحذف: من يملك حذف الفروع ولا يملك الإعدادات يرى الزرّ ويُردّ ٤٠٣.
     * ومن يُؤذن له بالحذف يُؤذن له بردّه — الردّ أقلّ خطرًا من الحذف نفسه.
     */
    Route::post('/branches/{id}/restore', [TrashController::class, 'restore'])
        ->defaults('type', 'branch')->name('branches.restore');

    // البحث الموحّد
    Route::get('/search', [SearchController::class, 'admin'])->name('search');

    // المنتجات
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/create', [PageController::class, 'productsCreate'])->name('products.create');
    // يجب أن يسبق products/{id} وإلا التقطه كمعرّف
    Route::get('/products/xlsx', [ReportExportController::class, 'productsXlsx'])->name('products.xlsx');
    Route::get('/products/export-pdf', [PdfController::class, 'productsReport'])->name('products.exportPdf');
    // تغذية كميات للوحة — بإجمالي الشركة كما تعرضه جداولها، لا برصيد فرع
    Route::get('/products/stock-feed', [ProductController::class, 'stockFeed'])->name('products.stockFeed');
    // تصدير/استيراد المنتجات — بيانات قابلة للدوران، لنقل التاجر من نظامه السابق
    Route::get('/products/export/xlsx', [ProductImportExportController::class, 'exportXlsx'])->name('products.export.xlsx');
    Route::get('/products/export/pdf', [ProductImportExportController::class, 'exportPdf'])->name('products.export.pdf');
    Route::post('/products/import', [ProductImportExportController::class, 'upload'])->name('products.import.upload');
    Route::get('/products/import/preview', [ProductImportExportController::class, 'preview'])->name('products.import.preview');
    Route::post('/products/import/remap', [ProductImportExportController::class, 'remap'])->name('products.import.remap');
    Route::post('/products/import/undo', [ProductImportExportController::class, 'undo'])->name('products.import.undo');
    Route::post('/products/import/confirm', [ProductImportExportController::class, 'confirm'])->name('products.import.confirm');
    Route::post('/products/import/cancel', [ProductImportExportController::class, 'cancel'])->name('products.import.cancel');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    // الإجراء الجماعي قبل نمط المعرّف حتى لا يبتلعه
    Route::post('/products/bulk', [ProductController::class, 'bulk'])->name('products.bulk');
    Route::get('/products/{id}', [PageController::class, 'productsShow'])->name('products.show');
    Route::post('/products/{id}/duplicate', [ProductController::class, 'duplicate'])->name('products.duplicate');
    Route::patch('/products/{id}/quick', [ProductController::class, 'quickUpdate'])->name('products.quick');
    Route::get('/products/{id}/edit', [PageController::class, 'productsEdit'])->name('products.edit');
    Route::put('/products/{id}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('/products/{id}', [ProductController::class, 'destroy'])->name('products.destroy');

    /*
     * صور المنتج — مسارٌ بذاته لا حقلٌ في نموذج المنتج.
     *
     * نموذج المنتج يكتب الكمية مطلقةً ويُزيح رصيد الفرع بفارقها، فمن بدّل
     * صورةً بحفظ النموذج كلِّه كتب فوق كلّ ما تغيّر بينه وبين فتحه الشاشة.
     * فالصور تُدار بطلباتٍ صغيرة لا تمسّ عمودًا آخر.
     */
    Route::post('/products/{id}/images', [ProductImageController::class, 'store'])->name('products.images.store');
    Route::post('/products/{id}/images/{imageId}/main', [ProductImageController::class, 'promote'])->name('products.images.promote');
    Route::delete('/products/{id}/images/{imageId}', [ProductImageController::class, 'destroy'])->name('products.images.destroy');
    Route::delete('/products/{id}/image', [ProductImageController::class, 'destroyMain'])->name('products.images.destroyMain');

    /*
     * تركيب المنتج: مقاساتُه ووصفتُه وإضافاتُه.
     *
     * تحت `/products/{id}` عمدًا: الحارس يشتقّ القسم من اسم المسار، فتقع
     * كلّها تحت صلاحية «المنتجات» بلا صلاحيةٍ جديدة تُخترع.
     */
    /*
     * قسمٌ أو إضافةٌ تُنشأ من جانب الحقل الذي يحتاجها.
     *
     * قبل هذا لم يكن للأقسام ولا للإضافات بابُ إنشاءٍ إطلاقًا: تأتي من
     * تهيئة نوع النشاط أو من استيراد ملفّ. وتُوضع تحت `/products/` كي
     * يقيسها الحارس بصلاحية «المنتجات» بلا صلاحيةٍ جديدة.
     */
    Route::post('/products/categories', [CatalogQuickAddController::class, 'storeCategory'])->name('products.categories.store');
    Route::post('/products/addons', [CatalogQuickAddController::class, 'storeAddon'])->name('products.addons.store');
    // تعديل إضافةٍ قائمة — سعرها ومداها وما تأكله من الرفّ. ويسبق مسار
    // «products/{id}/addons» فلا يبتلعه: هذا معرّف إضافةٍ لا معرّف منتج
    Route::put('/products/addons/{addon}', [CatalogQuickAddController::class, 'updateAddon'])->name('products.addons.update');

    Route::post('/products/{id}/variants', [ProductCompositionController::class, 'storeVariant'])->name('products.variants.store');
    Route::put('/products/{id}/variants/{variant}', [ProductCompositionController::class, 'updateVariant'])->name('products.variants.update');
    Route::delete('/products/{id}/variants/{variant}', [ProductCompositionController::class, 'destroyVariant'])->name('products.variants.destroy');
    Route::post('/products/{id}/recipe', [ProductCompositionController::class, 'storeRecipeItem'])->name('products.recipe.store');
    Route::put('/products/{id}/recipe/{item}', [ProductCompositionController::class, 'updateRecipeItem'])->name('products.recipe.update');
    Route::delete('/products/{id}/recipe/{item}', [ProductCompositionController::class, 'destroyRecipeItem'])->name('products.recipe.destroy');
    Route::put('/products/{id}/addons', [ProductCompositionController::class, 'syncAddons'])->name('products.addons.sync');
    Route::delete('/products/{id}/addons/{addon}', [ProductCompositionController::class, 'destroyAddon'])->name('products.addons.destroy');
    Route::post('/products/{id}/restore', [TrashController::class, 'restore'])
        ->defaults('type', 'product')->name('products.restore');
    // المحو النهائي يتبع صلاحية الحذف نفسها: من أخفاه يمحوه، ولا أحد سواه
    Route::delete('/products/{id}/purge', [TrashController::class, 'purge'])
        ->defaults('type', 'product')->name('products.purge');

    // التصنيفات

    // الإضافات

    // الطلبات
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    // تصدير قائمة الطلبات (قبل /orders/{id} حتى لا يبتلعها نمط المعرّف)
    Route::get('/orders/export-xlsx', [ReportExportController::class, 'ordersXlsx'])->name('orders.xlsx');
    Route::get('/orders/export-pdf', [PdfController::class, 'ordersReport'])->name('orders.exportPdf');
    Route::get('/orders/{number}', [PageController::class, 'ordersShow'])->name('orders.show');
    /*
     * تعديل بيانات التنفيذ ونقل الحالة — قسمُهما «المبيعات» يُشتقّ من الاسم.
     *
     * ومنفصلان عن تصحيح الفاتورة (Pos\OrderEditController): ذاك يُحرّك
     * المخزون والمال ويشترط سببًا، وهذا يُصحّح رقم هاتفٍ أو موعدًا.
     */
    Route::put('/orders/{number}/details', [OrderDetailController::class, 'update'])->name('orders.details.update');
    Route::post('/orders/{number}/status', [OrderDetailController::class, 'status'])->name('orders.status');
    Route::get('/orders/{number}/pdf', [PdfController::class, 'orderReceipt'])->name('orders.pdf');

    // العملات وأسعار الصرف
    Route::get('/currency/{code}/switch', [CurrencyController::class, 'switch'])->name('currency.switch');

    // العملاء
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    // تصدير/استيراد العملاء (Excel/PDF + معاينة قبل التأكيد)
    Route::get('/customers/export/xlsx', [CustomerImportExportController::class, 'exportXlsx'])->name('customers.export.xlsx');
    Route::get('/customers/export/pdf', [CustomerImportExportController::class, 'exportPdf'])->name('customers.export.pdf');
    Route::post('/customers/import', [CustomerImportExportController::class, 'upload'])->name('customers.import.upload');
    Route::get('/customers/import/preview', [CustomerImportExportController::class, 'preview'])->name('customers.import.preview');
    Route::post('/customers/import/confirm', [CustomerImportExportController::class, 'confirm'])->name('customers.import.confirm');
    Route::post('/customers/import/cancel', [CustomerImportExportController::class, 'cancel'])->name('customers.import.cancel');
    Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::post('/customers/{id}/note', [CustomerController::class, 'saveNote'])->name('customers.note');
    Route::put('/customers/{id}', [CustomerController::class, 'update'])->name('customers.update');
    Route::delete('/customers/{id}', [CustomerController::class, 'destroy'])->name('customers.destroy');
    Route::post('/customers/{id}/restore', [TrashController::class, 'restore'])
        ->defaults('type', 'customer')->name('customers.restore');
    Route::delete('/customers/{id}/purge', [TrashController::class, 'purge'])
        ->defaults('type', 'customer')->name('customers.purge');
    Route::post('/customers/{id}/redeem', [CustomerController::class, 'redeem'])->name('customers.redeem');
    Route::post('/customers/{id}/addresses', [CustomerController::class, 'saveAddress'])->name('customers.addresses.save');
    Route::post('/customers/{id}/addresses/{addressId}/default', [CustomerController::class, 'defaultAddress'])->name('customers.addresses.default');
    Route::delete('/customers/{id}/addresses/{addressId}', [CustomerController::class, 'deleteAddress'])->name('customers.addresses.delete');
    Route::get('/customers/{id}/statement', [PdfController::class, 'customerStatement'])->name('customers.statement');
    Route::get('/customers/{id}', [PageController::class, 'customersShow'])->name('customers.show');

    // الموظفون
    Route::get('/employees', [PageController::class, 'employeesIndex'])->name('employees.index');
    Route::get('/employees/create', [PageController::class, 'employeesCreate'])->name('employees.create');

    /*
     * الرواتب — مسيرةٌ تُعتمد ثمّ تُصرف.
     *
     * الاعتماد يقيّد المستحقّ والصرف يُخرج المال، وهما قيدان لا واحد: راتبُ
     * شهرٍ اعتُمد ولم يُصرف التزامٌ قائم، ودمجُهما يُخفيه حتى يخرج المال
     * فيُقرأ الشهر ربحًا وهو مدين برواتبه.
     */
    Route::get('/payroll', [PayrollRunController::class, 'index'])->name('payroll.index');
    Route::post('/payroll', [PayrollRunController::class, 'store'])->name('payroll.store');
    Route::post('/payroll/{id}/approve', [PayrollRunController::class, 'approve'])->name('payroll.approve');
    Route::delete('/payroll/{id}', [PayrollRunController::class, 'destroy'])->name('payroll.destroy');
    Route::put('/payroll/lines/{id}', [PayrollRunController::class, 'updateLine'])->name('payroll.lines.update');
    Route::delete('/payroll/lines/{id}', [PayrollRunController::class, 'destroyLine'])->name('payroll.lines.destroy');
    Route::get('/payroll/payments', [PayrollPaymentController::class, 'index'])->name('payroll.payments');
    Route::post('/payroll/{id}/pay', [PayrollPaymentController::class, 'pay'])->name('payroll.pay');

    // الوظائف
    Route::post('/job-titles', [JobTitleController::class, 'store'])->name('jobTitles.store');
    Route::put('/job-titles/{id}', [JobTitleController::class, 'update'])->name('jobTitles.update');
    Route::delete('/job-titles/{id}', [JobTitleController::class, 'destroy'])->name('jobTitles.destroy');
    Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::get('/employees/{id}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
    Route::put('/employees/{id}', [EmployeeController::class, 'update'])->name('employees.update');
    Route::post('/employees/{id}/toggle', [EmployeeController::class, 'toggleStatus'])->name('employees.toggle');
    Route::post('/employees/{id}/reset-password', [EmployeeController::class, 'resetPassword'])->name('employees.resetPassword');
    Route::get('/employees/{id}', [PageController::class, 'employeesShow'])->name('employees.show');

    // المخزون
    // نظرة عامة على المخزون — لا تسبق inventory.index في المطابقة لأن مسارها أخصّ
    Route::get('/inventory', [PageController::class, 'inventoryIndex'])->name('inventory.index');
    Route::get('/inventory/xlsx', [ReportExportController::class, 'inventoryXlsx'])->name('inventory.xlsx');
    Route::get('/inventory/export-pdf', [PdfController::class, 'inventoryReport'])->name('inventory.exportPdf');
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
    Route::get('/inventory/adjustments', [StockAdjustmentController::class, 'index'])->name('inventory.adjustments');
    Route::post('/inventory/adjustments', [StockAdjustmentController::class, 'store'])->name('inventory.adjustments.store');
    /*
     * إشعارات الاستلام — قراءةٌ فقط.
     *
     * لا تُكتب بيدٍ ولا تُحذف: تُنشئها لحظةُ استلام أمر الشراء شاهدةً على
     * واقعةٍ جرت. ونموذجٌ يُنشئ إشعارًا بلا استلامٍ يجعل الورقة تقول ما لم
     * يقله المخزون.
     */
    /*
     * سندات النقل بين الفروع — البابُ الذي لم يكن.
     *
     * وتحت `inventory` كأخواتها: من يملك المخزون ينقل بضاعته بين فروعه.
     */
    Route::get('/inventory/transfers', [StockTransferController::class, 'index'])->name('inventory.transfers');
    Route::post('/inventory/transfers', [StockTransferController::class, 'store'])->name('inventory.transfers.store');
    Route::get('/inventory/receipts', [GoodsReceiptNoteController::class, 'index'])->name('inventory.receipts');
    Route::post('/inventory/movements', [InventoryController::class, 'store'])->name('inventory.store');

    // المورّدون
    Route::get('/suppliers', [PageController::class, 'suppliersIndex'])->name('suppliers.index');
    // التصدير قبل {id}: لو جاء بعده لابتلع «export» مسارَ المورّد الواحد
    Route::get('/suppliers/export/xlsx', [SupplierExportController::class, 'xlsx'])->name('suppliers.export.xlsx');
    Route::get('/suppliers/export/pdf', [SupplierExportController::class, 'pdf'])->name('suppliers.export.pdf');
    Route::post('/suppliers/import', [SupplierExportController::class, 'upload'])->name('suppliers.import.upload');
    Route::get('/suppliers/import/preview', [SupplierExportController::class, 'preview'])->name('suppliers.import.preview');
    Route::post('/suppliers/import/confirm', [SupplierExportController::class, 'confirm'])->name('suppliers.import.confirm');
    Route::post('/suppliers/import/cancel', [SupplierExportController::class, 'cancel'])->name('suppliers.import.cancel');
    Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
    Route::put('/suppliers/{id}', [SupplierController::class, 'update'])->name('suppliers.update');
    Route::delete('/suppliers/{id}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');

    /*
     * المشتريات — ثلاث شاشات على بابين.
     *
     * القائمة تجمع ما اشتُري من البابين معًا (أمرٌ استُلم، وسندٌ بلا أمر)،
     * والسندات تحمل ما على المتجر لمورّديه، والأوامر تحمل ما طُلب ولم يصل.
     */
    Route::get('/purchases', [PurchaseRegisterController::class, 'index'])->name('purchases.index');
    Route::get('/purchases/invoices', [SupplierInvoiceController::class, 'index'])->name('purchases.invoices');
    Route::post('/purchases/invoices', [SupplierInvoiceController::class, 'store'])->name('purchases.invoices.store');
    Route::post('/purchases/invoices/{id}/pay', [SupplierInvoiceController::class, 'pay'])->name('purchases.invoices.pay');
    Route::delete('/purchases/invoices/{id}', [SupplierInvoiceController::class, 'destroy'])->name('purchases.invoices.destroy');
    Route::get('/purchases/orders', [PurchaseOrderController::class, 'index'])->name('purchases.orders');
    Route::get('/purchases/create', [PageController::class, 'purchasesCreate'])->name('purchases.create');
    Route::post('/purchases', [PurchaseOrderController::class, 'store'])->name('purchases.store');
    Route::post('/purchases/{id}/receipt', [PurchaseOrderController::class, 'uploadReceipt'])->name('purchases.receipt');
    Route::post('/purchases/{id}/receive', [PurchaseOrderController::class, 'receive'])->name('purchases.receive');
    Route::delete('/purchases/{id}', [PurchaseOrderController::class, 'destroy'])->name('purchases.destroy');

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
    Route::post('/marketing/website', [MarketingController::class, 'saveWebsite'])->name('marketing.website.save');
    Route::get('/marketing/seo', [MarketingController::class, 'seo'])->name('marketing.seo');
    Route::post('/marketing/seo', [MarketingController::class, 'saveSeo'])->name('marketing.seo.save');
    Route::post('/marketing/seo/refresh', [MarketingController::class, 'refreshSeo'])->name('marketing.seo.refresh');
    Route::get('/marketing/loyalty', [MarketingController::class, 'loyalty'])->name('marketing.loyalty');
    Route::post('/marketing/loyalty', [MarketingController::class, 'saveLoyalty'])->name('marketing.loyalty.save');
    /*
     * واتساب — ما يملكه التاجر: وضع الإرسال وربط رقمه.
     *
     * ومعرّف متجره يُقرأ من جلسته في المتحكّم لا ممّا يصل في الطلب — انظر
     * `Admin\WhatsAppController::bid`.
     */
    /*
     * بريد الاستعادة — يضبطه صاحب الحساب وهو داخل، قبل أن يحتاج إليه.
     * ويُشترط معه كلمةُ المرور الحالية: جلسةٌ مفتوحة وحدها لا تكفي.
     */
    /*
     * الدومين — أربعةُ أفعالٍ خارج «حفظ النطاق».
     *
     * ذاك يحفظ حقلًا واحدًا، وهذه لكلٍّ منها تحقّقُه: اسمُ نطاقٍ فرعيّ لا
     * يُقاس بمقياس نطاقٍ كامل، والطلب صفٌّ يُنشأ لا إعدادٌ يُكتب. وأسماؤها
     * تحت `settings` فتقع على صلاحيّتها — انظر Permissions::sectionFromRoute.
     */
    Route::post('/settings/domain/mode', [DomainController::class, 'mode'])->name('settings.domain.mode');
    Route::post('/settings/domain/subdomain', [DomainController::class, 'subdomain'])->name('settings.domain.subdomain');
    Route::post('/settings/domain/request', [DomainController::class, 'requestDomain'])->name('settings.domain.request');
    Route::delete('/settings/domain/request/{id}', [DomainController::class, 'cancelRequest'])->name('settings.domain.request.cancel');

    Route::post('/settings/recovery-email', [RecoveryEmailController::class, 'start'])->name('settings.recovery.start');
    Route::post('/settings/recovery-email/confirm', [RecoveryEmailController::class, 'confirm'])->name('settings.recovery.confirm');
    Route::post('/marketing/whatsapp/mode', [App\Http\Controllers\Admin\WhatsAppController::class, 'mode'])->name('marketing.whatsapp.mode');
    Route::post('/marketing/whatsapp/connect', [App\Http\Controllers\Admin\WhatsAppController::class, 'connect'])->name('marketing.whatsapp.connect');
    Route::delete('/marketing/whatsapp/connect', [App\Http\Controllers\Admin\WhatsAppController::class, 'disconnect'])->name('marketing.whatsapp.disconnect');
    /*
     * ربط خرائط Google — صفحةٌ في النظام لا رابطٌ يخرج منه.
     *
     * كان زرًّا يفتح `business.google.com` في تبويبٍ خارجيّ: اسمُه «ربط» ولا
     * يربط شيئًا — يُخرج التاجر من لوحته ولا يعود بمعرّفٍ ولا يُحفظ شيء.
     */
    Route::get('/marketing/google', [MarketingController::class, 'google'])->name('marketing.google');
    Route::post('/marketing/google', [MarketingController::class, 'saveGoogle'])->name('marketing.google.save');
    Route::post('/marketing/google/key', [MarketingController::class, 'saveGoogleKey'])->name('marketing.google.key');
    Route::delete('/marketing/google/key', [MarketingController::class, 'forgetGoogleKey'])->name('marketing.google.key.forget');
    Route::post('/marketing/google/refresh', [MarketingController::class, 'refreshGoogle'])->name('marketing.google.refresh');
    Route::get('/marketing/reviews', [ReviewController::class, 'index'])->name('marketing.reviews');
    Route::post('/marketing/reviews', [ReviewController::class, 'store'])->name('marketing.reviews.store');
    Route::post('/marketing/reviews/{id}/status', [ReviewController::class, 'status'])->name('marketing.reviews.status');
    Route::post('/marketing/reviews/{id}/reply', [ReviewController::class, 'reply'])->name('marketing.reviews.reply');
    Route::delete('/marketing/reviews/{id}', [ReviewController::class, 'destroy'])->name('marketing.reviews.destroy');
    Route::get('/marketing/coupons', [MarketingController::class, 'coupons'])->name('marketing.coupons');
    Route::get('/marketing/whatsapp', [MarketingController::class, 'whatsapp'])->name('marketing.whatsapp');
    Route::post('/marketing/whatsapp', [MarketingController::class, 'saveWhatsapp'])->name('marketing.whatsapp.save');

    Route::post('/coupons', [CouponController::class, 'store'])->name('coupons.store');
    Route::post('/coupons/{id}/toggle', [CouponController::class, 'toggle'])->name('coupons.toggle');
    Route::delete('/coupons/{id}', [CouponController::class, 'destroy'])->name('coupons.destroy');

    // ضريبة القيمة المضافة
    Route::get('/orders/{number}/tax-invoice', [PdfController::class, 'taxInvoice'])->name('orders.taxInvoice');

    /*
     * المالية — خمس شاشات على دفترٍ واحد.
     *
     * الحسابات البنكية والقيود اليومية وشجرة الحسابات والمصاريف الشهرية
     * والأصول الثابتة. وكلّها تكتب من باب `Ledger::post` وحده، فلا يدخل
     * الدفترَ قيدٌ لم يُفحص توازنه.
     */
    Route::get('/finance', [BankAccountController::class, 'index'])->name('finance.index');
    Route::post('/finance/banks', [BankAccountController::class, 'store'])->name('finance.banks.store');
    Route::put('/finance/banks/{id}', [BankAccountController::class, 'update'])->name('finance.banks.update');
    Route::post('/finance/banks/{id}/primary', [BankAccountController::class, 'primary'])->name('finance.banks.primary');
    Route::delete('/finance/banks/{id}', [BankAccountController::class, 'destroy'])->name('finance.banks.destroy');
    // كشف الحساب البنكي والمطابقة — بلا معرّف: الحساب الرئيسيّ
    Route::get('/finance/statement/{id?}', [BankAccountController::class, 'statement'])->name('finance.statement');

    // شجرة الحسابات
    Route::get('/finance/chart', [ChartController::class, 'index'])->name('finance.chart');
    Route::post('/finance/chart', [ChartController::class, 'store'])->name('finance.chart.store');
    Route::put('/finance/chart/{id}', [ChartController::class, 'update'])->name('finance.chart.update');
    Route::post('/finance/chart/{id}/toggle', [ChartController::class, 'toggle'])->name('finance.chart.toggle');
    Route::delete('/finance/chart/{id}', [ChartController::class, 'destroy'])->name('finance.chart.destroy');

    // القيود اليومية
    Route::get('/finance/journal', [JournalController::class, 'index'])->name('finance.journal');
    Route::post('/finance/journal', [JournalController::class, 'store'])->name('finance.journal.store');

    // الأصول الثابتة وإهلاكها
    Route::get('/finance/assets', [FixedAssetController::class, 'index'])->name('finance.assets');
    Route::post('/finance/assets', [FixedAssetController::class, 'store'])->name('finance.assets.store');
    Route::post('/finance/assets/depreciate', [FixedAssetController::class, 'depreciate'])->name('finance.assets.depreciate');
    Route::post('/finance/assets/{id}/dispose', [FixedAssetController::class, 'dispose'])->name('finance.assets.dispose');
    Route::delete('/finance/assets/{id}', [FixedAssetController::class, 'destroy'])->name('finance.assets.destroy');
    // الورديات المُقفلة وفروقها — يقرؤها من يملك «المالية» (sectionFromRoute)
    // تقرير إقفال الوردية (Z) — على ورق الإيصال، يُوقَّع عند تسليم الدرج
    // إقفال وردية نسيها الكاشير — بلا عدّ، وفرقُها يبقى مجهولًا
    Route::post('/bank/import', [BankStatementController::class, 'import'])->name('bank.import');
    Route::post('/bank/rematch', [BankStatementController::class, 'rematch'])->name('bank.rematch');
    Route::delete('/bank/clear', [BankStatementController::class, 'clear'])->name('bank.clear');
    Route::get('/finance/xlsx', [ReportExportController::class, 'financeXlsx'])->name('finance.xlsx');
    Route::get('/finance/pdf', [PdfController::class, 'financeReport'])->name('finance.pdf');
    Route::post('/finance/transactions', [FinanceController::class, 'store'])->name('finance.store');
    Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::get('/expenses/xlsx', [ReportExportController::class, 'expensesXlsx'])->name('expenses.xlsx');
    Route::get('/expenses/export-pdf', [PdfController::class, 'expensesReport'])->name('expenses.exportPdf');
    Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
    Route::post('/expenses/{id}/paid', [ExpenseController::class, 'markPaid'])->name('expenses.paid');
    Route::delete('/expenses/{id}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
    Route::post('/expenses/{id}/restore', [TrashController::class, 'restore'])
        ->defaults('type', 'expense')->name('expenses.restore');
    Route::delete('/expenses/{id}/purge', [TrashController::class, 'purge'])
        ->defaults('type', 'expense')->name('expenses.purge');
    // أنواع المصروفات
    Route::post('/expense-types', [ExpenseTypeController::class, 'store'])->name('expenseTypes.store');
    Route::put('/expense-types/{id}', [ExpenseTypeController::class, 'update'])->name('expenseTypes.update');
    Route::delete('/expense-types/{id}', [ExpenseTypeController::class, 'destroy'])->name('expenseTypes.destroy');
    // ملخّص المبيعات — كان محتوى /reports نفسه قبل أن يصير الفهرس بابها

    // تغذية التقارير (Polling) — صفحة تُترك مفتوحة لا يجوز أن تتجمّد على أرقام الصباح

    Route::post('/goals', [GoalController::class, 'update'])->name('goals.update');

    // إشعارات المتصفح (Polling)
    Route::get('/notifications/feed', [NotificationController::class, 'feed'])->name('notifications.feed');
    Route::post('/notifications/dismiss', [NotificationController::class, 'dismiss'])->name('notifications.dismiss');
    Route::post('/notifications/clear', [NotificationController::class, 'clear'])->name('notifications.clear');

    // النسخ الاحتياطي والاستعادة
    Route::get('/backup/download', [BackupController::class, 'download'])->name('backup.download');
    Route::post('/backup/restore', [BackupController::class, 'restore'])->name('backup.restore');

    // تصدير CSV
    Route::get('/export/reports', [ExportController::class, 'reports'])->name('export.reports');
    Route::get('/export/products', [ExportController::class, 'products'])->name('export.products');
    Route::get('/export/orders', [ExportController::class, 'orders'])->name('export.orders');
    Route::get('/export/customers', [ExportController::class, 'customers'])->name('export.customers');
    Route::get('/export/suppliers', [ExportController::class, 'suppliers'])->name('export.suppliers');
    Route::get('/export/transactions', [ExportController::class, 'transactions'])->name('export.transactions');
    Route::get('/export/expenses', [ExportController::class, 'expenses'])->name('export.expenses');
    Route::get('/export/inventory', [ExportController::class, 'inventory'])->name('export.inventory');

    /*
     * التقارير — أُعيدت بعد حذفها في d34f32e.
     *
     * الفهرس بابٌ لا شاشةُ أرقام: يجمع ما تفرّق في اثنتي عشرة شاشة ويقود
     * إليها. وملخّص المبيعات وحده تقريرٌ قائمٌ بذاته تحته.
     */
    Route::get('/reports', [PageController::class, 'reportsIndex'])->name('reports.index');
    Route::get('/reports/sales', [PageController::class, 'reportsSales'])->name('reports.sales');
    /*
     * ولكلِّ تقريرٍ صفحته.
     *
     * كانت هذه الثلاثة تُعرض في نافذةٍ واحدة بقالبٍ واحد: بلا مبدّل فترة —
     * محسوبةً على الشهر وحده ولا شيء يقول ذلك — وبلا مؤشّرات، وبلا رابطٍ
     * يُفتح أو يُرسَل. وصلاحيةُ كلٍّ قسمُه لا «التقارير» (انظر الحارس في
     * ReportPageController): فيها مبيعاتُ الموظفين وإنفاقُ العملاء.
     */
    Route::get('/reports/payments', [ReportPageController::class, 'payments'])->name('reports.payments');
    Route::get('/reports/staff', [ReportPageController::class, 'staff'])->name('reports.staff');
    Route::get('/reports/customers', [ReportPageController::class, 'customers'])->name('reports.customers');
    /*
     * وبقيّةُ الفهرس تبعتها.
     *
     * كانت بطاقاتُها تقود إلى **شاشات الأقسام**: «تقرير الطلبات» يفتح شاشة
     * إدارة الطلبات وفيها زرُّ تعديلٍ وزرُّ حذف — فمن دخل ليقرأ وجد نفسه في
     * موضع الكتابة. وشاشةُ القسم لا تُجيب سؤال التقرير أصلًا: لا فترةَ
     * تُختار ولا مؤشّراتٍ فوق الجدول.
     *
     * وشاشاتُ الأقسام باقيةٌ في القائمة الجانبية كما هي — تغيّرت وجهةُ
     * البطاقة لا الشاشة.
     */
    Route::get('/reports/finance', [ReportPageController::class, 'finance'])->name('reports.finance');
    Route::get('/reports/expenses', [ReportPageController::class, 'expenses'])->name('reports.expenses');
    Route::get('/reports/bank', [ReportPageController::class, 'bank'])->name('reports.bank');
    Route::get('/reports/orders', [ReportPageController::class, 'orders'])->name('reports.orders');
    Route::get('/reports/products', [ReportPageController::class, 'products'])->name('reports.products');
    Route::get('/reports/inventory', [ReportPageController::class, 'inventory'])->name('reports.inventory');
    Route::get('/reports/purchases', [ReportPageController::class, 'purchases'])->name('reports.purchases');
    Route::get('/reports/suppliers', [ReportPageController::class, 'suppliers'])->name('reports.suppliers');
    Route::get('/reports/activity', [ReportPageController::class, 'activity'])->name('reports.activity');
    Route::get('/reports/marketing', [ReportPageController::class, 'marketing'])->name('reports.marketing');
    // عمليات جرد المخزون — أين فارق الدفترُ الواقع، وبكم
    Route::get('/reports/stocktake', [ReportPageController::class, 'stocktake'])->name('reports.stocktake');

    /*
     * تنزيلُ أيّ تقريرٍ بالصيغ الثلاث المعتمدة.
     *
     * وبابٌ واحد لا ثلاثةٌ لكل تقرير: ستّةَ عشرَ تقريرًا في ثلاث صيغ تعني
     * ثمانيةً وأربعين مسارًا تتفرّق أعمدتُها عن أعمدة الشاشة. والحارس على
     * قسم التقرير نفسه لا على «التقارير» — انظر ReportDownloadController.
     */
    Route::get('/reports/{report}/export/xlsx', [ReportDownloadController::class, 'xlsx'])->name('reports.export.xlsx');
    Route::get('/reports/{report}/export/csv', [ReportDownloadController::class, 'csv'])->name('reports.export.csv');
    Route::get('/reports/{report}/export/pdf', [ReportDownloadController::class, 'pdf'])->name('reports.export.pdf');
    // تغذية: صفحةٌ تُترك مفتوحة لا يجوز أن تتجمّد على أرقام الصباح
    Route::get('/reports/feed', [ReportFeedController::class, 'reports'])->name('reports.feed');
    // والتصدير يتبع الفترة المعروضة — ملفٌّ يحمل غير ما على الشاشة يُقرأ خطأً
    Route::get('/reports/xlsx', [ReportExportController::class, 'xlsx'])->name('reports.xlsx');
    Route::get('/reports/pdf', [PdfController::class, 'salesReport'])->name('reports.pdf');
    /*
     * تحليلات الهالك.
     *
     * تحت `reports` لا تحت `inventory` كي يقيسها حارس المسار بصلاحية
     * التقارير — وهي قراءةٌ لا كتابة. ومن يملك المخزون يملك تسجيل الهالك
     * من شاشته، ومن يملك التقارير يقرأ أثره.
     */
    Route::get('/reports/waste', [WasteAnalyticsController::class, 'index'])->name('reports.waste');
    Route::get('/reports/vat', [ReportPageController::class, 'vat'])->name('reports.vat');

    Route::get('/activity', [ActivityController::class, 'adminIndex'])->name('activity.index');
    Route::get('/settings', [PageController::class, 'settingsIndex'])->name('settings.index');
    Route::post('/language', [LanguageController::class, 'update'])->name('language.update');
    Route::post('/settings', [App\Http\Controllers\Admin\SettingController::class, 'update'])->name('settings.update');
    // الشعار وحده: ملفٌّ يحتاج multipart، وخلطُه بنموذج الإعدادات يُرسل كل مقبضٍ نصًّا
    Route::post('/settings/logo', [App\Http\Controllers\Admin\SettingController::class, 'logo'])->name('settings.logo');

    // المحذوفات — استعادة ما أذهبته ضغطة (انظر TrashController)
    Route::get('/settings/trash', [TrashController::class, 'index'])->name('settings.trash');

    // تنبيهات يعرّفها صاحب النشاط — قواعد على مقاييس النظام، وتذكيرات بموعد
    Route::post('/alerts', [CustomAlertController::class, 'store'])->name('alerts.store');
    Route::put('/alerts/{id}', [CustomAlertController::class, 'update'])->name('alerts.update');
    Route::delete('/alerts/{id}', [CustomAlertController::class, 'destroy'])->name('alerts.destroy');
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
    Route::get('/', [App\Http\Controllers\Pos\PageController::class, 'index'])->name('index');

    /*
     * إعداد الجهاز: يقع مرّةً واحدة يوم التركيب، بيد مديرٍ يملك الإعدادات.
     * بعده لا يرى الكاشير هذه الشاشة أبدًا — يفتح فيجد لوحة الأرقام.
     */
    Route::get('/setup', [DeviceController::class, 'setup'])->name('setup');
    Route::post('/setup', [DeviceController::class, 'activate'])->name('setup.activate');

    /*
     * قفل الشاشة: يُنهي جلسة الموظف ويُبقي تفعيل الجهاز.
     * تبديل الكاشير عشر ثوانٍ، لا دخول مديرٍ من جديد.
     */
    Route::post('/lock', [App\Http\Controllers\Pos\PageController::class, 'lock'])->name('lock');

    // اختيار الموظف الواقف على الصندوق. ليس دخولًا ولا خروجًا — الصلاحيات
    // تبقى للمستخدم المسجَّل، وهذا يحدّد من تُنسب إليه البيعة فقط.
    Route::get('/cashier', [CashierController::class, 'choose'])->name('cashier');
    Route::post('/cashier', [CashierController::class, 'select'])->name('cashier.select');
    Route::post('/cashier/leave', [CashierController::class, 'leave'])->name('cashier.leave');
    Route::get('/currency/{code}/switch', [CurrencyController::class, 'switch'])->name('currency.switch');

    Route::post('/checkout', [PosController::class, 'checkout'])->name('checkout');
    Route::post('/coupon', [PosController::class, 'applyCoupon'])->name('coupon.apply');
    Route::post('/hold', [PosController::class, 'hold'])->name('hold');
    Route::get('/stock-feed', [PosController::class, 'stockFeed'])->name('stock-feed');
    Route::get('/orders', [App\Http\Controllers\Pos\PageController::class, 'orders'])->name('orders');
    Route::get('/orders/{id}/resume', [PosController::class, 'resume'])->name('orders.resume');
    Route::delete('/orders/{id}', [PosController::class, 'discard'])->name('orders.discard');
    /*
     * تصحيح بندٍ في فاتورة — لا إلغاء لها.
     *
     * الكاشير يُخطئ أمام الزبون فيُصلح، والأثر يبقى: ما كان وما صار ومن
     * غيّره ولماذا. والكتابة كلّها في `App\Support\OrderCorrection`.
     * ويسبق مسارَ العرض: «orders/{number}» يبتلع ما بعده لو تأخّر.
     */
    Route::put('/orders/{number}/items/{item}', [OrderEditController::class, 'update'])->name('orders.items.update');
    Route::put('/orders/{number}/items/{item}/addons/{addon}', [OrderEditController::class, 'addon'])->name('orders.items.addons.update');
    Route::put('/orders/{number}/payment', [OrderEditController::class, 'payment'])->name('orders.payment.update');
    Route::get('/orders/{number}', [App\Http\Controllers\Pos\PageController::class, 'orderDetails'])->name('order-details');
    // المدفوعات تسقط على صلاحية finance لا pos (انظر sectionFromRoute):
    // شاشةٌ مالية تعرض حصيلة الصندوق، فيراها صاحب النشاط والمدير والمحاسب
    // ويُمنع منها الكاشير — وإن كان يملك نقطة البيع.
    Route::get('/payments', [App\Http\Controllers\Pos\PageController::class, 'payments'])->name('payments');
    Route::get('/receipts', [App\Http\Controllers\Pos\PageController::class, 'receipts'])->name('receipts');
    Route::get('/receipts/search', [PosController::class, 'searchReceipts'])->name('receipts.search');
    // تفصيل فاتورة واحدة — تُطلب عند النقر، فلا تُرسَل مبالغ الثلاثين دفعةً
    Route::get('/receipts/{number}', [PosController::class, 'showReceipt'])->name('receipts.show');
    Route::get('/receipt/{number}/pdf', [PdfController::class, 'orderReceipt'])->name('receipt.pdf');
    Route::get('/customers', [App\Http\Controllers\Pos\PageController::class, 'customers'])->name('customers');
    Route::post('/customers', [PosController::class, 'storeCustomer'])->name('customers.store');
    // مناسبةٌ جديدة تُضاف من نافذة الدفع نفسها — تُحفظ للمتجر وتظهر في قائمته
    Route::post('/occasions', [PosController::class, 'storeOccasion'])->name('occasions.store');
    /*
     * «حسابي» — بيانات الموظّف وراتبه ومبيعاته هو.
     *
     * تحت `pos` لأنّ صاحبها لا يدخل لوحة النشاط: الكاشير كان زرُّ اللوحة
     * يغيب عنه فلا يجد بابًا إلى شيءٍ يخصّه. والحارس نفسه — صلاحية «نقطة
     * البيع» — لأنّ كلّ ما فيها بياناتُ من يفتحها (انظر MeController).
     */
    Route::get('/me', [MeController::class, 'show'])->name('me');
    Route::get('/settings', [App\Http\Controllers\Pos\PageController::class, 'settings'])->name('settings');
    Route::post('/language', [LanguageController::class, 'update'])->name('language.update');
});

/*
 * إشعارات ميتا — بابٌ عامّ بلا جلسة، وحارسه توقيعٌ لا كلمة سرّ.
 *
 * خارج مجموعات الحماية كلّها لأنّ من يُنادينا خادمُ ميتا لا متصفّح مستخدم:
 * لا جلسة له ولا رمز CSRF. والتحقّق في المتحكّم — توقيع HMAC بسرّ التطبيق —
 * ولا يُقبل شيءٌ بدونه.
 */
Route::get('/webhooks/whatsapp', [WebhookController::class, 'verify'])->name('webhooks.whatsapp.verify');
Route::post('/webhooks/whatsapp', [WebhookController::class, 'handle'])->name('webhooks.whatsapp');
