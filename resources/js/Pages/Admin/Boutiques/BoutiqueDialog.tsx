import { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import {
    Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle,
} from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';

export interface BoutiqueFields {
    name: string;
    name_en: string | null;
    phone: string | null;
    contact_person: string | null;
    /** باسم الخادم لا باسم العرض: به تصل رسالةُ الخطأ إلى حقلها */
    commission_rate: number;
    active: boolean;
    notes?: string | null;
}

interface Props {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    /** المعرّف حين يكون تعديلًا — وغيابُه يعني إنشاءً */
    id?: number | null;
    initial?: BoutiqueFields | null;
}

const EMPTY: BoutiqueFields = {
    name: '', name_en: '', phone: '', contact_person: '', commission_rate: 0, active: true, notes: '',
};

/**
 * نافذةُ البوتيك — اسمُه ونسبةُ المحلّ منه.
 *
 * ونافذةٌ واحدة للإنشاء والتعديل: شاشتان تفترقان عند أوّل حقلٍ يُضاف في
 * إحداهما، فيُنشأ بحقلٍ لا يُعدَّل به.
 */
export default function BoutiqueDialog({ open, onOpenChange, id = null, initial = null }: Props) {
    const t = useTranslate();
    const form = useForm<BoutiqueFields>(initial ?? EMPTY);

    // والنافذةُ تُملأ بما فُتحت عليه — لا بما فُتحت عليه أوّلَ مرّة
    useEffect(() => {
        if (open) form.setDefaults(), form.setData(initial ?? EMPTY), form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, id]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        const done = { onSuccess: () => onOpenChange(false), preserveScroll: true };

        if (id) form.put(route('admin.boutiques.update', id), done);
        else form.post(route('admin.boutiques.store'), done);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t(id ? 'تعديل البوتيك' : 'بوتيك جديد')}</DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4 px-5 pb-5">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="اسم البوتيك" required error={form.errors.name}>
                            <Input
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                autoFocus
                            />
                        </Field>
                        <Field label="الاسم بالإنجليزية" hint="يُعرض لمن لغتُه إنجليزية" error={form.errors.name_en}>
                            <Input
                                value={form.data.name_en ?? ''}
                                onChange={(e) => form.setData('name_en', e.target.value)}
                                dir="ltr"
                            />
                        </Field>
                        <Field label="رقم التواصل" error={form.errors.phone}>
                            <Input
                                value={form.data.phone ?? ''}
                                onChange={(e) => form.setData('phone', e.target.value)}
                                dir="ltr"
                                inputMode="tel"
                            />
                        </Field>
                        <Field label="المسؤول" error={form.errors.contact_person}>
                            <Input
                                value={form.data.contact_person ?? ''}
                                onChange={(e) => form.setData('contact_person', e.target.value)}
                            />
                        </Field>
                    </div>

                    {/*
                        النسبةُ أهمُّ حقلٍ في النافذة — وتُشرح لا تُترك رقمًا.
                        وتغييرُها يسري على ما يأتي: بنودُ ما بِيع تحمل نسبتَها
                        ساعتَها، فكشفُ الشهر الماضي لا يتبدّل تحت يد أحد.
                    */}
                    <Field
                        label="نسبة المتجر"
                        required
                        hint="تُطبَّق على ما يُباع بعد حفظها — وكشوف الشهور الماضية تبقى بنسبتها"
                        error={form.errors.commission_rate}
                    >
                        <div className="flex items-center gap-2">
                            <Input
                                type="number"
                                min={0}
                                max={100}
                                step="0.01"
                                value={form.data.commission_rate}
                                onChange={(e) => form.setData('commission_rate', Number(e.target.value))}
                                className="w-32"
                                dir="ltr"
                            />
                            <span className="text-sm text-[#6b7280]">%</span>
                        </div>
                    </Field>

                    <Field label="ملاحظات" error={form.errors.notes}>
                        <textarea
                            value={form.data.notes ?? ''}
                            onChange={(e) => form.setData('notes', e.target.value)}
                            rows={2}
                            className="w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] px-3 py-2 text-sm"
                        />
                    </Field>

                    <label className="flex cursor-pointer items-center gap-2.5">
                        <input
                            type="checkbox"
                            checked={form.data.active}
                            onChange={(e) => form.setData('active', e.target.checked)}
                            className="size-4 rounded border-[#d1d5db] accent-[#111]"
                        />
                        <span className="text-sm text-[#374151]">{t('نشط')}</span>
                    </label>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {t('حفظ')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
