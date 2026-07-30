import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';

interface Props {
    url: string;
    message?: string;
    label?: string;
    /** إلى أين يعود بعد الحذف — الخادم يقرّر عادةً، وهذا للحالات التي تبقى في مكانها */
    preserveScroll?: boolean;
}

/** زر حذف بنافذة تأكيد — بديل form+confirm() في Blade */
export default function DeleteButton({
    url,
    message = 'سيُحذف هذا السجل نهائيًا. هل تريد المتابعة؟',
    label = 'حذف',
    preserveScroll = false,
}: Props) {
    const t = useTranslate();
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);

    return (
        <>
            <Button variant="danger" onClick={() => setOpen(true)}>
                <Trash2 />
                {t(label)}
            </Button>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{t('تأكيد الحذف')}</DialogTitle>
                    </DialogHeader>
                    <div className="px-5 pb-5">
                        <p className="text-sm text-[#4b4b4b]">{t(message)}</p>
                        <div className="mt-5 flex justify-end gap-2">
                            <Button variant="outline" onClick={() => setOpen(false)} disabled={busy}>
                                {t('إلغاء')}
                            </Button>
                            <Button
                                variant="danger"
                                disabled={busy}
                                onClick={() => {
                                    setBusy(true);
                                    router.delete(url, {
                                        preserveScroll,
                                        onFinish: () => {
                                            setBusy(false);
                                            setOpen(false);
                                        },
                                    });
                                }}
                            >
                                {busy ? '…' : t('حذف')}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
