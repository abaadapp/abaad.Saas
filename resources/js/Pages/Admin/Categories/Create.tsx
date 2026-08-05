import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import { type EmojiGroups } from '@/Components/EmojiPicker';
import CategoryForm from './partials/CategoryForm';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Props {
    emojiGroups: EmojiGroups;
    palette: string[];
}

export default function CategoryCreate() {
    const { emojiGroups, palette } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

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

            <CategoryForm
                action={route('admin.categories.store')}
                initial={{
                    name: '',
                    name_en: '',
                    icon: '🌷',
                    color: palette[0] ?? '#7c3aed',
                }}
                emojiGroups={emojiGroups}
                palette={palette}
            />
        </AdminLayout>
    );
}
