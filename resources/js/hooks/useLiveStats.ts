import { useEffect, useState } from 'react';
import type { Stat } from '@/Components/StatCard';

interface Payload {
    stats: Stat[];
    updated_at?: string;
}

/**
 * تحديث حيّ لبطاقات الإحصاء — بديل `window.liveStats` الذي كان في app.js.
 *
 * يستطلع نقطة الإحصاءات كل 15 ثانية ويعيد آخر قائمة وصلت، فتبقى الأرقام
 * حيّة بلا إعادة تحميل. القيم الأولية تأتي من الخادم مع الصفحة، فلا تومض
 * اللوحة فارغة قبل أول استجابة.
 *
 * يتوقف الاستطلاع عندما يكون التبويب مخفيًا: لا فائدة من طلبات لا يراها أحد.
 */
export default function useLiveStats(url: string, initial: Stat[], intervalMs = 15000) {
    const [stats, setStats] = useState<Stat[]>(initial);
    const [updatedAt, setUpdatedAt] = useState<string | null>(null);

    // بيانات الخادم هي المرجع بعد كل تنقّل — تتجاوز ما استطلعناه للصفحة السابقة
    useEffect(() => setStats(initial), [initial]);

    useEffect(() => {
        let alive = true;

        const tick = async () => {
            if (document.hidden) return;
            try {
                const res = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!res.ok || !alive) return;
                const data: Payload = await res.json();
                if (!alive) return;
                if (Array.isArray(data.stats)) setStats(data.stats);
                if (data.updated_at) setUpdatedAt(data.updated_at);
            } catch {
                // أخطاء الشبكة العابرة تُتجاهل — المحاولة التالية بعد الفترة نفسها
            }
        };

        const id = setInterval(tick, intervalMs);
        return () => {
            alive = false;
            clearInterval(id);
        };
    }, [url, intervalMs]);

    return { stats, updatedAt };
}
