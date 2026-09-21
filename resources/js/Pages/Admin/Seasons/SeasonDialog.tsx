import { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import Field from '@/Components/Field';
import Toggle from '@/Components/Toggle';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';

export interface SeasonFields {
    id?: number;
    name: string;
    name_en: string | null;
    starts_at: string;
    ends_at: string;
    active: boolean;
    show_in_pos: boolean;
    show_on_website: boolean;
}

/**
 * نموذجُ الموسم — إنشاءً وتعديلًا.
 *
 * حقولٌ سبعة لا أكثر: اسمٌ ومدّةٌ وثلاثةُ مفاتيح. ولا سعرَ ولا خصمَ ولا
 * مخزونَ هنا — الموسمُ مجموعةٌ لا صنف.
 */
export default function SeasonDialog({
    open,
    onClose,
    season,
}: {
    open: boolean;
    onClose: () => void;
    season?: SeasonFields | null;
}) {
    const t = useTranslate();
    const editing = !!season?.id;

    const form = useForm({
        name: season?.name ?? '',
        name_en: season?.name_en ?? '',
        starts_at: season?.starts_at ?? '',
        ends_at: season?.ends_at ?? '',
        active: season?.active ?? true,
        show_in_pos: season?.show_in_pos ?? true,
        show_on_website: season?.show_on_website ?? true,
    });

    useEffect(() => {
        if (open) {
            form.setData({
                name: season?.name ?? '',
                name_en: season?.name_en ?? '',
                starts_at: season?.starts_at ?? '',
                ends_at: season?.ends_at ?? '',
                active: season?.active ?? true,
                show_in_pos: season?.show_in_pos ?? true,
                show_on_website: season?.show_on_website ?? true,
            });
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, season?.id]);

    const submit = () => {
        const options = { preserveScroll: true, onSuccess: () => onClose() };
        if (editing) {
            form.put(route('admin.seasons.update', season!.id), options);
        } else {
            form.post(route('admin.seasons.store'), options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{editing ? t('تعديل الموسم') : t('موسم جديد')}</DialogTitle>
                </DialogHeader>

                <form
                    className="space-y-4 px-5 pb-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit();
                    }}
                >
                    <Field label="اسم الموسم" required error={form.errors.name}>
                        <Input
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            placeholder={t('مثال: رمضان 2027')}
                            aria-label={t('اسم الموسم')}
                        />
                    </Field>
                    <Field label="الاسم بالإنجليزية" error={form.errors.name_en}>
                        <Input
                            value={form.data.name_en ?? ''}
                            onChange={(e) => form.setData('name_en', e.target.value)}
                            placeholder="e.g. Ramadan 2027"
                            dir="ltr"
                            aria-label={t('الاسم بالإنجليزية')}
                        />
                    </Field>

                    <div className="grid grid-cols-2 gap-3">
                        <Field label="بداية الموسم" required error={form.errors.starts_at}>
                            <Input
                                type="date"
                                value={form.data.starts_at}
                                onChange={(e) => form.setData('starts_at', e.target.value)}
                                aria-label={t('بداية الموسم')}
                            />
                        </Field>
                        <Field label="نهاية الموسم" required error={form.errors.ends_at}>
                            <Input
                                type="date"
                                min={form.data.starts_at || undefined}
                                value={form.data.ends_at}
                                onChange={(e) => form.setData('ends_at', e.target.value)}
                                aria-label={t('نهاية الموسم')}
                            />
                        </Field>
                    </div>

                    <div className="divide-y divide-[var(--ui-border,#e8e8e8)] rounded-[12px] border border-[var(--ui-border,#e8e8e8)] px-3">
                        <Toggle on={form.data.active} onChange={(v) => form.setData('active', v)} label="فعال" hint="الموسمُ المطفأ لا يظهر في الصندوق ولا الموقع ولا يُذكَّر به" />
                        <Toggle on={form.data.show_in_pos} onChange={(v) => form.setData('show_in_pos', v)} label="إظهار في نقطة البيع" hint="شريط مواسم يرشّح أصناف الموسم أثناء مدّته" />
                        <Toggle on={form.data.show_on_website} onChange={(v) => form.setData('show_on_website', v)} label="إظهار في الموقع الإلكتروني" hint="قسم باسم الموسم في موقعك أثناء مدّته — لما هو منشور أصلًا" />
                    </div>

                    <div className="flex justify-end gap-2 pt-1">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            {editing ? t('حفظ التغييرات') : t('إنشاء الموسم')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
