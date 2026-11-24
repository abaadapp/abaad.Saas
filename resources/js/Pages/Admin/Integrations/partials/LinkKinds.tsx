import { AlertTriangle, Server, Smartphone } from 'lucide-react';
import type { EmbeddedConfig, OnboardingKind } from '@/Pages/Admin/Integrations/partials/EmbeddedSignup';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * كيف يُربط الرقم — بطاقتان، ثمّ ما ستطلبه Meta خطوةً خطوة، ثمّ على من رسومُها.
 *
 * تقرؤها شاشتان: صفحةُ واتساب في حساب المتجر (`WhatsappLinkCard`)، وصفحةُ الرابط الذي يرسله فريقُ
 * أبعاد (`Auth/WhatsappLink`) — فلا يقرأ من فتح الرابط غيرَ ما يقرؤه من دخل حسابه. ولا اسمَ مسارٍ هنا:
 * صفحةُ الرابط بلا دخول، وجدولُ مساراتها جدولُ الزائر.
 */

/** بطاقتا الربط — رقمُ API مستقلٌّ أوّلًا وهو الافتراض، والبقاءُ في تطبيق الأعمال يُختار صراحةً */
const KINDS: { kind: OnboardingKind; icon: typeof Server; title: string; description: string }[] = [
    {
        kind: 'cloud_api',
        icon: Server,
        title: 'رقم API مخصّص للنظام',
        description: 'رقم يعمل من أبعاد وحده، منفصل عن واتساب في هاتفك — رقم واتساب في هاتفك لا يُمس. يردّ فريقك من صندوق المحادثات.',
    },
    {
        kind: 'coexistence',
        icon: Smartphone,
        title: 'أبقِ رقمك في تطبيق واتساب للأعمال',
        description: 'الرقم نفسه يبقى في تطبيق واتساب للأعمال على هاتفك ويصل النظام معًا، فتردّ من الهاتف ومن أبعاد.',
    },
];

/** ما ستطلبه Meta لكلّ طريقة — بالترتيب الذي تظهر به نافذتُها */
export const STEPS: Record<OnboardingKind, string[]> = {
    cloud_api: [
        'سجّل الدخول بفيسبوك واختر حساب نشاطك، أو أنشئه باسم نشاطك وبريدك.',
        'اكتب رقمًا لا يعمل على تطبيق واتساب الآن، وأدخل الرمز الذي يصلك برسالة أو مكالمة.',
        'ارجع إلى هنا واكتب رمز التحقّق بخطوتين (ستّة أرقام) لتفعيل الرقم.',
    ],
    coexistence: [
        'سجّل الدخول بفيسبوك واختر حساب نشاطك.',
        'اكتب رقمك المسجّل في تطبيق واتساب للأعمال.',
        'تعرض Meta رمزًا: أكّده من تطبيق واتساب للأعمال في هاتفك كما تطلب منك، واختر مشاركة محادثاتك السابقة.',
    ],
};

interface Props {
    config: EmbeddedConfig;
    kind: OnboardingKind;
    onKind: (kind: OnboardingKind) => void;
}

/**
 * رقمُ API مستقلٌّ للنظام هو الافتراض: رقمُ هاتفك الذي تستعمله لا يُمسّ. والبقاءُ في تطبيق واتساب
 * للأعمال (التعايش) يُختار صراحةً، ويبقى مقفلًا ما لم يُفتح على الخادم: ربطُه بلا مزامنةٍ يُضيّع تاريخَ
 * محادثاته مرّةً واحدة.
 */
export default function LinkKinds({ config, kind, onKind }: Props) {
    const t = useTranslate();

    return (
        <div className="mt-4" data-testid="link-kind">
            <p className="mb-2 text-[13px] font-semibold text-[#111]">{t('كيف تريد أن تربط رقمك؟')}</p>
            <div className="grid gap-3 sm:grid-cols-2">
                {KINDS.map((option) => {
                    const locked = option.kind === 'coexistence' && ! config.coexistence_open;
                    const Icon = option.icon;

                    return (
                        <label
                            key={option.kind}
                            data-testid={`link-kind-${option.kind}`}
                            className={cn(
                                'flex cursor-pointer items-start gap-3 rounded-[14px] border p-4 text-[12px] leading-relaxed transition-colors',
                                kind === option.kind ? 'border-[#111] bg-white shadow-[0_0_0_1px_#111]' : 'border-[var(--ui-border,#e8e8e8)] bg-white hover:bg-[#fafafa]',
                                locked && 'cursor-not-allowed opacity-60 hover:bg-white',
                            )}
                        >
                            <span className="flex size-10 shrink-0 items-center justify-center rounded-[10px] bg-[#f4f4f5] text-[#111]">
                                <Icon className="size-5" />
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="flex flex-wrap items-center gap-2">
                                    <span className="text-[14px] font-semibold text-[#111]">{t(option.title)}</span>
                                    {locked && (
                                        <span className="rounded-full bg-[#f4f4f5] px-2 py-0.5 text-[11px] text-[#6b7280]">{t('قريبًا')}</span>
                                    )}
                                </span>
                                <span className="mt-1 block text-[#6b7280]">
                                    {t(locked ? 'غير متاح بعد في أبعاد — نبني مزامنة رسائل الهاتف معه.' : option.description)}
                                </span>
                            </span>
                            <input
                                type="radio"
                                name="whatsapp-link-kind"
                                className="mt-1 accent-[#111]"
                                value={option.kind}
                                checked={kind === option.kind}
                                disabled={locked}
                                onChange={() => onKind(option.kind)}
                            />
                        </label>
                    );
                })}
            </div>

            {/* ما ستطلبه Meta — ثلاثُ خطواتٍ مرقّمة لما اختاره، لا مصطلحَ تقنيًّا فيها */}
            <div className="mt-4 rounded-[12px] bg-[#fafafa] p-4" data-testid="link-steps" data-kind={kind}>
                <p className="mb-3 text-[13px] font-semibold text-[#111]">{t('ما الذي ستطلبه Meta')}</p>
                <ol className="space-y-3">
                    {STEPS[kind].map((step, i) => (
                        <li key={i} className="flex items-start gap-3 text-[13px] leading-relaxed text-[#374151]">
                            <span className="flex size-6 shrink-0 items-center justify-center rounded-[8px] border border-[var(--ui-border,#e8e8e8)] bg-white text-[12px] font-semibold tabular-nums">
                                {i + 1}
                            </span>
                            <span>{t(step)}</span>
                        </li>
                    ))}
                </ol>
                {kind === 'coexistence' && (
                    <p className="mt-3 flex items-start gap-2 text-[12px] leading-relaxed text-[#6b7280]">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0 text-[#d97706]" />
                        {t('يتطلّب تطبيق واتساب للأعمال بالإصدار 2.24.17 أو أحدث، وتتوقّف فيه بعض الميزات مثل قوائم البثّ. وبقاء رقمك فيه تقرّره Meta بحسب أهلية حسابك — ونحن لا نُلغي تسجيل رقمك في كل الأحوال.')}
                    </p>
                )}
            </div>

            {/* ورسومُ Meta على التاجر نفسه — يُقال قبل الربط لا بعد أن تقف الردود */}
            <p className="mt-3 text-[12px] leading-relaxed text-[#6b7280]" data-testid="link-billing">
                {t('رسوم Meta على حسابك أنت مباشرة، لا على أبعاد: أوّل 1,000 ردّ في الشهر مجّانًا لكل رقم، وبعدها تحتاج وسيلة دفع في حسابك عند Meta لتستمر الردود. والإشعارات والحملات تُحتسب كلّ رسالة.')}{' '}
                <a href="https://business.facebook.com/billing_hub" target="_blank" rel="noopener noreferrer" className="font-medium text-[#111] underline underline-offset-2">
                    {t('الفوترة في Meta')}
                </a>
            </p>
        </div>
    );
}
