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
        <div className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0">
                <h1 className="truncate text-[22px] font-bold tracking-tight text-[#111]">{t(title)}</h1>
                {subtitle && <p className="mt-0.5 text-[13px] text-[#6b7280]">{subtitle}</p>}
            </div>

            {/*
                flex-wrap: shrink-0 وحده يمنع الانضغاط على الشاشات الواسعة —
                وهو مطلوب — لكنه على الجوّال يدفع الأزرار خارج الشاشة فيظهر
                تمريرٌ أفقي للصفحة كلّها ويُقصّ آخر زرّ. الالتفاف ينقلها إلى
                سطر ثانٍ بدل ذلك. ظهر هذا في «المالية» حيث الأزرار أربعة.
            */}
            {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}
