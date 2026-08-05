import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import { type EmojiGroups } from '@/Components/EmojiPicker';
import CategoryForm from './partials/CategoryForm';
import type { PageProps } from '@/types';
import type { Category } from '@/types/models';

interface Props {
    category: Category;
    emojiGroups: EmojiGroups;
    palette: string[];
}

export default function CategoryEdit() {
    const { category, emojiGroups, palette } = usePage<PageProps<Props>>().props;

    return (
        <AdminLayout title="تعديل القسم">
            <PageHeader
                title="تعديل القسم"
                subtitle={category.name}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'الأقسام', href: route('admin.categories.index') },
                    { label: 'تعديل القسم' },
                ]}
            />

            <CategoryForm
                action={route('admin.categories.update', category.id)}
                method="put"
                initial={{
                    name: category.name,
                    name_en: category.name_en ?? '',
                    icon: category.icon,
                    color: category.color,
                }}
                emojiGroups={emojiGroups}
                palette={palette}
            />
        </AdminLayout>
    );
}
