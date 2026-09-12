import { Download, ExternalLink, Printer } from 'lucide-react';

import PaperFrame from '@/Components/PaperFrame';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';

/**
 * معاينةُ ورقةٍ مكبَّرة — الورقةُ نفسُها، لا قارئُ المتصفّح.
 *
 * ═══ ما كان يقع أوّلًا ═══
 *
 * كانت شاشةُ الطلب تحمل إطارَ PDF مفتوحًا **على الدوام** بنصف عرضها، وفوق
 * الإطار شريطُ المتصفّح: تنزيلٌ وطباعةٌ وتكبيرٌ وقائمة. فيرى التاجر واجهتين
 * — واجهةَ أبعاد وواجهةَ قارئ المتصفّح — ويقرأ البياناتِ مرّتين. وكلُّ فتحةٍ
 * للصفحة تعني **رسمَ ملفِّ PDF على الخادم** لمن جاء يقرأ حالةَ طلب.
 *
 * فصارت تُطلب: زرٌّ يفتحها في نافذةٍ تكاد تملأ الشاشة.
 *
 * ═══ وما كان يقع بعد ذلك ═══
 *
 * والنافذةُ كانت تضع **رابطَ الـPDF** في إطار. فيرسم الخادمُ ملفًّا عند كلّ
 * ضغطةِ تكبير، ويفتحه المتصفّح بقارئه هو: شريطُ أدواتٍ رماديّ، وأزرارُ
 * تكبيرٍ وتصفّحٍ وطباعةٍ وقائمة — فوق أزرارِ أبعاد نفسِها. وهما صفّان من
 * الأزرار يفعلان الشيءَ نفسَه، وواجهةٌ ليست واجهتَنا فوق ورقةِ تاجرنا.
 *
 * ═══ والصواب أن تُرسم الورقةُ نفسُها ═══
 *
 * `PaperFrame` يرسم الورقةَ HTML بمقاسها الحقيقيّ على أرضٍ محايدة، بظلٍّ
 * خفيفٍ يقول إنّها ورقة، وبخطٍّ متقطّعٍ عند كلّ حدّ صفحة. ولا شريطَ فوقها
 * ولا طلبَ إلى الخادم: النصُّ المرسوم في يد الشاشة أصلًا.
 *
 * ═══ والأفعالُ الثلاثة تختلف حقًّا ═══
 *
 *  • **معاينة** تُري الورقة ولا تُنزلها — وهي هذه النافذة.
 *  • **تحميل** يحفظ الملفّ.
 *  • **طباعة** تفتح الـPDF ليُرسَل إلى الطابعة — والـPDF هو ما يُطبع، لا
 *    رسمُ المتصفّح: هوامشُه وخطوطُه وحدودُ صفحاته من المحرّك نفسِه.
 *
 * وكان في الشاشة زرّان — «تصدير PDF» و«تحميل» — على **الرابط نفسِه تمامًا**،
 * أحدُهما يفتح لسانًا والآخر يحفظ. فيقف من يريد الطباعة بينهما لا يعرف
 * أيَّهما يوصله.
 */
export default function DocumentPreview({
    html,
    size,
    url,
    title,
    filename,
    open,
    onOpenChange,
}: {
    /** الورقةُ مرسومةً — النصُّ نفسُه الذي في اللوحة */
    html: string;
    /** اسمُ المقاس — A4 · A5 · 80mm · 58mm */
    size?: string;
    /** بابُ الـPDF — للتحميل والطباعة وحدهما */
    url: string;
    title: string;
    filename: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const t = useTranslate();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="flex h-[calc(100dvh-2rem)] w-[calc(100vw-1.5rem)] max-w-4xl flex-col p-0 sm:h-[calc(100dvh-4rem)]"
                aria-describedby={undefined}
            >
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--ui-border,#e8e8e8)] p-3 pe-12">
                    <DialogTitle className="text-[15px]">{title}</DialogTitle>

                    {/* والأفعالُ داخل النافذة لا في شريط المتصفّح — فالواجهةُ واجهةُ أبعاد */}
                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <a href={url} target="_blank" rel="noreferrer">
                                <Printer />
                                <span className="max-sm:sr-only">{t('طباعة')}</span>
                            </a>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <a href={url} download={filename}>
                                <Download />
                                <span className="max-sm:sr-only">{t('تحميل')}</span>
                            </a>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <a href={url} target="_blank" rel="noreferrer">
                                <ExternalLink />
                                <span className="max-sm:sr-only">{t('فتح الملف')}</span>
                            </a>
                        </Button>
                    </div>
                </div>

                {/*
                    ولا تُرسم الورقةُ إلّا والنافذةُ مفتوحة.

                    `PaperFrame` يقيس ارتفاعَ ما رُسم ليعرف كم صفحةً هي، وقياسُ
                    إطارٍ مخفيٍّ يردّ صفرًا — فتُرسم بصفحةٍ واحدة أبدًا ويُقصّ
                    ما زاد بلا أن يقول ذلك شيء.
                */}
                {open && (
                    <div className="min-h-0 flex-1 overflow-hidden">
                        <PaperFrame
                            html={html}
                            paper={size}
                            title={title}
                            viewport="100%"
                            className="h-full rounded-none border-0"
                        />
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
