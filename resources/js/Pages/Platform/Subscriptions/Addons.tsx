import { type FormEvent, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Check, CheckCircle2, CircleAlert, Package, Pencil, Plus, Power, ShieldAlert, SlidersHorizontal } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import Tabs from '@/Components/Tabs';
import Field, { Select } from '@/Components/Field';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input, Textarea } from '@/Components/ui/input';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { Currency, PageProps } from '@/types';

export interface CatalogAddon {
    id: number;
    code: string;
    name: string;
    name_en: string | null;
    description: string | null;
    description_en: string | null;
    capability: string | null;
    pricing: 'paid' | 'free' | 'quote';
    unit: 'store' | 'branch' | 'usage';
    monthly_price: number | null;
    yearly_price: number | null;
    for_sale: boolean;
    for_new_merchants: boolean;
    sort: number;
    /** معرّفُ الباقة ← `eligible` تُشترى فيها، `included` مشمولة بها */
    plans: Record<string, 'eligible' | 'included'>;
    subscribers: number;
}

export interface PlanRow {
    id: number;
    name: string;
    code: string | null;
    legacy: { monthly: number; yearly: number };
    /** سعرُها في سياسة الإضافات — `null` لم يُعتمد */
    addons: { monthly: number; yearly: number } | null;
    capabilities_set: boolean;
    businesses: { legacy: number; addons: number };
}

export interface OrderRow {
    id: number;
    addon: { id: number; name: string; pricing: string };
    kind: 'new' | 'renewal';
    cycle: 'monthly' | 'semiannual' | 'yearly';
    quantity: number;
    unit_price: number | null;
    amount: number | null;
    status: 'requested' | 'invoiced' | 'paid' | 'cancelled';
    invoice: { id: number; number: string; paid: boolean } | null;
    requested_by: string | null;
    created_at: string | null;
    business?: { id: number; name: string };
}

export interface EntitlementRow {
    id: number;
    addon: { id: number; name: string; code: string };
    quantity: number;
    source: 'purchase' | 'grant';
    cycle: 'monthly' | 'semiannual' | 'yearly' | null;
    unit_price: number;
    starts_at: string | null;
    ends_at: string | null;
    renews: boolean;
    cancelled_at: string | null;
    active: boolean;
    reason?: string | null;
    business?: { id: number; name: string };
}

/** شرطٌ من شروط التفعيل — `PricingPolicy::checks` */
export interface LaunchCheck {
    key: string;
    label: string;
    ok: boolean;
    problems: string[];
}

interface Props {
    catalog: CatalogAddon[];
    plans: PlanRow[];
    orders: OrderRow[];
    subscribers: EntitlementRow[];
    launch: { enabled: boolean; checks?: LaunchCheck[]; blockers: string[]; counts: { legacy: number; addons: number } };
    capabilityOptions: { key: string; label: string }[];
    currency: Currency;
}

export const ORDER_STATUS: Record<OrderRow['status'], string> = {
    requested: 'بانتظار الاعتماد',
    invoiced: 'بانتظار السداد',
    paid: 'مدفوع',
    cancelled: 'ملغى',
};

export const CYCLE: Record<string, string> = { monthly: 'شهري', semiannual: 'نصف سنوي', yearly: 'سنوي' };

/** ستّةُ أشهرٍ بسعر الشهر ستَّ مرّات — بلا عمودٍ ولا خصم، كالباقة (`Catalog::unitPrice`) */
export const halfYear = (monthly: number | null | undefined) => (monthly == null ? null : Math.round(monthly * 6 * 1000) / 1000);

const UNIT: Record<string, string> = { store: 'للمتجر', branch: 'لكل فرع', usage: 'لكل وحدة استخدام' };

export function priceText(a: Pick<CatalogAddon, 'pricing' | 'monthly_price' | 'yearly_price'>, currency: Currency, t: (s: string) => string) {
    if (a.pricing === 'quote') return t('حسب الطلب');
    if (a.pricing === 'free') return t('مجانية');

    return `${money(a.monthly_price, currency)} ${t('/ شهريًا')} · ${money(halfYear(a.monthly_price), currency)} ${t('نصف سنويًا')} · ${money(a.yearly_price, currency)} ${t('سنويًا')}`;
}

export default function Addons() {
    const { catalog, plans, orders, subscribers, launch, capabilityOptions, currency } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [tab, setTab] = useState('catalog');
    const [editing, setEditing] = useState<CatalogAddon | 'new' | null>(null);
    const [placing, setPlacing] = useState<CatalogAddon | null>(null);

    return (
        <PlatformLayout title="إضافات الاشتراكات">
            <PageHeader
                title="إضافات الاشتراكات"
                subtitle={t('خدماتٌ تُشترى فوق الباقة لمتاجر نظام الإضافات — والمتاجر القائمة على اشتراكها كما هو')}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <SmartLink routeName="super-admin.subscriptions.plans" href={route('super-admin.subscriptions.plans')}>
                                {t('الباقات')}
                            </SmartLink>
                        </Button>
                        <Button onClick={() => setEditing('new')}>
                            <Plus />
                            {t('إضافة جديدة')}
                        </Button>
                    </>
                }
            />

            <Tabs
                className="mb-5"
                current={tab}
                onChange={setTab}
                tabs={[
                    { key: 'catalog', label: 'كتالوج الإضافات', count: catalog.length },
                    { key: 'orders', label: 'طلبات الإضافات', count: orders.length, alert: orders.some((o) => o.status === 'requested') },
                    { key: 'subscribers', label: 'المشتركون', count: subscribers.length },
                    { key: 'settings', label: 'التسعير والإعدادات' },
                ]}
            />

            {tab === 'catalog' && (
                <CatalogTab
                    catalog={catalog}
                    plans={plans}
                    capabilityOptions={capabilityOptions}
                    currency={currency}
                    onEdit={setEditing}
                    onPlace={setPlacing}
                />
            )}
            {tab === 'orders' && <OrdersTab orders={orders} currency={currency} />}
            {tab === 'subscribers' && <SubscribersTab rows={subscribers} currency={currency} />}
            {tab === 'settings' && <SettingsTab launch={launch} plans={plans} currency={currency} />}

            {editing && (
                <AddonDialog
                    addon={editing === 'new' ? null : editing}
                    capabilityOptions={capabilityOptions}
                    currency={currency}
                    onClose={() => setEditing(null)}
                />
            )}
            {placing && <PlacesDialog addon={placing} plans={plans} onClose={() => setPlacing(null)} />}
        </PlatformLayout>
    );
}

function CatalogTab({
    catalog,
    plans,
    capabilityOptions,
    currency,
    onEdit,
    onPlace,
}: {
    catalog: CatalogAddon[];
    plans: PlanRow[];
    capabilityOptions: Props['capabilityOptions'];
    currency: Currency;
    onEdit: (a: CatalogAddon) => void;
    onPlace: (a: CatalogAddon) => void;
}) {
    const t = useTranslate();
    const label = (key: string | null) => capabilityOptions.find((o) => o.key === key)?.label ?? t('خدمة تقدّمها أبعاد');
    const planName = (id: string) => plans.find((p) => String(p.id) === id)?.name ?? '—';

    if (catalog.length === 0) {
        return (
            <Card className="px-5 py-16 text-center">
                <Package className="mx-auto size-8 text-[#d1d5db]" />
                <p className="mt-3 font-medium text-[#111]">{t('لا إضافات بعد')}</p>
            </Card>
        );
    }

    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3" data-testid="addons-catalog">
            {catalog.map((a) => (
                <Card key={a.id} className="flex flex-col gap-3 p-5" data-testid="addon-card">
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <h3 className="text-[15px] font-bold break-words text-[#111]">{a.name}</h3>
                            {a.name_en && (
                                <p className="mt-0.5 text-[12px] text-[#9ca3af]" dir="ltr">
                                    {a.name_en}
                                </p>
                            )}
                        </div>
                        <div className="flex shrink-0 flex-wrap justify-end gap-1">
                            <Badge variant={a.for_sale ? 'success' : 'neutral'}>{t(a.for_sale ? 'تُباع' : 'موقوف بيعها')}</Badge>
                            {!a.for_new_merchants && <Badge variant="warning">{t('مغلقة للمتاجر الجديدة')}</Badge>}
                        </div>
                    </div>
                    <dl className="grid grid-cols-2 gap-x-3 gap-y-1 text-[13px]">
                        <dt className="text-[#9ca3af]">{t('السعر')}</dt>
                        <dd className="tabular-nums text-[#111]">{priceText(a, currency, t)}</dd>
                        <dt className="text-[#9ca3af]">{t('ما تفتحه')}</dt>
                        <dd className="text-[#111]">{t(label(a.capability))}</dd>
                        <dt className="text-[#9ca3af]">{t('الوحدة')}</dt>
                        <dd className="text-[#111]">{t(UNIT[a.unit])}</dd>
                        <dt className="text-[#9ca3af]">{t('المشتركون')}</dt>
                        <dd className="tabular-nums text-[#111]">{a.subscribers}</dd>
                    </dl>
                    <div className="flex flex-wrap gap-1.5">
                        {Object.entries(a.plans).length === 0 ? (
                            <span className="text-[12px] text-[#b45309]">{t('لا تُباع في أيّ باقة بعد')}</span>
                        ) : (
                            Object.entries(a.plans).map(([planId, mode]) => (
                                <Badge key={planId} variant={mode === 'included' ? 'primary' : 'outline'}>
                                    {t(planName(planId))} · {t(mode === 'included' ? 'مشمولة' : 'تُشترى')}
                                </Badge>
                            ))
                        )}
                    </div>
                    <div className="mt-auto flex justify-end gap-2">
                        <Button size="sm" variant="outline" onClick={() => onPlace(a)}>
                            {t('الباقات')}
                        </Button>
                        <Button size="sm" variant="outline" onClick={() => onEdit(a)}>
                            <Pencil />
                            {t('تعديل')}
                        </Button>
                    </div>
                </Card>
            ))}
        </div>
    );
}

function AddonDialog({
    addon,
    capabilityOptions,
    currency,
    onClose,
}: {
    addon: CatalogAddon | null;
    capabilityOptions: Props['capabilityOptions'];
    currency: Currency;
    onClose: () => void;
}) {
    const t = useTranslate();
    // والرمزُ لا يُرسل: يولّده الخادم يومَ تُنشأ ولا يتبدّل (`AddonController::store`)
    const form = useForm({
        name: addon?.name ?? '',
        name_en: addon?.name_en ?? '',
        description: addon?.description ?? '',
        description_en: addon?.description_en ?? '',
        capability: addon?.capability ?? '',
        pricing: addon?.pricing ?? 'paid',
        unit: addon?.unit ?? 'store',
        monthly_price: addon?.monthly_price?.toString() ?? '',
        yearly_price: addon?.yearly_price?.toString() ?? '',
        // والجديدةُ موقوفٌ بيعُها حتى تجهز — لا تظهر لتاجرٍ قبل أن يُقرَّر عرضُها
        for_sale: addon?.for_sale ?? false,
        for_new_merchants: addon?.for_new_merchants ?? true,
        sort: addon?.sort?.toString() ?? '0',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        form.transform((d) => ({ ...d, capability: d.capability || null }));
        if (addon) {
            form.put(route('super-admin.addons.update', addon.id), options);
        } else {
            form.post(route('super-admin.addons.store'), options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t(addon ? 'تعديل الإضافة' : 'إضافة اشتراك جديدة')}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4 px-5 pb-5" data-testid="addon-form">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Field label="الاسم بالعربية" required error={form.errors.name}>
                            <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        </Field>
                        <Field label="الاسم بالإنجليزية" error={form.errors.name_en}>
                            <Input dir="ltr" value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} />
                        </Field>
                    </div>
                    <Field label="الوصف" error={form.errors.description}>
                        <Textarea rows={2} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                    </Field>
                    <Field label="الوصف بالإنجليزية" error={form.errors.description_en}>
                        <Textarea dir="ltr" rows={2} value={form.data.description_en} onChange={(e) => form.setData('description_en', e.target.value)} />
                    </Field>
                    <Field label="ما تفتحه" error={form.errors.capability} hint={t('القدرة البرمجية التي تفتحها الإضافة. بلا اختيار: خدمة إدارية أو تجارية لا تفتح نظامًا تلقائيًا')}>
                        <Select
                            value={form.data.capability}
                            placeholder="خدمة تقدّمها أبعاد — بلا باب في النظام"
                            onChange={(e) => form.setData('capability', e.target.value)}
                            options={capabilityOptions.map((o) => ({ value: o.key, label: o.label }))}
                        />
                    </Field>
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="التسعير" error={form.errors.pricing}>
                            <Select
                                value={form.data.pricing}
                                onChange={(e) => form.setData('pricing', e.target.value as CatalogAddon['pricing'])}
                                options={[
                                    { value: 'paid', label: 'مدفوعة' },
                                    { value: 'free', label: 'مجانية' },
                                    { value: 'quote', label: 'حسب الطلب' },
                                ]}
                            />
                        </Field>
                        <Field label="الوحدة" error={form.errors.unit}>
                            <Select
                                value={form.data.unit}
                                onChange={(e) => form.setData('unit', e.target.value as CatalogAddon['unit'])}
                                options={[
                                    { value: 'store', label: 'للمتجر' },
                                    { value: 'branch', label: 'لكل فرع' },
                                    { value: 'usage', label: 'لكل وحدة استخدام' },
                                ]}
                            />
                        </Field>
                    </div>
                    {form.data.pricing === 'paid' && (
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="السعر الشهري" required error={form.errors.monthly_price}>
                                <Input type="number" step="0.001" min="0.001" dir="ltr" value={form.data.monthly_price} onChange={(e) => form.setData('monthly_price', e.target.value)} />
                            </Field>
                            <Field label="السعر السنوي" required error={form.errors.yearly_price}>
                                <Input type="number" step="0.001" min="0.001" dir="ltr" value={form.data.yearly_price} onChange={(e) => form.setData('yearly_price', e.target.value)} />
                            </Field>
                            <p className="col-span-2 text-[12px] text-[#6b7280]" data-testid="addon-semiannual-price">
                                {t('نصف السنوي: :p — ستّة أشهر بالسعر الشهري، بلا خصم', { p: money(halfYear(Number(form.data.monthly_price) || 0), currency) })}
                            </p>
                        </div>
                    )}
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        {(
                            [
                                ['for_sale', 'تُباع الآن'],
                                ['for_new_merchants', 'متاحة للمتاجر الجديدة'],
                            ] as const
                        ).map(([key, text]) => (
                            <label key={key} className="flex cursor-pointer items-center justify-between rounded-[12px] border border-[var(--ui-border,#e8e8e8)] px-4 py-3">
                                <span className="text-sm font-medium text-[#374151]">{t(text)}</span>
                                <input type="checkbox" checked={form.data[key]} onChange={(e) => form.setData(key, e.target.checked)} className="size-5 rounded accent-[#111]" />
                            </label>
                        ))}
                    </div>
                    <p className="text-[12px] text-[#9ca3af]">{t('تعديل السعر لا يمسّ طلبًا ولا فاتورةً صدرت ولا دورةً اتُّفق عليها.')}</p>
                    <p className="text-[12px] text-[#9ca3af]">{t('إيقاف البيع يمنع الطلبات الجديدة وحدها — ومن اشتراها تبقى سارية له حتى نهاية دورتها.')}</p>
                    {addon && (
                        <details className="rounded-[12px] bg-[#fafafa] px-4 py-2 text-[12px] text-[#6b7280]" data-testid="addon-technical">
                            <summary className="cursor-pointer select-none font-medium">{t('تفاصيل تقنية')}</summary>
                            <p className="mt-2">
                                {t('المعرّف الداخلي')}:{' '}
                                <span dir="ltr" className="font-mono break-all">
                                    {addon.code}
                                </span>
                            </p>
                            <p className="mt-1">{t('يولّده النظام ولا يتغيّر بتعديل الاسم.')}</p>
                        </details>
                    )}
                    <div className="flex justify-end gap-2 pt-1">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            {t(addon ? 'حفظ التغييرات' : 'إضافة')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function PlacesDialog({ addon, plans, onClose }: { addon: CatalogAddon; plans: PlanRow[]; onClose: () => void }) {
    const t = useTranslate();
    const form = useForm<{ plans: Record<string, string> }>({
        plans: Object.fromEntries(plans.map((p) => [String(p.id), addon.plans[String(p.id)] ?? ''])),
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(route('super-admin.addons.plans', addon.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('أين تُباع «:n»', { n: addon.name })}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3 px-5 pb-5">
                    {plans.map((p) => (
                        <Field key={p.id} label={p.name}>
                            <Select
                                value={form.data.plans[String(p.id)]}
                                placeholder="لا تُباع فيها"
                                onChange={(e) => form.setData('plans', { ...form.data.plans, [String(p.id)]: e.target.value })}
                                options={[
                                    { value: 'eligible', label: 'تُشترى فيها' },
                                    { value: 'included', label: 'مشمولة بها' },
                                ]}
                            />
                        </Field>
                    ))}
                    {form.errors.plans && <p className="text-[12px] text-[#b91c1c]">{form.errors.plans}</p>}
                    <p className="text-[12px] text-[#9ca3af]">
                        {t('تسري على متاجر سياسة الإضافات وحدها — ما تشمله الباقة للمتاجر القائمة في قدراتها كما هي.')}
                    </p>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            {t('حفظ')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function OrdersTab({ orders, currency, empty = 'لا طلبات مفتوحة.' }: { orders: OrderRow[]; currency: Currency; empty?: string }) {
    const t = useTranslate();
    const [confirm, confirmDialog] = useConfirm();
    const [approving, setApproving] = useState<OrderRow | null>(null);

    if (orders.length === 0) {
        return <p className="text-[13px] text-[#9ca3af]">{t(empty)}</p>;
    }

    const cancel = async (o: OrderRow) => {
        if (await confirm({ message: 'يُلغى الطلب وفاتورته غير المدفوعة — ولا يتغيّر شيءٌ في اشتراك المتجر.', danger: true, action: 'إلغاء الطلب' })) {
            router.post(route('super-admin.addonOrders.cancel', o.id), {}, { preserveScroll: true });
        }
    };

    const pay = async (o: OrderRow) => {
        if (o.invoice && (await confirm({ message: 'تسجيل سداد الفاتورة :n يفعّل الإضافة فورًا.', values: { n: o.invoice.number }, action: 'تسجيل السداد' }))) {
            router.post(route('super-admin.invoices.pay', o.invoice.id), {}, { preserveScroll: true });
        }
    };

    return (
        <Card className="divide-y divide-[var(--ui-border,#e8e8e8)]" data-testid="addon-orders">
            {orders.map((o) => (
                <div key={o.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-[13px]" data-testid="addon-order-row">
                    <div className="min-w-0">
                        <p className="font-semibold text-[#111]">
                            {o.addon.name}
                            {o.quantity > 1 && <span className="tabular-nums"> × {o.quantity}</span>}
                            {o.kind === 'renewal' && <span className="ms-1 text-[#6b7280]">({t('تجديد')})</span>}
                        </p>
                        <p className="mt-0.5 text-[12px] text-[#6b7280]">
                            {o.business && (
                                <SmartLink routeName="super-admin.businesses.show" href={route('super-admin.businesses.show', o.business.id)} className="text-[#6d28d9] hover:underline">
                                    {o.business.name}
                                </SmartLink>
                            )}
                            {o.business && ' · '}
                            {t(CYCLE[o.cycle])} · {o.amount === null ? t('يحتاج سعرًا') : money(o.amount, currency)}
                            {o.invoice && ` · ${o.invoice.number}`}
                            {o.created_at && ` · ${o.created_at}`}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Badge variant={o.status === 'requested' ? 'warning' : o.status === 'invoiced' ? 'info' : 'neutral'}>{t(ORDER_STATUS[o.status])}</Badge>
                        {o.status === 'requested' && (
                            <Button size="sm" onClick={() => setApproving(o)}>
                                <Check />
                                {t('اعتماد')}
                            </Button>
                        )}
                        {o.status === 'invoiced' && o.invoice && !o.invoice.paid && (
                            <Button size="sm" onClick={() => pay(o)}>
                                {t('تسجيل السداد')}
                            </Button>
                        )}
                        {(o.status === 'requested' || o.status === 'invoiced') && (
                            <Button size="sm" variant="outline" onClick={() => cancel(o)}>
                                {t('إلغاء')}
                            </Button>
                        )}
                    </div>
                </div>
            ))}
            {approving && <ApproveDialog order={approving} currency={currency} onClose={() => setApproving(null)} />}
            {confirmDialog}
        </Card>
    );
}

function ApproveDialog({ order, currency, onClose }: { order: OrderRow; currency: Currency; onClose: () => void }) {
    const t = useTranslate();
    const form = useForm({ unit_price: order.unit_price?.toString() ?? '', note: '' });
    const quote = order.unit_price === null;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, unit_price: d.unit_price === '' ? null : d.unit_price }));
        form.post(route('super-admin.addonOrders.approve', order.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('اعتماد «:n»', { n: order.addon.name })}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3 px-5 pb-5">
                    <Field
                        label={quote ? 'السعر المعروض للوحدة' : 'سعر الوحدة المثبَّت'}
                        required={quote}
                        error={form.errors.unit_price}
                        hint={quote ? t('حسب الطلب: لا تُصدر فاتورة قبل سعرٍ معتمد') : t('ثُبّت ساعة الطلب: :p — غيّره لخصمٍ معتمد واكتب سببه', { p: money(order.unit_price, currency) })}
                    >
                        <Input type="number" step="0.001" min="0" dir="ltr" value={form.data.unit_price} onChange={(e) => form.setData('unit_price', e.target.value)} />
                    </Field>
                    <Field label="ملاحظة" error={form.errors.note}>
                        <Textarea rows={2} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                    </Field>
                    <p className="text-[12px] text-[#9ca3af]">{t('تصدر الفاتورة، ولا تسري الإضافة قبل تسجيل سدادها. والصفر يفعّلها بلا فاتورة.')}</p>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            {t('اعتماد وإصدار الفاتورة')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function SubscribersTab({ rows, currency }: { rows: EntitlementRow[]; currency: Currency }) {
    const t = useTranslate();

    if (rows.length === 0) {
        return <p className="text-[13px] text-[#9ca3af]">{t('لا مشتركين في الإضافات بعد.')}</p>;
    }

    return (
        <Card className="overflow-x-auto no-scrollbar" data-testid="addon-subscribers">
            <table className="w-full min-w-[880px] text-[13px]">
                <thead className="bg-[#fafafa] text-[#6b7280]">
                    <tr>
                        <th className="px-4 py-2 text-start font-medium">{t('المتجر')}</th>
                        <th className="px-4 py-2 text-start font-medium">{t('الإضافة')}</th>
                        <th className="px-4 py-2 text-start font-medium">{t('الحالة')}</th>
                        <th className="px-4 py-2 text-start font-medium">{t('المصدر')}</th>
                        <th className="px-4 py-2 text-start font-medium">{t('من')}</th>
                        <th className="px-4 py-2 text-start font-medium">{t('حتى')}</th>
                        <th className="px-4 py-2 text-start font-medium">{t('الدورة')}</th>
                        <th className="px-4 py-2 text-start font-medium">{t('التجديد')}</th>
                        <th className="px-4 py-2 text-start font-medium">{t('السعر')}</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                    {rows.map((r) => (
                        <tr key={r.id}>
                            <td className="px-4 py-2">
                                {r.business && (
                                    <SmartLink routeName="super-admin.businesses.show" href={route('super-admin.businesses.show', r.business.id)} className="text-[#6d28d9] hover:underline">
                                        {r.business.name}
                                    </SmartLink>
                                )}
                            </td>
                            <td className="px-4 py-2 text-[#111]">
                                {r.addon.name}
                                {r.quantity > 1 && <span className="tabular-nums"> × {r.quantity}</span>}
                            </td>
                            <td className="px-4 py-2" data-testid="entitlement-state">
                                <Badge variant={r.active ? 'success' : 'neutral'}>{t(r.active ? 'سارية' : 'منتهية')}</Badge>
                            </td>
                            <td className="px-4 py-2">{t(r.source === 'grant' ? 'منحة' : 'شراء')}</td>
                            <td className="px-4 py-2 tabular-nums">{r.starts_at}</td>
                            <td className="px-4 py-2 tabular-nums">{r.ends_at ?? t('بلا نهاية')}</td>
                            <td className="px-4 py-2">{r.cycle ? t(CYCLE[r.cycle]) : '—'}</td>
                            <td className="px-4 py-2" data-testid="entitlement-renewal">
                                {!r.cycle ? t('لا تجديد') : r.renews ? t('يتجدّد') : t('التجديد موقوف')}
                            </td>
                            <td className="px-4 py-2 tabular-nums">
                                {r.source === 'grant' ? '—' : `${money(r.unit_price * r.quantity, currency)} ${r.cycle ? t(CYCLE[r.cycle]) : ''}`}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </Card>
    );
}

/**
 * «التسعير والإعدادات» — أسعارُ الباقات، وحالةُ نظام الإضافات وشروطُه، والسياستان.
 *
 * كان تبويبًا باسم «الإطلاق». والمقبضُ نفسُه لم يتغيّر (`PricingPolicy::setEnabled`):
 * لا يُفعَّل وشرطٌ ناقص، ولا بغير موافقةٍ صريحة تصل مع الطلب (`acknowledged`).
 * والإيقافُ للطوارئ بتأكيدين، ولا ينقل متجرًا ولا يمسّ استحقاقًا.
 */
function SettingsTab({ launch, plans, currency }: { launch: Props['launch']; plans: PlanRow[]; currency: Currency }) {
    const t = useTranslate();
    const [ask, confirmDialog] = useConfirm();
    const [activating, setActivating] = useState(false);
    const checks = launch.checks ?? [];
    const ready = launch.blockers.length === 0;

    const stop = async () => {
        const first = await ask({
            message: 'تعود التسجيلات الجديدة إلى السياسة السابقة. المتاجر الموجودة تبقى على سياستها وأسعارها واستحقاقاتها، ولا تُحذف أي إضافة.',
            action: 'متابعة الإيقاف',
            danger: true,
        });
        if (!first) return;
        const second = await ask({
            message: 'تأكيد أخير: إيقاف نظام الإضافات للتسجيلات الجديدة؟',
            action: 'إيقاف نظام الإضافات',
            danger: true,
        });
        if (second) {
            router.post(route('super-admin.addons.launch'), { enabled: false }, { preserveScroll: true });
        }
    };

    return (
        <div className="space-y-5" data-testid="addons-launch">
            <Card className="space-y-4 p-5 sm:p-6" data-testid="launch-status">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex min-w-0 items-start gap-3">
                        <span className={`flex size-11 shrink-0 items-center justify-center rounded-[12px] ${launch.enabled ? 'bg-[#ecfdf5] text-[#047857]' : 'bg-[#f2f2f0] text-[#4b4b4b]'}`}>
                            <SlidersHorizontal className="size-5" />
                        </span>
                        <div className="min-w-0">
                            <p className="text-[16px] font-bold text-[#111]">
                                {t(launch.enabled ? 'نظام الإضافات مفعّل للتسجيلات الجديدة' : 'نظام الاشتراكات الجديد غير مفعّل')}
                            </p>
                            <p className="mt-1 text-[13px] leading-6 text-[#6b7280]">
                                {t(
                                    launch.enabled
                                        ? 'كل متجر يُسجَّل الآن يُختم على نظام الإضافات. المتاجر الموجودة على سياستها كما هي.'
                                        : 'المتاجر الجديدة تستمر حاليًا على سياسة الاشتراكات السابقة، حتى تعتمد النظام الجديد.',
                                )}
                            </p>
                            <p className="mt-2 text-[13px] text-[#4b4b4b] tabular-nums" data-testid="policy-counts">
                                {t('متاجر على السياسة السابقة: :a · على نظام الإضافات: :b', { a: launch.counts.legacy, b: launch.counts.addons })}
                            </p>
                        </div>
                    </div>
                    <div className="flex shrink-0 flex-col items-stretch gap-2 sm:items-end">
                        <Badge variant={launch.enabled ? 'success' : 'neutral'} data-testid="launch-state" className="self-start sm:self-end">
                            {t(launch.enabled ? 'مفعّل' : 'غير مفعّل')}
                        </Badge>
                        {!launch.enabled && (
                            <Button disabled={!ready} onClick={() => setActivating(true)} data-testid="launch-activate">
                                <Power />
                                {t('تفعيل نظام الإضافات للتجار الجدد')}
                            </Button>
                        )}
                    </div>
                </div>

                {checks.length > 0 && (
                    <div className="border-t border-[var(--ui-border,#e8e8e8)] pt-4">
                        <p className="mb-2 text-[13px] font-semibold text-[#111]">{t('شروط الجاهزية')}</p>
                        <ul className="space-y-2" data-testid="launch-checks">
                            {checks.map((c) => (
                                <li key={c.key} className="flex items-start gap-2 text-[13px]" data-testid="launch-check" data-ok={c.ok ? '1' : '0'}>
                                    {c.ok ? <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-[#047857]" /> : <CircleAlert className="mt-0.5 size-4 shrink-0 text-[#b45309]" />}
                                    <div className="min-w-0">
                                        <p className={c.ok ? 'text-[#111]' : 'font-medium text-[#92400e]'}>{c.label}</p>
                                        {c.problems.map((p) => (
                                            <p key={p} className="text-[12px] text-[#92400e]">
                                                {p}
                                            </p>
                                        ))}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {launch.blockers.length > 0 && (
                    <ul className="space-y-1 rounded-[12px] bg-[#fffbeb] px-4 py-3 text-[13px] text-[#92400e]" data-testid="launch-blockers">
                        {launch.blockers.map((b) => (
                            <li key={b}>• {b}</li>
                        ))}
                    </ul>
                )}
            </Card>

            <Card className="overflow-hidden">
                <div className="border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                    <p className="text-[15px] font-bold text-[#111]">{t('أسعار الباقات')}</p>
                    <p className="mt-0.5 text-[12px] text-[#6b7280]">
                        {t('السعر الحالي لمتاجر السياسة السابقة، وسعر النظام الجديد لمتاجره. اعتماد سعر جديد لا يمسّ فاتورة صدرت ولا اشتراكًا قائمًا.')}
                    </p>
                </div>
                <div className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                    {plans.map((p) => (
                        <PlanPriceRow key={p.id} plan={p} currency={currency} />
                    ))}
                </div>
            </Card>

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2" data-testid="policies-info">
                <Card className="p-5">
                    <p className="text-[14px] font-bold text-[#111]">{t('السياسة السابقة')}</p>
                    <p className="mt-1 text-[13px] leading-6 text-[#6b7280]">
                        {t('كل متجر قائم، وكل متجر يُسجَّل والنظام الجديد غير مفعّل. باقته وخدماتها وأسعارها ونقطة البيع كما كانت، ولا يُطلب منه شراء شيء.')}
                    </p>
                </Card>
                <Card className="p-5">
                    <p className="text-[14px] font-bold text-[#111]">{t('نظام الإضافات')}</p>
                    <p className="mt-1 text-[13px] leading-6 text-[#6b7280]">
                        {t('متجر يُسجَّل بعد التفعيل. باقته بسعرها الجديد، ونقطة البيع وغيرها إضافات تُشترى وتُفوتَر وتتجدّد. والسياسة تُختم يوم التسجيل ولا تتبدّل بعده.')}
                    </p>
                </Card>
            </div>

            {launch.enabled && (
                <Card className="flex flex-col gap-3 border-[#fecaca] p-5 sm:flex-row sm:items-center sm:justify-between" data-testid="launch-emergency">
                    <div className="flex min-w-0 items-start gap-3">
                        <ShieldAlert className="mt-0.5 size-5 shrink-0 text-[#b91c1c]" />
                        <div className="min-w-0">
                            <p className="text-[14px] font-bold text-[#111]">{t('إيقاف الطوارئ')}</p>
                            <p className="mt-0.5 text-[13px] leading-6 text-[#6b7280]">
                                {t('يعيد التسجيلات الجديدة إلى السياسة السابقة. لا تتغيّر سياسة متجر موجود، ولا تتعطّل استحقاقاته، ولا تتغيّر أسعاره، ولا تُحذف إضافة.')}
                            </p>
                        </div>
                    </div>
                    <Button variant="outline" className="shrink-0" onClick={stop} data-testid="launch-stop">
                        <Power />
                        {t('إيقاف نظام الإضافات')}
                    </Button>
                </Card>
            )}

            {activating && <ActivateDialog launch={launch} plans={plans} currency={currency} onClose={() => setActivating(false)} />}
            {confirmDialog}
        </div>
    );
}

/** نافذةُ التفعيل — تلخّص أثرَه، ولا تُرسل قبل موافقةٍ تُؤشَّر بيد صاحب المنصّة */
function ActivateDialog({ launch, plans, currency, onClose }: { launch: Props['launch']; plans: PlanRow[]; currency: Currency; onClose: () => void }) {
    const t = useTranslate();
    const [agreed, setAgreed] = useState(false);
    const [sending, setSending] = useState(false);
    const basic = plans.find((p) => p.code === 'basic');

    const activate = () => {
        setSending(true);
        router.post(route('super-admin.addons.launch'), { enabled: true, acknowledged: true }, { preserveScroll: true, onFinish: () => setSending(false), onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('تفعيل نظام الإضافات للتجار الجدد')}</DialogTitle>
                </DialogHeader>
                <div className="space-y-4 px-5 pb-5" data-testid="launch-dialog">
                    <ul className="space-y-2 text-[13px] leading-6 text-[#4b4b4b]">
                        <li>
                            •{' '}
                            {basic?.addons
                                ? t('كل متجر يُسجَّل بعد التفعيل يبدأ بالباقة الأساسية بسعرها الجديد :p شهريًا، ونقطة البيع وغيرها إضافات تُشترى.', { p: money(basic.addons.monthly, currency) })
                                : t('كل متجر يُسجَّل بعد التفعيل يبدأ على نظام الإضافات، ونقطة البيع وغيرها إضافات تُشترى.')}
                        </li>
                        <li>• {t('المتاجر الموجودة (:n) تبقى على سياستها وباقاتها وأسعارها وخدماتها كما هي.', { n: launch.counts.legacy + launch.counts.addons })}</li>
                        <li>• {t('يمكن إيقاف التفعيل لاحقًا من هنا، ولا ينقل الإيقاف أي متجر بين السياستين.')}</li>
                    </ul>
                    <label className="flex cursor-pointer items-start gap-3 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] px-4 py-3">
                        <input type="checkbox" checked={agreed} onChange={(e) => setAgreed(e.target.checked)} className="mt-0.5 size-5 rounded accent-[#111]" data-testid="launch-ack" />
                        <span className="text-[13px] font-medium text-[#374151]">{t('قرأت أثر التفعيل وأوافق على تفعيل نظام الإضافات للتسجيلات الجديدة')}</span>
                    </label>
                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="button" disabled={!agreed} loading={sending} onClick={activate} data-testid="launch-confirm">
                            {t('تفعيل نظام الإضافات للتجار الجدد')}
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function PlanPriceRow({ plan, currency }: { plan: PlanRow; currency: Currency }) {
    const t = useTranslate();
    const form = useForm({ monthly: plan.addons?.monthly?.toString() ?? '', yearly: plan.addons?.yearly?.toString() ?? '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(route('super-admin.plans.policyPrice', plan.id), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="grid grid-cols-1 gap-4 px-5 py-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end" data-testid="plan-price-row">
            <div className="min-w-0 text-[13px]">
                <p className="font-semibold text-[#111]">{plan.name}</p>
                <p className="mt-0.5 text-[12px] text-[#6b7280] tabular-nums">
                    {t('متاجره: :a على السابقة · :b على الإضافات', { a: plan.businesses.legacy, b: plan.businesses.addons })}
                </p>
            </div>
            <div className="min-w-0 rounded-[12px] bg-[#fafafa] px-4 py-2.5 text-[13px]" data-testid="plan-legacy-price">
                <p className="text-[12px] text-[#6b7280]">{t('السعر الحالي — السياسة السابقة')}</p>
                <p className="mt-0.5 tabular-nums text-[#111]">
                    {money(plan.legacy.monthly, currency)} {t('/ شهريًا')} · {money(halfYear(plan.legacy.monthly), currency)} {t('نصف سنويًا')} · {money(plan.legacy.yearly, currency)} {t('سنويًا')}
                </p>
                <p className="mt-0.5 text-[11.5px] text-[#9ca3af]">{t('يُعدَّل من شاشة الباقات')}</p>
            </div>
            <div className="min-w-0">
                <p className="mb-1 text-[12px] text-[#6b7280]">{t('سعر النظام الجديد')}</p>
                <div className="flex flex-wrap items-end gap-2">
                    <Field label="شهري" error={form.errors.monthly}>
                        <Input type="number" step="0.001" min="0.001" dir="ltr" className="w-28" value={form.data.monthly} onChange={(e) => form.setData('monthly', e.target.value)} />
                    </Field>
                    <Field label="سنوي" error={form.errors.yearly}>
                        <Input type="number" step="0.001" min="0.001" dir="ltr" className="w-28" value={form.data.yearly} onChange={(e) => form.setData('yearly', e.target.value)} />
                    </Field>
                    <Button type="submit" size="sm" loading={form.processing}>
                        {t('اعتماد')}
                    </Button>
                </div>
                {/* ونصفُ السنة لا يُكتب: ستّةُ أشهرٍ بالشهريّ — كما يُفوتَر */}
                <p className="mt-1 text-[12px] tabular-nums text-[#6b7280]" data-testid="plan-semiannual-price">
                    {t('نصف سنوي: :p', { p: money(halfYear(Number(form.data.monthly) || 0), currency) })}
                </p>
                {!plan.addons && (
                    <p className="mt-1 text-[12px] text-[#b45309]">
                        {plan.code === 'basic' ? t('لم يُعتمد — يمنع التفعيل') : t('لم يُعتمد — تُفوتَر بسعرها السابق')}
                    </p>
                )}
            </div>
        </form>
    );
}
