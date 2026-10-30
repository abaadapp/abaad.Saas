import { type FormEvent, useRef, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Building2, Check, ChevronDown, ClipboardCheck, Crown, Image as ImageIcon, KeyRound, Layers, RefreshCw, Shapes, Upload, User, X } from 'lucide-react';
import PlanSelectionSummary, { type PlanOption } from '@/Components/PlanSelectionSummary';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
import { MERCHANT_DOMAIN, UsernameInput } from '@/Components/ui/username-input';
import SectorIcon from '@/Components/SectorIcon';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { randomPassword } from '@/lib/password';
import { cn } from '@/lib/utils';
import AccountCard from './AccountCard';
import AdminGrantField from './AdminGrantField';
import NewSubscriptionFields, { type NewSubscriptionOptions, type NewSubscriptionValues } from './NewSubscriptionFields';
import type { Currency } from '@/types';

export interface BusinessOptions {
    types: string[];
    cities: string[];
    statuses: string[];
    /** الباقات بتفاصيلها — تُعرض المختارةُ منها تحت الاختيار */
    plans: PlanOption[];
    /** طرقُ فتح الاشتراك لمتجرٍ جديد — في الإنشاء وحده (`NewMerchantSubscription::options`) */
    subscription?: NewSubscriptionOptions;
    /**
     * القطاعاتُ النشطة — والباقاتُ تُعرض من قطاع الشركة وحده.
     *
     * والغائبُ (شاشةٌ قديمة، أو حارسٌ يبني النموذج) يعرض الباقاتِ كلَّها كما كانت:
     * الخادمُ يحكم بالقطاع على كلّ حال (`Sectors::assertPlanFits`).
     */
    sectors?: SectorOption[];
}

export interface SectorOption {
    id: number;
    name: string;
    name_en?: string | null;
    icon: string;
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
    /** قطاعُ الشركة — يُختار وحده حين لا نشطَ غيرُه، أو يصل من صفحة القطاع */
    sector_id?: string;
    status: string;
    starts_at: string;
    ends_at: string;
    /** الفئة: '' لسائر المتاجر، 'gold' للنظام المستقلّ */
    tier: string;
    /** الواجهة الخاصّة: '' كسائر المتاجر، 'ribbon' لواجهة RIBBON */
    storefront_theme: string;
    /**
     * أيُؤوي بوتيكاتٍ تبيع تحت سقفه؟ — مفتاحٌ يفتحه مديرُ المنصّة لا قدرةُ باقة.
     *
     * واختياريٌّ في النوع: الغائبُ مغلق. فلا يُجبَر كلُّ موضعٍ يبني نموذجًا
     * — ومنها حرّاسٌ قائمة — على ذكر مفتاحٍ لا شأنَ له به.
     */
    boutiques_enabled?: boolean;
    /** مستحقّاتُ مكوّنات البوتيك — تحت «يُؤوي بوتيكات»، ومقفلةٌ لمن يحتسب الضريبة (يُحكم في الخادم) */
    boutique_components_enabled?: boolean;
    /** الإهداءُ في المتجر الإلكترونيّ — مثلُ البوتيكات: الغائبُ مغلق */
    gift_orders_enabled?: boolean;
    order_notes_and_edits_enabled?: boolean;
    /** سندُ التسليم على شريط الصندوق لطلبات الموقع — الغائبُ مغلق (`DeliveryPaper::thermalOn`) */
    thermal_delivery_note_enabled?: boolean;
    /** تجهيزُ فواتير العملاء بعد الدفع — الغائبُ مغلق (`CustomerInvoicePreparations::on`) */
    customer_invoice_preparation_enabled?: boolean;
    /** اشتراكُ المتجر الجديد — في الإنشاء وحده */
    subscription_mode?: NewSubscriptionValues['subscription_mode'];
    cycle?: NewSubscriptionValues['cycle'];
    grant_until?: string;
    grant_reason?: string;
    addons?: NewSubscriptionValues['addons'];
}

/* النطاق ومكوّنه في مكانٍ واحد — انظر Components/ui/username-input */
export { MERCHANT_DOMAIN } from '@/Components/ui/username-input';

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
    /** يُسأل عن منحةٍ إداريّة في التعديل — `AdminGrants::asked`، والغائبُ لا */
    grantable?: boolean;
    /** القطاعُ المحدَّد سلفًا — من صفحة القطاع، أو الوحيدُ النشط */
    sectorId?: number | null;
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
    ownerEmail,
    businessId,
    grantable = false,
    sectorId,
    action,
    method,
    submitLabel,
    cancelHref,
}: Props) {
    const t = useTranslate();
    const creating = method === 'post';
    const { currency } = usePage<{ currency: Currency }>().props;
    // الإنشاءُ بطريقة اشتراكٍ صريحة — والخادمُ يكتب الحالةَ والتاريخين بحسبها
    const newSubscription = creating ? options.subscription : undefined;
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
        sector_id: initial.sector_id ?? (sectorId ? String(sectorId) : ''),
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

        // ضغطةٌ ثانيةٌ والأولى تُحفظ لا تُرسل شيئًا — والخادمُ يردّ المكرَّر على كلّ حال
        if (form.processing) return;

        /*
         * ما لا يُعرض لا يُرسَل.
         *
         * حقلا الحساب يبقيان في بيانات النموذج حتى حين لا تُعرض بطاقتهما —
         * ويحملان الاسم كما كان لحظة فتح الصفحة. فيبدّل المشغّل اسم الدخول من
         * البطاقة (ويقع التبديل)، ثم يضغط «حفظ التعديلات» فيصعد الاسم القديم
         * معه ويعيده. لا شيء يفشل ولا رسالة تظهر: تعديلٌ وقع ثم أُلغي بيد
         * صاحبه، وسجلّ النشاط يقيّد الاثنين في الثانية نفسها.
         *
         * والحذف لا الإفراغ: قيمةٌ فارغة تعني «لا تغيّره» عند الخادم أصلًا،
         * لكنّ الحذف يقول المقصود بلا اعتمادٍ على تفسير الطرف الآخر.
         */
        form.transform((data) => {
            const out: Record<string, unknown> = { ...data };
            if (method === 'put') out._method = 'put';
            if (!needsAccount) {
                delete out.login_username;
                delete out.login_password;
            }
            /*
             * ولا يُرسل حقلُ ملفٍّ لم يُختر.
             *
             * النموذج يُجبَر على FormData لأجل الملفّ، وFormData لا يعرف
             * null: يكتبها نصًّا فارغًا. فيصل الخادمَ مفتاحٌ حاضرٌ فارغ في
             * كل حفظ — وحضورُ المفتاح عنده يعني «غيِّر الشعار» لا «اتركه».
             *
             * الخادم يحرس نفسه من هذا، لكنّ الطلب لا يقول ما لا يقصده.
             */
            if (!out.logo) delete out.logo;
            // ولا سببُ منحةٍ لم يُفتح حقلُها (`AdminGrantField`)
            if (!out.grant_reason) delete out.grant_reason;
            // ولا قطاعٌ فارغ: الخادمُ يختار الوحيدَ النشط أو يطلبه
            if (!out.sector_id) delete out.sector_id;

            return out;
        });

        form.post(action, { forceFormData: true });
    };

    const section = (icon: React.ReactNode, title: string, children: React.ReactNode, testId?: string) => (
        <Card className="p-6" data-testid={testId}>
            <div className="mb-5 flex items-center gap-2">
                <span className="flex size-9 items-center justify-center rounded-[12px] bg-[#f2f2f0] text-[#4b4b4b]">
                    {icon}
                </span>
                <h3 className="font-bold text-[#111]">{t(title)}</h3>
            </div>
            {children}
        </Card>
    );

    const errors = form.errors as Partial<Record<string, string>>;

    /* ═══ القطاع — وباقاتُ قطاعه وحدها ═══ */

    const sectors = options.sectors;
    const sector = sectors?.find((s) => String(s.id) === String(form.data.sector_id));
    /*
     * باقاتُ قطاع الشركة وحدها — والخادمُ يردّ غيرَها ولو عُدّل الطلب.
     *
     * وبلا قطاعاتٍ في الخيارات (شاشةٌ قديمة) تُعرض كلُّها كما كانت.
     */
    const plans = sectors && form.data.sector_id
        ? options.plans.filter((p) => p.sector_id == null || String(p.sector_id) === String(form.data.sector_id))
        : options.plans;
    const pickSector = (id: string) =>
        form.setData((d) => ({
            ...d,
            sector_id: id,
            // باقةٌ من قطاعٍ آخر تسقط بتغيّره، وإضافاتُها معها
            ...(options.plans.find((p) => String(p.value) === String(d.plan_id))?.sector_id != null &&
            String(options.plans.find((p) => String(p.value) === String(d.plan_id))?.sector_id) !== id
                ? { plan_id: '', addons: [] }
                : {}),
        }));

    /* ═══ الحقول — تُرتَّب في الإنشاء غيرَ ترتيبها في التعديل ═══ */

    const nameField = (
        <Field label="اسم الشركة" required error={form.errors.name}>
            <Input
                value={form.data.name}
                onChange={(e) => form.setData('name', e.target.value)}
                placeholder={t('مثال: زهرة مسقط')}
                required
            />
        </Field>
    );

    /*
        كتابةٌ حرّة بحتة: لا قائمة ولا اقتراحات.

        كانت ستّة أنواع لا سابع لها، فمن يسجّل مغسلةً أو ورشةً
        يضطرّ إلى «بقالة» — يُكذَب النوع في السجلّ من أول يوم.
        ثم صارت اقتراحاتٍ تنسدل، وهي تدعو إلى الاختيار من
        ستّةٍ لا تشبه ما بين يديه، فيعود إلى الكذب نفسه.

        والعمود نصّ حرّ أصلًا، وأيّ نوع غير معروف يأخذ تصنيفات
        البداية العامة (BusinessTypes::GENERIC).
        مطلوب: عمود NOT NULL في قاعدة البيانات.
    */
    const typeField = (
        <Field label="نوع النشاط" required error={form.errors.type}>
            <Input
                value={form.data.type}
                onChange={(e) => form.setData('type', e.target.value)}
                placeholder={t('مثال: محل ورود')}
                autoComplete="off"
                required
            />
        </Field>
    );

    const countryField = (
        <Field label="الدولة" error={form.errors.country}>
            <Input
                value={form.data.country}
                onChange={(e) => form.setData('country', e.target.value)}
            />
        </Field>
    );

    /* خمس مدن كانت تُغطّي عُمان كلّها — والبريمي ليست منها.
       تُكتب كما هي، بلا قائمةٍ تنسدل توحي بأنها كل الخيارات. */
    const cityField = (
        <Field label="المدينة" error={form.errors.city}>
            <Input
                value={form.data.city}
                onChange={(e) => form.setData('city', e.target.value)}
                placeholder={t('مثال: مسقط')}
                autoComplete="off"
            />
        </Field>
    );

    const addressField = (
        <Field label="العنوان" className="md:col-span-2" error={form.errors.address}>
            <Input
                value={form.data.address}
                onChange={(e) => form.setData('address', e.target.value)}
                placeholder={t('الحي، الشارع، رقم المبنى')}
            />
        </Field>
    );

    const ownerNameField = (
        <Field label="اسم المالك" error={form.errors.owner_name}>
            <Input
                value={form.data.owner_name}
                onChange={(e) => form.setData('owner_name', e.target.value)}
                placeholder={t('الاسم الكامل')}
            />
        </Field>
    );

    const phoneField = (
        <Field label="رقم الهاتف" error={form.errors.phone}>
            <Input
                type="tel"
                dir="ltr"
                value={form.data.phone}
                onChange={(e) => form.setData('phone', e.target.value)}
                placeholder="+968 9xxxxxxx"
            />
        </Field>
    );

    /* «التواصل» في الاسم: الحقل لا يفتح حسابًا ولا يبدّل دخولًا،
       وكُتب فيه بريد الدخول مرارًا لأن اسمه لم يقل غير ذلك */
    const emailField = (
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
    );

    const accountBody = needsAccount || !businessId ? (
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
                <UsernameInput
                    value={form.data.login_username}
                    onChange={(v) => form.setData('login_username', v)}
                    required
                />
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
                    <PasswordInput
                        className="w-full"
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
    );

    const trialLocked = !!newSubscription && form.data.subscription_mode === 'trial';
    const planField = (
        <Field label="الباقة" error={form.errors.plan_id} hint={trialLocked ? t('التجربة على الأساسية') : undefined}>
            <Select
                value={trialLocked ? String(newSubscription!.basic_plan_id ?? form.data.plan_id) : form.data.plan_id}
                disabled={trialLocked}
                // والإضافاتُ إضافاتُ الباقة: تُسقط حين تتغيّر
                onChange={(e) => {
                    form.setData('plan_id', e.target.value);
                    if (newSubscription) form.setData('addons', []);
                }}
                options={plans.map((p) => ({ label: p.label, value: p.value }))}
                placeholder="اختر الباقة…"
            />
            {/* ما تبيعه الباقةُ المختارة — من صفّها، لا قائمةُ قدرات النظام */}
            <PlanSelectionSummary
                plan={options.plans.find((p) => String(p.value) === String(form.data.plan_id))}
            />
        </Field>
    );

    const subscriptionBody = newSubscription ? (
        <div className="md:col-span-2">
            <NewSubscriptionFields
                options={newSubscription}
                planId={form.data.plan_id}
                values={{
                    subscription_mode: form.data.subscription_mode ?? 'trial',
                    cycle: form.data.cycle ?? '',
                    grant_until: form.data.grant_until ?? '',
                    grant_reason: form.data.grant_reason ?? '',
                    addons: form.data.addons ?? [],
                }}
                set={(key, value) =>
                    form.setData((d) => ({ ...d, [key]: value, ...(key === 'subscription_mode' && value === 'trial' ? { addons: [] } : {}) }))
                }
                errors={errors}
                currency={currency}
            />
        </div>
    ) : (
        <>
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
            {/*
                ═══ والمنحةُ الإداريّة صريحةٌ هنا ═══

                باقةٌ أعلى أو مدّةٌ أطول أو حسابٌ بانتظار السداد يردّها الخادم
                ويسمّيها (`AdminGrants`): تُرقّى وتُجدَّد بفاتورتها من صفحة المتجر.
                ومن أراد منحتها فتحها وكتب سببها — فتُحفظ بتاريخ الانتهاء وتُسجَّل.
            */}
            <AdminGrantField
                grantable={grantable}
                value={form.data.grant_reason ?? ''}
                error={form.errors.grant_reason}
                onChange={(v) => form.setData('grant_reason', v)}
                testId="business-grant"
                className="md:col-span-2"
            />
        </>
    );

    const flag = (key: 'gift_orders_enabled' | 'order_notes_and_edits_enabled' | 'thermal_delivery_note_enabled' | 'customer_invoice_preparation_enabled', label: string, hint: string, testId: string) => (
        <label className="flex cursor-pointer items-start gap-2.5 sm:col-span-2" data-testid={testId}>
            <input
                type="checkbox"
                checked={form.data[key] ?? false}
                onChange={(e) => form.setData(key, e.target.checked)}
                className="mt-0.5 size-4 rounded border-[#d1d5db] accent-[#111]"
            />
            <span>
                <span className="block text-sm text-[#374151]">{t(label)}</span>
                <span className="block text-[12px] text-[#9ca3af]">{t(hint)}</span>
            </span>
        </label>
    );

    const advancedBody = (
        <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
            {/*
                زبونٌ يُعامَل نظامًا مستقلًّا داخل أبعاد: يُوسم ذهبيًّا
                فيُعرف بعلامته، وتُضبط له واجهةٌ تُخدم على عنوان متجره
                بدل الصفحة البسيطة والبانِي. وفراغُهما «كسائر المتاجر».
            */}
            <Field label="الفئة" error={form.errors.tier}>
                <Select
                    value={form.data.tier}
                    onChange={(e) => form.setData('tier', e.target.value)}
                    options={[{ label: t('ذهبي — نظام مستقلّ داخل أبعاد'), value: 'gold' }]}
                    placeholder="عادي"
                    aria-label={t('الفئة')}
                />
            </Field>
            <Field label="واجهة المتجر الإلكتروني" error={form.errors.storefront_theme}>
                <Select
                    value={form.data.storefront_theme}
                    onChange={(e) => form.setData('storefront_theme', e.target.value)}
                    options={[{ label: 'RIBBON', value: 'ribbon' }]}
                    placeholder="الافتراضية (البانِي أو الصفحة البسيطة)"
                    aria-label={t('واجهة المتجر الإلكتروني')}
                />
            </Field>

            {/*
                والبوتيكاتُ مفتاحٌ لا قائمة.
                محلٌّ يُؤجّر أركانَه لبوتيكاتٍ تبيع تحته ويأخذ نسبةً
                ممّا تبيع — وهي صفةُ محلٍّ بعينه لا درجةُ اشتراك.
            */}
            <label className="flex cursor-pointer items-start gap-2.5 sm:col-span-2">
                <input
                    type="checkbox"
                    checked={form.data.boutiques_enabled ?? false}
                    onChange={(e) => form.setData('boutiques_enabled', e.target.checked)}
                    className="mt-0.5 size-4 rounded border-[#d1d5db] accent-[#111]"
                />
                <span>
                    <span className="block text-sm text-[#374151]">{t('يُؤوي بوتيكات')}</span>
                    <span className="block text-[12px] text-[#9ca3af]">
                        {t('تبويب «البوتيكات» في المنتجات: نسبة المتجر وكشف حساب شهري لكل بوتيك')}
                    </span>
                </span>
            </label>
            {form.data.boutiques_enabled && (
                <label className="flex cursor-pointer items-start gap-2.5 sm:col-span-2" data-testid="boutique-components-switch">
                    <input
                        type="checkbox"
                        checked={form.data.boutique_components_enabled ?? false}
                        onChange={(e) => form.setData('boutique_components_enabled', e.target.checked)}
                        className="mt-0.5 size-4 rounded border-[#d1d5db] accent-[#111]"
                    />
                    <span>
                        <span className="block text-sm text-[#374151]">{t('مستحقّات البوتيك عن مكوّنات الباقات')}</span>
                        <span className="block text-[12px] text-[#9ca3af]">
                            {t('صنفُ بوتيكٍ داخل باقةٍ أو إضافتِها يُستحقّ لصاحبه ثمنُه عند البيع. لا يُفتح لمتجرٍ يحتسب الضريبة.')}
                        </span>
                        {errors.boutique_components_enabled && (
                            <span className="block text-[12px] text-[#b91c1c]">{errors.boutique_components_enabled}</span>
                        )}
                    </span>
                </label>
            )}

            {/* والإهداءُ في المتجر الإلكترونيّ مفتاحٌ يُفتح لنشاطٍ بعينه — لا يفتحه التاجرُ من إعدادات موقعه (`Store\GiftOrders::on`) */}
            {flag('gift_orders_enabled', 'الإهداء في المتجر الإلكتروني', 'السماح لهذا النشاط باستقبال طلبات هدايا لمستلمين آخرين عبر المتجر الإلكتروني.', 'gift-orders-enabled')}
            {/* وملاحظاتُ العميل وتعديلُ أصناف الفاتورة بعد صدورها — مفتاحٌ يُفتح لنشاطٍ بعينه (`NotesAndEdits::on`) */}
            {flag('order_notes_and_edits_enabled', 'ملاحظات العميل وتعديل أصناف الفاتورة', 'ملاحظة المنتج وملاحظات الطلب في الموقع بالإنجليزية، وإضافة صنف أو استبداله في الفاتورة بعد صدورها لمن يملك صلاحية تعديل الفواتير.', 'order-notes-and-edits-enabled')}
            {/* وسندُ التسليم على شريط الصندوق لطلبات الموقع (`DeliveryPaper::thermalOn`) — وطلباتُ الصندوق وسائرُ القنوات على A4 كما كانت */}
            {flag('thermal_delivery_note_enabled', 'سند التسليم الحراري لطلبات الموقع', 'يُطبع سند تسليم طلبات المتجر الإلكتروني على طابعة الصندوق (58 أو 80 مم) بدل ورقة A4. طلبات نقطة البيع تبقى كما هي.', 'thermal-delivery-note-enabled')}
            {/* وتجهيزُ فواتير العملاء بعد الدفع (`CustomerInvoicePreparations::on`) — والتاجرُ لا يفتحه لنفسه */}
            {flag('customer_invoice_preparation_enabled', 'تجهيز فواتير العملاء بعد الدفع', 'فاتورة العميل التي يُطلب تجهيزها تظهر في لوحة التجهيز بعد سدادها كاملًا عبر رابط الدفع، وتُطبع لها نسخة داخلية على صندوق الفرع وسند تسليم يدويًا.', 'customer-invoice-preparation-enabled')}
        </div>
    );

    const logoBody = (
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
        </>
    );

    const actions = (
        <div className="flex items-center justify-end gap-3">
            <Button variant="outline" asChild>
                <SmartLink routeName="super-admin.businesses.index" href={cancelHref}>
                    {t('إلغاء')}
                </SmartLink>
            </Button>
            <Button type="submit" loading={form.processing} data-testid="business-save">
                <Check />
                {t(submitLabel)}
            </Button>
        </div>
    );

    /* ═══ الإنشاء: صفحةٌ واحدة بخمسة أقسام، وزرُّ حفظٍ واحد ═══ */

    if (creating) {
        return (
            <form onSubmit={submit} className="space-y-6" data-testid="business-create-form">
                {sectors && sectors.length > 0 &&
                    section(
                        <Shapes className="size-5" />,
                        'القطاع',
                        sectors.length === 1 || sectorId ? (
                            /* قطاعٌ نشطٌ واحد، أو وصلنا من صفحته: يُحدَّد وحده ولا يُسأل عنه */
                            <div className="flex items-center gap-3 rounded-[12px] bg-[#fafafa] px-4 py-3" data-testid="business-sector-fixed">
                                <span className="flex size-9 items-center justify-center rounded-[10px] bg-white text-[#111]">
                                    <SectorIcon icon={sector?.icon ?? 'store'} className="size-5" />
                                </span>
                                <span className="min-w-0">
                                    <span className="block font-medium text-[#111]">{t(sector?.name ?? '')}</span>
                                    <span className="block text-[12px] text-[#9ca3af]">{t('حُدِّد تلقائيًا — باقاتُه وحدها تظهر أدناه')}</span>
                                </span>
                            </div>
                        ) : (
                            <Field label="القطاع" required error={errors.sector_id}>
                                <Select
                                    value={form.data.sector_id ?? ''}
                                    onChange={(e) => pickSector(e.target.value)}
                                    options={sectors.map((s) => ({ label: s.name, value: String(s.id) }))}
                                    placeholder="اختر القطاع…"
                                    aria-label={t('القطاع')}
                                />
                            </Field>
                        ),
                        'business-sector',
                    )}

                {section(
                    <Building2 className="size-5" />,
                    'بيانات الشركة',
                    <div className="space-y-5">
                        <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                            {nameField}
                            {typeField}
                            {phoneField}
                            {cityField}
                        </div>
                        <Disclosure
                            title="تفاصيل إضافية"
                            hint="الدولة والعنوان وبريد التواصل والشعار — اختيارية"
                            invalid={!!(errors.country || errors.address || errors.email || errors.logo)}
                            testId="business-extra"
                        >
                            <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                                {countryField}
                                {addressField}
                                {emailField}
                            </div>
                            <div className="mt-5">
                                <p className="mb-2 text-sm font-medium text-[#374151]">{t('شعار الشركة')}</p>
                                {logoBody}
                            </div>
                        </Disclosure>
                    </div>,
                    'business-company',
                )}

                {section(
                    <KeyRound className="size-5" />,
                    'بيانات المالك وحساب الدخول',
                    <div className="space-y-5">
                        <div className="grid grid-cols-1 gap-5 md:grid-cols-2">{ownerNameField}</div>
                        {accountBody}
                    </div>,
                    'business-owner',
                )}

                {section(
                    <Layers className="size-5" />,
                    'الباقة والاشتراك',
                    <div className="space-y-5">
                        <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                            {planField}
                            {subscriptionBody}
                        </div>
                        <Disclosure
                            title="خيارات متقدمة"
                            hint="الفئة والواجهة الخاصّة ومفاتيح النشاط — مطفأة افتراضًا"
                            invalid={!!(errors.tier || errors.storefront_theme || errors.boutique_components_enabled)}
                            testId="business-advanced"
                        >
                            {advancedBody}
                        </Disclosure>
                    </div>,
                    'business-subscription',
                )}

                {section(
                    <ClipboardCheck className="size-5" />,
                    'المراجعة والحفظ',
                    <>
                        <ReviewSummary
                            values={form.data}
                            sector={sector?.name}
                            plan={options.plans.find((p) => String(p.value) === String(trialLocked ? (newSubscription!.basic_plan_id ?? form.data.plan_id) : form.data.plan_id))}
                            subscription={newSubscription}
                            currency={currency}
                        />
                        <div className="mt-5">{actions}</div>
                    </>,
                    'business-review',
                )}
            </form>
        );
    }

    /* ═══ التعديل: الأقسامُ كما كانت، مفتوحةً كلُّها ═══ */

    return (
        <form onSubmit={submit} className="space-y-6">
            {section(
                <Building2 className="size-5" />,
                'بيانات الشركة',
                <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                    {nameField}
                    {typeField}
                    {countryField}
                    {cityField}
                    {addressField}
                </div>,
            )}

            {section(
                <User className="size-5" />,
                'بيانات المالك',
                <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                    {ownerNameField}
                    {phoneField}
                    {emailField}
                </div>,
            )}

            {section(<KeyRound className="size-5" />, 'حساب دخول التاجر', accountBody)}

            {section(
                <Layers className="size-5" />,
                'الاشتراك والباقة',
                <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                    {/* والقطاعُ يُختار هنا حين يتعدّد وحده — والباقةُ من قطاعه (`Sectors`) */}
                    {sectors && sectors.length > 1 && (
                        <Field label="القطاع" error={errors.sector_id}>
                            <Select
                                value={form.data.sector_id ?? ''}
                                onChange={(e) => pickSector(e.target.value)}
                                options={sectors.map((s) => ({ label: s.name, value: String(s.id) }))}
                                placeholder="اختر القطاع…"
                                aria-label={t('القطاع')}
                            />
                        </Field>
                    )}
                    {planField}
                    {subscriptionBody}
                </div>,
            )}

            {section(<Crown className="size-5" />, 'الفئة والواجهة الخاصّة', advancedBody)}

            {section(<ImageIcon className="size-5" />, 'شعار الشركة', logoBody)}

            {actions}
        </form>
    );
}

/**
 * قسمٌ يُطوى — لما يُحتاج أحيانًا لا في كلّ إضافة.
 *
 * ويُفتح وحده إن ردّ الخادمُ خطأً في أحد حقوله: خطأٌ في قسمٍ مطويٍّ لا يُرى،
 * فيبدو الحفظُ كأنّه لم يستجب.
 */
function Disclosure({ title, hint, invalid, testId, children }: { title: string; hint: string; invalid: boolean; testId: string; children: React.ReactNode }) {
    const t = useTranslate();
    const [open, setOpen] = useState(false);
    const shown = open || invalid;

    return (
        <div className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)]" data-testid={testId}>
            <button
                type="button"
                aria-expanded={shown}
                onClick={() => setOpen(!shown)}
                className="flex w-full items-center justify-between gap-3 px-4 py-3 text-start"
                data-testid={`${testId}-toggle`}
            >
                <span>
                    <span className="block text-sm font-medium text-[#374151]">{t(title)}</span>
                    <span className="block text-[12px] text-[#9ca3af]">{t(hint)}</span>
                </span>
                <ChevronDown className={cn('size-4 shrink-0 text-[#9ca3af] transition-transform', shown && 'rotate-180')} />
            </button>
            {shown && <div className="border-t border-[var(--ui-border,#e8e8e8)] p-4">{children}</div>}
        </div>
    );
}

/**
 * ملخّصُ ما سيُحفظ — قبل الزرّ الواحد.
 *
 * يُقرأ من النموذج نفسه ولا يحسب ما لا يعرفه: السعرُ سعرُ الباقة في سياسة المتجر
 * الجديد (`NewMerchantSubscription::options`)، والإضافاتُ وإجماليُّها في قسمها أعلاه.
 */
function ReviewSummary({
    values,
    sector,
    plan,
    subscription,
    currency,
}: {
    values: BusinessValues & { login_username: string };
    sector?: string;
    plan?: PlanOption;
    subscription?: NewSubscriptionOptions;
    currency: Currency;
}) {
    const t = useTranslate();
    const mode = values.subscription_mode ?? 'trial';
    const prices = subscription?.plans.find((p) => String(p.id) === String(plan?.value))?.prices ?? null;
    const cycle = subscription?.cycles.find((c) => c.value === values.cycle);
    const price = values.cycle && prices ? prices[values.cycle] : null;

    const modeLabel =
        mode === 'trial'
            ? t('تجربة الأساسية :days يومًا', { days: subscription?.trial_days ?? 14 })
            : mode === 'paid'
              ? t('اشتراك مدفوع')
              : t('منحة إدارية حتى :date', { date: values.grant_until || '—' });

    const rows: [string, string, string][] = [
        ['اسم الشركة', values.name || '—', 'name'],
        ['القطاع ونوع النشاط', [sector ? t(sector) : null, values.type || null].filter(Boolean).join(' · ') || '—', 'sector'],
        ['حساب المالك', values.login_username ? `${values.login_username}${MERCHANT_DOMAIN}` : '—', 'account'],
        ['الباقة', plan ? t(plan.label) : '—', 'plan'],
        ['طريقة الاشتراك', modeLabel, 'mode'],
        [
            'السعر والمدة',
            mode === 'trial'
                ? t(':days يومًا — بلا فاتورة', { days: subscription?.trial_days ?? 14 })
                : cycle
                  ? `${price !== null ? money(price, currency) : t('بلا سعر معتمد')} · ${t(cycle.label)}`
                  : mode === 'grant'
                    ? t('بلا فاتورة حتى نهاية المنحة')
                    : '—',
            'price',
        ],
    ];

    return (
        <dl className="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2" data-testid="business-review-summary">
            {rows.map(([label, value, key]) => (
                <div key={key} className="rounded-[10px] bg-[#fafafa] px-3 py-2.5" data-testid={`review-${key}`}>
                    <dt className="text-[12px] text-[#9ca3af]">{t(label)}</dt>
                    <dd className="mt-0.5 text-sm font-medium text-[#111]" dir="auto">
                        {value}
                    </dd>
                </div>
            ))}
            {(values.addons?.length ?? 0) > 0 && (
                <p className="text-[12px] text-[#6b7280] sm:col-span-2">
                    {t('ومعها :n إضافة — إجماليُّها في قسم الاشتراك أعلاه.', { n: values.addons!.length })}
                </p>
            )}
        </dl>
    );
}
