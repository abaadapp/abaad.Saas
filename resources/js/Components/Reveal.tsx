import { motion, type HTMLMotionProps } from 'framer-motion';
import { DUR, EASE } from '@/lib/motion';

interface RevealProps extends HTMLMotionProps<'div'> {
    /** تأخير الظهور بالثواني — لتدريج ظهور قائمةٍ يدويًّا (index * 0.05). */
    delay?: number;
}

/**
 * ظهورٌ بتلاشٍ وانزلاقٍ طفيف للأعلى عند التركيب — للبطاقات والأقسام.
 *
 * حركةٌ واحدة هادئة في مكان واحد بدل تكرار initial/animate في كل صفحة.
 * تحترم «تقليل الحركة» تلقائيًّا عبر MotionConfig في جذر التطبيق: فيصير
 * الظهور فوريًّا بلا إزاحةٍ لمن طلب ذلك في نظامه.
 */
export default function Reveal({ delay = 0, children, ...props }: RevealProps) {
    return (
        <motion.div
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: DUR.slow, delay, ease: EASE }}
            {...props}
        >
            {children}
        </motion.div>
    );
}
