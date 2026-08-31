import { useForm, usePage } from '@inertiajs/react';
import { CheckCheck } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { INVENTORY_TABS } from '@/Components/SectionTabs';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { Branch, InventoryItem } from '@/types/models';

/** رصيد كل فرعٍ لهذا الصنف — المفتاح معرّف الفرع */
type StocktakeItem = InventoryItem & { stock: Record<number, number> };

interface Props {
    items: StocktakeItem[];
    branches: Branch[];
    currentBranch: number | null;
}

export default function Stocktake() {
    const { items, branches, currentBranch } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    /*
     * عمودان لا عمود.
     *
     *   الكمية المعدودة  →  ما وجدتَه على الرفّ، يصير هو الرصيد
     *   الفاقد          →  ما عُدم، يُطرح من الرصيد ولا يحلّ محلّه
     *
     * وعمودٌ واحد كان أصل عطبٍ كبير: الشاشة تسأل «الكمية المعدودة» فيكتب
     * فيها من عنده مئة وردةٍ تلفت ثلاثٌ منها «٣» — فيصير رصيده ثلاثًا بدل
     * سبعٍ وتسعين. رقمٌ مشروع في حقلٍ مشروع، وتسعون وردةً تختفي بلا رسالة.
     *
     * وعمود الفرق يعرض الرصيد الناتج بعده كي لا يبقى المعنى ضمنيًّا: من
     * يكتب رقمًا يرى أين ينتهي الصنف قبل أن يضغط.
     */
    const form = useForm<{
        branch_id: string;
        counts: Record<number, string>;
        losses: Record<number, string>;
    }>({
        branch_id: currentBranch ? String(currentBranch) : '',
        counts: {},
        losses: {},
    });

    const setCount = (id: number, value: string) =>
        form.setData('counts', { ...form.data.counts, [id]: value });

    const setLoss = (id: number, value: string) =>
        form.setData('losses', { ...form.data.losses, [id]: value });

    /** رقمٌ مكتوب في الحقل، أو null إن تُرك فارغًا */
    const entered = (bag: Record<number, string>, id: number): number | null => {
        const raw = bag[id];
        if (raw === undefined || raw === '') return null;
        const n = Number(raw);

        return Number.isFinite(n) ? n : null;
    };

    /**
     * دفتر الفرع المختار — لا إجمالي الشركة.
     *
     * الرقم المعروض هو ما يقرأه العادّ ويقارن به. وكان يعرض الإجمالي: من
     * يعدّ مسقط فيجدها عشرة كما في دفترها يقرأ «الدفترية ١٥» ويظنّ نفسه
     * ناقصًا خمسة — فيذهب يبحث عن بضاعةٍ لم تُفقد. والقاعدة نفسها على
     * الخادم (BranchStock::books)، وبلا ذلك تختلف الشاشة عمّا سيُطبَّق.
     */
    const book = (item: StocktakeItem): number =>
        form.data.branch_id ? (item.stock[Number(form.data.branch_id)] ?? 0) : 0;

    /**
     * الفرق الذي سيُطبَّق — بالمعادلة نفسها التي يطبّقها الخادم.
     *
     * null يعني «لم يُدخَل شيء» فلا يُحتسب. والفاقد طرحٌ لا رصيد، فالفرق
     * فيه سالبٌ دائمًا.
     */
    const variance = (id: number, book: number): number | null => {
        const loss = entered(form.data.losses, id);
        if (loss !== null && loss !== 0) return -loss;

        const counted = entered(form.data.counts, id);

        return counted === null ? null : counted - book;
    };

    /*
     * الحقلان يتعارضان فيُقفل أحدهما.
     *
     * من كتب «معدود ٩٧» و«فاقد ٣» يقصد شيئًا واحدًا، والنظام لو أطاع
     * الاثنين لَطرح ستًّا. والقفلُ في الشاشة يمنع الالتباس قبل أن يقع؛
     * والخادم يردّه أيضًا، فالقاعدة ليست في الشاشة وحدها.
     */
    const hasLoss = (id: number) => {
        const n = entered(form.data.losses, id);

        return n !== null && n !== 0;
    };
    const hasCount = (id: number) => entered(form.data.counts, id) !== null;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.inventory.stocktake.apply'));
    };

    return (
        <AdminLayout title="الجرد الفعلي">
            <PageHeader
                title="الجرد الفعلي"
                subtitle={t('الكمية المعدودة تصير هي الرصيد، والفاقد يُطرح منه')}
            />

            <SectionTabs tabs={INVENTORY_TABS} current="admin.inventory.stocktake" />

            <form onSubmit={submit}>
                <Card className="mb-6 p-4">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <Field label="الفرع" required error={form.errors.branch_id} className="w-full sm:w-72">
                            <Select
                                value={form.data.branch_id}
                                onChange={(e) => form.setData('branch_id', e.target.value)}
                                options={branches.map((b) => ({ label: b.name, value: b.id }))}
                                placeholder="اختر الفرع…"
                                required
                            />
                        </Field>
                        <p className="text-[12px] text-[#9ca3af] sm:pb-2.5">
                            {t('الأرقام تخصّ الفرع المختار وحده. اترك الحقل فارغًا لما لم تُدخله (لن يتغيّر).')}
                        </p>
                    </div>

                    {form.errors.losses && (
                        <p className="mt-3 text-[12px] text-[#b91c1c]">{form.errors.losses}</p>
                    )}

                    {/* معنى العمودين يُقال قبل أن يُكتب رقم — لا بعد أن يضيع رصيد */}
                    <dl className="mt-4 grid grid-cols-1 gap-2 border-t border-[var(--ui-border,#e8e8e8)] pt-3 text-[12px] sm:grid-cols-2">
                        <div>
                            <dt className="font-medium text-[#111]">{t('الكمية المعدودة')}</dt>
                            <dd className="text-[#6b7280]">{t('ما وجدتَه على الرفّ — يصير هو الرصيد')}</dd>
                        </div>
                        <div>
                            <dt className="font-medium text-[#111]">{t('الفاقد')}</dt>
                            <dd className="text-[#6b7280]">{t('ما عُدم — يُطرح من رصيد النظام ولا يحلّ محلّه')}</dd>
                        </div>
                    </dl>
                </Card>

                <Card className="overflow-hidden">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                {/* «رصيد النظام» لا «الدفتري»: المصطلح المحاسبي يحتاج شرحًا، ومن
                                    يقف على الرفّ يعدّ لا يقرأ اصطلاحًا. والفرع مذكورٌ في
                                    أعلى الشاشة فلا يُكرَّر في رأس العمود. */}
                                {['المنتج', 'SKU', 'رصيد النظام', 'الكمية المعدودة', 'الفاقد', 'الفرق'].map((h) => (
                                    <TableHead key={h}>{t(h)}</TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {items.map((item) => {
                                const onHand = book(item);
                                const diff = variance(item.id, onHand);

                                return (
                                    <TableRow key={item.id}>
                                        <TableCell className="font-medium text-[#111]">{item.name}</TableCell>
                                        <TableCell className="font-mono text-[#6b7280]">{item.sku}</TableCell>
                                        <TableCell className="font-semibold tabular-nums">
                                            {/* بلا فرعٍ مختار لا رقم دفتريّ يُقارن به */}
                                            {form.data.branch_id ? number(onHand) : <span className="text-[#d1d5db]">—</span>}
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                inputMode="numeric"
                                                dir="ltr"
                                                value={form.data.counts[item.id] ?? ''}
                                                onChange={(e) => setCount(item.id, e.target.value)}
                                                placeholder={t('لم يُعَدّ')}
                                                className="h-9 w-28"
                                                // بلا فرعٍ لا معنى للعدّ: الرقم يُقيَّد على فرع
                                                disabled={!form.data.branch_id || hasLoss(item.id)}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                inputMode="numeric"
                                                dir="ltr"
                                                value={form.data.losses[item.id] ?? ''}
                                                onChange={(e) => setLoss(item.id, e.target.value)}
                                                placeholder={t('لا شيء')}
                                                className="h-9 w-28"
                                                disabled={!form.data.branch_id || hasCount(item.id)}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            {diff === null ? (
                                                <span className="text-[#d1d5db]">—</span>
                                            ) : (
                                                <span
                                                    className={cn(
                                                        'font-semibold tabular-nums',
                                                        diff === 0
                                                            ? 'text-[#6b7280]'
                                                            : diff > 0
                                                              ? 'text-[#047857]'
                                                              : 'text-[#b91c1c]',
                                                    )}
                                                    dir="ltr"
                                                >
                                                    {diff > 0 ? '+' : ''}
                                                    {diff}
                                                    {/* والرصيد بعده: الفرق وحده لا يقول أين تنتهي
                                                        الكمية، وهو ما يُنظر إليه قبل الضغط */}
                                                    <span className="ms-2 text-[12px] font-normal text-[#9ca3af]">
                                                        ← {number(onHand + diff)}
                                                    </span>
                                                </span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </Card>

                <div className="mt-5 flex items-center gap-3">
                    <Button type="submit" loading={form.processing}>
                        <CheckCheck />
                        {t('تطبيق الجرد والتسوية')}
                    </Button>
                    <Button variant="outline" asChild>
                        <SmartLink routeName="admin.inventory.index" href={route('admin.inventory.index')}>
                            {t('إلغاء')}
                        </SmartLink>
                    </Button>
                </div>
            </form>
        </AdminLayout>
    );
}
