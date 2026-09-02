import { type ReactNode, useCallback, useRef, useState } from 'react';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';

export interface ConfirmOptions {
    /** السؤال — جملةٌ تقول ما سيقع، لا «هل أنت متأكد؟» */
    message: string;
    title?: string;
    /** نصّ زرّ المتابعة — «حذف» و«اعتماد» و«ترحيل» ليست كلمةً واحدة */
    action?: string;
    /** فعلٌ لا رجعة فيه يُصبغ بالأحمر */
    danger?: boolean;
}

/**
 * نافذةُ تأكيدٍ من النظام — بديلُ `confirm()` المتصفّح.
 *
 * و`confirm()` نافذةٌ يرسمها نظام التشغيل: تخرج داكنةً في أعلى الشاشة بخطٍّ
 * غريب، وتتجاهل اتجاه الواجهة فيصير «موافق/إلغاء» بترتيبٍ معكوس، ولا تعرف
 * الشيء الذي تسأل عنه فتقول «حذف؟» بلا اسمه. وهي أوّلُ ما يشكّ فيه من يراها
 * — نافذةٌ لا تشبه النظام تبدو تحذيرًا من المتصفّح لا سؤالًا من البرنامج.
 *
 * وأثقلُ من الشكل: تُجمّد الصفحة كلَّها حتى يُجاب، ولا تُترجَم أزرارُها إلى
 * لغة الواجهة، ولا يمكن أن يُقال فيها «هذا لا يُستردّ» بلونٍ يُرى.
 *
 * والاستعمال:
 *
 *     const [ask, dialog] = useConfirm();
 *     …
 *     onClick={async () => {
 *         if (! await ask({ message: 'حذف الأصل؟', danger: true })) return;
 *         router.delete(url);
 *     }}
 *     …
 *     {dialog}
 */
export function useConfirm(): [(options: ConfirmOptions) => Promise<boolean>, ReactNode] {
    const t = useTranslate();
    const [options, setOptions] = useState<ConfirmOptions | null>(null);
    /*
     * الجوابُ يُحفظ في مرجعٍ لا في حالة.
     *
     * الوعدُ يُحلّ مرّةً واحدة، ولو حُفظ في حالةٍ لَأعادت كلُّ إعادة رسمٍ
     * دالّةً جديدة فيبقى الوعدُ الأوّل معلّقًا — ونافذةٌ تُغلق ولا يقع بعدها
     * شيء أسوأ من نافذةٍ لا تُفتح.
     */
    const resolver = useRef<((value: boolean) => void) | null>(null);

    const ask = useCallback((next: ConfirmOptions) => {
        setOptions(next);

        return new Promise<boolean>((resolve) => {
            resolver.current = resolve;
        });
    }, []);

    const settle = (answer: boolean) => {
        resolver.current?.(answer);
        resolver.current = null;
        setOptions(null);
    };

    const dialog = (
        <Dialog
            open={options !== null}
            /* الإغلاق بالمفتاح أو بالنقر خارجها جوابٌ بـ«لا» — لا صمتٌ يُعلّق الوعد */
            onOpenChange={(open) => ! open && settle(false)}
        >
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    <DialogTitle>{t(options?.title ?? 'تأكيد')}</DialogTitle>
                </DialogHeader>
                <div className="px-5 pb-5">
                    <p className="text-sm text-[#4b4b4b]">{options ? t(options.message) : ''}</p>
                    <div className="mt-5 flex justify-end gap-2">
                        <Button variant="outline" onClick={() => settle(false)}>
                            {t('إلغاء')}
                        </Button>
                        <Button
                            variant={options?.danger ? 'danger' : 'primary'}
                            onClick={() => settle(true)}
                        >
                            {t(options?.action ?? 'متابعة')}
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );

    return [ask, dialog];
}
