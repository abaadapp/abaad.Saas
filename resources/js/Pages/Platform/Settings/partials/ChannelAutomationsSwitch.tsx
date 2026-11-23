import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Bot } from 'lucide-react';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';

export type AutomationsMode = 'off' | 'pilot' | 'all';

/** ‏‏لمن تُفتح أتمتةُ الردود بالكلمات — `App\Support\ChannelAutomationsSwitch::state` */
export interface ChannelAutomationsState {
    mode: AutomationsMode;
    pilot: number[];
    modes: AutomationsMode[];
    pilot_max: number;
}

export const AUTOMATIONS_LABEL: Record<AutomationsMode, string> = {
    off: 'مقفلة',
    pilot: 'لمتاجر التجربة',
    all: 'مفتوحة لكل التجار',
};

/**
 * ‏‏مديرُ المنصّة يفتح أتمتةَ الردود بالكلمات — لمتاجر تجربةٍ بأرقامها، أو للجميع، أو يقفلها.
 *
 * والإقفالُ يُسكت الأتمتات المنشورة ولا يمحوها. ويسري من الطلب التالي، ويُقيَّد في السجلّ.
 */
export default function ChannelAutomationsSwitch({ state }: { state: ChannelAutomationsState }) {
    const t = useTranslate();
    const [confirm, dialog] = useConfirm();
    const [mode, setMode] = useState<AutomationsMode>(state.mode);
    const [pilot, setPilot] = useState(state.pilot.join('، '));
    const errors = (usePage().props.errors ?? {}) as Record<string, string>;

    const save = async () => {
        const ok = await confirm({
            title: 'أتمتة الردود بالكلمات',
            message: mode === 'off'
                ? 'إقفالها يسكت أتمتات التجار المنشورة — ولا يمحوها. متابعة؟'
                : 'تصير الأتمتة :state. تعمل على أرقام التجار الحقيقية حين ينشرون أتمتة. متابعة؟',
            values: { state: t(AUTOMATIONS_LABEL[mode]) },
            action: 'حفظ',
        });

        if (ok) {
            router.post(route('super-admin.automations.switch'), { mode, pilot }, { preserveScroll: true });
        }
    };

    return (
        <Card className="mb-6 p-6" data-testid="automations-switch">
            {dialog}
            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div className="flex items-start gap-3">
                    <span className="flex size-9 items-center justify-center rounded-[10px] bg-[#f3f4f6]"><Bot className="size-[18px]" /></span>
                    <div>
                        <h2 className="font-bold text-[#111]">{t('أتمتة الردود بالكلمات')}</h2>
                        <p className="mt-0.5 text-[13px] text-[#6b7280]">{t('يكتب التاجر كلمات وردًا، فيخرج الرد وحده من رقمه حين يكتبها زبون.')}</p>
                    </div>
                </div>
                <Badge variant={state.mode === 'off' ? 'neutral' : 'success'} data-testid="automations-state">{t(AUTOMATIONS_LABEL[state.mode])}</Badge>
            </div>

            <div className="grid gap-2 sm:grid-cols-3" role="radiogroup">
                {state.modes.map((m) => (
                    <button key={m} type="button" role="radio" aria-checked={mode === m} onClick={() => setMode(m)} data-testid={`automations-mode-${m}`}
                        className={`rounded-[12px] border p-3 text-start text-[13px] font-medium ${mode === m ? 'border-2 border-[#111]' : 'border-[#e5e7eb]'}`}>
                        {t(AUTOMATIONS_LABEL[m])}
                    </button>
                ))}
            </div>

            {mode === 'pilot' && (
                <div className="mt-3 space-y-1">
                    <Input value={pilot} onChange={(e) => setPilot(e.target.value)} dir="ltr" placeholder="5, 12" data-testid="automations-pilot" />
                    <p className="text-[12px] text-[#9ca3af]">{t('أرقام المتاجر مفصولة بفاصلة — :max على الأكثر.', { max: state.pilot_max })}</p>
                </div>
            )}
            {errors.pilot && <p className="mt-2 text-[12px] text-[#b91c1c]" data-testid="automations-error">{errors.pilot}</p>}

            <div className="mt-4 flex justify-end">
                <Button type="button" onClick={save} data-testid="automations-save">{t('حفظ')}</Button>
            </div>
        </Card>
    );
}
