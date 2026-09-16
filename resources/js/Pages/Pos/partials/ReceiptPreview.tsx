import { useEffect, useRef, useState } from 'react';
import { Eye, Loader2 } from 'lucide-react';
import { toast } from 'sonner';

import DocumentPreview from '@/Components/DocumentPreview';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';

/**
 * «معاينة الفاتورة» — الورقةُ فوق شاشة البيع، لا بدلًا منها.
 *
 * ═══ العطبُ الذي يعالجه ═══
 *
 * لم يكن في نقطة البيع فعلٌ إلّا «طباعة الفاتورة»، وهو يفتح ملفَّ PDF في
 * لسانٍ آخر. وعلى سطح المكتب لسانٌ إلى جانب لسان؛ وعلى الآيباد والهاتف
 * يملأ قارئُ PDF الشاشةَ ولا شريطَ ألسنةٍ يُرى — فتختفي شاشةُ البيع، ويقف
 * الكاشير أمام ورقةٍ لا يعرف كيف يرجع منها والزبون واقف.
 *
 * وهو يريد أن **يرى** ما سيُسلَّم قبل أن يمزّق الشريط: أصحيحٌ الصنف؟ أظهر
 * الخصم؟ أطُبع اسمُ العميل؟ وهذا سؤالُ معاينةٍ لا سؤالُ طباعة.
 *
 * ═══ ولمَ `DocumentPreview` لا قارئٌ جديد ═══
 *
 * النافذةُ نفسُها تستعملها ستُّ شاشاتٍ في اللوحة: فاتورةُ العميل، وأمرُ
 * الشراء، وسندُ الاستلام، وإشعارُ الدائن، وشاشةُ الطلب. وفيها الأفعالُ
 * الثلاثة مفروزةً أصلًا — معاينةٌ تُري ولا تُنزل، وطباعةٌ، وتحميل — ورسمُ
 * الورقة HTML بلا شريط قارئ المتصفّح فوقها. وقارئٌ سابعٌ يُكتب هنا يعني
 * أنّ إصلاحًا في أحدهما لا يبلغ الآخر.
 *
 * ═══ والنصُّ يُطلب عند الفتح لا مع كلّ بيعة ═══
 *
 * لو حُمل مع ردّ الدفع لَحُمل في كلّ بيعةٍ وإن لم يُنظر إليه — ونقطةُ البيع
 * تبيع مئةً في اليوم ويُعاين منها ما يُشكّ فيه. فيُطلب عند الضغط، ويُحفظ
 * بعدها: فتحةٌ ثانيةٌ للإيصال نفسِه لا تسأل الخادمَ من جديد.
 *
 * ولا `Blob` ولا `URL.createObjectURL` في هذا الطريق: النصُّ نصٌّ يُرسَم،
 * فلا رابطَ كائنٍ يُنسى فلا يُحرَّر.
 */
interface Paper {
    html: string;
    size: string;
}

export default function ReceiptPreviewButton({
    number,
    label,
    className,
    variant = 'outline',
}: {
    /** رقمُ الفاتورة — ولا يُعرض الزرّ بدونه */
    number: string;
    label?: string;
    className?: string;
    variant?: 'primary' | 'outline' | 'subtle' | 'ghost';
}) {
    const t = useTranslate();
    const [paper, setPaper] = useState<Paper | null>(null);
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState(false);

    /*
     * ما حُمِّل يُحفظ برقمه.
     *
     * والرقمُ معه لأنّ الزرّ نفسَه قد يخدم فاتورةً أخرى بعد إغلاق النافذة
     * (صفُّ جدولٍ يتبدّل)، فورقةٌ محفوظةٌ بلا رقمها تُعرض لفاتورةٍ ليست لها
     * — والكاشير يقرأ إيصالَ الزبون السابق ويظنّه إيصالَ الواقف أمامه.
     */
    const cached = useRef<{ number: string; paper: Paper } | null>(null);

    /*
     * والطلبُ المعلَّق يُلغى عند الخروج.
     *
     * الكاشير يضغط «معاينة» ثمّ «طلب جديد» قبل أن يصل الردّ — فلو تُرك
     * لَفتح النافذةَ فوق سلّةٍ جديدة بعد أن غادرها.
     */
    const flight = useRef<AbortController | null>(null);

    useEffect(() => () => flight.current?.abort(), []);

    const show = async () => {
        if (loading) return;

        if (cached.current?.number === number) {
            setPaper(cached.current.paper);
            setOpen(true);

            return;
        }

        flight.current?.abort();
        const controller = new AbortController();
        flight.current = controller;
        setLoading(true);

        try {
            const res = await fetch(route('pos.receipt.paper', number), {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });

            if (!res.ok) {
                throw new Error(String(res.status));
            }

            const data = (await res.json()) as Paper;

            if (controller.signal.aborted) return;

            cached.current = { number, paper: data };
            setPaper(data);
            setOpen(true);
        } catch (e) {
            if (controller.signal.aborted || (e instanceof DOMException && e.name === 'AbortError')) {
                return;
            }

            /*
             * والفشلُ يُقال في مكانه ولا يُغادَر به.
             *
             * لا سببَ تقنيّ ولا رمزُ حالة: الكاشير لا يُصلح خادمًا وزبونُه
             * واقف. والشاشةُ تبقى كما هي — والطباعةُ بابٌ آخر ما زال مفتوحًا.
             */
            toast.error(t('تعذّر عرض الفاتورة الآن — يمكنك طباعتها.'));
        } finally {
            if (!controller.signal.aborted) setLoading(false);
        }
    };

    return (
        <>
            <Button type="button" variant={variant} className={className} disabled={loading} onClick={show}>
                {loading ? <Loader2 className="animate-spin" /> : <Eye />}
                {label ?? t('معاينة الفاتورة')}
            </Button>

            {paper && (
                <DocumentPreview
                    html={paper.html}
                    size={paper.size}
                    /* والطباعةُ والتحميلُ على بابِ الـPDF نفسِه الذي كان — لم يتبدّل */
                    url={route('pos.receipt.pdf', number)}
                    title={`${t('الفاتورة')} ${number}`}
                    filename={`${number}.pdf`}
                    open={open}
                    onOpenChange={setOpen}
                />
            )}
        </>
    );
}
