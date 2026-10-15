import { UserPlus } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import StatCard, { type Stat } from '@/Components/StatCard';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';

/** صفٌّ من `SelfSignupsList::rows` — كلُّه من صفّ الشركة نفسِه واستعلاماتٍ فرعيّة */
export interface SelfSignupRow {
    id: number;
    name: string;
    owner: string | null;
    type: string;
    phone: string | null;
    /** بريدُ التواصل المكتوب عند التسجيل */
    contactEmail: string | null;
    /** بريدُ دخول المالك */
    loginEmail: string | null;
    teamSize: string | null;
    address: string | null;
    city: string | null;
    registeredAt: string | null;
    plan: string | null;
    trialStarts: string | null;
    trialEnds: string | null;
    daysLeft: number | null;
    /** من تعاريف `Business` — و`null` لما لا يقع في أيٍّ منها، فيُعرض بحالة الشركة */
    state: 'trialing' | 'expired' | 'subscribed' | null;
    status: string;
    /** ختمُ شاشة التهيئة — لا تخمينٌ من الاسم */
    setupConfirmedAt: string | null;
    lastLogin: string | null;
    products: number;
    firstSale: string | null;
    lastSale: string | null;
}

export interface SelfSignupStats {
    total: number;
    trialing: number;
    expired: number;
    subscribed: number;
}

/** الأرقامُ الأربعة بالبطاقة نفسِها التي تعرض بها لوحةُ المنصّة أرقامَها */
export function selfSignupStatCards(stats: SelfSignupStats): Stat[] {
    return [
        { label: 'إجمالي التسجيلات الذاتية', value: number(stats.total), icon: 'user-plus', color: 'primary' },
        { label: 'في التجربة الآن', value: number(stats.trialing), icon: 'hourglass', color: 'info' },
        { label: 'انتهت تجربتهم دون اشتراك', value: number(stats.expired), icon: 'badge-x', color: 'warning' },
        { label: 'تحوّلوا إلى مشتركين', value: number(stats.subscribed), icon: 'badge-check', color: 'success' },
    ];
}

/** حالةُ التجربة — والأيّامُ الباقية معها، فلا يُحسب شيءٌ بالعين */
export function SignupState({ row }: { row: SelfSignupRow }) {
    const t = useTranslate();

    if (row.state === 'trialing') {
        return (
            <Badge variant="info" data-signup-state="trialing">
                {t('في التجربة')}
                {row.daysLeft !== null && <span className="opacity-80">· {t('بقي :n يوم', { n: row.daysLeft })}</span>}
            </Badge>
        );
    }

    if (row.state === 'expired') {
        return (
            <Badge variant="warning" data-signup-state="expired">
                {t('انتهت التجربة')}
            </Badge>
        );
    }

    if (row.state === 'subscribed') {
        return (
            <Badge variant="success" data-signup-state="subscribed">
                {t('مشترك')}
            </Badge>
        );
    }

    // لا تُرمى في أقرب الثلاث: تُعرض بحالتها هي (معطّلةٌ مثلًا)
    return <Badge status={row.status} data-signup-state="other" />;
}

interface Props {
    stats: SelfSignupStats;
    recent: SelfSignupRow[];
}

/**
 * «التسجيلات من الموقع» — قسمٌ ثانٍ في صفحة الشركات، فوق جدولها.
 *
 * صفوفُ الشركات نفسُها (`registration_source = self_signup`) لا نسخةٌ منها،
 * والاسمُ يفتح ملفَّ الشركة القائم. و«عرض الكل» يفتح صفحتَه — ولا بابَ لها
 * في الشريط الجانبيّ.
 */
export default function SelfSignupsSection({ stats, recent }: Props) {
    const t = useTranslate();

    return (
        <section
            data-self-signups
            aria-labelledby="self-signups-title"
            className="mb-6 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] bg-white p-4 sm:p-5"
        >
            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 id="self-signups-title" className="flex items-center gap-2 text-[15px] font-semibold text-[#111]">
                        <UserPlus className="size-4 text-[#6d28d9]" />
                        {t('التسجيلات من الموقع')}
                    </h2>
                    <p className="mt-0.5 text-[12.5px] text-[#6b7280]">
                        {t('من فتح متجره بنفسه من صفحة التسجيل — على الباقة الأساسية وتجربة مجانية')}
                    </p>
                </div>
                {stats.total > 0 && (
                    <Button variant="outline" size="sm" asChild>
                        <SmartLink
                            routeName="super-admin.businesses.self-signups.index"
                            href={route('super-admin.businesses.self-signups.index')}
                        >
                            {t('عرض الكل')}
                        </SmartLink>
                    </Button>
                )}
            </div>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {selfSignupStatCards(stats).map((s, i) => (
                    <StatCard key={s.label} stat={s} index={i} />
                ))}
            </div>

            {recent.length === 0 ? (
                <p data-self-signups-empty className="mt-4 rounded-[10px] bg-[#fafaf9] px-4 py-6 text-center text-[13px] text-[#6b7280]">
                    {t('لا توجد تسجيلات ذاتية بعد')}
                </p>
            ) : (
                <ul className="mt-4 divide-y divide-[var(--ui-border,#e8e8e8)]">
                    {recent.map((r) => (
                        <li key={r.id} data-self-signup-row={r.id} className="flex flex-wrap items-center gap-x-4 gap-y-1.5 py-3">
                            <div className="min-w-0 flex-1">
                                <SmartLink
                                    routeName="super-admin.businesses.show"
                                    href={route('super-admin.businesses.show', r.id)}
                                    className="font-medium text-[#111] hover:underline"
                                >
                                    {r.name}
                                </SmartLink>
                                <p className="truncate text-[12.5px] text-[#6b7280]">
                                    {[r.owner, t(r.type), r.plan ? t(r.plan) : null].filter(Boolean).join(' · ')}
                                </p>
                            </div>
                            <span dir="ltr" className="text-[12.5px] text-[#6b7280] tabular-nums">
                                {r.registeredAt}
                            </span>
                            <SignupState row={r} />
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
