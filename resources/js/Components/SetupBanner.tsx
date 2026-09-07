import { Link, usePage } from '@inertiajs/react';
import { Store } from 'lucide-react';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

/**
 * شريطُ تهيئة المتجر.
 *
 * التاجرُ يهبط على لوحةٍ فيها أربعةَ عشرَ قسمًا ولا شيءَ يقول له بمَ يبدأ.
 * فيبيع شهرًا واسمُ متجره «متجري» — وتخرج فواتيرُه الضريبيّة بذلك الاسم.
 * ولم يكن ذلك إهمالًا منه: النظام لم يسأله.
 *
 * ويظهر في كلّ شاشة لا في اللوحة وحدها: من يهبط على «المنتجات» أوّلًا لا
 * يمرّ باللوحة. ويختفي بإقرار الهويّة — شريطٌ لا ينطفئ يُقرأ زخرفةً.
 *
 * ونصُّه يقول ما ينقص بالاسم: «أكمل بياناتك» لا تُقرأ ولا تُنفَّذ، و«اسم
 * المتجر — العنوان» تُقرأ وتُنفَّذ.
 */
export default function SetupBanner() {
    const { context } = usePage<PageProps>().props;
    const t = useTranslate();
    const setup = context?.setup;

    if (!setup) {
        return null;
    }

    return (
        <div className="mb-4 flex flex-wrap items-center gap-2 rounded-[12px] border border-[#bfdbfe] bg-[#eff6ff] px-4 py-3 text-[13px] text-[#1d4ed8]">
            <Store className="size-4 shrink-0" />
            <span className="font-medium">
                {t('متجرك لم يُسمَّ بعد — واسمُه يُطبع على كلّ فاتورة.')}
            </span>
            {setup.missing.length > 0 && (
                <span className="text-[12px] opacity-80">{setup.missing.join(' · ')}</span>
            )}
            <Link
                href="/admin/setup"
                className="ms-auto rounded-[8px] bg-[#1d4ed8] px-3 py-1.5 text-[12px] font-medium text-white transition hover:bg-[#1e40af]"
            >
                {t('أكملها الآن')} ({setup.done}/{setup.total})
            </Link>
        </div>
    );
}
