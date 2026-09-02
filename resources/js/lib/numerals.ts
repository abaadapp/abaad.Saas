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

        if (!data || !hasArabicDigits(data)) {
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
