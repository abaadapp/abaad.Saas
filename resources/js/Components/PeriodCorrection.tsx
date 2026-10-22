import { AlertTriangle } from 'lucide-react';
import Field from '@/Components/Field';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * تصحيحُ قيدٍ من شهرٍ غير الشهر الجاري — يُسأل عنه قبل الإرسال.
 *
 * يصل من الخادم (`BooksPeriod::describe`): `crosses` حين يعبر التصحيحُ حدَّ
 * شهر، و`original_allowed` حين تكون فترةُ القيد مفتوحةً معلومةً والمستخدمُ
 * صاحبَ النشاط. والخادمُ يتحقّق من الثلاثة ثانيةً — هذا سؤالٌ لا حارس.
 */
export interface Correction {
    crosses: boolean;
    entry_date: string | null;
    status: 'open' | 'closed' | 'unknown' | null;
    original_allowed: boolean;
    closed_through: string | null;
}

export interface CorrectionValue {
    correction: '' | 'current' | 'original';
    correction_reason: string;
}

export const EMPTY_CORRECTION: CorrectionValue = { correction: '', correction_reason: '' };

/** هل اكتمل ما يُرسَل؟ — اختيارٌ وسببٌ حين يعبر التصحيحُ حدَّ شهر */
export const correctionReady = (c: Correction | undefined, v: CorrectionValue): boolean =>
    !c?.crosses || (v.correction !== '' && v.correction_reason.trim() !== '');

export default function PeriodCorrection({
    correction,
    value,
    onChange,
    errors,
}: {
    correction: Correction;
    value: CorrectionValue;
    onChange: (v: CorrectionValue) => void;
    errors?: Record<string, string | undefined>;
}) {
    const t = useTranslate();

    if (!correction.crosses) return null;

    const why =
        correction.status === 'closed'
            ? t('الدفاتر مقفلة حتى :date، فلا يُكتب في تلك الفترة شيء.', { date: correction.closed_through ?? '' })
            : correction.status === 'unknown'
              ? t('لم يُحدَّد تاريخ إقفال الدفاتر في الإعدادات، فلا يُفترض أنّ شهرًا مضى مفتوح.')
              : t('التسجيل بتاريخ فترةٍ سابقة لصاحب النشاط وحده.');

    const options: { key: 'current' | 'original'; label: string; hint: string; disabled: boolean }[] = [
        {
            key: 'current',
            label: t('في الفترة الحاليّة'),
            hint: t('يُسجَّل التصحيح بتاريخ اليوم، ويبقى الشهر السابق كما صدر.'),
            disabled: false,
        },
        {
            key: 'original',
            label: t('بتاريخ القيد الأصلي (:date)', { date: correction.entry_date ?? '' }),
            hint: correction.original_allowed ? t('يُصحَّح الشهر السابق نفسه، ولا يتأثّر الشهر الحالي.') : why,
            disabled: !correction.original_allowed,
        },
    ];

    return (
        <div className="space-y-3 rounded-[10px] border border-[#fde68a] bg-[#fffbeb] p-3" data-testid="period-correction">
            <p className="flex items-start gap-2 text-[12.5px] text-[#92400e]">
                <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                {t('قيدُ هذا المصروف بتاريخ :date، في شهرٍ غير الشهر الجاري. اختر أين يُسجَّل التصحيح، واكتب سببه.', {
                    date: correction.entry_date ?? '',
                })}
            </p>
            <div className="grid gap-2">
                {options.map((o) => (
                    <label
                        key={o.key}
                        className={cn(
                            'flex cursor-pointer items-start gap-2 rounded-[8px] border bg-white p-2.5 text-[13px]',
                            value.correction === o.key ? 'border-[#111827]' : 'border-[#e5e7eb]',
                            o.disabled && 'cursor-not-allowed opacity-60',
                        )}
                    >
                        <input
                            type="radio"
                            name="correction"
                            className="mt-1"
                            checked={value.correction === o.key}
                            disabled={o.disabled}
                            onChange={() => onChange({ ...value, correction: o.key })}
                        />
                        <span>
                            <span className="block font-medium text-[#111827]">{o.label}</span>
                            <span className="block text-[12px] text-[#6b7280]">{o.hint}</span>
                        </span>
                    </label>
                ))}
            </div>
            {errors?.correction && <p className="text-[12px] text-[#b91c1c]">{errors.correction}</p>}
            <Field label={t('سبب التصحيح')} error={errors?.correction_reason} required>
                <textarea
                    rows={2}
                    maxLength={200}
                    value={value.correction_reason}
                    onChange={(e) => onChange({ ...value, correction_reason: e.target.value })}
                    className="w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2 text-sm transition-[border-color,box-shadow] focus:border-[#d1d5db] focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)] focus:outline-none"
                />
            </Field>
        </div>
    );
}
