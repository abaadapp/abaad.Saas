import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Check, ChevronLeft, Copy, ExternalLink, Link2, Printer, QrCode, RefreshCw, UserRoundCheck } from 'lucide-react';
import { toast } from 'sonner';
import CopyButton from '@/Components/CopyButton';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useCopy } from '@/lib/copy';
import { useTranslate } from '@/lib/i18n';
import { askPortal, linkOf, type PortalLinkState, type SharedLink } from '@/lib/ribbon-portal';
import { cn } from '@/lib/utils';

/**
 * بوابةُ عملاء RIBBON عند الموظّف — تخصيصٌ لمتجر سعود وحده.
 *
 * لا شيءَ هنا يُرسم بلا ما يرسله الخادم (`RibbonPortal\Portal`): متجرٌ آخر لا
 * يصله مفتاحُها، وموظّفٌ لا يشارك لا يصله كذلك. والرابطُ الشخصيُّ لا يأتي مع
 * الصفحة: يُطلب حين يُضغط الزرّ. ولا رسالةَ من هنا ولا قناةَ مراسلة: يُنسخ
 * الرابطُ أو يُمسح رمزُه من الشاشة.
 */
export interface PortalPanel {
    url: string;
    qr: string;
    poster: string;
    name: string;
}

/** الرابطُ العامّ ورمزُه — لا يكشف شيئًا وحده */
export function PortalLinkBox({ portal, stacked = false }: { portal: PortalPanel; stacked?: boolean }) {
    const t = useTranslate();

    return (
        <div className="space-y-4" data-testid="portal-public-box">
            <p className="text-sm text-[#6b7280]">{t('الرابط العام لا يكشف شيئًا وحده: يدخل العميل برابطه الشخصي ورقم هاتفه.')}</p>
            <div className="flex flex-col items-center gap-3 rounded-2xl border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] p-5">
                <img src={portal.qr} alt={t('رمز QR لبوابة العملاء')} className="size-44 rounded-xl bg-white p-3" />
                <span dir="ltr" className="break-all text-center text-sm font-semibold text-[#111]">{portal.url}</span>
            </div>
            <div className={cn('grid grid-cols-1 gap-2', !stacked && 'sm:grid-cols-3')}>
                <CopyButton text={portal.url} label={t('نسخ الرابط')} size="md" className="w-full" />
                <Button variant="outline" asChild>
                    <a href={portal.url} target="_blank" rel="noreferrer"><ExternalLink />{t('فتح البوابة')}</a>
                </Button>
                <Button variant="outline" asChild>
                    <a href={portal.poster} target="_blank" rel="noreferrer"><Printer />{t('طباعة الرمز')}</a>
                </Button>
            </div>
        </div>
    );
}

/** أعلى قائمة العملاء بجانب «إضافة عميل» */
export function PortalButton({ portal }: { portal: PortalPanel }) {
    const t = useTranslate();
    const [open, setOpen] = useState(false);

    return (
        <>
            <Button variant="outline" onClick={() => setOpen(true)} data-testid="portal-button">
                <QrCode />
                {t('بوابة العملاء')}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader><DialogTitle>{t('بوابة العملاء')}</DialogTitle></DialogHeader>
                    <div className="p-5 pt-2"><PortalLinkBox portal={portal} /></div>
                </DialogContent>
            </Dialog>
        </>
    );
}

/** ملفُّ العميل — قسمُ «رابط بوابة العميل» */
export function CustomerPortalCard({ state }: { state: PortalLinkState }) {
    const t = useTranslate();
    const [ask, confirmDialog] = useConfirm();
    const { copyLater, copied } = useCopy();
    const [busy, setBusy] = useState<'make' | 'copy' | 'qr' | 'reissue' | 'revoke' | null>(null);
    const [now, setNow] = useState(state);
    const [link, setLink] = useState<SharedLink | null>(null);
    const [showing, setShowing] = useState(false);
    const failed = t('تعذّر ذلك الآن. تحقّق من الاتصال وحاول من جديد.');

    /** الرابطُ من الخادم في كلّ مرّة: قد أعاد غيرُك إصدارَه */
    const fetchLink = async (what: 'make' | 'copy' | 'qr' | 'reissue', url: string): Promise<SharedLink | null> => {
        setBusy(what);
        const asked = askPortal<SharedLink>(url, 'POST', failed);
        if (what === 'copy') await copyLater(linkOf(asked));
        const { data, error } = await asked;
        setBusy(null);
        if (error || !data) {
            toast.error(error ?? failed);

            return null;
        }
        setLink(data);
        setNow(data.state);

        return data;
    };
    const showQr = async () => {
        if (await fetchLink('qr', now.urls.share)) setShowing(true);
    };
    const reissue = async () => {
        if (! now.urls.reissue || ! await ask({ message: 'إعادة إصدار رابط هذا العميل؟ يتوقف رابطه الحالي ويخرج من أجهزته.', action: 'إعادة إصدار الرابط' })) return;
        if (await fetchLink('reissue', now.urls.reissue)) toast.success(t('صدر رابط جديد، والقديم لم يعد يعمل'));
    };
    const revoke = async () => {
        if (! now.urls.revoke || ! await ask({ message: 'إلغاء رابط هذا العميل؟ يخرج من أجهزته ولا يعمل الرابط بعدها.', danger: true, action: 'إلغاء الرابط' })) return;
        setBusy('revoke');
        const { data, error } = await askPortal<{ state: PortalLinkState }>(now.urls.revoke, 'DELETE', failed);
        setBusy(null);
        if (error || !data) return void toast.error(error ?? failed);
        setLink(null);
        setNow(data.state);
        toast.success(t('أُلغي الرابط وخرج صاحبه من أجهزته'));
    };

    return (
        <Card className="p-6" data-testid="portal-customer">
            {confirmDialog}
            <div className="mb-1 flex items-center justify-between gap-3">
                <h3 className="text-sm font-bold text-[#111]">{t('رابط بوابة العميل')}</h3>
                <Badge variant={now.issued ? 'success' : 'neutral'} data-testid="portal-customer-state">{now.issued ? t('فعّال') : t('لم يصدر بعد')}</Badge>
            </div>
            <p className="mb-4 text-[12px] text-[#9ca3af]">{t('يدخل العميل بوابته برابطه الشخصي ورقم هاتفه المسجّل.')}</p>

            {!now.hasPhone ? (
                <p className="rounded-xl bg-[#fffbeb] px-4 py-3 text-sm text-[#92400e]">{t('أضف رقم هاتف العميل أولًا — يدخل به إلى بوابته.')}</p>
            ) : !now.issued ? (
                <Button className="w-full" loading={busy === 'make'} onClick={() => void fetchLink('make', now.urls.share)} data-testid="portal-make">
                    <Link2 />
                    {t('إنشاء الرابط الشخصي')}
                </Button>
            ) : (
                <div className="space-y-3">
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                        <Button variant="outline" loading={busy === 'copy'} disabled={busy !== null} onClick={() => void fetchLink('copy', now.urls.share)} data-testid="portal-copy">
                            {copied ? <Check className="text-[#047857]" /> : <Copy />}
                            {copied ? t('نُسخ') : t('نسخ الرابط')}
                        </Button>
                        <Button variant="outline" loading={busy === 'qr'} disabled={busy !== null} onClick={() => void showQr()} data-testid="portal-qr">
                            <QrCode />
                            {t('عرض رمز QR')}
                        </Button>
                    </div>
                    {link && (
                        <p dir="ltr" className="select-all break-all rounded-xl border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] p-3 text-start text-[12px] leading-relaxed text-[#374151]" data-testid="portal-link">{link.url}</p>
                    )}
                    <dl className="space-y-1.5 text-[12px]">
                        <div className="flex justify-between gap-3"><dt className="text-[#9ca3af]">{t('صدر في')}</dt><dd dir="ltr" className="text-[#111]">{now.issued}</dd></div>
                        <div className="flex justify-between gap-3"><dt className="text-[#9ca3af]">{t('آخر دخول به')}</dt><dd dir="ltr" className="text-[#111]">{now.used ?? '—'}</dd></div>
                    </dl>
                    {(now.urls.reissue || now.urls.revoke) && (
                        <div className="flex flex-wrap gap-2 border-t border-[var(--ui-border,#e8e8e8)] pt-3">
                            {now.urls.reissue && <Button variant="outline" size="sm" loading={busy === 'reissue'} disabled={busy !== null} onClick={() => void reissue()} data-testid="portal-reissue"><RefreshCw />{t('إعادة إصدار الرابط')}</Button>}
                            {now.urls.revoke && <Button variant="ghost" size="sm" className="text-[#b91c1c]" loading={busy === 'revoke'} disabled={busy !== null} onClick={() => void revoke()} data-testid="portal-revoke">{t('إلغاء الرابط')}</Button>}
                        </div>
                    )}
                </div>
            )}

            <Dialog open={showing && link !== null} onOpenChange={setShowing}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader><DialogTitle>{t('رمز بوابة العميل')}</DialogTitle></DialogHeader>
                    <div className="flex flex-col items-center gap-3 p-5 pt-2 text-center" data-testid="portal-customer-qr">
                        {link && <img src={link.qr} alt={t('رمز QR لرابط العميل')} className="size-64 rounded-xl border border-[var(--ui-border,#e8e8e8)] bg-white p-6" />}
                        <p className="text-sm text-[#374151]">{t('يمسحه العميل بهاتفه، ثم يكتب رقم هاتفه المسجّل.')}</p>
                        <p className="text-[12px] text-[#9ca3af]">{t('رمز خاص بهذا العميل وحده، فلا يُعرض لغيره.')}</p>
                    </div>
                </DialogContent>
            </Dialog>
        </Card>
    );
}

/** بطاقةُ «بوابة العملاء» في لوحة الإعدادات */
export function PortalSettingsCard({ href }: { href: string }) {
    const t = useTranslate();

    return (
        <Link
            href={href}
            data-testid="portal-settings-card"
            className="group flex items-center gap-4 rounded-[16px] border border-[var(--ui-border,#e8e8e8)] bg-white p-5 text-start transition hover:border-[#d4d4d4] hover:bg-[#fafafa]"
        >
            <span className="flex size-[52px] shrink-0 items-center justify-center rounded-[14px] bg-[#f5f5f4] text-[#111] transition-colors group-hover:bg-[#111] group-hover:text-white">
                <UserRoundCheck className="size-6" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="block text-[15px] font-semibold text-[#111]">{t('بوابة العملاء')}</span>
                <span className="mt-1 block text-[13px] leading-snug text-[#9ca3af]">{t('رابط البوابة ومن يشاركه وروابط العملاء')}</span>
            </span>
            <ChevronLeft className="size-4 shrink-0 text-[#d1d5db] transition-colors group-hover:text-[#6b7280] ltr:rotate-180" />
        </Link>
    );
}

