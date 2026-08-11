import { Head, usePage } from '@inertiajs/react';
import { CalendarX, Mail, Phone, ShieldCheck } from 'lucide-react';
import Logo from '@/Components/Logo';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { logout } from '@/lib/logout';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Props {
    business: string | null;
    endedAt: string | null;
    daysSince: number | null;
    plan: string | null;
    amount: number | null;
    contact: { company: string | null; email: string | null; phone: string | null };
}

/**
 * ما يراه متجرٌ انتهى اشتراكه — بدل رسالةٍ حمراء في حقل البريد.
 *
 * الرسالة عند الباب كانت تجعله يعيد كتابة كلمة المرور ظنًّا أنه أخطأها، ثم
 * يتّصل ليسأل «لماذا لا أدخل؟» قبل أن يسأل «كيف أجدّد؟». وهذه الصفحة تجيب
 * الثاني مباشرةً، وتطمئنه على بياناته — فأوّل ما يخطر لمن أُقفل عليه أنها
 * ضاعت.
 */
export default function SubscriptionExpired() {
    const { business, endedAt, daysSince, plan, amount, contact, csrf } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();

    return (
        <div className="flex min-h-dvh flex-col items-center justify-center bg-[#f7f8f9] px-4 py-10">
            <Head title="انتهى الاشتراك" />

            <Logo className="mb-8 h-6 w-auto text-[#111]" />

            <Card className="w-full max-w-md p-7">
                <span className="mb-4 flex size-12 items-center justify-center rounded-[14px] bg-[#fef2f2] text-[#b91c1c]">
                    <CalendarX className="size-6" />
                </span>

                <h1 className="text-[20px] font-bold text-[#111]">{t('انتهى اشتراك المتجر')}</h1>
                <p className="mt-2 text-[14px] leading-relaxed text-[#4b4b4b]">
                    {t('لا يمكن استخدام النظام حتى يُجدَّد الاشتراك.')}
                </p>

                <dl className="mt-6 space-y-2 rounded-[12px] bg-[#fafafa] p-4 text-[13px]">
                    {business && (
                        <div className="flex justify-between gap-3">
                            <dt className="text-[#6b7280]">{t('المتجر')}</dt>
                            <dd className="font-medium text-[#111]">{business}</dd>
                        </div>
                    )}
                    {endedAt && (
                        <div className="flex justify-between gap-3">
                            <dt className="text-[#6b7280]">{t('انتهى في')}</dt>
                            <dd className="font-medium text-[#111]" dir="ltr">
                                {endedAt}
                                {daysSince !== null && daysSince > 0 && (
                                    <span className="text-[#9ca3af]"> ({t('قبل :n يوم', { n: daysSince })})</span>
                                )}
                            </dd>
                        </div>
                    )}
                    {plan && (
                        <div className="flex justify-between gap-3">
                            <dt className="text-[#6b7280]">{t('الباقة')}</dt>
                            <dd className="font-medium text-[#111]">
                                {plan}
                                {amount !== null && amount > 0 && (
                                    <span className="text-[#6b7280]">
                                        {' — '}
                                        {amount.toFixed(3)} {t('ر.ع')} / {t('شهريًا')}
                                    </span>
                                )}
                            </dd>
                        </div>
                    )}
                </dl>

                {/* بياناته أوّل ما يخطر له — تُقال قبل أن يسأل */}
                <p className="mt-4 flex items-start gap-2 rounded-[10px] bg-[#f0fdf4] p-3 text-[13px] text-[#166534]">
                    <ShieldCheck className="mt-0.5 size-4 shrink-0" />
                    {t('بياناتك محفوظة كما هي — المنتجات والطلبات والعملاء والتقارير. لا يُحذف شيء.')}
                </p>

                {(contact.phone || contact.email) && (
                    <div className="mt-6">
                        <p className="mb-2 text-[13px] font-medium text-[#111]">
                            {t('للتجديد تواصل مع :company', { company: contact.company || 'أبعاد' })}
                        </p>
                        <div className="flex flex-col gap-2">
                            {/* روابط لا نصّ: على الجوال ضغطةٌ واحدة تتّصل */}
                            {contact.phone && (
                                <a
                                    href={`tel:${contact.phone.replace(/\s/g, '')}`}
                                    className="flex items-center gap-2 rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2.5 text-[14px] font-medium text-[#111] transition-colors hover:bg-[#fafafa]"
                                >
                                    <Phone className="size-4 text-[#6b7280]" />
                                    <span dir="ltr">{contact.phone}</span>
                                </a>
                            )}
                            {contact.email && (
                                <a
                                    href={`mailto:${contact.email}`}
                                    className="flex items-center gap-2 rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2.5 text-[14px] font-medium text-[#111] transition-colors hover:bg-[#fafafa]"
                                >
                                    <Mail className="size-4 text-[#6b7280]" />
                                    <span dir="ltr">{contact.email}</span>
                                </a>
                            )}
                        </div>
                    </div>
                )}

                <Button
                    variant="ghost"
                    className="mt-6 w-full"
                    onClick={() => logout(route('logout'), csrf)}
                >
                    {t('تسجيل الخروج')}
                </Button>
            </Card>
        </div>
    );
}
