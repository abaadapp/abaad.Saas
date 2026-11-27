import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { ChevronLeft, ExternalLink, Printer, QrCode, ShieldCheck, ShieldOff, UserRoundCheck } from 'lucide-react';
import { toast } from 'sonner';
import CopyButton from '@/Components/CopyButton';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { askPortal, type PortalCustomerState } from '@/lib/ribbon-portal';
import { cn } from '@/lib/utils';

/**
 * بوابةُ عملاء RIBBON عند الموظّف — تخصيصٌ لمتجر سعود وحده.
 *
 * لا شيءَ هنا يُرسم بلا ما يرسله الخادم (`RibbonPortal\Portal`): متجرٌ آخر لا
 * يصله مفتاحُها، وموظّفٌ لم يُختر لا يصله كذلك. والرابطُ واحدٌ لجميع العملاء: يكتب
 * العميلُ رقمَه فتفتح صفحتُه. ولا رسالةَ من هنا ولا قناةَ مراسلة.
 */
export interface PortalPanel {
    url: string;
    qr: string;
    poster: string;
    name: string;
}

/** الرابطُ الواحد ورمزُه */
export function PortalLinkBox({ portal, stacked = false }: { portal: PortalPanel; stacked?: boolean }) {
    const t = useTranslate();

    return (
        <div className="space-y-4" data-testid="portal-public-box">
            <p className="text-sm text-[#6b7280]">{t('رابط واحد ورمز QR واحد لجميع العملاء. يكتب العميل رقم هاتفه المسجّل فتفتح مشترياته ونقاطه.')}</p>
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

/** يُسأل قبل الإيقاف ويُقال أثرُه — في ملفّ العميل وفي الإعدادات */
export const STOP_ASK = { message: 'إيقاف دخول هذا العميل؟ يخرج من أجهزته كلها، ولا تفتح صفحته برقم هاتفه حتى تسمح بذلك.', danger: true, action: 'إيقاف الدخول' };

/** ملفُّ العميل — قسمُ «بوابة العميل» */
export function CustomerPortalCard({ state }: { state: PortalCustomerState }) {
    const t = useTranslate();
    const [ask, confirmDialog] = useConfirm();
    const [busy, setBusy] = useState(false);
    const [now, setNow] = useState(state);
    const failed = t('تعذّر ذلك الآن. تحقّق من الاتصال وحاول من جديد.');

    const send = async (method: 'POST' | 'DELETE', done: string) => {
        if (! now.manage) return;
        setBusy(true);
        const { data, error } = await askPortal<{ state: PortalCustomerState }>(now.manage, method, failed);
        setBusy(false);
        if (error || !data) return void toast.error(error ?? failed);
        setNow(data.state);
        toast.success(done);
    };
    const stop = async () => {
        if (await ask(STOP_ASK)) await send('DELETE', t('أُوقف الدخول وخرج العميل من أجهزته'));
    };
    const badge = now.stopped ? (['danger', 'الدخول موقوف'] as const) : now.since ? (['success', 'دخل بوابته'] as const) : (['neutral', 'لم يدخل بعد'] as const);

    return (
        <Card className="p-6" data-testid="portal-customer">
            {confirmDialog}
            <div className="mb-1 flex items-center justify-between gap-3">
                <h3 className="text-sm font-bold text-[#111]">{t('بوابة العميل')}</h3>
                <Badge variant={badge[0]} data-testid="portal-customer-state">{t(badge[1])}</Badge>
            </div>
            <p className="mb-4 text-[12px] text-[#9ca3af]">{t('يكتب رقم هاتفه في الرابط الواحد فتفتح صفحته.')}</p>

            {now.phone === null ? (
                <p className="rounded-xl bg-[#fffbeb] px-4 py-3 text-sm text-[#92400e]">{t('أضف رقم هاتف العميل أولًا — يدخل به إلى بوابته.')}</p>
            ) : (
                <div className="space-y-3">
                    {now.since && (
                        <dl className="space-y-1.5 text-[12px]">
                            <div className="flex justify-between gap-3"><dt className="text-[#9ca3af]">{t('أول دخول')}</dt><dd dir="ltr" className="text-[#111]">{now.since}</dd></div>
                            <div className="flex justify-between gap-3"><dt className="text-[#9ca3af]">{t('آخر زيارة')}</dt><dd dir="ltr" className="text-[#111]">{now.used ?? '—'}</dd></div>
                            <div className="flex justify-between gap-3"><dt className="text-[#9ca3af]">{t('أجهزة دخل منها')}</dt><dd dir="ltr" className="text-[#111]" data-testid="portal-devices">{number(now.devices)}</dd></div>
                        </dl>
                    )}
                    <CopyButton text={now.url} label="نسخ رابط البوابة" size="md" className="w-full" />
                    {now.manage && (
                        <div className="border-t border-[var(--ui-border,#e8e8e8)] pt-3">
                            {now.stopped ? (
                                <Button variant="outline" size="sm" loading={busy} onClick={() => void send('POST', t('سُمح بالدخول، وتفتح صفحة العميل برقم هاتفه'))} data-testid="portal-allow"><ShieldCheck />{t('السماح بالدخول')}</Button>
                            ) : (
                                <Button variant="ghost" size="sm" className="text-[#b91c1c]" loading={busy} onClick={() => void stop()} data-testid="portal-stop"><ShieldOff />{t('إيقاف الدخول')}</Button>
                            )}
                        </div>
                    )}
                </div>
            )}
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
                <span className="mt-1 block text-[13px] leading-snug text-[#9ca3af]">{t('رابط البوابة ومن يعمل عليها ومن دخل من العملاء')}</span>
            </span>
            <ChevronLeft className="size-4 shrink-0 text-[#d1d5db] transition-colors group-hover:text-[#6b7280] ltr:rotate-180" />
        </Link>
    );
}

