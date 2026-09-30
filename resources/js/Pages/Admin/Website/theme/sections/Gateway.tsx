import { Link } from '@inertiajs/react';
import { CreditCard, ExternalLink } from 'lucide-react';

import StatusPill from '@/Components/StatusPill';
import { SettingsSection } from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';

/**
 * حالُ Paymob كما يقرؤها الموقع — `Store\PaymobSettings::summary`.
 *
 * لا مفتاحَ فيها ولا رقمَ تكامل: الموقعُ يقول أمربوطةٌ هي ولا يُحرّرها.
 */
export interface GatewayState {
    state: 'off' | 'partial' | 'ready';
    card_ready: boolean;
    /** رقمُ تكامل Apple Pay مُضافٌ — لا «مفعّل»: التفعيلُ عند Paymob */
    apple_pay: boolean;
    /** أفي الموقع سلّةٌ وإتمامُ طلب؟ */
    checkout: boolean;
    /** أيدفع الزبونُ بها على الموقع الآن؟ */
    online: boolean;
}

const PAYMOB = {
    off: { pill: 'idle', label: 'غير مربوط', action: 'ربط Paymob' },
    partial: { pill: 'progress', label: 'لم يكتمل', action: 'إكمال الربط' },
    ready: { pill: 'ready', label: 'مربوط', action: 'إدارة Paymob' },
} as const;

/**
 * «الدفع الإلكتروني» — حالُ Paymob ورابطٌ إلى بيتها.
 *
 * ═══ ولمَ لا نموذج هنا ═══
 *
 * كانت مفاتيحُ Paymob تُحرَّر في شاشتين من شاشات الموقع بنموذجين متطابقين.
 * فصار لها بيتٌ واحد: «التطبيقات التكاملية ← Paymob». وهذه البطاقةُ تقول
 * الحالَ — الحسابُ، والبطاقات، و Apple Pay — وتُحيل إليه، ولا تحمل مفتاحًا.
 */
export default function Gateway({ gateway }: { gateway: GatewayState }) {
    return (
        <section id="gateway" className="scroll-mt-24">
            <SettingsSection
                icon={CreditCard}
                title="الدفع الإلكتروني"
                description="مفاتيحك أنت من حساب Paymob الخاص بك، والمال يصل إلى حسابك ولا يمر عبر أبعاد."
            >
                <PaymobStatus gateway={gateway} />
            </SettingsSection>
        </section>
    );
}

/**
 * جسدُ الحال وحده — يُلبَس بطاقةً في شاشة المتجر، ومجموعةً في الإعدادات
 * العامّة (بطاقةٌ داخل بطاقةٍ تُضاعف الحدود ولا تُضيف معنًى).
 */
export function PaymobStatus({ gateway }: { gateway: GatewayState }) {
    const t = useTranslate();
    const paymob = PAYMOB[gateway.state];

    const rows = [
        { name: 'Paymob', pill: paymob.pill, label: paymob.label },
        {
            name: 'البطاقات',
            pill: gateway.online ? 'ready' : 'idle',
            label: gateway.online ? 'جاهزة' : 'غير جاهزة',
        },
        {
            name: 'Apple Pay',
            pill: gateway.apple_pay ? 'ready' : 'idle',
            label: gateway.apple_pay ? 'مُضاف' : 'غير مُضاف',
        },
    ] as const;

    return (
        <div data-testid="paymob-status">
            <ul className="m-0 grid list-none gap-2 p-0">
                {rows.map((row) => (
                    <li key={row.name} className="flex items-center justify-between gap-3 text-[13px]">
                        <span className="font-medium text-[#111]">{t(row.name)}</span>
                        <StatusPill state={row.pill} label={row.label} />
                    </li>
                ))}
            </ul>

            {/* والسببُ يُقال: بوّابةٌ مربوطةٌ لا تكفي لدفعٍ على موقعٍ بلا سلّة */}
            {gateway.state === 'ready' && !gateway.checkout && (
                <p className="mt-3 text-[12px] leading-relaxed text-[#6b7280]">
                    {t('Paymob مربوط، لكن لا سلّة في موقعك بعد — الدفع الإلكتروني يعمل في المواقع ذات السلّة وإتمام الطلب.')}
                </p>
            )}

            <div className="mt-4">
                <Button asChild variant="outline" size="sm">
                    <Link href={route('admin.integrations.paymob')}>
                        <ExternalLink />
                        {t(paymob.action)}
                    </Link>
                </Button>
            </div>
        </div>
    );
}
