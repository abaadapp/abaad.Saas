import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * استطلاع دوري لحمولة صفحة كاملة (تقرير، تحليلات، ربحية).
 *
 * صفحات التقارير كانت تُحسب لحظة الفتح ثم تتجمّد: تُترك مفتوحة على شاشة
 * المكتب فتقرأ أرقام الصباح بعد يوم بيع كامل. وهي أخطر من جدول متجمّد،
 * لأن التاجر يبني عليها قرارًا.
 *
 * ولا يعيد استخدام useLiveStock/useLiveStats: تلك تدمج صفوفًا أو بطاقات،
 * وهذه تُبدّل الحمولة كلها.
 *
 * ثلاث قواعد:
 * 1. يتوقف عند إخفاء التبويب — لا طلبات لشاشة لا يراها أحد.
 * 2. لا يأخذ القيم الأوليّة معاملًا. تمريرُ كائن يُبنى في كل تصيير كان
 *    يجعل الخطّاف يمسح لقطته أبديًّا فلا يتحدّث شيء — وقعنا فيه في صفحة
 *    المخزون. فيعيد null قبل أوّل استجابة، والصفحة تستعمل بيانات الخادم.
 * 3. كل تنقّل ناجح يُلغي اللقطة: ما جاء مع الصفحة أحدث مما استطلعناه.
 *
 * ويعيد وقت آخر استطلاع — رقمٌ يتحرّك بلا أن يُعرف عمرُه يُقرأ على أنه لحظيّ.
 */
export default function useLiveFeed<T>(url: string, intervalMs = 20000) {
    const [data, setData] = useState<T | null>(null);
    const [updatedAt, setUpdatedAt] = useState<string | null>(null);

    useEffect(
        () =>
            router.on('success', () => {
                setData(null);
                setUpdatedAt(null);
            }),
        [],
    );

    useEffect(() => {
        let alive = true;

        const tick = async () => {
            if (document.hidden) return;
            try {
                const res = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!res.ok || !alive) return;
                const payload: T & { updated_at?: string } = await res.json();
                if (!alive || !payload || typeof payload !== 'object') return;
                setData(payload);
                if (payload.updated_at) setUpdatedAt(payload.updated_at);
            } catch {
                // انقطاع عابر — المحاولة التالية بعد الفترة نفسها، واللقطة الأخيرة تبقى
            }
        };

        const id = setInterval(tick, intervalMs);
        return () => {
            alive = false;
            clearInterval(id);
        };
    }, [url, intervalMs]);

    return { data, updatedAt };
}
