import { type FormEvent } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import { MonitorSmartphone, TriangleAlert } from 'lucide-react';
import Logo from '@/Components/Logo';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

/** صندوقٌ قائمٌ في المتجر — قد يحلّ هذا الجهازُ محلَّه بملحقاته */
interface ExistingTill {
    id: number;
    name: string;
    branchId: number;
    branch: string;
    active: boolean;
    printers: number;
}

interface Props {
    branches: { value: number; label: string }[];
    businessName: string;
    devices?: ExistingTill[];
}

/**
 * إعداد نقطة البيع — يقع مرّةً واحدة يوم التركيب.
 *
 * بعده لا يرى الكاشير هذه الشاشة أبدًا: يفتح نقطة البيع فيجد لوحة الأرقام،
 * والجهاز هو من يعرف الفرع.
 */
export default function DeviceSetup() {
    const { branches, businessName, devices = [], errors } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    // فرعٌ واحد لا اختيار فيه — يُملأ سلفًا
    const onlyBranch = branches.length === 1 ? String(branches[0].value) : '';
    const form = useForm({
        branch_id: onlyBranch,
        name: '',
        replaces: '',
    });
    const replaced = devices.find((d) => String(d.id) === form.data.replaces) ?? null;

    /*
        صندوقٌ قديمٌ اختير: اسمُه وفرعُه يُملآن منه — ويبقيان قابلَين للتعديل.
        و«جهازٌ جديد» يُفرغ ما مُلئ، فلا يُفعَّل صندوقٌ جديد باسم القديم صدفة.
    */
    const choose = (value: string) => {
        const till = devices.find((d) => String(d.id) === value);
        form.setData({
            replaces: value,
            name: till ? till.name : '',
            branch_id: till ? String(till.branchId) : onlyBranch,
        });
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('pos.setup.activate'));
    };

    return (
        <div className="flex min-h-dvh flex-col items-center justify-center bg-[#f7f8f9] px-4 py-10">
            <Head title={t('إعداد نقطة البيع')} />

            <div className="w-full max-w-[420px]">
                <div className="mb-8 flex flex-col items-center gap-3">
                    <Logo className="h-14 w-auto text-[#111]" />
                    <p className="text-[15px] font-semibold text-[#111]">{businessName}</p>
                    <p className="text-[13px] text-[#6b7280]">{t('إعداد نقطة البيع')}</p>
                </div>

                <Card className="p-7">
                    <div className="mb-5 flex items-center gap-2">
                        <span className="flex size-9 items-center justify-center rounded-[12px] bg-[#f2f2f0] text-[#4b4b4b]">
                            <MonitorSmartphone className="size-5" />
                        </span>
                        <h3 className="font-bold text-[#111]">{t('ربط هذا الجهاز بفرع')}</h3>
                    </div>

                    {/*
                        الفرع يُختار مرّة، ولا يُبدَّل من الصندوق بعدها: هو من
                        يقرّر أيّ مخزونٍ يُخصم وأيّ درجٍ يُعدّ، وجعلُه زرًّا على
                        الشاشة يعني أن خطأً في نقرةٍ ينقل مبيعات فرعٍ إلى آخر.
                    */}
                    <p className="mb-5 rounded-[10px] bg-[#f7f7f5] px-3 py-2 text-[13px] text-[#6b7280]">
                        {t('يُربط الجهاز بفرع واحد. تغييره لاحقًا من الإعدادات ويحتاج إعادة تفعيل.')}
                    </p>

                    {branches.length === 0 && (
                        <div className="mb-5 flex items-start gap-2 rounded-[10px] border border-[#fecaca] bg-[#fef2f2] p-3 text-[13px] text-[#b91c1c]">
                            <TriangleAlert className="mt-px size-4 shrink-0" />
                            <span>{t('لا فروع في هذا المتجر — أضف فرعًا أولًا.')}</span>
                        </div>
                    )}

                    <form onSubmit={submit} className="space-y-5">
                        {/*
                            الجهازُ يُعرف بمتصفّحه. فمن انتقل إلى تطبيق أبعاد أو
                            بدّل جهازَه اللوحيّ يختار صندوقَه القديم هنا: يبقى
                            سجلُّه وطابعتُه وإعداداتُها، ويتوقّف الجهازُ القديم.
                            وبلا اختيارٍ يُنشأ صندوقٌ جديدٌ بلا ملحقات.
                        */}
                        {devices.length > 0 && (
                            <Field
                                label="هل يحلّ هذا الجهاز محلّ صندوق قائم؟"
                                hint="اختر الصندوق القديم إن انتقلت إلى تطبيق أبعاد أو بدّلت الجهاز — تبقى طابعته وإعداداتها"
                                error={errors.replaces}
                            >
                                <Select
                                    value={form.data.replaces}
                                    onChange={(e) => choose(e.target.value)}
                                    // «لا» هو الخيارُ الفارغ: Radix لا يقبل قيمةً فارغة على خيار
                                    placeholder="لا — جهاز جديد"
                                    options={devices.map((d) => ({
                                        value: String(d.id),
                                        label: `${d.name} — ${d.branch} — ${t('طابعات')}: ${d.printers}${d.active ? '' : ` (${t('ملغى')})`}`,
                                    }))}
                                />
                            </Field>
                        )}

                        {replaced?.active && (
                            <div className="flex items-start gap-2 rounded-[10px] border border-[#fde68a] bg-[#fffbeb] p-3 text-[13px] text-[#b45309]">
                                <TriangleAlert className="mt-px size-4 shrink-0" />
                                <span>{t('هذا الصندوق مفعَّل على جهاز آخر — سيتوقّف هناك ويعمل هنا بطابعته وإعداداتها.')}</span>
                            </div>
                        )}

                        <Field label="الفرع" required error={errors.branch_id}>
                            <Select
                                value={form.data.branch_id}
                                onChange={(e) => form.setData('branch_id', e.target.value)}
                                options={branches}
                                placeholder="اختر الفرع…"
                            />
                        </Field>

                        <Field label="اسم الجهاز" required hint="مثال: كاشير الخوير 1" error={errors.name}>
                            <Input
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder={t('كاشير 01')}
                                autoFocus
                                required
                            />
                        </Field>

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={form.processing || branches.length === 0}
                        >
                            {form.processing ? t('جارٍ التفعيل…') : t('تفعيل الجهاز')}
                        </Button>
                    </form>
                </Card>
            </div>
        </div>
    );
}
