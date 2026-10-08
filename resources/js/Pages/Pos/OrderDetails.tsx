import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { ArrowRight, Pencil, Plus, Printer, Repeat } from 'lucide-react';
import PosLayout from '@/Layouts/PosLayout';
import ReceiptPreviewButton from './partials/ReceiptPreview';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import {
    CollectDialog,
    CorrectItemDialog,
    CorrectPaymentDialog,
    LineDialog,
    NoteDialog,
    correctionLabel,
    type LineEdit,
    type OrderEditRecord,
} from '@/Components/InvoiceCorrection';
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
    /** بندُ كرت هدية: نصُّه رسالةٌ لا ملاحظةُ منتج (`GiftCardProduct::cardLine`) */
    card_line?: boolean;
    addons?: { name: string; qty: number; total: number }[];
    /** وصفُ الطلب المخصَّص كما بيع — `null` لبند الكتالوج */
    custom?: {
        template: string | null;
        base_label: string | null;
        base_value: number | null;
        fields: { label: string; internal: boolean; values: string[] }[];
    } | null;
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
    /* والملاحظاتُ الأخرى كلٌّ باسمها — لا «ملاحظات» عامّة */
    delivery_notes?: string | null;
    internal_notes?: string | null;
    card_message?: string | null;
    balance_due?: number;
    items: OrderItem[];
    edits: OrderEditRecord[];
    payment_methods: string[];
}

export default function PosOrderDetails() {
    const { order, context, canEdit, lineEdit } = usePage<
        PageProps<{ order: OrderDetail; canEdit: boolean; lineEdit?: LineEdit | null }>
    >().props;
    const t = useTranslate();
    const [editing, setEditing] = useState<OrderItem | null>(null);
    const [fixingPayment, setFixingPayment] = useState(false);
    const [adding, setAdding] = useState(false);
    const [replacing, setReplacing] = useState<OrderItem | null>(null);
    const [noting, setNoting] = useState<OrderItem | null>(null);
    const [collecting, setCollecting] = useState(false);
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
                        {/*
                            إلى نقطة البيع — لا إلى «الطلبات المعلّقة».
                            
                            كانت تُردّ إلى شاشة المعلّقة أيًّا كان الطريق الذي
                            جاء منه الكاشير: يفتح فاتورةً من «العملاء» أو من
                            «المدفوعات» فيجد نفسه في قائمةِ سلالٍ لم تُبَع بعد،
                            ولا شيء يقول له كيف وصل. ولا يصل هذه الصفحةَ أحدٌ
                            من المعلّقة أصلًا — فالوجهةُ كانت خطأً لكلّ من يفتحها.

                            والاسم يقول الوجهة لا «رجوع»: زرٌّ مكتوبٌ عليه
                            «رجوع» يَعِد بالمكان الذي جاء منه، وهذا يذهب إلى
                            الصندوق دائمًا — وهو بيت الكاشير.
                        */}
                        <Button variant="outline" asChild>
                            <a href={route('pos.index')}>
                                <ArrowRight className="ltr:rotate-180" />
                                {t('نقطة البيع')}
                            </a>
                        </Button>
                        {/* والورقةُ تُرى في مكانها — انظر `partials/ReceiptPreview` */}
                        <ReceiptPreviewButton number={order.id} />
                        {/* وإضافةُ صنفٍ بعد الإصدار — لنشاطٍ فُتحت له ولمن يملك «order.edit» */}
                        {lineEdit?.lines && (
                            <Button variant="outline" onClick={() => setAdding(true)} data-testid="add-line">
                                <Plus />
                                {t('إضافة صنف')}
                            </Button>
                        )}
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
                                        {canEdit && <TableHead className="text-end">{t('تصحيح')}</TableHead>}
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
                                                {/* وملاحظةُ البند باسمها — ورسالةُ الكرت باسمها هي. والنصُّ كما كُتب */}
                                                {it.note && (
                                                    <span className="block text-[11px] text-gray-500" data-testid="item-note">
                                                        {t(it.card_line ? 'رسالة الكرت' : 'ملاحظة المنتج')}:{' '}
                                                        <span dir="auto" className="whitespace-pre-wrap text-gray-600">{it.note}</span>
                                                    </span>
                                                )}
                                                {lineEdit?.notes && !it.card_line && (
                                                    <button
                                                        type="button"
                                                        className="block text-[11px] text-[#6d28d9] underline"
                                                        onClick={() => setNoting(it)}
                                                        data-testid="edit-item-note"
                                                    >
                                                        {t(it.note ? 'تعديل ملاحظة المنتج' : 'إضافة ملاحظة المنتج')}
                                                    </button>
                                                )}
                                                {/*
                                                    وما اختاره الزبونُ تحت اسم بنده — كما في شاشة الطلبات.
                                                    الكاشيرُ يُسأل عند التسليم «أيُّ لونٍ طلبتُ؟» وهو أقربُ
                                                    من صاحب المتجر إلى السؤال.
                                                */}
                                                {(it.custom?.fields ?? []).map((f, i) => (
                                                    <span key={i} className="block text-[11px] text-gray-500">
                                                        {f.label}: {f.values.join(' · ')}
                                                    </span>
                                                ))}
                                            </TableCell>
                                            <TableCell className="text-center tabular-nums">{it.qty}</TableCell>
                                            <TableCell className="text-end tabular-nums">{m(it.price)}</TableCell>
                                            <TableCell className="text-end tabular-nums font-medium">{m(it.total)}</TableCell>
                                            {canEdit && (
                                                <TableCell className="text-end">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        aria-label={t('تصحيح البند')}
                                                        onClick={() => setEditing(it)}
                                                    >
                                                        <Pencil />
                                                    </Button>
                                                    {lineEdit?.lines && !it.card_line && (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            aria-label={t('استبدال الصنف')}
                                                            onClick={() => setReplacing(it)}
                                                            data-testid="replace-line"
                                                        >
                                                            <Repeat />
                                                        </Button>
                                                    )}
                                                </TableCell>
                                            )}
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
                                        {canEdit && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                aria-label={t('تصحيح وسيلة الدفع')}
                                                onClick={() => setFixingPayment(true)}
                                            >
                                                <Pencil />
                                            </Button>
                                        )}
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
                                {/* وما بقي على العميل من فاتورةٍ مدفوعة ثمّ زادت — يُحصَّل بقيدٍ بيومه */}
                                {(order.balance_due ?? 0) > 0 && (
                                    <div className="flex items-center justify-between gap-2 text-[#b45309]" data-testid="balance-due">
                                        <span>{t('المتبقّي على العميل')}</span>
                                        <span className="flex items-center gap-2">
                                            <span className="tabular-nums font-semibold">{m(order.balance_due ?? 0)}</span>
                                            {lineEdit?.notes && (
                                                <Button variant="outline" size="sm" onClick={() => setCollecting(true)}>
                                                    {t('تحصيل المتبقّي')}
                                                </Button>
                                            )}
                                        </span>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/*
                            والملاحظاتُ كلٌّ باسمها — لا يُخمَّن مصدرُها: ما كتبه العميل
                            على الطلب، وتعليماتُ التوصيل، والداخليّةُ للمحلّ، ورسالةُ الكرت.
                            والعنوانُ بلغة الشاشة، والنصُّ كما كُتب.
                        */}
                        {[
                            ['ملاحظات الطلب', order.notes, 'order-notes'],
                            ['تعليمات التوصيل', order.delivery_notes, 'delivery-notes'],
                            ['ملاحظات داخلية', order.internal_notes, 'internal-notes'],
                            ['رسالة الكرت', order.card_message, 'card-message'],
                        ]
                            .filter(([, text]) => Boolean(text))
                            .map(([label, text, id]) => (
                                <Card key={id as string} data-testid={id as string}>
                                    <CardHeader>
                                        <CardTitle>{t(label as string)}</CardTitle>
                                    </CardHeader>
                                    <CardContent className="whitespace-pre-wrap text-[13px] text-gray-600" dir="auto">
                                        {text}
                                    </CardContent>
                                </Card>
                            ))}
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
                                        <p className="font-medium text-[#111]">{correctionLabel(e, t)}</p>
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
                    <CorrectPaymentDialog
                        url={route('pos.orders.payment.update', order.id)}
                        current={order.payment}
                        methods={order.payment_methods}
                        onClose={() => setFixingPayment(false)}
                    />
                )}

                {editing && (
                    <CorrectItemDialog
                        url={route('pos.orders.items.update', [order.id, editing.id])}
                        item={editing}
                        onClose={() => setEditing(null)}
                    />
                )}

                {/* وإضافةُ صنفٍ واستبدالُه وملاحظتُه وتحصيلُ المتبقّي — من نوافذ شاشة الطلبات نفسِها */}
                {adding && lineEdit && (
                    <LineDialog
                        url={route('pos.orders.items.store', order.id)}
                        title={t('إضافة صنف إلى الفاتورة')}
                        catalog={lineEdit.catalog}
                        paid={order.payment_status !== 'غير مدفوع'}
                        methods={order.payment_methods}
                        format={m}
                        onClose={() => setAdding(false)}
                    />
                )}
                {replacing && lineEdit && (
                    <LineDialog
                        url={route('pos.orders.items.replace', [order.id, replacing.id])}
                        title={t('استبدال الصنف')}
                        catalog={lineEdit.catalog}
                        paid={order.payment_status !== 'غير مدفوع'}
                        methods={order.payment_methods}
                        replacing={replacing.name}
                        format={m}
                        onClose={() => setReplacing(null)}
                    />
                )}
                {noting && (
                    <NoteDialog
                        url={route('pos.orders.items.note', [order.id, noting.id])}
                        item={{ name: noting.name, note: noting.note ?? null }}
                        onClose={() => setNoting(null)}
                    />
                )}
                {collecting && (
                    <CollectDialog
                        url={route('pos.orders.balance.collect', order.id)}
                        amount={m(order.balance_due ?? 0)}
                        methods={order.payment_methods}
                        onClose={() => setCollecting(false)}
                    />
                )}
        </PosLayout>
    );
}
