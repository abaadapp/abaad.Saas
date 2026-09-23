import { useEffect, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Check, KeyRound, Plus, ShieldCheck, Target, UserRound, Wallet } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import Toggle from '@/Components/Toggle';
import { Avatar, AvatarFallback, AvatarImage } from '@/Components/ui/avatar';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
import { UsernameInput, usernameOf } from '@/Components/ui/username-input';
import { initials } from '@/lib/format';
import { cn } from '@/lib/utils';
import { useTranslate } from '@/lib/i18n';
import { usePlanFeature } from '@/lib/plan';
import type { Branch } from '@/types/models';

export interface EmployeeFormValues {
    id?: number;
    name: string;
    job_title: string | null;
    branch: string | null;
    phone: string | null;
    email: string;
    /** الاسم قبل النطاق — يملأ الحقل، والنطاق مُلحق ثابت */
    username?: string;
    /** هل عنوانه على نطاق أبعاد؟ الحسابات القديمة خارجه لا تُنقل بلا قصد */
    on_domain?: boolean;
    avatar?: string | null;
    status?: string;
    monthly_target?: number | string | null;
    basic_salary?: number | string | null;
    allowances?: number | string | null;
    /** null تعني «اتبع الدور»؛ مصفوفة تعني قائمة يدوية */
    permissions?: string[] | null;
    /** ما يمنحه الدور — يُعرض حين تكون الصلاحيات موروثة */
    role_permissions?: string[];
    /** فروع العمل المسموح بها. الفارغة = كل فروع المتجر */
    branches?: number[];
}

interface Props {
    branches: Branch[];
    /** فروع المتجر كخيارات — مصدر الإذن، لا الحقل النصّي القديم */
    branchOptions?: { value: number; label: string }[];
    jobTitles: string[];
    employee?: EmployeeFormValues;
    defaultBranch?: string | null;
    /** مفتاح القسم → اسمه المعروض */
    sections?: Record<string, string>;
    /** أفعالٌ تُمنح بأسمائها — لا أقسامٌ تُفتح. انظر Permissions::ACTIONS */
    actions?: Record<string, string>;
    /**
     * ما يملك الفاعلُ منحَه — وسواه يُعطَّل بسببه مكتوبًا.
     *
     * والخادمُ يردّ من يمنح ما لا يملك بـ٤٠٣ كاملة (`refuseGrantingMoreThanIHave`)
     * — صفحةُ خطأٍ تمحو النموذج كلَّه ولا تقول أيُّ مربّعٍ سبّبها. فيُقال
     * قبل الضغط. ويُقرأ من `Permissions::grantable` نفسِها التي يقيس بها
     * الحارس، فلا تفترق الشاشةُ عن الباب.
     */
    grantable?: string[];
    /** وظائفُ لا يُسندها الفاعل: دورُها يحمل ما لا يملكه — `mayAssignRole` */
    blockedTitles?: string[];
    /**
     * ما تفتحه كلُّ وظيفة: تسميتها ← مفاتيحُها.
     *
     * تُقرأ حين يُختار «اتبع صلاحيات الوظيفة»، فيرى المديرُ ما سيفتحه
     * الموظّف فعلًا — ويتبدّل حين يبدّل المسمّى في النموذج نفسِه.
     *
     * وتُحسب في الخادم (`Permissions::titleGrants`): الدورُ عمودٌ لا يصل
     * الواجهةَ أصلًا، وشاشةٌ تخمّنه تقول غيرَ ما يقع.
     */
    titleGrants?: Record<string, string[]>;
    /** لا يُعدّل المدير صلاحيات حسابه */
    canEditPermissions?: boolean;
    /*
     * ومن لا يقرأ الرواتب لا يرى حقولَها.
     *
     * `PAYROLL_VIEW` تحرس شاشةَ المسيرة — وكان الرقمُ نفسُه معروضًا هنا
     * ومكتوبًا، فمن مُنح القسم ليصحّح مسمًّى يقرأ رواتب المتجر كلَّه ويغيّرها.
     * والخادمُ يرفع الحقلين من الحمولة كذلك، فلا يكفي إخفاؤهما.
     */
    mayReadPayroll?: boolean;
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
export default function EmployeeForm({
    branches,
    branchOptions = [],
    jobTitles,
    employee,
    defaultBranch,
    sections,
    actions,
    grantable,
    blockedTitles,
    titleGrants,
    canEditPermissions = true,
    mayReadPayroll = true,
}: Props) {
    const t = useTranslate();
    /*
     * الصلاحيات المخصّصة قدرةٌ تُشترى.
     *
     * وبلا هذا كانت الشاشة ترسم المربّعات كاملةً على الباقة الأساسية: يؤشّرها
     * المالك ويحفظ فيُردّ — بابٌ معروضٌ لا يُفتح. والخادم يبقى الحارس، وهذا
     * ليقول قبل المحاولة لا بعدها.
     */
    const canCustomize = usePlanFeature('custom_permissions');
    const editing = !!employee;

    /*
     * القائمة محلّية لأن الوظيفة الجديدة تُضاف بلا إعادة تحميل الصفحة:
     * الاعتماد على الخاصية القادمة من الخادم كان سيتطلّب reload يمحو ما
     * كُتب في بقيّة الحقول.
     */
    const [titles, setTitles] = useState<string[]>(jobTitles);
    const [addingTitle, setAddingTitle] = useState(false);
    /*
     * الوظيفة المضافة تُختار بعد أن تصير القائمة تعرفها لا معها: ضبط القيمة
     * في اللحظة نفسها يجعل قائمة الاختيار ترفض قيمةً ليست بين خياراتها بعد،
     * فتُضاف الوظيفة ويبقى الحقل فارغًا — والمستخدم يظنّ الإضافة فشلت.
     */
    const [pendingTitle, setPendingTitle] = useState<string | null>(null);

    const form = useForm({
        name: employee?.name ?? '',
        job_title: employee?.job_title ?? '',
        branch: employee?.branch ?? defaultBranch ?? '',
        branches: employee?.branches ?? [],
        phone: employee?.phone ?? '',
        login_username: employee ? (employee.on_domain === false ? '' : (employee.username ?? usernameOf(employee.email))) : '',
        password: '',
        status: (employee?.status ?? 'نشط') === 'نشط',
        // صفرٌ يعني «بلا هدف» — يُعرض فارغًا كما يقول التلميح، لا رقمًا مضبوطًا
        monthly_target: Number(employee?.monthly_target ?? 0) ? String(employee!.monthly_target) : '',
        basic_salary: Number(employee?.basic_salary ?? 0) ? String(employee!.basic_salary) : '',
        allowances: Number(employee?.allowances ?? 0) ? String(employee!.allowances) : '',
        /*
         * علمٌ يُرسل دائمًا: مصفوفة فارغة تسقط من طلب HTTP، فبدونه لا يميّز
         * الخادم «لم تُرسل الصلاحيات» من «أُرسلت فارغة» — ولا يستطيع رفضها.
         *
         * وقيمتُه تتبع الموظّف: من صلاحياتُه موروثةٌ من وظيفته (`null`) يُفتح
         * نموذجُه على «اتبع الوظيفة»، ومن خُصّصت له قائمةٌ يُفتح على «خصّص».
         * وكان يُرسل `true` أبدًا — فلا سبيل إلى الرجوع، ولا تُقرأ حالُ
         * الموظّف كما هي.
         */
        manual_permissions: employee ? employee.permissions != null : true,
        permissions: employee?.permissions ?? employee?.role_permissions ?? [],
    });

    /*
     * ما تفتحه الوظيفةُ المختارةُ **الآن** — لا وظيفةُ الموظّف يوم فُتحت الشاشة.
     *
     * المديرُ قد يبدّل المسمّى ويختار «اتبع الوظيفة» في الحفظة نفسِها، فلو
     * عُرضت له قائمةُ الوظيفة القديمة لَقرأ غيرَ ما سيقع.
     *
     * ومسمًّى قديمٌ لا صفَّ له (وقعت على الإنتاج: «أمين مخزن» بلا وظيفة) لا
     * يُخترع له جواب — يُقال إنّه غير معروف، وتبقى صلاحياتُ الموظّف على
     * دورها. انظر `EmployeeController::update`.
     */
    const titleKnown = !titleGrants || form.data.job_title in (titleGrants ?? {});
    const roleGrants = titleGrants?.[form.data.job_title] ?? employee?.role_permissions ?? [];

    // ما يُعرض مؤشَّرًا: المخصَّصُ قائمتُه، والمتبِعُ قائمةَ وظيفته
    const shown = form.data.manual_permissions ? form.data.permissions : roleGrants;

    /*
     * والمربّعاتُ تُؤشَّر حين تُخصَّص وحدها.
     *
     * في «اتبع الوظيفة» هي عرضٌ لا مقبض: نقرةٌ عليها تُغيّر قائمةً لا تُقرأ
     * أصلًا، فيظنّ المديرُ أنّه نزع صلاحيةً وهي قائمة. ومقبضٌ لا يُدير شيئًا
     * أسوأ من غياب المقبض.
     */
    const boxesLive = canCustomize && form.data.manual_permissions;

    /*
     * والتخصيصُ يبدأ من حيث انتهت الوظيفة.
     *
     * من ضغط «خصّص» يريد أن يزيد أو ينقص، لا أن يبدأ من صفحةٍ بيضاء فيعيد
     * تأشير ما كانت وظيفتُه تمنحه أصلًا — وأوّلُ حفظةٍ بلا تأشير تُردّ.
     */
    const setManual = (on: boolean) => {
        if (on && form.data.permissions.length === 0) {
            form.setData({ ...form.data, manual_permissions: true, permissions: roleGrants });
            return;
        }
        form.setData('manual_permissions', on);
    };

    /*
     * ما لا يُمنح يُعطَّل — ولا يُرفع.
     *
     * رفعُه كان سيُخفي عن بطاقةِ موظّفٍ قائمٍ صلاحيةً يملكها بالفعل، فيظنّ
     * القارئُ أنّها نُزعت. و`grantable` غائبةٌ تعني «لا حدّ» — شاشاتٌ لا
     * ترسل هذه الخاصّية تبقى كما كانت.
     */
    const mayGrant = (key: string) => !grantable || grantable.includes(key);
    const titleBlocked = (name: string) => (blockedTitles ?? []).includes(name);

    const togglePermission = (key: string) =>
        form.setData(
            'permissions',
            form.data.permissions.includes(key)
                ? form.data.permissions.filter((k) => k !== key)
                : [...form.data.permissions, key],
        );

    // الاسم وحده: الصلاحيات تُحدَّد لهذا الموظف بعينه في القسم أدناه، فلا معنى
    // لسؤالٍ عن صلاحيات «الوظيفة» يُجاب مرّتين ويتناقض جوابه
    const titleForm = useForm({ name: '' });

    /*
     * حسابٌ قديم خارج نطاق أبعاد — يُعرض عنوانه ولا يُنقل حتى يُطلب النقل.
     */
    const [moving, setMoving] = useState(false);
    const legacyEmail = editing && employee?.on_domain === false && !moving;

    useEffect(() => {
        if (pendingTitle && titles.includes(pendingTitle)) {
            form.setData('job_title', pendingTitle);
            setPendingTitle(null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pendingTitle, titles]);

    /*
     * تُحفظ عبر مسار الوظائف نفسه — لا مسار ثانٍ يكرّر التحقق ويفترق عنه.
     * وعند النجاح تُضاف إلى القائمة وتُختار مباشرة، فلا يعيد المستخدم
     * اختيارها، ولا يُعاد تحميل الصفحة فيضيع ما كُتب في بقيّة الحقول.
     */
    const saveTitle = () => {
        titleForm.post(route('admin.jobTitles.store'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                const name = titleForm.data.name.trim();
                setTitles((list) => (list.includes(name) ? list : [...list, name].sort()));
                setPendingTitle(name);
                titleForm.reset();
                setAddingTitle(false);
            },
        });
    };

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
                        {/*
                            زرّ الإضافة بجانب القائمة: وظيفةٌ ناقصة كانت تعني
                            ترك النموذج والذهاب إلى تبويب الوظائف ثم العودة
                            وإعادة تعبئة ما كُتب — فيُختار مسمًّى قريب بدل
                            الصحيح، وتُبنى صلاحيات الموظف على وظيفة ليست وظيفته.
                        */}
                        <div className="flex items-center gap-2">
                            <div className="min-w-0 flex-1">
                                <Select
                                    value={form.data.job_title}
                                    onChange={(e) => form.setData('job_title', e.target.value)}
                                    /*
                                        والوظيفةُ التي لا يُسندها الفاعلُ تبقى
                                        معروضةً معطَّلةً بسببها: دورُها يفتح ما
                                        لا يفتحه هو، ومن أسند دورًا أسند ما
                                        يحمله — انظر `Permissions::mayAssignRole`.
                                    */
                                    options={titles.map((j) => ({
                                        label: titleBlocked(j) ? `${j} — ${t('تفتح ما لا تفتحه')}` : j,
                                        value: j,
                                        disabled: titleBlocked(j),
                                    }))}
                                    placeholder="اختر الوظيفة…"
                                />
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                title={t('إضافة وظيفة')}
                                aria-label={t('إضافة وظيفة')}
                                onClick={() => setAddingTitle(true)}
                            >
                                <Plus />
                            </Button>
                        </div>
                    </Field>

                    <Field label="الفرع الأساسي" error={form.errors.branch}>
                        <Select
                            value={form.data.branch}
                            onChange={(e) => form.setData('branch', e.target.value)}
                            options={branches.map((b) => ({ label: b.name, value: b.name }))}
                            placeholder="اختر الفرع…"
                        />
                    </Field>

                    {/*
                        فروع العمل — هي مصدر الإذن، لا «الفرع الأساسي» أعلاه.
                        الفارغة تعني كل فروع المتجر: موظفوك الحاليون كلّهم بلا
                        تحديد، فجعلُ الفارغ منعًا كان سيقفل كل كاشير دفعةً واحدة.
                    */}
                    {branchOptions.length > 0 && (
                        <Field
                            label="فروع العمل"
                            className="md:col-span-2"
                            hint="بلا تحديد = كل الفروع. الموظف يدخل نقطة البيع في فروعه فقط"
                            error={form.errors.branches}
                        >
                            <div className="flex flex-wrap gap-2">
                                {branchOptions.map((b) => {
                                    const on = form.data.branches.includes(b.value);
                                    return (
                                        <button
                                            key={b.value}
                                            type="button"
                                            onClick={() =>
                                                form.setData(
                                                    'branches',
                                                    on
                                                        ? form.data.branches.filter((v) => v !== b.value)
                                                        : [...form.data.branches, b.value],
                                                )
                                            }
                                            className={cn(
                                                'rounded-[10px] border px-3 py-2 text-[13px] transition-colors',
                                                on
                                                    ? 'border-[#111] bg-[#111] text-white'
                                                    : 'border-[var(--ui-border,#e8e8e8)] bg-white text-[#4b4b4b] hover:bg-[#f7f7f5]',
                                            )}
                                        >
                                            {b.label}
                                        </button>
                                    );
                                })}
                            </div>
                        </Field>
                    )}

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
                hint="بالبريد وكلمة المرور يدخل الموظف — اللوحة ونقطة البيع معًا."
            >
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    {/*
                        إلزاميّ: هو الباب الوحيد بعد رفع الدخول بالرمز.
                        وهو فريدٌ على المنصة كلّها، فأوّل متجرين يريدان
                        `cashier@` يصطدمان — والتلميح يقول ذلك قبل الاصطدام.
                    */}
                    <Field
                        label="اسم المستخدم"
                        required={!legacyEmail}
                        hint="به يدخل الموظف — ولا يتكرّر على المنصة"
                        error={form.errors.login_username}
                    >
                        {/*
                            الحساب القديم خارج النطاق لا يُنقل بلا قصد: يُعرض
                            عنوانه كما هو، ولا يتبدّل إلا بضغطةٍ تقول ذلك. ولو
                            نُقل مع أيّ حفظ لَتبدّل بريدُ دخوله وهو يصحّح رقم
                            هاتفه — ثمّ يقف غدًا أمام الشاشة بعنوانٍ لا يعرفه.
                        */}
                        {legacyEmail ? (
                            <div className="flex items-center justify-between gap-3 rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-[#f7f7f5] px-3 py-2">
                                <span className="text-[13px] text-[#4b4b4b]" dir="ltr">
                                    {employee?.email}
                                </span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        setMoving(true);
                                        form.setData('login_username', usernameOf(employee?.email));
                                    }}
                                >
                                    {t('انقله إلى نطاق أبعاد')}
                                </Button>
                            </div>
                        ) : (
                            <UsernameInput
                                value={form.data.login_username}
                                onChange={(v) => form.setData('login_username', v)}
                                required
                            />
                        )}
                        {moving && (
                            <p className="mt-1.5 text-[12px] text-[#b45309]">
                                {t('سيتغيّر بريد دخوله عند الحفظ — أبلغه بالجديد.')}
                            </p>
                        )}
                    </Field>

                    {/*
                        الكلمة القائمة لا تُعرض هنا ولا في أيّ شاشة: تُحفظ
                        مُجزَّأةً بلا طريقٍ يعود منها إلى نصّها. ومن يفتح ملفّ
                        موظّفٍ يقرأ كلمته يفتح كلمةَ صاحبها في كل موقعٍ آخر
                        يستعملها فيه — والناس يعيدون كلماتهم.
                        فالمعروض هنا ما يُكتب الآن: يُكتب، ويُرى بالعين،
                        ثمّ يُملى على صاحبه.
                    */}
                    <Field
                        label={editing ? 'كلمة مرور جديدة' : 'كلمة المرور'}
                        hint={
                            editing
                                ? 'اتركها فارغة للإبقاء على الحالية — والجديدة ثمانية أحرف فيها حرف ورقم'
                                : 'ثمانية أحرف على الأقل فيها حرف ورقم — أو اتركها فارغة فتُولَّد وتُعرض لك مرّةً واحدة'
                        }
                        error={form.errors.password}
                    >
                        <PasswordInput
                            autoComplete="new-password"
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
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

            {/*
                الراتب قبل الهدف: هو ما يُدفع كلّ شهر، والهدف تقديرٌ يُقاس عليه.
                ومنه تُملأ مسيرة الرواتب — فبلا إدخاله تُفتح المسيرة على أصفار.
            */}
            <Section
                icon={Wallet}
                title="الراتب"
                hint={mayReadPayroll ? 'منه تُملأ مسيرة رواتب الشهر' : 'صلاحيةٌ لا تملكها'}
            >
                {! mayReadPayroll && (
                    <p className="rounded-[10px] bg-[#fffbeb] p-3 text-[12px] leading-relaxed text-[#b45309]">
                        {t('قراءة الرواتب صلاحيةٌ لا تملكها — يكتبها من يملكها، ولا يتغيّر ما هو مسجَّل بحفظك.')}
                    </p>
                )}

                <div className={cn('grid grid-cols-1 gap-4 md:grid-cols-2', ! mayReadPayroll && 'hidden')}>
                    <Field label="الراتب الأساسي" hint="اتركه فارغًا لمن لا راتب له" error={form.errors.basic_salary}>
                        <Input
                            inputMode="decimal"
                            dir="ltr"
                            value={form.data.basic_salary}
                            onChange={(e) => form.setData('basic_salary', e.target.value)}
                            placeholder="0"
                        />
                    </Field>

                    <Field label="البدلات" hint="سكن، مواصلات، وما يجري مجراها" error={form.errors.allowances}>
                        <Input
                            inputMode="decimal"
                            dir="ltr"
                            value={form.data.allowances}
                            onChange={(e) => form.setData('allowances', e.target.value)}
                            placeholder="0"
                        />
                    </Field>
                </div>
            </Section>

            {/*
                والعمولةُ رُفعت من هنا.

                كان تحت هذا العنوان حقلٌ ثانٍ — «نسبة العمولة %» — يُدخَل
                ويُحفظ ولا يُصرف منه شيء: لا مسيرةَ رواتبَ تقرؤه، ولا كشفَ
                عمولةٍ في النظام يُبنى عليه. ولافتةُ القسم كانت تقول «الهدف
                والعمولة»، فيُصدّق التاجرُ أنّ ما يكتبه يُحتسب لموظّفه.

                والحقلُ الذي لا يُدير شيئًا أسوأ من غيابه: صاحبُه يظنّ أنّ
                الأمر مضبوطٌ فلا يسأل عنه. فرُفع، والعمود يبقى بما فيه.
            */}
            <Section
                icon={Target}
                title="الهدف الشهري"
                hint="عليه يُحتسب «تحقيق الهدف» في قائمة الموظفين"
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
                </div>
            </Section>

            {sections && (
                <Section
                    icon={ShieldCheck}
                    title="الصلاحيات"
                    hint="حدّد ما يفتحه هذا الموظف — لا شيء يُفتح ما لم تُعلّمه"
                >
                    {!canEditPermissions ? (
                        <p className="text-[13px] text-[#6b7280]">
                            {t('لا يمكنك تعديل صلاحيات حسابك الخاص.')}
                        </p>
                    ) : (
                        <div className="space-y-4">
                            {/* ما لا تفتحه الباقة يُقال قبل المحاولة لا بعد الحفظ */}
                            {! canCustomize && (
                                <p className="rounded-[10px] border border-[#fed7aa] bg-[#fff7ed] px-3 py-2 text-[12px] text-[#9a3412]">
                                    {t('الصلاحيات المخصّصة ليست في باقتك الحالية — تُتبع صلاحيات الوظيفة. وما مُنح سابقًا يبقى كما هو.')}
                                </p>
                            )}

                            {/*
                                ═══ مصدرُ الصلاحية: وظيفتُه أم قائمةٌ له وحده ═══

                                سؤالٌ كان يُجاب في الخادم ولا يُسأل في الشاشة:
                                `null` تعني «اتبع الوظيفة» ويعالجها المتحكّم
                                بعناية — وبابُها كان مسدودًا، فالنموذج يرسل
                                «خصّص» أبدًا. فمن خُصّصت صلاحياتُه مرّةً بقي
                                عليها ولو بُدِّلت وظيفتُه.

                                والفرقُ بين الحالين ليس شكلًا: المتبِعُ تتبدّل
                                صلاحياتُه حين يُرقّى، والمخصَّصُ لا تتبدّل.
                            */}
                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                {([
                                    { on: false, title: 'اتبع صلاحيات الوظيفة', hint: 'تتبدّل صلاحياته كلّما تبدّلت وظيفته' },
                                    { on: true, title: 'خصّص لهذا الموظّف', hint: 'قائمةٌ له وحده — لا تتبدّل مع الوظيفة' },
                                ] as const).map((opt) => {
                                    /*
                                        وما لا تفتحه الباقة يُعطَّل — إلّا لمن خُصّصت
                                        صلاحياتُه قبل أن يوجد الحدّ أو قبل أن تنزل
                                        باقتُه. والخادمُ يُبقي القائم ولا يجيز إحداثَ
                                        جديد (`refuseManualPermissionsBeyondPlan`)،
                                        فتقول الشاشةُ ما يقوله الباب.
                                    */
                                    const locked = opt.on && ! canCustomize && employee?.permissions == null;
                                    const picked = form.data.manual_permissions === opt.on;

                                    return (
                                        <button
                                            key={String(opt.on)}
                                            type="button"
                                            disabled={locked}
                                            onClick={() => setManual(opt.on)}
                                            title={locked ? t('الصلاحيات المخصّصة ليست في باقتك الحالية.') : undefined}
                                            className={cn(
                                                'rounded-[10px] border p-3 text-start transition-colors',
                                                picked
                                                    ? 'border-[#111] bg-[#f9fafb]'
                                                    : 'border-[#e5e7eb] hover:bg-[#f9fafb]',
                                                locked && 'cursor-not-allowed opacity-60 hover:bg-transparent',
                                            )}
                                        >
                                            <span className="flex items-center gap-2">
                                                <span
                                                    className={cn(
                                                        'flex size-4 shrink-0 items-center justify-center rounded-full border',
                                                        picked ? 'border-[#111]' : 'border-[#d1d5db]',
                                                    )}
                                                >
                                                    {picked && <span className="size-2 rounded-full bg-[#111]" />}
                                                </span>
                                                <span className="text-sm font-medium text-[#111]">{t(opt.title)}</span>
                                            </span>
                                            <span className="mt-1 block ps-6 text-[12px] text-[#6b7280]">
                                                {t(opt.hint)}
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>

                            {/*
                                ومسمًّى لا صفَّ له لا يُخترع له جواب.

                                يقع فعلًا: موظّفٌ على الإنتاج يحمل «أمين مخزن»
                                وليست في وظائف متجره. فتُقال الحالُ كما هي بدل
                                أن تُعرض قائمةٌ فارغة تُقرأ «بلا صلاحيات».
                            */}
                            {! form.data.manual_permissions && ! titleKnown && (
                                <p className="rounded-[10px] border border-[#fde68a] bg-[#fffbeb] px-3 py-2 text-[12px] text-[#92400e]">
                                    {t('مسمّى «:title» ليس في قائمة الوظائف — تبقى صلاحياته على ما هي حتى تُسنَد إليه وظيفةٌ معروفة.', {
                                        title: form.data.job_title,
                                    })}
                                </p>
                            )}

                            {! form.data.manual_permissions && titleKnown && (
                                <p className="text-[12px] text-[#6b7280]">
                                    {t('ما تفتحه وظيفة «:title» — يُعرض ولا يُعدَّل من هنا.', {
                                        title: form.data.job_title || '—',
                                    })}
                                </p>
                            )}

                            <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                                {Object.entries(sections).map(([key, label]) => (
                                    <label
                                        key={key}
                                        title={mayGrant(key) ? undefined : t('لا تملك هذه الصلاحية — ولا تُمنح ما لا تملك.')}
                                        className={cn(
                                            'flex items-center gap-2.5',
                                            boxesLive && mayGrant(key)
                                                ? 'cursor-pointer'
                                                : 'cursor-not-allowed opacity-60',
                                        )}
                                    >
                                        {/* والمعروضُ من المصدر المختار: قائمتُه هو، أو قائمةُ وظيفته */}
                                        <input
                                            type="checkbox"
                                            checked={shown.includes(key)}
                                            onChange={() => togglePermission(key)}
                                            disabled={! boxesLive || ! mayGrant(key)}
                                            className="size-4 rounded border-[#d1d5db] accent-[#111]"
                                        />
                                        <span className="text-sm text-[#374151]">{label}</span>
                                    </label>
                                ))}
                            </div>

                            {actions && Object.keys(actions).length > 0 && (
                                <div className="space-y-2.5 border-t border-[#e5e7eb] pt-4">
                                    {/*
                                        والفعلُ يُعرض تحت عنوانه لا مع الأقسام: «المبيعات»
                                        تفتح شاشة، و«تصحيح فاتورة مكتملة» تعيد كتابة مستندٍ
                                        ضريبيّ — وصفٌّ واحد يجمعهما يجعل الثانية تُعلَّم سهوًا.
                                    */}
                                    <p className="text-[13px] font-semibold text-[#374151]">
                                        {t('أفعالٌ حسّاسة')}
                                    </p>
                                    <p className="text-[12px] text-[#6b7280]">
                                        {t('تُمنح بالاسم — ولا يفتح أيٌّ منها شاشةً بنفسه.')}
                                    </p>

                                    {Object.entries(actions).map(([key, label]) => (
                                        <label
                                            key={key}
                                            title={mayGrant(key) ? undefined : t('لا تملك هذه الصلاحية — ولا تُمنح ما لا تملك.')}
                                            className={cn(
                                                'flex items-center gap-2.5',
                                                boxesLive && mayGrant(key)
                                                    ? 'cursor-pointer'
                                                    : 'cursor-not-allowed opacity-60',
                                            )}
                                        >
                                            <input
                                                type="checkbox"
                                                checked={shown.includes(key)}
                                                onChange={() => togglePermission(key)}
                                                disabled={! boxesLive || ! mayGrant(key)}
                                                className="size-4 rounded border-[#d1d5db] accent-[#111]"
                                            />
                                            <span className="text-sm text-[#374151]">{label}</span>
                                        </label>
                                    ))}
                                </div>
                            )}

                            {form.errors.permissions && (
                                <p className="text-[12px] text-[#b91c1c]">{form.errors.permissions}</p>
                            )}
                        </div>
                    )}
                </Section>
            )}

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

            <Dialog open={addingTitle} onOpenChange={setAddingTitle}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('إضافة وظيفة')}</DialogTitle>
                    </DialogHeader>
                    {/*
                        النموذج هنا ليس <form> متداخلًا: نموذجٌ داخل نموذج
                        يجعل زرّ الحفظ يُرسل الاثنين، فيُحفظ الموظف ناقصًا.
                    */}
                    <div className="space-y-4 px-5 pb-5">
                        <Field label="اسم الوظيفة" required error={titleForm.errors.name}>
                            <Input
                                value={titleForm.data.name}
                                onChange={(e) => titleForm.setData('name', e.target.value)}
                                placeholder={t('مثال: مشرف الصالة')}
                            />
                        </Field>

                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="ghost" onClick={() => setAddingTitle(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="button" loading={titleForm.processing} onClick={saveTitle}>
                                <Check />
                                {t('إضافة')}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </form>
    );
}
