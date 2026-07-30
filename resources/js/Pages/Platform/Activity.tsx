import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import {
    History,
    LogIn,
    LogOut,
    type LucideIcon,
    Pause,
    Pencil,
    Plus,
    RefreshCw,
    Settings,
    ShoppingCart,
    Timer,
    Trash2,
} from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import Field, { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { ServerPagination } from '@/Components/DataTable';

interface Log {
    user: string;
    action: string;
    description: string;
    icon: string;
    color: string;
    ago: string;
    time: string;
    ip: string | null;
}

interface Props {
    logs: Log[];
    pagination: ServerPagination;
    filters: Record<string, string | null>;
}

const ACTION_LABEL: Record<string, string> = {
    created: 'إضافة',
    updated: 'تعديل',
    deleted: 'حذف',
    login: 'دخول',
    logout: 'خروج',
    checkout: 'بيع',
    status: 'تغيير حالة',
    settings: 'إعدادات',
    hold: 'تعليق',
    shift: 'وردية',
};

/** خريطة صريحة — الاستيراد الشامل من lucide يضخّ المكتبة كاملة في الحزمة */
const ICONS: Record<string, LucideIcon> = {
    plus: Plus,
    pencil: Pencil,
    'trash-2': Trash2,
    'log-in': LogIn,
    'log-out': LogOut,
    'shopping-cart': ShoppingCart,
    'refresh-cw': RefreshCw,
    settings: Settings,
    pause: Pause,
    timer: Timer,
    history: History,
};

const TONE: Record<string, string> = {
    success: 'bg-[#ecfdf5] text-[#047857]',
    info: 'bg-[#eff6ff] text-[#2563eb]',
    danger: 'bg-[#fef2f2] text-[#b91c1c]',
    warning: 'bg-[#fffbeb] text-[#d97706]',
    primary: 'bg-[#f5f3ff] text-[#6d28d9]',
    gray: 'bg-[#f2f2f0] text-[#6b7280]',
};

/**
 * سجل نشاط المنصة — نفس صفحة سجل التاجر شكلًا، ويختلف نطاقها: هذه تقرأ
 * ActivityLog كله بلا تقييد بـbusiness_id (انظر ActivityController::superIndex).
 */
export default function Activity() {
    const { logs, pagination, filters } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [q, setQ] = useState(filters.q ?? '');
    const [action, setAction] = useState(filters.action ?? '');

    const go = (patch: Record<string, string | number | null>) => {
        const next: Record<string, string | number> = {};
        Object.entries({ q, action, ...patch }).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '') next[k] = v as string | number;
        });
        router.get(route('super-admin.activity.index'), next, { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <PlatformLayout title="سجل النشاط">
            <PageHeader
                title="سجل النشاط"
                subtitle={t('سجلّ كامل بكل العمليات على المنصة وكل الشركات')}
                breadcrumbs={[{ label: 'الرئيسية', href: route('super-admin.dashboard') }, { label: 'سجل النشاط' }]}
            />

            <Card className="mb-6 p-4">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        go({ page: null });
                    }}
                    className="grid grid-cols-1 gap-3 md:grid-cols-4"
                >
                    <Field className="md:col-span-2">
                        <Input
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder={t('بحث في الوصف أو المستخدم...')}
                        />
                    </Field>
                    <Field>
                        <Select
                            value={action}
                            onChange={(e) => {
                                setAction(e.target.value);
                                go({ action: e.target.value || null, page: null });
                            }}
                            placeholder="كل العمليات"
                            options={[
                                { label: 'إضافة', value: 'created' },
                                { label: 'تعديل', value: 'updated' },
                                { label: 'حذف', value: 'deleted' },
                                { label: 'بيع', value: 'checkout' },
                                { label: 'تغيير حالة', value: 'status' },
                                { label: 'دخول', value: 'login' },
                                { label: 'إعدادات', value: 'settings' },
                            ]}
                        />
                    </Field>
                    <div className="flex items-center gap-2">
                        <Button type="submit">{t('تصفية')}</Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setQ('');
                                setAction('');
                                router.get(route('super-admin.activity.index'));
                            }}
                        >
                            {t('عرض الكل')}
                        </Button>
                    </div>
                </form>
            </Card>

            <Card>
                {logs.length === 0 ? (
                    <div className="px-5 py-16 text-center">
                        <History className="mx-auto size-8 text-[#d1d5db]" />
                        <p className="mt-3 font-medium text-[#111]">{t('لا يوجد نشاط بعد')}</p>
                        <p className="mt-1 text-[13px] text-[#9ca3af]">
                            {t('ستظهر هنا كل العمليات التي تُجرى على النظام.')}
                        </p>
                    </div>
                ) : (
                    logs.map((log, i) => {
                        const Icon = ICONS[log.icon] ?? History;

                        return (
                            <motion.div
                                key={i}
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                transition={{ duration: 0.2, delay: Math.min(i * 0.015, 0.2) }}
                                className={cn(
                                    'flex items-start gap-3 px-5 py-4',
                                    i < logs.length - 1 && 'border-b border-[#f5f5f4]',
                                )}
                            >
                                <span
                                    className={cn(
                                        'flex size-10 shrink-0 items-center justify-center rounded-[12px]',
                                        TONE[log.color] ?? TONE.primary,
                                    )}
                                >
                                    <Icon className="size-5" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="text-sm font-medium text-[#111]">{log.user}</p>
                                        <Badge
                                            variant={
                                                (log.color as 'success' | 'info' | 'danger' | 'warning' | 'primary') ??
                                                'neutral'
                                            }
                                        >
                                            {t(ACTION_LABEL[log.action] ?? log.action)}
                                        </Badge>
                                    </div>
                                    <p className="mt-0.5 text-sm text-[#4b4b4b]">{log.description}</p>
                                    <p className="mt-1 text-[12px] text-[#9ca3af]">
                                        {log.ago} · {log.time} · IP {log.ip ?? '—'}
                                    </p>
                                </div>
                            </motion.div>
                        );
                    })
                )}

                {pagination.last_page > 1 && (
                    <div className="flex items-center justify-between gap-3 border-t border-[var(--ui-border,#e8e8e8)] px-5 py-3">
                        <p className="text-[12px] text-[#6b7280]">
                            {pagination.from ?? 0}–{pagination.to ?? 0} {t('من')} {number(pagination.total)}
                        </p>
                        <div className="flex gap-1.5">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={pagination.current_page <= 1}
                                onClick={() => go({ page: pagination.current_page - 1 })}
                            >
                                {t('السابق')}
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={pagination.current_page >= pagination.last_page}
                                onClick={() => go({ page: pagination.current_page + 1 })}
                            >
                                {t('التالي')}
                            </Button>
                        </div>
                    </div>
                )}
            </Card>
        </PlatformLayout>
    );
}
