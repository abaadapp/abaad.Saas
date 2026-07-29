import { type FormEvent, useRef } from 'react';
import { UserPlus } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { useTranslate } from '@/lib/i18n';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** يُرجع true عند النجاح فتُغلق النافذة */
    onSubmit: (form: HTMLFormElement) => Promise<boolean>;
}

export default function NewCustomerDialog({ open, onOpenChange, onSubmit }: Props) {
    const t = useTranslate();
    const formRef = useRef<HTMLFormElement>(null);

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        if (!formRef.current) return;
        if (await onSubmit(formRef.current)) onOpenChange(false);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('إضافة عميل جديد')}</DialogTitle>
                </DialogHeader>

                <form
                    ref={formRef}
                    action={route('pos.customers.store')}
                    method="POST"
                    onSubmit={submit}
                    className="space-y-3.5 px-5"
                >
                    <div>
                        <Label htmlFor="c-name" required className="mb-1.5">{t('الاسم الكامل')}</Label>
                        <Input id="c-name" name="name" placeholder={t('اسم العميل')} required />
                    </div>
                    <div>
                        <Label htmlFor="c-phone" className="mb-1.5">{t('رقم الهاتف')}</Label>
                        <Input id="c-phone" name="phone" type="tel" placeholder="+968 9xxxxxxx" dir="ltr" />
                    </div>
                    <div>
                        <Label htmlFor="c-email" className="mb-1.5">{t('البريد الإلكتروني')}</Label>
                        <Input id="c-email" name="email" type="email" placeholder="example@mail.com" dir="ltr" />
                    </div>
                    <div>
                        <Label htmlFor="c-tax" className="mb-1.5">{t('الرقم الضريبي (TRN)')}</Label>
                        <Input id="c-tax" name="tax_number" placeholder="OM1100XXXXXX" dir="ltr" />
                        <p className="mt-1 text-[11px] text-gray-400">{t('اختياري — لفواتير الشركات (B2B)')}</p>
                    </div>
                </form>

                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>{t('إلغاء')}</Button>
                    <Button onClick={submit}>
                        <UserPlus />
                        {t('حفظ العميل')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
