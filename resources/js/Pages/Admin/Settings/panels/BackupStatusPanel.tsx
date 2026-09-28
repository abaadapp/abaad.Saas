import { useState } from 'react';
import { router } from '@inertiajs/react';
import { CloudUpload, DatabaseBackup, Download, Loader2, RotateCcw, Timer } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import Field, { Select } from '@/Components/Field';
import { PageActions, SettingsSection } from '@/Components/Settings';
import { useConfirm } from '@/Components/ConfirmDialog';
import { useTranslate } from '@/lib/i18n';

export type BackupFrequency = 'daily' | 'weekly' | 'monthly' | 'manual';

/** ما يصل من `BackupController::panel` — ولا يُخمَّن شيءٌ منه هنا */
export interface BackupStatus {
    frequency: BackupFrequency;
    frequencies: BackupFrequency[];
    /** أمفعَّلٌ النسخُ إلى القرص البعيد على المنصّة؟ */
    offsite_enabled: boolean;
    /**
     * أيملك الناظرُ الاستعادة؟ — لصاحب النشاط وحده (`Permissions::isOwner`).
     *
     * يُخفي الزرَّ فقط، والخادمُ هو ما يردّ. واختياريٌّ لأنّ غيابه لا يعني
     * «لا»: الخادمُ يرسله دائمًا، وما يُخفى هو ما قال عنه صراحةً `false`.
     */
    can_restore?: boolean;
    latest: {
        name: string;
        created_at: string;
        bytes: number;
        /** `null`: كُتبت قبل أن يُحفظ هذا الجواب — لا يُعرف */
        offsite: boolean | null;
        compressed: boolean;
    } | null;
}

export const FREQUENCY_LABELS: Record<BackupFrequency, string> = {
    daily: 'يومي — كل 24 ساعة',
    weekly: 'أسبوعي',
    monthly: 'شهري',
    manual: 'يدوي فقط',
};

/**
 * الحجمُ بوحدةٍ تُقرأ — لا بايتاتٌ بستّة أرقام.
 *
 * وبالكيلوبايت حدُّه الأدنى: نسخةُ متجرٍ جديد ثلاثةُ كيلوبايتات، و«0 MB»
 * تُقرأ «فارغة» وهي ليست كذلك.
 */
export function formatBytes(bytes: number): string {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * أنُسخت آخرُ نسخةٍ إلى التخزين الخارجيّ؟ — ثلاثةُ أجوبةٍ لا اثنان.
 *
 * «غير مفعّل» غيرُ «لا»: الأوّلُ قرارُ المنصّة، والثاني انقطاعٌ يُسأل عنه.
 * و«غير معروف» لنسخةٍ كُتبت قبل أن يُحفظ الجواب — و«لا» فيها كاذبة.
 */
export function offsiteLabel(status: Pick<BackupStatus, 'offsite_enabled'>, offsite: boolean | null): string {
    if (offsite === true) return 'نعم — نُسخت إلى التخزين الخارجي';
    if (!status.offsite_enabled) return 'التخزين الخارجي غير مفعّل';
    if (offsite === false) return 'لا — بقيت على الخادم وحده';

    return 'غير معروف';
}

/**
 * النسخُ على الخادم — الواجهةُ الأساسيّة للنسخ الاحتياطي.
 *
 * ═══ وترتيبُها ═══
 *
 * زرٌّ أساسيٌّ واحد: «إنشاء نسخة الآن» — يُعرض دائمًا، ولو كان التكرارُ
 * «يدويًّا فقط»: هو عندها الطريقُ الوحيدُ إلى نسخة. ثمّ التكرار. ثمّ آخرُ
 * نسخةٍ ناجحة وإجراءاها الثانويّان: تحميلُها واستعادتُها.
 *
 * وكان بجانبها «تنزيل النسخة الآن» القديم يُنشئ ملفًّا غيرَ مضغوط —
 * بابان لشيءٍ واحد. فرُفع من الشاشة، وبقي الرفعُ من ملفٍّ خارجيّ وحده.
 *
 * ═══ وما لا يُعرض هنا ═══
 *
 * لا مسارُ تخزين ولا اسمُ قرص. التاجرُ يسأل: متى آخرُ نسخة؟ كم حجمُها؟
 * أهي بعيدةٌ عن الخادم؟ وما سوى ذلك يخصّ المشغّل.
 */
export default function BackupStatusPanel({ data }: { data: BackupStatus }) {
    const t = useTranslate();
    const [ask, confirmDialog] = useConfirm();
    const [busy, setBusy] = useState<'create' | 'restore' | 'frequency' | null>(null);
    const [error, setError] = useState<string | null>(null);
    const latest = data.latest;

    const post = (name: string, payload: Record<string, string | boolean>, kind: NonNullable<typeof busy>) => {
        setBusy(kind);
        setError(null);
        router.post(route(name), payload, {
            preserveScroll: true,
            // وما يردّه الخادم (نسخةٌ أكبرُ من الحدّ بعد فكّها) يُقرأ هنا لا يضيع
            onError: (errors) => setError(Object.values(errors)[0] ?? null),
            onFinish: () => setBusy(null),
        });
    };

    const restore = async () => {
        if (!latest || busy) return;
        if (
            !(await ask({
                message:
                    'ستحلّ بيانات آخر نسخة (:date) محلّ بيانات متجرك كلِّها. سيتم إنشاء نسخة أمان داخلية قبل الاستعادة — وإن تعذّرت لا تبدأ الاستعادة. أتمضي؟',
                values: { date: latest.created_at },
                danger: true,
                action: 'استعادة',
            }))
        )
            return;
        // والتأكيدُ يُرسَل: الخادمُ يشترطه لأنّ الشاشة تُتخطّى
        post('admin.backup.latest.restore', { confirm: true }, 'restore');
    };

    return (
        <SettingsSection
            title="النسخ الاحتياطي"
            description="نسخة احتياطية من بيانات النظام وقاعدة بيانات متجرك — لا تشمل الصور والمرفقات. تُحفظ على الخادم مضغوطة، تلقائيًّا بحسب التكرار أو الآن بضغطة."
            icon={DatabaseBackup}
        >
            {confirmDialog}

            <PageActions>
                <Button
                    type="button"
                    onClick={() => post('admin.backup.create', {}, 'create')}
                    disabled={busy !== null}
                >
                    {busy === 'create' ? <Loader2 className="animate-spin" /> : <DatabaseBackup />}
                    {t('إنشاء نسخة الآن')}
                </Button>
            </PageActions>

            <div className="mt-5">
                <Field label="تكرار النسخ التلقائي">
                    <Select
                        value={data.frequency}
                        disabled={busy !== null}
                        aria-label={t('تكرار النسخ التلقائي')}
                        options={data.frequencies.map((f) => ({ value: f, label: FREQUENCY_LABELS[f] }))}
                        onChange={(e) => {
                            if (e.target.value === data.frequency) return;
                            post('admin.backup.frequency', { frequency: e.target.value }, 'frequency');
                        }}
                    />
                </Field>
                {data.frequency === 'manual' && (
                    <p className="mt-3 flex items-start gap-2 rounded-[12px] bg-[#fffbeb] px-3 py-2.5 text-[12px] text-[#92400e]">
                        <Timer className="mt-0.5 size-4 shrink-0" />
                        {t('لن تُحفظ نسخة تلقائيًّا — أنشئها بنفسك بزر «إنشاء نسخة الآن».')}
                    </p>
                )}
            </div>

            <div className="mt-5 border-t border-[#ececec] pt-4">
                <h3 className="mb-3 text-[13px] font-semibold text-[#111]">{t('آخر نسخة ناجحة')}</h3>
                {latest ? (
                    <dl className="grid grid-cols-1 gap-3 text-[13px] sm:grid-cols-3">
                        <div>
                            <dt className="text-[12px] text-[#777]">{t('التاريخ')}</dt>
                            <dd className="font-semibold text-[#111]" dir="ltr">
                                {latest.created_at}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-[12px] text-[#777]">{t('الحجم')}</dt>
                            <dd className="font-semibold text-[#111]" dir="ltr">
                                {formatBytes(latest.bytes)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-[12px] text-[#777]">{t('التخزين الخارجي')}</dt>
                            <dd className="flex items-center gap-1.5 font-semibold text-[#111]">
                                <CloudUpload className="size-4 shrink-0 text-[#777]" />
                                {t(offsiteLabel(data, latest.offsite))}
                            </dd>
                        </div>
                    </dl>
                ) : (
                    <p className="py-2 text-[13px] text-[#777]">{t('لا توجد نسخة محفوظة على الخادم بعد.')}</p>
                )}

                {latest && (
                    <PageActions className="mt-4 flex-wrap">
                        <Button asChild variant="outline">
                            <a href={route('admin.backup.latest.download')}>
                                <Download />
                                {t('تحميل آخر نسخة')}
                            </a>
                        </Button>
                        {/* لصاحب النشاط وحده — والخادمُ يردّ غيرَه ولو ظهر الزرّ */}
                        {data.can_restore !== false && (
                            <Button
                                type="button"
                                variant="outline"
                                className="text-[#b91c1c]"
                                onClick={restore}
                                disabled={busy !== null}
                            >
                                {busy === 'restore' ? <Loader2 className="animate-spin" /> : <RotateCcw />}
                                {t('استعادة آخر نسخة')}
                            </Button>
                        )}
                    </PageActions>
                )}
            </div>

            {error && (
                <p role="alert" className="mt-3 rounded-[12px] bg-[#fef2f2] px-3 py-2.5 text-[12px] text-[#b91c1c]">
                    {error}
                </p>
            )}
        </SettingsSection>
    );
}
