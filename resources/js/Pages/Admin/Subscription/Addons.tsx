import { type FormEvent, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, Package, ShoppingCart } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
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

/**
 * «إضافات متجرك» — ما يشمله الاشتراك، وما يُطلب، وما يسري.
 *
 * الأزرارُ ترسم ما يقبله الخادم ولا تقرّر شيئًا: الطلبُ يُرسل رمزَ الإضافة
 * ودورتَها وعددَها، والسعرُ والشمولُ والسياسةُ تُقرأ هناك.
 */
export default function SubscriptionAddons() {
    const props = usePage<PageProps<Props>>().props;
    const { policy, plan, included, available, active, orders, addons_total, may_request, mayBuy, currency } = props;
    const t = useTranslate();
    const [confirm, confirmDialog] = useConfirm();
    const [buying, setBuying] = useState<MerchantAddon | null>(null);

    const toggleRenews = async (row: Active) => {
        const next = !row.renews;
        const ok = await confirm({
            message: next ? 'تتجدّد الإضافة بفاتورةٍ عند نهاية دورتها.' : 'تبقى الإضافة ساريةً حتى :d ثمّ تتوقّف — ولا يُحذف شيءٌ من بيانات متجرك.',
            values: { d: row.ends_at ?? '—' },
            action: next ? 'إعادة التجديد' : 'إيقاف التجديد',
            danger: !next,
        });
        if (ok) {
            router.put(route('admin.subscription.addons.renews', row.id), { renews: next }, { preserveScroll: true });
        }
    };

    return (
        <AdminLayout title="إضافات متجرك">
            <PageHeader title="إضافات متجرك" subtitle={t('طوّر اشتراكك بالإضافات التي يحتاجها متجرك — ولكلّ إضافةٍ فاتورتُها ودورتُها')} />

            {policy === 'legacy' ? (
                <Card className="p-5 text-[14px] text-[#4b4b4b]" data-testid="legacy-policy-note">
                    <p className="font-semibold text-[#111]">{t('اشتراكك على سياسته السابقة')}</p>
                    <p className="mt-1">{t('ميزات باقتك وأسعارها كما هي، ولا يُطلب منك شراء شيءٍ منها. الإضافات للمتاجر التي تُسجَّل بعد إطلاقها — ولأيّ تعديلٍ تواصل مع أبعاد.')}</p>
                </Card>
            ) : (
                <div className="space-y-6">
                    <Card className="flex flex-wrap items-center justify-between gap-3 p-5" data-testid="plan-summary">
                        <div>
                            <p className="text-[13px] text-[#9ca3af]">{t('باقتك')}</p>
                            <p className="text-[17px] font-bold text-[#111]">{plan?.name ?? '—'}</p>
                        </div>
                        <div className="text-end text-[13px] text-[#4b4b4b] tabular-nums">
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
                    </Card>

                    {included.length > 0 && (
                        <section>
                            <h2 className="mb-2 text-[14px] font-bold text-[#111]">{t('مشمولة في باقتك')}</h2>
                            <div className="flex flex-wrap gap-2" data-testid="included-addons">
                                {included.map((a) => (
                                    <Badge key={a.code} variant="success">
                                        <CheckCircle2 className="me-1 size-3.5" />
                                        {a.name}
                                    </Badge>
                                ))}
                            </div>
                        </section>
                    )}

                    {active.length > 0 && (
                        <section>
                            <h2 className="mb-2 text-[14px] font-bold text-[#111]">{t('إضافاتك السارية')}</h2>
                            <Card className="divide-y divide-[var(--ui-border,#e8e8e8)]" data-testid="active-addons">
                                {active.map((row) => (
                                    <div key={row.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-[13px]">
                                        <div>
                                            <p className="font-semibold text-[#111]">
                                                {row.addon.name}
                                                {row.quantity > 1 && <span className="tabular-nums"> × {row.quantity}</span>}
                                            </p>
                                            <p className="mt-0.5 text-[12px] text-[#6b7280]">
                                                {row.ends_at ? t('سارية حتى :d', { d: row.ends_at }) : t('بلا نهاية')}
                                                {row.source === 'grant' && ` · ${t('منحة من أبعاد')}`}
                                                {row.source === 'purchase' && row.cycle && ` · ${money(row.unit_price * row.quantity, currency)} ${t(CYCLE[row.cycle])}`}
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

                    {orders.length > 0 && (
                        <section>
                            <h2 className="mb-2 text-[14px] font-bold text-[#111]">{t('طلباتك')}</h2>
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
                        <h2 className="mb-2 text-[14px] font-bold text-[#111]">{t('إضافات متاحة لباقتك')}</h2>
                        {available.length === 0 ? (
                            <p className="text-[13px] text-[#9ca3af]">{t('لا إضافات أخرى لباقتك الآن.')}</p>
                        ) : (
                            <div className="grid grid-cols-1 gap-3 md:grid-cols-2" data-testid="available-addons">
                                {available.map((a) => (
                                    <Card key={a.code} className="flex flex-col gap-2 p-5">
                                        <div className="flex items-start gap-3">
                                            <span className="flex size-10 shrink-0 items-center justify-center rounded-[10px] bg-[#f2f2f0] text-[#4b4b4b]">
                                                <Package className="size-5" />
                                            </span>
                                            <div className="min-w-0">
                                                <p className="font-bold text-[#111]">{a.name}</p>
                                                {a.description && <p className="mt-0.5 text-[12px] text-[#6b7280]">{a.description}</p>}
                                            </div>
                                        </div>
                                        <p className="text-[13px] tabular-nums text-[#111]">
                                            {a.pricing === 'quote'
                                                ? t('حسب الطلب')
                                                : a.pricing === 'free'
                                                  ? t('مجانية')
                                                  : `${money(a.monthly_price, currency)} ${t('/ شهريًا')} · ${money(a.yearly_price, currency)} ${t('سنويًا')}`}
                                            {a.counted && <span className="text-[#6b7280]"> · {t(a.unit === 'branch' ? 'لكل فرع' : 'لكل وحدة')}</span>}
                                        </p>
                                        <div className="mt-auto flex justify-end">
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
                                    </Card>
                                ))}
                            </div>
                        )}
                    </section>
                </div>
            )}

            {buying && <RequestDialog addon={buying} currency={currency} onClose={() => setBuying(null)} />}
            {confirmDialog}
        </AdminLayout>
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
