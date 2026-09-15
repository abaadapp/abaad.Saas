import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Archive, Download, Loader2, TriangleAlert } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Select } from '@/Components/Field';
import StatusPill, { type SetupState } from '@/Components/StatusPill';
import { PageActions, SettingsSection } from '@/Components/Settings';
import { useTranslate } from '@/lib/i18n';

/** ما يصل من `BusinessArchiveController::panel` — ولا يُخمَّن شيءٌ منه هنا */
export interface ArchiveRow {
    id: number;
    /** «2026-08» — يُرسَل عند الإنشاء ويُستعمل مفتاحًا للصفّ */
    period: string;
    /** «أغسطس 2026» — مترجَمٌ على الخادم بلغة القارئ */
    label: string;
    status: string;
    created_at: string | null;
    size_mb: number | null;
    downloadable: boolean;
    failure_reason: string | null;
}

export interface ArchiveData {
    enabled: boolean;
    may_generate: boolean;
    may_download: boolean;
    retention_months: number;
    months: { value: string; label: string }[];
    items: ArchiveRow[];
}

/**
 * حالُ الأرشيف بمفردات `StatusPill` المغلقة — لا بألوانٍ تُخترع هنا.

 * الشارةُ في هذا النظام ستُّ حالاتٍ لكلٍّ معنًى مفروز، ولها أيقونتُها
 * ونصُّها لا لونُها وحده. فحالاتُ الأرشيف الخمسُ تُسنَد إليها، ولا يُبنى
 * لها سلّمُ ألوانٍ سابع تفترق به هذه الشاشة عن بقيّة اللوحة.
 *
 * و`label` يحمل كلمةَ الأرشيف نفسَها («جاهز»، «قيد الإنشاء») لأنّها أدقُّ
 * ممّا تقوله الشارةُ القياسيّة هنا: «مُطفأ» ليست «منتهي».
 */
const STATE: Record<string, SetupState> = {
    جاهز: 'ready',
    'قيد الإنشاء': 'progress',
    بانتظار: 'progress',
    فشل: 'error',
    منتهي: 'off',
};

/**
 * الأرشيفُ الشهريّ — قائمةُ شهورٍ وزرُّ تنزيل.
 *
 * ═══ وما لا يُعرض هنا ═══
 *
 * لا مسارُ تخزين، ولا اسمُ قرص، ولا بصمة، ولا اسمُ جدول. صاحبُ المحلّ يسأل
 * سؤالين: أجاهزٌ أرشيفُ أغسطس؟ وكم حجمُه؟ وما سواهما تفاصيلُ تشغيلٍ تخصّ
 * من يُصلح، ومكانُها لوحةُ المنصّة.
 *
 * وسببُ الفشل يُعرض: هو الشيءُ الوحيد الذي يُملي على التاجر فعلًا («الشهر
 * فارغ» ≠ «القرص ممتلئ»). وبلا سببٍ يبقى صفٌّ أحمرُ لا يُعرف ما يُفعل به.
 */
export default function ArchivePanel({ archive }: { archive: ArchiveData }) {
    const t = useTranslate();
    const [period, setPeriod] = useState(archive.months[0]?.value ?? '');
    const [sending, setSending] = useState(false);

    const generate = () => {
        if (!period || sending) return;
        setSending(true);
        router.post(
            route('admin.archives.store'),
            { period },
            {
                preserveScroll: true,
                /*
                 * و`section=backup` يبقى في الطلب العائد.
                 *
                 * `back()` على الخادم تعيد إلى الرابط الذي جاء منه، وهو
                 * يحمل المعامل — فيُعاد بناءُ القسم ببياناته الجديدة. ولولاه
                 * لعاد التاجر إلى الإعدادات بلا قائمة أرشيف.
                 */
                onFinish: () => setSending(false),
            },
        );
    };

    if (!archive.enabled) {
        return null;
    }

    return (
        <SettingsSection
            title="الأرشيف الشهري"
            description="نسخة مقروءة من بيانات كل شهر: ملفات Excel وفواتير PDF والمرفقات — للاحتفاظ بها أو تسليمها لمحاسبك."
            icon={Archive}
        >
            {/*
                والفرقُ يُقال في الشاشة لا في ورقةٍ داخل الملفّ وحدها.

                التبويبُ نفسُه فيه «تنزيل نسخة احتياطية» فوق هذا القسم. ومن
                يقرأ الاثنين بلا تمييزٍ يظنّ أحدَهما يُغني عن الآخر — فينزّل
                الأرشيفَ كلَّ شهر ويظنّ أنّه أمّن قاعدتَه.
            */}
            <p className="mb-4 rounded-[12px] bg-[#f6f6f4] px-3 py-2.5 text-[12px] leading-relaxed text-[#555]">
                {t('الأرشيف الشهري تصدير مقروء لبياناتك، وليس نسخة احتياطية تقنية تُستعاد بها قاعدة البيانات.')}
                {archive.retention_months > 0 && (
                    <>
                        {' '}
                        {t('يُحتفظ بكل أرشيف :n شهرًا.', { n: String(archive.retention_months) })}
                    </>
                )}
            </p>

            {archive.items.length === 0 ? (
                <p className="py-6 text-center text-[13px] text-[#777]">
                    {t('لا أرشيف بعد — أنشئ أول أرشيف لشهر مضى.')}
                </p>
            ) : (
                <div className="space-y-2">
                    {archive.items.map((row) => (
                        <div
                            key={row.period}
                            className="flex flex-wrap items-center gap-3 rounded-[12px] border border-[#ececec] px-3.5 py-3"
                        >
                            <div className="min-w-[8rem] flex-1">
                                <div className="flex items-center gap-2">
                                    <span className="text-[14px] font-semibold text-[#111]">{row.label}</span>
                                    <StatusPill state={STATE[row.status] ?? 'idle'} label={row.status} />
                                </div>
                                <div className="mt-0.5 text-[12px] text-[#777]">
                                    {row.created_at && <span>{t('تاريخ الإنشاء')}: {row.created_at}</span>}
                                    {row.size_mb !== null && <span> · {row.size_mb} MB</span>}
                                </div>
                                {row.failure_reason && (
                                    <p className="mt-1 flex items-start gap-1.5 text-[12px] text-[#b91c1c]">
                                        <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
                                        {row.failure_reason}
                                    </p>
                                )}
                            </div>

                            {row.downloadable && archive.may_download && (
                                <Button asChild variant="outline">
                                    <a href={route('admin.archives.download', row.id)}>
                                        <Download />
                                        {t('تحميل')}
                                    </a>
                                </Button>
                            )}
                        </div>
                    ))}
                </div>
            )}

            {archive.may_generate && archive.months.length > 0 && (
                <div className="mt-5 border-t border-[#ececec] pt-4">
                    {/*
                        والشهورُ المعروضة مغلقةٌ كلُّها ولا أرشيفَ لها.

                        الخادمُ يبنيها (`selectable`) — فلا يُعرض الشهرُ
                        الجاري، ولا شهرٌ له أرشيفٌ يُختار فيُردّ «موجود».
                    */}
                    <Select
                        value={period}
                        onChange={(e) => setPeriod(e.target.value)}
                        options={archive.months.map((m) => ({ value: m.value, label: m.label }))}
                        aria-label={t('فترة الأرشيف')}
                    />
                    <PageActions className="mt-3">
                        <Button type="button" onClick={generate} disabled={sending || !period}>
                            {sending ? <Loader2 className="animate-spin" /> : <Archive />}
                            {t('إنشاء أرشيف الآن')}
                        </Button>
                    </PageActions>
                </div>
            )}
        </SettingsSection>
    );
}
