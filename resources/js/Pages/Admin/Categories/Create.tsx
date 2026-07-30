import { useForm, usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import EmojiPicker, { type EmojiGroups } from '@/Components/EmojiPicker';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { Category } from '@/types/models';

interface Props {
    categories: Category[];
    emojiGroups: EmojiGroups;
    palette: string[];
}

export default function CategoryCreate() {
    const { categories, emojiGroups, palette } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm({
        name: '',
        name_en: '',
        description: '',
        parent: '',
        icon: '🌷',
        color: palette[0] ?? '#7c3aed',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.categories.store'));
    };

    return (
        <AdminLayout title="إضافة قسم">
            <PageHeader
                title="إضافة قسم"
                subtitle={t('أنشئ قسمًا جديدًا لتنظيم منتجاتك')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'الأقسام', href: route('admin.categories.index') },
                    { label: 'إضافة قسم' },
                ]}
            />

            <form onSubmit={submit} className="max-w-2xl">
                <Card className="space-y-4 p-6">
                    <Field label="اسم القسم" required error={form.errors.name}>
                        <Input
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            placeholder={t('مثال: باقات ورد')}
                            required
                        />
                    </Field>

                    <Field
                        label="الاسم بالإنجليزية (اختياري)"
                        hint="يظهر تلقائيًا عند تشغيل الواجهة بالإنجليزية"
                        error={form.errors.name_en}
                    >
                        <Input
                            dir="ltr"
                            value={form.data.name_en}
                            onChange={(e) => form.setData('name_en', e.target.value)}
                            placeholder="e.g. Bouquets"
                        />
                    </Field>

                    <Field label="الوصف" error={form.errors.description}>
                        <Textarea
                            rows={3}
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                            placeholder={t('وصف مختصر للقسم...')}
                        />
                    </Field>

                    <Field label="القسم الأب" error={form.errors.parent}>
                        <Select
                            value={form.data.parent}
                            onChange={(e) => form.setData('parent', e.target.value)}
                            options={categories.map((c) => ({ label: c.name, value: c.id }))}
                            placeholder="بدون (قسم رئيسي)"
                        />
                    </Field>

                    <EmojiPicker
                        value={form.data.icon}
                        onChange={(icon) => form.setData('icon', icon)}
                        groups={emojiGroups}
                        fallback="🌷"
                    />

                    <div className="space-y-1.5">
                        <span className="block text-[13px] font-medium text-[#4b4b4b]">{t('اللون')}</span>
                        <div className="flex flex-wrap items-center gap-2">
                            {palette.map((hex) => (
                                <button
                                    key={hex}
                                    type="button"
                                    onClick={() => form.setData('color', hex)}
                                    style={{ background: hex }}
                                    aria-label={hex}
                                    className={cn(
                                        'size-9 rounded-full ring-2 ring-offset-2 transition',
                                        form.data.color === hex ? 'ring-[#111]' : 'ring-transparent',
                                    )}
                                />
                            ))}
                            <input
                                type="color"
                                value={form.data.color}
                                onChange={(e) => form.setData('color', e.target.value)}
                                title={t('لون مخصص')}
                                className="size-9 cursor-pointer rounded-full border border-[var(--ui-border,#e8e8e8)] bg-transparent p-0.5"
                            />
                        </div>
                    </div>
                </Card>

                <div className="mt-5 flex items-center gap-3">
                    <Button type="submit" disabled={form.processing}>
                        <Check />
                        {form.processing ? '…' : t('حفظ القسم')}
                    </Button>
                    <Button variant="outline" asChild>
                        <SmartLink routeName="admin.categories.index" href={route('admin.categories.index')}>
                            {t('إلغاء')}
                        </SmartLink>
                    </Button>
                </div>
            </form>
        </AdminLayout>
    );
}
