import type { PrepSchedule } from './types';

/**
 * قراءةُ الموعد — بلا أن يُفسَّر تاريخٌ في المتصفّح.
 *
 * ═══ العطب الذي تمنعه ═══
 *
 * `new Date('2026-09-23 14:00')` ليست صيغةً قياسيّة: المحرّكات تقرؤها
 * بالتوقيت المحليّ للجهاز. ولوحةُ التجهيز تعمل على أجهزةٍ توضع على الطاولة
 * وتُشغَّل ولا أحد يفتح إعداداتِها — فيقرأ من يجهّز «بعد ساعتين» لطلبٍ فات
 * موعدُه بساعة، ولا شيء في الشاشة يقول إنّ الساعتين كذب.
 *
 * فالخادم يحسب الدقائقَ الباقية بتوقيت التاجر ويرسلها عددًا، والشاشة تطرح
 * منها ما انقضى منذ وصولها. لا `Date.parse` ولا منطقةَ زمن ولا تقويم.
 *
 * ═══ والانقضاءُ يُقاس لا يُفترض ═══
 *
 * اللوحة تستطلع كلّ عشرين ثانية، لكنّ الاستطلاع يقف حين يُخفى التبويب.
 * فشاشةٌ تُركت ساعةً ثمّ عاد إليها من يجهّز تعرض دقائقَ عمرُها ساعة — لولا
 * أن يُطرح المنقضي. و`ageMs` يأتي من ساعة الجهاز نفسِه فرقًا بين لحظتين،
 * وفرقُ لحظتين على ساعةٍ واحدة صادقٌ وإن كانت الساعةُ مضبوطةً على غير
 * وقتها.
 */
export function minutesLeft(schedule: PrepSchedule | null, ageMs: number): number | null {
    if (!schedule) return null;

    return schedule.minutes_left - Math.floor(ageMs / 60000);
}

/** درجةُ الإلحاح — ثلاثُ درجاتٍ لا لونان */
export type Urgency = 'late' | 'soon' | 'later';

/** ما دون ساعةٍ «عاجل»: وقتُ صنع باقةٍ وتغليفِها ووضعِها في الصندوق */
export const SOON_MINUTES = 60;

export function urgencyOf(minutes: number | null): Urgency {
    if (minutes === null) return 'later';
    if (minutes < 0) return 'late';

    return minutes <= SOON_MINUTES ? 'soon' : 'later';
}

/**
 * المدّةُ كلامًا: «متأخّر ٣ س ٢٠ د» أو «خلال ٤٥ د».
 *
 * وتُردّ مفتاحًا وأجزاءً لا نصًّا جاهزًا: الترجمةُ في الشاشة كسائر نصوصها،
 * ولو رُكّب النصُّ هنا لَبقي عربيًّا في واجهةٍ إنجليزية.
 */
export function spanOf(minutes: number | null): { key: string; hours: number; minutes: number } | null {
    if (minutes === null) return null;

    const abs = Math.abs(minutes);

    return {
        key: minutes < 0 ? 'late' : 'left',
        hours: Math.floor(abs / 60),
        minutes: abs % 60,
    };
}
