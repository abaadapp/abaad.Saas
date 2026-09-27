import { usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Download, ShieldCheck } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { fileSize } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

/**
 * أرشيفاتُ الشركات المحذوفة — ما بقي بعد أن زالت.
 *
 * ═══ ولمَ صفحةٌ مستقلّة ═══
 *
 * الشركةُ المحذوفة لا صفحةَ تفاصيلَ لها: معرّفُها يردّ ٤٠٤. فلو كان بابُ
 * أرشيفها في صفحتها لصار الأرشيفُ غيرَ مطروقٍ في اللحظة التي يُحتاج فيها
 * — بعد الحذف.
 *
 * ═══ وما لا يُعرض هنا ═══
 *
 * لا مسارٌ على القرص، ولا مسارٌ في التخزين البعيد، ولا اسمُ دلوٍ ولا
 * مفتاح. والبصمةُ تُعرض مقتطعةً لأنّها وسيلةُ مطابقةٍ لا سِرّ: من نزّل
 * الملفَّ يحسب `sha256` ويقارن.
 */
interface PurgeRow {
    id: number;
    business_id: number;
    business_name: string;
    by: string | null;
    status: 'pending' | 'running' | 'done' | 'failed';
    label: string;
    /** أبلغ السقوطُ مرحلةً مسّت البيانات؟ — الفرقُ كلُّ ما يحتاجه من يقرأ */
    touched: boolean;
    rows: number | null;
    users: number | null;
    files: number | null;
    failures: number;
    /** أثبتت استعادةُ النسخة البعيدة فعلًا؟ */
    verified: boolean;
    sha: string | null;
    bytes: number | null;
    available: boolean;
    error: string | null;
    at: string | null;
}

export default function BusinessPurges() {
    const { runs } = usePage<PageProps<{ runs: PurgeRow[] }>>().props;
    const t = useTranslate();

    return (
        <PlatformLayout title={t('أرشيفات الحذف النهائي')}>
            <PageHeader
                title={t('أرشيفات الحذف النهائي')}
                subtitle={t('دفاتر الشركات المحذوفة — محفوظة ومشفَّرة ومنسوخة إلى تخزين مستقل')}
            />

            <Card className="p-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('الشركة')}</TableHead>
                            <TableHead>{t('الحالة')}</TableHead>
                            <TableHead>{t('من حذفها')}</TableHead>
                            <TableHead>{t('التاريخ')}</TableHead>
                            <TableHead>{t('ما مُحي')}</TableHead>
                            <TableHead>{t('الأرشيف')}</TableHead>
                            <TableHead className="text-end">{t('تنزيل')}</TableHead>
                        </TableRow>
                    </TableHeader>

                    <TableBody>
                        {runs.length === 0 && <TableEmpty colSpan={7}>{t('لا شركة حُذفت نهائيًا بعد.')}</TableEmpty>}

                        {runs.map((r) => (
                            <TableRow key={r.id}>
                                <TableCell>
                                    <span className="block font-semibold text-[#111]">{r.business_name}</span>
                                    <span className="block text-[11.5px] text-[#71717a]" dir="ltr">
                                        #{r.business_id}
                                    </span>
                                </TableCell>

                                <TableCell>
                                    <Badge
                                        variant={
                                            r.status === 'done' ? 'success' : r.status === 'failed' ? 'danger' : 'warning'
                                        }
                                    >
                                        {r.label}
                                    </Badge>
                                    {r.status === 'failed' && (
                                        <span className="mt-1 block text-[11.5px] leading-relaxed text-[#b91c1c]">
                                            {r.touched
                                                ? t('بدأ المحو قبل السقوط — راجع قبل إعادة المحاولة.')
                                                : t('سقط قبل أن يُمسّ شيء — الشركة كما كانت.')}
                                        </span>
                                    )}
                                    {r.error && (
                                        <span className="mt-1 block text-[11.5px] leading-relaxed text-[#7f1d1d]">{r.error}</span>
                                    )}
                                </TableCell>

                                <TableCell className="text-[12.5px] text-[#4b4b4b]">{r.by ?? '—'}</TableCell>

                                <TableCell className="text-[12.5px] text-[#4b4b4b]" dir="ltr">
                                    {r.at ?? '—'}
                                </TableCell>

                                <TableCell className="text-[12px] text-[#4b4b4b]">
                                    {r.rows === null ? (
                                        '—'
                                    ) : (
                                        <span className="block">
                                            {t('صفوف: :n', { n: String(r.rows) })}
                                            {r.users !== null && <> · {t('حسابات: :n', { n: String(r.users) })}</>}
                                            {r.files !== null && <> · {t('ملفات: :n', { n: String(r.files) })}</>}
                                        </span>
                                    )}
                                    {r.failures > 0 && (
                                        <span className="mt-0.5 flex items-center gap-1 text-[11.5px] text-[#b45309]">
                                            <AlertTriangle className="size-3" />
                                            {t('تعذّر حذف :n ملفًّا', { n: String(r.failures) })}
                                        </span>
                                    )}
                                </TableCell>

                                <TableCell>
                                    {r.verified ? (
                                        <span className="flex items-center gap-1 text-[11.5px] font-semibold text-[#15803d]">
                                            <ShieldCheck className="size-3.5" />
                                            {t('استُعيد وتُحقق منه')}
                                        </span>
                                    ) : (
                                        <span className="text-[11.5px] text-[#71717a]">{t('لم يُتحقق منه')}</span>
                                    )}
                                    {r.sha && (
                                        <span className="mt-0.5 block font-mono text-[11px] text-[#71717a]" dir="ltr">
                                            {r.sha}…
                                        </span>
                                    )}
                                    {r.bytes !== null && (
                                        /* والحجمُ رقمٌ ووحدةٌ لاتينيّة — يُقرأ من اليسار وإن كان السطرُ عربيًّا */
                                        <span className="block text-[11px] text-[#9ca3af]" dir="ltr">
                                            {fileSize(r.bytes)}
                                        </span>
                                    )}
                                </TableCell>

                                <TableCell className="text-end">
                                    {r.available ? (
                                        <Button variant="outline" size="sm" asChild>
                                            <a href={route('super-admin.businesses.purgeDownload', r.id)}>
                                                <Download className="size-3.5" />
                                                {t('تنزيل')}
                                            </a>
                                        </Button>
                                    ) : (
                                        <span className="text-[11.5px] text-[#9ca3af]">{t('لا نسخة')}</span>
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Card>

            <p className="mt-4 flex items-start gap-2 text-[12.5px] leading-relaxed text-[#71717a]">
                <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-[#15803d]" />
                {t('كل تنزيل يُقيَّد في سجل النشاط باسم من نزّله ووقته. والملف يمر بالنظام — ولا رابط مباشر إلى التخزين.')}
            </p>
        </PlatformLayout>
    );
}
