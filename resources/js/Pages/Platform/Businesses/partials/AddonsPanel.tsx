import { type FormEvent, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Gift } from 'lucide-react';
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
                <Button size="sm" onClick={() => setGranting(true)}>
                    <Gift />
                    {t('منح إضافة')}
                </Button>
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
                    <Field label="حتى تاريخ" hint="الفراغ: بلا نهاية" error={form.errors.ends_at}>
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
