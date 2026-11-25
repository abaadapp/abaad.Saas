import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ExternalLink, LogOut } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import { PortalLinkBox, STOP_ASK, type PortalPanel } from '@/Components/RibbonPortal';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import BackToSettings from './partials/BackToSettings';

interface EmployeeRow {
    id: number;
    name: string;
    role: string;
    share: boolean;
    manage: boolean;
}

interface LinkRow {
    id: number;
    customer: string;
    since: string | null;
    used: string | null;
    /** كم جهازًا داخلٌ الآن */
    devices: number;
    stopped: boolean;
    /** بابُ إيقافه والسماح به */
    url: string;
}

interface Props {
    portal: PortalPanel;
    urls: { self: string; staff: string; signOut: string };
    links: LinkRow[];
    pagination: { current_page: number; last_page: number; total: number };
    employees: EmployeeRow[];
    /** يختار من يعمل على البوابة — صاحبُ النشاط وحده */
    mayGrant: boolean;
}

/** «بوابة العملاء» — تخصيصٌ لمتجر RIBBON. لا يفتحها خادمُ متجرٍ آخر (404) */
export default function CustomerPortal() {
    const { portal, urls, links, pagination, employees, mayGrant } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [ask, confirmDialog] = useConfirm();
    const [staff, setStaff] = useState(employees);
    const [saving, setSaving] = useState(false);
    const dirty = JSON.stringify(staff) !== JSON.stringify(employees);

    const set = (id: number, key: 'share' | 'manage', on: boolean) =>
        setStaff((rows) => rows.map((r) => (r.id === id ? { ...r, [key]: on, ...(key === 'manage' && on ? { share: true } : {}), ...(key === 'share' && !on ? { manage: false } : {}) } : r)));
    const save = () =>
        router.post(
            urls.staff,
            { share: staff.filter((r) => r.share).map((r) => r.id), manage: staff.filter((r) => r.manage).map((r) => r.id) },
            { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false) },
        );
    const stop = async (l: LinkRow) => {
        if (await ask(STOP_ASK)) router.delete(l.url, { preserveScroll: true });
    };
    const signOut = async () => {
        if (await ask({ message: 'إخراج كل العملاء من أجهزتهم؟ ويدخل كل منهم من جديد برقم هاتفه.', danger: true, action: 'إخراج الجميع' })) router.delete(urls.signOut, { preserveScroll: true });
    };

    return (
        <AdminLayout title="بوابة العملاء">
            {confirmDialog}
            <PageHeader title="بوابة العملاء" subtitle={t('يطّلع عميلك على مشترياته وفواتيره ونقاطه من رابط واحد برقم هاتفه')} />
            <BackToSettings />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card>
                    <CardHeader className="flex-row items-center justify-between">
                        <CardTitle>{t('رابط البوابة')}</CardTitle>
                        <Badge variant="success" data-testid="portal-status">{t('تعمل')}</Badge>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <PortalLinkBox portal={portal} stacked />
                        <Button variant="subtle" className="w-full" asChild>
                            <a href={portal.url} target="_blank" rel="noreferrer" data-testid="portal-preview"><ExternalLink />{t('معاينة الصفحة')}</a>
                        </Button>
                    </CardContent>
                </Card>

                <Card className="lg:col-span-2">
                    <CardHeader className="flex-row items-center justify-between">
                        <CardTitle>{t('من يعمل على البوابة')}</CardTitle>
                        {mayGrant && dirty && <Button size="sm" loading={saving} onClick={save} data-testid="portal-save-staff">{t('حفظ')}</Button>}
                    </CardHeader>
                    <CardContent>
                        <p className="mb-3 text-[13px] text-[#6b7280]">
                            {t('صاحب النشاط يملكها كلها. «يرى البوابة»: الرابط والرمز وحال دخول العميل في ملفه. و«يدير البوابة»: ومعها إيقاف الدخول وهذه الصفحة. ولا تفتح الفواتير ولا تعديل النقاط، وتلزم معها صلاحية الشاشة التي فيها الزر.')}
                        </p>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('الموظف')}</TableHead>
                                    <TableHead className="text-center">{t('يرى البوابة')}</TableHead>
                                    <TableHead className="text-center">{t('يدير البوابة')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {staff.length === 0 && <TableEmpty colSpan={3}>{t('لا موظفين بعد')}</TableEmpty>}
                                {staff.map((r) => (
                                    <TableRow key={r.id} data-testid="portal-staff-row">
                                        <TableCell><span className="font-medium">{r.name}</span> <span className="text-[12px] text-[#9ca3af]">{t(r.role)}</span></TableCell>
                                        {(['share', 'manage'] as const).map((key) => (
                                            <TableCell key={key} className="text-center">
                                                <input
                                                    type="checkbox"
                                                    className="size-5 accent-[#111]"
                                                    checked={r[key]}
                                                    disabled={!mayGrant}
                                                    aria-label={`${t(key === 'share' ? 'يرى البوابة' : 'يدير البوابة')} — ${r.name}`}
                                                    onChange={(e) => set(r.id, key, e.target.checked)}
                                                />
                                            </TableCell>
                                        ))}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Card className="lg:col-span-3">
                    <CardHeader className="flex-row items-center justify-between">
                        <CardTitle>{t('من دخل من العملاء')} <span className="text-[13px] font-normal text-[#9ca3af]">({number(pagination.total)})</span></CardTitle>
                        {links.some((l) => l.devices > 0) && <Button variant="ghost" size="sm" className="text-[#b91c1c]" onClick={() => void signOut()} data-testid="portal-sign-out"><LogOut />{t('إخراج الجميع')}</Button>}
                    </CardHeader>
                    <CardContent>
                        <p className="mb-3 rounded-xl bg-[#fffbeb] px-4 py-3 text-[13px] text-[#92400e]" data-testid="portal-open-note">
                            {t('من يكتب رقم عميل في البوابة يرى مشترياته وفواتيره ونقاطه. أوقف دخول من يطلب ذلك، فلا تفتح صفحته برقمه.')}
                        </p>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('العميل')}</TableHead>
                                    <TableHead>{t('أول دخول')}</TableHead>
                                    <TableHead>{t('آخر زيارة')}</TableHead>
                                    <TableHead>{t('أجهزة دخل منها')}</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {links.length === 0 && <TableEmpty colSpan={5}>{t('لم يدخل عميل بعد')}</TableEmpty>}
                                {links.map((l) => (
                                    <TableRow key={l.id} data-testid="portal-link-row">
                                        <TableCell><span className="font-medium">{l.customer}</span> {l.stopped && <Badge variant="danger">{t('الدخول موقوف')}</Badge>}</TableCell>
                                        <TableCell>{l.since ? <span dir="ltr">{l.since}</span> : '—'}</TableCell>
                                        <TableCell>{l.used ? <span dir="ltr">{l.used}</span> : '—'}</TableCell>
                                        <TableCell>{number(l.devices)}</TableCell>
                                        <TableCell className="text-end">
                                            {l.stopped ? (
                                                <Button variant="ghost" size="sm" onClick={() => router.post(l.url, {}, { preserveScroll: true })}>{t('السماح بالدخول')}</Button>
                                            ) : (
                                                <Button variant="ghost" size="sm" className="text-[#b91c1c]" onClick={() => void stop(l)}>{t('إيقاف الدخول')}</Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        {pagination.last_page > 1 && (
                            <div className="mt-4 flex items-center justify-center gap-3 text-sm">
                                <Button variant="outline" size="sm" disabled={pagination.current_page <= 1} onClick={() => router.get(urls.self, { page: pagination.current_page - 1 }, { preserveScroll: true })}>{t('السابق')}</Button>
                                <span dir="ltr">{pagination.current_page} / {pagination.last_page}</span>
                                <Button variant="outline" size="sm" disabled={pagination.current_page >= pagination.last_page} onClick={() => router.get(urls.self, { page: pagination.current_page + 1 }, { preserveScroll: true })}>{t('التالي')}</Button>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
