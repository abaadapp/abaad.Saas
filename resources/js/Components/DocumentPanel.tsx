import { Download, Maximize2, Printer } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import DocumentPreview from '@/Components/DocumentPreview';
import PaperFrame from '@/Components/PaperFrame';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * الورقةُ إلى جانب تفاصيلها — لوحةً واحدة تتقاسمها شاشاتُ المستندات.
 *
 * ═══ ولمَ مكوّنٌ لا تكرار ═══
 *
 * شاشةُ الطلب كانت وحدها تعرض ورقتَها، وفيها ثلاثةُ أزرارٍ وإطارٌ ونافذةُ
 * تكبير — نحو سبعين سطرًا. وأربعُ شاشاتٍ أخرى تحتاجها: أمرُ الشراء، وسندُ
 * الاستلام، وفاتورةُ العميل، وما يأتي بعدها. ونسخُها أربعًا يعني أنّ إصلاحَ
 * زرٍّ في واحدةٍ يترك ثلاثًا معطوبة — وهو ما وقع فعلًا حين وُصلت **معاينةُ**
 * فاتورة البيع بالقالب الجديد ونُسي **زرُّ الطباعة**.
 *
 * ═══ والأفعالُ الثلاثة تختلف حقًّا ═══
 *
 *  • **تكبير** يُري الورقة في نافذةٍ تملأ الشاشة ولا يُنزل شيئًا.
 *  • **تحميل** يحفظ الملفّ.
 *  • **طباعة** يفتح الورقة لتُرسَل إلى الطابعة.
 *
 * ═══ والورقةُ في العمود HTML لا PDF ═══
 *
 * إطارُ PDF يحمل فوقه شريطَ قارئ المتصفّح — تنزيلٌ وطباعةٌ وتكبيرٌ وقائمة —
 * فيرى التاجر واجهتين. ويُشغَّل محرّكُ طباعةٍ كامل على الخادم لكلّ من فتح
 * الصفحة ليقرأ حالةَ مستند. و`PaperFrame` يرسمها بعرض الورقة الحقيقيّ ثمّ
 * يُصغّرها بصريًّا: السطرُ ينكسر حيث ينكسر على الورق، وحدودُ الصفحات تُرسم.
 * والـPDF لا يُطلب إلّا عند التكبير أو التحميل أو الطباعة.
 */
export default function DocumentPanel({
    html,
    size,
    url,
    filename,
    label,
    heading,
    note,
    className,
    frameClassName,
    open,
    onOpenChange,
}: {
    /** الورقةُ مرسومةً — من الباني نفسِه الذي يُطبع منه */
    html: string;
    /** اسمُ المقاس — A4 · A5 · 80mm · 58mm */
    size?: string;
    /** بابُ الـPDF — للتكبير والتحميل والطباعة */
    url: string;
    /** اسمُ الملفّ عند التحميل */
    filename: string;
    /** اسمُ المستند — عنوانُ نافذة التكبير ووصفُ الإطار لقارئ الشاشة */
    label: string;
    /** عنوانُ اللوحة — و«الورقة كما تُطبع» افتراضًا */
    heading?: string;
    /** سطرٌ تحت الأزرار يقول ما لا تقوله الورقة */
    note?: ReactNode;
    className?: string;
    frameClassName?: string;
    /**
     * نافذةُ التكبير محكومةً من خارج اللوحة — اختياريّة.
     *
     * الشاشةُ الضيّقة تُنزل الورقةَ أسفل كلّ البطاقات، فيوصل إليها مختصرٌ
     * في الترويسة. وحالةٌ ثانيةٌ في الصفحة تعني نافذتين تُفتحان بزرّين —
     * فتُعار حالةُ اللوحة لمن يحتاجها بدل أن تُستنسخ.
     */
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}) {
    const t = useTranslate();
    const [inner, setInner] = useState(false);

    const previewing = open ?? inner;
    const setPreviewing = onOpenChange ?? setInner;

    return (
        <>
            <Card className={cn('overflow-hidden', className)}>
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3">
                    <h2 className="text-[15px] font-semibold text-[#111]">{t(heading ?? 'الورقة كما تُطبع')}</h2>

                    <div className="flex items-center gap-1.5">
                        <Button variant="outline" size="sm" onClick={() => setPreviewing(true)}>
                            <Maximize2 />
                            <span className="max-sm:sr-only">{t('تكبير')}</span>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <a href={url} download={filename}>
                                <Download />
                                <span className="max-sm:sr-only">{t('تحميل')}</span>
                            </a>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <a href={url} target="_blank" rel="noreferrer">
                                <Printer />
                                <span className="max-sm:sr-only">{t('طباعة')}</span>
                            </a>
                        </Button>
                    </div>
                </div>

                {note && (
                    <p className="border-b border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] px-4 py-2 text-[12px] leading-relaxed text-[#6b7280]">
                        {note}
                    </p>
                )}

                {/*
                    والنافذةُ تهبط إلى قدر الورقة، ولا تُحجَز بعلوٍّ مكتوب.

                    علوٌّ ثابتٌ يُخرج رمادًا فارغًا أسفلَ كلّ ورقةٍ أقصرَ منه —
                    وإيصالُ ثمانين مليمترًا بثلاثة أصنافٍ أقصرُ منه بكثير.
                    و`auto` يجعل الصندوقَ بقدر ما فيه، والحدُّ الأقصى يمنعه أن
                    يطول بفاتورةٍ من ثلاث صفحات — فتُمرَّر داخله بدل أن يمتدّ
                    العمود.
                */}
                <PaperFrame
                    html={html}
                    paper={size}
                    title={label}
                    viewport="auto"
                    className={cn('max-h-[min(78svh,1000px)] rounded-none border-0', frameClassName)}
                />
            </Card>

            {/*
                والتكبيرُ يُري الورقةَ نفسَها لا ملفَّها.

                كانت النافذةُ تضع رابطَ الـPDF في إطار: يرسم الخادمُ ملفًّا عند
                كلّ ضغطة، ويفتحه المتصفّح بقارئه هو — شريطُ أدواتٍ رماديّ فوق
                أزرارِ أبعاد نفسِها. والنصُّ المرسوم في يد الشاشة أصلًا.
            */}
            <DocumentPreview
                html={html}
                size={size}
                url={url}
                title={label}
                filename={filename}
                open={previewing}
                onOpenChange={setPreviewing}
            />
        </>
    );
}

/**
 * عمودُ الورقة اللاصق — غلافٌ يضع اللوحة إلى جانب التفاصيل.
 *
 * ولاصقٌ عند الأعلى: عمودُ التفاصيل أطولُ منها بكثير، وورقةٌ تغيب عند أوّل
 * تمريرة لا تُقابَل بشيء. وعلى الشاشات الضيّقة تهبط تحت التفاصيل بلا لصق —
 * ورقةُ A4 لا تُقرأ على تلك السعة أصلًا، فلا تُزاحم ما يُقرأ.
 */
export function DocumentAside({ className, children }: { className?: string; children: ReactNode }) {
    return (
        <aside className={cn('min-w-0', className)}>
            <div className="xl:sticky xl:top-6">{children}</div>
        </aside>
    );
}
