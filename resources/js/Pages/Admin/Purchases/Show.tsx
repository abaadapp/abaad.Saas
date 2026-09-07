import { useState, type ReactNode } from 'react';
import { router, usePage } from '@inertiajs/react';
import { FileText, PackageCheck, Paperclip, Printer, Trash2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import BackLink from '@/Components/BackLink';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Line {
    id: number;
    name: string;
    purchase_unit: string | null;
    units_per_purchase_unit: number;
    quantity: number;
    received: number;
    /** ما سُجّل ولم يُعتمد بعد — لا هو مستلَمٌ ولا هو متاحٌ للتسجيل ثانية */
    pending: number;
    remaining: number;
    /** ما يدخل الرفَّ فعلًا: الكمية × محتوى الوحدة */
    base_quantity: number;
    cost: number;
    line_total: number;
}

interface Order {
    id: number;
    number: string;
    status: string;
    supplier: string;
    supplier_reference: string | null;
    branch: string | null;
    notes: string | null;
    ordered_at: string | null;
    expected_delivery_at: string | null;
    received_at: string | null;
    items_subtotal: number;
    supplier_discount: number;
    shipping_cost: number;
    tax: number;
    tax_rate: number;
    total: number;
    has_attachment: boolean;
    attachment: string | null;
    attachment_name: string | null;
    has_receipt: boolean;
    receipt: string | null;
    receipt_name: string | null;
    items: Line[];
}

interface Receipt {
    id: number;
    number: string;
    status: string;
    received_at: string | null;
    receiver: string | null;
    quantity: number;
    lines: number;
    rejection_reason: string | null;
    pdf: string;
}

interface Invoice {
    id: number;
    reference: string;
    approval_status: string;
    status: string;
    issued_at: string | null;
    due_at: string | null;
    total: number;
    paid: number;
    outstanding: number;
    match_status: string | null;
}

interface Event {
    at: string;
    kind: string;
    title: string;
    detail: string | null;
    actor: string | null;
}

interface Props {
    order: Order;
    receipts: Receipt[];
    invoices: Invoice[];
    timeline: Event[];
    can: { receive: boolean; delete: boolean };
}

/** لونُ نقطة الحدث — الاعتمادُ أخضرُ والرفضُ أحمر، ولا يُقرأ الخطُّ بالنصّ وحده */
const DOT: Record<string, string> = {
    draft: '#9ca3af',
    created: '#6d28d9',
    receipt: '#2563eb',
    invoice: '#6d28d9',
    approved: '#059669',
    rejected: '#dc2626',
    cancelled: '#dc2626',
};

/**
 * أمرُ شراءٍ واحد — بنودُه، وما وصل منه، وأوراقُه، وخطُّه الزمنيّ.
 *
 * وهي شاشةُ قراءةٍ ومقبضين: تسجيلُ استلامٍ على البابِ القائم، وحذفُ أمرٍ لم
 * يقع منه شيء. ولا مقبضَ ثالث يُرسم على ما يردّه الخادم.
 */
export default function PurchaseOrderShow() {
    const { order, receipts, invoices, timeline, can, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const [receiving, setReceiving] = useState(false);
    const [deleting, setDeleting] = useState(false);

    /*
     * ما يُتاح تسجيلُه من كلّ بند: المتبقّي ناقصَ ما ينتظر الاعتماد.
     *
     * والمتبقّي وحده يكذب: ورقةٌ سُجّلت ولم تُعتمد بعد لم تُنقص `received`،
     * فمن يقرأ المتبقّي يسجّل الشحنةَ نفسَها مرّتين — وتدخل الرفَّ مرّتين
     * حين تُعتمد الورقتان.
     */
    const available = (line: Line) => Math.max(0, line.remaining - line.pending);
    const openable = order.items.some((i) => available(i) > 0);

    const [taking, setTaking] = useState<Record<number, string>>({});

    const openReceive = () => {
        setTaking(Object.fromEntries(order.items.map((i) => [i.id, String(available(i))])));
        setReceiving(true);
    };

    const submitReceive = () => {
        router.post(
            route('admin.purchases.receive', order.id),
            {
                items: order.items.map((i) => ({
                    id: i.id,
                    // حقلٌ فُرّغ يعني «لم يصل منه شيء» لا «وصل كلّه»
                    quantity: Number(taking[i.id] ?? 0) || 0,
                })),
            },
            { preserveScroll: true, onFinish: () => setReceiving(false) },
        );
    };

    const takingTotal = order.items.reduce((sum, i) => sum + (Number(taking[i.id]) || 0), 0);

    return (
        <AdminLayout title={order.number}>
            {/* الرجوعُ طريقٌ لا فعل: فوق الترويسة لا بين أزرارها */}
            <BackLink
                routeName="admin.purchases.orders"
                href={route('admin.purchases.orders')}
                label="كل أوامر الشراء"
            />

            <PageHeader
                title={order.number}
                subtitle={`${order.supplier} — ${t(order.status)}`}
                actions={
                    <>
                        {can.receive && openable && (
                            <Button variant="success" onClick={openReceive}>
                                <PackageCheck />
                                {t('تسجيل استلام')}
                            </Button>
                        )}

                        {/* مرفقُ الأمر: عرضُ سعرٍ أو مستندُ طلب — لا إيصالُ دفع */}
                        {order.attachment && (
                            <Button variant="outline" asChild>
                                <a href={order.attachment} target="_blank" rel="noreferrer">
                                    <Paperclip />
                                    {order.attachment_name || t('مرفق الأمر')}
                                </a>
                            </Button>
                        )}

                        <Button variant="outline" asChild>
                            <a href={route('admin.purchases.pdf', order.id)} target="_blank" rel="noreferrer">
                                <Printer />
                                {t('طباعة أمر الشراء')}
                            </a>
                        </Button>

                        {can.delete && (
                            <Button variant="outline" className="text-[#b91c1c]" onClick={() => setDeleting(true)}>
                                <Trash2 />
                                {t('حذف')}
                            </Button>
                        )}
                    </>
                }
            />

            {/*
                ومرفقٌ موجودٌ لا يُقرأ يُقال، لا يُكتم.
                من لا يملك فتحَ المرفقات لا يُبنى له رابط — وصمتُ الشاشة عنه
                يجعله يظنّ أنّ الأمر بلا مستند.
            */}
            {order.has_attachment && !order.attachment && (
                <Card className="mb-4 border-[#fde68a] bg-[#fffbeb] p-3 text-[13px] text-[#92400e]">
                    {t('لهذا الأمر مرفق — وفتحُ المرفقات صلاحيةٌ لا تملكها')}
                </Card>
            )}

            <div className="mb-4 grid gap-4 lg:grid-cols-3">
                <Card className="space-y-2 p-4 text-[13px] lg:col-span-2">
                    <Row label={t('المورّد')} value={order.supplier} />
                    <Row label={t('الفرع')} value={order.branch || '—'} />
                    <Row label={t('الحالة')} value={<Badge status={order.status}>{t(order.status)}</Badge>} />
                    <Row label={t('تاريخ الطلب')} value={<span dir="ltr">{order.ordered_at || '—'}</span>} />
                    <Row
                        label={t('تاريخ الوصول المتوقّع')}
                        value={<span dir="ltr">{order.expected_delivery_at || '—'}</span>}
                    />
                    {order.received_at && (
                        <Row label={t('تاريخ الاستلام')} value={<span dir="ltr">{order.received_at}</span>} />
                    )}
                    {order.supplier_reference && (
                        <Row label={t('مرجع المورّد')} value={order.supplier_reference} />
                    )}
                    {order.notes && <Row label={t('ملاحظات')} value={order.notes} />}
                    {/* إيصالُ دفعٍ قديم: لا يُرفع من هنا بعد اليوم، ويُقرأ ما رُفع */}
                    {order.receipt && (
                        <Row
                            label={t('إيصال الدفع')}
                            value={
                                <a
                                    href={order.receipt}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex items-center gap-1 text-[#6d28d9] hover:underline"
                                >
                                    <FileText className="size-3.5" />
                                    {order.receipt_name || t('عرض')}
                                </a>
                            }
                        />
                    )}
                </Card>

                <Card className="space-y-2 p-4 text-[13px]">
                    <Row label={t('مجموع الأصناف')} value={m(order.items_subtotal)} />
                    {order.supplier_discount > 0 && (
                        <Row label={t('خصم المورد')} value={`− ${m(order.supplier_discount)}`} />
                    )}
                    {order.shipping_cost > 0 && <Row label={t('الشحن')} value={m(order.shipping_cost)} />}
                    {order.tax > 0 && (
                        <Row label={`${t('الضريبة')} (${number(order.tax_rate, 2)}%)`} value={m(order.tax)} />
                    )}
                    <Row label={t('الإجمالي')} value={m(order.total)} strong />
                </Card>
            </div>

            <Card className="mb-4 overflow-x-auto p-4">
                <h2 className="mb-3 font-bold text-[#111]">{t('البنود')}</h2>
                <table className="w-full min-w-[720px] text-[13px]">
                    <thead className="text-[12px] text-[#71717a]">
                        <tr>
                            <th className="p-2 text-start">{t('الصنف')}</th>
                            <th className="p-2 text-start">{t('وحدة الشراء')}</th>
                            <th className="p-2 text-end">{t('المطلوب')}</th>
                            <th className="p-2 text-end">{t('المستلَم')}</th>
                            <th className="p-2 text-end">{t('قيد الاعتماد')}</th>
                            <th className="p-2 text-end">{t('المتبقّي')}</th>
                            <th className="p-2 text-end">{t('تكلفة الوحدة')}</th>
                            <th className="p-2 text-end">{t('الإجمالي')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {order.items.map((i) => (
                            <tr key={i.id} className="border-t border-[var(--ui-border,#e8e8e8)]">
                                <td className="p-2 font-medium text-[#111]">{i.name}</td>
                                <td className="p-2 text-[#6b7280]">
                                    {i.purchase_unit || '—'}
                                    {/*
                                        ومحتوى الوحدة يُقال حين يزيد على واحد.
                                        «٣ صناديق» لا تقول كم يدخل الرفّ، و«٣٦ ساقًا»
                                        هي ما يراه من يجرد المخزون.
                                    */}
                                    {i.units_per_purchase_unit > 1 && (
                                        <span className="ms-1 text-[11px] text-[#9ca3af]">
                                            × {number(i.units_per_purchase_unit, 2)} ={' '}
                                            {number(i.base_quantity, 2)}
                                        </span>
                                    )}
                                </td>
                                <td className="p-2 text-end tabular-nums">{number(i.quantity)}</td>
                                <td className="p-2 text-end tabular-nums text-[#047857]">{number(i.received)}</td>
                                <td className="p-2 text-end tabular-nums text-[#d97706]">
                                    {i.pending > 0 ? number(i.pending, 2) : '—'}
                                </td>
                                <td className="p-2 text-end tabular-nums">{number(i.remaining)}</td>
                                <td className="p-2 text-end tabular-nums">{m(i.cost)}</td>
                                <td className="p-2 text-end font-semibold tabular-nums">{m(i.line_total)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card className="p-4">
                    <h2 className="mb-3 font-bold text-[#111]">{t('الخطّ الزمنيّ')}</h2>
                    <ol className="space-y-3">
                        {timeline.map((e, idx) => (
                            <li key={idx} className="flex gap-3 text-[13px]">
                                <span
                                    aria-hidden
                                    className="mt-1.5 size-2 shrink-0 rounded-full"
                                    style={{ background: DOT[e.kind] ?? '#9ca3af' }}
                                />
                                <div className="min-w-0">
                                    <p className="font-medium text-[#111]">{e.title}</p>
                                    <p className="text-[12px] text-[#9ca3af]">
                                        <span dir="ltr">{e.at}</span>
                                        {e.actor && <> — {e.actor}</>}
                                    </p>
                                    {e.detail && <p className="text-[12px] text-[#6b7280]">{e.detail}</p>}
                                </div>
                            </li>
                        ))}
                    </ol>
                </Card>

                <div className="space-y-4">
                    <Card className="p-4">
                        <h2 className="mb-3 font-bold text-[#111]">{t('أوراق الاستلام')}</h2>
                        {receipts.length === 0 ? (
                            <p className="text-[13px] text-[#9ca3af]">{t('لم يصل من هذا الأمر شيء بعد')}</p>
                        ) : (
                            <ul className="space-y-2 text-[13px]">
                                {receipts.map((r) => (
                                    <li
                                        key={r.id}
                                        className="flex flex-wrap items-center gap-2 border-t border-[var(--ui-border,#e8e8e8)] pt-2 first:border-0 first:pt-0"
                                    >
                                        <a
                                            href={r.pdf}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="font-mono text-[#6d28d9] hover:underline"
                                        >
                                            {r.number}
                                        </a>
                                        <Badge
                                            variant={
                                                r.status === 'معتمد'
                                                    ? 'success'
                                                    : r.status === 'مرفوض'
                                                      ? 'danger'
                                                      : 'warning'
                                            }
                                        >
                                            {t(r.status)}
                                        </Badge>
                                        <span className="text-[#6b7280]">
                                            {number(r.quantity, 2)} {t('وحدة')} · {number(r.lines)} {t('بند')}
                                        </span>
                                        <span dir="ltr" className="ms-auto text-[12px] text-[#9ca3af]">
                                            {r.received_at || '—'}
                                        </span>
                                        {r.rejection_reason && (
                                            <p className="w-full text-[12px] text-[#b91c1c]">{r.rejection_reason}</p>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card className="p-4">
                        <h2 className="mb-3 font-bold text-[#111]">{t('سندات المورّد')}</h2>
                        {invoices.length === 0 ? (
                            <p className="text-[13px] text-[#9ca3af]">{t('لم يُحرَّر على هذا الأمر سند بعد')}</p>
                        ) : (
                            <ul className="space-y-2 text-[13px]">
                                {invoices.map((v) => (
                                    <li
                                        key={v.id}
                                        className="flex flex-wrap items-center gap-2 border-t border-[var(--ui-border,#e8e8e8)] pt-2 first:border-0 first:pt-0"
                                    >
                                        <span className="font-mono text-[#4b4b4b]">{v.reference}</span>
                                        <Badge
                                            variant={
                                                v.approval_status === 'معتمد'
                                                    ? 'success'
                                                    : v.approval_status === 'بانتظار الاعتماد'
                                                      ? 'warning'
                                                      : 'danger'
                                            }
                                        >
                                            {t(v.approval_status)}
                                        </Badge>
                                        <span className="tabular-nums">{m(v.total)}</span>
                                        {v.outstanding > 0 && (
                                            <span className="text-[12px] text-[#d97706]">
                                                {t('الباقي')} {m(v.outstanding)}
                                            </span>
                                        )}
                                        <span dir="ltr" className="ms-auto text-[12px] text-[#9ca3af]">
                                            {v.issued_at || '—'}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
            </div>

            <Dialog open={receiving} onOpenChange={(v) => !v && setReceiving(false)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('تسجيل استلام')} — {order.number}</DialogTitle>
                    </DialogHeader>

                    <div className="space-y-2">
                        {order.items.map((i) => (
                            <div key={i.id} className="flex items-center gap-3 text-[13px]">
                                <span className="min-w-0 flex-1 truncate">{i.name}</span>
                                <span className="text-[12px] text-[#9ca3af]">
                                    {t('المتاح')} {number(available(i))}
                                </span>
                                <Input
                                    type="number"
                                    min={0}
                                    max={available(i)}
                                    className="w-24"
                                    value={taking[i.id] ?? ''}
                                    onChange={(e) => setTaking((p) => ({ ...p, [i.id]: e.target.value }))}
                                />
                            </div>
                        ))}
                    </div>

                    <p className="text-[12px] text-[#6b7280]">
                        {t('الورقة تُسجَّل بانتظار الاعتماد — ولا تدخل البضاعة الرفّ إلّا باعتمادها')}
                    </p>

                    <div className="flex justify-end gap-2">
                        <Button variant="outline" onClick={() => setReceiving(false)}>
                            {t('إلغاء')}
                        </Button>
                        <Button variant="success" disabled={takingTotal <= 0} onClick={submitReceive}>
                            {t('تسجيل الاستلام')}
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>

            <Dialog open={deleting} onOpenChange={(v) => !v && setDeleting(false)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('حذف أمر الشراء')}</DialogTitle>
                    </DialogHeader>
                    <p className="text-[13px] text-[#4b4b4b]">
                        {t('سيُحذف الأمر :number ولا يمكن التراجع.', { number: order.number })}
                    </p>
                    <div className="flex justify-end gap-2">
                        <Button variant="outline" onClick={() => setDeleting(false)}>
                            {t('إلغاء')}
                        </Button>
                        <Button
                            variant="danger"
                            onClick={() => router.delete(route('admin.purchases.destroy', order.id))}
                        >
                            {t('حذف')}
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}

function Row({ label, value, strong }: { label: string; value: ReactNode; strong?: boolean }) {
    return (
        <div className="flex items-center justify-between gap-3">
            <span className="text-[#6b7280]">{label}</span>
            <span className={strong ? 'font-bold text-[#111]' : 'text-[#111]'}>{value}</span>
        </div>
    );
}
