import { toast } from 'sonner';
import { businessIdOf, expectBusiness } from '@/lib/exportLink';
import {
    abandonAutoPrint,
    type AutoPrintAttempt,
    commitAutoPrint,
    failureMessage,
    PRINTER,
    prepareAutoPrint,
    printReceipt,
} from '@/lib/printing/printer-service';
import type { PrinterLike } from '@/lib/printing/types';

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
 * ═══ وفي تطبيق أبعاد ═══
 *
 * لا نافذة: إن كانت طابعةُ الصندوق على الشبكة طبع التطبيقُ مباشرةً بعد أن
 * يقول الخادم «أُكّد الآن» — بالشرط نفسِه. وإن لم تكن على الشبكة فحوارُ
 * الطباعة في النظام. انظر `lib/printing/printer-service`.
 *
 * ═══ وما لا تفعله ═══
 *
 * لا تطبع على طابعةٍ غير طابعة الصندوق المربوط بهذا الجهاز. هاتفٌ لا صندوقَ
 * عليه لا يُطبع منه شيء.
 */

export const WEBSITE = 'website';
/** «جديد» و«مؤكّد» كما في `OrderStatus::PENDING` و`CONFIRMED` — بالشدّة */
export const PENDING = 'جديد';
export const CONFIRMED = 'مؤكّد';

export interface WebsiteConfirmInput {
    /** ملحقاتُ الصندوق المربوط بهذا المتصفّح النشطة — `context.peripherals` */
    peripherals?: PrinterLike[] | null;
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
    /** `null` حين منعها المتصفّح — أو حين لا نافذة أصلًا (تطبيق أبعاد) */
    sheet: Window | null;
    route: string;
    settled: boolean;
    /** المحاولةُ نفسُها في طبقة الطباعة — طريقُها وطابعتُها */
    auto: AutoPrintAttempt;
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

    // الطابعةُ التي ضُبط عليها الخيار — لا أوّلُ طابعةٍ في الصندوق
    const printer = input.peripherals?.find((p) => p.type === PRINTER && p.autoPrintWebsite) ?? null;
    // وفي المتصفّح تُفتح النافذةُ الفارغة هنا — وبلا `noopener`، انظر `prepareAutoPrint`
    const auto = prepareAutoPrint(printer, true);

    return { sheet: auto.sheet, route: input.receipt, settled: false, auto };
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
                // والفارغةُ تُغلق — `abandonAutoPrint` تُغلقها مرّةً واحدة
                abandonAutoPrint(attempt.auto);

                return;
            }

            // والورقةُ تحمل نشاطَ الصفحة التي ردّ بها الخادمُ للتوّ — انظر `TenantExportGuard`
            const url = expectBusiness(route(attempt.route, number), businessIdOf(page.props));

            /*
             * وفي تطبيق أبعاد: طباعةٌ مباشرة (أو حوارُ النظام) بعد «أُكّد الآن».
             *
             * والطلبُ أُكّد أيًّا كانت الطباعة — فالفشلُ يُقال ومعه «إعادة
             * الطباعة»، ولا يُعاد تلقائيًّا.
             */
            if (attempt.auto.mode === 'native' || attempt.auto.mode === 'system') {
                const printer = attempt.auto.printer;
                const retry = () =>
                    void printReceipt({ invoice: number, url, printer }).then((again) => {
                        if (!again.ok) toast.error(failureMessage(again.code, t));
                    });

                void commitAutoPrint(attempt.auto, number, () => url).then((outcome) => {
                    if (!outcome || outcome.ok) return;
                    toast.error(`${t('أُكّد الطلب، لكن تعذرت طباعة إيصاله.')} ${failureMessage(outcome.code, t)}`, {
                        duration: 15000,
                        action: { label: t('إعادة الطباعة'), onClick: retry },
                    });
                });

                return;
            }

            attempt.auto.settled = true;

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
            abandonAutoPrint(attempt.auto);
        },
    };
}
