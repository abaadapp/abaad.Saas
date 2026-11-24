import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Megaphone } from 'lucide-react';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';

type CampaignsMode = 'off' | 'pilot' | 'all';

/** ‏‏لمن تُفتح حملاتُ واتساب — `App\Support\WhatsAppCampaignsSwitch::state` */
export interface WhatsappCampaignsState {
    mode: CampaignsMode;
    pilot: number[];
    modes: CampaignsMode[];
    pilot_max: number;
}

const LABEL: Record<CampaignsMode, string> = {
    off: 'مقفلة',
    pilot: 'لمتاجر التجربة',
    all: 'مفتوحة لكل التجار',
};

/**
 * ‏‏مديرُ المنصّة يفتح حملاتِ واتساب — لمتاجر تجربةٍ بأرقامها، أو للجميع، أو يقفلها.
 *
 * والإقفالُ يوقف ما يُرسل الآن قبل رسالته التالية، ولا يمحو شيئًا. ويُقيَّد في السجلّ.
 */
export default function WhatsappCampaignsSwitch({ state }: { state: WhatsappCampaignsState }) {
    const t = useTranslate();
    const [confirm, dialog] = useConfirm();
    const [mode, setMode] = useState<CampaignsMode>(state.mode);
    const [pilot, setPilot] = useState(state.pilot.join('، '));
    const errors = (usePage().props.errors ?? {}) as Record<string, string>;

    const save = async () => {
        const ok = await confirm({
            title: 'حملات واتساب',
            message: mode === 'off'
                ? 'إقفالها يوقف الحملات التي تُرسل الآن — ولا يمحوها. متابعة؟'
                : 'تصير الحملات :state. يرسل التجار عروضًا إلى عملائهم الحقيقيين، وتُحتسب عليهم عند Meta. متابعة؟',
            values: { state: t(LABEL[mode]) },
            action: 'حفظ',
        });

        if (ok) {
            router.post(route('super-admin.whatsappCampaigns.switch'), { mode, pilot }, { preserveScroll: true });
        }
    };

    return (
        <Card className="mb-6 p-6" data-testid="campaigns-switch">
            {dialog}
            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div className="flex items-start gap-3">
                    <span className="flex size-9 items-center justify-center rounded-[10px] bg-[#f3f4f6]"><Megaphone className="size-[18px]" /></span>
                    <div>
                        <h2 className="font-bold text-[#111]">{t('حملات واتساب')}</h2>
                        <p className="mt-0.5 text-[13px] text-[#6b7280]">{t('يرسل التاجر عرضًا بقالب يعتمده واتساب إلى عملائه من رقمه — وكل رسالة تُحتسب على حسابه عند Meta.')}</p>
                    </div>
                </div>
                <Badge variant={state.mode === 'off' ? 'neutral' : 'success'} data-testid="campaigns-state">{t(LABEL[state.mode])}</Badge>
            </div>

            <div className="grid gap-2 sm:grid-cols-3" role="radiogroup">
                {state.modes.map((m) => (
                    <button key={m} type="button" role="radio" aria-checked={mode === m} onClick={() => setMode(m)} data-testid={`campaigns-mode-${m}`}
                        className={`rounded-[12px] border p-3 text-start text-[13px] font-medium ${mode === m ? 'border-2 border-[#111]' : 'border-[#e5e7eb]'}`}>
                        {t(LABEL[m])}
                    </button>
                ))}
            </div>

            {mode === 'pilot' && (
                <div className="mt-3 space-y-1">
                    <Input value={pilot} onChange={(e) => setPilot(e.target.value)} dir="ltr" placeholder="5, 12" data-testid="campaigns-pilot" />
                    <p className="text-[12px] text-[#9ca3af]">{t('أرقام المتاجر مفصولة بفاصلة — :max على الأكثر.', { max: state.pilot_max })}</p>
                </div>
            )}
            {errors.campaigns_pilot && <p className="mt-2 text-[12px] text-[#b91c1c]" data-testid="campaigns-error">{errors.campaigns_pilot}</p>}

            <div className="mt-4 flex justify-end">
                <Button type="button" onClick={save} data-testid="campaigns-save">{t('حفظ')}</Button>
            </div>
        </Card>
    );
}
