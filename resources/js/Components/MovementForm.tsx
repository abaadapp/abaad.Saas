import { useMemo, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { ArrowLeftRight, Check } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';

export interface Movement {
    value: string;
    label: string;
    hint: string;
    /** سؤال الشاشة عن الجهة — و null يعني أنّ النوع يحدّدها */
    asks: string | null;
    direction: string;
}

interface Props {
    movements: Movement[];
    /** أنواع المصروفات كما عرّفها التاجر — اختياريّة في النموذج */
    expenseTypes: string[];
    today: string;
    onCancel: () => void;
    onSuccess: () => void;
}

/**
 * «ماذا حدث؟» — نموذج الحركة المالية، بلا مدينٍ ولا دائن.
 *
 * التاجر يعرف أنه دفع إيجارًا أو حوّل من الدرج إلى البنك؛ ولا يعرف — ولا
 * يلزمه أن يعرف — أنّ الأوّل يُقيَّد مدينَ مصروفاتٍ دائنَ صندوق. فالوصفة في
 * الخادم (`Books::MOVEMENTS`) ولا تُرسَل إلى هنا: شاشةٌ تعرف الحسابات تُغري
 * بأن تجعل التاجر يختار منها.
 *
 * ويعيش في موضعين: شاشة الحركة المالية حيث هو البابُ الوحيد، وتبويب «مبسّط»
 * في نافذة القيد الجديد حيث هو البابُ الذي يُغني المحاسبَ عن كتابة سطرين
 * بيده لحركةٍ يعرفها النظام. ونسختان منه كانتا ستفترقان: يُضاف حقلٌ هنا ولا
 * يُضاف هناك، ولا يُنبّه شيء.
 */
export default function MovementForm({ movements, expenseTypes, today, onCancel, onSuccess }: Props) {
    const t = useTranslate();

    /*
     * معرّفٌ لكل فتحةِ نموذج — لا لكل ضغطةِ حفظ.
     *
     * ضغطتان متتاليتان على «حفظ» (أو إعادةُ إرسالٍ بعد انقطاع) تحملان
     * المعرّف نفسه، فيردّ الخادم الحركة الأولى ولا يكتب ثانية. والنافذة
     * تُفرَّغ عند إغلاقها فيُبنى المكوّن من جديد بمعرّفٍ جديد، وحركتان
     * متشابهتان عمدًا تُسجَّلان كلتاهما.
     */
    const [uuid] = useState(() => crypto.randomUUID());

    const form = useForm({
        kind: '',
        amount: '',
        side: 'cash',
        description: '',
        expense_type: '',
        occurred_at: today,
        client_uuid: uuid,
    });

    const selected = useMemo(
        () => movements.find((x) => x.value === form.data.kind) ?? null,
        [movements, form.data.kind],
    );

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.finance.store'), {
            preserveScroll: true,
            onSuccess,
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4 px-5 pb-5">
            <Field
                label="نوع الحركة"
                hint={selected?.hint ?? 'اختر ما حدث فعلًا — والنظام يكتب قيده'}
                error={form.errors.kind}
                required
            >
                <Select
                    options={movements.map((x) => ({
                        label: x.label,
                        value: x.value,
                    }))}
                    placeholder={t('اختر…')}
                    value={form.data.kind}
                    onChange={(e) => form.setData('kind', e.target.value)}
                />
            </Field>

            {selected?.asks && (
                <Field label={selected.asks} error={form.errors.side} required>
                    <Select
                        options={[
                            { label: t('الصندوق (نقدًا)'), value: 'cash' },
                            { label: t('البنك'), value: 'bank' },
                        ]}
                        value={form.data.side}
                        onChange={(e) => form.setData('side', e.target.value)}
                    />
                </Field>
            )}

            {form.data.kind === 'expense' && expenseTypes.length > 0 && (
                <Field
                    label="نوع المصروف"
                    hint="اختياريّ — يُرشَّح به في شاشة المصروفات"
                    error={form.errors.expense_type}
                >
                    <Select
                        options={expenseTypes.map((x) => ({
                            label: x,
                            value: x,
                        }))}
                        placeholder={t('مصروف عام')}
                        value={form.data.expense_type}
                        onChange={(e) => form.setData('expense_type', e.target.value)}
                    />
                </Field>
            )}

            {selected && !selected.asks && (
                <p className="flex items-center gap-2 rounded-[10px] bg-[#f5f5f5] px-3 py-2 text-[13px] text-[#6b7280]">
                    <ArrowLeftRight className="size-4 shrink-0" />
                    {t('المال ينتقل بين الصندوق والبنك — لا يُقرأ دخلًا ولا مصروفًا ولا يمسّ الربح.')}
                </p>
            )}

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="المبلغ" error={form.errors.amount} required>
                    <Input
                        type="number"
                        step="0.001"
                        min="0.001"
                        dir="ltr"
                        value={form.data.amount}
                        onChange={(e) => form.setData('amount', e.target.value)}
                    />
                </Field>
                <Field label="التاريخ" error={form.errors.occurred_at}>
                    <Input
                        type="date"
                        dir="ltr"
                        value={form.data.occurred_at}
                        onChange={(e) => form.setData('occurred_at', e.target.value)}
                    />
                </Field>
            </div>

            <Field
                label="البيان"
                hint="ما يُذكّرك بها بعد شهر — «إيجار المحل عن سبتمبر»"
                error={form.errors.description}
            >
                <Input
                    value={form.data.description}
                    onChange={(e) => form.setData('description', e.target.value)}
                />
            </Field>

            <div className="flex justify-end gap-2 pt-2">
                <Button type="button" variant="ghost" onClick={onCancel}>
                    {t('إلغاء')}
                </Button>
                <Button type="submit" loading={form.processing} disabled={!form.data.kind}>
                    <Check />
                    {t('حفظ')}
                </Button>
            </div>
        </form>
    );
}
