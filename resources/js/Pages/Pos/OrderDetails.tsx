import { type FormEvent, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { ArrowRight, Pencil, Printer, Trash2 } from 'lucide-react';
import PosLayout from '@/Layouts/PosLayout';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import Field, { Select } from '@/Components/Field';
import { Input } from '@/Components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface OrderItem {
    id: number;
    product_id: number | null;
    name: string;
    price: number;
    qty: number;
    /** ثمن البند كاملًا — سعره في كميّته وإضافاته */
    total: number;
    note?: string | null;
    addons?: { name: string; qty: number; total: number }[];
}

interface OrderDetail {
    id: string;
    db_id: number;
    customer: string;
    employee: string;
    branch: string;
    status: string;
    payment: string;
    payment_status: string;
    date: string;
    subtotal: number;
    discount: number;
    tax: number;
    delivery: number;
    total: number;
    notes: string | null;
    items: OrderItem[];
    edits: OrderEdit[];
    payment_methods: string[];
}

/** أثرُ تصحيحٍ وقع على هذه الفاتورة — يُعرض ولا يُخفى */
interface OrderEdit {
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
}

export default function PosOrderDetails() {
    const { order, context } = usePage<PageProps<{ order: OrderDetail }>>().props;
    const t = useTranslate();
    const [editing, setEditing] = useState<OrderItem | null>(null);
    const [fixingPayment, setFixingPayment] = useState(false);
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);

    const rows: [string, string][] = [
        [t('العميل'), order.customer],
        [t('الكاشير'), order.employee],
        [t('الفرع'), order.branch],
        [t('التاريخ'), order.date],
    ];

    return (
        <PosLayout title={`${t('الطلب')} ${order.id}`}>
            <div className="mx-auto max-w-4xl p-4">
                <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-[20px] font-bold text-[#111]">
                            {t('الطلب')} {order.id}
                        </h1>
                        <div className="mt-1 flex items-center gap-2">
                            <Badge status={order.status} />
                            <Badge status={order.payment_status} />
                        </div>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <a href={route('pos.orders')}>
                                <ArrowRight className="ltr:rotate-180" />
                                {t('رجوع')}
                            </a>
                        </Button>
                        <Button asChild>
                            <a href={route('pos.receipt.pdf', order.id)} target="_blank" rel="noreferrer">
                                <Printer />
                                {t('طباعة الفاتورة')}
                            </a>
                        </Button>
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>{t('الأصناف')}</CardTitle>
                        </CardHeader>
                        <CardContent className="px-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{t('الصنف')}</TableHead>
                                        <TableHead className="text-center">{t('الكمية')}</TableHead>
                                        <TableHead className="text-end">{t('السعر')}</TableHead>
                                        <TableHead className="text-end">{t('الإجمالي')}</TableHead>
                                        <TableHead className="text-end">{t('تصحيح')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {order.items.map((it) => (
                                        <TableRow key={it.id}>
                                            <TableCell>
                                                <span className="font-medium">{it.name}</span>
                                                {(it.addons ?? []).map((a, i) => (
                                                    <span key={i} className="block text-[11px] text-[#7c3aed]">
                                                        + {a.name}
                                                        {a.qty > 1 && ` ×${a.qty}`}
                                                    </span>
                                                ))}
                                                {it.note && (
                                                    <span className="block text-[11px] text-gray-400">{it.note}</span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-center tabular-nums">{it.qty}</TableCell>
                                            <TableCell className="text-end tabular-nums">{m(it.price)}</TableCell>
                                            <TableCell className="text-end tabular-nums font-medium">{m(it.total)}</TableCell>
                                            <TableCell className="text-end">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    aria-label={t('تصحيح البند')}
                                                    onClick={() => setEditing(it)}
                                                >
                                                    <Pencil />
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <div className="flex flex-col gap-4">
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('التفاصيل')}</CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-2 text-[13px]">
                                {rows.map(([label, value]) => (
                                    <div key={label} className="flex items-center justify-between gap-2">
                                        <span className="text-gray-500">{label}</span>
                                        <span className="truncate font-medium text-[#111]">{value || '—'}</span>
                                    </div>
                                ))}
                                {/*
                                    وسيلة الدفع تُصحَّح كما تُصحَّح الكميّة: «نقدي»
                                    على دفعةٍ بالبطاقة يجعل الإقفال يطلب مالًا لم
                                    يدخل الدرج، ولا يظهر السبب في أيّ شاشة.
                                */}
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-gray-500">{t('وسيلة الدفع')}</span>
                                    <span className="flex items-center gap-1">
                                        <span className="truncate font-medium text-[#111]">
                                            {t(order.payment) || '—'}
                                        </span>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            aria-label={t('تصحيح وسيلة الدفع')}
                                            onClick={() => setFixingPayment(true)}
                                        >
                                            <Pencil />
                                        </Button>
                                    </span>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>{t('الملخص')}</CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-2 text-[13px]">
                                <div className="flex justify-between">
                                    <span className="text-gray-500">{t('المجموع الفرعي')}</span>
                                    <span className="tabular-nums">{m(order.subtotal)}</span>
                                </div>
                                {order.discount > 0 && (
                                    <div className="flex justify-between text-[#b91c1c]">
                                        <span>{t('الخصم')}</span>
                                        <span className="tabular-nums">- {m(order.discount)}</span>
                                    </div>
                                )}
                                <div className="flex justify-between">
                                    <span className="text-gray-500">{t('الضريبة')}</span>
                                    <span className="tabular-nums">{m(order.tax)}</span>
                                </div>
                                {order.delivery > 0 && (
                                    <div className="flex justify-between">
                                        <span className="text-gray-500">{t('التوصيل')}</span>
                                        <span className="tabular-nums">{m(order.delivery)}</span>
                                    </div>
                                )}
                                <div className="mt-1 flex justify-between border-t border-dashed border-gray-200 pt-2 text-[15px] font-bold">
                                    <span>{t('الإجمالي')}</span>
                                    <span className="tabular-nums">{m(order.total)}</span>
                                </div>
                            </CardContent>
                        </Card>

                        {order.notes && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>{t('ملاحظات')}</CardTitle>
                                </CardHeader>
                                <CardContent className="text-[13px] text-gray-600">{order.notes}</CardContent>
                            </Card>
                        )}
                    </div>
                </div>
            </div>

                {/*
                    سجلّ التصحيحات — يُعرض في الشاشة نفسها لا في مكانٍ بعيد.
                    من يقرأ فاتورةً نقص إجماليّها يسأل «لماذا؟» في اللحظة
                    نفسها، والجواب تحتها لا في تقريرٍ آخر.
                */}
                {order.edits.length > 0 && (
                    <Card className="mt-4">
                        <CardHeader>
                            <CardTitle>{t('تصحيحات على هذه الفاتورة')}</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3 text-[13px]">
                            {order.edits.map((e, i) => (
                                <div
                                    key={i}
                                    className="flex flex-col gap-1 rounded-[10px] bg-[#fafafa] p-3 sm:flex-row sm:items-start sm:justify-between"
                                >
                                    <div className="min-w-0">
                                        <p className="font-medium text-[#111]">{editLabel(e, t)}</p>
                                        <p className="text-gray-500">{e.reason}</p>
                                    </div>
                                    <div className="shrink-0 text-end text-[12px] text-gray-400">
                                        {e.total_before !== e.total_after && (
                                            <p className="tabular-nums">
                                                {m(e.total_before)} ← {m(e.total_after)}
                                            </p>
                                        )}
                                        <p>
                                            {e.by} · <span dir="ltr">{e.at}</span>
                                        </p>
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}

                {fixingPayment && (
                    <FixPaymentDialog
                        orderNumber={order.id}
                        current={order.payment}
                        methods={order.payment_methods}
                        onClose={() => setFixingPayment(false)}
                    />
                )}

                {editing && (
                    <EditItemDialog
                        orderNumber={order.id}
                        item={editing}
                        onClose={() => setEditing(null)}
                    />
                )}
        </PosLayout>
    );
}

function EditItemDialog({
    orderNumber,
    item,
    onClose,
}: {
    orderNumber: string;
    item: OrderItem;
    onClose: () => void;
}) {
    const t = useTranslate();
    const form = useForm({ quantity: String(item.qty), reason: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(route('pos.orders.items.update', [orderNumber, item.id]), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    const removing = Number(form.data.quantity) === 0;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {t('تصحيح')} «{item.name}»
                    </DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4 px-5 pb-5">
                    <Field
                        label="الكمية الصحيحة"
                        required
                        hint="صفرٌ يحذف البند من الفاتورة"
                        error={form.errors.quantity}
                    >
                        <Input
                            type="number"
                            min="0"
                            dir="ltr"
                            required
                            value={form.data.quantity}
                            onChange={(e) => form.setData('quantity', e.target.value)}
                        />
                    </Field>

                    {/* السبب مطلوب: تصحيحٌ بلا سببٍ سطرٌ لا يُدقَّق */}
                    <Field
                        label="سبب التصحيح"
                        required
                        hint="يُقرأ في سجلّ الفاتورة — اكتب ما يفهمه غيرك"
                        error={form.errors.reason}
                    >
                        <Input
                            required
                            minLength={3}
                            placeholder={t('مثال: أدخلتُ الكمية خطأً')}
                            value={form.data.reason}
                            onChange={(e) => form.setData('reason', e.target.value)}
                        />
                    </Field>

                    <p className="rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] text-[#92400e]">
                        {t('يُعاد المخزون وتُحتسب الضريبة والنقاط من جديد، ويبقى هذا التصحيح مقيَّدًا باسمك في سجلّ الفاتورة.')}
                    </p>

                    <div className="flex justify-end gap-2 pt-1">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            {removing ? <Trash2 /> : <Pencil />}
                            {t(removing ? 'حذف البند' : 'حفظ التصحيح')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * سطرُ التصحيح كما يُقرأ.
 *
 * نوعان في قائمةٍ واحدة تحت الفاتورة: بندٌ تغيّرت كميّته أو حُذف، ووسيلة
 * دفعٍ صُحّحت. وقائمتان منفصلتان كانتا ستجعلان القارئ يجمعهما بعينه ليعرف
 * ما جرى على فاتورةٍ واحدة.
 */
function editLabel(e: OrderEdit, t: (s: string) => string): string {
    if (e.kind === 'وسيلة دفع') {
        return `${t('وسيلة الدفع')}: ${t(e.value_before ?? '')} ← ${t(e.value_after ?? '')}`;
    }

    return e.qty_after === 0
        ? `${t('حُذف')} «${e.subject}»`
        : `«${e.subject}» ${e.qty_before} ← ${e.qty_after}`;
}

function FixPaymentDialog({
    orderNumber,
    current,
    methods,
    onClose,
}: {
    orderNumber: string;
    current: string;
    methods: string[];
    onClose: () => void;
}) {
    const t = useTranslate();
    const form = useForm({ payment_method: current, reason: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(route('pos.orders.payment.update', orderNumber), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('تصحيح وسيلة الدفع')}</DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4 px-5 pb-5">
                    <Field label="الوسيلة الصحيحة" required error={form.errors.payment_method}>
                        <Select
                            required
                            value={form.data.payment_method}
                            onChange={(e) => form.setData('payment_method', e.target.value)}
                            options={methods.map((x) => ({ label: t(x === 'بطاقة' ? 'فيزا' : x), value: x }))}
                        />
                    </Field>

                    <Field
                        label="سبب التصحيح"
                        required
                        hint="يُقرأ في سجلّ الفاتورة — اكتب ما يفهمه غيرك"
                        error={form.errors.reason}
                    >
                        <Input
                            required
                            minLength={3}
                            placeholder={t('مثال: دفع بالبطاقة وسجّلتُها نقدًا')}
                            value={form.data.reason}
                            onChange={(e) => form.setData('reason', e.target.value)}
                        />
                    </Field>

                    <p className="rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] text-[#92400e]">
                        {t('يتغيّر المتوقَّع في درج ورديتك المفتوحة فورًا. والورديات المقفلة تبقى على أرقامها — عدُّها وقع يومه.')}
                    </p>

                    <div className="flex justify-end gap-2 pt-1">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            <Pencil />
                            {t('حفظ التصحيح')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
