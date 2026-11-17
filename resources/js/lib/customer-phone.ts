/**
 * رقمُ هاتف العميل — مرآةُ `App\Support\CustomerPhone` حرفًا بحرف.
 *
 * العُمانيّ ثماني خاناتٍ بلا مفتاح الدولة، ويُكتب «91234567» أو «+968…» أو
 * «00968…»، بالأرقام العربيّة أو اللاتينيّة، بمسافاتٍ وشرطات. والدوليّ يُقرأ
 * من مفتاحه ولا يُفرض عليه الثماني. والخادمُ هو المرجع: هذا لتنبيه الكاتب
 * قبل أن يحفظ، لا بدلًا من القاعدة. والحالاتُ المشتركة يقرؤها الطرفان من
 * `tests/fixtures/customer-phone-cases.json`.
 */
export type PhoneProblem = 'missing' | 'short' | 'long' | 'chars' | 'foreign' | 'nocode' | 'unmarked';
export type PhoneStatus = 'missing' | 'incomplete' | 'invalid' | 'country';

const OMAN = '968';
const OMAN_DIGITS = 8;
const WALK_IN = 'عميل نقدي';
/** دولٌ رقمُها الدوليُّ كلُّه ثماني خانات — كـ`CustomerPhone::EIGHT_DIGIT_CODES` */
const EIGHT_DIGIT_CODES = ['290', '500', '676', '677', '682', '686', '688'];
const INVISIBLE = /[\u00A0\u2000-\u200F\u202A-\u202F\u205F\u2066-\u2069\u3000\uFEFF]/g;

/** الأرقامُ العربيّة (٠-٩) والهنديّة الفارسيّة (۰-۹) لاتينيّةً — كـ`Digits::western` */
const western = (s: string): string =>
    s.replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660)).replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0));

const omani = (local: string): PhoneProblem | null =>
    local.length < OMAN_DIGITS ? 'short' : local.length > OMAN_DIGITS ? 'long' : null;

const foreign = (digits: string): PhoneProblem | null => {
    if (digits.startsWith('0') || digits.length < 8 || digits.length > 15) return 'foreign';
    if (digits.length === 8) return EIGHT_DIGIT_CODES.includes(digits.slice(0, 3)) ? null : 'nocode';
    return null;
};

/**
 * وأكثرُ من ثماني خاناتٍ بلا «+» ولا «00» ولا «968» لا تُعرف دولتُه (`unmarked`):
 * يُردّ إن كُتب الآن، وحالتُه في المراجعة «يحتاج تأكيد الدولة» — لا خطأ.
 */
export function phoneProblem(raw: string | null | undefined): PhoneProblem | null {
    const text = western((raw ?? '').replace(INVISIBLE, ' ').trim());
    if (text === '') return 'missing';

    if (!/^\+?\s*[0-9(][0-9\s().-]*$/.test(text) || !/[0-9]/.test(text)) return 'chars';

    const digits = text.replace(/\D+/g, '');

    if (text.startsWith('+') || digits.startsWith('00')) {
        const international = text.startsWith('+') ? digits : digits.slice(2);
        return international.startsWith(OMAN) ? omani(international.slice(OMAN.length)) : foreign(international);
    }

    if (digits.length > 9 && digits.startsWith(OMAN)) return omani(digits.slice(OMAN.length));

    if (digits.length > OMAN_DIGITS) return 'unmarked';

    return omani(digits);
}

/** نصُّ الرفض — مفتاحٌ يُترجم، أو `null` لرقمٍ صالحٍ أو حقلٍ فارغ */
export function phoneRefusal(raw: string | null | undefined): string | null {
    switch (phoneProblem(raw)) {
        case null:
        case 'missing':
            return null;
        case 'short':
        case 'long':
            return 'رقم الهاتف العُماني يجب أن يتكون من 8 أرقام.';
        case 'unmarked':
            return 'رقم الهاتف العُماني يجب أن يتكون من 8 أرقام. أمّا الرقم الدولي فيُكتب بـ + أو 00 ثمّ مفتاح الدولة، مثل +971501234567 أو 00971501234567.';
        case 'nocode':
            return 'هذا رقمٌ عُمانيٌّ بلا مفتاحه — اكتبه +968 ثمّ الأرقام الثمانية، أو اكتب الرقم الدولي كاملًا بمفتاح دولته.';
        case 'chars':
            return 'رقم الهاتف لا يقبل إلّا الأرقام والمسافات والشرطة، و+ في أوّله.';
        default:
            return 'الرقم الدولي غير صحيح — بعد + أو 00 يُكتب مفتاح الدولة ثمّ الرقم، من 9 إلى 15 خانة، مثل +971501234567.';
    }
}

/** حالةُ العميل في المراجعة — كـ`CustomerPhone::status` */
export function phoneStatus(phone: string | null | undefined, contactPhone?: string | null, name?: string | null): PhoneStatus | null {
    const raw = phone && phone.trim() !== '' ? phone : contactPhone;
    const problem = phoneProblem(raw);
    if (problem === 'missing' && (name ?? '').trim() === WALK_IN) return null;
    if (problem === null) return null;
    if (problem === 'missing') return 'missing';
    if (problem === 'unmarked') return 'country';
    return problem === 'short' ? 'incomplete' : 'invalid';
}

export const PHONE_STATUS_LABEL: Record<PhoneStatus, string> = {
    missing: 'رقم مفقود',
    incomplete: 'رقم غير مكتمل',
    invalid: 'رقم غير صحيح',
    country: 'رقم يحتاج تأكيد الدولة',
};
