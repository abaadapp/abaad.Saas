import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { ChevronLeft, ExternalLink, Link2, MessageCircle, Printer, QrCode, RefreshCw, Share2, UserRoundCheck } from 'lucide-react';
import { toast } from 'sonner';
import CopyButton from '@/Components/CopyButton';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';
import { askPortal, type PortalLinkState, type SharedLink } from '@/lib/ribbon-portal';
import { cn } from '@/lib/utils';

/**
 * بوابةُ عملاء RIBBON عند الموظّف — تخصيصٌ لمتجر سعود وحده.
 *
 * لا شيءَ هنا يُرسم بلا ما يرسله الخادم (`RibbonPortal\Portal`): متجرٌ آخر لا
 * يصله مفتاحُها، وموظّفٌ لا يشارك لا يصله كذلك. والرابطُ الشخصيُّ لا يأتي مع
 * الصفحة: يُطلب حين يُضغط الزرّ. ولا تُرسَل رسالة — يُفتح واتساب بنصّها
 * ويضغط الموظّفُ «إرسال» بيده.
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

/** أعلى قائمة العملاء */
export function PortalButton({ portal }: { portal: PortalPanel }) {
    const t = useTranslate();
    const [open, setOpen] = useState(false);

    return (
        <>
            <Button variant="outline" onClick={() => setOpen(true)} data-testid="portal-button">
                <QrCode />
                {t('بوابة عملاء RIBBON')}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader><DialogTitle>{t('بوابة عملاء RIBBON')}</DialogTitle></DialogHeader>
                    <div className="p-5 pt-2"><PortalLinkBox portal={portal} /></div>
                </DialogContent>
            </Dialog>
        </>
    );
}

/** ما يُفعل برابطٍ صدر: نسخٌ وواتساب */
function SharedActions({ shared }: { shared: SharedLink }) {
    const t = useTranslate();

    return (
        <div className="space-y-3" data-testid="portal-shared">
            <div className="rounded-xl border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] p-3">
                <p className="whitespace-pre-line break-words text-[13px] leading-relaxed text-[#374151]">{shared.message}</p>
            </div>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <CopyButton text={shared.url} label={t('نسخ الرابط')} size="md" className="w-full" />
                <Button variant="success" asChild>
                    <a href={shared.whatsapp} target="_blank" rel="noreferrer" data-testid="portal-whatsapp"><MessageCircle />{t('مشاركة عبر واتساب')}</a>
                </Button>
            </div>
            <p className="text-[12px] text-[#9ca3af]">{t('لا تُرسل الرسالة تلقائيًّا: يُفتح واتساب بنصّها وترسلها بيدك.')}</p>
        </div>
    );
}

/** ملفُّ العميل */
export function CustomerPortalButton({ state }: { state: PortalLinkState }) {
    const t = useTranslate();
    const [confirm, confirmDialog] = useConfirm();
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [now, setNow] = useState(state);
    const [shared, setShared] = useState<SharedLink | null>(null);
    const failed = t('تعذّر ذلك الآن. تحقّق من الاتصال وحاول من جديد.');

    const run = async (path: 'share' | 'reissue') => {
        setBusy(true);
        const { data, error } = await askPortal<SharedLink>(now.urls[path], 'POST', failed);
        setBusy(false);
        if (error || !data) return void toast.error(error ?? failed);
        setShared(data);
        setNow(data.state);
        if (path === 'reissue') toast.success(t('صدر رابط جديد، والقديم لم يعد يعمل'));
    };
    const revoke = async () => {
        if (! await confirm({ message: 'إلغاء رابط هذا العميل؟ يخرج من أجهزته ولا يعمل الرابط بعدها.', danger: true, action: 'إلغاء الرابط' })) return;
        setBusy(true);
        const { data, error } = await askPortal<{ state: PortalLinkState }>(now.urls.revoke, 'DELETE', failed);
        setBusy(false);
        if (error || !data) return void toast.error(error ?? failed);
        setShared(null);
        setNow(data.state);
        toast.success(t('أُلغي الرابط وخرج صاحبه من أجهزته'));
    };

    return (
        <>
            {confirmDialog}
            <Button variant="outline" onClick={() => setOpen(true)} data-testid="portal-customer-button">
                <Share2 />
                {t('مشاركة رابط العميل')}
            </Button>
            <Dialog open={open} onOpenChange={(o) => { setOpen(o); if (!o) setShared(null); }}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader><DialogTitle>{t('رابط العميل إلى بوابته')}</DialogTitle></DialogHeader>
                    <div className="space-y-4 p-5 pt-2">
                        <dl className="grid grid-cols-2 gap-3 text-sm" data-testid="portal-state">
                            <div><dt className="text-[12px] text-[#9ca3af]">{t('الرابط الحالي')}</dt><dd className="font-medium">{now.issued ? <span dir="ltr">{now.issued}</span> : t('لم يصدر بعد')}</dd></div>
                            <div><dt className="text-[12px] text-[#9ca3af]">{t('آخر دخول به')}</dt><dd className="font-medium">{now.used ? <span dir="ltr">{now.used}</span> : '—'}</dd></div>
                        </dl>

                        {!now.hasPhone ? (
                            <p className="rounded-xl bg-[#fffbeb] px-4 py-3 text-sm text-[#92400e]">{t('أضف رقم هاتف العميل أولًا — يدخل به إلى بوابته.')}</p>
                        ) : shared ? (
                            <SharedActions shared={shared} />
                        ) : (
                            <Button className="w-full" loading={busy} onClick={() => void run('share')} data-testid="portal-make">
                                <Link2 />
                                {now.issued ? t('عرض الرابط للمشاركة') : t('إنشاء رابط العميل')}
                            </Button>
                        )}

                        {now.issued && (
                            <div className="flex flex-wrap gap-2 border-t border-[var(--ui-border,#e8e8e8)] pt-4">
                                <Button variant="outline" size="sm" disabled={busy} onClick={() => void run('reissue')} data-testid="portal-reissue"><RefreshCw />{t('إعادة إصدار الرابط')}</Button>
                                <Button variant="ghost" size="sm" className="text-[#b91c1c]" disabled={busy} onClick={() => void revoke()} data-testid="portal-revoke">{t('إلغاء رابط الوصول')}</Button>
                            </div>
                        )}
                    </div>
                </DialogContent>
            </Dialog>
        </>
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

