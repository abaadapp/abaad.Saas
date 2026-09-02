import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Check, Copy, KeyRound, Pencil, RefreshCw } from 'lucide-react';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';
import { PasswordInput } from '@/Components/ui/password-input';
import { MERCHANT_DOMAIN, UsernameInput } from '@/Components/ui/username-input';
import { randomPassword } from '@/lib/password';

interface Props {
    businessId: number;
    /** بريد الحساب القائم — المكوّن لا يُعرض بدونه */
    ownerEmail: string;
    /** بلا إطار البطاقة: صفحة التعديل تضع القسم داخل نموذجها */
    bare?: boolean;
}

/**
 * حساب دخول التاجر — بطاقةٌ واحدة تُستعمل في ملف الشركة وصفحة تعديلها.
 *
 * كانت في ملف الشركة وحده، والمشغّل يصل من زرّ «تعديل» في القائمة — فيقف
 * أمام صفحةٍ لا يجد فيها ما وُعد به ويظنّ أنه لم يُبنَ. ومكوّنٌ واحد يمنع
 * أن تفترق الصفحتان بعد اليوم.
 *
 * والكلمة تُعيَّن ولا تُقرأ: المخزَّن بصمة bcrypt، وهي دالة باتجاه واحد لا
 * تستخرج شيئًا — فالكلمة القديمة ليست محجوبةً بل غير محفوظة. وتخزينها نصًّا
 * لتُعرض يعني أن تسريبًا واحدًا للقاعدة يفتح حسابات التجّار كلّها دفعةً واحدة.
 */
export default function AccountCard({ businessId, ownerEmail, bare = false }: Props) {
    const t = useTranslate();
    const [editing, setEditing] = useState(false);
    const [changing, setChanging] = useState(false);
    /** تُعرض بعد الحفظ لتُنسخ — ثم لا تُقرأ من القاعدة أبدًا */
    const [issued, setIssued] = useState<string | null>(null);
    const [copied, setCopied] = useState<string | null>(null);

    const copy = (text: string) => {
        navigator.clipboard?.writeText(text);
        setCopied(text);
        setTimeout(() => setCopied(null), 1500);
    };

    /*
     * القطع عند @ لا بنزع نطاقنا: حسابات أُنشئت قبل توحيد النطاق تحمل نطاقًا
     * آخر، فنزعُ «@abaadapp.om» منها لا يطابق شيئًا — فيمتلئ الحقل بالبريد
     * كاملًا، ويُرفض الحفظ لأن @ ليست حرفًا مسموحًا في الاسم.
     */
    const nameForm = useForm({ login_username: ownerEmail.split('@')[0] });
    const passwordForm = useForm({ login_password: '' });

    /*
     * الإرسال يُوقف هنا ولا يصعد.
     *
     * النافذة تُعرض في portal، لكنها في شجرة React ابنةٌ لنموذج الشركة في
     * صفحة التعديل — فحدث الإرسال يصعد إليه ويحفظ الشركة كلّها معه: يضغط
     * المشغّل «تغيير كلمة المرور» فتُحفظ الشركة وتُغلق النافذة قبل أن تُنسخ
     * الكلمة، وهي الشيء الوحيد الذي لا يُسترجَع.
     */
    const saveName = (e: React.FormEvent) => {
        e.preventDefault();
        e.stopPropagation();
        nameForm.post(route('super-admin.businesses.account', businessId), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setEditing(false),
        });
    };

    /** تُفتح بكلمةٍ مولَّدة جاهزة: المشغّل يريد كلمةً تعمل لا أن يخترع واحدة */
    const openPassword = () => {
        setIssued(null);
        passwordForm.setData('login_password', randomPassword());
        setChanging(true);
    };

    const savePassword = (e: React.FormEvent) => {
        e.preventDefault();
        e.stopPropagation();
        passwordForm.post(route('super-admin.businesses.account', businessId), {
            preserveScroll: true,
            /*
             * الحالة تبقى بعد الردّ.
             *
             * Inertia يُعيد بناء المكوّن افتراضيًّا بعد POST، فتضيع الكلمة
             * المعروضة قبل أن تُنسخ — وهي الشيء الوحيد الذي لا يُسترجَع.
             */
            preserveState: true,
            // النافذة تبقى مفتوحة والكلمة معروضة: إغلاقها يمحو الشيء الوحيد
            // الذي لا يُسترجَع، فيعود المشغّل ليولّد أخرى ويُقفل التاجر مرّتين
            onSuccess: () => setIssued(passwordForm.data.login_password),
        });
    };

    const body = (
        <>
            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <p className="text-[12px] text-[#9ca3af]">{t('اسم المستخدم')}</p>
                    <div className="mt-1.5 flex items-center gap-2">
                        <span className="min-w-0 flex-1 truncate font-mono text-sm text-[#111]" dir="ltr">
                            {ownerEmail}
                        </span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            onClick={() => copy(ownerEmail)}
                            title={t('نسخ')}
                            aria-label={t('نسخ')}
                        >
                            {copied === ownerEmail ? <Check /> : <Copy />}
                        </Button>
                        <Button type="button" variant="outline" size="sm" onClick={() => setEditing(true)}>
                            <Pencil />
                            {t('تعديل')}
                        </Button>
                    </div>
                </div>

                <div>
                    <p className="text-[12px] text-[#9ca3af]">{t('كلمة المرور')}</p>
                    <div className="mt-1.5 flex items-center gap-2">
                        <span className="min-w-0 flex-1 font-mono text-sm text-[#9ca3af]" dir="ltr">
                            ••••••••
                        </span>
                        <Button type="button" variant="outline" size="sm" onClick={openPassword}>
                            <KeyRound />
                            {t('تغيير كلمة المرور')}
                        </Button>
                    </div>
                    <p className="mt-1.5 text-[12px] text-[#9ca3af]">
                        {t('محفوظة مشفَّرة — تُعيَّن ولا تُعرض')}
                    </p>
                </div>
            </div>

            {/* تعديل اسم المستخدم — منفصل عن كلمة المرور: حرفٌ يسقط سهوًا من
                معرّف الدخول يُقفل الحساب الذي جاء المشغّل ليصلحه */}
            <Dialog open={editing} onOpenChange={setEditing}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('تعديل اسم المستخدم')}</DialogTitle>
                    </DialogHeader>

                    <form onSubmit={saveName} className="space-y-4 px-5 pb-5">
                        <Field label="اسم المستخدم" required error={nameForm.errors.login_username}>
                            <UsernameInput
                                value={nameForm.data.login_username}
                                onChange={(v) => nameForm.setData('login_username', v)}
                                preview={false}
                                required
                            />
                        </Field>

                        <p className="rounded-[10px] bg-[#f7f7f5] px-3 py-2 text-[13px] text-[#6b7280]" dir="ltr">
                            {nameForm.data.login_username}
                            {MERCHANT_DOMAIN}
                        </p>
                        <p className="text-[12px] text-[#9ca3af]">
                            {t('تغيير الاسم يغيّر بريد الدخول نفسه — أبلغ صاحب الشركة بالجديد.')}
                        </p>

                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="outline" onClick={() => setEditing(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" disabled={nameForm.processing}>
                                {t('حفظ')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={changing}
                onOpenChange={(o) => {
                    setChanging(o);
                    if (!o) {
                        setIssued(null);
                        passwordForm.reset();
                    }
                }}
            >
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('تغيير كلمة المرور')}</DialogTitle>
                    </DialogHeader>

                    <form onSubmit={savePassword} className="space-y-4 px-5 pb-5">
                        <p className="text-[13px] text-[#6b7280]" dir="ltr">
                            {ownerEmail}
                        </p>

                        <Field
                            label="كلمة المرور الجديدة"
                            required
                            hint="ثمانية أحرف على الأقل — تُعرض هنا مرّة واحدة، ولا تُقرأ بعدها"
                            error={passwordForm.errors.login_password}
                        >
                            <div className="flex items-stretch gap-2">
                                <PasswordInput
                                    className="w-full"
                                    autoComplete="new-password"
                                    value={passwordForm.data.login_password}
                                    onChange={(e) => passwordForm.setData('login_password', e.target.value)}
                                    required
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => passwordForm.setData('login_password', randomPassword())}
                                    title={t('ولّد كلمة مرور')}
                                    aria-label={t('ولّد كلمة مرور')}
                                >
                                    <RefreshCw />
                                </Button>
                            </div>
                        </Field>

                        {/* بعد الحفظ: تُعرض لتُنسخ — هذه آخر مرّة تُرى فيها */}
                        {issued && (
                            <div className="rounded-[10px] border border-[#bbf7d0] bg-[#f0fdf4] p-3">
                                <p className="text-[12px] text-[#047857]">
                                    {t('تم التغيير — انسخها الآن وأرسلها للتاجر')}
                                </p>
                                <div className="mt-2 flex items-center gap-2">
                                    <span className="min-w-0 flex-1 truncate font-mono text-sm text-[#111]" dir="ltr">
                                        {issued}
                                    </span>
                                    <Button type="button" variant="outline" size="sm" onClick={() => copy(issued)}>
                                        {copied === issued ? <Check /> : <Copy />}
                                        {t(copied === issued ? 'نُسخت' : 'نسخ')}
                                    </Button>
                                </div>
                            </div>
                        )}

                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="outline" onClick={() => setChanging(false)}>
                                {t(issued ? 'إغلاق' : 'إلغاء')}
                            </Button>
                            {!issued && (
                                <Button type="submit" disabled={passwordForm.processing}>
                                    {t('تغيير')}
                                </Button>
                            )}
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );

    if (bare) {
        return body;
    }

    return (
        <Card className="mb-6 p-6">
            <div className="mb-4 flex items-center gap-2">
                <span className="flex size-9 items-center justify-center rounded-[12px] bg-[#f2f2f0] text-[#4b4b4b]">
                    <KeyRound className="size-5" />
                </span>
                <h3 className="font-bold text-[#111]">{t('حساب دخول التاجر')}</h3>
            </div>
            {body}
        </Card>
    );
}
