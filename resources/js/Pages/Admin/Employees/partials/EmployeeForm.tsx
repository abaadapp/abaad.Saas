import { useForm } from '@inertiajs/react';
import { Check } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { Branch } from '@/types/models';

export interface EmployeeFormValues {
    id?: number;
    name: string;
    job_title: string | null;
    branch: string | null;
    phone: string | null;
    email: string;
    pin: string | null;
    /** هل للموظف رمز دخول محفوظ؟ الرمز نفسه لا يصل أبدًا (مخزَّن مشفّرًا) */
    has_pin?: boolean;
}

interface Props {
    branches: Branch[];
    jobTitles: string[];
    employee?: EmployeeFormValues;
    defaultBranch?: string | null;
}

/** نموذج الموظف — يخدم الإضافة والتعديل بحقل واحد لكل معنى */
export default function EmployeeForm({ branches, jobTitles, employee, defaultBranch }: Props) {
    const t = useTranslate();
    const editing = !!employee;

    const form = useForm({
        name: employee?.name ?? '',
        job_title: employee?.job_title ?? '',
        branch: employee?.branch ?? defaultBranch ?? '',
        phone: employee?.phone ?? '',
        email: employee?.email ?? '',
        password: '',
        pin: employee?.pin ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editing) form.put(route('admin.employees.update', employee!.id));
        else form.post(route('admin.employees.store'));
    };

    return (
        <form onSubmit={submit} className="max-w-3xl">
            <Card className="space-y-4 p-6">
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <Field label="الاسم الكامل" required error={form.errors.name}>
                        <Input
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            required
                        />
                    </Field>

                    <Field label="الوظيفة / الدور" error={form.errors.job_title}>
                        <Select
                            value={form.data.job_title}
                            onChange={(e) => form.setData('job_title', e.target.value)}
                            options={jobTitles.map((j) => ({ label: j, value: j }))}
                            placeholder="اختر الوظيفة…"
                        />
                    </Field>

                    <Field label="الفرع" error={form.errors.branch}>
                        <Select
                            value={form.data.branch}
                            onChange={(e) => form.setData('branch', e.target.value)}
                            options={branches.map((b) => ({ label: b.name, value: b.name }))}
                            placeholder="اختر الفرع…"
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

                    <Field label="البريد الإلكتروني" required error={form.errors.email}>
                        <Input
                            type="email"
                            dir="ltr"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                            required
                        />
                    </Field>

                    <Field
                        label="كلمة المرور"
                        hint={editing ? 'اتركها فارغة للإبقاء على كلمة المرور الحالية' : undefined}
                        required={!editing}
                        error={form.errors.password}
                    >
                        <Input
                            type="password"
                            dir="ltr"
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
                            required={!editing}
                        />
                    </Field>

                    <Field
                        label="رمز الدخول السريع (4 أرقام)"
                        hint={
                            employee?.has_pin
                                ? 'اتركه فارغًا للإبقاء على الرمز الحالي'
                                : 'يُستخدم لدخول نقطة البيع بلا كلمة مرور'
                        }
                        error={form.errors.pin}
                    >
                        <Input
                            inputMode="numeric"
                            maxLength={4}
                            dir="ltr"
                            value={form.data.pin}
                            onChange={(e) => form.setData('pin', e.target.value.replace(/\D/g, ''))}
                            placeholder="••••"
                        />
                    </Field>
                </div>
            </Card>

            <div className="mt-5 flex items-center gap-3">
                <Button type="submit" disabled={form.processing}>
                    <Check />
                    {form.processing ? '…' : editing ? t('حفظ التغييرات') : t('حفظ الموظف')}
                </Button>
                <Button variant="outline" asChild>
                    <SmartLink routeName="admin.employees.index" href={route('admin.employees.index')}>
                        {t('إلغاء')}
                    </SmartLink>
                </Button>
            </div>
        </form>
    );
}
