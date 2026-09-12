import { router, usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2 } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import DataTable, { type Column, type ServerPagination } from '@/Components/DataTable';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Task {
    id: number;
    title: string;
    status: string;
    statusLabel: string;
    priority: string;
    priorityLabel: string;
    assignee: string | null;
    dueAt: string | null;
    overdue: boolean;
    notes: string | null;
    lead: { id: number; name: string; stage: string; url: string } | null;
}

interface Props {
    tasks: Task[];
    pagination: ServerPagination;
    scope: string;
    counts: { open: number; overdue: number; mine: number; done: number };
}

export default function CrmTasks() {
    const { tasks, pagination, scope, counts } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const scopes = [
        { key: 'open', label: 'مفتوحة', n: counts.open },
        { key: 'overdue', label: 'متأخرة', n: counts.overdue },
        { key: 'mine', label: 'مهامّي', n: counts.mine },
        { key: 'done', label: 'منجزة', n: counts.done },
    ];

    const columns: Column<Task>[] = [
        {
            key: 'title',
            header: 'المهمة',
            cell: (task) => (
                <div className="min-w-0">
                    <div className={`truncate text-[13px] font-medium ${task.status === 'done' ? 'text-[#9ca3af] line-through' : 'text-[#111]'}`}>
                        {task.title}
                    </div>
                    {task.lead && (
                        <SmartLink
                            routeName="super-admin.crm.leads.show"
                            href={task.lead.url}
                            className="truncate text-[12px] text-[#6b7280] hover:text-[#111]"
                        >
                            {task.lead.name} · {task.lead.stage}
                        </SmartLink>
                    )}
                </div>
            ),
        },
        {
            key: 'dueAt',
            header: 'موعد الاستحقاق',
            cell: (task) => (
                <span className={`text-[13px] ${task.overdue ? 'font-medium text-[#b91c1c]' : 'text-[#4b5563]'}`}>
                    {task.overdue && <AlertTriangle className="me-1 inline size-3.5" />}
                    {task.dueAt}
                </span>
            ),
        },
        { key: 'priority', header: 'الأولوية', cell: (task) => <span className="text-[13px] text-[#4b5563]">{task.priorityLabel}</span> },
        {
            key: 'assignee',
            header: 'المسؤول',
            cell: (task) =>
                task.assignee ? (
                    <span className="text-[13px] text-[#4b5563]">{task.assignee}</span>
                ) : (
                    <span className="text-[12px] text-[#9ca3af]">{t('غير معيّن')}</span>
                ),
        },
        {
            key: 'action',
            header: '',
            align: 'end',
            cell: (task) =>
                task.status === 'open' ? (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            router.post(route('super-admin.crm.tasks.update', task.id), { status: 'done' }, { preserveScroll: true })
                        }
                    >
                        <CheckCircle2 className="size-4" />
                        {t('إنجاز')}
                    </Button>
                ) : (
                    <span className="text-[12px] text-[#9ca3af]">{task.statusLabel}</span>
                ),
        },
    ];

    return (
        <PlatformLayout title={t('المهام والمتابعات')}>
            <PageHeader
                title="المهام والمتابعات"
                actions={scopes.map((s) => (
                    <Button
                        key={s.key}
                        variant={scope === s.key ? 'primary' : 'outline'}
                        size="sm"
                        onClick={() => router.get(route('super-admin.crm.tasks'), { scope: s.key }, { preserveScroll: true })}
                    >
                        {t(s.label)} ({s.n})
                    </Button>
                ))}
            />

            <DataTable
                rows={tasks}
                columns={columns}
                rowKey={(task) => task.id}
                empty={t('لا مهام في هذا العرض.')}
                server={{ pagination, params: { scope } }}
            />
        </PlatformLayout>
    );
}
