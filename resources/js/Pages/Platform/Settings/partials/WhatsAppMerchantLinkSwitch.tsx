import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Check, X } from 'lucide-react';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';

export type MerchantLinkMode = 'own_app' | 'abaad_app';

/** ‏‏كيف يربط التجّارُ الجدد واتسابهم الآن — انظر `App\Support\WhatsAppMerchantLink::state` */
export interface WhatsAppLinkState {
    /** ‏ما يسري الآن */
    mode: MerchantLinkMode;
    /** ‏ما في إعداد الخادم — يسري ما لم يُختر هنا */
    default: MerchantLinkMode;
    /** ‏ما اختير من هذه الشاشة، أو `null` */
    platform: MerchantLinkMode | null;
    readable: boolean;
    /** ‏رقمُ إعداد الربط المدمج الساري — ليس سرًّا: تحمله صفحةُ التاجر إلى نافذة Meta */
    config_id: string;
    config_source: 'platform' | 'server' | 'none';
    /** ‏وجودُ معرّف تطبيق أبعاد وسرّه على الخادم — نعم أو لا، ولا يُعرض السرّ */
    app_id_ready: boolean;
    app_secret_ready: boolean;
    /** ‏أجاهزٌ الربطُ بضغطة؟ — الثلاثةُ معًا */
    ready: boolean;
    /** ‏أمفتوحٌ للتجّار خيارُ البقاء في تطبيق واتساب للأعمال؟ */
    coexistence?: boolean;
    /** ‏حقولُ إشعارات Meta التي يحتاجها ذلك الخيار */
    coexistence_fields?: string[];
    history: { at: string; by: string; what: string }[];
}

export const MODE_LABEL: Record<MerchantLinkMode, string> = {
    own_app: 'تطبيق التاجر على Meta',
    abaad_app: 'تطبيق أبعاد (بضغطة)',
};

/**
 * ‏‏طريقُ ربط واتساب للتجّار الجدد، ورقمُ إعداد الربط المدمج — لمدير المنصّة وحده
 * (`super-admin.whatsapp.merchant-link.*`).
 *
 * يسري من الطلب التالي بلا نشرٍ ولا `.env`. ولا يمسّ وصلةً قائمة: نوعُها مكتوبٌ فيها،
 * ووصلةُ تطبيق التاجر تبقى تُرسل وتسمع على عنوانها. ولا يُفتح طريقُ تطبيق أبعاد قبل أن
 * تكتمل جاهزيّتُه — الخادمُ يردّه، والزرُّ هنا مطفأٌ معه.
 */
export default function WhatsAppMerchantLinkSwitch({ state, locale }: { state: WhatsAppLinkState; locale?: string }) {
    const t = useTranslate();
    const [confirm, dialog] = useConfirm();
    const [busy, setBusy] = useState(false);
    const [saving, setSaving] = useState(false);
    const [configId, setConfigId] = useState(state.config_source === 'platform' ? state.config_id : '');
    const [errors, setErrors] = useState<{ mode?: string; config_id?: string; enabled?: string }>({});
    const [coexBusy, setCoexBusy] = useState(false);
    const when = (iso: string) =>
        new Intl.DateTimeFormat(locale === 'en' ? 'en-GB' : 'ar-OM', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Muscat' }).format(new Date(iso));

    const toAbaad = state.mode === 'own_app';
    const next: MerchantLinkMode = toAbaad ? 'abaad_app' : 'own_app';

    const flip = async () => {
        const ok = await confirm({
            title: toAbaad ? t('نقل التجّار الجدد إلى تطبيق أبعاد') : t('إعادة التجّار الجدد إلى تطبيق التاجر'),
            message: toAbaad
                ? t('يربط كلُّ تاجرٍ جديد واتسابه بضغطة عبر نافذة Meta، ويختار رقم API أو تطبيق واتساب للأعمال. رقمه وحسابه ووسيلة دفعه له، وMeta تفوتره مباشرة. الوصلات القائمة لا تتغيّر.')
                : t('يعود التاجر الجديد إلى ربط تطبيقه الخاص على Meta بالخطوات اليدوية. الوصلات القائمة لا تتغيّر.'),
            action: toAbaad ? t('نقل') : t('إعادة'),
        });
        if (!ok) return;
        router.post(route('super-admin.whatsapp.merchant-link.mode'), { mode: next }, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => setErrors({}),
            onError: (e) => setErrors(e as typeof errors),
        });
    };

    const coexOpen = state.coexistence === true;
    const flipCoexistence = async () => {
        const ok = await confirm({
            title: coexOpen ? t('إقفال خيار البقاء في تطبيق واتساب للأعمال') : t('فتح خيار البقاء في تطبيق واتساب للأعمال'),
            message: coexOpen
                ? t('لا يظهر الخيار للتجّار الجدد. الأرقام المربوطة به تبقى كما هي.')
                : t('يستطيع التاجر أن يُبقي رقمه في تطبيق واتساب للأعمال على هاتفه ويصل أبعاد معًا، وتُنقل جهاته ومحادثاته السابقة. تأكّد أنّ تطبيق أبعاد مشترك في حقول الإشعارات المذكورة.'),
            action: coexOpen ? t('إقفال') : t('فتح'),
        });
        if (!ok) return;
        router.post(route('super-admin.whatsapp.merchant-link.coexistence'), { enabled: !coexOpen }, {
            preserveScroll: true,
            onStart: () => setCoexBusy(true),
            onFinish: () => setCoexBusy(false),
            onSuccess: () => setErrors({}),
            onError: (e) => setErrors(e as typeof errors),
        });
    };

    const save = () =>
        router.post(route('super-admin.whatsapp.merchant-link.config'), { config_id: configId.trim() }, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: () => setErrors({}),
            onError: (e) => setErrors(e as typeof errors),
        });

    const checks: { key: string; ok: boolean; label: string }[] = [
        { key: 'app', ok: state.app_id_ready, label: t('معرّف تطبيق أبعاد على الخادم') },
        { key: 'secret', ok: state.app_secret_ready, label: t('سرّ تطبيق أبعاد على الخادم') },
        { key: 'config', ok: state.config_id !== '', label: t('رقم إعداد الربط المدمج') },
    ];

    return (
        <Card className="mb-6 p-6" data-testid="wa-link-switch">
            <div className="mb-1 flex flex-wrap items-center gap-2">
                <h3 className="text-[18px] font-bold text-[#111]">{t('طريقة ربط واتساب للتجّار')}</h3>
                <Badge variant={state.mode === 'abaad_app' ? 'success' : 'neutral'} data-testid="wa-link-mode">
                    {t(MODE_LABEL[state.mode])}
                </Badge>
            </div>
            <p className="mb-5 text-[13px] leading-relaxed text-[#6b7280]">
                {t('عبر تطبيق أبعاد يربط التاجر رقمه بضغطة، ويختار رقم API أو تطبيق واتساب للأعمال؛ ورسوم Meta على حسابه مباشرة. وعبر تطبيقه الخاص يربطه بخطوات يدوية. يسري على التجّار الجدد من الطلب التالي، والوصلات القائمة لا تتغيّر.')}
            </p>

            {!state.readable && (
                <p data-testid="wa-link-unreadable" className="mb-4 rounded-[10px] bg-[#fef2f2] px-3 py-2 text-[12px] text-[#b91c1c]">
                    {t('تعذّرت قراءة الاختيار من القاعدة، فيسري ما في إعداد الخادم حتى يُقرأ.')}
                </p>
            )}

            {/* ‏‏جاهزيّةُ الربط بضغطة — نعم أو لا لكلّ بند، والسرُّ لا يُعرض */}
            <ul className="mb-4 space-y-1.5" data-testid="wa-link-ready">
                {checks.map((c) => (
                    <li key={c.key} data-testid={`wa-link-ready-${c.key}`} data-ok={c.ok ? '1' : '0'} className="flex items-center gap-2 text-[13px] text-[#374151]">
                        {c.ok ? <Check className="size-4 text-[#047857]" /> : <X className="size-4 text-[#b91c1c]" />}
                        {c.label}
                        {c.key === 'config' && state.config_source === 'server' && (
                            <span className="text-[12px] text-[#9ca3af]">· {t('من إعداد الخادم')} <span dir="ltr">{state.config_id}</span></span>
                        )}
                    </li>
                ))}
            </ul>

            <form
                className="mb-5 flex flex-wrap items-end gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    save();
                }}
            >
                <label className="flex flex-col gap-1 text-[12px] text-[#6b7280]">
                    {t('رقم إعداد الربط المدمج (Configuration ID)')}
                    <Input
                        dir="ltr"
                        inputMode="numeric"
                        value={configId}
                        onChange={(e) => setConfigId(e.target.value.replace(/\D/g, ''))}
                        placeholder={state.config_source === 'server' ? state.config_id : ''}
                        className="w-64 tabular-nums"
                        data-testid="wa-link-config-input"
                    />
                </label>
                <Button type="submit" variant="outline" loading={saving} data-testid="wa-link-config-save">
                    {t('حفظ')}
                </Button>
                {errors.config_id && (
                    <p className="basis-full text-[12px] text-[#b91c1c]" data-testid="wa-link-config-error">{errors.config_id}</p>
                )}
            </form>

            <div className="flex flex-wrap items-center justify-between gap-3 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] px-4 py-3">
                <span className="text-sm text-[#111]">
                    {t('التجّار الجدد يربطون عبر')}: <strong>{t(MODE_LABEL[state.mode])}</strong>
                    {!state.platform && <span className="ms-2 text-[12px] text-[#9ca3af]">· {t('من إعداد الخادم')}</span>}
                </span>
                <Button
                    data-testid="wa-link-flip"
                    variant={toAbaad ? 'primary' : 'outline'}
                    loading={busy}
                    disabled={toAbaad && !state.ready}
                    onClick={() => void flip()}
                >
                    {toAbaad ? t('انقلهم إلى تطبيق أبعاد') : t('أعِدهم إلى تطبيق التاجر')}
                </Button>
            </div>
            {toAbaad && !state.ready && (
                <p className="mt-2 text-[12px] text-[#6b7280]" data-testid="wa-link-not-ready">
                    {t('يُفتح النقل حين تكتمل البنود الثلاثة أعلاه.')}
                </p>
            )}
            {errors.mode && (
                <p className="mt-2 text-[12px] text-[#b91c1c]" data-testid="wa-link-error">{errors.mode}</p>
            )}

            {/* ‏‏وخيارُ البقاء في تطبيق واتساب للأعمال — يُفتح بعد النقل إلى تطبيق أبعاد */}
            <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] px-4 py-3" data-testid="wa-coex">
                <span className="text-sm text-[#111]">
                    {t('خيار «أبقِ رقمك في تطبيق واتساب للأعمال»')}:{' '}
                    <strong data-testid="wa-coex-state">{coexOpen ? t('مفتوح للتجّار') : t('مقفل')}</strong>
                </span>
                <Button
                    data-testid="wa-coex-flip"
                    variant={coexOpen ? 'outline' : 'primary'}
                    loading={coexBusy}
                    disabled={!coexOpen && (state.mode !== 'abaad_app' || !state.ready)}
                    onClick={() => void flipCoexistence()}
                >
                    {coexOpen ? t('أقفله') : t('افتحه')}
                </Button>
            </div>
            <p className="mt-2 text-[12px] leading-relaxed text-[#6b7280]" data-testid="wa-coex-fields">
                {t('يحتاج أن يكون تطبيق أبعاد عند Meta مشتركًا في حقول الإشعارات')}:{' '}
                <span dir="ltr" className="font-medium text-[#374151]">{(state.coexistence_fields ?? []).join(' · ')}</span>{' '}
                {t('(لوحة Meta ← WhatsApp ← Configuration).')}
                {!coexOpen && state.mode !== 'abaad_app' && <> {t('ويُفتح بعد نقل التجّار الجدد إلى تطبيق أبعاد.')}</>}
            </p>
            {errors.enabled && (
                <p className="mt-2 text-[12px] text-[#b91c1c]" data-testid="wa-coex-error">{errors.enabled}</p>
            )}

            {state.history.length > 0 && (
                <div className="mt-5">
                    <p className="mb-2 text-[12px] font-semibold text-[#6b7280]">{t('آخر التغييرات')}</p>
                    <ul className="space-y-1.5" data-testid="wa-link-history">
                        {state.history.map((h, i) => (
                            <li key={i} className="text-[12px] text-[#4b4b4b]">
                                {when(h.at)} — {h.by}: {h.what}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {dialog}
        </Card>
    );
}
