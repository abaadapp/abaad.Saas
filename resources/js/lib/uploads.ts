import { useSyncExternalStore } from 'react';

/**
 * كم صورةً تُرفع الآن — يسأله المحرّرُ قبل أن يحفظ.
 *
 * ═══ ولمَ يُسأل ═══
 *
 * صورةُ هاتفٍ تُصغَّر ثمّ تُرفع في ثوانٍ، ورابطُها يُكتب في الصفحة حين يعود. فمن
 * ضغط «احفظ» قبل أن يعود حُفظت صفحتُه بلا صورتها، وبدت الصورةُ في الشاشة كأنّها
 * حُفظت. فالحفظُ ينتظر ما يُرفع ثمّ يُرسل الصفحةَ بصورتها (`ThemeEditor`).
 */
type Listener = () => void;

let inFlight = 0;
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
