<?php

namespace App\Support\RibbonPortal;

/** نصوصُ البوابة بلغتيها — من موضعٍ واحد كنصوص المتجر (`Store\RibbonTexts`) */
final class Texts
{
    public const LANGS = ['ar', 'en'];

    private const AR = [
        'title' => 'بوابة العملاء',
        'langBtn' => 'English',
        'refresh' => 'تحديث',
        'logout' => 'تسجيل الخروج',
        'logoutAsk' => 'تسجيل الخروج ينسى هذا الجهاز. ولتدخل منه مرة أخرى يعتمده موظف :shop من جديد.',
        'logoutNo' => 'البقاء',
        'updated' => 'آخر تحديث',
        'poweredBy' => 'Powered by ABAAD',

        // الدخول
        'welcomeGuest' => 'مشترياتك وفواتيرك ونقاطك في مكان واحد',
        'phoneLabel' => 'رقم هاتفك المسجّل لدينا',
        'phoneHint' => 'الرقم الذي أعطيته للمحل عند الشراء.',
        'phoneShort' => 'اكتب رقم هاتفك كاملًا.',
        'enter' => 'دخول',
        'entering' => 'لحظة…',
        'waitTitle' => 'بقيت خطوة واحدة',
        'waitWhy' => 'حتى لا يرى مشترياتك أحد غيرك، يعتمد موظف :shop هذا الجهاز مرة واحدة فقط.',
        'waitNumber' => 'رقم جهازك',
        'waitHow' => 'أرِ الموظف هذا الرقم، وستفتح صفحتك وحدها.',
        'waitFar' => 'لست في المحل؟ اطلب أن نتصل بك على رقمك المسجّل، وأخبر الموظف بهذا الرقم.',
        'waitLeft' => 'يصلح الرقم',
        'waiting' => 'بانتظار اعتماد الجهاز',
        'waitNote' => 'الخدمة لعملاء :shop المسجّلين.',
        'waitOver' => 'انتهى رقم جهازك قبل اعتماده.',
        'again' => 'طلب رقم جديد',
        'changePhone' => 'تغيير الرقم',
        'tooMany' => 'محاولات كثيرة. حاول بعد :n دقيقة.',
        'slowDown' => 'محاولات كثيرة. انتظر دقيقة وحاول من جديد.',
        'networkError' => 'تعذّر الاتصال. تحقّق من الشبكة وحاول من جديد.',
        'needJs' => 'هذه الصفحة تحتاج إلى تفعيل JavaScript في متصفحك.',

        // الحساب
        'hello' => 'أهلًا :name',
        'helloSub' => 'هنا كل مشترياتك وفواتيرك ورصيد نقاطك لدينا.',
        'points' => 'نقاط الولاء',
        'pointsUnit' => 'نقطة',
        'totalSpent' => 'إجمالي مشترياتك',
        'purchasesCount' => 'عدد المشتريات',
        'lastPurchase' => 'آخر شراء',
        'none' => '—',

        // المشتريات
        'purchases' => 'مشترياتك',
        'searchNumber' => 'ابحث برقم الفاتورة',
        'from' => 'من',
        'to' => 'إلى',
        'apply' => 'عرض',
        'clear' => 'مسح',
        'more' => 'عرض المزيد',
        'empty' => 'لا توجد مشتريات مسجّلة باسمك بعد.',
        'emptyFiltered' => 'لا توجد مشتريات بهذا البحث.',
        'view' => 'عرض الفاتورة',
        'pdf' => 'تنزيل PDF',
        'open' => 'فتح PDF',
        'print' => 'طباعة',
        'back' => 'رجوع',
        'total' => 'الإجمالي',
        'credited' => 'إشعار دائن',
        'cover' => 'فاتورة',
        'state.paid' => 'مدفوعة',
        'state.partial' => 'مدفوعة جزئيًا',
        'state.unpaid' => 'غير مدفوعة',
        'state.void' => 'ملغاة',
        'kind.pos' => 'شراء من المحل',
        'kind.website' => 'طلب من المتجر الإلكتروني',
        'kind.order' => 'طلب',
        'kind.invoice' => 'فاتورة',

        // النقاط
        'pointsTitle' => 'نقاط الولاء',
        'pointsBalance' => 'رصيدك المتاح',
        'pointsEarned' => 'المكتسبة',
        'pointsUsed' => 'المستخدمة',
        'pointsHow' => 'كيف تستفيد من نقاطك',
        'pointsHistory' => 'سجل النقاط',
        'pointsNone' => 'لا توجد حركات نقاط بعد.',
        'balanceAfter' => 'الرصيد بعدها',
        'move.earned' => 'نقاط مكتسبة',
        'move.used' => 'نقاط مستخدمة',
        'move.returned' => 'نقاط أُعيدت إليك',
        'move.reversed' => 'نقاط أُلغيت مع عملية ملغاة',
        'move.corrected' => 'تصحيح مع تعديل فاتورة',
        'move.adjusted' => 'تصحيح رصيد',
        'rule.off' => 'برنامج النقاط غير مفعّل حاليًا.',
        'rule.earn' => 'تكسب :n نقطة عن كل :amount تشتري بها.',
        'rule.noRedeem' => 'استبدال النقاط غير متاح حاليًا.',
        'rule.fixed' => 'كل :n نقطة تساوي خصم :amount.',
        'rule.percent' => 'كل :n نقطة تساوي خصم :p% من قيمة الفاتورة المؤهلة، فتتبع قيمتها فاتورتك.',
        'rule.minPoints' => 'يبدأ الاستبدال من :n نقطة.',
        'rule.minInvoice' => 'للفواتير من :amount فأكثر.',
        'rule.maxPct' => 'لا يتجاوز الخصم :p% من الفاتورة.',
        'rule.maxAmount' => 'ولا يتجاوز :amount في الفاتورة الواحدة.',
        'rule.where' => 'أخبر الموظف عند الدفع برغبتك في استخدام نقاطك.',
    ];

    private const EN = [
        'title' => 'Customer portal',
        'langBtn' => 'العربية',
        'refresh' => 'Refresh',
        'logout' => 'Sign out',
        'logoutAsk' => 'Signing out forgets this device. To enter from it again, :shop staff approve it once more.',
        'logoutNo' => 'Stay',
        'updated' => 'Last updated',
        'poweredBy' => 'Powered by ABAAD',

        'welcomeGuest' => 'Your purchases, invoices and points in one place',
        'phoneLabel' => 'Your phone number registered with us',
        'phoneHint' => 'The number you gave the shop when you bought.',
        'phoneShort' => 'Enter your full phone number.',
        'enter' => 'Enter',
        'entering' => 'One moment…',
        'waitTitle' => 'One step left',
        'waitWhy' => 'So that no one else sees your purchases, :shop staff approve this device once only.',
        'waitNumber' => 'Your device number',
        'waitHow' => 'Show this number to our staff and your page will open by itself.',
        'waitFar' => 'Not in the shop? Ask us to call you on your registered number, and give our staff this number.',
        'waitLeft' => 'Valid for',
        'waiting' => 'Waiting for the device to be approved',
        'waitNote' => 'For registered :shop customers.',
        'waitOver' => 'Your device number ended before it was approved.',
        'again' => 'Get a new number',
        'changePhone' => 'Change number',
        'tooMany' => 'Too many attempts. Try again in :n minutes.',
        'slowDown' => 'Too many attempts. Wait a minute and try again.',
        'networkError' => 'No connection. Check your network and try again.',
        'needJs' => 'This page needs JavaScript enabled in your browser.',

        'hello' => 'Welcome, :name',
        'helloSub' => 'All your purchases, invoices and points with us.',
        'points' => 'Loyalty points',
        'pointsUnit' => 'points',
        'totalSpent' => 'Total purchases',
        'purchasesCount' => 'Number of purchases',
        'lastPurchase' => 'Last purchase',
        'none' => '—',

        'purchases' => 'Your purchases',
        'searchNumber' => 'Search by invoice number',
        'from' => 'From',
        'to' => 'To',
        'apply' => 'Show',
        'clear' => 'Clear',
        'more' => 'Show more',
        'empty' => 'No purchases recorded under your name yet.',
        'emptyFiltered' => 'No purchases match this search.',
        'view' => 'View invoice',
        'pdf' => 'Download PDF',
        'open' => 'Open PDF',
        'print' => 'Print',
        'back' => 'Back',
        'total' => 'Total',
        'credited' => 'Credit note',
        'cover' => 'Invoice',
        'state.paid' => 'Paid',
        'state.partial' => 'Partly paid',
        'state.unpaid' => 'Unpaid',
        'state.void' => 'Cancelled',
        'kind.pos' => 'Bought in store',
        'kind.website' => 'Online store order',
        'kind.order' => 'Order',
        'kind.invoice' => 'Invoice',

        'pointsTitle' => 'Loyalty points',
        'pointsBalance' => 'Available balance',
        'pointsEarned' => 'Earned',
        'pointsUsed' => 'Used',
        'pointsHow' => 'How to use your points',
        'pointsHistory' => 'Points history',
        'pointsNone' => 'No points activity yet.',
        'balanceAfter' => 'Balance after',
        'move.earned' => 'Points earned',
        'move.used' => 'Points used',
        'move.returned' => 'Points returned to you',
        'move.reversed' => 'Points cancelled with a cancelled sale',
        'move.corrected' => 'Corrected with an invoice edit',
        'move.adjusted' => 'Balance correction',
        'rule.off' => 'The points programme is not active at the moment.',
        'rule.earn' => 'You earn :n points for every :amount you spend.',
        'rule.noRedeem' => 'Redeeming points is not available at the moment.',
        'rule.fixed' => 'Every :n points give :amount off.',
        'rule.percent' => 'Every :n points give :p% off the eligible invoice, so their value follows your invoice.',
        'rule.minPoints' => 'Redeeming starts at :n points.',
        'rule.minInvoice' => 'On invoices of :amount or more.',
        'rule.maxPct' => 'The discount is at most :p% of the invoice.',
        'rule.maxAmount' => 'And at most :amount per invoice.',
        'rule.where' => 'Tell our staff at checkout that you want to use your points.',
    ];

    /** @return array<string, string> */
    public static function all(string $lang): array
    {
        return $lang === 'en' ? self::EN : self::AR;
    }

    public static function say(string $lang, string $key, array $with = []): string
    {
        $text = self::all($lang)[$key] ?? $key;
        foreach ($with as $name => $value) {
            $text = str_replace(':'.$name, (string) $value, $text);
        }

        return $text;
    }
}
