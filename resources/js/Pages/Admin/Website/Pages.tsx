import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { ChevronDown, FileText, Home, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { WEBSITE_TABS } from '@/Components/SectionTabs';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { SiteShell } from './shell';

interface Row {
    id: number;
    key: string;
    title: string;
    slug: string;
    status: string;
    is_home: boolean;
    removable: boolean;
    sections: number;
    seo: { title: string; description: string; image: string };
}

interface Props extends SiteShell {
    pages: Row[];
    templates: { key: string; label: string; hint: string; sections: string[] }[];
    statuses: { value: string; label: string }[];
}

const TONE: Record<string, 'success' | 'neutral' | 'warning'> = {
    published: 'success',
    draft: 'neutral',
    hidden: 'warning',
};

/**
 * الصفحات — قائمتها وترتيبها وسيو كلٍّ منها.
 *
 * والصفحة الجديدة تُبنى من قالبٍ لا من فراغ: «من نحن» تأتي بفقرتها وأرقامها،
 * و«تواصل معنا» بهاتف التاجر وعنوانه. فيصل إلى المحرّر وأمامه شيءٌ يعدّله.
 */
export default function Pages() {
    const { pages, templates, statuses } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<Row | null>(null);

    const addForm = useForm({ title: '', slug: '', template: 'blank' });
    const editForm = useForm({
        title: '',
        slug: '',
        status: 'draft',
        seo: { title: '', description: '', image: '' },
    });

    const openEdit = (row: Row) => {
        editForm.clearErrors();
        editForm.setDefaults({
            title: row.title,
            slug: row.slug,
            status: row.status,
            seo: { ...row.seo },
        });
        editForm.reset();
        setEditing(row);
    };

    const move = (index: number, delta: number) => {
        const target = index + delta;

        if (target < 0 || target >= pages.length) return;

        const order = pages.map((p) => p.id);
        [order[index], order[target]] = [order[target], order[index]];

        router.post(route('admin.website.pages.reorder'), { order }, { preserveScroll: true });
    };

    return (
        <AdminLayout title="صفحات الموقع">
            <PageHeader
                title="الصفحات"
                subtitle={t('صفحات موقعك وترتيبها في القائمة — والترتيب هنا هو ترتيبها عند الزائر')}
                actions={
                    <Button onClick={() => setAdding(true)}>
                        <Plus />
                        {t('صفحة جديدة')}
                    </Button>
                }
            />

            <SectionTabs tabs={WEBSITE_TABS} current="admin.website.pages" />

            <Card className="overflow-hidden">
                {pages.map((p, i) => (
                    <div
                        key={p.id}
                        className="flex flex-wrap items-center gap-2 border-b border-[var(--ui-border,#e8e8e8)] px-3 py-3 last:border-0"
                    >
                        <div className="flex shrink-0 flex-col">
                            <button
                                type="button"
                                aria-label={t('لأعلى')}
                                disabled={i === 0}
                                onClick={() => move(i, -1)}
                                className="px-1 text-[#9ca3af] hover:text-[#111] disabled:opacity-30"
                            >
                                <ChevronDown className="size-3.5 rotate-180" />
                            </button>
                            <button
                                type="button"
                                aria-label={t('لأسفل')}
                                disabled={i === pages.length - 1}
                                onClick={() => move(i, 1)}
                                className="px-1 text-[#9ca3af] hover:text-[#111] disabled:opacity-30"
                            >
                                <ChevronDown className="size-3.5" />
                            </button>
                        </div>

                        <span className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-[#f5f5f5] text-[#6b7280]">
                            {p.is_home ? <Home className="size-4" /> : <FileText className="size-4" />}
                        </span>

                        <div className="min-w-0 flex-1">
                            <p className="flex flex-wrap items-center gap-2">
                                <span className="truncate font-semibold text-[#111]">{p.title}</span>
                                <Badge variant={TONE[p.status] ?? 'neutral'}>
                                    {statuses.find((s) => s.value === p.status)?.label ?? p.status}
                                </Badge>
                                {p.is_home && <Badge variant="info">{t('الرئيسية')}</Badge>}
                            </p>
                            <p className="mt-0.5 flex flex-wrap items-center gap-2 text-[12px] text-[#9ca3af]">
                                <span dir="ltr" className="font-mono">
                                    {p.slug}
                                </span>
                                <span>
                                    · {number(p.sections)} {t('قسمًا')}
                                </span>
                                {!p.seo.description && (
                                    <span className="text-[#d97706]">· {t('بلا وصفٍ لمحركات البحث')}</span>
                                )}
                            </p>
                        </div>

                        <div className="flex shrink-0 items-center gap-1">
                            <Button variant="outline" size="sm" asChild>
                                <SmartLink
                                    routeName="admin.website.editor"
                                    href={route('admin.website.editor', p.id)}
                                >
                                    {t('تحرير')}
                                </SmartLink>
                            </Button>
                            <Button variant="ghost" size="icon" aria-label={t('الإعدادات')} onClick={() => openEdit(p)}>
                                <Pencil />
                            </Button>
                            {p.removable && !p.is_home && (
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="text-[#b91c1c]"
                                    aria-label={t('حذف')}
                                    onClick={() => {
                                        if (!confirm(t('حذف صفحة :name وكل أقسامها؟', { name: p.title }))) return;
                                        router.delete(route('admin.website.pages.destroy', p.id));
                                    }}
                                >
                                    <Trash2 />
                                </Button>
                            )}
                        </div>
                    </div>
                ))}
            </Card>

            {/* ===== صفحة جديدة ===== */}
            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('صفحة جديدة')}</DialogTitle>
                    </DialogHeader>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            addForm.post(route('admin.website.pages.store'));
                        }}
                        className="space-y-4 px-5 pb-5"
                    >
                        <Field label="اسم الصفحة" required error={addForm.errors.title}>
                            <Input
                                value={addForm.data.title}
                                onChange={(e) => addForm.setData('title', e.target.value)}
                                placeholder={t('سياسة الاستبدال')}
                            />
                        </Field>

                        <Field
                            label="الرابط"
                            hint="اتركه فارغًا ليُبنى من الاسم"
                            error={addForm.errors.slug}
                        >
                            <Input
                                dir="ltr"
                                value={addForm.data.slug}
                                onChange={(e) => addForm.setData('slug', e.target.value)}
                                placeholder="/returns"
                            />
                        </Field>

                        <Field label="ابدأ من" error={addForm.errors.template}>
                            <Select
                                value={addForm.data.template}
                                options={templates.map((x) => ({ value: x.key, label: x.label }))}
                                onChange={(e) => addForm.setData('template', e.target.value)}
                            />
                        </Field>

                        <p className="rounded-[10px] bg-[#f5f5f5] px-3 py-2 text-[12px] leading-6 text-[#6b7280]">
                            {templates.find((x) => x.key === addForm.data.template)?.hint}
                        </p>

                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="ghost" onClick={() => setAdding(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={addForm.processing}>
                                <Plus />
                                {t('أنشئ')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* ===== إعدادات الصفحة ===== */}
            <Dialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('إعدادات الصفحة')}</DialogTitle>
                    </DialogHeader>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (!editing) return;
                            editForm.put(route('admin.website.pages.update', editing.id), {
                                preserveScroll: true,
                                onSuccess: () => setEditing(null),
                            });
                        }}
                        className="space-y-4 px-5 pb-5"
                    >
                        <Field label="اسم الصفحة" required error={editForm.errors.title}>
                            <Input
                                value={editForm.data.title}
                                onChange={(e) => editForm.setData('title', e.target.value)}
                            />
                        </Field>

                        {!editing?.is_home && (
                            <>
                                <Field label="الرابط" error={editForm.errors.slug}>
                                    <Input
                                        dir="ltr"
                                        value={editForm.data.slug}
                                        onChange={(e) => editForm.setData('slug', e.target.value)}
                                    />
                                </Field>

                                <Field label="الحالة" error={editForm.errors.status}>
                                    <Select
                                        value={editForm.data.status}
                                        options={statuses}
                                        onChange={(e) => editForm.setData('status', e.target.value)}
                                    />
                                </Field>
                            </>
                        )}

                        {editing?.is_home && (
                            <p className="rounded-[10px] bg-[#f5f5f5] px-3 py-2 text-[12px] leading-6 text-[#6b7280]">
                                {t('الرئيسية هي ما يُفتح حين يُكتب نطاقك — فرابطُها وحالتُها ثابتان.')}
                            </p>
                        )}

                        <div className="space-y-4 border-t border-[var(--ui-border,#e8e8e8)] pt-4">
                            <p className="flex items-center gap-2 text-[13px] font-semibold text-[#111]">
                                <Search className="size-4 text-[#9ca3af]" />
                                {t('في نتائج البحث')}
                            </p>

                            <Field
                                label="العنوان في غوغل"
                                hint="اتركه فارغًا ليُستعمل اسم الصفحة"
                                error={editForm.errors['seo.title']}
                            >
                                <Input
                                    maxLength={70}
                                    value={editForm.data.seo.title}
                                    onChange={(e) =>
                                        editForm.setData('seo', { ...editForm.data.seo, title: e.target.value })
                                    }
                                />
                            </Field>

                            <Field
                                label="الوصف تحت العنوان"
                                hint="سطران يقنعان من يقرؤهما بأن يضغط"
                                error={editForm.errors['seo.description']}
                            >
                                <textarea
                                    rows={3}
                                    maxLength={170}
                                    value={editForm.data.seo.description}
                                    onChange={(e) =>
                                        editForm.setData('seo', { ...editForm.data.seo, description: e.target.value })
                                    }
                                    className="w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2.5 text-sm leading-7 outline-none focus:border-[#111]"
                                />
                            </Field>
                        </div>

                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="ghost" onClick={() => setEditing(null)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={editForm.processing}>
                                {t('حفظ')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
