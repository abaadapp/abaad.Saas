import { useSyncExternalStore } from 'react';

/**
 * كم صورةً تُرفع الآن — يسأله المحرّرُ قبل أن يحفظ.
 *
 * ═══ ولمَ يُسأل ═══
 *
 * صورةُ هاتفٍ تُصغَّر ثمّ تُرفع في ثوانٍ، ورابطُها يُكتب في الصفحة حين يعود. فمن
 * ضغط «احفظ» قبل أن يعود حُفظت صفحتُه بلا صورتها، وبدت الصورةُ في الشاشة كأنّها
 * حُفظت. فالحفظُ ينتظر ما يُرفع ثمّ يُرسل الصفحةَ بصورتها (`ThemeEditor`).
 *
 * والرفعُ يُعدّ من لحظة اختيار الصورة — التصغيرُ على الهاتف ثوانٍ قبل أن يُرسل
 * شيء — ويُغلَق حين تستقرّ في الصفحة، لا حين يعود الخادم (`StoreImageField`).
 *
 * وصورةٌ لم تُرفع يبقى حقلُها في `failing` حتى يُعاد رفعُها أو يُزال: فلا يقول
 * المحرّرُ «حُفظ» وفي الصفحة صورةٌ يراها صاحبُها ولم تصل.
 */
type Listener = () => void;

let inFlight = 0;
const failing = new Set<object>();
const listeners = new Set<Listener>();
const emit = () => listeners.forEach((l) => l());

export const uploads = {
    /** يبدأ رفعًا — ويُعيد ما يُنهيه، مرّةً واحدةً مهما نودي */
    begin(): () => void {
        inFlight++;
        emit();
        let done = false;

        return () => {
            if (done) return;
            done = true;
            inFlight = Math.max(0, inFlight - 1);
            emit();
        };
    },
    count: (): number => inFlight,
    /** حقلٌ لم تُرفع صورتُه (`true`) — أو أُعيد رفعُها أو أُزيل (`false`) */
    failed(field: object, yes: boolean): void {
        if (failing.has(field) === yes) return;
        if (yes) failing.add(field);
        else failing.delete(field);
        emit();
    },
    failures: (): number => failing.size,
    subscribe(listener: Listener): () => void {
        listeners.add(listener);

        return () => {
            listeners.delete(listener);
        };
    },
};

export function useUploadsInFlight(): number {
    return useSyncExternalStore(uploads.subscribe, uploads.count, uploads.count);
}

export function useUploadFailures(): number {
    return useSyncExternalStore(uploads.subscribe, uploads.failures, uploads.failures);
}
