import { useForm } from '@inertiajs/react';
import { Check } from 'lucide-react';
import EmojiPicker, { type EmojiGroups } from '@/Components/EmojiPicker';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Category } from '@/types/models';

export interface CategoryValues {
    name: string;
    name_en: string;
    parent: string;
    icon: string;
    color: string;
}

interface Props {
    action: string;
    /** put للتعديل — Inertia يرسله عبر حقل _method */
    method?: 'post' | 'put';
    initial: CategoryValues;
    /** الأقسام المتاحة أبًا؛ القسم قيد التعديل مستبعَد منها */
    categories: Category[];
    emojiGroups: EmojiGroups;
    palette: string[];
}

/**
 * نموذج القسم — مشترك بين الإضافة والتعديل.
 *
 * كان محصورًا في صفحة الإضافة، ولم تكن للتعديل صفحة أصلًا رغم وجود مسار
 * categories.update في الخادم، فكان القسم يُنشأ ولا يُصحَّح أبدًا.
 */
export default function CategoryForm({
    action,
    method = 'post',
    initial,
    categories,
    emojiGroups,
    palette,
}: Props) {
    const t = useTranslate();
    const form = useForm<CategoryValues>(initial);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (method === 'put') {
            form.transform((data) => ({ ...data, _method: 'put' }));
        }
        form.post(action);
    };

    return (
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

                {/* كان هنا حقل «الوصف» — ولا عمود له في جدول الأقسام، فكان
                    ما يكتبه التاجر يُرمى عند الحفظ بلا رسالة. حُذف الحقل بدل
                    إضافة عمود لا تعرضه أي شاشة. */}

                <Field label="القسم الأب" error={form.errors.parent}>
                    <Select
                        value={form.data.parent}
                        onChange={(e) => form.setData('parent', e.target.value)}
                        options={categories.map((c) => ({ label: c.name, value: String(c.id) }))}
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
    );
}
