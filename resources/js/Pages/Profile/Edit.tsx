import { type FormEvent, type ReactNode, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Camera, Check, Lock } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PosLayout from '@/Layouts/PosLayout';
import PageHeader from '@/Components/PageHeader';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
import { initials } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Props {
    profile: {
        name: string;
        email: string;
        phone: string | null;
        avatar: string | null;
        roleLabel: string;
    };
    /** أي قشرة تُلبس الصفحة — يحدّدها الخادم من دور المستخدم */
    shell: 'platform' | 'pos' | 'admin';
    /** الكاشير يعدّل صورته وهاتفه فقط */
    limited: boolean;
}

export default function ProfileEdit() {
    const { profile, shell, limited } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [preview, setPreview] = useState<string | null>(profile.avatar);

    const form = useForm<{
        name: string;
        email: string;
        phone: string;
        current_password: string;
        password: string;
        password_confirmation: string;
        avatar: File | null;
    }>({
        name: profile.name,
        email: profile.email,
        phone: profile.phone ?? '',
        current_password: '',
        password: '',
        password_confirmation: '',
        avatar: null,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        // transform يُعيد void في هذا الإصدار، فيُستدعى قبل post لا مسلسلًا معه
        form.transform((data) => ({ ...data, _method: 'put' }));
        form.post(route('profile.update'), { forceFormData: true });
    };

    const body = (
        <>
            <PageHeader
                title="الملف الشخصي"
                subtitle={t(limited ? 'يمكنك تحديث صورتك ورقم هاتفك' : 'إدارة بياناتك الشخصية وكلمة المرور')}
            />

            <form onSubmit={submit} className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div>
                    <Card className="p-6 text-center">
                        <div className="relative mx-auto size-28">
                            {preview ? (
                                <img
                                    src={preview}
                                    alt=""
                                    className="size-28 rounded-[16px] border border-[var(--ui-border,#e8e8e8)] object-cover"
                                />
                            ) : (
                                <span className="flex size-28 items-center justify-center rounded-[16px] bg-[#f2f2f0] text-[28px] font-bold text-[#4b4b4b]">
                                    {initials(profile.name)}
                                </span>
                            )}
                            {/* الحافة النهائية: يسار في العربية ويمين في الإنجليزية */}
                            <label className="absolute -bottom-2 -end-2 flex size-9 cursor-pointer items-center justify-center rounded-[12px] bg-[#111] text-white transition-colors hover:bg-[#2a2a2a]">
                                <Camera className="size-4" />
                                <input
                                    type="file"
                                    accept="image/*"
                                    className="hidden"
                                    onChange={(e) => {
                                        const file = e.target.files?.[0] ?? null;
                                        form.setData('avatar', file);
                                        setPreview(file ? URL.createObjectURL(file) : profile.avatar);
                                    }}
                                />
                            </label>
                        </div>
                        <h3 className="mt-4 font-bold text-[#111]">{profile.name}</h3>
                        <p className="text-sm text-[#9ca3af]">{t(profile.roleLabel)}</p>
                        {form.errors.avatar && (
                            <p className="mt-2 text-[12px] text-[#b91c1c]">{form.errors.avatar}</p>
                        )}
                    </Card>
                </div>

                <div className="space-y-6 lg:col-span-2">
                    <Card className="p-6">
                        <h3 className="mb-4 font-bold text-[#111]">{t('البيانات الشخصية')}</h3>
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            {limited ? (
                                <>
                                    {/* الاسم والبريد مقفولان: يعدّلهما صاحب النشاط */}
                                    <Field label="الاسم" hint="لا يمكن تغيير الاسم — راجع صاحب النشاط">
                                        <span className="relative block">
                                            <Input value={profile.name} readOnly className="bg-[#fafafa] text-[#6b7280]" />
                                            <Lock className="pointer-events-none absolute end-3 top-3 size-4 text-[#9ca3af]" />
                                        </span>
                                    </Field>
                                    <Field label="رقم الهاتف" error={form.errors.phone}>
                                        <Input
                                            dir="ltr"
                                            value={form.data.phone}
                                            onChange={(e) => form.setData('phone', e.target.value)}
                                        />
                                    </Field>
                                    <Field
                                        label="البريد الإلكتروني"
                                        hint="البريد يعدّله صاحب النشاط فقط"
                                        className="md:col-span-2"
                                    >
                                        <span className="relative block">
                                            <Input
                                                dir="ltr"
                                                value={profile.email}
                                                readOnly
                                                className="bg-[#fafafa] text-[#6b7280]"
                                            />
                                            <Lock className="pointer-events-none absolute end-3 top-3 size-4 text-[#9ca3af]" />
                                        </span>
                                    </Field>
                                </>
                            ) : (
                                <>
                                    <Field label="الاسم" required error={form.errors.name}>
                                        <Input
                                            value={form.data.name}
                                            onChange={(e) => form.setData('name', e.target.value)}
                                            required
                                        />
                                    </Field>
                                    <Field label="رقم الهاتف" error={form.errors.phone}>
                                        <Input
                                            dir="ltr"
                                            value={form.data.phone}
                                            onChange={(e) => form.setData('phone', e.target.value)}
                                        />
                                    </Field>
                                    <Field
                                        label="البريد الإلكتروني"
                                        required
                                        className="md:col-span-2"
                                        error={form.errors.email}
                                    >
                                        <Input
                                            type="email"
                                            dir="ltr"
                                            value={form.data.email}
                                            onChange={(e) => form.setData('email', e.target.value)}
                                            required
                                        />
                                    </Field>
                                </>
                            )}
                        </div>
                    </Card>

                    {!limited && (
                        <Card className="p-6">
                            <h3 className="mb-1 font-bold text-[#111]">{t('تغيير كلمة المرور')}</h3>
                            <p className="mb-4 text-[12px] text-[#9ca3af]">
                                {t('اتركها فارغة إن لم ترغب بتغييرها.')}
                            </p>
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                                <Field label="كلمة المرور الحالية" error={form.errors.current_password}>
                                    <PasswordInput
                                        value={form.data.current_password}
                                        onChange={(e) => form.setData('current_password', e.target.value)}
                                    />
                                </Field>
                                <Field label="كلمة المرور الجديدة" error={form.errors.password}>
                                    <PasswordInput
                                        value={form.data.password}
                                        onChange={(e) => form.setData('password', e.target.value)}
                                    />
                                </Field>
                                <Field label="تأكيد كلمة المرور">
                                    <PasswordInput
                                        value={form.data.password_confirmation}
                                        onChange={(e) => form.setData('password_confirmation', e.target.value)}
                                    />
                                </Field>
                            </div>
                        </Card>
                    )}

                    <div className="flex justify-end gap-3">
                        <Button type="button" variant="outline" onClick={() => window.history.back()}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            <Check />
                            {t('حفظ التغييرات')}
                        </Button>
                    </div>
                </div>
            </form>
        </>
    );

    /** لكل دور قشرته: قائمة المنصة لمديرها، وقائمة المتجر لصاحبه، وشريط نقطة البيع للكاشير */
    const shells: Record<Props['shell'], (children: ReactNode) => ReactNode> = {
        platform: (c) => <PlatformLayout title="الملف الشخصي">{c}</PlatformLayout>,
        pos: (c) => <PosLayout title="الملف الشخصي">{c}</PosLayout>,
        admin: (c) => <AdminLayout title="الملف الشخصي">{c}</AdminLayout>,
    };

    return <>{shells[shell](body)}</>;
}
