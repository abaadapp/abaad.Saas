import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Archive, CalendarRange, Download, Loader2, TriangleAlert } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Select } from '@/Components/Field';
import StatusPill, { type SetupState } from '@/Components/StatusPill';
import { PageActions, SettingsSection } from '@/Components/Settings';
import { useTranslate } from '@/lib/i18n';

/** ما يصل من `BusinessArchiveController::panel` — ولا يُخمَّن شيءٌ منه هنا */
export interface ArchiveRow {
    id: number;
    /** «2026-08» أو «2026-W37» — مفتاحٌ ثابتٌ لا يتبدّل بلغة القارئ */
    period: string;
    /** «أغسطس 2026» أو «7 – 13 سبتمبر 2026» — مترجَمٌ على الخادم بلغة القارئ */
    label: string;
    status: string;
    created_at: string | null;
    size_mb: number | null;
    downloadable: boolean;
    failure_reason: string | null;
}

interface Offer {
    /** تاريخُ بداية المدى — الخادمُ يعيد بناءه منه */
    value: string;
    label: string;
}

export interface ArchiveData {
    enabled: boolean;
    weekly_enabled: boolean;
    may_generate: boolean;
    may_download: boolean;
    retention_months: number;
    retention_weeks: number;
    monthly: ArchiveRow[];
    weekly: ArchiveRow[];
    offer_monthly: Offer[];
    offer_weekly: Offer[];
}

/**
 * حالُ الأرشيف بمفردات `StatusPill` المغلقة — لا بألوانٍ تُخترع هنا.
 *
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
 * قسمُ أرشيفٍ واحد — أسبوعيًّا كان أو شهريًّا.
 *
 * ═══ ولمَ مكوّنٌ واحد لا قسمان مكتوبان ═══
 *
 * القسمان يعرضان الشيء نفسَه: قائمةَ صفوفٍ بحالةٍ وحجمٍ وزرِّ تنزيل، ثمّ
 * قائمةَ فتراتٍ وزرَّ إنشاء. والفرقُ كلُّه في العنوان والأيقونة وسطرِ
 * المدّة. ونسخُ ستّين سطرًا لأجل ثلاثةٍ يعني أنّ إصلاحًا في أحدهما لا يبلغ
 * الآخر — وهو ما يُرى بعد شهرين: زرُّ تنزيلٍ أُصلح في الشهريّ وبقي معطوبًا
 * في الأسبوعيّ.
 */
function ArchiveGroup({
    title,
    description,
    icon,
    note,
    rows,
    offers,
    type,
    mayDownload,
    mayGenerate,
    highlighted,
    emptyText,
}: {
    title: string;
    description: string;
    icon: typeof Archive;
    note: string;
    rows: ArchiveRow[];
    offers: Offer[];
    type: 'weekly' | 'monthly';
    mayDownload: boolean;
    mayGenerate: boolean;
    highlighted: number | null;
    emptyText: string;
}) {
    const t = useTranslate();
    const [period, setPeriod] = useState(offers[0]?.value ?? '');
    const [sending, setSending] = useState(false);

    const generate = () => {
        if (!period || sending) return;
        setSending(true);
        router.post(
            route('admin.archives.store'),
            { type, period },
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

    return (
        <SettingsSection title={title} description={description} icon={icon}>
            <p className="mb-4 rounded-[12px] bg-[#f6f6f4] px-3 py-2.5 text-[12px] leading-relaxed text-[#555]">
                {note}
            </p>

            {rows.length === 0 ? (
                <p className="py-6 text-center text-[13px] text-[#777]">{emptyText}</p>
            ) : (
                <div className="space-y-2">
                    {rows.map((row) => (
                        <div
                            key={row.period}
                            className={
                                'flex flex-wrap items-center gap-3 rounded-[12px] border px-3.5 py-3 ' +
                                (row.id === highlighted
                                    ? 'border-[#111] bg-[#fafaf9]'
                                    : 'border-[#ececec]')
                            }
                        >
                            <div className="min-w-[8rem] flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-[14px] font-semibold text-[#111]">{row.label}</span>
                                    <StatusPill state={STATE[row.status] ?? 'idle'} label={row.status} />
                                </div>
                                <div className="mt-0.5 text-[12px] text-[#777]">
                                    {row.created_at && (
                                        <span>
                                            {t('تاريخ الإنشاء')}: {row.created_at}
                                        </span>
                                    )}
                                    {row.size_mb !== null && <span> · {row.size_mb} MB</span>}
                                </div>
                                {row.failure_reason && (
                                    <p className="mt-1 flex items-start gap-1.5 text-[12px] text-[#b91c1c]">
                                        <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
                                        {row.failure_reason}
                                    </p>
                                )}
                            </div>

                            {row.downloadable && mayDownload && (
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

            {mayGenerate && offers.length > 0 && (
                <div className="mt-5 border-t border-[#ececec] pt-4">
                    {/*
                        والفتراتُ المعروضة مغلقةٌ كلُّها ولا أرشيفَ لها.

                        الخادمُ يبنيها (`selectable`) — فلا تُعرض الفترةُ
                        الجارية، ولا فترةٌ لها أرشيفٌ تُختار فيُردّ «موجود».
                    */}
                    <Select
                        value={period}
                        onChange={(e) => setPeriod(e.target.value)}
                        options={offers.map((o) => ({ value: o.value, label: o.label }))}
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

/**
 * الأرشيفُ الأسبوعيُّ والشهريُّ — قائمتان وزرّا تنزيل.
 *
 * ═══ وما لا يُعرض هنا ═══
 *
 * لا مسارُ تخزين، ولا اسمُ قرص، ولا بصمة، ولا اسمُ جدول. صاحبُ المحلّ يسأل
 * سؤالين: أجاهزٌ أرشيفُ أغسطس؟ وكم حجمُه؟ وما سواهما تفاصيلُ تشغيلٍ تخصّ
 * من يُصلح، ومكانُها لوحةُ المنصّة.
 *
 * وسببُ الفشل يُعرض: هو الشيءُ الوحيد الذي يُملي على التاجر فعلًا («الفترة
 * فارغة» ≠ «القرص ممتلئ»). وبلا سببٍ يبقى صفٌّ أحمرُ لا يُعرف ما يُفعل به.
 */
export default function ArchivePanel({ archive }: { archive: ArchiveData }) {
    const t = useTranslate();
    const { url } = usePage();

    if (!archive.enabled) {
        return null;
    }

    /*
     * والجرسُ يقود إلى الصفّ بعينه لا إلى القائمة.
     *
     * أربعون صفًّا بين أسبوعيٍّ وشهريّ، و«تم تجهيز الأرشيف» بلا إشارةٍ إلى
     * أيِّها تجعل التاجر يقرأ القائمةَ كلَّها. والمعامل `archive` يصل في
     * الرابط، فيُحاط صفُّه بإطار.
     */
    const highlighted = Number(new URLSearchParams(url.split('?')[1] ?? '').get('archive')) || null;

    return (
        <>
            {archive.weekly_enabled && (
                <ArchiveGroup
                    title={t('الأرشيف الأسبوعي')}
                    description={t('نسخة مقروءة من بيانات كل أسبوع منقضٍ — تُجهَّز تلقائيًّا كل اثنين.')}
                    icon={CalendarRange}
                    note={
                        archive.retention_weeks > 0
                            ? t('يُحتفظ بكل أرشيف أسبوعي :n أسبوعًا، ثم يُحذف ملفه ويبقى سجله.', {
                                  n: String(archive.retention_weeks),
                              })
                            : t('يُحتفظ بالأرشيف الأسبوعي بلا مدة محددة.')
                    }
                    rows={archive.weekly}
                    offers={archive.offer_weekly}
                    type="weekly"
                    mayDownload={archive.may_download}
                    mayGenerate={archive.may_generate}
                    highlighted={highlighted}
                    emptyText={t('لا أرشيف أسبوعي بعد — يُجهَّز أول أرشيف بعد انتهاء أسبوع كامل.')}
                />
            )}

            <ArchiveGroup
                title={t('الأرشيف الشهري')}
                description={t('نسخة مقروءة من بيانات كل شهر: ملفات Excel وفواتير PDF والمرفقات — للاحتفاظ بها أو تسليمها لمحاسبك.')}
                icon={Archive}
                note={
                    /*
                        والفرقُ يُقال في الشاشة لا في ورقةٍ داخل الملفّ وحدها.

                        التبويبُ نفسُه فيه «تنزيل نسخة احتياطية» فوق هذا القسم.
                        ومن يقرأ الاثنين بلا تمييزٍ يظنّ أحدَهما يُغني عن الآخر
                        — فينزّل الأرشيفَ كلَّ شهر ويظنّ أنّه أمّن قاعدتَه.
                    */
                    t('الأرشيف الشهري تصدير مقروء لبياناتك، وليس نسخة احتياطية تقنية تُستعاد بها قاعدة البيانات.') +
                    (archive.retention_months > 0
                        ? ' ' + t('يُحتفظ بكل أرشيف :n شهرًا.', { n: String(archive.retention_months) })
                        : '')
                }
                rows={archive.monthly}
                offers={archive.offer_monthly}
                type="monthly"
                mayDownload={archive.may_download}
                mayGenerate={archive.may_generate}
                highlighted={highlighted}
                emptyText={t('لا أرشيف بعد — أنشئ أول أرشيف لشهر مضى.')}
            />
        </>
    );
}
