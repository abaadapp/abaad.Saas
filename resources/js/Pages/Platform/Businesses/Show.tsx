import { useEffect, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, Archive, KeyRound, Layers, Loader2, LogIn, Pencil, Phone, Power, Trash2, User } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import DeleteButton from '@/Components/DeleteButton';
import SmartLink from '@/Components/SmartLink';
import StatCard, { type Stat } from '@/Components/StatCard';
import Tabs from '@/Components/Tabs';
import GoldBadge from '@/Components/GoldBadge';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import Field, { Select } from '@/Components/Field';
import AccountCard from './partials/AccountCard';
import { BusinessDocuments, type BusinessDocumentsData } from './partials/BusinessDocuments';
import WhatsAppCard, { type BusinessWhatsApp } from './partials/WhatsAppCard';
import RecoveryCard, { type BusinessRecovery } from './partials/RecoveryCard';
import GoogleCard, { type BusinessGoogle } from './partials/GoogleCard';
import AddonsPanel, { type BusinessAddons } from './partials/AddonsPanel';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { isDisabled } from '@/lib/tenancy';
import { cn } from '@/lib/utils';
import type { Currency, PageProps } from '@/types';

interface Business {
    id: number;
    name: string;
    type: string;
    owner: string | null;
    phone: string | null;
    email: string | null;
    plan: string;
    status: string;
    tier?: string | null;
    registered: string;
    branches: number;
    city: string | null;
    country: string | null;
    logo: string | null;
    owner_email: string | null;
}

interface Subscription {
    plan: string;
    start: string;
    end: string;
    amount: number;
    payment: string;
    status: string;
}

/** تجديدُ المتجر كما تقرؤه لوحةُ المنصّة — `PageController::businessesShow` */
export interface Renewal {
    monthly: number;
    semiannual: number;
    yearly: number;
    endsAt: string | null;
    status: string;
    /** فاتورةُ التجديد التي تنتظر سدادها — والاشتراكُ كما هو حتى تُسدَّد */
    pending: { invoice_id: number; number: string | null; cycle: string | null; amount: number; issued: string | null; upgrade_to?: string | null } | null;
    /** باقاتٌ يُرقّى إليها في الحساب نفسِه — بسعرها في سياسته، وتسري بسداد فاتورتها */
    upgrades?: { id: number; name: string; prices: { monthly: number; semiannual: number; yearly: number } | null }[];
    unpaid: { id: number; number: string; amount: number; date: string | null; kind: string }[];
}

/** اسمُ الدورة كما يقرؤه مديرُ المنصّة */
const CYCLE_LABEL: Record<string, string> = { monthly: 'شهر', semiannual: 'ستّة أشهر', yearly: 'سنة' };

interface BranchRow {
    id: number;
    name: string;
    phone: string | null;
    address: string | null;
    employees: number;
}

interface OrderRow {
    id: string;
    customer: string;
    items_count: number;
    total: number;
    payment: string;
    status: string;
    date: string;
}

interface UsageRow {
    key: string;
    label: string;
    used: number;
    /** null = لا سقف: متجرٌ بلا باقة لا يُقيَّد */
    cap: number | null;
}

interface Props {
    business: Business;
    subscription: Subscription | null;
    usage: UsageRow[];
    renewal: Renewal;
    stats: Stat[];
    overview: { sales: string; orders: number; average: string };
    branches: BranchRow[];
    orders: OrderRow[];
    currency: Currency;
    whatsapp: BusinessWhatsApp | null;
    recovery: BusinessRecovery;
    /** حالُ Google لهذا المتجر — نعم ولا، بلا مفتاحٍ ولا رمز */
    google?: BusinessGoogle;
    /** أمفتوحٌ بابُ الحذف النهائيّ على هذا الخادم؟ — `config/purge.php` */
    purge: boolean;
    /** حالُ آخرِ محاولةِ حذفٍ نهائيّ لهذه الشركة — أو `null` إن لم تكن */
    purgeRun: PurgeRunState | null;
    /** مستنداتُ الشركة من قسم المستندات — آخرُ خمسين، والعددُ كلُّه */
    documents: BusinessDocumentsData;
    /** سياسةُ التسعير وإضافاتُ الاشتراك — `Addons\AddonViews::forBusiness` */
    addons: BusinessAddons;
}


const TABS = [
    { key: 'overview', label: 'نظرة عامة' },
    { key: 'branches', label: 'الفروع' },
    { key: 'orders', label: 'آخر الطلبات' },
    { key: 'addons', label: 'الإضافات' },
];

export default function BusinessShow() {
    const {
        business,
        subscription,
        usage,
        renewal,
        stats,
        overview,
        branches,
        orders,
        currency,
        whatsapp,
        recovery,
        google,
        purge,
        purgeRun,
        documents,
        addons,
    } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [tab, setTab] = useState('overview');
    const facts = [
        { label: 'الاسم', value: business.name },
        { label: 'النوع', value: t(business.type) },
        { label: 'المدينة', value: business.city },
        { label: 'الدولة', value: business.country },
        { label: 'تاريخ التسجيل', value: business.registered, ltr: true },
    ];

    const owner = [
        { label: 'الاسم', value: business.owner },
        { label: 'الهاتف', value: business.phone, ltr: true },
        { label: 'بريد التواصل', value: business.email, ltr: true },
        // بريد الدخول لا بريد التواصل — أوّل ما يُسأل عنه حين يتصل التاجر
        { label: 'حساب الدخول', value: business.owner_email, ltr: true },
    ];

    return (
        <PlatformLayout title="ملف الشركة">
            <PageHeader
                title="ملف الشركة"
                subtitle={business.name}
                actions={
                    <>
                        {/* الدعم يحتاج أن يرى الشاشة التي يشكو منها التاجر لا وصفها */}
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(route('super-admin.businesses.impersonate', business.id))
                            }
                        >
                            <LogIn />
                            {t('دخول كتاجر')}
                        </Button>
                        <Button variant="outline" asChild>
                            <SmartLink
                                routeName="super-admin.businesses.edit"
                                href={route('super-admin.businesses.edit', business.id)}
                            >
                                <Pencil />
                                {t('تعديل')}
                            </SmartLink>
                        </Button>
                        {/* المعطَّلة تُعرض عليها إعادة التشغيل وحدها، والعاملة
                            التعطيل وحده — لا زرَّ لا يفعل شيئًا */}
                        {isDisabled(business.status) ? (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        route('super-admin.businesses.activate', business.id),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Power />
                                {t('إعادة تشغيل')}
                            </Button>
                        ) : (
                            <DeleteButton
                                url={route('super-admin.businesses.destroy', business.id)}
                                label="تعطيل"
                                message="سيُنقل هذا النشاط إلى حالة «معطل». هل تريد المتابعة؟"
                            />
                        )}
                    </>
                }
            />

            <Card className="mb-6 p-6">
                <div className="flex flex-col gap-5 sm:flex-row sm:items-center">
                    {business.logo ? (
                        <img
                            src={business.logo}
                            alt=""
                            className="size-24 shrink-0 rounded-[16px] border border-[var(--ui-border,#e8e8e8)] object-cover"
                        />
                    ) : (
                        <span className="size-24 shrink-0 rounded-[16px] bg-[#f2f2f0]" />
                    )}
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-3">
                            <h2 className="text-[20px] font-bold text-[#111]">{business.name}</h2>
                            <Badge status={business.status} />
                            <GoldBadge tier={business.tier} />
                        </div>
                        <p className="mt-1 text-sm text-[#6b7280]">
                            {t(business.type)}
                            {business.city && ` — ${business.city}${business.country ? `، ${business.country}` : ''}`}
                        </p>
                        <div className="mt-3 flex flex-wrap items-center gap-4 text-sm text-[#6b7280]">
                            <span className="flex items-center gap-1.5">
                                <User className="size-4" />
                                {business.owner || '—'}
                            </span>
                            <span dir="ltr" className="flex items-center gap-1.5">
                                <Phone className="size-4" />
                                {business.phone || '—'}
                            </span>
                            <span className="flex items-center gap-1.5">
                                <Layers className="size-4" />
                                {t('باقة')} {t(business.plan)}
                            </span>
                        </div>
                    </div>
                </div>
            </Card>

            {business.owner_email ? (
                <AccountCard businessId={business.id} ownerEmail={business.owner_email} />
            ) : (
                /* شركةٌ بلا حساب: لا يفتحها أحد — والإنشاء من صفحة التعديل حيث يُلزَم */
                <Card className="mb-6 flex flex-wrap items-center justify-between gap-3 border-[#fed7aa] bg-[#fff7ed] p-5">
                    <p className="text-[13px] text-[#9a3412]">
                        {t('هذه الشركة بلا حساب دخول — لا يستطيع صاحبها فتح لوحته.')}
                    </p>
                    <Button variant="outline" size="sm" asChild>
                        <SmartLink
                            routeName="super-admin.businesses.edit"
                            href={route('super-admin.businesses.edit', business.id)}
                        >
                            <KeyRound />
                            {t('إنشاء حساب')}
                        </SmartLink>
                    </Button>
                </Card>
            )}

            {/* وسيلة الاستعادة — أوّل ما يُسأل عنه حين يتّصل تاجرٌ نسي كلمته */}
            {recovery && <RecoveryCard businessId={business.id} data={recovery} />}

            {/* واتساب — بطاقةٌ في ملفّ المتجر لا شاشة ثالثة */}
            {whatsapp && <WhatsAppCard businessId={business.id} data={whatsapp} />}

            {/* Google — حالٌ تُقرأ لا إعدادٌ يُضبط: يربطه التاجر بنفسه */}
            {google && <GoogleCard data={google} />}

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                {stats.map((s, i) => (
                    <StatCard key={s.label} stat={s} index={i} />
                ))}
            </div>

            <div className="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card className="p-5">
                    <h3 className="mb-4 font-bold text-[#111]">{t('بيانات الشركة')}</h3>
                    <dl className="space-y-3 text-sm">
                        {facts.map((f) => (
                            <div key={f.label} className="flex justify-between gap-3">
                                <dt className="text-[#6b7280]">{t(f.label)}</dt>
                                <dd dir={f.ltr ? 'ltr' : undefined} className="truncate font-medium text-[#111]">
                                    {f.value || '—'}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </Card>

                <Card className="p-5">
                    <h3 className="mb-4 font-bold text-[#111]">{t('بيانات المالك')}</h3>
                    <dl className="space-y-3 text-sm">
                        {owner.map((f) => (
                            <div key={f.label} className="flex justify-between gap-3">
                                <dt className="text-[#6b7280]">{t(f.label)}</dt>
                                <dd dir={f.ltr ? 'ltr' : undefined} className="truncate font-medium text-[#111]">
                                    {f.value || '—'}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </Card>

                <Card className="p-5">
                    <h3 className="mb-4 font-bold text-[#111]">{t('الاشتراك الحالي')}</h3>
                    {subscription ? (
                        <dl className="space-y-3 text-sm">
                            <div className="flex justify-between gap-3">
                                <dt className="text-[#6b7280]">{t('الباقة')}</dt>
                                <dd className="font-medium text-[#111]">{t(subscription.plan)}</dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt className="text-[#6b7280]">{t('تاريخ البداية')}</dt>
                                <dd dir="ltr" className="font-medium text-[#111]">
                                    {subscription.start}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt className="text-[#6b7280]">{t('تاريخ الانتهاء')}</dt>
                                <dd dir="ltr" className="font-medium text-[#111]">
                                    {subscription.end}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt className="text-[#6b7280]">{t('المبلغ')}</dt>
                                <dd className="font-medium tabular-nums text-[#111]">
                                    {money(subscription.amount, currency)}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt className="text-[#6b7280]">{t('حالة الدفع')}</dt>
                                <dd>
                                    <Badge status={subscription.payment} />
                                </dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt className="text-[#6b7280]">{t('حالة الاشتراك')}</dt>
                                <dd>
                                    <Badge status={renewal.status} />
                                </dd>
                            </div>
                            {renewal.pending && (
                                <div className="flex justify-between gap-3">
                                    <dt className="text-[#6b7280]">{t('التجديد')}</dt>
                                    <dd>
                                        <Badge status="بانتظار السداد" />
                                    </dd>
                                </div>
                            )}
                        </dl>
                    ) : (
                        <p className="py-6 text-center text-sm text-[#9ca3af]">{t('لا يوجد اشتراك')}</p>
                    )}
                </Card>
            </div>

            <Card className="overflow-hidden">
                {/* داخل بطاقة: الحشو يُبعد التبويبات عن حدّها */}
                <Tabs tabs={TABS} current={tab} onChange={setTab} className="px-4" />

                {tab === 'addons' && <AddonsPanel businessId={business.id} data={addons} currency={currency} />}

                {tab === 'overview' && (
                    <div className="p-5">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            {[
                                { label: 'إجمالي المبيعات', value: overview.sales },
                                { label: 'عدد الطلبات', value: number(overview.orders) },
                                { label: 'متوسط قيمة الطلب', value: overview.average },
                            ].map((s) => (
                                <div key={s.label} className="rounded-[12px] bg-[#fafafa] p-4">
                                    <p className="text-sm text-[#6b7280]">{t(s.label)}</p>
                                    <p className="mt-1 text-[18px] font-bold tabular-nums text-[#111]">{s.value}</p>
                                </div>
                            ))}
                        </div>
                        <p className="mt-4 text-sm leading-relaxed text-[#6b7280]">
                            {t('مسجّلة منذ')} {business.registered} · {t(business.type)}
                            {business.city && ` · ${business.city}`} · {number(business.branches)} {t('فرعًا')} ·{' '}
                            {t('باقة')} {t(business.plan)}
                        </p>

                        {/*
                            الاستهلاك مقابل السقف — من بلغ حدّه هو المرشَّح
                            للترقية، ولا سبيل لمعرفته إن لم يُعرض.
                        */}
                        <div className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
                            {usage.map((u) => {
                                const full = u.cap !== null && u.used >= u.cap;
                                const pct = u.cap ? Math.min(100, (u.used / u.cap) * 100) : 0;

                                return (
                                    <div
                                        key={u.key}
                                        className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-4"
                                    >
                                        <div className="flex items-baseline justify-between gap-2">
                                            <span className="text-sm text-[#6b7280]">{u.label}</span>
                                            <span
                                                className={
                                                    'tabular-nums text-sm font-semibold ' +
                                                    (full ? 'text-[#b91c1c]' : 'text-[#111]')
                                                }
                                            >
                                                {number(u.used)}
                                                {u.cap !== null && ` / ${number(u.cap)}`}
                                            </span>
                                        </div>
                                        {u.cap !== null ? (
                                            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-[#f2f2f0]">
                                                <div
                                                    className={
                                                        'h-full rounded-full ' +
                                                        (full ? 'bg-[#b91c1c]' : 'bg-[#111]')
                                                    }
                                                    style={{ width: `${pct}%` }}
                                                />
                                            </div>
                                        ) : (
                                            <p className="mt-2 text-[12px] text-[#9ca3af]">{t('بلا سقف')}</p>
                                        )}
                                    </div>
                                );
                            })}
                        </div>

                        <RenewalPanel businessId={business.id} renewal={renewal} currency={currency} />
                    </div>
                )}

                {tab === 'branches' && (
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                {['الفرع', 'الهاتف', 'العنوان', 'عدد الموظفين'].map((h) => (
                                    <TableHead key={h}>{t(h)}</TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {branches.length === 0 ? (
                                <TableEmpty colSpan={4}>{t('لا توجد فروع مسجلة لهذه الشركة')}</TableEmpty>
                            ) : (
                                branches.map((b) => (
                                    <TableRow key={b.id}>
                                        <TableCell className="font-medium text-[#111]">{b.name}</TableCell>
                                        <TableCell dir="ltr" className="text-[#6b7280]">
                                            {b.phone || '—'}
                                        </TableCell>
                                        <TableCell className="text-[#4b4b4b]">{b.address || '—'}</TableCell>
                                        <TableCell className="tabular-nums text-[#4b4b4b]">
                                            {number(b.employees)}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                )}

                {tab === 'orders' && (
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                {['رقم الطلب', 'العميل', 'العناصر', 'الإجمالي', 'الدفع', 'الحالة', 'التاريخ'].map(
                                    (h) => (
                                        <TableHead key={h}>{t(h)}</TableHead>
                                    ),
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {orders.length === 0 ? (
                                <TableEmpty colSpan={7}>{t('لا توجد طلبات لهذه الشركة بعد')}</TableEmpty>
                            ) : (
                                orders.map((o) => (
                                    <TableRow key={o.id}>
                                        <TableCell className="font-medium text-[#111]">{o.id}</TableCell>
                                        <TableCell className="text-[#4b4b4b]">{o.customer}</TableCell>
                                        <TableCell className="tabular-nums text-[#4b4b4b]">
                                            {number(o.items_count)}
                                        </TableCell>
                                        <TableCell className="font-medium tabular-nums text-[#111]">
                                            {money(o.total, currency)}
                                        </TableCell>
                                        <TableCell className="text-[#4b4b4b]">{t(o.payment)}</TableCell>
                                        <TableCell>
                                            <Badge status={o.status} />
                                        </TableCell>
                                        <TableCell dir="ltr" className="text-[#6b7280]">
                                            {o.date}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                )}

            </Card>

            {/* مستنداتُ الشركة أسفلَ ملفّها — بلا تبويبٍ يُضغط، قبل منطقة الخطر وحدها */}
            <BusinessDocuments documents={documents} />

            {purge && <DangerZone name={business.name} id={business.id} run={purgeRun} />}

        </PlatformLayout>
    );
}


/**
 * منطقةُ الخطر — الحذفُ النهائيّ وحدَه فيها.
 *
 * ═══ ولمَ بطاقةٌ في أسفل الصفحة لا زرٌّ في رأسها ═══
 *
 * زرُّ «تعطيل» في الرأس بين «تعديل» و«دخول كتاجر» — أفعالٌ يوميّةٌ تُضغط
 * بلا تردّد. ووضعُ فعلٍ لا رجعةَ فيه بينها يجعله يُضغط بالعادة. فمكانُه
 * أسفلَ الصفحة، خلف عنوانٍ يقول ما هو، وبإطارٍ أحمر.
 *
 * والتأكيدُ كتابةُ الاسم كاملًا: «هل أنت متأكّد؟» تُجاب بـ«نعم» بلا قراءة،
 * وكتابةُ الاسم لا تقع إلا بعد أن تُقرأ الشاشة. والخادمُ يقارنه ثانيةً —
 * فهذا الحقلُ راحةٌ لمن يضغط لا حراسةٌ للبيانات.
 */
/** حالُ محاولةِ حذفٍ — كما يقرؤها الخادمُ من صفّها */
export interface PurgeRunState {
    status: 'pending' | 'running' | 'done' | 'failed';
    stage: string;
    label?: string;
    error?: string | null;
    done?: boolean;
}

const ACTIVE = ['pending', 'running'];

export function DangerZone({ name, id, run }: { name: string; id: number; run: PurgeRunState | null }) {
    const t = useTranslate();
    const [open, setOpen] = useState(false);
    const [typed, setTyped] = useState('');
    const [busy, setBusy] = useState(false);
    const [state, setState] = useState<PurgeRunState | null>(run);

    const tidy = (v: string) => v.replace(/\s+/g, ' ').trim();
    const matches = tidy(typed) === tidy(name);
    const running = state !== null && ACTIVE.includes(state.status);

    /*
     * والحالُ تُسأل كلَّ ثلاثِ ثوانٍ ما دامت تعمل.
     *
     * والسؤالُ يتوقّف حين تنتهي: طلبٌ كلَّ ثلاثِ ثوانٍ إلى الأبد على شاشةٍ
     * تُركت مفتوحةً حملٌ بلا فائدة. ومتى تمّ المحوُ لم تبقَ الصفحةُ صالحة
     * — الشركةُ التي تعرضها لم تعد موجودة — فيُنتقل إلى القائمة.
     */
    useEffect(() => {
        if (! running) return;

        const tick = async () => {
            try {
                const res = await fetch(route('super-admin.businesses.purgeStatus', id), {
                    headers: { Accept: 'application/json' },
                });

                if (! res.ok) return;

                const next: PurgeRunState = await res.json();
                setState(next);

                if (next.done) {
                    router.visit(route('super-admin.businesses.index'));
                }
            } catch {
                /* انقطاعُ شبكةٍ لحظيّ لا يُبطل المتابعة — تُسأل في الدورة التالية */
            }
        };

        const timer = window.setInterval(tick, 3000);
        void tick();

        return () => window.clearInterval(timer);
    }, [running, id]);

    const submit = () => {
        if (! matches || busy) return;

        setBusy(true);
        router.delete(route('super-admin.businesses.purge', id), {
            data: { confirm: typed },
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                setTyped('');
                setState({ status: 'pending', stage: 'queued' });
            },
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Card className="mt-6 border-[#fecaca] bg-[#fffafa] p-6">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="min-w-0">
                    <h2 className="flex items-center gap-2 text-[15px] font-bold text-[#b91c1c]">
                        <AlertTriangle className="size-4" />
                        {t('منطقة الخطر')}
                    </h2>
                    <p className="mt-1 max-w-xl text-[13px] leading-relaxed text-[#7f1d1d]">
                        {t('يمحو هذا الإجراء الشركة وكل بياناتها ومستخدميها وملفاتها. تُؤرشف دفاترها المحاسبية وتُنسخ مشفَّرةً إلى تخزين مستقل، ويُتحقق من استعادتها قبل المحو.')}
                    </p>
                    <Link
                        href={route('super-admin.businesses.purges')}
                        className="mt-2 inline-flex items-center gap-1.5 text-[12.5px] font-semibold text-[#b91c1c] underline-offset-4 hover:underline"
                    >
                        <Archive className="size-3.5" />
                        {t('أرشيفات الحذف النهائي')}
                    </Link>
                </div>

                <Button variant="danger" onClick={() => setOpen(true)} disabled={running}>
                    <Trash2 />
                    {t('حذف الشركة نهائيًا')}
                </Button>
            </div>

            {state !== null && (
                <div
                    className={cn(
                        'mt-4 flex items-start gap-2 rounded-[10px] border p-3 text-[12.5px] leading-relaxed',
                        state.status === 'failed'
                            ? 'border-[#fecaca] bg-white text-[#b91c1c]'
                            : 'border-[#fed7aa] bg-white text-[#9a3412]',
                    )}
                    role="status"
                >
                    {running ? <Loader2 className="mt-0.5 size-4 shrink-0 animate-spin" /> : <AlertTriangle className="mt-0.5 size-4 shrink-0" />}
                    <span className="min-w-0">
                        <span className="font-bold">{state.label ?? t('الحذف النهائي')}</span>
                        {state.error && <span className="block text-[#7f1d1d]">{state.error}</span>}
                    </span>
                </div>
            )}

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="text-[#b91c1c]">{t('حذف الشركة نهائيًا')}</DialogTitle>
                    </DialogHeader>

                    <div className="space-y-3 px-5 pb-1">
                        <p className="text-[13.5px] leading-relaxed text-[#4b4b4b]">
                            {t('سيُحذف كل ما يخص «:name»: المنتجات والطلبات والعملاء والمخزون والمستخدمون والملفات. لا يمكن التراجع عن هذا الإجراء.', { name })}
                        </p>
                        <p className="text-[13px] leading-relaxed text-[#7f1d1d]">
                            {t('تُؤرشف السجلات المحاسبية والمالية، وتُنسخ مشفَّرةً إلى تخزين مستقل، وتُستعاد للتحقق — ولا يُمحى شيء قبل نجاح ذلك.')}
                        </p>

                        <label className="block">
                            <span className="mb-1.5 block text-[12.5px] text-[#4b4b4b]">
                                {t('اكتب اسم الشركة للتأكيد:')} <span className="font-bold text-[#111]">{name}</span>
                            </span>
                            <Input
                                value={typed}
                                onChange={(e) => setTyped(e.target.value)}
                                placeholder={name}
                                aria-label={t('اسم الشركة للتأكيد')}
                                autoComplete="off"
                            />
                        </label>
                    </div>

                    <div className="mt-4 flex justify-end gap-2 px-5 pb-5">
                        <Button variant="outline" onClick={() => setOpen(false)}>
                            {t('إلغاء')}
                        </Button>
                        <Button variant="danger" disabled={! matches || busy} onClick={submit}>
                            <Trash2 />
                            {t('احذف نهائيًا')}
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </Card>
    );
}

/**
 * التجديدُ في لوحة المنصّة: فاتورةٌ تُصدَر أوّلًا، والمدّةُ تبدأ يوم يُسجَّل سدادُها.
 *
 * ما دامت فاتورةُ تجديدٍ تنتظر تُطفأ أزرارُ الإصدار (تجديدٌ معلّقٌ واحد للمتجر —
 * `Billing::renew`)، ويُقال أيُّ فاتورةٍ تنتظر وبكم. والخادمُ يرفض الثانيةَ على
 * أيّ حال؛ إطفاءُ الزرّ راحةٌ لا حراسة.
 */
/**
 * ترقيةُ المتجر في حسابه نفسِه — تجربةٌ إلى اشتراكٍ مدفوع، أو باقةٌ إلى أخرى.
 *
 * تصدر فاتورةُ الباقة الجديدة بسعرها في سياسة المتجر، ويبقى المتجرُ على باقته حتى
 * يُسجَّل سدادُها؛ ثمّ ينتقل ببياناته كلّها، ودورتُه من يوم السداد (`Billing::upgrade`).
 */
export function UpgradeForm({ businessId, upgrades, disabled, currency }: { businessId: number; upgrades: NonNullable<Renewal['upgrades']>; disabled: boolean; currency: Currency }) {
    const t = useTranslate();
    const [planId, setPlanId] = useState('');
    const [cycle, setCycle] = useState<'monthly' | 'semiannual' | 'yearly'>('monthly');
    const [asking, setAsking] = useState(false);
    const plan = upgrades.find((p) => String(p.id) === planId);
    const price = plan?.prices ? plan.prices[cycle] : null;

    return (
        <div className="mt-3 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-4" data-testid="upgrade-form">
            <p className="text-[13px] font-bold text-[#111]">{t('ترقية الباقة في الحساب نفسه')}</p>
            <p className="mt-0.5 text-[12px] text-[#6b7280]">{t('تصدر فاتورة الباقة الجديدة، ويبقى المتجر على باقته وبياناته حتى يُسجَّل سدادها.')}</p>
            <div className="mt-3 flex flex-wrap items-end gap-3">
                <Field label="الباقة الجديدة">
                    <Select
                        value={planId}
                        placeholder="اختر"
                        onChange={(e) => { setPlanId(e.target.value); setAsking(false); }}
                        options={upgrades.map((p) => ({ value: String(p.id), label: p.name }))}
                    />
                </Field>
                <Field label="المدة">
                    <Select
                        value={cycle}
                        onChange={(e) => { setCycle(e.target.value as typeof cycle); setAsking(false); }}
                        options={[
                            { value: 'monthly', label: 'شهري' },
                            { value: 'semiannual', label: 'نصف سنوي' },
                            { value: 'yearly', label: 'سنوي' },
                        ]}
                    />
                </Field>
                {!asking ? (
                    <Button variant="outline" disabled={disabled || !plan || price === null} onClick={() => setAsking(true)} data-testid="upgrade-ask">
                        {t('إصدار فاتورة الترقية')}
                    </Button>
                ) : (
                    <Button
                        data-testid="upgrade-confirm"
                        onClick={() => router.post(route('super-admin.businesses.upgrade', businessId), { plan_id: planId, cycle }, { preserveScroll: true, onFinish: () => setAsking(false) })}
                    >
                        {t('تأكيد — :p بمبلغ :a', { p: plan?.name ?? '', a: money(price ?? 0, currency) })}
                    </Button>
                )}
            </div>
            {plan && price === null && <p className="mt-2 text-[12px] text-[#b45309]">{t('سعر هذه الباقة في سياسة المتجر لم يُعتمد بعد.')}</p>}
            {disabled && <p className="mt-2 text-[12px] text-[#6b7280]">{t('للمتجر فاتورة اشتراك بانتظار السداد — تُسدَّد أو تُلغى أوّلًا.')}</p>}
        </div>
    );
}

export function RenewalPanel({ businessId, renewal, currency }: { businessId: number; renewal: Renewal; currency: Currency }) {
    const t = useTranslate();

    return (
        <>
            {/* التجديد: فاتورةٌ تُصدَر أوّلًا، والمدّةُ تبدأ يوم يُسجَّل سدادُها */}
            <div className="mt-6 flex flex-wrap items-center gap-3 rounded-[12px] bg-[#fafafa] p-4">
                <span className="text-sm text-[#4b4b4b]">
                    {renewal.endsAt
                        ? `${t('ينتهي في')} ${renewal.endsAt}`
                        : t('لا تاريخ انتهاء محدَّد')}
                </span>
                <span className="flex-1" />
                {/*
                    يُستأذن قبل الضغط: الضغطةُ تُصدر فاتورة. ولا يُمدَّد
                    شيءٌ قبل سدادها، وتجديدٌ معلّقٌ واحد للمتجر — فالأزرارُ
                    تُطفأ ما دامت فاتورةٌ تنتظر.
                */}
                {(
                    [
                        ['monthly', 'جدّد شهرًا', renewal.monthly],
                        ['semiannual', 'جدّد ستّة أشهر', renewal.semiannual],
                        ['yearly', 'جدّد سنة', renewal.yearly],
                    ] as const
                ).map(([cycle, label, price]) => (
                    <Button
                        key={cycle}
                        variant={cycle === 'yearly' ? undefined : 'outline'}
                        disabled={renewal.pending !== null}
                        onClick={() => {
                            if (
                                !confirm(
                                    `${t('تصدر فاتورة تجديد')} ${t(CYCLE_LABEL[cycle])} ${t('بمبلغ')} ${money(price, currency)}. ${t('لن يتم تمديد الاشتراك إلا بعد تسجيل السداد.')}`,
                                )
                            )
                                return;
                            router.post(route('super-admin.businesses.renew', businessId), { cycle });
                        }}
                    >
                        {t(label)} · {money(price, currency)}
                    </Button>
                ))}
            </div>

            {(renewal.upgrades?.length ?? 0) > 0 && (
                <UpgradeForm businessId={businessId} upgrades={renewal.upgrades ?? []} disabled={renewal.pending !== null} currency={currency} />
            )}

            {renewal.pending && (
                <div className="mt-3 flex flex-wrap items-center gap-3 rounded-[12px] border border-[#fde68a] bg-[#fffbeb] p-4 text-sm text-[#92400e]">
                    <Badge status="بانتظار السداد" />
                    <span>
                        {renewal.pending.upgrade_to ? t('ترقية إلى «:p»', { p: renewal.pending.upgrade_to }) : t('تجديد')}{' '}
                        {t(CYCLE_LABEL[renewal.pending.cycle ?? 'monthly'] ?? 'شهر')} ·{' '}
                        <span className="font-mono">{renewal.pending.number}</span> ·{' '}
                        {money(renewal.pending.amount, currency)}
                    </span>
                    <span className="flex-1" />
                    <span>{t('لن يتم تمديد الاشتراك إلا بعد تسجيل السداد.')}</span>
                </div>
            )}

            {renewal.unpaid.length > 0 && (
                <div className="mt-3 rounded-[12px] bg-[#fafafa] p-4">
                    <h4 className="mb-2 text-sm font-semibold text-[#111]">{t('الفواتير غير المدفوعة')}</h4>
                    <ul className="space-y-2 text-sm">
                        {renewal.unpaid.map((i) => (
                            <li key={i.id} className="flex flex-wrap items-center gap-2">
                                <span className="font-mono text-[#111]">{i.number}</span>
                                <span dir="ltr" className="text-[#6b7280]">
                                    {i.date}
                                </span>
                                <span className="font-medium tabular-nums">{money(i.amount, currency)}</span>
                                <span className="flex-1" />
                                <Button
                                    size="sm"
                                    onClick={() => {
                                        if (!confirm(`${t('تسجيل سداد')} ${i.number}${t('؟')}`)) return;
                                        router.post(route('super-admin.invoices.pay', i.id));
                                    }}
                                >
                                    {t('تسجيل السداد')}
                                </Button>
                                {renewal.pending?.invoice_id === i.id && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => {
                                            if (!confirm(`${t('إلغاء فاتورة التجديد')} ${i.number}${t('؟')}`)) return;
                                            router.post(route('super-admin.invoices.cancel', i.id));
                                        }}
                                    >
                                        {t('إلغاء الفاتورة')}
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </>
    );
}

export { BusinessDocuments };
