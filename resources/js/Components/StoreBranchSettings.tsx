import Field from '@/Components/Field';
import Toggle from '@/Components/Toggle';
import { useTranslate } from '@/lib/i18n';

export interface StoreBranch {
    id: number;
    name: string;
}

/**
 * فرعُ طلبات الموقع، واختيارُ الزبون فرعَ استلامه — `Store\StoreBranches`.
 *
 * لا يُعرض لمتجرٍ بفرعٍ واحد: لا شيءَ يُختار. والفارغُ «أول فروع المتجر» —
 * ما كان يقع لكلّ طلبٍ قبل هذا المقبض — فمتجرٌ لم يلمسه لا يتبدّل عليه شيء.
 */
export default function StoreBranchSettings({
    branches,
    branchId,
    onBranch,
    choice,
    onChoice,
    error,
}: {
    branches: StoreBranch[];
    branchId: string;
    onBranch: (id: string) => void;
    choice: boolean;
    onChoice: (on: boolean) => void;
    error?: string;
}) {
    const t = useTranslate();

    if (branches.length < 2) {
        return null;
    }

    return (
        <div className="space-y-4" data-testid="store-branch-settings">
            <Field
                label="فرع طلبات الموقع"
                hint="منه يُنفَّذ طلب الموقع ويُخصم مخزونه. فارغًا: أول فروع المتجر كما كان"
                error={error}
            >
                <select
                    value={branchId}
                    onChange={(e) => onBranch(e.target.value)}
                    className="h-10 w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 text-sm"
                    aria-label={t('فرع طلبات الموقع')}
                    data-testid="store-branch"
                >
                    <option value="">{t('أول فروع المتجر')}</option>
                    {branches.map((b) => (
                        <option key={b.id} value={String(b.id)}>
                            {b.name}
                        </option>
                    ))}
                </select>
            </Field>
            <Toggle
                on={choice}
                onChange={onChoice}
                label="يختار الزبون فرع الاستلام"
                hint="حين يختار «استلام من المحل» يرى فروعك ويختار منها، ويُخصم المخزون من الفرع الذي اختاره. والتوصيل من فرع طلبات الموقع"
            />
        </div>
    );
}
