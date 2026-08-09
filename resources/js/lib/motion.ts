import type { Transition } from 'framer-motion';

/**
 * ثوابت الحركة الموحّدة لكل النظام.
 *
 * منحنىً واحد ومددٌ واحدة في مكان واحد — فتتّسق كل الحركات بدل أن تخترع
 * كل شاشة توقيتها. القيم منقولة عن التوقيع القائم في StatCard واللوحة
 * (خروجٌ ناعم، ٠٫٢–٠٫٣ ثانية) كي لا يتبدّل الإحساس المألوف.
 *
 * احترام «تقليل الحركة» لا يُضبط هنا بل مرّةً واحدة في جذر التطبيق عبر
 * <MotionConfig reducedMotion="user">، فيسري على كل مكوّنات framer-motion.
 */
export const EASE = [0.22, 1, 0.36, 1] as const;

/** مددٌ قياسية بالثواني — ضِمن ١٥٠–٣٠٠ms المطلوبة. */
export const DUR = {
    fast: 0.15,
    base: 0.22,
    slow: 0.3,
} as const;

/** انتقالٌ ناعم موحّد (تمدّد/انطواء، تبدّل مكوّن…). */
export const TRANSITION: Transition = { duration: DUR.base, ease: EASE };
