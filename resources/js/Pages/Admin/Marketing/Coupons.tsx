import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Plus, SlidersHorizontal, ToggleLeft, ToggleRight, Trash2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import StatCard from '@/Components/StatCard';
import Field, { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Coupon } from '@/types/models';

interface Props {
    stats: { total: number; active: number; redemptions: number };
    coupons: Coupon[];
}

/**
 * سطرُ حدودِ الكوبون — العدُّ والحدّان والانتهاء، كلٌّ باسمه.
 *
 * ═══ ولمَ دالّةٌ تُقاس ═══
 *
 * «استُخدم ٣ / ١٠٠» عدٌّ إجماليّ، و«لكل زبون ٢» حدٌّ لكلّ واحد. وعرضُ
 * أحدهما مكانَ الآخر يجعل التاجر يظنّ كودَه انتهى وهو يعمل لزبونٍ جديد —
 * أو يظنّه بلا حدٍّ وهو مقيَّد بمرّةٍ واحدة. وهذا ما يُسأل عنه في
 * `each-customer-reads-his-own-share-of-the-coupon`.
 */
export function limitsLine(
    c: Pick<Coupon, 'min_order' | 'used_count' | 'max_uses' | 'per_customer_limit' | 'per_customer_since' | 'expires'>,
    t: (k: string) => string,
    currency: Parameters<typeof money>[1],
): string {
    const parts: string[] = [];

    if (c.min_order > 0) {
        parts.push(`${t('حد أدنى')} ${money(c.min_order, currency)}`);
    }

    // العدُّ الإجماليُّ وحدُّه معه — بشُرطةٍ مائلة كما كان
    parts.push(`${t('استُخدم')} ${number(c.used_count)}${c.max_uses ? ` / ${number(c.max_uses)}` : ''}`);

    if (c.per_customer_limit) {
        /*
         * ومنذ متى يُحسب — لا يُحسب ما وقع قبل تفعيله.
         *
         * التاجرُ يضبط الحدَّ على كودٍ يعمل منذ شهور، فلو قُرئ «مرّتان لكلّ
         * زبون» بلا تاريخٍ لَظنّ الحدَّ ساريًا على ما مضى، وحسب أنّ زبونًا
         * اشترى ثلاثًا في رمضان سيُردّ اليوم — وهو لا يُردّ.
         */
        parts.push(
            `${t('لكل زبون')} ${number(c.per_customer_limit)}${
                c.per_customer_since ? ` ${t('منذ')} ${c.per_customer_since}` : ''
            }`,
        );
    }

    if (c.expires) {
        parts.push(`${t('ينتهي')} ${c.expires}`);
    }

    return parts.join(' · ');
}

export default function Coupons() {
    const { stats, coupons, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const [adding, setAdding] = useState(false);
    const [deleting, setDeleting] = useState<Coupon | null>(null);
    const [editing, setEditing] = useState<Coupon | null>(null);

    const form = useForm({
        code: '',
        type: 'نسبة',
        value: '',
        min_order: '0',
        max_uses: '',
        per_customer_limit: '',
        expires_at: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.coupons.store'), {
            onSuccess: () => {
                form.reset();
                setAdding(false);
            },
        });
    };

    const cards = [
        { label: t('إجمالي الكوبونات'), value: String(stats.total), icon: 'receipt', color: 'primary' },
        { label: t('كوبونات فعّالة'), value: String(stats.active), icon: 'badge-check', color: 'success' },
        { label: t('مرات الاستخدام'), value: String(stats.redemptions), icon: 'circle-check', color: 'info' },
    ];

    return (
        <AdminLayout title="الكوبونات والعروض">
            <PageHeader
                title="الكوبونات والعروض"
                subtitle={t('أكواد الخصم والعروض لاستهداف العملاء')}
                actions={
                    <Button onClick={() => setAdding(true)}>
                        <Plus />
                        {t('كوبون جديد')}
                    </Button>
                }
            />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                {cards.map((s, i) => (
                    <StatCard key={s.label} stat={s} index={i} />
                ))}
            </div>

            <Card className="overflow-hidden">
                <div className="flex items-center justify-between border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                    <h3 className="font-bold text-[#111]">{t('أكواد الخصم')}</h3>
                    <button
                        type="button"
                        onClick={() => setAdding(true)}
                        className="text-sm font-medium text-[#6d28d9] hover:underline"
                    >
                        + {t('إضافة')}
                    </button>
                </div>

                {coupons.length === 0 ? (
                    <p className="p-8 text-center text-sm text-[#9ca3af]">
                        {t('لا توجد كوبونات بعد. أنشئ أول كود خصم.')}
                    </p>
                ) : (
                    <div className="divide-y divide-[#f5f5f4]">
                        {coupons.map((c) => (
                            <div key={c.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                                <div className="flex items-center gap-3">
                                    <span className="rounded-[8px] bg-[#f5f3ff] px-2.5 py-1 font-mono font-bold text-[#6d28d9]">
                                        {c.code}
                                    </span>
                                    <div>
                                        <p className="text-sm font-semibold text-[#111]">
                                            {t('خصم')} {c.display}
                                        </p>
                                        <p className="text-[12px] text-[#9ca3af]">
                                            {limitsLine(c, t, currency)}
                                        </p>
                                    </div>
                                </div>

                                <div className="flex items-center gap-2">
                                    {/*
                                      * الشارةُ تقول ما سيفعله الصندوق.
                                      *
                                      * كانت تقرأ شرطين من ثلاثة: «منتهٍ» لمن مضى
                                      * تاريخه، و«فعّال» لكلّ ما عداه. فكودٌ حدُّه
                                      * خمسون استُخدم خمسين يُقرأ أخضرَ في اللوحة
                                      * ويُردّ عند الدفع — والتاجر يظنّ العطبَ في
                                      * الكاشير أو في الكود الذي طبعه على لافتته.
                                      *
                                      * و«استُنفد» ليست «موقوف»: الثانيةُ قرارٌ
                                      * يُلغى بالمقبض الذي بجانبها، والأولى حدٌّ
                                      * بلغه الكود — لا يردّه إلا كودٌ جديد.
                                      */}
                                    {c.expired ? (
                                        <Badge variant="danger">{t('منتهٍ')}</Badge>
                                    ) : c.exhausted ? (
                                        <Badge variant="danger">{t('استُنفد')}</Badge>
                                    ) : (
                                        <Badge variant={c.usable ? 'success' : 'neutral'}>
                                            {c.usable ? t('فعّال') : t('موقوف')}
                                        </Badge>
                                    )}

                                    {/* وحدّاه يُعدَّلان — لا كودُه ولا قيمتُه */}
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        title={t('تعديل الحدود')}
                                        aria-label={t('تعديل الحدود')}
                                        onClick={() => setEditing(c)}
                                    >
                                        <SlidersHorizontal />
                                    </Button>

                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        title={c.active ? t('إيقاف') : t('تفعيل')}
                                        aria-label={c.active ? t('إيقاف') : t('تفعيل')}
                                        onClick={() =>
                                            router.post(route('admin.coupons.toggle', c.id), {}, { preserveScroll: true })
                                        }
                                    >
                                        {c.active ? <ToggleRight /> : <ToggleLeft />}
                                    </Button>

                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        className="text-[#b91c1c]"
                                        aria-label={t('حذف')}
                                        onClick={() => setDeleting(c)}
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </Card>

            {/* إنشاء كوبون */}
            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('إنشاء كوبون خصم')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4 px-5 pb-5">
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="الكود" required error={form.errors.code}>
                                <Input
                                    value={form.data.code}
                                    onChange={(e) => form.setData('code', e.target.value.toUpperCase())}
                                    placeholder="SUMMER25"
                                    dir="ltr"
                                    className="uppercase"
                                    required
                                />
                            </Field>
                            <Field label="النوع" error={form.errors.type}>
                                <Select
                                    value={form.data.type}
                                    onChange={(e) => form.setData('type', e.target.value)}
                                    options={[
                                        { label: 'نسبة %', value: 'نسبة' },
                                        { label: 'مبلغ ثابت', value: 'مبلغ' },
                                    ]}
                                />
                            </Field>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <Field label="القيمة" required error={form.errors.value}>
                                <Input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    dir="ltr"
                                    value={form.data.value}
                                    onChange={(e) => form.setData('value', e.target.value)}
                                    required
                                />
                            </Field>
                            <Field label="حد أدنى للطلب" error={form.errors.min_order}>
                                <Input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    dir="ltr"
                                    value={form.data.min_order}
                                    onChange={(e) => form.setData('min_order', e.target.value)}
                                />
                            </Field>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <Field
                                label="أقصى عدد استخدامات (إجمالًا)"
                                hint="لكل الزبائن معًا — يُغلق الكود حين يبلغه"
                                error={form.errors.max_uses}
                            >
                                <Input
                                    type="number"
                                    min="1"
                                    dir="ltr"
                                    value={form.data.max_uses}
                                    onChange={(e) => form.setData('max_uses', e.target.value)}
                                    placeholder={t('بلا حدّ')}
                                />
                            </Field>
                            {/*
                              * وحدٌّ ثانٍ مستقلّ — لا صياغةٌ أخرى للأوّل.
                              *
                              * «مرّتان لكلّ زبون» بلا حدٍّ إجماليّ: أحمدُ مرّتان
                              * ومحمّدٌ مرّتان ولا ينتهي الكود. والفراغُ يعني بلا
                              * حدٍّ لكلّ زبون — وهو حالُ الكوبونات القائمة كلِّها.
                              */}
                            <Field
                                label="الحد لكل زبون"
                                hint="كم مرّة يستخدمه الزبون الواحد — يُعرف برقم هاتفه"
                                error={form.errors.per_customer_limit}
                            >
                                <Input
                                    type="number"
                                    min="1"
                                    dir="ltr"
                                    value={form.data.per_customer_limit}
                                    onChange={(e) => form.setData('per_customer_limit', e.target.value)}
                                    placeholder={t('بلا حدّ')}
                                />
                            </Field>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <Field label="تاريخ الانتهاء" error={form.errors.expires_at}>
                                <Input
                                    type="date"
                                    value={form.data.expires_at}
                                    onChange={(e) => form.setData('expires_at', e.target.value)}
                                />
                            </Field>
                        </div>

                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="outline" onClick={() => setAdding(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={form.processing}>
                                {t('إنشاء')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* تعديلُ الحدّين */}
            <LimitsDialog coupon={editing} onClose={() => setEditing(null)} />

            <Dialog open={deleting !== null} onOpenChange={(v) => !v && setDeleting(null)}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{t('تأكيد الحذف')}</DialogTitle>
                    </DialogHeader>
                    <div className="px-5 pb-5">
                        <p className="text-sm text-[#4b4b4b]">{t('حذف الكوبون؟')}</p>
                        <div className="mt-5 flex justify-end gap-2">
                            <Button variant="outline" onClick={() => setDeleting(null)}>
                                {t('إلغاء')}
                            </Button>
                            <Button
                                variant="danger"
                                onClick={() =>
                                    deleting &&
                                    router.delete(route('admin.coupons.destroy', deleting.id), {
                                        preserveScroll: true,
                                        onFinish: () => setDeleting(null),
                                    })
                                }
                            >
                                {t('حذف')}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}

/**
 * نافذةُ الحدّين — الإجماليُّ والذي لكلّ زبون.
 *
 * ═══ ولمَ مكوّنٌ على حدة ═══
 *
 * يُفتح على كوبونٍ بعينه فيقرأ حدَّيه، ويُغلق فيُنسى ما كُتب. ومفتاحُه
 * معرّفُ الكوبون (`key`) لا حالةٌ تُنسخ في تأثير: بلا ذلك يفتح التاجرُ
 * كوبونًا ثمّ آخرَ فيقرأ حدودَ الأوّل في نموذج الثاني — ويحفظها عليه.
 *
 * وتقول له من أيّ يومٍ يُحسب الحدُّ: العدُّ من لحظة التفعيل لا من استعمالاتٍ
 * مضت، وإطفاؤه وإعادتُه لا يُصفّران شيئًا.
 */
export function LimitsDialog({ coupon, onClose }: { coupon: Coupon | null; onClose: () => void }) {
    const t = useTranslate();

    return (
        <Dialog open={coupon !== null} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('حدود الكوبون')}</DialogTitle>
                </DialogHeader>
                {coupon && <LimitsForm key={coupon.id} coupon={coupon} onClose={onClose} />}
            </DialogContent>
        </Dialog>
    );
}

function LimitsForm({ coupon, onClose }: { coupon: Coupon; onClose: () => void }) {
    const t = useTranslate();
    const form = useForm({
        max_uses: coupon.max_uses ? String(coupon.max_uses) : '',
        per_customer_limit: coupon.per_customer_limit ? String(coupon.per_customer_limit) : '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch(route('admin.coupons.limits', coupon.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4 px-5 pb-5">
            <p className="font-mono text-sm font-bold text-[#6d28d9]" dir="ltr">
                {coupon.code}
            </p>

            {/* والتسميةُ تُشير إلى حقلها: حقلان بالعنوان نفسِه «بلا حدّ» لا يُفرَّق بينهما بغيرها */}
            <Field
                label="أقصى عدد استخدامات (إجمالًا)"
                hint="لكل الزبائن معًا — يُغلق الكود حين يبلغه"
                htmlFor="limits-max-uses"
                error={form.errors.max_uses}
            >
                <Input
                    id="limits-max-uses"
                    type="number"
                    min="1"
                    dir="ltr"
                    value={form.data.max_uses}
                    onChange={(e) => form.setData('max_uses', e.target.value)}
                    placeholder={t('بلا حدّ')}
                />
            </Field>

            <Field
                label="الحد لكل زبون"
                hint="كم مرّة يستخدمه الزبون الواحد — يُعرف برقم هاتفه"
                htmlFor="limits-per-customer"
                error={form.errors.per_customer_limit}
            >
                <Input
                    id="limits-per-customer"
                    type="number"
                    min="1"
                    dir="ltr"
                    value={form.data.per_customer_limit}
                    onChange={(e) => form.setData('per_customer_limit', e.target.value)}
                    placeholder={t('بلا حدّ')}
                />
            </Field>

            <p className="text-[12px] leading-relaxed text-[#9ca3af]">
                {coupon.per_customer_since
                    ? `${t('يُحسب الحد لكل زبون من')} ${coupon.per_customer_since} — ${t('وما قبله لا يُحسب. وإطفاء الحد وإعادته لا يُصفّر الاستخدامات.')}`
                    : t('يبدأ حساب الحد لكل زبون من لحظة تفعيله — وما استُخدم قبله لا يُحسب.')}
            </p>

            <div className="flex justify-end gap-2 pt-1">
                <Button type="button" variant="outline" onClick={onClose}>
                    {t('إلغاء')}
                </Button>
                <Button type="submit" loading={form.processing}>
                    {t('حفظ الحدود')}
                </Button>
            </div>
        </form>
    );
}
