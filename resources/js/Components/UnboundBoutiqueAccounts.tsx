import { Link } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { useTranslate } from '@/lib/i18n';

export interface UnboundBoutiqueAccount {
    id: number;
    name: string;
}

/**
 * حساباتٌ تحمل «المنتجات» وحدها ولم تُربط ببوتيك — لصاحب النشاط في متجرٍ يُؤوي بوتيكات.
 *
 * هي هيئةُ حساب صاحب البوتيك قبل الربط: يفتح أصنافَ المتجر كلَّها، ويُسند أيَّ صنفٍ
 * إلى أيّ بوتيك. فيُسمّى كلٌّ منها برابطٍ إلى شاشة تعديله، فيربطه التاجرُ ببوتيكه أو
 * يعطّله بيده. لا ربطَ تلقائيًّا ولا تعطيل، ولا يُغلق التنبيه ما دام الحسابُ كما هو.
 * والقائمةُ من الخادم (`BoutiqueAccount::unbound`) — فارغةٌ لغير صاحب النشاط.
 */
export function UnboundBoutiqueAccountsNotice({ accounts }: { accounts?: UnboundBoutiqueAccount[] | null }) {
    const t = useTranslate();

    if (!accounts || accounts.length === 0) return null;

    return (
        <div role="status" data-testid="unbound-boutique-accounts" className="mb-4 rounded-xl border border-[#fde68a] bg-[#fffbeb] px-4 py-3 text-sm text-[#92400e]">
            <span className="flex items-start gap-2">
                <ShieldAlert className="mt-0.5 size-5 shrink-0" />
                <span>
                    <strong className="block font-semibold">{t('حساباتٌ تفتح أصناف المتجر كلَّها ولم تُربط ببوتيك')}</strong>
                    {t('إن كان أحدُها لصاحب بوتيك فاربطه ببوتيكه من شاشة الموظف ليرى أصنافه وحدها، أو عطّله. لا يُربط حسابٌ ولا يُعطَّل تلقائيًّا.')}
                </span>
            </span>
            <ul className="mt-2 flex flex-wrap gap-2 ps-7">
                {accounts.map((account) => (
                    <li key={account.id}>
                        <Link
                            href={route('admin.employees.edit', account.id)}
                            className="inline-flex items-center rounded-[8px] border border-[#fcd34d] bg-white px-2.5 py-1 font-medium text-[#92400e] hover:bg-[#fef3c7]"
                        >
                            {account.name}
                        </Link>
                    </li>
                ))}
            </ul>
        </div>
    );
}
