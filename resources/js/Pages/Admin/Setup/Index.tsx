import { useForm } from '@inertiajs/react';
import { BadgeCheck, Check, CircleAlert, Minus, Store } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Field from '@/Components/Field';
import StatusPill from '@/Components/StatusPill';
import { PageActions, SettingsPage, SettingsSection, SetupProgress } from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

interface Step {
    key: string;
    label: string;
    done: boolean;
    required: boolean;
}

interface Props {
    shop: {
        name: string;
        phone: string;
        address: string;
        logo: string;
        vat_number: string;
        vat_enabled: boolean;
    };
    steps: Step[];
    confirmed: boolean;
    placeholders: string[];
}

/**
 * تهيئةُ المتجر — شاشةٌ تكتب بنفسها لا تُحيل.
 *
 * الإحالةُ إلى «الإعدادات ‹ بيانات النشاط» تعني أربعَ نقراتٍ ورجوعًا، ومن
 * رجع مرّةً لا يعود. فالحقولُ هنا، وتُحفظ بالباب القائم للإعدادات لا بباب
 * ثانٍ يكتب المفاتيح نفسها.
 *
 * ═══ قائمةٌ لا معالج ═══
 *
 * الحقول الأربعة لا يشترط أحدُها الآخر: يكتب العنوان قبل الهاتف أو بعده،
 * ولا فرق. فقائمةُ تحقّقٍ تُقرأ لا معالجٌ يُساق فيه — وخطوةٌ تُفرض على من
 * يستطيع تخطّيها مقبضٌ لا يُدير شيئًا.
 *
 * وما هو مرتَّبٌ حقًّا واحد: **الإقرار بعد الاسم**. اسمُ النظام لا يُقرّ،
 * فيبقى زرُّه معطَّلًا ومكتوبٌ تحته لماذا — لا يُترك مطفأً بلا سبب.
 */
export default function SetupIndex({ shop, steps, confirmed, placeholders }: Props) {
    const t = useTranslate();

    const form = useForm({
        shop_name: shop.name,
        phone: shop.phone,
        address: shop.address,
        vat_number: shop.vat_number,
        vat_enabled: shop.vat_enabled,
    });

    const confirmForm = useForm<{ name?: string }>({});

    // اسمٌ يكتبه النظام ليس اسمًا: يُقال ذلك قبل الإرسال لا بعده
    const typed = form.data.shop_name.trim();
    const nameIsPlaceholder = typed === '' || placeholders.includes(typed);

    const save = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/admin/settings', { preserveScroll: true });
    };

    const at = steps.filter((s) => s.done).length;
    const missing = steps.filter((s) => s.required && ! s.done).length;

    return (
        <AdminLayout title={t('تهيئة المتجر')}>
            <PageHeader
                title={t('تهيئة المتجر')}
                subtitle={t('اسمُ متجرك يُطبع في رأس كلّ فاتورة وإيصال وتقرير — اكتبه مرّةً هنا.')}
                actions={
                    confirmed
                        ? <StatusPill state="ready" label="الهويّة مُقرَّة" />
                        : <StatusPill state={missing > 0 ? 'action' : 'progress'} />
                }
            />

            <SettingsPage>
                {/*
                    ما تمّ وما بقي — قائمةً تُقرأ في نظرة.

                    وكانت شرائحَ متراصّةً في سطرٍ واحد: خضراءُ وحمراءُ ورماديّة
                    بلا ترتيبٍ ولا عدّ، فيقرؤها صاحبُها زينةً ويمضي. والسؤالُ
                    الذي جاء له — «كم بقي؟» — لا جواب له فيها.
                */}
                <SettingsSection
                    title="ما ينقص قبل أن تخرج أوراقك باسمك"
                    description="اكتب ما ينقص أدناه بأيّ ترتيب — ولا شيء هنا يشترط ما قبله."
                    icon={Store}
                    status={
                        <span className="text-[13px] font-medium tabular-nums text-[#6b7280]" dir="ltr">
                            {at} / {steps.length}
                        </span>
                    }
                >
                    <SetupProgress
                        at={at}
                        total={steps.length}
                        done={at === steps.length}
                        label={t('بنودٌ مكتملة')}
                    />

                    <ul className="mt-5 divide-y divide-[var(--ui-border,#e8e8e8)]">
                        {steps.map((s) => (
                            <li key={s.key} className="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                                <span className="shrink-0">
                                    {s.done ? (
                                        <Check className="size-4 text-[#047857]" />
                                    ) : s.required ? (
                                        <CircleAlert className="size-4 text-[#b91c1c]" />
                                    ) : (
                                        /*
                                            والاختياريُّ الناقص ليس نقصًا: شرطةٌ
                                            رماديّة لا علامةُ خطأٍ حمراء — وإلّا
                                            بقي صاحبُه يطارد بندًا لا يلزمه.
                                        */
                                        <Minus className="size-4 text-[#9ca3af]" />
                                    )}
                                </span>
                                <span
                                    className={cn(
                                        'min-w-0 flex-1 text-[13px]',
                                        s.done ? 'text-[#6b7280]' : 'font-medium text-[#111]',
                                    )}
                                >
                                    {s.label}
                                </span>
                                {s.required && ! s.done && (
                                    <span className="shrink-0 text-[12px] font-medium text-[#b91c1c]">
                                        {t('مطلوب')}
                                    </span>
                                )}
                                {! s.required && ! s.done && (
                                    <span className="shrink-0 text-[12px] text-[#9ca3af]">{t('اختياريّ')}</span>
                                )}
                            </li>
                        ))}
                    </ul>
                </SettingsSection>

                <form onSubmit={save}>
                    <SettingsSection
                        title="بيانات متجرك"
                        description="هذه الأربعة هي ما يُطبع في رأس الورقة — ولا شيء سواها هنا."
                    >
                        <div className="space-y-4">
                            <Field
                                label="اسم المتجر"
                                required
                                htmlFor="shop_name"
                                /*
                                 * ولا يُقال «مطلوب» فحسب: من كتب «متجري» يظنّه اسمًا،
                                 * فيُقال له إنّه اسمُ النظام حين لا اسم.
                                 */
                                error={
                                    form.errors.shop_name ??
                                    (nameIsPlaceholder
                                        ? t('هذا ما يكتبه النظام حين لا اسم للمتجر — اكتب اسمك التجاري كما تريده على الفاتورة.')
                                        : undefined)
                                }
                            >
                                <Input
                                    id="shop_name"
                                    value={form.data.shop_name}
                                    onChange={(e) => form.setData('shop_name', e.target.value)}
                                    placeholder={t('مثال: زهور مسقط')}
                                />
                            </Field>

                            <Field label="رقم الهاتف" htmlFor="phone" error={form.errors.phone}>
                                <Input
                                    id="phone"
                                    dir="ltr"
                                    value={form.data.phone}
                                    onChange={(e) => form.setData('phone', e.target.value)}
                                />
                            </Field>

                            <Field label="العنوان" htmlFor="address" error={form.errors.address}>
                                <Input
                                    id="address"
                                    value={form.data.address}
                                    onChange={(e) => form.setData('address', e.target.value)}
                                />
                            </Field>

                            <Field label="الرقم الضريبي" htmlFor="vat_number" error={form.errors.vat_number}>
                                <Input
                                    id="vat_number"
                                    dir="ltr"
                                    value={form.data.vat_number}
                                    onChange={(e) => form.setData('vat_number', e.target.value)}
                                    disabled={!form.data.vat_enabled}
                                />
                            </Field>

                            {/*
                             * وسؤالٌ بلا جوابٍ صحيحٍ لغير المسجَّل يبقى معلّقًا أبدًا
                             * فيُقرأ عيبًا في النظام — فله جوابُه هنا.
                             */}
                            <label className="flex cursor-pointer items-center gap-2 text-[13px] text-[#4b4b4b]">
                                <input
                                    type="checkbox"
                                    className="size-4 accent-[#111]"
                                    checked={!form.data.vat_enabled}
                                    onChange={(e) => form.setData('vat_enabled', !e.target.checked)}
                                />
                                {t('متجري غير مسجّل في ضريبة القيمة المضافة')}
                            </label>
                        </div>

                        <PageActions className="mt-6">
                            <Button type="submit" loading={form.processing}>
                                {t('حفظ')}
                            </Button>
                        </PageActions>
                    </SettingsSection>
                </form>

                {/*
                    الإقرارُ آخرُ الصفحة لأنّه آخرُ الفعل — وهو الشيء الوحيد
                    هنا الذي يشترط ما قبله: اسمٌ يكتبه النظام لا يُقرّ.
                */}
                <SettingsSection
                    title="إقرار هويّة المتجر"
                    description="بعد الحفظ، أقرّ هويّة متجرك — عندها تحمل أوراقُك اسمَه ويختفي التنبيه."
                    icon={BadgeCheck}
                    status={confirmed ? <StatusPill state="ready" label="مُقرَّة" /> : undefined}
                >
                    <PageActions
                        note={
                            confirmForm.errors.name
                                ? <span className="text-[#b91c1c]">{confirmForm.errors.name}</span>
                                : confirmed
                                  ? t('أقرَرتَ هويّة متجرك — ولا شيء بقي هنا.')
                                  : nameIsPlaceholder
                                    ? t('اكتب اسم متجرك واحفظه أوّلًا — لا يُقرّ اسمٌ كتبه النظام.')
                                    : t('بالإقرار يُعتمد هذا الاسم على فواتيرك وإيصالاتك وتقاريرك.')
                        }
                    >
                        <Button
                            onClick={() => confirmForm.post('/admin/setup/confirm', { preserveScroll: true })}
                            loading={confirmForm.processing}
                            disabled={confirmForm.processing || nameIsPlaceholder || confirmed}
                        >
                            {confirmed ? t('الهويّة مُقرَّة') : t('أقرّ هويّة المتجر')}
                        </Button>
                    </PageActions>
                </SettingsSection>
            </SettingsPage>
        </AdminLayout>
    );
}
