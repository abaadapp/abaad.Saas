/**
 * الأرقام تُكتب إنجليزيّة مهما كانت لغة لوحة المفاتيح.
 *
 * ولوحةُ المفاتيح العربية على الهاتف تكتب «٥٠٠» لا «500»، والتاجر لا يبدّل
 * لغتها لأجل حقل سعر. وحقلُ `type="number"` **يرفض** ما كتبته: المتصفّح يعدّه
 * قيمةً غير صالحة فيُفرغ الحقل — يضغط التاجر ثلاثة أرقام ولا يظهر شيء، ولا
 * رسالةَ تقول له لماذا.
 *
 * فيُبدَّل الحرفُ لحظةَ إدخاله: يُمنع الرقمُ العربيّ من الدخول ويُكتب مكانه
 * نظيرُه اللاتينيّ. والخادم يُصحّح كذلك (انظر `NormalizeNumbers`) لأنّ اللصق
 * والاستيراد وطلبًا من جهازٍ قديم لا تمرّ من هنا — لكنّ التصحيح هناك لا يُرى،
 * وهنا يُرى.
 */

import * as React from 'react';

const MAP: Record<string, string> = {
    "٠": "0",
    "١": "1",
    "٢": "2",
    "٣": "3",
    "٤": "4",
    "٥": "5",
    "٦": "6",
    "٧": "7",
    "٨": "8",
    "٩": "9",
    "۰": "0",
    "۱": "1",
    "۲": "2",
    "۳": "3",
    "۴": "4",
    "۵": "5",
    "۶": "6",
    "۷": "7",
    "۸": "8",
    "۹": "9",
    // الفاصلتان الرقميّتان وحدهما — والفاصلة العربية «،» تردُ في الكلام فلا تُمسّ
    "٫": ".",
    "٬": ",",
};

const ARABIC = /[٠-٩۰-۹٫٬]/;

/**
 * الفاصلة العربية «،» — نقطةٌ عشريّة في حقل رقم، وعلامةُ ترقيمٍ في غيره.
 *
 * ولوحةُ المفاتيح العربية تُخرجها حيث تُخرج الإنجليزيةُ «,»، فهي ما يضغطه
 * التاجر وهو يعني الفاصل العشريّ. وكان الحقل يرفضها بلا صوت: يكتب «4،5»
 * فلا يظهر إلّا «4»، ولا شيء يقول له لماذا.
 *
 * ولا تُبدَّل في كلّ حقل: «اشتريت وردًا، ثمّ عدت» جملةٌ لا رقم.
 */
const ARABIC_COMMA = '،';

/** حروفٌ يقبلها حقلٌ عشريّ — وما عداها كان حقلُ الأرقام يمنعه قبل أن يُرسم نصًّا */
const DECIMAL_CHAR = /[0-9.\-]/;

/** هل هذا الحقل عشريّ؟ — انظر `Input`: يُرسم نصًّا بلوحةٍ عشريّة */
function isDecimalField(el: HTMLInputElement | HTMLTextAreaElement): boolean {
    return el.getAttribute('inputmode') === 'decimal';
}

/**
 * ما يُكتب فعلًا في حقلٍ عشريّ من ضغطةٍ واحدة.
 *
 * تُبدَّل الأرقام والفاصلتان العربيّتان إلى نقطة، ويُسقَط ما ليس رقمًا ولا
 * نقطةً ولا إشارةً — فيبقى الحقل نظيفًا كما كان يوم كان `type="number"`.
 * ونقطةٌ ثانية تُسقَط: «4.5.6» ليست رقمًا.
 */
function decimalInsert(el: HTMLInputElement | HTMLTextAreaElement, data: string): string {
    const hasDot = el.value.includes('.');

    let out = '';

    for (const raw of toAsciiDigits(data).replace(new RegExp(ARABIC_COMMA, 'g'), '.')) {
        const ch = raw === ',' ? '.' : raw;

        if (!DECIMAL_CHAR.test(ch)) {
            continue;
        }

        if (ch === '.' && (hasDot || out.includes('.'))) {
            continue;
        }

        out += ch;
    }

    return out;
}

/** الأرقام إنجليزيّة، وما عداها كما هو */
export function toAsciiDigits(value: string): string {
    return ARABIC.test(value)
        ? value.replace(/[٠-٩۰-۹٫٬]/g, (c) => MAP[c] ?? c)
        : value;
}

export function hasArabicDigits(value: string): boolean {
    return ARABIC.test(value);
}

/**
 * يُركّب الحارس على عنصر إدخال — ويُعيد ما يفكّه.
 *
 * والاستماع للحدث الأصليّ لا لحدث React: `beforeinput` الأصليّ وحده يقع قبل
 * أن يرى المتصفّحُ الحرف، وهو الموضع الوحيد الذي يمكن فيه منعُ حقل الأرقام
 * من رفض «٥» وإفراغ نفسه.
 *
 * ولا يتدخّل إلّا حين يُكتب رقمٌ عربيّ: كلُّ ضغطةٍ أخرى تمرّ كما كانت، فلا
 * يُغيَّر سلوك الكتابة في النظام كلّه لأجل حالةٍ واحدة.
 */
export function guardAsciiDigits(
    el: HTMLInputElement | HTMLTextAreaElement,
): () => void {
    const onBeforeInput = (event: Event) => {
        const data = (event as InputEvent).data;

        if (!data) {
            return;
        }

        /*
         * والحقلُ العشريّ يُنقّى حرفًا حرفًا: هو نصٌّ في الرسم، فلو تُرك على
         * حاله لَقبِل «أبجد» في خانة سعر — وهو ما كان `type="number"` يمنعه.
         */
        if (isDecimalField(el)) {
            const clean = decimalInsert(el, data);

            if (clean === data) {
                return;
            }

            event.preventDefault();

            if (clean !== '') {
                insert(el, clean);
            }

            return;
        }

        if (!hasArabicDigits(data)) {
            return;
        }

        event.preventDefault();
        insert(el, toAsciiDigits(data));
    };

    el.addEventListener("beforeinput", onBeforeInput);

    return () => el.removeEventListener("beforeinput", onBeforeInput);
}

/**
 * يكتب النصّ مكان ما مُنع.
 *
 * و`setRangeText` هي الطريق حين يُتاح: تحترم موضع المؤشّر والتحديد. لكنّ
 * `input[type=number]` لا يملك تحديدًا أصلًا — تُلقي الدالّة عليه — فيُلحق
 * النصُّ بآخره. وهو الصواب العمليّ: الأرقام تُكتب من أوّلها إلى آخرها.
 *
 * ثمّ يُطلق حدث `input` بعد الكتابة بالضابط الأصليّ للقيمة: React يتجاهل
 * تعديلًا يقع على العنصر مباشرةً، فيبقى الحقلُ المضبوط عارضًا قيمته القديمة.
 */
function insert(
    el: HTMLInputElement | HTMLTextAreaElement,
    text: string,
): void {
    let next: string;
    let caret: number | null = null;

    try {
        const start = el.selectionStart ?? el.value.length;
        const end = el.selectionEnd ?? el.value.length;
        next = el.value.slice(0, start) + text + el.value.slice(end);
        caret = start + text.length;
    } catch {
        next = el.value + text;
    }

    setValue(el, next);

    if (caret !== null) {
        try {
            el.setSelectionRange(caret, caret);
        } catch {
            /* حقل الأرقام لا يقبل تحديدًا — والمؤشّر يقف في آخره وحده */
        }
    }

    el.dispatchEvent(new Event("input", { bubbles: true }));
}

/** الضابط الأصليّ للقيمة — يتخطّى ضابط React فيرى الحدثَ التالي القيمةَ الجديدة */
function setValue(
    el: HTMLInputElement | HTMLTextAreaElement,
    value: string,
): void {
    const prototype =
        el instanceof HTMLTextAreaElement
            ? HTMLTextAreaElement.prototype
            : HTMLInputElement.prototype;
    const setter = Object.getOwnPropertyDescriptor(prototype, "value")?.set;

    if (setter) {
        setter.call(el, value);

        return;
    }

    el.value = value;
}

/**
 * يُركّب الحارس على حقلٍ في React، ويُبقي المرجع الخارجيّ يعمل كما كان.
 *
 * وهو المصدر الوحيد: `Input` و`Textarea` تستعملانه، وكذلك الحقول القليلة
 * المرسومة بـ`<input>` مباشرةً لأنّ لها شكلًا خاصًّا (بحثُ الجدول، وتحرير
 * الكميّة في مكانها). ولو كتب كلٌّ منها حارسَه لَافترقت يومًا.
 */
export function useAsciiDigits<T extends HTMLInputElement | HTMLTextAreaElement>(
    ref?: React.ForwardedRef<T> | React.RefObject<T | null> | null,
): (el: T | null) => void {
    const detach = React.useRef<(() => void) | null>(null);

    return React.useCallback(
        (el: T | null) => {
            detach.current?.();
            detach.current = el ? guardAsciiDigits(el) : null;

            if (typeof ref === 'function') {
                ref(el);
            } else if (ref) {
                ref.current = el;
            }
        },
        [ref],
    );
}
