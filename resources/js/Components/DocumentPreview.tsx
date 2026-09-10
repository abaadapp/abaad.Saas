import { Download, ExternalLink, Printer } from 'lucide-react';
import { useRef, useState } from 'react';

import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';

/**
 * معاينةُ ورقةٍ — عند الطلب، لا دائمًا.
 *
 * ═══ ما كان يقع ═══
 *
 * كانت شاشةُ الطلب تحمل إطارَ PDF مفتوحًا **على الدوام** بنصف عرضها،
 * وفوق الإطار شريطُ المتصفّح: تنزيلٌ وطباعةٌ وتكبيرٌ وقائمة. فيرى التاجر
 * واجهتين — واجهةَ أبعاد وواجهةَ قارئ المتصفّح — ويقرأ البياناتِ مرّتين:
 * في الجدول وفي الورقة إلى جانبه.
 *
 * وعلى الهاتف أسوأ: الإطارُ يهبط تحت التفاصيل بعرض الشاشة كاملًا، فيصير
 * بين المستخدم وأزراره ورقةٌ لا تُقرأ على تلك السعة أصلًا. وكلُّ فتحةٍ
 * للصفحة تعني **رسمَ ملفِّ PDF على الخادم** لمن جاء يقرأ حالةَ طلب.
 *
 * ═══ والصواب أن تُطلب ═══
 *
 * زرٌّ يفتحها في نافذةٍ تكاد تملأ الشاشة، وتُغلق فتعود التفاصيلُ سيّدةَ
 * الصفحة. والإطارُ لا يُركَّب إلّا وهي مفتوحة — فلا رسمَ ولا طلبَ شبكةٍ
 * لمن لم يطلب.
 *
 * ═══ والأفعالُ الثلاثة تختلف حقًّا ═══
 *
 *  • **معاينة** تُري الورقة ولا تُنزلها.
 *  • **تحميل** يحفظ الملفّ.
 *  • **طباعة** تُرسله إلى الطابعة مباشرةً بلا حفظ.
 *
 * وكان في الشاشة زرّان — «تصدير PDF» و«تحميل» — على **الرابط نفسِه
 * تمامًا**، أحدُهما يفتح لسانًا والآخر يحفظ. فيقف من يريد الطباعة بينهما
 * لا يعرف أيَّهما يوصله.
 */
export default function DocumentPreview({
    url,
    title,
    filename,
    open,
    onOpenChange,
}: {
    url: string;
    title: string;
    filename: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const t = useTranslate();
    const frame = useRef<HTMLIFrameElement>(null);
    const [ready, setReady] = useState(false);

    /*
     * والطباعةُ من الإطار نفسِه إن أمكن.
     *
     * الملفُّ من أصلِ الصفحة نفسِه، فنافذتُه مقروءة. وبعضُ المتصفّحات
     * تمنعها في إطارٍ يحمل PDF — فيُفتح لسانٌ يطبع منه المستخدم بيده بدل
     * ضغطةٍ لا يقع بعدها شيء ولا يُقال لماذا.
     */
    const print = () => {
        try {
            const win = frame.current?.contentWindow;

            if (win) {
                win.focus();
                win.print();

                return;
            }
        } catch {
            /* منعَه المتصفّح — يُفتح لسانٌ بدلًا منه */
        }

        window.open(url, '_blank', 'noopener');
    };

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
                        <Button variant="outline" size="sm" onClick={print} disabled={!ready}>
                            <Printer />
                            <span className="max-sm:sr-only">{t('طباعة')}</span>
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
                                <span className="max-sm:sr-only">{t('فتح في لسان')}</span>
                            </a>
                        </Button>
                    </div>
                </div>

                {/*
                    ولا يُركَّب الإطارُ إلّا والنافذةُ مفتوحة.
                    كلُّ تركيبٍ طلبٌ إلى الخادم يرسم ملفًّا كاملًا.
                */}
                {open && (
                    <iframe
                        ref={frame}
                        title={title}
                        src={url}
                        onLoad={() => setReady(true)}
                        className="min-h-0 w-full flex-1 bg-[#f7f7f5]"
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
