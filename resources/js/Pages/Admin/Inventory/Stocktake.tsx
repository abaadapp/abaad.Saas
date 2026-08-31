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
     * وضعان لا واحد.
     *
     * كانت الشاشة تسأل «الكمية المعدودة» وتعتمدها رصيدًا جديدًا. ومن كتب
     * فيها الكمية المعدومة — ثلاث ورداتٍ تلفت — صار رصيده ثلاثًا بدل سبعٍ
     * وتسعين: رقمٌ مشروع في حقلٍ مشروع، وتسعون وردةً تختفي بلا رسالةٍ ولا
     * أثر. فصار السؤال يُقال صراحةً قبل أن يُكتب رقم.
     *
     * والافتراضيّ «المعدود» كما كان: قلبُ معنى شاشةٍ تحت يد من يستعملها
     * أخطر من العطب نفسه.
     */
    const form = useForm<{ branch_id: string; mode: 'count' | 'loss'; counts: Record<number, string> }>({
        branch_id: currentBranch ? String(currentBranch) : '',
        mode: 'count',
        counts: {},
    });

    const isLoss = form.data.mode === 'loss';

    const setCount = (id: number, value: string) =>
        form.setData('counts', { ...form.data.counts, [id]: value });

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
     * null يعني «لم يُدخَل شيء» فلا يُحتسب. وفي وضع المعدوم الرقم طرحٌ لا
     * رصيد، فالفرق سالبٌ دائمًا.
     */
    const variance = (id: number, book: number): number | null => {
        const raw = form.data.counts[id];
        if (raw === undefined || raw === '') return null;
        const n = Number(raw);
        if (!Number.isFinite(n)) return null;

        return isLoss ? -n : n - book;
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.inventory.stocktake.apply'));
    };

    return (
        <AdminLayout title="الجرد الفعلي">
            <PageHeader
                title="الجرد الفعلي"
                subtitle={t('اختر ماذا تُدخل: الكمية المعدودة فعليًا، أو الكمية المعدومة التي تُطرح من الرصيد')}
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

                    {/* المبدّل بجانب الفرع لا في زاوية: هو ما يقرّر معنى كلّ
                        رقمٍ في الجدول، فيُقرأ قبل أن يُكتب رقم */}
                    <div className="mt-4 border-t border-[var(--ui-border,#e8e8e8)] pt-4">
                        <span className="mb-2 block text-[13px] font-medium text-[#111]">{t('ماذا تُدخل؟')}</span>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            {[
                                {
                                    key: 'count' as const,
                                    title: 'الكمية المعدودة',
                                    hint: 'ما وجدته على الرفّ — يصير هو الرصيد',
                                },
                                {
                                    key: 'loss' as const,
                                    title: 'الكمية المعدومة',
                                    hint: 'ما تلف — يُطرح من الرصيد ولا يحلّ محلّه',
                                },
                            ].map((option) => (
                                <button
                                    key={option.key}
                                    type="button"
                                    onClick={() => form.setData('mode', option.key)}
                                    className={cn(
                                        'flex-1 rounded-[12px] border p-3 text-start transition-colors',
                                        form.data.mode === option.key
                                            ? 'border-[#111] bg-[#111] text-white'
                                            : 'border-[#e8e8e8] bg-white text-[#4b4b4b] hover:bg-[#f7f7f5]',
                                    )}
                                >
                                    <span className="block text-[14px] font-semibold">{t(option.title)}</span>
                                    <span className="mt-0.5 block text-[12px] opacity-80">{t(option.hint)}</span>
                                </button>
                            ))}
                        </div>
                    </div>
                </Card>

                <Card className="overflow-hidden">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                {/* «رصيد النظام» لا «الدفتري»: المصطلح المحاسبي يحتاج شرحًا، ومن
                                    يقف على الرفّ يعدّ لا يقرأ اصطلاحًا. والفرع مذكورٌ في
                                    أعلى الشاشة فلا يُكرَّر في رأس العمود. */}
                                {['المنتج', 'SKU', 'رصيد النظام', isLoss ? 'الكمية المعدومة' : 'الكمية المعدودة', 'الفرق'].map((h) => (
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
                                                placeholder={t(isLoss ? 'لا شيء' : 'لم يُعَدّ')}
                                                className="h-9 w-28"
                                                // بلا فرعٍ لا معنى للعدّ: الرقم يُقيَّد على فرع
                                                disabled={!form.data.branch_id}
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
                        {t(isLoss ? 'خصم الكمية المعدومة' : 'تطبيق الجرد والتسوية')}
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
