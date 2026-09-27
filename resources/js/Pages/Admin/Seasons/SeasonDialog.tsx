import { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import Field from '@/Components/Field';
import Toggle from '@/Components/Toggle';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface SeasonFields {
    id?: number;
    name: string;
    name_en: string | null;
    starts_at: string;
    ends_at: string;
    active: boolean;
    show_in_pos: boolean;
    show_on_website: boolean;
    /** يعود كلَّ سنة؟ وبأيّ تقويم — والتاريخان يصيران مرساةً تُحسب منها الدورات */
    repeats?: boolean;
    calendar?: string;
    /** تاريخا المرساة — وهما ما يُعدَّل هنا، لا تاريخا الدورة المعروضة */
    anchor_starts_at?: string;
    anchor_ends_at?: string;
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
    hijriAvailable = true,
}: {
    open: boolean;
    onClose: () => void;
    season?: SeasonFields | null;
    /** هل يحسب الخادمُ الهجريَّ؟ — بلا `intl` يُقفل الخيارُ ويُقال السبب */
    hijriAvailable?: boolean;
}) {
    const t = useTranslate();
    const editing = !!season?.id;

    const form = useForm({
        name: season?.name ?? '',
        name_en: season?.name_en ?? '',
        /*
         * والمعدَّلُ هو المرساةُ لا الدورةُ المعروضة.
         *
         * شاشةُ الموسم تعرض تاريخَي الدورة الجارية — وهو ما يسأل عنه صاحبُه.
         * أمّا هنا فيُفتح على التاريخين الأصليّين: تعديلُ الدورة يقع من
         * «تصحيح هذه الدورة»، وتعديلُ المرساة يُزيح الدورات كلَّها.
         */
        starts_at: season?.anchor_starts_at ?? season?.starts_at ?? '',
        ends_at: season?.anchor_ends_at ?? season?.ends_at ?? '',
        active: season?.active ?? true,
        show_in_pos: season?.show_in_pos ?? true,
        show_on_website: season?.show_on_website ?? true,
        repeats: season?.repeats ?? false,
        calendar: season?.calendar ?? 'gregorian',
    });

    useEffect(() => {
        if (open) {
            form.setData({
                name: season?.name ?? '',
                name_en: season?.name_en ?? '',
                starts_at: season?.anchor_starts_at ?? season?.starts_at ?? '',
                ends_at: season?.anchor_ends_at ?? season?.ends_at ?? '',
                active: season?.active ?? true,
                show_in_pos: season?.show_in_pos ?? true,
                show_on_website: season?.show_on_website ?? true,
                repeats: season?.repeats ?? false,
                calendar: season?.calendar ?? 'gregorian',
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

                    {/*
                        ═══ يعود كلَّ سنة ═══

                        والتاريخان أعلاه يصيران **مرساةً**: أوّلَ مرّةٍ وقع
                        فيها الموسم. ولا يُعاد تأريخُه كلَّ سنةٍ بيد أحد —
                        الدورةُ تُحسب منهما، فتبقى الأصنافُ والتذكيراتُ
                        والتقاريرُ على صاحبها.
                    */}
                    <div className="divide-y divide-[var(--ui-border,#e8e8e8)] rounded-[12px] border border-[var(--ui-border,#e8e8e8)] px-3">
                        <Toggle
                            on={form.data.repeats}
                            onChange={(v) => form.setData('repeats', v)}
                            label="يتكرر كل سنة"
                            hint="يعود الموسمُ وحدَه كلَّ عام بأصنافه وتذكيراته — والتاريخان أعلاه أوّلُ مرّةٍ وقع فيها"
                        />
                    </div>

                    {form.data.repeats && (
                        <div className="space-y-2 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-3">
                            <p className="text-[13px] font-medium text-[#111]">{t('بأيّ تقويم يعود؟')}</p>

                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                {([
                                    { key: 'gregorian', title: 'ميلادي', hint: 'في اليوم نفسه من كل سنة — كعيد الأم واليوم الوطني' },
                                    { key: 'hijri', title: 'هجري', hint: 'كرمضان والعيدين — يتقدّم نحو ١١ يومًا في السنة الميلادية' },
                                ] as const).map((opt) => {
                                    const locked = opt.key === 'hijri' && hijriAvailable === false;
                                    const picked = form.data.calendar === opt.key;

                                    return (
                                        <button
                                            key={opt.key}
                                            type="button"
                                            disabled={locked}
                                            onClick={() => form.setData('calendar', opt.key)}
                                            title={locked ? t('التقويم الهجري غير متاح على هذا الخادم — اختر الميلادي.') : undefined}
                                            className={cn(
                                                'rounded-[10px] border p-3 text-start transition-colors',
                                                picked ? 'border-[#111] bg-[#f9fafb]' : 'border-[#e5e7eb] hover:bg-[#f9fafb]',
                                                locked && 'cursor-not-allowed opacity-60 hover:bg-transparent',
                                            )}
                                        >
                                            <span className="flex items-center gap-2">
                                                <span className={cn('flex size-4 shrink-0 items-center justify-center rounded-full border', picked ? 'border-[#111]' : 'border-[#d1d5db]')}>
                                                    {picked && <span className="size-2 rounded-full bg-[#111]" />}
                                                </span>
                                                <span className="text-sm font-medium text-[#111]">{t(opt.title)}</span>
                                            </span>
                                            <span className="mt-1 block ps-6 text-[12px] text-[#6b7280]">{t(opt.hint)}</span>
                                        </button>
                                    );
                                })}
                            </div>

                            {form.errors.calendar && (
                                <p className="text-[12px] text-[#b91c1c]">{form.errors.calendar}</p>
                            )}

                            {/*
                                وموعدُ الهجريّ يُحسب من تقويم أمّ القرى، وقد يفترق
                                يومًا عن الإعلان الرسميّ — فيبقى للتاجر أن يصحّح
                                دورةً بعينها من صفحة الموسم.
                            */}
                            {form.data.calendar === 'hijri' && (
                                <p className="rounded-[10px] border border-[#fde68a] bg-[#fffbeb] px-3 py-2 text-[12px] text-[#92400e]">
                                    {t('يُحسب الموعد من تقويم أم القرى. إن اختلف الإعلان الرسمي، صحّح الدورة من صفحة الموسم.')}
                                </p>
                            )}
                        </div>
                    )}

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
