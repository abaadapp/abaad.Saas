import { useForm } from '@inertiajs/react';
import { Check, KeyRound, Target, UserRound } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import Toggle from '@/Components/Toggle';
import { Avatar, AvatarFallback, AvatarImage } from '@/Components/ui/avatar';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { initials } from '@/lib/format';
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
    avatar?: string | null;
    status?: string;
    monthly_target?: number | string | null;
    commission_rate?: number | string | null;
}

interface Props {
    branches: Branch[];
    jobTitles: string[];
    employee?: EmployeeFormValues;
    defaultBranch?: string | null;
}

/** قسم داخل النموذج: عنوان وشرح سطر، ثم حقوله */
function Section({
    icon: Icon,
    title,
    hint,
    children,
}: {
    icon: typeof UserRound;
    title: string;
    hint: string;
    children: React.ReactNode;
}) {
    const t = useTranslate();

    return (
        <Card className="p-6">
            <div className="mb-5 flex items-start gap-3">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-[#f3f4f6] text-[#111]">
                    <Icon className="size-[18px]" />
                </span>
                <div className="min-w-0">
                    <h3 className="font-bold text-[#111]">{t(title)}</h3>
                    <p className="mt-0.5 text-[12px] text-[#9ca3af]">{t(hint)}</p>
                </div>
            </div>
            {children}
        </Card>
    );
}

/**
 * نموذج الموظف — يخدم الإضافة والتعديل بحقل واحد لكل معنى.
 *
 * كان شبكةً واحدة من سبعة حقول بلا تجميع: «كلمة المرور» بجانب «البريد»
 * و«رمز الدخول» وحده في صفٍّ نصفه فارغ، وتلميحٌ يهبط تحت عمودٍ فيزيح ما
 * تحته. والحقول مختلطة: بيانات تعريف وأمانٌ وأداء في مستوًى واحد.
 *
 * فصُنّفت في ثلاثة أقسام، وكلٌّ منها شبكةٌ مكتملة الصفوف لا تترك فجوة.
 */
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
        status: (employee?.status ?? 'نشط') === 'نشط',
        // صفرٌ يعني «بلا هدف» — يُعرض فارغًا كما يقول التلميح، لا رقمًا مضبوطًا
        monthly_target: Number(employee?.monthly_target ?? 0) ? String(employee!.monthly_target) : '',
        commission_rate: Number(employee?.commission_rate ?? 0) ? String(employee!.commission_rate) : '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editing) form.put(route('admin.employees.update', employee!.id));
        else form.post(route('admin.employees.store'));
    };

    return (
        <form onSubmit={submit} className="max-w-3xl space-y-5">
            {editing && (
                <Card className="flex items-center gap-4 p-5">
                    <Avatar className="size-14">
                        {employee!.avatar && <AvatarImage src={employee!.avatar} alt="" />}
                        <AvatarFallback>{initials(employee!.name)}</AvatarFallback>
                    </Avatar>
                    <div className="min-w-0">
                        <p className="truncate font-bold text-[#111]">{employee!.name}</p>
                        <p className="truncate text-[12px] text-[#9ca3af]" dir="ltr">
                            {employee!.email}
                        </p>
                    </div>
                </Card>
            )}

            <Section icon={UserRound} title="البيانات الأساسية" hint="ما يظهر في القوائم والتقارير">
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <Field label="الاسم الكامل" required error={form.errors.name}>
                        <Input
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            required
                        />
                    </Field>

                    <Field
                        label="الوظيفة / الدور"
                        hint="الصلاحيات تُشتقّ منها"
                        error={form.errors.job_title}
                    >
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
                </div>
            </Section>

            <Section
                icon={KeyRound}
                title="الدخول والأمان"
                hint="البريد لدخول اللوحة، والرمز لدخول نقطة البيع بلا كلمة مرور"
            >
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
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
                        label={editing ? 'كلمة مرور جديدة' : 'كلمة المرور'}
                        hint={editing ? 'اتركها فارغة للإبقاء على الحالية' : 'أربعة أحرف على الأقل'}
                        required={!editing}
                        error={form.errors.password}
                    >
                        <Input
                            type="password"
                            dir="ltr"
                            autoComplete="new-password"
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
                                : 'اختياري — لدخول نقطة البيع'
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

                    {editing && (
                        <Field label="حالة الحساب" hint="الحساب المعطَّل لا يستطيع الدخول">
                            <div className="pt-1.5">
                                <Toggle
                                    on={form.data.status}
                                    onChange={(v) => form.setData('status', v)}
                                    label={form.data.status ? 'نشط' : 'معطل'}
                                />
                            </div>
                        </Field>
                    )}
                </div>
            </Section>

            <Section
                icon={Target}
                title="الهدف والعمولة"
                hint="يُحتسب عليهما «تحقيق الهدف» في قائمة الموظفين"
            >
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <Field
                        label="الهدف الشهري"
                        hint="اتركه فارغًا لبلا هدف"
                        error={form.errors.monthly_target}
                    >
                        <Input
                            inputMode="decimal"
                            dir="ltr"
                            value={form.data.monthly_target}
                            onChange={(e) => form.setData('monthly_target', e.target.value)}
                            placeholder="0"
                        />
                    </Field>

                    <Field label="نسبة العمولة %" error={form.errors.commission_rate}>
                        <Input
                            inputMode="decimal"
                            dir="ltr"
                            value={form.data.commission_rate}
                            onChange={(e) => form.setData('commission_rate', e.target.value)}
                            placeholder="0"
                        />
                    </Field>
                </div>
            </Section>

            <div className="flex items-center gap-3">
                <Button type="submit" loading={form.processing}>
                    <Check />
                    {editing ? t('حفظ التغييرات') : t('حفظ الموظف')}
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
