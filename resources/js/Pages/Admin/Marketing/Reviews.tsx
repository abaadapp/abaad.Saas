import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Check, MessageSquare, Plus, Star, Trash2, X } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import StatCard from '@/Components/StatCard';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import Field, { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface Review {
    id: number;
    author: string;
    product: string | null;
    rating: number;
    comment: string | null;
    status: string;
    reply: string | null;
    replied_at: string | null;
    at: string | null;
}

interface Props {
    reviews: Review[];
    pagination: ServerPagination;
    filters: Record<string, string | null | undefined>;
    products: { value: number; label: string }[];
    customers: { value: number; label: string }[];
    summary: { count: number; pending: number; published: number; average: number };
}

const STATUS_TONE: Record<string, string> = {
    'معلّق': 'warning',
    منشور: 'success',
    مرفوض: 'danger',
};

/** النجوم تُقرأ بلمحة — والرقم المجرّد يُقرأ بعدّ */
function Stars({ value }: { value: number }) {
    return (
        <span className="inline-flex items-center gap-0.5" aria-label={`${value}/5`}>
            {[1, 2, 3, 4, 5].map((i) => (
                <Star
                    key={i}
                    className={cn('size-3.5', i <= value ? 'fill-[#d97706] text-[#d97706]' : 'text-[#e5e7eb]')}
                />
            ))}
        </span>
    );
}

export default function Reviews() {
    const { reviews, pagination, filters, products, customers, summary } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const [adding, setAdding] = useState(false);
    const [replying, setReplying] = useState<Review | null>(null);

    const form = useForm({
        customer_id: '',
        product_id: '',
        author_name: '',
        rating: '5',
        comment: '',
    });

    const replyForm = useForm({ reply: '' });

    const setStatus = (review: Review, status: string) =>
        router.post(
            route('admin.marketing.reviews.status', review.id),
            { status },
            { preserveScroll: true },
        );

    const columns: Column<Review>[] = [
        {
            key: 'author',
            header: 'المُقيِّم',
            cell: (r) => (
                <>
                    <span className="font-medium text-[#111]">{r.author}</span>
                    <span className="block text-[12px] text-[#9ca3af]" dir="ltr">
                        {r.at}
                    </span>
                </>
            ),
        },
        { key: 'product', header: 'المنتج', cell: (r) => r.product ?? '—' },
        { key: 'rating', header: 'التقييم', cell: (r) => <Stars value={r.rating} /> },
        {
            key: 'comment',
            header: 'التعليق',
            cell: (r) => (
                <>
                    <span className="line-clamp-2 max-w-md">{r.comment ?? '—'}</span>
                    {r.reply && (
                        <span className="mt-1 line-clamp-1 block max-w-md text-[12px] text-[#047857]">
                            ↩ {r.reply}
                        </span>
                    )}
                </>
            ),
        },
        {
            key: 'status',
            header: 'الحالة',
            cell: (r) => <Badge variant={(STATUS_TONE[r.status] ?? 'neutral') as never}>{t(r.status)}</Badge>,
        },
        {
            key: 'actions',
            header: 'إجراءات',
            align: 'end',
            cell: (r) => (
                <div className="flex items-center justify-end gap-1">
                    {r.status !== 'منشور' && (
                        <Button variant="ghost" size="sm" onClick={() => setStatus(r, 'منشور')}>
                            <Check />
                            {t('نشر')}
                        </Button>
                    )}
                    {r.status !== 'مرفوض' && (
                        <Button variant="ghost" size="sm" onClick={() => setStatus(r, 'مرفوض')}>
                            <X />
                            {t('رفض')}
                        </Button>
                    )}
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            replyForm.clearErrors();
                            replyForm.setData('reply', r.reply ?? '');
                            setReplying(r);
                        }}
                    >
                        <MessageSquare />
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        className="text-[#b91c1c]"
                        onClick={() => {
                            if (!confirm(t('حذف التقييم؟ الرفض يُبقيه محفوظًا.'))) return;
                            router.delete(route('admin.marketing.reviews.destroy', r.id), { preserveScroll: true });
                        }}
                    >
                        <Trash2 />
                    </Button>
                </div>
            ),
        },
    ];

    const tableFilters: Filter<Review>[] = [
        {
            label: 'كل الحالات',
            asTabs: true,
            param: 'status',
            options: [
                { label: 'معلّق', value: 'معلّق' },
                { label: 'منشور', value: 'منشور' },
                { label: 'مرفوض', value: 'مرفوض' },
            ],
        },
        {
            label: 'كل التقييمات',
            param: 'rating',
            options: [5, 4, 3, 2, 1].map((n) => ({ label: `${n} ★`, value: String(n) })),
        },
    ];

    return (
        <AdminLayout title="تقييمات العملاء">
            <PageHeader
                title="تقييمات العملاء"
                subtitle={t('لا يُنشر منها إلا ما أُذن بنشره')}
                actions={
                    <Button onClick={() => setAdding(true)}>
                        <Plus />
                        {t('تسجيل تقييم')}
                    </Button>
                }
            />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard stat={{ label: t('التقييمات'), value: number(summary.count), icon: 'star', color: 'info' }} index={0} />
                <StatCard
                    stat={{
                        label: t('ينتظر المراجعة'),
                        value: number(summary.pending),
                        icon: 'clock',
                        color: summary.pending > 0 ? 'warning' : 'success',
                    }}
                    index={1}
                />
                <StatCard stat={{ label: t('منشور'), value: number(summary.published), icon: 'badge-check', color: 'success' }} index={2} />
                {/* المعدّل على المنشور وحده: المعلّق لم يُقرأ بعد فلا يُحتسب رأيًا */}
                <StatCard
                    stat={{ label: t('معدّل المنشور'), value: summary.average ? `${summary.average} ★` : '—', icon: 'trending-up', color: 'primary' }}
                    index={3}
                />
            </div>

            <Card className="overflow-hidden">
                <DataTable
                    rows={reviews}
                    columns={columns}
                    rowKey={(r) => r.id}
                    searchPlaceholder="ابحث في التعليقات…"
                    searchable={() => ''}
                    filters={tableFilters}
                    empty="لا تقييمات بعد"
                    server={{ pagination, params: filters }}
                />
            </Card>

            {/* ===== تسجيل تقييم ===== */}
            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('تسجيل تقييم')}</DialogTitle>
                    </DialogHeader>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(route('admin.marketing.reviews.store'), {
                                preserveScroll: true,
                                onSuccess: () => {
                                    setAdding(false);
                                    form.reset();
                                },
                            });
                        }}
                        className="space-y-4"
                    >
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="العميل" error={form.errors.customer_id}>
                                <Select
                                    placeholder="بلا عميل"
                                    value={form.data.customer_id}
                                    onChange={(e) => form.setData('customer_id', e.target.value)}
                                    options={customers}
                                />
                            </Field>
                            <Field label="المنتج" error={form.errors.product_id}>
                                <Select
                                    placeholder="عن المتجر عمومًا"
                                    value={form.data.product_id}
                                    onChange={(e) => form.setData('product_id', e.target.value)}
                                    options={products}
                                />
                            </Field>
                        </div>

                        <Field
                            label="الاسم"
                            hint="حين لا يكون المُقيِّم عميلًا مسجَّلًا"
                            error={form.errors.author_name}
                        >
                            <Input
                                value={form.data.author_name}
                                onChange={(e) => form.setData('author_name', e.target.value)}
                            />
                        </Field>

                        <Field label="التقييم" required error={form.errors.rating}>
                            <Select
                                value={form.data.rating}
                                onChange={(e) => form.setData('rating', e.target.value)}
                                options={[5, 4, 3, 2, 1].map((n) => ({ value: String(n), label: `${n} ★` }))}
                            />
                        </Field>

                        <Field label="التعليق" error={form.errors.comment}>
                            <textarea
                                rows={3}
                                value={form.data.comment}
                                onChange={(e) => form.setData('comment', e.target.value)}
                                className="w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2 text-sm transition-[border-color,box-shadow] focus:border-[#d1d5db] focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)] focus:outline-none"
                            />
                        </Field>

                        <p className="text-[12px] text-[#9ca3af]">
                            {t('يُسجَّل معلَّقًا ولا يظهر على الموقع حتى تنشره.')}
                        </p>

                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="ghost" onClick={() => setAdding(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={form.processing}>
                                <Check />
                                {t('حفظ')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* ===== الردّ ===== */}
            <Dialog open={replying !== null} onOpenChange={(o) => !o && setReplying(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('الردّ على التقييم')}</DialogTitle>
                    </DialogHeader>

                    <div className="rounded-[12px] bg-[#fafafa] p-3">
                        <div className="flex items-center gap-2">
                            <span className="font-medium text-[#111]">{replying?.author}</span>
                            <Stars value={replying?.rating ?? 0} />
                        </div>
                        {replying?.comment && (
                            <p className="mt-1 text-[13px] text-[#6b7280]">{replying.comment}</p>
                        )}
                    </div>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (!replying) return;
                            replyForm.post(route('admin.marketing.reviews.reply', replying.id), {
                                preserveScroll: true,
                                onSuccess: () => setReplying(null),
                            });
                        }}
                        className="space-y-4"
                    >
                        <Field label="ردّك" required error={replyForm.errors.reply}>
                            <textarea
                                rows={3}
                                value={replyForm.data.reply}
                                onChange={(e) => replyForm.setData('reply', e.target.value)}
                                className="w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2 text-sm transition-[border-color,box-shadow] focus:border-[#d1d5db] focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)] focus:outline-none"
                            />
                        </Field>

                        {replying?.status === 'معلّق' && (
                            <p className="text-[12px] text-[#9ca3af]">
                                {t('الردّ يُنشر مع تقييمه — فحفظُه ينشر التقييم أيضًا.')}
                            </p>
                        )}

                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="ghost" onClick={() => setReplying(null)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={replyForm.processing}>
                                <MessageSquare />
                                {t('نشر الردّ')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
