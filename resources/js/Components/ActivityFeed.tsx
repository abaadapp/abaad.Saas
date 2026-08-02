import { Activity as ActivityIcon } from 'lucide-react';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface ActivityItem {
    text: string;
    time: string;
    icon: string;
    color: string;
}

/** ألوان ActivityLog::COLORS في الخادم — الاسم هناك، الطلاء هنا */
const TONE: Record<string, string> = {
    primary: 'bg-[#f5f3ff] text-[#6d28d9]',
    success: 'bg-[#ecfdf5] text-[#047857]',
    warning: 'bg-[#fffbeb] text-[#d97706]',
    danger: 'bg-[#fef2f2] text-[#b91c1c]',
    info: 'bg-[#eff6ff] text-[#2563eb]',
    gray: 'bg-[#f2f2f0] text-[#6b7280]',
};

/**
 * سجل نشاط مشترك بين ملف الموظف وملف مستخدم المنصة — نفس شكل البيانات
 * الآتي من Demo::userActivities، فلا داعي لتكرار العرض في الصفحتين.
 */
export default function ActivityFeed({
    items,
    empty = 'لا يوجد نشاط بعد',
}: {
    items: ActivityItem[];
    empty?: string;
}) {
    const t = useTranslate();

    if (items.length === 0) {
        return <p className="py-10 text-center text-sm text-[#9ca3af]">{t(empty)}</p>;
    }

    return (
        <ul className="space-y-5">
            {items.map((a, i) => (
                <li key={i} className="flex items-start gap-3">
                    <span
                        className={cn(
                            'flex size-9 shrink-0 items-center justify-center rounded-[12px]',
                            TONE[a.color] ?? TONE.primary,
                        )}
                    >
                        <ActivityIcon className="size-4" />
                    </span>
                    <div className="min-w-0">
                        <p className="text-sm text-[#4b4b4b]">{a.text}</p>
                        <p className="mt-0.5 text-[12px] text-[#9ca3af]">{a.time}</p>
                    </div>
                </li>
            ))}
        </ul>
    );
}
