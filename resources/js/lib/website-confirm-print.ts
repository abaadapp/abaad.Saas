import { toast } from 'sonner';

/**
 * «طباعة تلقائية عند تأكيد طلب الموقع» — تقرؤها شاشةُ الطلب ولوحةُ التجهيز معًا.
 *
 * ═══ والنافذةُ تُفتح في الضغطة نفسِها ═══
 *
 * كما في `PaymentDialog`: المتصفّحاتُ لا تفتح نافذةً إلّا من إيماءةِ مستخدمٍ
 * حيّة، وما يُفتح بعد انتظار الخادم يبتلعه مانعُ النوافذ صامتًا. فتُفتح
 * فارغةً عند الضغط إن كانت الشروطُ كلُّها قائمة، ثمّ:
 *
 *   - نجح النقلُ وقال الخادمُ إنّه «جديد ← مؤكّد» لهذا الطلب (`flash.websiteConfirmed`)
 *     ← تُوجَّه النافذةُ إلى الإيصال الحراريّ.
 *   - رُفض النقلُ أو لم يقلها الخادم ← تُغلق النافذةُ الفارغة.
 *   - منعها المتصفّح ← يُقال ذلك، ومعه زرُّ طباعةٍ يدويّة.
 *
 * ولا شيءَ هنا يعمل على تحديثٍ دوريّ أو إعادة رسم: الطباعةُ معلّقةٌ بالضغطة
 * وحدها، فلا يُطبع طلبٌ مرّتين.
 *
 * ═══ وما لا تفعله ═══
 *
 * لا تطبع على طابعةٍ غير طابعة الصندوق المربوط بهذا المتصفّح. هاتفٌ لا صندوقَ
 * عليه لا يُفتح فيه شيء — ولا جسرَ في النظام يوصل أمرًا إلى طابعة المحلّ.
 */

export const WEBSITE = 'website';
/** «جديد» و«مؤكّد» كما في `OrderStatus::PENDING` و`CONFIRMED` — بالشدّة */
export const PENDING = 'جديد';
export const CONFIRMED = 'مؤكّد';

const PRINTER = 'طابعة';

interface Peripheral {
    type: string;
    autoPrintWebsite?: boolean;
}

export interface WebsiteConfirmInput {
    /** ملحقاتُ الصندوق المربوط بهذا المتصفّح النشطة — `context.peripherals` */
    peripherals?: Peripheral[] | null;
    /**
     * بابُ الإيصال الحراريّ — تختاره الشاشةُ من قسمها هي، لا من صلاحيّاتٍ أخرى.
     * `admin.orders.receipt` في شاشة الطلب، و`admin.preparation.receipt` في
     * اللوحة. وكلاهما `PdfController::orderThermal`.
     */
    receipt: string;
    channel?: string | null;
    /** حالُ الطلب كما تراها الشاشة قبل الضغط */
    from: string;
    to: string;
}

export interface PrintAttempt {
    /** `null` حين منعها المتصفّح */
    sheet: Window | null;
    route: string;
    settled: boolean;
}

/*
 * ولا يُختار البابُ من «الطلبات» أو «نقطة البيع»: من خُصّص له «لوحة التجهيز»
 * وحدها يؤكّد الطلبَ منها، ولو فُتحت له الورقةُ من بابٍ ليس له لردّ ٤٠٣.
 * فكلُّ شاشةٍ تفتحها من بابٍ في قسمها — ومن بلغ الشاشةَ بلغ بابَها.
 */

/** أتُفتح النافذةُ في هذه الضغطة؟ */
export function wantsWebsiteConfirmPrint(input: WebsiteConfirmInput): boolean {
    return (
        input.channel === WEBSITE &&
        input.from === PENDING &&
        input.to === CONFIRMED &&
        !!input.peripherals?.some((p) => p.type === PRINTER && p.autoPrintWebsite)
    );
}

/** يُنادى في معالج الضغطة نفسِه — قبل أيّ انتظار */
export function prepareWebsiteConfirmPrint(input: WebsiteConfirmInput): PrintAttempt | null {
    if (!wantsWebsiteConfirmPrint(input)) return null;

    return {
        // وبلا `noopener`: معه تردّ `open` قيمةً فارغة فلا يبقى مقبضٌ يُوجَّه — انظر PaymentDialog
        sheet: window.open('', '_blank'),
        route: input.receipt,
        settled: false,
    };
}

/**
 * ما يُضاف إلى نداء النقل — `onSuccess` يطبع، و`onFinish` يُغلق ما بقي فارغًا.
 *
 * و`onFinish` لا `onError` وحده: نداءٌ أُلغي أو سقط بخطأ شبكة لا يمرّ بـ`onError`،
 * ولا يُترك له لسانٌ أبيضُ مفتوح لا يُعرف لمَ فُتح.
 */
export function websiteConfirmPrintCallbacks(
    attempt: PrintAttempt | null,
    number: string,
    t: (key: string) => string,
) {
    return {
        onSuccess: (page: { props: unknown }) => {
            if (!attempt || attempt.settled) return;
            attempt.settled = true;

            const confirmed = (page.props as { flash?: { websiteConfirmed?: string | null } }).flash
                ?.websiteConfirmed;

            if (confirmed !== number) {
                attempt.sheet?.close();

                return;
            }

            const url = route(attempt.route, number);

            if (attempt.sheet) {
                // و`replace`: الصفحةُ الفارغة لا تدخل تاريخَ اللسان
                attempt.sheet.location.replace(url);

                return;
            }

            toast.warning(t('منع المتصفح فتح نافذة الطباعة — اطبع فاتورة طلب الموقع يدويًّا.'), {
                duration: 15000,
                action: {
                    label: t('طباعة الفاتورة'),
                    onClick: () => window.open(url, '_blank', 'noopener'),
                },
            });
        },
        onFinish: () => {
            if (!attempt || attempt.settled) return;
            attempt.settled = true;
            attempt.sheet?.close();
        },
    };
}
