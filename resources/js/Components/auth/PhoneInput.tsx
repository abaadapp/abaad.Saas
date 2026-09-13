import { useAsciiDigits } from '@/lib/numerals';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * رقمُ الجوّال — مفتاحُ الدولة إلى جانبه، لا مدفونًا في نصٍّ حرّ.
 *
 * ═══ ولمَ قائمةٌ قصيرة ═══
 *
 * أبعادٌ تعمل في الخليج، وافتراضُ النظام كلِّه عُمان: `businesses.country`
 * تبدأ بها، و`whatsapp.default_country_code` تساوي 968. فقائمةٌ بمئتي دولةٍ
 * تجعل المستخدم يمرّر عشرين مرّةً ليجد ما هو أمامه أصلًا.
 *
 * والقائمةُ تتوسّع بسطرٍ حين يُفتح سوقٌ جديد — لا ببناء مكوّنٍ ثانٍ.
 *
 * ═══ والأرقامُ لاتينيّةٌ مهما كانت لوحة المفاتيح ═══
 *
 * لوحةُ المفاتيح العربيّة على الهاتف تكتب «٩١٢٣٤٥٦٧»، و`WhatsAppPhone`
 * تُصحّحها على الخادم — لكنّ التصحيحَ هناك لا يُرى. و`useAsciiDigits` تبدّل
 * الحرفَ لحظةَ إدخاله فيرى المستخدم ما سيُحفظ.
 *
 * ═══ والاتّجاهُ لاتينيٌّ دائمًا ═══
 *
 * رقمُ هاتفٍ يُقرأ من اليسار في اللغتين. وحقلٌ يتبع اتّجاهَ الصفحة يضع
 * المفتاحَ «968+» في طرفٍ والرقمَ في آخر، فيُقرأ رقمين لا رقمًا.
 */

const COUNTRIES = [
    { code: '968', flag: '🇴🇲', name: 'عُمان' },
    { code: '971', flag: '🇦🇪', name: 'الإمارات' },
    { code: '966', flag: '🇸🇦', name: 'السعودية' },
    { code: '974', flag: '🇶🇦', name: 'قطر' },
    { code: '973', flag: '🇧🇭', name: 'البحرين' },
    { code: '965', flag: '🇰🇼', name: 'الكويت' },
    { code: '967', flag: '🇾🇪', name: 'اليمن' },
];

interface Props {
    id?: string;
    /** مفتاحُ الدولة بلا «+» — «968» */
    dial: string;
    onDialChange: (dial: string) => void;
    value: string;
    onChange: (value: string) => void;
    autoFocus?: boolean;
    className?: string;
}

export default function PhoneInput({
    id,
    dial,
    onDialChange,
    value,
    onChange,
    autoFocus,
    className,
}: Props) {
    const t = useTranslate();
    const attach = useAsciiDigits<HTMLInputElement>();

    return (
        <div
            dir="ltr"
            className={cn(
                'flex h-11 items-stretch overflow-hidden rounded-[10px] border border-[#e0e0e0] bg-white',
                'focus-within:border-[#111] focus-within:ring-2 focus-within:ring-[#111]/10',
                className,
            )}
        >
            {/*
                القائمةُ عنصرُ `select` خام — لا قائمةٌ مبنيّةٌ بيد.

                لوحةُ المفاتيح تفتحها، والهاتفُ يعرضها بعجلته هو، وقارئُ
                الشاشة يعرفها. ومكوّنٌ مبنيٌّ بيدٍ يحتاج الثلاثةَ من جديد.
            */}
            <select
                aria-label={t('مفتاح الدولة')}
                value={dial}
                onChange={(e) => onDialChange(e.target.value)}
                className="h-full cursor-pointer border-e border-[#eee] bg-[#fafafa] px-2.5 text-[13px] text-[#4b4b4b] outline-none"
            >
                {COUNTRIES.map((c) => (
                    <option key={c.code} value={c.code}>
                        {c.flag} +{c.code}
                    </option>
                ))}
            </select>

            <input
                id={id}
                name="phone"
                type="tel"
                inputMode="tel"
                autoComplete="tel"
                autoFocus={autoFocus}
                required
                placeholder="9123 4567"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                ref={attach}
                className="h-full min-w-0 flex-1 bg-transparent px-3 text-[14px] text-[#111] outline-none placeholder:text-[#c4c4c4]"
            />
        </div>
    );
}
