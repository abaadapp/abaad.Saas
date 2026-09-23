import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * ما وصل بعد أن فُتحت الشاشة — ونغمةٌ تقوله.
 *
 * ═══ أوّلُ تحميلٍ ليس وصولًا ═══
 *
 * من يفتح اللوحة صباحًا وعليها أربعون طلبًا لم يصله أربعون طلب. فالتحميلُ
 * الأوّل خطُّ أساسٍ يُسجَّل بلا تنبيه، والتنبيهُ لما جاء بعده.
 *
 * ═══ والمرئيُّ سابقًا ليس جديدًا ولو غاب ═══
 *
 * المجموعةُ تتراكم ولا تُستبدل: من ينتقل من «اليوم» إلى «غدًا» ثم يعود لا
 * يُنبَّه على ما رآه قبل دقيقة. ولو كانت تُستبدل بكلّ حمولةٍ لَصار كلُّ تبديل
 * مرشّحٍ رنينًا وشارةً حمراء — فيتعلّم من يجهّز أن يتجاهلها، وتصير عديمةَ
 * المعنى يوم يصل طلبٌ حقيقيّ.
 *
 * ═══ والنغمةُ مرّةً للطلب الواحد — من المجموعة نفسِها ═══
 *
 * الطلب يبقى على اللوحة حتى يُجهَّز، فلا يجوز أن تُعاد نغمتُه في كلّ استطلاع.
 * ولا حارسَ ثانٍ لذلك: `seen` تتراكم ولا تُستبدل، فما كان في `arrivals` لم
 * يُرَ قطّ — ومن لم يُرَ لم يُنبَّه عليه. وكانت هنا مجموعةُ `alerted` ثانية
 * تحرس ما تحرسه الأولى: أثبتت الطفرةُ أنّ تعطيلَها لا يُسقط اختبارًا، لأنّ
 * سطرَها لا يُبلَغ أصلًا. وحارسٌ لا يُبلَغ ليس أمانًا زائدًا — هو سطرٌ يُقرأ
 * على أنّه يحمي شيئًا فلا يُفحص ما تحته.
 *
 * ═══ ولا صوتَ قبل أن يُطلَب ═══
 *
 * المتصفّحات تمنع الصوت قبل تفاعلٍ من المستخدم، ومحاولتُه تُلقي استثناءً لا
 * تُصدر صوتًا. فالمفتاحُ يُشغَّل بضغطةٍ — وهي التفاعلُ نفسُه — و`AudioContext`
 * يُنشأ حينها لا قبلها.
 */
export default function useNewArrivals(numbers: string[], sound: boolean) {
    const seen = useRef<Set<string> | null>(null);
    const audio = useRef<AudioContext | null>(null);
    const [fresh, setFresh] = useState<string[]>([]);

    const beep = useCallback(() => {
        try {
            const Ctor = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
            if (!Ctor) return;
            audio.current ??= new Ctor();
            const ctx = audio.current;
            void ctx.resume();

            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.frequency.value = 880;
            // نغمةٌ خافتةٌ قصيرة: تنبيهٌ عند الطاولة لا جرسُ إنذار
            gain.gain.setValueAtTime(0.0001, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.08, ctx.currentTime + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.35);
            osc.connect(gain).connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.36);
        } catch {
            // جهازٌ يمنع الصوت أو سياقٌ لم يُفتح — الشارةُ وحدها تكفي
        }
    }, []);

    useEffect(() => {
        if (seen.current === null) {
            seen.current = new Set(numbers);

            return;
        }

        const arrivals = numbers.filter((n) => !seen.current!.has(n));
        for (const n of numbers) seen.current.add(n);

        if (arrivals.length === 0) return;

        setFresh((prev) => [...new Set([...prev, ...arrivals])]);

        if (sound) beep();
    }, [numbers, sound, beep]);

    /** يُنادى حين يقرّ من يجهّز أنّه رآها — فتُطفأ الشارة ولا تتراكم */
    const acknowledge = useCallback(() => setFresh([]), []);

    /** وتفعيلُ الصوت نفسُه تفاعلٌ — فتُجرَّب النغمة عنده ليُعرف أنّه يعمل */
    const armSound = useCallback(() => beep(), [beep]);

    return { fresh, acknowledge, armSound };
}
