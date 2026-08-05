import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import { useTranslate } from '@/lib/i18n';

export interface Crumb {
    label: string;
    href?: string;
}

interface PageHeaderProps {
    title: string;
    subtitle?: string;
    breadcrumbs?: Crumb[];
    actions?: ReactNode;
}

/** ترويسة الصفحة — مطابقة لـ<x-page-header> في Blade */
export default function PageHeader({ title, subtitle, breadcrumbs, actions }: PageHeaderProps) {
    const t = useTranslate();

    return (
        <div className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0">
                {breadcrumbs && breadcrumbs.length > 0 && (
                    <nav className="mb-1.5 flex items-center gap-1 text-[12px] text-[#9ca3af]">
                        {breadcrumbs.map((crumb, i) => (
                            <span key={i} className="flex items-center gap-1">
                                {i > 0 && <ChevronLeft className="size-3" />}
                                {crumb.href ? (
                                    <Link href={crumb.href} className="transition-colors hover:text-[#111]">
                                        {t(crumb.label)}
                                    </Link>
                                ) : (
                                    <span>{t(crumb.label)}</span>
                                )}
                            </span>
                        ))}
                    </nav>
                )}
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
