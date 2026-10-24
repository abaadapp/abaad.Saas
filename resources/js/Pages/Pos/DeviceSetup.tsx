import { type FormEvent, useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import { MonitorSmartphone, Replace, SquarePlus, TriangleAlert } from 'lucide-react';
import Logo from '@/Components/Logo';
import { useConfirm } from '@/Components/ConfirmDialog';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/** صندوقٌ قائمٌ في المتجر — قد يحلّ هذا الجهازُ محلَّه بملحقاته */
export interface ExistingTill {
    id: number;
    name: string;
    branchId: number;
    branch: string;
    active: boolean;
    printers: number;
    lastSeen: string | null;
    /** بصمةُ حاله كما عُرضت — تُقارن عند الضغط (`TillActivation::version`) */
    version: string;
}

interface Props {
    branches: { value: number; label: string }[];
    businessName: string;
    devices?: ExistingTill[];
    /** الصندوقُ الذي كانه هذا المتصفّح — يُعلَّم، ولا يُختار عنه */
    previous?: number | null;
    /** أيستبدل صندوقًا قائمًا؟ — للإعدادات وحدها */
    canReplace?: boolean;
}

type Mode = '' | 'new' | 'replace';

/**
 * إعداد نقطة البيع — يقع مرّةً واحدة يوم التركيب، ومرّةً كلّما تبدّل الجهاز.
 *
 * ═══ والاختيارُ صريح ═══
 *
 * الجهازُ يُعرف بمتصفّحه. فكان كلُّ متصفّحٍ جديد — تطبيقُ أبعاد بعد Chrome،
 * أو المتصفّحُ نفسُه بعد مسح بياناته — يولد له صندوقٌ جديدٌ بلا طابعة، والطابعةُ
 * على القديم. فحيث في المتجر صناديقُ قائمة يُسأل: «صندوقٌ جديد» أم «يحلّ محلّ
 * صندوقٍ قائم» — ولا يُختار عن أحد.
 *
 * والاستبدالُ لا ينقل: الفرعُ فرعُ الصندوق، يُعرض ولا يُبدَّل. والنقلُ من
 * الإعدادات («تعديل»). وصندوقٌ يعمل الآن على جهازٍ آخر لا يُوقَف بلا تأكيدٍ
 * يقول أيَّ جهازٍ وأيَّ فرعٍ وما يقع له.
 */
export default function DeviceSetup() {
    const { branches, businessName, devices = [], previous = null, canReplace = false, errors } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [ask, confirmDialog] = useConfirm();
    // فرعٌ واحد لا اختيار فيه — يُملأ سلفًا
    const onlyBranch = branches.length === 1 ? String(branches[0].value) : '';
    const asks = devices.length > 0;
    const [unchosen, setUnchosen] = useState(false);
    const form = useForm({
        mode: (asks ? '' : 'new') as Mode,
        branch_id: onlyBranch,
        name: '',
        replaces: '',
        version: '',
        confirm_active: false as boolean,
    });
    const replaced = devices.find((d) => String(d.id) === form.data.replaces) ?? null;
    const replacing = form.data.mode === 'replace';

    const pickMode = (mode: Mode) => {
        setUnchosen(false);
        form.setData({ ...form.data, mode, replaces: '', version: '', name: '', branch_id: onlyBranch });
    };

    /*
        صندوقٌ قديمٌ اختير: اسمُه يُملأ منه ويبقى قابلًا للتعديل، وفرعُه فرعُه —
        يُعرض مقفلًا: الاستبدالُ لا ينقل.
    */
    const pickTill = (value: string) => {
        const till = devices.find((d) => String(d.id) === value);
        form.setData({
            ...form.data,
            replaces: value,
            version: till?.version ?? '',
            name: till ? till.name : '',
            branch_id: till ? String(till.branchId) : onlyBranch,
        });
    };

    const submit = async (e: FormEvent) => {
        e.preventDefault();

        if (asks && form.data.mode === '') {
            setUnchosen(true);
            return;
        }

        let confirmActive = false;

        if (replacing && replaced?.active) {
            confirmActive = await ask({
                title: 'إيقاف صندوق يعمل الآن',
                message:
                    '«:name» في فرع «:branch» مفعَّل الآن على جهاز آخر (آخر اتصال: :seen). سيتوقّف هناك فورًا ويعمل على هذا الجهاز بطابعته وإعداداتها، وتبقى مبيعاته ووردياته منسوبة إليه. متابعة؟',
                values: { name: replaced.name, branch: replaced.branch, seen: replaced.lastSeen ?? '—' },
                action: 'أوقفه هناك وشغّله هنا',
                danger: true,
            });
            if (!confirmActive) return;
        }

        form.transform((data) => ({ ...data, mode: data.mode || 'new', confirm_active: confirmActive }));
        form.post(route('pos.setup.activate'));
    };

    const branchOptions = replacing && replaced ? [{ value: replaced.branchId, label: replaced.branch }] : branches;

    return (
        <div className="flex min-h-dvh flex-col items-center justify-center bg-[#f7f8f9] px-4 py-10">
            <Head title={t('إعداد نقطة البيع')} />

            <div className="w-full max-w-[460px]">
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
                        {asks && (
                            <div className="space-y-2" role="radiogroup" aria-label={t('ما هذا الجهاز؟')}>
                                <p className="text-sm font-medium text-[#111]">{t('ما هذا الجهاز؟')}</p>
                                <div className="grid grid-cols-2 gap-2">
                                    <ModeCard
                                        on={form.data.mode === 'new'}
                                        onPick={() => pickMode('new')}
                                        icon={<SquarePlus className="size-4" />}
                                        title={t('صندوق جديد')}
                                        hint={t('يبدأ بلا طابعة ولا إعدادات')}
                                    />
                                    <ModeCard
                                        on={replacing}
                                        disabled={!canReplace}
                                        onPick={() => pickMode('replace')}
                                        icon={<Replace className="size-4" />}
                                        title={t('يحلّ محلّ صندوق قائم')}
                                        hint={canReplace ? t('تبقى طابعته وإعداداته ومبيعاته') : t('يحتاج صلاحية الإعدادات — اطلبه من المدير')}
                                    />
                                </div>
                                {(unchosen || errors.mode) && (
                                    <p className="text-[12px] text-[#b91c1c]">{errors.mode ?? t('اختر: صندوق جديد، أو يحلّ محلّ صندوق قائم.')}</p>
                                )}
                                {form.data.mode === 'new' && devices.some((d) => d.printers > 0) && (
                                    <p className="text-[12px] text-[#b45309]">
                                        {t('الصندوق الجديد لا يأخذ طابعة أيّ صندوق قائم. إن كان هذا الجهاز بديلًا لصندوق قديم فاختر «يحلّ محلّ صندوق قائم».')}
                                    </p>
                                )}
                            </div>
                        )}

                        {replacing && (
                            <Field
                                label="الصندوق الذي يحلّ هذا الجهاز محلّه"
                                required
                                hint="يبقى سجلّه ومبيعاته ووردياته وطابعته، ويتوقّف الجهاز السابق"
                                error={errors.replaces ?? errors.version ?? errors.confirm_active}
                            >
                                <Select
                                    value={form.data.replaces}
                                    onChange={(e) => pickTill(e.target.value)}
                                    placeholder="اختر الصندوق…"
                                    options={devices.map((d) => ({
                                        value: String(d.id),
                                        label: [
                                            d.name,
                                            d.branch,
                                            `${t('طابعات')}: ${d.printers}`,
                                            d.active ? t('يعمل على جهاز آخر') : t('ملغى'),
                                            d.id === previous ? t('كان هذا المتصفّح') : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' — '),
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

                        <Field
                            label="الفرع"
                            required
                            hint={replacing ? 'فرع الصندوق نفسه — لنقله إلى فرع آخر عدّله من الإعدادات' : undefined}
                            error={errors.branch_id}
                        >
                            <Select
                                value={form.data.branch_id}
                                onChange={(e) => form.setData('branch_id', e.target.value)}
                                options={branchOptions}
                                placeholder="اختر الفرع…"
                                disabled={replacing}
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
                            disabled={form.processing || branches.length === 0 || (replacing && !replaced)}
                        >
                            {form.processing ? t('جارٍ التفعيل…') : t('تفعيل الجهاز')}
                        </Button>
                    </form>
                </Card>
            </div>

            {confirmDialog}
        </div>
    );
}

/** خيارٌ من اثنين — بطاقةٌ تُضغط، لا قائمةٌ يُختار منها فراغ */
function ModeCard({
    on,
    disabled = false,
    onPick,
    icon,
    title,
    hint,
}: {
    on: boolean;
    disabled?: boolean;
    onPick: () => void;
    icon: React.ReactNode;
    title: string;
    hint: string;
}) {
    return (
        <button
            type="button"
            role="radio"
            aria-checked={on}
            disabled={disabled}
            onClick={onPick}
            className={cn(
                'flex flex-col items-start gap-1 rounded-[10px] border p-3 text-start transition-colors',
                on ? 'border-[#111] bg-[#f7f7f5]' : 'border-[var(--ui-border,#e8e8e8)] hover:bg-[#fafafa]',
                disabled && 'cursor-not-allowed opacity-60 hover:bg-transparent',
            )}
        >
            <span className="flex items-center gap-1.5 text-sm font-medium text-[#111]">
                {icon}
                {title}
            </span>
            <span className="text-[12px] text-[#6b7280]">{hint}</span>
        </button>
    );
}
