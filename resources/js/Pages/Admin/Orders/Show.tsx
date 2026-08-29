import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Calendar, FileText, Gift, MapPin, PencilLine, Truck, User } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { Input } from '@/Components/ui/input';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface OrderDetail {
    id: string;
    customer: string;
    employee: string;
    branch: string | null;
    date: string;
    payment: string;
    payment_status: string;
    notes: string | null;
    /* تفاصيل التنفيذ — كلّها قد تكون فارغة في طلبٍ قديم */
    recipient_name: string | null;
    recipient_phone: string | null;
    fulfillment_type: string | null;
    scheduled_for: string | null;
    occasion_type: string | null;
    card_message: string | null;
    sender_name: string | null;
    hide_sender: boolean;
    delivery_address: string | null;
    delivery_notes: string | null;
    internal_notes: string | null;
    status: string;
    next_statuses: string[];
    occasions: { value: string; label: string }[];
    fulfillments: { value: string; label: string }[];
    subtotal: number;
    discount: number;
    tax: number;
    delivery: number;
    total: number;
    items: { name: string; qty: number; price: number; total: number }[];
    /** تصحيحات وقعت على الفاتورة بعد بيعها — انظر App\Support\OrderCorrection */
    edits: {
        kind: string;
        subject: string;
        qty_before: number | null;
        qty_after: number | null;
        value_before: string | null;
        value_after: string | null;
        total_before: number;
        total_after: number;
        reason: string;
        by: string;
        at: string;
    }[];
}

export default function OrderShow() {
    const { order, context } = usePage<PageProps<{ order: OrderDetail }>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);

    /*
     * نموذج تعديل التنفيذ — يبدأ من القيم المحفوظة.
     *
     * القيم الفارغة تُبدَّل بسلسلةٍ فارغة لا تُترك null: حقلٌ قيمتُه null
     * يبدأ غير مضبوط في React، فأوّل حرفٍ يُكتب فيه يقلبه إلى مضبوط ويطبع
     * تحذيرًا في المتصفّح.
     */
    const [editing, setEditing] = useState(false);
    const form = useForm({
        fulfillment_type: order.fulfillment_type ?? '',
        recipient_name: order.recipient_name ?? '',
        recipient_phone: order.recipient_phone ?? '',
        scheduled_for: order.scheduled_for ?? '',
        occasion_type: order.occasion_type ?? '',
        card_message: order.card_message ?? '',
        sender_name: order.sender_name ?? '',
        hide_sender: order.hide_sender,
        delivery_address: order.delivery_address ?? '',
        delivery_notes: order.delivery_notes ?? '',
        internal_notes: order.internal_notes ?? '',
    });
    const isDelivery = form.data.fulfillment_type === 'delivery';

    const occasionLabel = order.occasions.find((o) => o.value === order.occasion_type)?.label;
    const fulfillmentLabel = order.fulfillments.find((f) => f.value === order.fulfillment_type)?.label;
    // البطاقة تُعرض حين يكون فيها شيء — وطلبٌ قديم لا شيء فيه فلا تظهر
    const hasFulfillment = Boolean(
        order.fulfillment_type || order.scheduled_for || order.recipient_name || order.delivery_address,
    );
    const hasCard = Boolean(order.card_message || order.sender_name);

    const summary: { label: string; value: string; tone?: string }[] = [
        { label: 'المجموع الفرعي', value: m(order.subtotal) },
        { label: 'الخصم', value: `- ${m(order.discount)}`, tone: 'text-[#b91c1c]' },
        { label: 'الضريبة', value: m(order.tax) },
        { label: 'رسوم التوصيل', value: m(order.delivery) },
    ];

    return (
        <AdminLayout title="تفاصيل الطلب">
            <PageHeader
                title={`${t('الطلب')} ${order.id}`}
                subtitle={order.date}
                actions={
                    /* زرّ «فاتورة ضريبية» أُزيل من هنا بطلب صاحب النظام */
                    <Button variant="outline" asChild>
                        <a href={route('admin.orders.pdf', order.id)} target="_blank" rel="noreferrer">
                            <FileText />
                            {t('تصدير PDF')}
                        </a>
                    </Button>
                }
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <div>
                        <h3 className="mb-3 font-bold text-[#111]">{t('تفاصيل المنتجات')}</h3>
                        <Card className="overflow-hidden">
                            <Table>
                                <TableHeader>
                                    <TableRow className="hover:bg-transparent">
                                        <TableHead>{t('المنتج')}</TableHead>
                                        <TableHead className="text-end">{t('الكمية')}</TableHead>
                                        <TableHead className="text-end">{t('السعر')}</TableHead>
                                        <TableHead className="text-end">{t('الإجمالي')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {order.items.map((line, i) => (
                                        <TableRow key={i}>
                                            <TableCell className="font-medium text-[#111]">{line.name}</TableCell>
                                            <TableCell className="text-end tabular-nums">{number(line.qty)}</TableCell>
                                            <TableCell className="text-end tabular-nums text-[#4b4b4b]">
                                                {m(line.price)}
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums font-medium">
                                                {m(line.total)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Card>
                    </div>

                    <Card className="p-6">
                        <h3 className="mb-4 font-bold text-[#111]">{t('الملخص المالي')}</h3>
                        <dl className="space-y-3 text-sm">
                            {summary.map((row) => (
                                <div key={row.label} className="flex items-center justify-between">
                                    <dt className="text-[#6b7280]">{t(row.label)}</dt>
                                    <dd className={`font-medium tabular-nums ${row.tone ?? 'text-[#111]'}`}>
                                        {row.value}
                                    </dd>
                                </div>
                            ))}
                            <div className="flex items-center justify-between border-t border-[var(--ui-border,#e8e8e8)] pt-3">
                                <dt className="font-bold text-[#111]">{t('الإجمالي')}</dt>
                                <dd className="text-[18px] font-bold tabular-nums text-[#6d28d9]">{m(order.total)}</dd>
                            </div>
                        </dl>
                    </Card>
                </div>

                <div className="space-y-6">
                    <Card className="p-6">
                        <h3 className="mb-4 font-bold text-[#111]">{t('بيانات العميل')}</h3>
                        <div className="mb-4 flex items-center gap-3">
                            <span className="flex size-11 items-center justify-center rounded-full bg-[#f5f3ff] font-bold text-[#6d28d9]">
                                {order.customer.slice(0, 1)}
                            </span>
                            <div>
                                <p className="font-medium text-[#111]">{order.customer}</p>
                                <p className="text-[12px] text-[#9ca3af]">{order.branch || '—'}</p>
                            </div>
                        </div>
                        <dl className="space-y-2.5 text-sm text-[#4b4b4b]">
                            <div className="flex items-center gap-2">
                                <User className="size-4 text-[#9ca3af]" />
                                {t('الموظف')}: {order.employee}
                            </div>
                            <div className="flex items-center gap-2">
                                <Calendar className="size-4 text-[#9ca3af]" />
                                {order.date}
                            </div>
                        </dl>
                    </Card>

                    <Card className="p-6">
                        <h3 className="mb-4 font-bold text-[#111]">{t('حالة الدفع')}</h3>
                        <div className="mb-3 flex items-center justify-between">
                            <span className="text-sm text-[#6b7280]">{t('وسيلة الدفع')}</span>
                            <Badge status={order.payment}>{t(order.payment === 'بطاقة' ? 'فيزا' : order.payment)}</Badge>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-sm text-[#6b7280]">{t('حالة الدفع')}</span>
                            <Badge variant="success">{t(order.payment_status)}</Badge>
                        </div>
                    </Card>

                    {/*
                        التنفيذ: إلى من، ومتى، وإلى أين.
                        بطاقةٌ في العمود نفسه بنمط البطاقات التي حولها — لا تخطيط
                        جديد ولا مكوّن جديد.
                    */}
                    {(hasFulfillment || editing) && (
                        <Card className="p-6">
                            <div className="mb-4 flex items-center justify-between">
                                <h3 className="flex items-center gap-2 font-bold text-[#111]">
                                    <Truck className="size-4 text-[#9ca3af]" />
                                    {t('التنفيذ')}
                                </h3>
                                <Button variant="ghost" size="sm" onClick={() => setEditing((e) => !e)}>
                                    <PencilLine />
                                    {t(editing ? 'إلغاء' : 'تعديل')}
                                </Button>
                            </div>

                            {editing ? (
                                <form
                                    className="space-y-3"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        form.put(route('admin.orders.details.update', order.id), {
                                            preserveScroll: true,
                                            onSuccess: () => setEditing(false),
                                        });
                                    }}
                                >
                                    <Field label="نوع التنفيذ" error={form.errors.fulfillment_type}>
                                        <Select
                                            placeholder="—"
                                            options={order.fulfillments}
                                            value={form.data.fulfillment_type}
                                            onChange={(e) => form.setData('fulfillment_type', e.target.value)}
                                        />
                                    </Field>
                                    <Field label="موعد التسليم" error={form.errors.scheduled_for}>
                                        <Input
                                            type="datetime-local"
                                            value={form.data.scheduled_for}
                                            onChange={(e) => form.setData('scheduled_for', e.target.value)}
                                        />
                                    </Field>
                                    <Field label="اسم المستلِم" required={isDelivery} error={form.errors.recipient_name}>
                                        <Input
                                            value={form.data.recipient_name}
                                            onChange={(e) => form.setData('recipient_name', e.target.value)}
                                        />
                                    </Field>
                                    <Field label="هاتف المستلِم" required={isDelivery} error={form.errors.recipient_phone}>
                                        <Input
                                            inputMode="tel"
                                            value={form.data.recipient_phone}
                                            onChange={(e) => form.setData('recipient_phone', e.target.value)}
                                        />
                                    </Field>
                                    {isDelivery && (
                                        <>
                                            <Field label="عنوان التوصيل" required error={form.errors.delivery_address}>
                                                <Input
                                                    value={form.data.delivery_address}
                                                    onChange={(e) => form.setData('delivery_address', e.target.value)}
                                                />
                                            </Field>
                                            <Field label="تعليمات التوصيل" error={form.errors.delivery_notes}>
                                                <Input
                                                    value={form.data.delivery_notes}
                                                    onChange={(e) => form.setData('delivery_notes', e.target.value)}
                                                />
                                            </Field>
                                        </>
                                    )}
                                    <Field label="المناسبة" error={form.errors.occasion_type}>
                                        <Select
                                            placeholder="—"
                                            options={order.occasions}
                                            value={form.data.occasion_type}
                                            onChange={(e) => form.setData('occasion_type', e.target.value)}
                                        />
                                    </Field>
                                    <Field label="نصّ البطاقة" error={form.errors.card_message}>
                                        <textarea
                                            rows={2}
                                            value={form.data.card_message}
                                            onChange={(e) => form.setData('card_message', e.target.value)}
                                            className="w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2 text-sm transition-[border-color,box-shadow] focus:border-[#d1d5db] focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)] focus:outline-none"
                                        />
                                    </Field>
                                    <Field label="اسم المُهدي" error={form.errors.sender_name}>
                                        <Input
                                            value={form.data.sender_name}
                                            onChange={(e) => form.setData('sender_name', e.target.value)}
                                        />
                                    </Field>
                                    <Field label="إخفاء المُهدي" hint="لا يظهر للمستلِم">
                                        <label className="flex h-9 items-center gap-2 text-sm text-[#4b4b4b]">
                                            <input
                                                type="checkbox"
                                                checked={form.data.hide_sender}
                                                onChange={(e) => form.setData('hide_sender', e.target.checked)}
                                                className="size-4 accent-[#6d28d9]"
                                            />
                                            {t('إخفاء')}
                                        </label>
                                    </Field>
                                    <Field label="ملاحظات داخلية" hint="لا تُطبع للزبون" error={form.errors.internal_notes}>
                                        <Input
                                            value={form.data.internal_notes}
                                            onChange={(e) => form.setData('internal_notes', e.target.value)}
                                        />
                                    </Field>
                                    <Button type="submit" disabled={form.processing} className="w-full">
                                        {t('حفظ')}
                                    </Button>
                                </form>
                            ) : (
                                <dl className="space-y-2.5 text-sm text-[#4b4b4b]">
                                    {fulfillmentLabel && (
                                        <div className="flex items-center justify-between">
                                            <dt className="text-[#6b7280]">{t('نوع التنفيذ')}</dt>
                                            <dd className="font-medium text-[#111]">{t(fulfillmentLabel)}</dd>
                                        </div>
                                    )}
                                    {order.scheduled_for && (
                                        <div className="flex items-center justify-between">
                                            <dt className="text-[#6b7280]">{t('موعد التسليم')}</dt>
                                            <dd className="font-medium text-[#111]" dir="ltr">
                                                {order.scheduled_for.replace('T', ' ')}
                                            </dd>
                                        </div>
                                    )}
                                    {order.recipient_name && (
                                        <div className="flex items-center justify-between">
                                            <dt className="text-[#6b7280]">{t('المستلِم')}</dt>
                                            <dd className="font-medium text-[#111]">{order.recipient_name}</dd>
                                        </div>
                                    )}
                                    {order.recipient_phone && (
                                        <div className="flex items-center justify-between">
                                            <dt className="text-[#6b7280]">{t('هاتف المستلِم')}</dt>
                                            <dd className="font-medium text-[#111]" dir="ltr">{order.recipient_phone}</dd>
                                        </div>
                                    )}
                                    {order.occasion_type && occasionLabel && (
                                        <div className="flex items-center justify-between">
                                            <dt className="text-[#6b7280]">{t('المناسبة')}</dt>
                                            <dd className="font-medium text-[#111]">{t(occasionLabel)}</dd>
                                        </div>
                                    )}
                                    {order.delivery_address && (
                                        <div className="flex items-start gap-2 pt-1">
                                            <MapPin className="mt-0.5 size-4 shrink-0 text-[#9ca3af]" />
                                            <span>{order.delivery_address}</span>
                                        </div>
                                    )}
                                    {order.delivery_notes && (
                                        <p className="text-[12px] text-[#6b7280]">{order.delivery_notes}</p>
                                    )}
                                    {order.internal_notes && (
                                        <p className="rounded-[10px] bg-gray-50 p-2 text-[12px] text-[#6b7280]">
                                            {t('داخلي')}: {order.internal_notes}
                                        </p>
                                    )}
                                </dl>
                            )}
                        </Card>
                    )}

                    {/* بطاقة الإهداء — واسم المُهدي يُوسَم مخفيًّا حين طُلب إخفاؤه */}
                    {hasCard && !editing && (
                        <Card className="p-6">
                            <h3 className="mb-3 flex items-center gap-2 font-bold text-[#111]">
                                <Gift className="size-4 text-[#9ca3af]" />
                                {t('بطاقة الإهداء')}
                            </h3>
                            {order.card_message && (
                                <p className="text-sm leading-relaxed text-[#4b4b4b]">{order.card_message}</p>
                            )}
                            {order.sender_name && (
                                <p className="mt-2 text-[12px] text-[#9ca3af]">
                                    {t('من')}: {order.sender_name}
                                    {order.hide_sender && ` · ${t('مخفيّ عن المستلِم')}`}
                                </p>
                            )}
                        </Card>
                    )}

                    {/*
                        نقل الحالة — الخيارات من الخادم لا من قائمةٍ تُكتب هنا.
                        وحارسُ الانتقالات في الخادم؛ هذه تعرض ما يجوز وحده.
                    */}
                    {order.next_statuses.length > 0 && (
                        <Card className="p-6">
                            <h3 className="mb-3 font-bold text-[#111]">{t('حالة الطلب')}</h3>
                            <div className="mb-3 flex items-center justify-between">
                                <span className="text-sm text-[#6b7280]">{t('الحالة')}</span>
                                <Badge status={order.status}>{t(order.status)}</Badge>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {order.next_statuses.map((s) => (
                                    <Button
                                        key={s}
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                route('admin.orders.status', order.id),
                                                { status: s },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {t(s)}
                                    </Button>
                                ))}
                            </div>
                        </Card>
                    )}

                    {order.notes && (
                        <Card className="p-6">
                            <h3 className="mb-3 font-bold text-[#111]">{t('ملاحظات الطلب')}</h3>
                            <p className="text-sm leading-relaxed text-[#4b4b4b]">{order.notes}</p>
                        </Card>
                    )}

                    {/*
                        ما غُيّر في هذه الفاتورة بعد بيعها.
                        فاتورةٌ نقص إجماليّها تُسأل «لماذا؟» عند قراءتها، فالجواب
                        بجانبها لا في سجلّ نشاطٍ يُفتح بقصد ويُبحث فيه.
                    */}
                    {order.edits.length > 0 && (
                        <Card className="p-6">
                            <h3 className="mb-3 flex items-center gap-2 font-bold text-[#111]">
                                <PencilLine className="size-4 text-[#b45309]" />
                                {t('تصحيحات بعد البيع')}
                            </h3>
                            <ul className="flex flex-col gap-3 text-sm">
                                {order.edits.map((e, i) => (
                                    <li key={i} className="rounded-[10px] bg-[#fffbeb] p-3">
                                        <p className="font-medium text-[#111]">
                                            {e.kind === 'وسيلة دفع'
                                                ? `${t('وسيلة الدفع')}: ${t(e.value_before ?? '')} ← ${t(e.value_after ?? '')}`
                                                : e.qty_after === 0
                                                  ? `${t('حُذف')} «${e.subject}»`
                                                  : `«${e.subject}» ${e.qty_before} ← ${e.qty_after}`}
                                        </p>
                                        <p className="mt-0.5 text-[#92400e]">{e.reason}</p>
                                        <p className="mt-1 text-[12px] text-[#a16207]">
                                            {/* الإجمالي يُذكر حين يتغيّر — وسيلةُ الدفع لا تمسّه */}
                                            {e.total_before !== e.total_after && (
                                                <>
                                                    {money(e.total_before, currency)} ←{' '}
                                                    {money(e.total_after, currency)}
                                                    {' · '}
                                                </>
                                            )}
                                            {e.by} · <span dir="ltr">{e.at}</span>
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}
