import { useCallback, useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import { ChevronLeft, ExternalLink, Printer, QrCode, ShieldCheck, ShieldOff, Smartphone, UserRoundCheck } from 'lucide-react';
import { toast } from 'sonner';
import CopyButton from '@/Components/CopyButton';
import { useConfirm } from '@/Components/ConfirmDialog';
import { DeviceApproval } from '@/Components/RibbonPortalDevice';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { askPortal, type PortalCustomerState, type WaitingCustomer, type WaitingDevice } from '@/lib/ribbon-portal';
import { cn } from '@/lib/utils';

/**
 * بوابةُ عملاء RIBBON عند الموظّف — تخصيصٌ لمتجر سعود وحده.
 *
 * لا شيءَ هنا يُرسم بلا ما يرسله الخادم (`RibbonPortal\Portal`): متجرٌ آخر لا
 * يصله مفتاحُها، وموظّفٌ لم يُختر لا يصله كذلك. والرابطُ واحدٌ لجميع العملاء: يكتب
 * العميلُ رقمَه، ويُعتمد جهازُه هنا مرّة. ولا رسالةَ من هنا ولا قناةَ مراسلة.
 */
export interface PortalPanel {
    url: string;
    qr: string;
    poster: string;
    name: string;
    /** كم عميلًا ينتظر اعتمادَ جهازه ساعةَ رُسمت الصفحة */
    waiting: number;
    waitingUrl: string;
}

/** الرابطُ الواحد ورمزُه — لا يكشف شيئًا وحده */
export function PortalLinkBox({ portal, stacked = false }: { portal: PortalPanel; stacked?: boolean }) {
    const t = useTranslate();

    return (
        <div className="space-y-4" data-testid="portal-public-box">
            <p className="text-sm text-[#6b7280]">{t('رابط واحد ورمز واحد لجميع العملاء. يكتب العميل رقم هاتفه المسجّل، ويعتمد الموظف جهازه مرة واحدة.')}</p>
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

/** كلُّ جهازٍ ينتظر: نوعُه ومتى كُتب فيه الرقم */
function DeviceLine({ devices }: { devices: WaitingDevice[] }) {
    const t = useTranslate();

    return (
        <ul className="space-y-0.5">
            {devices.map((d, i) => (
                <li key={i} className="flex items-center gap-1.5 text-[12px] text-[#6b7280]">
                    <Smartphone className="size-3.5 shrink-0" />
                    <span dir="ltr">{d.device || t('جهاز')}</span>
                    <span dir="ltr">{d.at}</span>
                </li>
            ))}
        </ul>
    );
}

/** من ينتظر اعتمادَ جهازه — يتحدّث والنافذةُ مفتوحة */
export function WaitingList({ url, onCount }: { url: string; onCount?: (n: number) => void }) {
    const t = useTranslate();
    const [rows, setRows] = useState<WaitingCustomer[] | null>(null);
    const failed = t('تعذّر ذلك الآن. تحقّق من الاتصال وحاول من جديد.');

    const load = useCallback(async () => {
        const { data } = await askPortal<{ waiting: WaitingCustomer[] }>(url, 'GET', '');
        // انقطاعٌ لا يمحو ما عُرض
        if (!data) return;
        setRows(data.waiting);
        onCount?.(data.waiting.length);
    }, [url, onCount]);
    useEffect(() => {
        void load();
        const timer = setInterval(() => !document.hidden && void load(), 8000);

        return () => clearInterval(timer);
    }, [load]);

    const dismiss = async (row: WaitingCustomer) => {
        const { error } = await askPortal(row.url, 'DELETE', failed);
        if (error) toast.error(error);
        void load();
    };

    return (
        <section className="space-y-3 border-t border-[var(--ui-border,#e8e8e8)] pt-4" data-testid="portal-waiting">
            <h4 className="text-sm font-bold text-[#111]">{t('أجهزة تنتظر الاعتماد')}</h4>
            {rows === null && <p className="text-[13px] text-[#9ca3af]">{t('جارٍ التحميل…')}</p>}
            {rows?.length === 0 && <p className="text-[13px] text-[#9ca3af]">{t('لا جهاز ينتظر الآن. يظهر هنا من كتب رقمه في البوابة وهو عميل مسجّل.')}</p>}
            {rows?.map((row) => (
                <div key={row.id} className="space-y-3 rounded-2xl border border-[var(--ui-border,#e8e8e8)] p-4" data-testid="portal-waiting-row">
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <p className="truncate text-sm font-semibold text-[#111]">{row.name}</p>
                            <p dir="ltr" className="text-start text-[12px] text-[#6b7280]">{row.phone}</p>
                            <DeviceLine devices={row.devices} />
                        </div>
                        <Button variant="ghost" size="sm" className="text-[#b91c1c]" onClick={() => void dismiss(row)}>{t('رفض')}</Button>
                    </div>
                    {row.twin && <p className="text-[12px] text-[#92400e]">{t('هذا الرقم مسجّل لأكثر من عميل. اعتمده لصاحبه.')}</p>}
                    <DeviceApproval url={row.url} onDone={() => void load()} />
                </div>
            ))}
        </section>
    );
}

/** أعلى قائمة العملاء بجانب «إضافة عميل» — ومعه كم جهازًا ينتظر */
export function PortalButton({ portal }: { portal: PortalPanel }) {
    const t = useTranslate();
    const [open, setOpen] = useState(false);
    const [waiting, setWaiting] = useState(portal.waiting);

    return (
        <>
            <Button variant="outline" onClick={() => setOpen(true)} data-testid="portal-button">
                <QrCode />
                {t('بوابة العملاء')}
                {waiting > 0 && <Badge variant="warning" data-testid="portal-waiting-count">{number(waiting)}</Badge>}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-md">
                    <DialogHeader><DialogTitle>{t('بوابة العملاء')}</DialogTitle></DialogHeader>
                    <div className="space-y-5 p-5 pt-2">
                        <PortalLinkBox portal={portal} />
                        <WaitingList url={portal.waitingUrl} onCount={setWaiting} />
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}

/** ملفُّ العميل — قسمُ «بوابة العميل» */
export function CustomerPortalCard({ state }: { state: PortalCustomerState }) {
    const t = useTranslate();
    const [ask, confirmDialog] = useConfirm();
    const [busy, setBusy] = useState(false);
    const [now, setNow] = useState(state);
    const [approving, setApproving] = useState(state.waiting.length > 0);
    const failed = t('تعذّر ذلك الآن. تحقّق من الاتصال وحاول من جديد.');

    const revoke = async () => {
        if (! now.urls.revoke || ! await ask({ message: 'إلغاء دخول هذا العميل؟ يخرج من أجهزته كلها، ولا يدخل حتى يُعتمد جهازه من جديد.', danger: true, action: 'إلغاء الدخول' })) return;
        setBusy(true);
        const { data, error } = await askPortal<{ state: PortalCustomerState }>(now.urls.revoke, 'DELETE', failed);
        setBusy(false);
        if (error || !data) return void toast.error(error ?? failed);
        setNow(data.state);
        toast.success(t('أُلغي الدخول وخرج العميل من أجهزته'));
    };

    return (
        <Card className="p-6" data-testid="portal-customer">
            {confirmDialog}
            <div className="mb-1 flex items-center justify-between gap-3">
                <h3 className="text-sm font-bold text-[#111]">{t('بوابة العميل')}</h3>
                <Badge variant={now.since ? 'success' : 'neutral'} data-testid="portal-customer-state">{now.since ? t('دخل بوابته') : t('لم يدخل بعد')}</Badge>
            </div>
            <p className="mb-4 text-[12px] text-[#9ca3af]">{t('يكتب رقم هاتفه في الرابط الواحد، ويُعتمد جهازه مرة واحدة.')}</p>

            {!now.hasPhone ? (
                <p className="rounded-xl bg-[#fffbeb] px-4 py-3 text-sm text-[#92400e]">{t('أضف رقم هاتف العميل أولًا — يدخل به إلى بوابته.')}</p>
            ) : (
                <div className="space-y-3">
                    {now.since && (
                        <dl className="space-y-1.5 text-[12px]">
                            <div className="flex justify-between gap-3"><dt className="text-[#9ca3af]">{t('أول اعتماد')}</dt><dd dir="ltr" className="text-[#111]">{now.since}</dd></div>
                            <div className="flex justify-between gap-3"><dt className="text-[#9ca3af]">{t('آخر زيارة')}</dt><dd dir="ltr" className="text-[#111]">{now.used ?? '—'}</dd></div>
                            <div className="flex justify-between gap-3"><dt className="text-[#9ca3af]">{t('أجهزة معتمدة')}</dt><dd className="text-[#111]">{number(now.devices)}</dd></div>
                        </dl>
                    )}
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                        <CopyButton text={now.url} label="نسخ رابط البوابة" size="md" className="w-full" />
                        <Button variant="outline" aria-expanded={approving} onClick={() => setApproving(! approving)} data-testid="portal-approve-button">
                            <ShieldCheck />
                            {t('اعتماد جهاز العميل')}
                        </Button>
                    </div>
                    {approving && (
                        <div className="space-y-3 rounded-2xl border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] p-4">
                            {now.waiting.length > 0 && <DeviceLine devices={now.waiting} />}
                            <DeviceApproval<{ state: PortalCustomerState }>
                                url={now.urls.device}
                                onDone={(d) => {
                                    setNow(d.state);
                                    setApproving(false);
                                }}
                            />
                        </div>
                    )}
                    {now.urls.revoke && (
                        <div className="border-t border-[var(--ui-border,#e8e8e8)] pt-3">
                            <Button variant="ghost" size="sm" className="text-[#b91c1c]" loading={busy} onClick={() => void revoke()} data-testid="portal-revoke"><ShieldOff />{t('إلغاء الدخول')}</Button>
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

