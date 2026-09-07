import { useForm } from '@inertiajs/react';
import { Check, CircleAlert } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { useTranslate } from '@/lib/i18n';

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

    return (
        <AdminLayout title={t('تهيئة المتجر')}>
            <PageHeader
                title={t('تهيئة المتجر')}
                subtitle={t('اسمُ متجرك يُطبع في رأس كلّ فاتورة وإيصال وتقرير — اكتبه مرّةً هنا.')}
            />

            <Card className="mb-4 p-4">
                <div className="flex flex-wrap gap-3">
                    {steps.map((s) => (
                        <div
                            key={s.key}
                            className={
                                'flex items-center gap-1.5 rounded-full px-3 py-1 text-[12px] ' +
                                (s.done
                                    ? 'bg-[#ecfdf5] text-[#047857]'
                                    : s.required
                                      ? 'bg-[#fef2f2] text-[#b91c1c]'
                                      : 'bg-[#f4f4f5] text-[#71717a]')
                            }
                        >
                            {s.done ? <Check className="size-3.5" /> : <CircleAlert className="size-3.5" />}
                            {s.label}
                            {s.required && !s.done && <span>{t('— مطلوب')}</span>}
                        </div>
                    ))}
                </div>
            </Card>

            <form onSubmit={save}>
                <Card className="mb-4 space-y-4 p-4">
                    <div>
                        <Label htmlFor="shop_name">{t('اسم المتجر')}</Label>
                        <Input
                            id="shop_name"
                            value={form.data.shop_name}
                            onChange={(e) => form.setData('shop_name', e.target.value)}
                            placeholder={t('مثال: زهور مسقط')}
                        />
                        {/*
                         * ولا يُقال «مطلوب» فحسب: من كتب «متجري» يظنّه اسمًا،
                         * فيُقال له إنّه اسمُ النظام حين لا اسم.
                         */}
                        {nameIsPlaceholder && (
                            <p className="mt-1 text-[12px] text-[#b91c1c]">
                                {t('هذا ما يكتبه النظام حين لا اسم للمتجر — اكتب اسمك التجاري كما تريده على الفاتورة.')}
                            </p>
                        )}
                        {form.errors.shop_name && (
                            <p className="mt-1 text-[12px] text-[#b91c1c]">{form.errors.shop_name}</p>
                        )}
                    </div>

                    <div>
                        <Label htmlFor="phone">{t('رقم الهاتف')}</Label>
                        <Input
                            id="phone"
                            value={form.data.phone}
                            onChange={(e) => form.setData('phone', e.target.value)}
                        />
                    </div>

                    <div>
                        <Label htmlFor="address">{t('العنوان')}</Label>
                        <Input
                            id="address"
                            value={form.data.address}
                            onChange={(e) => form.setData('address', e.target.value)}
                        />
                    </div>

                    <div>
                        <Label htmlFor="vat_number">{t('الرقم الضريبي')}</Label>
                        <Input
                            id="vat_number"
                            value={form.data.vat_number}
                            onChange={(e) => form.setData('vat_number', e.target.value)}
                            disabled={!form.data.vat_enabled}
                        />
                        {/*
                         * وسؤالٌ بلا جوابٍ صحيحٍ لغير المسجَّل يبقى معلّقًا أبدًا
                         * فيُقرأ عيبًا في النظام — فله جوابُه هنا.
                         */}
                        <label className="mt-2 flex items-center gap-2 text-[13px]">
                            <input
                                type="checkbox"
                                checked={!form.data.vat_enabled}
                                onChange={(e) => form.setData('vat_enabled', !e.target.checked)}
                            />
                            {t('متجري غير مسجّل في ضريبة القيمة المضافة')}
                        </label>
                    </div>

                    <Button type="submit" disabled={form.processing}>
                        {t('حفظ')}
                    </Button>
                </Card>
            </form>

            <Card className="p-4">
                <p className="mb-3 text-[13px] text-[#52525b]">
                    {t('بعد الحفظ، أقرّ هويّة متجرك — عندها تحمل أوراقُك اسمَه ويختفي التنبيه.')}
                </p>
                {confirmForm.errors.name && (
                    <p className="mb-2 text-[12px] text-[#b91c1c]">{confirmForm.errors.name}</p>
                )}
                <Button
                    onClick={() => confirmForm.post('/admin/setup/confirm', { preserveScroll: true })}
                    disabled={confirmForm.processing || nameIsPlaceholder || confirmed}
                >
                    {confirmed ? t('الهويّة مُقرَّة') : t('أقرّ هويّة المتجر')}
                </Button>
            </Card>
        </AdminLayout>
    );
}
