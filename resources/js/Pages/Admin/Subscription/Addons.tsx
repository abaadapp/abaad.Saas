import { type FormEvent, type ReactNode, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import {
    Award,
    Building2,
    CheckCircle2,
    Gift,
    Globe,
    Headset,
    type LucideIcon,
    MessageCircle,
    ScanBarcode,
    ShieldCheck,
    ShoppingCart,
    Smartphone,
    Sparkles,
    TicketPercent,
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { Currency, PageProps } from '@/types';

interface MerchantAddon {
    code: string;
    capability?: string | null;
    name: string;
    description: string | null;
    pricing: 'paid' | 'free' | 'quote';
    unit: 'store' | 'branch' | 'usage';
    counted: boolean;
    monthly_price: number | null;
    yearly_price: number | null;
    active?: boolean;
    pending?: boolean;
}

/** خدمةٌ في كتالوج السياسة السابقة — تعريفٌ بلا سعرٍ ولا رمز (`AddonViews::showcase`) */
interface Showcased {
    id: number;
    capability: string | null;
    name: string;
    description: string | null;
    state: 'included' | 'learn' | 'not_included';
    note: string | null;
}

interface Active {
    id: number;
    addon: { name: string; code: string };
    quantity: number;
    source: 'purchase' | 'grant';
    cycle: 'monthly' | 'yearly' | null;
    unit_price: number;
    starts_at: string | null;
    ends_at: string | null;
    renews: boolean;
}

interface Order {
    id: number;
    addon: { name: string; pricing: string };
    kind: 'new' | 'renewal';
    cycle: 'monthly' | 'yearly';
    quantity: number;
    amount: number | null;
    status: 'requested' | 'invoiced';
    invoice: { number: string } | null;
}

interface Props {
    policy: 'legacy' | 'addons_v1';
    plan: { name: string; price: { monthly: number; yearly: number } | null } | null;
    subscription?: { status: string | null; live: boolean; ends_at: string | null };
    showcase?: Showcased[];
    included: MerchantAddon[];
    available: MerchantAddon[];
    active: Active[];
    orders: Order[];
    addons_total: { monthly: number; yearly: number };
    may_request: boolean;
    mayBuy: boolean;
    currency: Currency;
}

const CYCLE: Record<string, string> = { monthly: 'شهري', yearly: 'سنوي' };

/** أيقونةُ الخدمة بما تفتحه — والخدمةُ بلا باب بأيقونةٍ عامّة */
const ICONS: Record<string, LucideIcon> = {
    pos: ScanBarcode,
    website: Globe,
    marketing_tools: TicketPercent,
    loyalty: Award,
    whatsapp: MessageCircle,
    gift_orders: Gift,
    whatsapp_own_number: Smartphone,
    branches: Building2,
};

const SHOWN: Record<Showcased['state'], { label: string; variant: 'success' | 'outline' | 'neutral' }> = {
    included: { label: 'متوفرة في اشتراكك', variant: 'success' },
    learn: { label: 'تعرّف على الخدمة', variant: 'outline' },
    not_included: { label: 'ليست ضمن باقتك الحالية', variant: 'neutral' },
};

const GRID = 'grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3';

/**
 * «طوّر متجرك مع أبعاد» — ما يشمله الاشتراك، وخدماتُ أبعاد، وما يُطلب ويسري.
 *
 * ═══ سياستان، وصفحةٌ واحدةُ الشكل ═══
 *
 *   - **السابقة**: كتالوجٌ تعريفيّ. لا سعرَ ولا زرَّ شراء — ولا يصل من الخادم
 *     ما يُشترى به أصلًا (`showcase` بلا رمزٍ ولا سعر)، والطلبُ يُردّ هناك.
 *   - **الإضافات**: كتالوجٌ تجاريّ بأسعاره وطلبه وتجديده.
 *
 * والأزرارُ ترسم ما يقبله الخادم ولا تقرّر شيئًا: الطلبُ يُرسل رمزَ الإضافة
 * ودورتَها وعددَها، والسعرُ والشمولُ والسياسةُ تُقرأ هناك.
 */
export default function SubscriptionAddons() {
    const props = usePage<PageProps<Props>>().props;
    const { policy, plan, subscription, showcase = [], included, available, active, orders, addons_total, may_request, mayBuy, currency } = props;
    const t = useTranslate();
    const [ask, confirmDialog] = useConfirm();
    const [buying, setBuying] = useState<MerchantAddon | null>(null);
    const legacy = policy !== 'addons_v1';

    const toggleRenews = async (row: Active) => {
        const next = !row.renews;
        const ok = await ask({
            message: next ? 'تتجدّد الإضافة بفاتورةٍ عند نهاية دورتها.' : 'تبقى الإضافة ساريةً حتى :d ثمّ تتوقّف — ولا يُحذف شيءٌ من بيانات متجرك.',
            values: { d: row.ends_at ?? '—' },
            action: next ? 'إعادة التجديد' : 'إيقاف التجديد',
            danger: !next,
        });
        if (ok) {
            router.put(route('admin.subscription.addons.renews', row.id), { renews: next }, { preserveScroll: true });
        }
    };

    const covered = legacy ? showcase.filter((s) => s.state === 'included').map((s) => ({ key: `s${s.id}`, name: s.name })) : included.map((a) => ({ key: a.code, name: a.name }));

    return (
        <AdminLayout title="إضافات متجرك">
            <PageHeader title="طوّر متجرك مع أبعاد" subtitle={t('اكتشف الخدمات والأدوات التي تساعدك على تطوير نشاطك، مع المحافظة على اشتراكك الحالي ومميزاته.')} />

            <div className="space-y-6">
                <Card className="p-5 sm:p-6" data-testid={legacy ? 'legacy-policy-note' : 'plan-summary'}>
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="flex min-w-0 items-start gap-3">
                            <span className="flex size-11 shrink-0 items-center justify-center rounded-[12px] bg-[#ecfdf5] text-[#047857]">
                                <ShieldCheck className="size-5" />
                            </span>
                            <div className="min-w-0">
                                <p className="text-[13px] text-[#6b7280]">{t(legacy ? 'اشتراكك الحالي محفوظ' : 'باقتك')}</p>
                                <p className="mt-0.5 text-[18px] font-bold text-[#111]" data-testid="current-plan">
                                    {plan?.name ?? '—'}
                                </p>
                                {subscription?.status && (
                                    <p className="mt-1 flex flex-wrap items-center gap-2 text-[12px] text-[#6b7280]" data-testid="subscription-status">
                                        <Badge variant={subscription.live ? 'success' : 'warning'}>{subscription.status}</Badge>
                                        {subscription.ends_at && <span className="tabular-nums">{t('حتى :d', { d: subscription.ends_at })}</span>}
                                    </p>
                                )}
                            </div>
                        </div>
                        {!legacy && (
                            <div className="text-[13px] text-[#4b4b4b] tabular-nums sm:text-end">
                                {plan?.price && (
                                    <p>
                                        {t('الباقة')}: {money(plan.price.monthly, currency)} {t('/ شهريًا')}
                                    </p>
                                )}
                                {(addons_total.monthly > 0 || addons_total.yearly > 0) && (
                                    <p data-testid="addons-total">
                                        {t('الإضافات السارية')}: {addons_total.monthly > 0 && `${money(addons_total.monthly, currency)} ${t('/ شهريًا')}`}
                                        {addons_total.monthly > 0 && addons_total.yearly > 0 && ' · '}
                                        {addons_total.yearly > 0 && `${money(addons_total.yearly, currency)} ${t('سنويًا')}`}
                                    </p>
                                )}
                            </div>
                        )}
                    </div>

                    {legacy && (
                        <p className="mt-4 text-[14px] leading-7 text-[#4b4b4b]">
                            {t('جميع خدمات باقتك وأسعار اشتراكك الحالية مستمرة دون تغيير. يمكنك الاطلاع على خدمات أبعاد الأخرى والتواصل معنا لمعرفة خيارات التطوير.')}
                        </p>
                    )}

                    {covered.length > 0 && (
                        <div className="mt-4 border-t border-[var(--ui-border,#e8e8e8)] pt-4">
                            <p className="mb-2 text-[13px] font-semibold text-[#111]">{t(legacy ? 'متوفرة في اشتراكك' : 'مشمولة في باقتك')}</p>
                            <div className="flex flex-wrap gap-2" data-testid="included-addons">
                                {covered.map((a) => (
                                    <Badge key={a.key} variant="success">
                                        <CheckCircle2 className="me-1 size-3.5" />
                                        {a.name}
                                    </Badge>
                                ))}
                            </div>
                        </div>
                    )}
                </Card>

                {!legacy && active.length > 0 && (
                    <section>
                        <h2 className="mb-2 text-[15px] font-bold text-[#111]">{t('إضافاتك السارية')}</h2>
                        <Card className="divide-y divide-[var(--ui-border,#e8e8e8)]" data-testid="active-addons">
                            {active.map((row) => (
                                <div key={row.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-[13px]">
                                    <div className="min-w-0">
                                        <p className="font-semibold text-[#111]">
                                            {row.addon.name}
                                            {row.quantity > 1 && <span className="tabular-nums"> × {row.quantity}</span>}
                                        </p>
                                        <p className="mt-0.5 text-[12px] text-[#6b7280]">
                                            {row.ends_at ? t('سارية حتى :d', { d: row.ends_at }) : t('بلا نهاية')}
                                            {row.source === 'grant' && ` · ${t('منحة من أبعاد')}`}
                                            {row.source === 'purchase' && row.cycle && ` · ${money(row.unit_price * row.quantity, currency)} ${t(CYCLE[row.cycle])}`}
                                            {row.source === 'purchase' && row.cycle && !row.renews && ` · ${t('التجديد موقوف')}`}
                                        </p>
                                    </div>
                                    {mayBuy && row.source === 'purchase' && row.cycle && (
                                        <Button size="sm" variant="outline" onClick={() => toggleRenews(row)}>
                                            {t(row.renews ? 'إيقاف التجديد' : 'إعادة التجديد')}
                                        </Button>
                                    )}
                                </div>
                            ))}
                        </Card>
                    </section>
                )}

                {!legacy && orders.length > 0 && (
                    <section>
                        <h2 className="mb-2 text-[15px] font-bold text-[#111]">{t('طلباتك')}</h2>
                        <Card className="divide-y divide-[var(--ui-border,#e8e8e8)]" data-testid="addon-requests">
                            {orders.map((o) => (
                                <div key={o.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-[13px]">
                                    <p className="font-semibold text-[#111]">
                                        {o.addon.name}
                                        {o.quantity > 1 && <span className="tabular-nums"> × {o.quantity}</span>}
                                        {o.kind === 'renewal' && <span className="ms-1 font-normal text-[#6b7280]">({t('تجديد')})</span>}
                                    </p>
                                    <span className="text-[12px] text-[#6b7280]">
                                        {o.status === 'requested'
                                            ? o.amount === null
                                                ? t('بانتظار عرض السعر من أبعاد')
                                                : t('بانتظار إصدار الفاتورة')
                                            : t('فاتورة :n بانتظار السداد — :a', { n: o.invoice?.number ?? '—', a: money(o.amount, currency) })}
                                    </span>
                                </div>
                            ))}
                        </Card>
                    </section>
                )}

                <section>
                    <h2 className="mb-1 text-[15px] font-bold text-[#111]">{t(legacy ? 'خدمات أبعاد' : 'إضافات متاحة لباقتك')}</h2>
                    {legacy && <p className="mb-3 text-[13px] text-[#6b7280]">{t('تعرّف على ما تقدّمه أبعاد لمتجرك — والاستفسار لا يغيّر اشتراكك.')}</p>}

                    {legacy ? (
                        showcase.length === 0 ? (
                            <Card className="px-5 py-10 text-center text-[13px] text-[#6b7280]" data-testid="showcase-empty">
                                {t('لا خدمات معروضة الآن.')}
                            </Card>
                        ) : (
                            <div className={GRID} data-testid="services-showcase">
                                {showcase.map((s) => (
                                    <ServiceCard key={s.id} capability={s.capability} name={s.name} description={s.description} testId="service-card">
                                        {s.note && <p className="text-[12px] text-[#6b7280]">{s.note}</p>}
                                        <div className="mt-auto pt-1">
                                            <Badge variant={SHOWN[s.state].variant} data-testid="service-state" data-state={s.state}>
                                                {s.state === 'included' && <CheckCircle2 className="me-1 size-3.5" />}
                                                {t(SHOWN[s.state].label)}
                                            </Badge>
                                        </div>
                                    </ServiceCard>
                                ))}
                            </div>
                        )
                    ) : available.length === 0 ? (
                        <Card className="px-5 py-10 text-center text-[13px] text-[#6b7280]">{t('لا إضافات أخرى لباقتك الآن.')}</Card>
                    ) : (
                        <div className={GRID} data-testid="available-addons">
                            {available.map((a) => (
                                <ServiceCard key={a.code} capability={a.capability ?? null} name={a.name} description={a.description}>
                                    <p className="text-[13px] tabular-nums text-[#111]">
                                        {a.pricing === 'quote'
                                            ? t('حسب الطلب')
                                            : a.pricing === 'free'
                                              ? t('مجانية')
                                              : `${money(a.monthly_price, currency)} ${t('/ شهريًا')} · ${money(a.yearly_price, currency)} ${t('سنويًا')}`}
                                        {a.counted && <span className="text-[#6b7280]"> · {t(a.unit === 'branch' ? 'لكل فرع' : 'لكل وحدة')}</span>}
                                    </p>
                                    <div className="mt-auto flex justify-end pt-1">
                                        {a.active ? (
                                            <Badge variant="success">{t('مفعّلة')}</Badge>
                                        ) : a.pending ? (
                                            <Badge variant="warning">{t('طلبك قيد المعالجة')}</Badge>
                                        ) : may_request && mayBuy ? (
                                            <Button size="sm" onClick={() => setBuying(a)} data-testid={`request-${a.code}`}>
                                                <ShoppingCart />
                                                {t(a.pricing === 'quote' ? 'اطلب عرض سعر' : 'اطلب الإضافة')}
                                            </Button>
                                        ) : (
                                            <span className="text-[12px] text-[#9ca3af]">{t('يطلبها صاحب النشاط')}</span>
                                        )}
                                    </div>
                                </ServiceCard>
                            ))}
                        </div>
                    )}
                </section>

                <Card className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6" data-testid="contact-abaad">
                    <div className="flex min-w-0 items-start gap-3">
                        <span className="flex size-11 shrink-0 items-center justify-center rounded-[12px] bg-[#f5f3ff] text-[#6d28d9]">
                            <Headset className="size-5" />
                        </span>
                        <div className="min-w-0">
                            <p className="text-[15px] font-bold text-[#111]">{t('تحتاج خدمة إضافية لمتجرك؟')}</p>
                            <p className="mt-0.5 text-[13px] text-[#6b7280]">{t('فريق أبعاد يساعدك في معرفة خيارات التطوير المناسبة لنشاطك التجاري.')}</p>
                        </div>
                    </div>
                    <Button asChild className="w-full sm:w-auto">
                        <SmartLink routeName="admin.help.index" href={route('admin.help.index', { topic: 'services' })} data-testid="contact-abaad-button">
                            {t('تواصل مع أبعاد')}
                        </SmartLink>
                    </Button>
                </Card>
            </div>

            {buying && <RequestDialog addon={buying} currency={currency} onClose={() => setBuying(null)} />}
            {confirmDialog}
        </AdminLayout>
    );
}

/** بطاقةُ خدمة — أيقونتُها واسمُها ووصفُها، وما تحتها لكلّ سياسةٍ ما يخصّها */
function ServiceCard({ capability, name, description, testId, children }: { capability: string | null; name: string; description: string | null; testId?: string; children: ReactNode }) {
    const Icon = (capability && ICONS[capability]) || Sparkles;

    return (
        <Card className="flex h-full min-w-0 flex-col gap-3 p-5" data-testid={testId}>
            <div className="flex items-start gap-3">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-[10px] bg-[#f2f2f0] text-[#4b4b4b]">
                    <Icon className="size-5" />
                </span>
                <div className="min-w-0">
                    <p className="font-bold leading-6 break-words text-[#111]">{name}</p>
                    {description && <p className="mt-1 text-[13px] leading-6 text-[#6b7280]">{description}</p>}
                </div>
            </div>
            {children}
        </Card>
    );
}

function RequestDialog({ addon, currency, onClose }: { addon: MerchantAddon; currency: Currency; onClose: () => void }) {
    const t = useTranslate();
    const form = useForm({ code: addon.code, cycle: 'monthly', quantity: '1' });
    const unit = form.data.cycle === 'yearly' ? addon.yearly_price : addon.monthly_price;
    const qty = addon.counted ? Math.max(1, Number(form.data.quantity) || 1) : 1;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('admin.subscription.addons.request'), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{addon.name}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4 px-5 pb-5" data-testid="addon-request-form">
                    <Field label="الدورة" error={form.errors.cycle}>
                        <Select
                            value={form.data.cycle}
                            onChange={(e) => form.setData('cycle', e.target.value)}
                            options={[
                                { value: 'monthly', label: 'شهري' },
                                { value: 'yearly', label: 'سنوي' },
                            ]}
                        />
                    </Field>
                    {addon.counted && (
                        <Field label={addon.unit === 'branch' ? 'عدد الفروع' : 'العدد'} error={form.errors.quantity}>
                            <Input type="number" min="1" max="50" dir="ltr" value={form.data.quantity} onChange={(e) => form.setData('quantity', e.target.value)} />
                        </Field>
                    )}
                    {form.errors.code && <p className="text-[12px] text-[#b91c1c]">{form.errors.code}</p>}
                    <p className="text-[13px] text-[#4b4b4b]" data-testid="request-summary">
                        {addon.pricing === 'quote'
                            ? t('تُرسل أبعاد عرض سعر — ولا تُفعَّل الإضافة قبل اعتماده وسداده.')
                            : addon.pricing === 'free'
                              ? t('مجانية — تُفعَّل فور الطلب.')
                              : t('المبلغ :a — تصلك فاتورته، وتُفعَّل الإضافة فور تسجيل السداد.', { a: money((unit ?? 0) * qty, currency) })}
                    </p>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            {t('إرسال الطلب')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
