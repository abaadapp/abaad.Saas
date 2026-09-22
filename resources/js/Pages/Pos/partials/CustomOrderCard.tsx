import { Plus, Sparkles } from 'lucide-react';
import { useTranslate } from '@/lib/i18n';

interface Props {
    /** ما يُكتب على البطاقة — اسمُ القالب حين يكون واحدًا، وإلّا «تخصيص الطلب» */
    label: string;
    onClick: () => void;
}

/**
 * بطاقةُ «تخصيص الطلب» — ثابتةٌ في أوّل شبكة الصندوق.
 *
 * ═══ ولمَ بطاقةٌ لا زرٌّ في شريط الأقسام ═══
 *
 * كان مدخلُ الطلب المخصَّص زرًّا منقّطًا بين الأقسام، فلم يُرَ: الكاشير يقرأ
 * الشبكةَ ويضغط ما فيها، والشريطُ عنده مُرشِّحٌ لا بضاعة. وبطاقةٌ في أوّل
 * الشبكة تبقى في مكانها مهما تبدّل القسمُ أو البحث — فيجدها من يبحث عنها
 * ومن لا يبحث.
 *
 * وشكلُها يقول إنّها ليست صنفًا: حدٌّ منقّط ولا صورةَ ولا رصيد. ولا سعرَ
 * عليها — السعرُ يُقال في التخصيص.
 */
export default function CustomOrderCard({ label, onClick }: Props) {
    const t = useTranslate();

    return (
        <button
            type="button"
            onClick={onClick}
            data-testid="pos-custom-card"
            className="group select-none overflow-hidden rounded-2xl border-2 border-dashed border-[#c4b5fd] bg-gradient-to-br from-[#faf5ff] to-white text-start shadow-sm transition-[border-color,box-shadow] hover:border-[#7c3aed] hover:shadow-md"
        >
            <div className="flex aspect-square items-center justify-center">
                <span className="flex size-16 items-center justify-center rounded-full bg-[#ede9fe] text-[#7c3aed] transition-transform duration-300 group-hover:scale-105">
                    <Sparkles className="size-8" />
                </span>
            </div>
            <div className="p-3">
                <h3 className="truncate text-sm font-semibold text-[#5b21b6]">{label}</h3>
                <p className="mt-0.5 text-xs text-gray-400">{t('على ذوق الزبون')}</p>
                <div className="mt-2 flex items-center justify-between gap-1">
                    <p className="text-xs font-bold text-[#7c3aed]">{t('السعر عند التخصيص')}</p>
                    <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#ede9fe] text-[#7c3aed] transition-colors group-hover:bg-[#7c3aed] group-hover:text-white">
                        <Plus className="size-4" />
                    </span>
                </div>
            </div>
        </button>
    );
}
