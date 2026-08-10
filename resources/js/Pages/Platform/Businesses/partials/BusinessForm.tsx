import { type FormEvent, useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Building2, Check, Image as ImageIcon, KeyRound, Layers, RefreshCw, Upload, User, X } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import AccountCard from './AccountCard';

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

/** نطاق حسابات التجّار — يطابق MerchantAccount::DOMAIN على الخادم */
export const MERCHANT_DOMAIN = '@abaadapp.om';

interface Props {
    options: BusinessOptions;
    initial: BusinessValues;
    /** الشعار المحفوظ — يُعرض حتى يختار المستخدم ملفًا جديدًا */
    logoUrl?: string | null;
    /**
     * بريد الحساب القائم — يُملأ به الحقل عند التعديل.
     *
     * غيابه يعني شركةً بلا حساب: تُطلب بياناته كما في الإنشاء.
     */
    ownerEmail?: string | null;
    /** مطلوب عند التعديل: بطاقة الحساب تحفظ بمسارها الخاص */
    businessId?: number;
    action: string;
    /** التعديل يمرّ بـPUT عبر _method لأن الطلب متعدّد الأجزاء (رفع ملف) */
    method: 'post' | 'put';
    submitLabel: string;
    cancelHref: string;
}

/** كلمة مرور مقروءة: بلا حروف تلتبس (l/1/O/0) لأنها تُملى في الهاتف */
export function randomPassword(): string {
    const chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    return Array.from(
        crypto.getRandomValues(new Uint32Array(10)),
        (n) => chars[n % chars.length],
    ).join('');
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
    ownerEmail,
    businessId,
    action,
    method,
    submitLabel,
    cancelHref,
}: Props) {
    const t = useTranslate();
    const creating = method === 'post';
    /*
     * الحساب يُطلب عند الإنشاء، وعند تعديل شركةٍ لا حساب لها.
     *
     * الشركات المسجّلة قبل إلزام الحساب بقيت بلا مستخدم، فكانت الصفحة تعرض
     * «—» بلا مخرج: شركةٌ لا يفتحها أحد ولا سبيل إلى إصلاحها من اللوحة.
     */
    const needsAccount = creating || !ownerEmail;
    const form = useForm<
        BusinessValues & {
            logo: File | null;
            remove_logo: boolean;
            login_username: string;
            login_password: string;
        }
    >({
        ...initial,
        logo: null,
        remove_logo: false,
        /*
         * الاسم القائم يُملأ سلفًا: التعديل يُصحّح الموجود لا يبدأ من فراغ.
         *
         * والقطع عند @ لا بنزع نطاقنا: حسابات أُنشئت قبل توحيد النطاق تحمل
         * نطاقًا آخر، فنزعُ «@abaadapp.om» منها لا يطابق شيئًا — فيمتلئ الحقل
         * بالبريد كاملًا، ويُرفض الحفظ لأن @ ليست حرفًا مسموحًا في الاسم.
         */
        login_username: ownerEmail?.split('@')[0] ?? '',
        login_password: '',
    });
    const [preview, setPreview] = useState<string | null>(logoUrl ?? null);
    const fileRef = useRef<HTMLInputElement>(null);

    const pickLogo = (file: File | null) => {
        form.setData((d) => ({ ...d, logo: file, remove_logo: false }));
        setPreview(file ? URL.createObjectURL(file) : (logoUrl ?? null));
    };

    /*
     * الحذف يبلّغ الخادم، لا يمسح المعاينة وحدها.
     *
     * إخفاء الصورة من الشاشة دون علامةٍ تصل مع الطلب كان سيترك الشعار
     * القديم في القاعدة: يضغط المستخدم «حذف» ويحفظ، فيعود الشعار عند فتح
     * الصفحة — حذفٌ ظاهرٌ لم يحدث.
     *
     * وقيمة حقل الملف تُصفَّر: بدونها لا يُطلق المتصفّح onChange إن أعاد
     * اختيار الملف نفسه بعد حذفه.
     */
    const clearLogo = () => {
        form.setData((d) => ({ ...d, logo: null, remove_logo: true }));
        setPreview(null);
        if (fileRef.current) {
            fileRef.current.value = '';
        }
    };

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
                    {/*
                        كتابةٌ حرّة بحتة: لا قائمة ولا اقتراحات.

                        كانت ستّة أنواع لا سابع لها، فمن يسجّل مغسلةً أو ورشةً
                        يضطرّ إلى «بقالة» — يُكذَب النوع في السجلّ من أول يوم.
                        ثم صارت اقتراحاتٍ تنسدل، وهي تدعو إلى الاختيار من
                        ستّةٍ لا تشبه ما بين يديه، فيعود إلى الكذب نفسه.

                        والعمود نصّ حرّ أصلًا، وأيّ نوع غير معروف يأخذ تصنيفات
                        البداية العامة (BusinessTypes::GENERIC).
                        مطلوب: عمود NOT NULL في قاعدة البيانات.
                    */}
                    <Field label="نوع النشاط" required error={form.errors.type}>
                        <Input
                            value={form.data.type}
                            onChange={(e) => form.setData('type', e.target.value)}
                            placeholder={t('مثال: محل ورود')}
                            autoComplete="off"
                            required
                        />
                    </Field>
                    <Field label="الدولة" error={form.errors.country}>
                        <Input
                            value={form.data.country}
                            onChange={(e) => form.setData('country', e.target.value)}
                        />
                    </Field>
                    {/* خمس مدن كانت تُغطّي عُمان كلّها — والبريمي ليست منها.
                        تُكتب كما هي، بلا قائمةٍ تنسدل توحي بأنها كل الخيارات. */}
                    <Field label="المدينة" error={form.errors.city}>
                        <Input
                            value={form.data.city}
                            onChange={(e) => form.setData('city', e.target.value)}
                            placeholder={t('مثال: مسقط')}
                            autoComplete="off"
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
                    {/* «التواصل» في الاسم: الحقل لا يفتح حسابًا ولا يبدّل دخولًا،
                        وكُتب فيه بريد الدخول مرارًا لأن اسمه لم يقل غير ذلك */}
                    <Field
                        label="بريد التواصل"
                        hint="للتواصل مع الشركة — حساب الدخول يُضبط من بطاقة «حساب الدخول»"
                        className="md:col-span-2"
                        error={form.errors.email}
                    >
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
                <KeyRound className="size-5" />,
                'حساب دخول التاجر',
                needsAccount || !businessId ? (
                <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                    {creating ? (
                        <p className="text-[13px] text-[#6b7280] md:col-span-2">
                            {t('بهذا الحساب يدخل صاحب الشركة لوحته. النطاق ثابت لكل التجّار.')}
                        </p>
                    ) : needsAccount ? (
                        <p className="rounded-[10px] bg-[#fff7ed] px-3 py-2 text-[13px] text-[#9a3412] md:col-span-2">
                            {t('هذه الشركة بلا حساب دخول — لا يستطيع صاحبها فتح لوحته. أنشئ له حسابًا الآن.')}
                        </p>
                    ) : (
                        <p className="text-[13px] text-[#6b7280] md:col-span-2">
                            {t('تغيير الاسم يغيّر بريد الدخول نفسه — أبلغ صاحب الشركة بالجديد.')}
                        </p>
                    )}

                    {/*
                        الاسم وحده يُكتب والنطاق مُلحق ثابت: كتابةُ النطاق
                        يدويًّا تُنتج عناوين على أشكال (‎.om‎ و‎.com‎ وحرفٌ
                        زائد)، ثم لا يدخل صاحبها ولا يعرف السبب.
                    */}
                    <Field label="اسم المستخدم" required error={form.errors.login_username}>
                        <div className="flex items-stretch" dir="ltr">
                            <Input
                                className="rounded-e-none"
                                value={form.data.login_username}
                                onChange={(e) =>
                                    form.setData('login_username', e.target.value.toLowerCase())
                                }
                                placeholder="zahra"
                                autoComplete="off"
                                required
                            />
                            <span className="flex items-center rounded-e-[10px] border border-s-0 border-[var(--ui-border,#e8e8e8)] bg-[#f7f7f5] px-3 text-sm text-[#6b7280]">
                                {MERCHANT_DOMAIN}
                            </span>
                        </div>
                        {form.data.login_username && (
                            <p className="mt-1.5 text-[12px] text-[#6b7280]" dir="ltr">
                                {form.data.login_username}
                                {MERCHANT_DOMAIN}
                            </p>
                        )}
                    </Field>

                    {/*
                        كلمة المرور تُبدَّل من هنا لا من لوحة التاجر: من نسيها
                        لا يدخل لوحته أصلًا، و«نسيت كلمة المرور» محذوفة — فبلا
                        هذا الحقل لا مخرج إلا قاعدة البيانات.

                        وعلى الحساب القائم تبقى اختيارية: الفارغ يعني «لا
                        تغيّرها»، لئلا يُخرج تصحيحُ مدينةٍ تاجرًا من حسابه.
                    */}
                    <Field
                        label={needsAccount ? 'كلمة المرور' : 'كلمة مرور جديدة'}
                        required={needsAccount}
                        hint={needsAccount ? 'ثمانية أحرف على الأقل' : 'اتركه فارغًا إن لم ترد تغييرها'}
                        error={form.errors.login_password}
                    >
                        <div className="flex items-stretch gap-2">
                            <Input
                                dir="ltr"
                                autoComplete="new-password"
                                value={form.data.login_password}
                                onChange={(e) => form.setData('login_password', e.target.value)}
                                placeholder={needsAccount ? undefined : '••••••••'}
                                required={needsAccount}
                            />
                            {/* كلمةٌ مولَّدة أفضل من «12345678» يكتبها المشغّل لكل تاجر */}
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => form.setData('login_password', randomPassword())}
                                title={t('ولّد كلمة مرور')}
                                aria-label={t('ولّد كلمة مرور')}
                            >
                                <RefreshCw />
                            </Button>
                        </div>
                    </Field>
                </div>
                ) : (
                    /*
                        الحساب القائم: البطاقة نفسها التي في ملف الشركة.
                        كانت هنا حقولٌ داخل نموذج الشركة وهناك بطاقةٌ بأزرار،
                        فيقف المشغّل في الصفحة التي وصل منها ولا يجد ما وُعد به.
                    */
                    <AccountCard bare businessId={businessId} ownerEmail={ownerEmail!} />
                ),
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
                            <div className="relative shrink-0">
                                <img
                                    src={preview}
                                    alt={t('معاينة الشعار')}
                                    className="size-20 rounded-[16px] border border-[var(--ui-border,#e8e8e8)] object-cover"
                                />
                                {/* على الصورة نفسها: الحذف يخصّ هذه الصورة لا النموذج */}
                                <button
                                    type="button"
                                    onClick={clearLogo}
                                    aria-label={t('حذف الشعار')}
                                    title={t('حذف الشعار')}
                                    className="absolute -end-2 -top-2 flex size-7 items-center justify-center rounded-full border border-[var(--ui-border,#e8e8e8)] bg-white text-[#b91c1c] shadow-[0_1px_3px_rgba(0,0,0,0.12)] transition-colors hover:bg-[#fef2f2]"
                                >
                                    <X className="size-4" />
                                </button>
                            </div>
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
                                ref={fileRef}
                                type="file"
                                accept="image/*"
                                className="hidden"
                                onChange={(e) => pickLogo(e.target.files?.[0] ?? null)}
                            />
                        </label>
                    </div>
                    {form.data.remove_logo && logoUrl && (
                        <p className="mt-3 text-[12px] text-[#b45309]">
                            {t('سيُحذف الشعار عند الحفظ.')}
                        </p>
                    )}
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
