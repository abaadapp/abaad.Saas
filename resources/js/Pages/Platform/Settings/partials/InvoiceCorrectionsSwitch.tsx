import { useState } from 'react';
import { router } from '@inertiajs/react';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';

/** ‏‏حالُ المفتاح — انظر `App\Support\InvoiceCorrectionsSwitch::state` */
export interface InvoiceCorrectionsState {
    on: boolean;
    readable: boolean;
    changed_at: string | null;
    history: { at: string; by: string; from: boolean; to: boolean }[];
}

/**
 * ‏‏مفتاحُ إيقاف تعديل فواتير العملاء وتصحيحها — لمدير المنصّة وحده (`super-admin.invoice-corrections.update`).
 *
 * هو خطّةُ الرجوع بعد أوّل مستند تصحيح: يُوقف إصدار الجديد، ويُبقي قراءةَ ما صدر كلِّه.
 */
export default function InvoiceCorrectionsSwitch({ state, locale }: { state: InvoiceCorrectionsState; locale?: string }) {
    const t = useTranslate();
    const [confirm, dialog] = useConfirm();
    const [busy, setBusy] = useState(false);
    const when = (iso: string) =>
        new Intl.DateTimeFormat(locale === 'en' ? 'en-GB' : 'ar-OM', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Muscat' }).format(new Date(iso));
    const word = (on: boolean) => (on ? t('مشغّل') : t('موقوف'));

    const flip = async () => {
        const next = !state.on;
        const ok = await confirm({
            title: next ? t('تشغيل تعديل فواتير العملاء') : t('إيقاف تعديل فواتير العملاء'),
            message: next
                ? t('يعود تعديل المسودات وتصحيح الفواتير الصادرة بالإشعارات لكل التجار، كلٌّ بصلاحياته. يسري من الطلب التالي.')
                : t('لا تُعدَّل مسودة ولا تُصحَّح فاتورة ولا تُستبدل، ولا يُنفَّذ اعتماد معلّق، عند أي تاجر. وما صدر من فواتير وإشعارات يبقى كما هو ويُقرأ في الذمم والتقارير والإقرار. يسري من الطلب التالي.'),
            action: next ? t('تشغيل') : t('إيقاف'),
            danger: !next,
        });
        if (!ok) return;
        router.post(route('super-admin.invoice-corrections.update'), { enabled: next }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    };

    return (
        <Card className="p-6" data-testid="invoice-corrections-switch">
            <div className="mb-1 flex flex-wrap items-center gap-2">
                <h3 className="text-[18px] font-bold text-[#111]">{t('تعديل فواتير العملاء وتصحيحها')}</h3>
                <Badge variant={state.on ? 'success' : 'warning'} data-testid="invoice-corrections-state">
                    {state.on ? t('يعمل الآن') : t('موقوف الآن')}
                </Badge>
            </div>
            <p className="mb-5 text-[13px] leading-relaxed text-[#6b7280]">
                {t('مفتاح الطوارئ لتعديل المسودات وتصحيح الفواتير الصادرة بإشعار دائن أو مدين. الإيقاف يمنع إصدار الجديد فقط، وما صدر يبقى ويُقرأ. وهو طريق الرجوع بدل إرجاع الكود.')}
            </p>

            {!state.readable && (
                <p data-testid="invoice-corrections-unreadable" className="mb-4 rounded-[10px] bg-[#fef2f2] px-3 py-2 text-[12px] text-[#b91c1c]">
                    {t('تعذّرت قراءة المفتاح من القاعدة، فالتعديل موقوف حتى يُقرأ.')}
                </p>
            )}

            <div className="flex flex-wrap items-center justify-between gap-3 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] px-4 py-3">
                <span className="text-sm text-[#111]">
                    {t('الحالة')}: <strong data-testid="invoice-corrections-word">{word(state.on)}</strong>
                    {state.changed_at && <span className="ms-2 text-[12px] text-[#9ca3af]">· {t('آخر تغيير')} {when(state.changed_at)}</span>}
                </span>
                <Button data-testid="invoice-corrections-flip" variant={state.on ? 'outline' : 'primary'} loading={busy} onClick={() => void flip()}>
                    {state.on ? t('إيقاف') : t('تشغيل')}
                </Button>
            </div>

            {state.history.length > 0 && (
                <div className="mt-5">
                    <p className="mb-2 text-[12px] font-semibold text-[#6b7280]">{t('آخر التغييرات')}</p>
                    <ul className="space-y-1.5" data-testid="invoice-corrections-history">
                        {state.history.map((h, i) => (
                            <li key={i} className="text-[12px] text-[#4b4b4b]">
                                {when(h.at)} — {h.by}: {t('كان')} {word(h.from)}، {t('صار')} {word(h.to)}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {dialog}
        </Card>
    );
}
