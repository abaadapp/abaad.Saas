import { type FormEvent, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Gift, Plus } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { Currency } from '@/types';
import { CYCLE, type EntitlementRow, type OrderRow, OrdersTab } from '@/Pages/Platform/Subscriptions/Addons';

export interface BusinessAddons {
    policy: 'legacy' | 'addons_v1';
    entitlements: EntitlementRow[];
    orders: OrderRow[];
    grantable: { id: number; name: string; counted: boolean }[];
    /** ما يُطلب للتاجر بفاتورة — إضافاتُ باقته المؤهّلة، لسياسة الإضافات وحدها */
    orderable?: OrderableAddon[];
}

export interface OrderableAddon {
    id: number;
    name: string;
    pricing: 'paid' | 'free' | 'quote';
    counted: boolean;
    prices: { monthly: number; semiannual: number; yearly: number } | null;
}

/**
 * إضافاتُ متجرٍ في ملفّه — سياستُه، وما يسري له وما انقضى، وطلباتُه، والمنح.
 *
 * كلُّ فعلٍ هنا يمرّ بالخادم (`Addons\AddonOrders`): المنحُ بسببٍ مكتوب، والإنهاءُ
 * بسببٍ مكتوب، والتجديدُ بفاتورة — ولا يُحذف شيءٌ من بيانات المتجر.
 */
export default function AddonsPanel({ businessId, data, currency }: { businessId: number; data: BusinessAddons; currency: Currency }) {
    const t = useTranslate();
    const [confirm, confirmDialog] = useConfirm();
    const [granting, setGranting] = useState(false);
    const [ordering, setOrdering] = useState(false);
    const [ending, setEnding] = useState<EntitlementRow | null>(null);

    const renew = async (row: EntitlementRow) => {
        if (await confirm({ message: 'تصدر فاتورة تجديد «:n» بسعرها المتّفق، وتُمدّ الإضافة بتسجيل سدادها.', values: { n: row.addon.name }, action: 'إصدار فاتورة التجديد' })) {
            router.post(route('super-admin.businessAddons.renew', row.id), {}, { preserveScroll: true });
        }
    };

    const renews = (row: EntitlementRow) =>
        router.put(route('super-admin.businessAddons.renews', row.id), { renews: !row.renews }, { preserveScroll: true });

    return (
        <div className="space-y-5 p-5" data-testid="business-addons">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-2 text-[13px] text-[#4b4b4b]">
                    {t('سياسة التسعير')}:
                    <Badge variant={data.policy === 'addons_v1' ? 'primary' : 'neutral'} data-testid="business-policy">
                        {t(data.policy === 'addons_v1' ? 'سياسة الإضافات' : 'السياسة السابقة')}
                    </Badge>
                </div>
                <div className="flex flex-wrap gap-2">
                    {(data.orderable?.length ?? 0) > 0 && (
                        <Button size="sm" onClick={() => setOrdering(true)} data-testid="order-addon">
                            <Plus />
                            {t('طلب إضافة للتاجر')}
                        </Button>
                    )}
                    <Button size="sm" variant="outline" onClick={() => setGranting(true)}>
                        <Gift />
                        {t('منح إضافة')}
                    </Button>
                </div>
            </div>

            {data.entitlements.length === 0 ? (
                <p className="text-[13px] text-[#9ca3af]">{t('لا إضافات لهذا المتجر.')}</p>
            ) : (
                <div className="divide-y divide-[var(--ui-border,#e8e8e8)] rounded-[12px] border border-[var(--ui-border,#e8e8e8)]">
                    {data.entitlements.map((row) => (
                        <div key={row.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-[13px]" data-testid="business-entitlement">
                            <div className="min-w-0">
                                <p className="font-semibold text-[#111]">
                                    {row.addon.name}
                                    {row.quantity > 1 && <span className="tabular-nums"> × {row.quantity}</span>}
                                </p>
                                <p className="mt-0.5 text-[12px] text-[#6b7280]">
                                    {t(row.source === 'grant' ? 'منحة' : 'شراء')} · {row.starts_at} → {row.ends_at ?? t('بلا نهاية')}
                                    {row.source === 'purchase' && row.cycle && ` · ${money(row.unit_price * row.quantity, currency)} ${t(CYCLE[row.cycle])}`}
                                    {row.reason && ` · ${row.reason}`}
                                </p>
                            </div>
                            <div className="flex items-center gap-2">
                                <Badge variant={row.cancelled_at ? 'danger' : row.active ? 'success' : 'neutral'}>
                                    {t(row.cancelled_at ? 'أُنهيت' : row.active ? 'سارية' : 'منقضية')}
                                </Badge>
                                {!row.cancelled_at && row.source === 'purchase' && row.cycle && (
                                    <>
                                        <Button size="sm" variant="outline" onClick={() => renews(row)}>
                                            {t(row.renews ? 'إيقاف التجديد' : 'إعادة التجديد')}
                                        </Button>
                                        {row.renews && (
                                            <Button size="sm" variant="outline" onClick={() => renew(row)}>
                                                {t('تجديد')}
                                            </Button>
                                        )}
                                    </>
                                )}
                                {!row.cancelled_at && row.active && (
                                    <Button size="sm" variant="outline" onClick={() => setEnding(row)}>
                                        {t('إنهاء')}
                                    </Button>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            <div>
                <p className="mb-2 text-[13px] font-bold text-[#111]">{t('طلبات الإضافات')}</p>
                <OrdersTab orders={data.orders.filter((o) => o.status === 'requested' || o.status === 'invoiced')} currency={currency} empty="لا طلبات مفتوحة لهذا المتجر." />
            </div>

            {ordering && <OrderDialog businessId={businessId} orderable={data.orderable ?? []} currency={currency} onClose={() => setOrdering(false)} />}
            {granting && <GrantDialog businessId={businessId} grantable={data.grantable} onClose={() => setGranting(false)} />}
            {ending && <EndDialog row={ending} onClose={() => setEnding(null)} />}
            {confirmDialog}
        </div>
    );
}

function GrantDialog({ businessId, grantable, onClose }: { businessId: number; grantable: BusinessAddons['grantable']; onClose: () => void }) {
    const t = useTranslate();
    const form = useForm({ addon_id: '', quantity: '1', ends_at: '', reason: '' });
    const counted = grantable.find((a) => String(a.id) === form.data.addon_id)?.counted ?? false;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('super-admin.businesses.addons.grant', businessId), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('منح إضافة أو عرض خاص')}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3 px-5 pb-5" data-testid="grant-form">
                    <Field label="الإضافة" required error={form.errors.addon_id}>
                        <Select
                            value={form.data.addon_id}
                            placeholder="اختر"
                            onChange={(e) => form.setData('addon_id', e.target.value)}
                            options={grantable.map((a) => ({ value: String(a.id), label: a.name }))}
                        />
                    </Field>
                    {counted && (
                        <Field label="العدد" error={form.errors.quantity}>
                            <Input type="number" min="1" max="50" dir="ltr" value={form.data.quantity} onChange={(e) => form.setData('quantity', e.target.value)} />
                        </Field>
                    )}
                    <Field label="حتى تاريخ" required hint="المنحة مؤرّخة: تنتهي في يومها" error={form.errors.ends_at}>
                        <Input type="date" dir="ltr" value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} />
                    </Field>
                    <Field label="السبب" required error={form.errors.reason}>
                        <Input value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    </Field>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            {t('منح')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * طلبُ إضافةٍ يكتبه فريقُ أبعاد للتاجر — يُعتمد وتصدر فاتورتُه معًا (`AddonOrders::orderFor`).
 *
 * والإضافةُ تسري بتسجيل سداد فاتورتها، لا بهذا الطلب. و«حسب الطلب» تحتاج سعرًا،
 * وسعرٌ غيرُ سعر الكتالوج يحتاج سببًا.
 */
function OrderDialog({ businessId, orderable, currency, onClose }: { businessId: number; orderable: OrderableAddon[]; currency: Currency; onClose: () => void }) {
    const t = useTranslate();
    const form = useForm({ addon_id: '', cycle: 'monthly', quantity: '1', unit_price: '', note: '' });
    const addon = orderable.find((a) => String(a.id) === form.data.addon_id);
    const catalog = addon?.prices ? addon.prices[form.data.cycle as 'monthly' | 'semiannual' | 'yearly'] : addon?.pricing === 'free' ? 0 : null;
    const unit = form.data.unit_price !== '' ? Number(form.data.unit_price) : catalog;
    const qty = addon?.counted ? Math.max(1, Number(form.data.quantity) || 1) : 1;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('super-admin.businesses.addons.order', businessId), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('طلب إضافة للتاجر')}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3 px-5 pb-5" data-testid="order-addon-form">
                    <Field label="الإضافة" required error={form.errors.addon_id}>
                        <Select
                            value={form.data.addon_id}
                            placeholder="اختر"
                            onChange={(e) => form.setData('addon_id', e.target.value)}
                            options={orderable.map((a) => ({ value: String(a.id), label: a.name }))}
                        />
                    </Field>
                    <Field label="الدورة" required error={form.errors.cycle}>
                        <Select
                            value={form.data.cycle}
                            onChange={(e) => form.setData('cycle', e.target.value)}
                            options={[
                                { value: 'monthly', label: 'شهري' },
                                { value: 'semiannual', label: 'نصف سنوي' },
                                { value: 'yearly', label: 'سنوي' },
                            ]}
                        />
                    </Field>
                    {addon?.counted && (
                        <Field label="العدد" error={form.errors.quantity}>
                            <Input type="number" min="1" max="50" dir="ltr" value={form.data.quantity} onChange={(e) => form.setData('quantity', e.target.value)} />
                        </Field>
                    )}
                    <Field
                        label="سعر الوحدة"
                        required={addon?.pricing === 'quote'}
                        hint={catalog != null ? t('سعر الكتالوج: :p — اتركه فارغًا لاعتماده', { p: money(catalog, currency) }) : t('حسب الطلب: اكتب السعر المتّفق')}
                        error={form.errors.unit_price}
                    >
                        <Input type="number" step="0.001" min="0" dir="ltr" value={form.data.unit_price} onChange={(e) => form.setData('unit_price', e.target.value)} />
                    </Field>
                    {form.data.unit_price !== '' && catalog != null && Number(form.data.unit_price) !== catalog && (
                        <Field label="سبب تغيير السعر" required error={form.errors.note}>
                            <Input value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                        </Field>
                    )}
                    {(form.errors as Record<string, string>).addon && <p className="text-[12px] text-[#b91c1c]">{(form.errors as Record<string, string>).addon}</p>}
                    <p className="text-[13px] text-[#4b4b4b]" data-testid="order-addon-summary">
                        {unit == null
                            ? t('اكتب السعر قبل إصدار الفاتورة.')
                            : unit * qty > 0
                              ? t('تصدر فاتورة بمبلغ :a، وتسري الإضافة بتسجيل سدادها.', { a: money(unit * qty, currency) })
                              : t('بلا مبلغ — تُفعَّل الإضافة باعتماد هذا الطلب.')}
                    </p>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" loading={form.processing} disabled={!addon}>
                            {t('إصدار الطلب والفاتورة')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EndDialog({ row, onClose }: { row: EntitlementRow; onClose: () => void }) {
    const t = useTranslate();
    const form = useForm({ reason: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('super-admin.businessAddons.end', row.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('إنهاء «:n» الآن', { n: row.addon.name })}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3 px-5 pb-5">
                    <p className="text-[13px] text-[#4b4b4b]">{t('تتوقّف الإضافة اليوم، ولا يُحذف شيءٌ من بيانات المتجر — ولا يُردّ مبلغٌ تلقائيًّا.')}</p>
                    <Field label="السبب" required error={form.errors.reason}>
                        <Input value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    </Field>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" variant="danger" loading={form.processing}>
                            {t('إنهاء')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
