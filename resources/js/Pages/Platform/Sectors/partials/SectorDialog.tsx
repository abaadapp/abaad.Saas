import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import Field from '@/Components/Field';
import SectorIcon from '@/Components/SectorIcon';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface SectorCard {
    id: number;
    code: string;
    name: string;
    name_en: string | null;
    icon: string;
    sort: number;
    active: boolean;
    /** اسمُ النظام الذي يعمل عليه القطاع — نظامُ أبعاد اليوم */
    system: string;
    plans_count: number;
    businesses_count: number;
}

/**
 * نافذةُ القطاع — للإضافة والتعديل.
 *
 * والرمزُ يُكتب في الإضافة وحدها ويُعرض بعدها ثابتًا: الاسمُ يتغيّر والرمزُ هو
 * ما يربط القطاع بما يُبنى عليه (`SectorController::update` يردّ تغييره).
 */
export default function SectorDialog({ sector, icons, onClose }: { sector: SectorCard | null; icons: string[]; onClose: () => void }) {
    const t = useTranslate();
    const form = useForm({
        code: sector?.code ?? '',
        name: sector?.name ?? '',
        name_en: sector?.name_en ?? '',
        icon: sector?.icon ?? icons[0] ?? 'store',
        sort: String(sector?.sort ?? 0),
        active: sector?.active ?? true,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const done = { onSuccess: () => onClose(), preserveScroll: true };
        if (sector) {
            form.transform(({ code: _code, ...data }) => data);
            form.put(route('super-admin.sectors.update', sector.id), done);
        } else {
            form.post(route('super-admin.sectors.store'), done);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t(sector ? 'تعديل القطاع' : 'إضافة قطاع')}</DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4 px-5 pb-5" data-testid="sector-form">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Field label="اسم القطاع" required error={form.errors.name}>
                            <Input
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder={t('مثال: التجارة والتجزئة')}
                                required
                            />
                        </Field>
                        <Field label="الاسم بالإنجليزية" error={form.errors.name_en}>
                            <Input
                                dir="ltr"
                                value={form.data.name_en}
                                onChange={(e) => form.setData('name_en', e.target.value)}
                                placeholder="Retail & Commerce"
                            />
                        </Field>
                    </div>

                    <Field
                        label="رمز القطاع"
                        required={!sector}
                        hint={sector ? 'ثابتٌ لا يتغيّر بتغيّر الاسم' : 'حروفٌ لاتينيّة صغيرة — يُكتب مرّةً ولا يتغيّر'}
                        error={form.errors.code}
                    >
                        <Input
                            dir="ltr"
                            value={form.data.code}
                            onChange={(e) => form.setData('code', e.target.value.toLowerCase())}
                            placeholder="retail"
                            readOnly={!!sector}
                            disabled={!!sector}
                            required={!sector}
                            data-testid="sector-code"
                        />
                    </Field>

                    <fieldset>
                        <legend className="mb-2 text-sm font-medium text-[#374151]">{t('الأيقونة')}</legend>
                        <div className="grid grid-cols-6 gap-2 sm:grid-cols-9" role="radiogroup" aria-label={t('الأيقونة')}>
                            {icons.map((icon) => (
                                <button
                                    key={icon}
                                    type="button"
                                    role="radio"
                                    aria-checked={form.data.icon === icon}
                                    aria-label={icon}
                                    onClick={() => form.setData('icon', icon)}
                                    className={cn(
                                        'flex size-10 items-center justify-center rounded-[10px] border transition-colors',
                                        form.data.icon === icon
                                            ? 'border-[#111] bg-[#111] text-white'
                                            : 'border-[var(--ui-border,#e8e8e8)] text-[#4b4b4b] hover:bg-[#f7f7f5]',
                                    )}
                                >
                                    <SectorIcon icon={icon} className="size-5" />
                                </button>
                            ))}
                        </div>
                        {form.errors.icon && <p className="mt-1.5 text-[12px] text-[#b91c1c]">{form.errors.icon}</p>}
                    </fieldset>

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Field label="ترتيب العرض" hint="الأصغر يُعرض أوّلًا" error={form.errors.sort}>
                            <Input
                                type="number"
                                min="0"
                                max="999"
                                dir="ltr"
                                value={form.data.sort}
                                onChange={(e) => form.setData('sort', e.target.value)}
                            />
                        </Field>
                        <div>
                            <label className="flex h-full cursor-pointer items-center justify-between gap-3 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] px-4 py-3">
                                <span>
                                    <span className="block text-sm font-medium text-[#374151]">{t('نشط')}</span>
                                    <span className="block text-[12px] text-[#9ca3af]">{t('غير النشط لا تُضاف إليه شركاتٌ جديدة')}</span>
                                </span>
                                <input
                                    type="checkbox"
                                    checked={form.data.active}
                                    onChange={(e) => form.setData('active', e.target.checked)}
                                    className="size-5 rounded accent-[#111]"
                                    data-testid="sector-active"
                                />
                            </label>
                            {(form.errors as Record<string, string | undefined>).active && (
                                <p className="mt-1.5 text-[12px] text-[#b91c1c]">{(form.errors as Record<string, string | undefined>).active}</p>
                            )}
                        </div>
                    </div>

                    <div className="flex justify-end gap-2 pt-1">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            {t(sector ? 'حفظ التغييرات' : 'إضافة القطاع')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
