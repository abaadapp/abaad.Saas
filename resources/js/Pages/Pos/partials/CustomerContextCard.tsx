import { useState } from 'react';
import { Button } from '@/Components/ui/button';
import { Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { CustomerContext } from '@/types/models';

interface Props {
    context: CustomerContext;
    /** يملك «تجاوز حظر البيع» ومقبضُ الإعدادات مفتوح — يقوله الخادم */
    canOverride: boolean;
    overrideReason: string;
    onOverrideReason: (reason: string) => void;
}

/** «اليوم» و«غدًا» و«بعد ٣ أيام» — لا رقمٌ عارٍ */
export function birthdayPhrase(days: number, t: (k: string, r?: Record<string, string | number>) => string): string {
    if (days <= 0) return t('🎂 عيد ميلاد العميل اليوم');
    if (days === 1) return t('🎂 عيد ميلاد العميل غدًا');

    return t('🎂 عيد ميلاد العميل بعد :n أيام', { n: days });
}

/**
 * ما يُقال للكاشير عن الزبون بعد اختياره — بطاقةٌ واحدة لا نافذةٌ فوق نافذة.
 *
 * الترتيبُ بالأهمّية: الحظرُ، فالتحذيرُ، فعيدُ الميلاد، فالملاحظة. والتحذيرُ
 * يُقرأ ويُطوى بزرّ «متابعة البيع» ولا يمنع؛ والحظرُ لا يُطوى — يبقى حتى
 * يُكتب سببُ تجاوزٍ ممّن يملكه، والخادمُ يقيسه ثانيةً.
 */
export default function CustomerContextCard({ context, canOverride, overrideReason, onOverrideReason }: Props) {
    const t = useTranslate();
    const [acknowledged, setAcknowledged] = useState(false);

    const { alert, birthday_in: birthdayIn, note } = context;
    const block = alert?.type === 'block' ? alert : null;
    const warning = alert?.type === 'warning' && !acknowledged ? alert : null;

    if (!block && !warning && birthdayIn === null && !note) return null;

    return (
        <div className="mt-3 space-y-2" data-testid="customer-context">
            {block && (
                <div className="rounded-xl border border-[#fecaca] bg-[#fef2f2] p-3 text-[12px] text-[#b91c1c]" role="alert">
                    <p className="font-bold">⛔ {t('البيع لهذا العميل موقوف')}</p>
                    {block.reason && <p className="mt-1">{block.reason}</p>}
                    {canOverride ? (
                        <label className="mt-2 block text-[11px] text-[#7f1d1d]">
                            {t('سبب التجاوز')}
                            <Textarea
                                rows={2}
                                className="mt-1 bg-white"
                                value={overrideReason}
                                onChange={(e) => onOverrideReason(e.target.value)}
                                placeholder={t('اكتب سببَ تجاوز الحظر — يُقيَّد في السجلّ')}
                            />
                        </label>
                    ) : (
                        <p className="mt-1 text-[11px] text-[#7f1d1d]">{t('يلزم إذنُ من يملك تجاوز الحظر.')}</p>
                    )}
                </div>
            )}

            {warning && (
                <div className="rounded-xl border border-[#fde68a] bg-[#fffbeb] p-3 text-[12px] text-[#92400e]" role="alert">
                    <p className="font-bold">⚠️ {t('تنبيه على العميل')}</p>
                    {warning.reason && <p className="mt-1">{warning.reason}</p>}
                    <Button type="button" size="sm" variant="outline" className="mt-2" onClick={() => setAcknowledged(true)}>
                        {t('متابعة البيع')}
                    </Button>
                </div>
            )}

            {birthdayIn !== null && (
                <p className="rounded-xl bg-[#f5f3ff] px-3 py-2 text-[12px] font-medium text-[#6d28d9]">{birthdayPhrase(birthdayIn, t)}</p>
            )}

            {note && (
                <p className="rounded-xl bg-gray-50 px-3 py-2 text-[12px] text-[#4b4b4b]">
                    <span className="font-medium text-[#6b7280]">{t('ملاحظة داخلية')}: </span>
                    {note}
                </p>
            )}
        </div>
    );
}
