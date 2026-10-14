import type { ReactNode } from 'react';
import { useTranslate } from '@/lib/i18n';

interface PageHeaderProps {
    title: string;
    subtitle?: string;
    actions?: ReactNode;
}

/**
 * ترويسة الصفحة.
 *
 * بلا سلسلة صفحات: كانت كل صفحة تبدأ بـ«الرئيسية ‹ القسم» — سطرٌ يقول ما
 * تقوله القائمة الجانبية وهي ظاهرةٌ على الشاشة نفسها، والقسمُ فيها مضيءٌ
 * أصلًا. وعمقُ اللوحة مستويان أو ثلاثة، فلا أحد يضلّ فيها ليحتاج أثرًا
 * يعود عليه.
 */
export default function PageHeader({ title, subtitle, actions }: PageHeaderProps) {
    const t = useTranslate();

    return (
        <div
            data-page-header
            className="mb-5 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between"
        >
            <div className="min-w-0">
                <h1 className="truncate text-[22px] font-bold tracking-tight text-[#111]">{t(title)}</h1>
                {subtitle && <p className="mt-0.5 text-[13px] text-[#6b7280]">{subtitle}</p>}
            </div>

            {/*
                الأزرارُ تلتفّ ولا تدفع الصفحة.

                كانت `shrink-0`: في صفٍّ واحدٍ مع العنوان (من `sm` فما فوق) يأخذ
                صفُّ الأزرار عرضَه كاملًا ولا يلتفّ أبدًا — فصفحةُ الطلب بأزرارها
                السبعة (~1260px) كانت تمدّ الصفحةَ كلَّها على الجهاز اللوحيّ،
                فيُقصّ عمودُ «المنتج» وتسمياتُ المجاميع.

                فالترويسةُ تلتفّ (`sm:flex-wrap`): ما يتّسع للأزرار بجوار العنوان
                يبقى كما كان بلا انضغاط، وما لا يتّسع ينزل سطرًا تحته. و`min-w-0`
                يجعل الصفَّ في سطره يضيق إلى عرض الصفحة فتلتفّ أزرارُه داخله.
            */}
            {actions && <div className="flex min-w-0 flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}
