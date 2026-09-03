import { Check, ChevronLeft, Globe, ShoppingBag, Tag } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * سعرُ العنوان كما يصل من الخادم — انظر `Storefront::pricing`.
 *
 * و`free` تصل محسوبةً ولا تُشتقّ هنا من الصفر: «مجّانًا» و«٠٫٠٠٠» حكمٌ
 * واحد، وحسابُه في طرفين يجعل شاشةً تقول رقمًا ويقول الخادم مجّانًا.
 */
export type DomainPricing = {
    currency: string;
    subdomain: { monthly: number; yearly: number; free: boolean };
};

export type DomainPath = '' | 'sub' | 'own' | 'new';

/** السعر جملةً واحدة — أو «مشمول» حين لا رسم */
export function subdomainPrice(pricing: DomainPricing, t: (s: string) => string): string {
    const { monthly, yearly, free } = pricing.subdomain;

    if (free) {
        return t('مشمول في باقتك — بلا رسوم إضافية');
    }

    const parts: string[] = [];
    if (monthly > 0) {
        parts.push(`${monthly.toFixed(3)} ${pricing.currency} ${t('شهريًا')}`);
    }
    if (yearly > 0) {
        parts.push(`${yearly.toFixed(3)} ${pricing.currency} ${t('سنويًا')}`);
    }

    return parts.join(' · ');
}

type Option = {
    key: Exclude<DomainPath, ''>;
    icon: typeof Globe;
    title: string;
    desc: string;
    price: string;
    note?: string;
    best?: boolean;
};

/**
 * السؤالُ الأوّل: من أين يأتي عنوان متجرك؟
 *
 * ويُطرح مرّةً واحدة لأنّ البطاقتين كانتا تُعرضان معًا وفيهما كلمةُ «نطاق»
 * بمعنيين متضادّين — «موقعي عندكم» و«موقعي عند غيركم». فيكتب التاجر عنوان
 * متجره في حقل الموقع الخارجيّ، ويصير زرُّ «فتح الموقع» يشير إلى عنوانٍ لا
 * وجود له.
 *
 * والسعرُ مكتوبٌ في البطاقة لا مخبوءٌ خلف اختيار: من يعرف الثمن قبل أن يختار
 * لا يعود ليُلغي.
 */
export default function DomainChooser({
    pricing,
    domain,
    current,
    onPick,
    onCancel,
    busy,
}: {
    pricing: DomainPricing;
    domain: string;
    current: DomainPath;
    onPick: (path: Exclude<DomainPath, ''>) => void;
    onCancel?: () => void;
    busy?: boolean;
}) {
    const t = useTranslate();

    const options: Option[] = [
        {
            key: 'sub',
            icon: ShoppingBag,
            title: t('استخدم نطاق أبعاد'),
            desc: t('صفحةُ متجرٍ يستضيفها أبعاد بمنتجاتك وصورها، تعمل اليوم بلا شراءٍ ولا إعداد.'),
            price: subdomainPrice(pricing, t),
            note: `my-store.${domain}`,
            best: true,
        },
        {
            key: 'own',
            icon: Globe,
            title: t('عندي نطاق'),
            desc: t('موقعك قائمٌ عند غيرنا — نربط إليه من النظام ونفحص ظهوره في البحث.'),
            price: t('بلا رسوم — أبعاد لا يستضيف هذا الموقع'),
        },
        {
            key: 'new',
            icon: Tag,
            title: t('أريد نطاقًا جديدًا'),
            desc: t('ما عندك نطاق بعد — نقول لك من أين يُشترى، وبم تبدأ اليوم.'),
            price: t('ثمنُ النطاق يُدفع لمُسجِّله لا لأبعاد'),
        },
    ];

    return (
        <Card className="p-6">
            <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 className="font-bold text-[#111]">{t('عنوان متجرك على الإنترنت')}</h3>
                    <p className="mt-1 text-[13px] text-[#6b7280]">
                        {t('اختر من أين يأتي العنوان — ويمكنك تغييره لاحقًا بلا أن تفقد ما ضبطت.')}
                    </p>
                </div>
                {onCancel && (
                    <Button type="button" variant="ghost" size="sm" onClick={onCancel}>
                        {t('إلغاء')}
                    </Button>
                )}
            </div>

            <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                {options.map((o) => {
                    const Icon = o.icon;
                    const picked = current === o.key;

                    return (
                        <button
                            key={o.key}
                            type="button"
                            disabled={busy}
                            onClick={() => onPick(o.key)}
                            className={cn(
                                'group flex h-full flex-col rounded-[16px] border p-5 text-start transition disabled:opacity-60',
                                picked
                                    ? 'border-[#111] bg-[#fafafa]'
                                    : 'border-[var(--ui-border,#e8e8e8)] bg-white hover:border-[#d4d4d4] hover:bg-[#fafafa]',
                            )}
                        >
                            <div className="mb-3 flex items-center gap-2">
                                <Icon className="size-5 shrink-0 text-[#6b7280]" />
                                <span className="text-[15px] font-semibold text-[#111]">{o.title}</span>
                                {o.best && (
                                    <span className="rounded-full bg-[#f0fdf4] px-2 py-0.5 text-[11px] font-medium text-[#15803d]">
                                        {t('الأسهل')}
                                    </span>
                                )}
                                {picked && <Check className="ms-auto size-4 shrink-0 text-[#111]" />}
                            </div>

                            <p className="text-[13px] leading-relaxed text-[#6b7280]">{o.desc}</p>

                            {o.note && (
                                <p dir="ltr" className="mt-2 text-[12px] text-[#9ca3af]">
                                    {o.note}
                                </p>
                            )}

                            {/* السعر آخرَ ما يُقرأ وأثبتَ ما في البطاقة: يُحاذى أسفلها مهما طال الوصف */}
                            <p className="mt-auto pt-4 text-[13px] font-medium text-[#111]">{o.price}</p>
                        </button>
                    );
                })}
            </div>

            {/*
                ولا يُقال «ادفع» هنا ولا يُخصم شيء: الفوترة تقع في اشتراكك،
                وزرٌّ يقول «ادفع» ولا يدفع أسوأ من سعرٍ مكتوبٍ بجانبه أثرُه.
            */}
            {!pricing.subdomain.free && (
                <p className="mt-4 rounded-[10px] bg-[#f9fafb] px-3 py-2 text-[12px] text-[#6b7280]">
                    {t('رسوم نطاق أبعاد تُضاف إلى فاتورة اشتراكك — لا يُخصم شيء من هذه الصفحة.')}
                </p>
            )}
        </Card>
    );
}

/** شريطُ الطريق المختار — يقول ما اختير ويفتح تغييره في نقرة */
export function DomainPathStrip({
    path,
    pricing,
    onChange,
}: {
    path: Exclude<DomainPath, ''>;
    pricing: DomainPricing;
    onChange: () => void;
}) {
    const t = useTranslate();

    const label =
        path === 'sub' ? t('نطاق أبعاد') : path === 'own' ? t('نطاقي الخاص') : t('أبحث عن نطاق');

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-[16px] border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] px-5 py-3">
            <div className="min-w-0">
                <span className="text-[13px] text-[#6b7280]">{t('طريق العنوان')}: </span>
                <span className="text-[14px] font-semibold text-[#111]">{label}</span>
                {path === 'sub' && (
                    <span className="ms-2 text-[13px] text-[#6b7280]">· {subdomainPrice(pricing, t)}</span>
                )}
            </div>
            <Button type="button" variant="outline" size="sm" onClick={onChange}>
                {t('تغيير')}
                <ChevronLeft />
            </Button>
        </div>
    );
}

/**
 * من لا نطاق له — وما نقوله له بصدق.
 *
 * أبعاد لا يبيع النطاقات ولا يسجّلها، وبطاقةٌ توحي بذلك تنتهي بتاجرٍ ينتظر
 * نطاقًا لا يأتي. فالبطاقة تقول من أين يُشترى، وتفتح له البابَ العامل اليوم.
 */
export function NewDomainCard({
    pricing,
    domain,
    onPick,
    busy,
}: {
    pricing: DomainPricing;
    domain: string;
    onPick: (path: Exclude<DomainPath, ''>) => void;
    busy?: boolean;
}) {
    const t = useTranslate();

    return (
        <Card className="p-6">
            <h3 className="font-bold text-[#111]">{t('الحصول على نطاق جديد')}</h3>
            <p className="mt-1 text-[13px] text-[#6b7280]">
                {t('أبعاد لا يبيع النطاقات ولا يسجّلها — النطاق يُشترى من مُسجِّل ويُجدَّد عنده سنويًّا.')}
            </p>

            <ol className="mt-5 space-y-3 text-[13px] leading-relaxed text-[#374151]">
                <li>
                    <span className="font-semibold text-[#111]">١. {t('اختر الامتداد')}</span>
                    <br />
                    {t('نطاق ‎.om‎ يُسجَّل عبر مُسجِّل معتمد في سلطنة عُمان، و‎.com‎ من أي مُسجِّل عالمي.')}
                </li>
                <li>
                    <span className="font-semibold text-[#111]">٢. {t('اشترِ النطاق باسمك أنت')}</span>
                    <br />
                    {t('سجّله بحسابك لا بحساب غيرك — النطاق المسجَّل باسم شخصٍ آخر يبقى ملكه لا ملكك.')}
                </li>
                <li>
                    <span className="font-semibold text-[#111]">٣. {t('عد إلى هنا واختر «عندي نطاق»')}</span>
                    <br />
                    {t('اكتبه فيُربط زرُّ الموقع في النظام ويُفحص ظهورُه في البحث.')}
                </li>
            </ol>

            {/* والبابُ العامل اليوم يُفتح من هنا لا يُترك للبحث عنه */}
            <div className="mt-6 rounded-[12px] bg-[#f9fafb] p-4">
                <p className="text-[13px] font-semibold text-[#111]">{t('وحتى تشتريه — ابدأ اليوم')}</p>
                <p className="mt-1 text-[13px] text-[#6b7280]">
                    {t('نطاق أبعاد الفرعيّ يعمل فورًا، وينتقل زبائنك إلى نطاقك حين تجهّزه.')}
                </p>
                <p dir="ltr" className="mt-2 text-[12px] text-[#9ca3af]">
                    my-store.{domain}
                </p>
                <p className="mt-2 text-[13px] font-medium text-[#111]">{subdomainPrice(pricing, t)}</p>
                <div className="mt-4 flex flex-wrap gap-2">
                    <Button type="button" size="sm" disabled={busy} onClick={() => onPick('sub')}>
                        {t('استخدم نطاق أبعاد الآن')}
                    </Button>
                    <Button type="button" variant="outline" size="sm" disabled={busy} onClick={() => onPick('own')}>
                        {t('عندي نطاق الآن')}
                    </Button>
                </div>
            </div>
        </Card>
    );
}
