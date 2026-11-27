import { router, usePage } from '@inertiajs/react';
import { Link2, MessageCircle, X } from 'lucide-react';
import { useState } from 'react';
import CopyButton from '@/Components/CopyButton';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Button } from '@/Components/ui/button';
import { whatsappShareUrl } from '@/Components/PaymentLinks';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

/** حالُ آخر رابطٍ لهذا المتجر — بلا رمزٍ ولا عنوان (انظر App\Support\WhatsAppClientLinks::view) */
export interface ClientLinkView {
    can: boolean;
    /** لماذا لا يُنشأ رابطٌ له الآن */
    why: string | null;
    hours: number;
    link: {
        state: 'open' | 'used' | 'expired' | 'revoked' | 'exhausted';
        label: string;
        created_by: string | null;
        created_at: string | null;
        expires_at: string | null;
        opened_at: string | null;
        attempts: number;
        problem: string | null;
        connected_at: string | null;
        phone: string | null;
    } | null;
}

const TONE: Record<NonNullable<ClientLinkView['link']>['state'], string> = {
    open: 'bg-[#eff6ff] text-[#1d4ed8]',
    used: 'bg-[#ecfdf5] text-[#047857]',
    expired: 'bg-[#f5f5f5] text-[#4b5563]',
    revoked: 'bg-[#f5f5f5] text-[#4b5563]',
    exhausted: 'bg-[#fef2f2] text-[#b91c1c]',
};

/**
 * «أرسل رابطًا لعميلك» — رابطٌ يفتحه صاحبُ المتجر فيربط رقم واتساب بنفسه، بلا دخولٍ إلى أبعاد.
 *
 * والعنوانُ يُعرض مرّةً عند إنشائه (`flash.whatsappClientLink`) ولا يُحفظ في الخادم — فمن أغلق الصفحة
 * قبل نسخه يُنشئ رابطًا جديدًا، والجديدُ يُلغي ما قبله.
 */
export default function ClientLinkBox({ businessId, data, stillShared = false, switchesItself = false }: {
    businessId: number;
    data: ClientLinkView;
    /** رُبط رقمُه ويعمل، والإرسالُ ما زال من رقم أبعاد */
    stillShared?: boolean;
    /** ويُبدَّل وحده حين تكتمل مراحل التحقّق (`WhatsAppSender`) */
    switchesItself?: boolean;
}) {
    const { flash, errors } = usePage<PageProps>().props;
    const t = useTranslate();
    const [busy, setBusy] = useState(false);
    const [ask, dialog] = useConfirm();

    const made = flash.whatsappClientLink ?? null;
    const link = data.link;
    const refused = ((errors ?? {}) as Record<string, string>).client_link ?? null;

    const create = async () => {
        if (link?.state === 'open') {
            const yes = await ask({
                title: 'إنشاء رابط جديد',
                message: 'الرابط المرسل قبله يتوقّف. متابعة؟',
                action: 'إنشاء رابط جديد',
            });

            if (! yes) return;
        }

        router.post(route('super-admin.businesses.whatsapp.clientLink.store', businessId), {}, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
        });
    };

    const revoke = async () => {
        const yes = await ask({
            title: 'إلغاء الرابط',
            message: 'لن يستطيع صاحب المتجر الربط من هذا الرابط. متابعة؟',
            danger: true,
            action: 'إلغاء الرابط',
        });

        if (yes) router.delete(route('super-admin.businesses.whatsapp.clientLink.destroy', businessId), { preserveScroll: true });
    };

    return (
        <div className="mb-5 rounded-[12px] border border-[#f0f0f0] p-4 text-[13px]" data-testid="client-link-box">
            <p className="flex items-center gap-2 font-semibold text-[#111]">
                <Link2 className="size-4" />
                {t('أرسل رابطًا لعميلك')}
            </p>
            <p className="mt-1 text-[12px] leading-relaxed text-[#6b7280]">
                {t('يفتحه صاحب المتجر فيربط رقم واتساب بنفسه، بلا دخول إلى أبعاد ولا كلمة مرور. يعمل مرّة واحدة لمدّة :h ساعة.', { h: String(data.hours) })}
            </p>

            {link && (
                <div className="mt-3 space-y-1 rounded-[10px] bg-[#fafafa] p-3 text-[12px] text-[#6b7280]" data-testid="client-link-last" data-state={link.state}>
                    <p>
                        <span className={`me-2 inline-block rounded-full px-2 py-0.5 text-[11px] font-medium ${TONE[link.state]}`}>{t(link.label)}</span>
                        {t('أنشأه')} {link.created_by ?? '—'} · <span dir="ltr">{link.created_at}</span>
                    </p>
                    {link.state === 'open' && (
                        <p>
                            {t('ينتهي في')} <span dir="ltr">{link.expires_at}</span> · {link.opened_at ? <>{t('فُتح في')} <span dir="ltr">{link.opened_at}</span></> : t('لم يُفتح بعد')}
                        </p>
                    )}
                    {link.state === 'used' && (
                        <p className="text-[#047857]">
                            <span dir="ltr">{link.phone}</span> · <span dir="ltr">{link.connected_at}</span>
                        </p>
                    )}
                    {link.state === 'used' && stillShared && (
                        <p className="text-[#b45309]" data-testid="client-link-still-shared">
                            {switchesItself
                                ? t('رسائل المتجر ما زالت تخرج من رقم أبعاد — تُبدَّل إلى رقمه تلقائيًّا حين تكتمل مراحل التحقّق.')
                                : t('رسائل المتجر ما زالت تخرج من رقم أبعاد — اختر «API مستقل للمتجر» أدناه واحفظ لتخرج من رقمه.')}
                        </p>
                    )}
                    {link.problem && link.state !== 'used' && (
                        <p className="text-[#b45309]" data-testid="client-link-last-problem">{t('آخر محاولة')}: {link.problem}</p>
                    )}
                </div>
            )}

            {made && (
                <div className="mt-3 space-y-2 rounded-[10px] border border-[#111] p-3" data-testid="client-link-made">
                    <p className="text-[12px] font-medium text-[#111]">{t('انسخه الآن — لا يظهر مرّة أخرى.')}</p>
                    <p className="break-all rounded-[8px] bg-[#fafafa] px-2 py-1.5 text-[12px] text-[#111]" dir="ltr" data-testid="client-link-url">{made.url}</p>
                    <div className="flex flex-wrap gap-2">
                        <CopyButton text={made.url} label="نسخ الرابط" done="نُسخ الرابط" />
                        <CopyButton text={made.message} label="نسخ الرسالة" done="نُسخت الرسالة" />
                        <Button asChild variant="outline" size="sm">
                            <a href={whatsappShareUrl(made.message)} target="_blank" rel="noopener noreferrer" data-testid="client-link-share">
                                <MessageCircle />
                                {t('إرسال عبر واتساب')}
                            </a>
                        </Button>
                    </div>
                </div>
            )}

            {! data.can && data.why && <p className="mt-3 text-[12px] leading-relaxed text-[#92400e]" data-testid="client-link-why">{data.why}</p>}
            {refused && <p role="alert" className="mt-3 text-[12px] text-[#b91c1c]" data-testid="client-link-refused">{refused}</p>}

            <div className="mt-3 flex flex-wrap gap-2">
                <Button type="button" variant="outline" size="sm" onClick={create} loading={busy} disabled={! data.can} data-testid="client-link-create">
                    <Link2 />
                    {t(link?.state === 'open' ? 'إنشاء رابط جديد' : 'إنشاء رابط')}
                </Button>
                {link?.state === 'open' && (
                    <Button type="button" variant="ghost" size="sm" onClick={revoke} data-testid="client-link-revoke">
                        <X />
                        {t('إلغاء الرابط')}
                    </Button>
                )}
            </div>
            {dialog}
        </div>
    );
}
