import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import {
    Calendar,
    ClipboardList,
    FileText,
    Gift,
    MapPin,
    PencilLine,
    Phone,
    Receipt,
    Truck,
    User,
} from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Tabs from '@/Components/Tabs';
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

/**
 * صفحة الطلب — قسمان لا كومةٌ واحدة.
 *
 * كانت البطاقات الستّ تتراكم في عمودٍ واحد: بيانات العميل، ووسيلة الدفع،
 * والتنفيذ، والبطاقة، والحالة، والتصحيحات — يقرؤها المحاسب باحثًا عن مبلغ
 * فيمرّ على نصّ بطاقة إهداء، ويقرؤها من يجهّز الباقة باحثًا عن العنوان فيمرّ
 * على الضريبة. وهما قارئان مختلفان لحاجتين مختلفتين.
 *
 * فصارت تبويبين: **بيانات الطلب** — ما بيع وبكم ولمن ومتى وبأي حالة. و**ورقة
 * التفاصيل** — الورقة التي تُنفَّذ منها: إلى من تصل، ومتى، وإلى أين، وبأي
 * بطاقة. وتُعدَّل من مكانها.
 *
 * والتعديل لا يمسّ مالًا ولا حالة: `OrderDetailController::update` يكتب حقول
 * التنفيذ وحدها ولا يعيد حسبة إجمالي، والحالة بابها الآخر في تبويب البيانات.
 * فمن يصحّح رقم هاتفٍ لا يُحرّك ريالًا ولا يُقدّم طلبًا في مساره.
 */
export default function OrderShow() {
    const { order, context } = usePage<PageProps<{ order: OrderDetail }>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);

    const [tab, setTab] = useState<'data' | 'sheet'>('data');

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
    const hasErrors = Object.keys(form.errors).length > 0;

    const occasionLabel = order.occasions.find((o) => o.value === order.occasion_type)?.label;
    const fulfillmentLabel = order.fulfillments.find((f) => f.value === order.fulfillment_type)?.label;

    /*
     * ورقةٌ فارغة تُقال فارغةً ولا تُخفى.
     *
     * كانت البطاقة تختفي كلّها حين لا شيء فيها، فطلبٌ قديم بلا تفاصيل لا
     * يعرف صاحبه أن للتفاصيل موضعًا أصلًا — ولا كيف يضيفها.
     */
    const hasSheet = Boolean(
        order.fulfillment_type ||
            order.scheduled_for ||
            order.recipient_name ||
            order.recipient_phone ||
            order.delivery_address ||
            order.card_message ||
            order.sender_name ||
            order.internal_notes,
    );

    const summary: { label: string; value: string; tone?: string }[] = [
        { label: 'المجموع الفرعي', value: m(order.subtotal) },
        { label: 'الخصم', value: `- ${m(order.discount)}`, tone: 'text-[#b91c1c]' },
        { label: 'الضريبة', value: m(order.tax) },
        { label: 'رسوم التوصيل', value: m(order.delivery) },
    ];

    /** سطرٌ في الورقة: عنوانٌ خافت وقيمةٌ ظاهرة — والفارغ يُكتب شرطة لا يُحذف */
    const Row = ({ label, value, ltr }: { label: string; value: string | null; ltr?: boolean }) => (
        <div className="flex items-start justify-between gap-4 py-2">
            <dt className="shrink-0 text-[13px] text-[#6b7280]">{t(label)}</dt>
            <dd
                className={`text-end text-sm font-medium ${value ? 'text-[#111]' : 'text-[#9ca3af]'}`}
                dir={ltr && value ? 'ltr' : undefined}
            >
                {value || '—'}
            </dd>
        </div>
    );

    /** كتلةٌ في الورقة: عنوانٌ بأيقونة وما تحته */
    const Block = ({
        icon: Icon,
        title,
        children,
    }: {
        icon: typeof Truck;
        title: string;
        children: React.ReactNode;
    }) => (
        <div className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-4">
            <h4 className="mb-1 flex items-center gap-2 text-[13px] font-bold text-[#111]">
                <Icon className="size-4 text-[#9ca3af]" />
                {t(title)}
            </h4>
            <dl className="divide-y divide-[var(--ui-border,#e8e8e8)]">{children}</dl>
        </div>
    );

    return (
        <AdminLayout title="تفاصيل الطلب">
            <PageHeader
                title={`${t('الطلب')} ${order.id}`}
                subtitle={order.date}
                actions={
                    /* زرّ «فاتورة ضريبية» أُزيل من هنا بطلب صاحب النظام */
                    <>
                        <Button variant="outline" asChild>
                            <a href={route('admin.orders.pdf', order.id)} target="_blank" rel="noreferrer">
                                <FileText />
                                {t('تصدير PDF')}
                            </a>
                        </Button>
                        {/*
                            وسندُ التسليم ورقةٌ أخرى لا نسخةٌ من الفاتورة:
                            يحملها السائق، ويوقّعها المستلم، وقالبُها يُخفي
                            الأسعار — فلا يرى من استلم الهديّة ثمنَها.
                        */}
                        <Button variant="outline" asChild>
                            <a href={route('admin.orders.deliveryNote', order.id)} target="_blank" rel="noreferrer">
                                <Truck />
                                {t('سند تسليم')}
                            </a>
                        </Button>
                    </>
                }
            />

            {/*
                التبويب لا يُخفي خطأً: حين يردّ الخادم خطأ تحقّقٍ على حقلٍ في
                الورقة ونحن في تبويب البيانات، تُوضع نقطة على تبويب الورقة —
                وإلا بدا الحفظ كأنه لم يستجب.
            */}
            <Tabs
                className="mb-6"
                current={tab}
                onChange={(k) => setTab(k as 'data' | 'sheet')}
                tabs={[
                    { key: 'data', label: 'بيانات الطلب', icon: Receipt },
                    { key: 'sheet', label: 'ورقة التفاصيل', icon: ClipboardList, alert: hasErrors },
                ]}
            />

            {tab === 'data' ? (
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
                                                <TableCell className="text-end tabular-nums">
                                                    {number(line.qty)}
                                                </TableCell>
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
                                    <dd className="text-[18px] font-bold tabular-nums text-[#6d28d9]">
                                        {m(order.total)}
                                    </dd>
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
                                <Badge status={order.payment}>
                                    {t(order.payment === 'بطاقة' ? 'فيزا' : order.payment)}
                                </Badge>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-sm text-[#6b7280]">{t('حالة الدفع')}</span>
                                <Badge variant="success">{t(order.payment_status)}</Badge>
                            </div>
                        </Card>

                        {/*
                            نقل الحالة — الخيارات من الخادم لا من قائمةٍ تُكتب هنا.
                            وحارسُ الانتقالات في الخادم؛ هذه تعرض ما يجوز وحده.

                            وموضعها هنا لا في الورقة عمدًا: تعديل التفاصيل لا يُقدّم
                            الطلب في مساره، فلا يُوضع البابان في يدٍ واحدة.
                        */}
                        <Card className="p-6">
                            <h3 className="mb-3 font-bold text-[#111]">{t('حالة الطلب')}</h3>
                            <div className="mb-3 flex items-center justify-between">
                                <span className="text-sm text-[#6b7280]">{t('الحالة')}</span>
                                <Badge status={order.status}>{t(order.status)}</Badge>
                            </div>
                            {order.next_statuses.length > 0 && (
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
                            )}
                        </Card>

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
            ) : (
                <Card className="p-6">
                    <div className="mb-5 flex flex-wrap items-start justify-between gap-3 border-b border-[var(--ui-border,#e8e8e8)] pb-4">
                        <div>
                            <h3 className="flex items-center gap-2 font-bold text-[#111]">
                                <ClipboardList className="size-4 text-[#9ca3af]" />
                                {t('ورقة تفاصيل الطلب')}
                            </h3>
                            {/* الحدّ يُقال قبل الضغط لا بعده: من يفتح «تعديل» باحثًا عن
                                المبلغ يجب أن يعرف أنه ليس هنا */}
                            <p className="mt-1 text-[12px] text-[#9ca3af]">
                                {t('بيانات التنفيذ وحدها — لا تمسّ المبالغ ولا حالة الطلب')}
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            <Badge status={order.status}>{t(order.status)}</Badge>
                            <Button variant="ghost" size="sm" onClick={() => setEditing((e) => !e)}>
                                <PencilLine />
                                {t(editing ? 'إلغاء' : 'تعديل')}
                            </Button>
                        </div>
                    </div>

                    {editing ? (
                        <form
                            className="grid grid-cols-1 gap-4 md:grid-cols-2"
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
                            <Field label="اسم المُهدي" error={form.errors.sender_name}>
                                <Input
                                    value={form.data.sender_name}
                                    onChange={(e) => form.setData('sender_name', e.target.value)}
                                />
                            </Field>
                            <div className="md:col-span-2">
                                <Field label="نصّ البطاقة" error={form.errors.card_message}>
                                    <textarea
                                        rows={3}
                                        value={form.data.card_message}
                                        onChange={(e) => form.setData('card_message', e.target.value)}
                                        className="w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2 text-sm transition-[border-color,box-shadow] focus:border-[#d1d5db] focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)] focus:outline-none"
                                    />
                                </Field>
                            </div>
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

                            <div className="flex items-center gap-2 md:col-span-2">
                                <Button type="submit" disabled={form.processing}>
                                    {t('حفظ')}
                                </Button>
                                <Button type="button" variant="outline" onClick={() => setEditing(false)}>
                                    {t('إلغاء')}
                                </Button>
                            </div>
                        </form>
                    ) : hasSheet ? (
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <Block icon={Truck} title="التنفيذ والموعد">
                                <Row label="نوع التنفيذ" value={fulfillmentLabel ? t(fulfillmentLabel) : null} />
                                <Row
                                    label="موعد التسليم"
                                    value={order.scheduled_for?.replace('T', ' ') ?? null}
                                    ltr
                                />
                            </Block>

                            <Block icon={Phone} title="المستلِم">
                                <Row label="الاسم" value={order.recipient_name} />
                                <Row label="الهاتف" value={order.recipient_phone} ltr />
                            </Block>

                            <Block icon={MapPin} title="العنوان والتعليمات">
                                <Row label="عنوان التوصيل" value={order.delivery_address} />
                                <Row label="تعليمات التوصيل" value={order.delivery_notes} />
                            </Block>

                            <Block icon={Gift} title="المناسبة والبطاقة">
                                <Row label="المناسبة" value={occasionLabel ? t(occasionLabel) : null} />
                                <Row
                                    label="اسم المُهدي"
                                    value={
                                        order.sender_name
                                            ? order.hide_sender
                                                ? `${order.sender_name} · ${t('مخفيّ عن المستلِم')}`
                                                : order.sender_name
                                            : null
                                    }
                                />
                                {order.card_message && (
                                    <div className="pt-3">
                                        <p className="mb-1 text-[13px] text-[#6b7280]">{t('نصّ البطاقة')}</p>
                                        <p className="rounded-[10px] bg-[#faf5ff] p-3 text-sm leading-relaxed text-[#4b4b4b]">
                                            {order.card_message}
                                        </p>
                                    </div>
                                )}
                            </Block>

                            {order.internal_notes && (
                                <div className="md:col-span-2">
                                    <p className="rounded-[10px] bg-gray-50 p-3 text-[13px] text-[#6b7280]">
                                        {t('ملاحظات داخلية')} · {t('لا تُطبع للزبون')}: {order.internal_notes}
                                    </p>
                                </div>
                            )}
                        </div>
                    ) : (
                        /* طلبٌ قديم بلا تفاصيل: يُقال له أين تُضاف بدل أن يختفي الباب */
                        <div className="py-10 text-center">
                            <ClipboardList className="mx-auto mb-3 size-8 text-[#d1d5db]" />
                            <p className="text-sm text-[#6b7280]">{t('لا تفاصيل تنفيذ لهذا الطلب بعد')}</p>
                            <Button className="mt-4" variant="outline" onClick={() => setEditing(true)}>
                                <PencilLine />
                                {t('أضِف التفاصيل')}
                            </Button>
                        </div>
                    )}
                </Card>
            )}
        </AdminLayout>
    );
}
