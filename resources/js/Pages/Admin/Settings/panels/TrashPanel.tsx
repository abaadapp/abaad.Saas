import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Building2, Package, Receipt, Trash2, Undo2 } from 'lucide-react';
import DataTable, { type Column } from '@/Components/DataTable';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';
import { money } from '@/lib/format';
import type { PageProps } from '@/types';

/** ما يشترك فيه كل محذوف — ومنه العمودان اللذان يُقرآن قبل أيّ شيء */
interface Trashed {
    id: number;
    deletedAt: string | null;
    /** اسم من ضغط الحذف، من سجلّ النشاط. «—» لما قُيّد قبل أن يُكتب النوع */
    deletedBy: string | null;
}

interface TrashedProduct extends Trashed {
    name: string;
    sku: string | null;
    price: number;
    quantity: number;
    daysLeft: number;
}

interface TrashedExpense extends Trashed {
    reference: string | null;
    title: string | null;
    amount: number;
    spentAt: string | null;
    daysLeft: number;
}

interface TrashedBranch extends Trashed {
    name: string;
    address: string | null;
}

export interface TrashData {
    products: TrashedProduct[];
    expenses: TrashedExpense[];
    /* الاسم مميَّز لأن صفحة الإعدادات تستقبل `branches` للفروع العاملة في
       قسمها — انظر التعليق في TrashController::panelData */
    trashedBranches: TrashedBranch[];
    windowDays: number;
}

type PurgeTarget = { type: 'product' | 'expense'; id: number; label: string };

/**
 * جسم قسم «المحذوفات» بلا قشرة — في لوحة الإعدادات وفي صفحته المستقلّة.
 *
 * وهو الزرّ المقابل للحذف: كان حذف المنتج والمصروف محوًا نهائيًّا، فضغطةٌ
 * خاطئة تُذهب التكلفة والباركود والمرفق، والسجلّ يقيّد ما جرى ولا يردّه.
 */
export default function TrashPanel({ products, expenses, trashedBranches: branches, windowDays }: TrashData) {
    const { context } = usePage<PageProps>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);
    const [purging, setPurging] = useState<PurgeTarget | null>(null);
    const [busy, setBusy] = useState(false);

    /*
     * لكل نوعٍ مسارُه: الاستعادة والمحو يقعان تحت صلاحية القسم الذي حُذف منه،
     * لا تحت «الإعدادات» — انظر TrashController::restore.
     */
    const restore = (type: 'product' | 'expense' | 'branch', id: number) =>
        router.post(route(`admin.${type}s.restore`, id), {}, { preserveScroll: true });

    const purge = () => {
        if (!purging) return;
        setBusy(true);
        router.delete(route(`admin.${purging.type}s.purge`, purging.id), {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setPurging(null);
            },
        });
    };

    /** أيقونةٌ في مربّع — رأس كل صفٍّ في الجداول الثلاثة */
    const icon = (Icon: typeof Package, title: string, sub?: string | null) => (
        <div className="flex items-center gap-3">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-[#f2f2f0] text-[#4b4b4b]">
                <Icon className="size-4" />
            </span>
            <span>
                <span className="block font-medium text-[#111]">{title}</span>
                {sub && <span className="block text-[12px] text-[#9ca3af]">{sub}</span>}
            </span>
        </div>
    );

    /**
     * «من حذفه» — السؤال الأول لصاحب متجرٍ فيه موظّفون.
     *
     * الاستعادة تُصلح الضرر مرّة، ومعرفة الفاعل تمنع تكراره. والاسم يأتي من
     * سجلّ النشاط لا من الصفّ، فما حُذف قبل أن يُكتب النوع في السجلّ لا اسم
     * له — وعرضُ «—» أصدق من نسبة الفعل إلى من لم يفعله.
     */
    const deletedByColumn = <T extends Trashed>(): Column<T> => ({
        key: 'deletedBy',
        header: 'من حذفه',
        cell: (r) => r.deletedBy ?? <span className="text-[#c4c4c4]">—</span>,
    });

    /** ما تبقّى قبل المحو — الرقم هو ما يدفع إلى قرارٍ قبل فوات الأوان */
    const daysLeftColumn = <T extends Trashed & { daysLeft: number }>(): Column<T> => ({
        key: 'daysLeft',
        header: 'يُمحى بعد',
        align: 'center',
        cell: (r) => (
            <span className={r.daysLeft <= 7 ? 'font-medium text-[#b91c1c]' : 'text-[#6b7280]'}>
                {t(':n يوم', { n: r.daysLeft })}
            </span>
        ),
    });

    /** استعادة، ومحوٌ نهائيّ بجانبها لمن قرّر أنه لا يريده */
    const actions = <T extends Trashed>(
        type: 'product' | 'expense',
        label: (row: T) => string,
    ): Column<T> => ({
        key: 'actions',
        header: '',
        align: 'end',
        cell: (r) => (
            <div className="flex justify-end gap-2">
                <Button variant="outline" size="sm" onClick={() => restore(type, r.id)}>
                    <Undo2 className="size-4" />
                    {t('استعادة')}
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    title={t('محو نهائي')}
                    className="text-[#9ca3af] hover:bg-[#fef2f2] hover:text-[#b91c1c]"
                    onClick={() => setPurging({ type, id: r.id, label: label(r) })}
                >
                    <Trash2 className="size-4" />
                    <span className="sr-only">{t('محو نهائي')}</span>
                </Button>
            </div>
        ),
    });

    const productColumns: Column<TrashedProduct>[] = [
        {
            key: 'name',
            header: 'المنتج',
            sortable: true,
            value: (p) => p.name,
            cell: (p) => icon(Package, p.name, p.sku),
        },
        { key: 'price', header: 'السعر', align: 'end', cell: (p) => m(p.price) },
        { key: 'quantity', header: 'الكمية', align: 'center', cell: (p) => p.quantity },
        deletedByColumn<TrashedProduct>(),
        { key: 'deletedAt', header: 'تاريخ الحذف', cell: (p) => p.deletedAt ?? '—' },
        daysLeftColumn<TrashedProduct>(),
        actions<TrashedProduct>('product', (p) => p.name),
    ];

    const expenseColumns: Column<TrashedExpense>[] = [
        {
            key: 'reference',
            header: 'المصروف',
            sortable: true,
            value: (e) => e.reference ?? '',
            cell: (e) => icon(Receipt, e.reference ?? '—', e.title),
        },
        { key: 'amount', header: 'المبلغ', align: 'end', cell: (e) => m(e.amount) },
        { key: 'spentAt', header: 'تاريخ الصرف', cell: (e) => e.spentAt ?? '—' },
        deletedByColumn<TrashedExpense>(),
        { key: 'deletedAt', header: 'تاريخ الحذف', cell: (e) => e.deletedAt ?? '—' },
        daysLeftColumn<TrashedExpense>(),
        actions<TrashedExpense>('expense', (e) => e.reference || e.title || '—'),
    ];

    const branchColumns: Column<TrashedBranch>[] = [
        {
            key: 'name',
            header: 'الفرع',
            sortable: true,
            value: (b) => b.name,
            cell: (b) => icon(Building2, b.name, b.address),
        },
        deletedByColumn<TrashedBranch>(),
        { key: 'deletedAt', header: 'تاريخ الحذف', cell: (b) => b.deletedAt ?? '—' },
        {
            key: 'actions',
            header: '',
            align: 'end',
            // لا محوَ للفرع — انظر TrashController::PURGEABLE
            cell: (b) => (
                <Button variant="outline" size="sm" onClick={() => restore('branch', b.id)}>
                    <Undo2 className="size-4" />
                    {t('استعادة')}
                </Button>
            ),
        },
    ];

    return (
        <div className="min-w-0 space-y-6">
            {/* المهلة داخل القسم لا في ترويسة الصفحة: القسم يُفتح في
                الإعدادات بلا ترويسة، ولا يُستعاد شيءٌ بعد انقضائها */}
            <p className="text-[13px] text-[#9ca3af]">
                {t('يُستعاد ما حُذف خلال :n يومًا، ثم يُمحى محوًا نهائيًّا لا رجعة فيه.', {
                    n: windowDays,
                })}
            </p>

            <div>
                <h2 className="mb-3 text-[15px] font-bold text-[#111]">{t('المنتجات')}</h2>
                <Card className="p-0">
                    <DataTable
                        columns={productColumns}
                        rows={products}
                        rowKey={(p) => p.id}
                        empty={t('لا منتجات محذوفة.')}
                    />
                </Card>
            </div>

            <div>
                <h2 className="mb-3 text-[15px] font-bold text-[#111]">{t('المصروفات')}</h2>
                <Card className="p-0">
                    <DataTable
                        columns={expenseColumns}
                        rows={expenses}
                        rowKey={(e) => e.id}
                        empty={t('لا مصروفات محذوفة.')}
                    />
                </Card>
            </div>

            {/* جدول الفروع يظهر عند وجود محذوفٍ فقط: حذف الفرع نادرٌ في عمر
                المتجر، وجدولٌ فارغٌ دائمًا يجعل الشاشة تُقرأ «لا شيء هنا» */}
            {branches.length > 0 && (
                <div>
                    <h2 className="mb-3 text-[15px] font-bold text-[#111]">{t('الفروع')}</h2>
                    <p className="mb-3 text-[13px] text-[#9ca3af]">
                        {t('الفرع لا يُمحى: صناديقه وأذون موظفيه وحركة مخزونه معلّقةٌ به، فيبقى هنا حتى تستعيده.')}
                    </p>
                    <Card className="p-0">
                        <DataTable
                            columns={branchColumns}
                            rows={branches}
                            rowKey={(b) => b.id}
                            empty={t('لا فروع محذوفة.')}
                        />
                    </Card>
                </div>
            )}

            {/*
                المحو النهائي يسأل قبله: هو الفعل الوحيد في الشاشة بلا زرّ
                «تراجع» بعده، والاسم مكتوبٌ في السؤال كي يرى الضاغط ما يمحوه
                لا عدده.
            */}
            <Dialog open={purging !== null} onOpenChange={(o) => !o && setPurging(null)}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{t('محو نهائي')}</DialogTitle>
                    </DialogHeader>
                    <div className="px-5 pb-5">
                        <p className="text-sm text-[#4b4b4b]">
                            {t('سيُمحى «:name» ولا يمكن استرداده بعدها — ولا من النسخة الاحتياطية إن مضى وقت.', {
                                name: purging?.label ?? '',
                            })}
                        </p>
                        <div className="mt-5 flex justify-end gap-2">
                            <Button variant="outline" onClick={() => setPurging(null)} disabled={busy}>
                                {t('إلغاء')}
                            </Button>
                            <Button variant="danger" onClick={purge} disabled={busy}>
                                {busy ? '…' : t('امحُ نهائيًّا')}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    );
}
