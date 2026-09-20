import { cn } from '@/lib/utils';

/**
 * لغةُ رسائل واتساب للزبون — اختيارٌ إجباريٌّ بين اثنتين، لا قائمةٌ تُترك.
 *
 * زرّان لا قائمةٌ منسدلة: القائمةُ تُفتح وتُغلق بلا اختيار ويمرّ الكاشير،
 * أمّا زرّان لا لونَ على أحدهما فيُرى الفراغُ قبل الحفظ. ولا افتراضيَّ:
 * افتراضٌ عربيٌّ يجعل الهنديَّ يستلم ما لا يقرأ ولا أحدَ سُئل.
 *
 * والخيارات كما في `WhatsAppEvent::LANGUAGES` — والخادمُ يردّ سواهما.
 */
export const LANGUAGE_OPTIONS = [
    { label: 'العربية', value: 'ar' },
    { label: 'English', value: 'en' },
] as const;

interface Props {
    value: string;
    onChange: (value: string) => void;
    /** للنماذج التي تُقرأ بـ`FormData` — يُرفع حقلٌ مخفيٌّ بهذا الاسم */
    name?: string;
    className?: string;
}

export default function LanguageChoice({ value, onChange, name, className }: Props) {
    return (
        <div className={cn('grid grid-cols-2 gap-2', className)} role="radiogroup">
            {name && <input type="hidden" name={name} value={value} />}
            {LANGUAGE_OPTIONS.map((o) => {
                const active = value === o.value;
                return (
                    <button
                        key={o.value}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        onClick={() => onChange(o.value)}
                        className={cn(
                            'h-10 rounded-md border text-sm font-medium transition-colors',
                            active
                                ? 'border-[#6d28d9] bg-[#f5f3ff] text-[#6d28d9]'
                                : 'border-[#e5e7eb] bg-white text-[#4b4b4b] hover:bg-[#f9fafb]',
                        )}
                    >
                        {o.label}
                    </button>
                );
            })}
        </div>
    );
}
