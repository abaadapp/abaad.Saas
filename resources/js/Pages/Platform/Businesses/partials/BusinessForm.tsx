import { type FormEvent, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Building2, Check, Image as ImageIcon, Layers, Upload, User } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';

export interface BusinessOptions {
    types: string[];
    cities: string[];
    statuses: string[];
    plans: { label: string; value: number }[];
}

export interface BusinessValues {
    name: string;
    type: string;
    country: string;
    city: string;
    address: string;
    owner_name: string;
    phone: string;
    email: string;
    plan_id: string;
    status: string;
    starts_at: string;
    ends_at: string;
}

interface Props {
    options: BusinessOptions;
    initial: BusinessValues;
    /** الشعار المحفوظ — يُعرض حتى يختار المستخدم ملفًا جديدًا */
    logoUrl?: string | null;
    action: string;
    /** التعديل يمرّ بـPUT عبر _method لأن الطلب متعدّد الأجزاء (رفع ملف) */
    method: 'post' | 'put';
    submitLabel: string;
    cancelHref: string;
}

/**
 * نموذج بيانات الشركة — مشترك بين الإنشاء والتعديل.
 *
 * كانا قالبين شبه متطابقين (95 و99 سطرًا)، فأي تعديل على أحدهما يُنسى في
 * الآخر: قالب التعديل كان يطبع العنوان وتاريخي الاشتراك كقيم ثابتة بينما
 * قالب الإنشاء يتركها فارغة.
 */
export default function BusinessForm({
    options,
    initial,
    logoUrl,
    action,
    method,
    submitLabel,
    cancelHref,
}: Props) {
    const t = useTranslate();
    const form = useForm<BusinessValues & { logo: File | null }>({ ...initial, logo: null });
    const [preview, setPreview] = useState<string | null>(logoUrl ?? null);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (method === 'put') {
            // transform يُعيد void في هذا الإصدار، فيُستدعى قبل post لا مسلسلًا معه
            form.transform((data) => ({ ...data, _method: 'put' }));
        }
        form.post(action, { forceFormData: true });
    };

    const section = (icon: React.ReactNode, title: string, children: React.ReactNode) => (
        <Card className="p-6">
            <div className="mb-5 flex items-center gap-2">
                <span className="flex size-9 items-center justify-center rounded-[12px] bg-[#f2f2f0] text-[#4b4b4b]">
                    {icon}
                </span>
                <h3 className="font-bold text-[#111]">{t(title)}</h3>
            </div>
            {children}
        </Card>
    );

    return (
        <form onSubmit={submit} className="space-y-6">
            {section(
                <Building2 className="size-5" />,
                'بيانات الشركة',
                <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                    <Field label="اسم الشركة" required error={form.errors.name}>
                        <Input
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            placeholder={t('مثال: زهرة مسقط')}
                            required
                        />
                    </Field>
                    {/* مطلوب: عمود NOT NULL في قاعدة البيانات */}
                    <Field label="نوع النشاط" required error={form.errors.type}>
                        <Select
                            value={form.data.type}
                            onChange={(e) => form.setData('type', e.target.value)}
                            options={options.types.map((v) => ({ label: v, value: v }))}
                            placeholder="اختر النوع…"
                            required
                        />
                    </Field>
                    <Field label="الدولة" error={form.errors.country}>
                        <Input
                            value={form.data.country}
                            onChange={(e) => form.setData('country', e.target.value)}
                        />
                    </Field>
                    <Field label="المدينة" error={form.errors.city}>
                        <Select
                            value={form.data.city}
                            onChange={(e) => form.setData('city', e.target.value)}
                            options={options.cities.map((v) => ({ label: v, value: v }))}
                            placeholder="اختر المدينة…"
                        />
                    </Field>
                    <Field label="العنوان" className="md:col-span-2" error={form.errors.address}>
                        <Input
                            value={form.data.address}
                            onChange={(e) => form.setData('address', e.target.value)}
                            placeholder={t('الحي، الشارع، رقم المبنى')}
                        />
                    </Field>
                </div>,
            )}

            {section(
                <User className="size-5" />,
                'بيانات المالك',
                <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                    <Field label="اسم المالك" error={form.errors.owner_name}>
                        <Input
                            value={form.data.owner_name}
                            onChange={(e) => form.setData('owner_name', e.target.value)}
                            placeholder={t('الاسم الكامل')}
                        />
                    </Field>
                    <Field label="رقم الهاتف" error={form.errors.phone}>
                        <Input
                            type="tel"
                            dir="ltr"
                            value={form.data.phone}
                            onChange={(e) => form.setData('phone', e.target.value)}
                            placeholder="+968 9xxxxxxx"
                        />
                    </Field>
                    <Field label="البريد الإلكتروني" className="md:col-span-2" error={form.errors.email}>
                        <Input
                            type="email"
                            dir="ltr"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                            placeholder="info@example.com"
                        />
                    </Field>
                </div>,
            )}

            {section(
                <Layers className="size-5" />,
                'الاشتراك والباقة',
                <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                    <Field label="الباقة" error={form.errors.plan_id}>
                        <Select
                            value={form.data.plan_id}
                            onChange={(e) => form.setData('plan_id', e.target.value)}
                            options={options.plans}
                            placeholder="اختر الباقة…"
                        />
                    </Field>
                    <Field label="حالة الحساب" required error={form.errors.status}>
                        <Select
                            value={form.data.status}
                            onChange={(e) => form.setData('status', e.target.value)}
                            options={options.statuses.map((v) => ({ label: v, value: v }))}
                            placeholder="اختر الحالة…"
                            required
                        />
                    </Field>
                    <Field label="تاريخ البداية" error={form.errors.starts_at}>
                        <Input
                            type="date"
                            dir="ltr"
                            value={form.data.starts_at}
                            onChange={(e) => form.setData('starts_at', e.target.value)}
                        />
                    </Field>
                    <Field label="تاريخ الانتهاء" error={form.errors.ends_at}>
                        <Input
                            type="date"
                            dir="ltr"
                            value={form.data.ends_at}
                            onChange={(e) => form.setData('ends_at', e.target.value)}
                        />
                    </Field>
                </div>,
            )}

            {section(
                <ImageIcon className="size-5" />,
                'شعار الشركة',
                <>
                    <div className="flex items-center gap-5">
                        {preview && (
                            <img
                                src={preview}
                                alt={t('معاينة الشعار')}
                                className="size-20 rounded-[16px] border border-[var(--ui-border,#e8e8e8)] object-cover"
                            />
                        )}
                        <label className="flex flex-1 cursor-pointer flex-col items-center justify-center gap-2 rounded-[16px] border-2 border-dashed border-[var(--ui-border,#e8e8e8)] p-8 text-center transition-colors hover:border-[#d1d5db] hover:bg-[#fafafa]">
                            <span className="flex size-12 items-center justify-center rounded-[12px] bg-[#f2f2f0] text-[#9ca3af]">
                                <Upload className="size-6" />
                            </span>
                            <span className="text-sm font-medium text-[#374151]">
                                {t(preview ? 'تغيير الشعار' : 'اسحب الشعار هنا أو انقر للرفع')}
                            </span>
                            <span className="text-[12px] text-[#9ca3af]">
                                {t('PNG أو JPG بحد أقصى 2 ميجابايت')}
                            </span>
                            <input
                                type="file"
                                accept="image/*"
                                className="hidden"
                                onChange={(e) => {
                                    const file = e.target.files?.[0] ?? null;
                                    form.setData('logo', file);
                                    setPreview(file ? URL.createObjectURL(file) : (logoUrl ?? null));
                                }}
                            />
                        </label>
                    </div>
                    {form.errors.logo && <p className="mt-2 text-[12px] text-[#b91c1c]">{form.errors.logo}</p>}
                </>,
            )}

            <div className="flex items-center justify-end gap-3">
                <Button variant="outline" asChild>
                    <SmartLink routeName="super-admin.businesses.index" href={cancelHref}>
                        {t('إلغاء')}
                    </SmartLink>
                </Button>
                <Button type="submit" loading={form.processing}>
                    <Check />
                    {t(submitLabel)}
                </Button>
            </div>
        </form>
    );
}
