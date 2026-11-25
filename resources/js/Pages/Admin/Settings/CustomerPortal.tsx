import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ExternalLink, ShieldOff } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import { ApprovedDevices, PortalLinkBox, type PortalPanel } from '@/Components/RibbonPortal';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { ApprovedDevice } from '@/lib/ribbon-portal';
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
    devices: ApprovedDevice[];
    revokeUrl: string;
}

interface Props {
    portal: PortalPanel;
    urls: { self: string; staff: string; revokeAll: string };
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
    const revoke = async (l: LinkRow) => {
        if (! await ask({ message: 'إلغاء دخول هذا العميل؟ يخرج من أجهزته كلها، ولا يدخل حتى يُعتمد جهازه من جديد.', danger: true, action: 'إلغاء الدخول' })) return;
        router.delete(l.revokeUrl, { preserveScroll: true });
    };
    const revokeAll = async () => {
        if (! await ask({ message: 'إلغاء دخول كل العملاء؟ يخرجون جميعًا من أجهزتهم، ولا يدخل أحد حتى يُعتمد جهازه من جديد.', danger: true, action: 'إلغاء الكل' })) return;
        router.delete(urls.revokeAll, { preserveScroll: true });
    };

    return (
        <AdminLayout title="بوابة العملاء">
            {confirmDialog}
            <PageHeader title="بوابة العملاء" subtitle={t('يطّلع عميلك على مشترياته وفواتيره ونقاطه من رابط واحد، برقم هاتفه وجهاز يُعتمد مرة واحدة')} />
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
                            {t('صاحب النشاط يملكها كلها. «يعتمد الأجهزة»: يرى الرابط والرمز ويعتمد جهاز عميل أمامه، أو عميل اتصل هو برقمه المسجّل. فيفتح به العميل مشترياته، ويُكتب اسم من اعتمده. و«يدير البوابة»: ومعها إلغاء الدخول وهذه الصفحة. ولا تفتح الفواتير ولا تعديل النقاط، وتلزم معها صلاحية الشاشة التي فيها الزر.')}
                        </p>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('الموظف')}</TableHead>
                                    <TableHead className="text-center">{t('يعتمد الأجهزة')}</TableHead>
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
                                                    aria-label={`${t(key === 'share' ? 'يعتمد الأجهزة' : 'يدير البوابة')} — ${r.name}`}
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
                        {links.length > 0 && <Button variant="ghost" size="sm" className="text-[#b91c1c]" onClick={() => void revokeAll()} data-testid="portal-revoke-all"><ShieldOff />{t('إلغاء الكل')}</Button>}
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('العميل')}</TableHead>
                                    <TableHead>{t('أول اعتماد')}</TableHead>
                                    <TableHead>{t('آخر زيارة')}</TableHead>
                                    <TableHead>{t('الأجهزة المعتمدة ومن اعتمدها')}</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {links.length === 0 && <TableEmpty colSpan={5}>{t('لم يدخل عميل بعد')}</TableEmpty>}
                                {links.map((l) => (
                                    <TableRow key={l.id} data-testid="portal-link-row">
                                        <TableCell className="font-medium">{l.customer}</TableCell>
                                        <TableCell><span dir="ltr">{l.since}</span></TableCell>
                                        <TableCell>{l.used ? <span dir="ltr">{l.used}</span> : '—'}</TableCell>
                                        <TableCell>{l.devices.length > 0 ? <ApprovedDevices devices={l.devices} /> : '—'}</TableCell>
                                        <TableCell className="text-end"><Button variant="ghost" size="sm" className="text-[#b91c1c]" onClick={() => void revoke(l)}>{t('إلغاء الدخول')}</Button></TableCell>
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
