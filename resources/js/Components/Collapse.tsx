import type { ReactNode } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import { DUR, EASE } from '@/lib/motion';

interface CollapseProps {
    /** مفتوح؟ عند التبدّل يتمدّد المحتوى أو ينطوي بارتفاعٍ متحرّك. */
    open: boolean;
    className?: string;
    children: ReactNode;
}

/**
 * تمدّد/انطواء ناعم بارتفاعٍ تلقائي — لقسمٍ يظهر ويختفي.
 *
 * overflow-hidden كي لا يتسرّب المحتوى وهو ينمو من ارتفاعٍ صفر. AnimatePresence
 * يبقي العنصر حتى تكتمل حركة الانطواء بدل أن يُقتلع فجأة. يحترم «تقليل الحركة»
 * عبر MotionConfig فيصير الظهور/الاختفاء فوريًّا.
 */
export default function Collapse({ open, className, children }: CollapseProps) {
    return (
        <AnimatePresence initial={false}>
            {open && (
                <motion.div
                    key="content"
                    initial={{ height: 0, opacity: 0 }}
                    animate={{ height: 'auto', opacity: 1 }}
                    exit={{ height: 0, opacity: 0 }}
                    transition={{ duration: DUR.base, ease: EASE }}
                    style={{ overflow: 'hidden' }}
                    className={className}
                >
                    {children}
                </motion.div>
            )}
        </AnimatePresence>
    );
}
