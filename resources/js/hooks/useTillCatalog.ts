import { useCallback, useEffect, useRef, useState } from 'react';

import { csrfHeaders } from '@/lib/csrf';
import { applyCatalog, askWith, orderedRows, type CatalogReply, type CatalogSnapshot } from '@/lib/till-catalog';
import * as store from '@/lib/till-catalog-store';
import { fromTill, type TillProduct } from '@/lib/till-products';

/** ‏‏من أين جاءت الأصنافُ المعروضة الآن */
export type CatalogSource = 'device' | 'server';

/** ‏‏أيُحفظ الكتالوجُ في هذا الجهاز: `yes` حُفظ، `no` لم يُكتب (مساحةٌ ممتلئة)، `off` لا IndexedDB */
export type CatalogSaved = 'unknown' | 'yes' | 'no' | 'off';

export interface TillCatalogState {
    /** ‏الأصنافُ مرتّبةً بالمعرّف — `null` حتى تُعرف */
    rows: TillProduct[] | null;
    /** ‏`loading` حتى تُعرف، `failed` حين لا نسخةَ في الجهاز ولا جوابَ من الخادم */
    status: 'loading' | 'ready' | 'failed';
    source: CatalogSource | null;
    /** ‏آخرُ سؤالٍ لم يصل — ما يُعرض ما حُفظ في الجهاز، لم يُتحقَّق منه الآن */
    stale: boolean;
    saved: CatalogSaved;
    /** ‏فُتح متجرٌ أو فرعٌ أو لغةٌ أخرى في الجلسة — لا يُسأل بعدها */
    scopeChanged: boolean;
    /** ‏يُسأل الآن — و`deep` يطلب مقارنةَ الصفّ المبنيّ نفسِه (مبلغٌ ردّه الخادم) */
    refresh: (deep?: boolean) => Promise<void>;
}

/**
 * ═══ كتالوجُ الصندوق من الجهاز، ثمّ ما تغيّر وحده ═══
 *
 * كانت الصفحةُ تحمل الأصنافَ كلَّها في كلّ فتح. فصار الجهازُ يحفظها
 * (`lib/till-catalog-store.ts`)، ويعرضها حين يُفتح الصندوق، ويسأل الخادمَ في
 * الوقت نفسِه عمّا تغيّر (`App\Support\TillCatalog`) — فيأتيه «كما هو» أو سلالُ ما
 * تغيّر. وجهازٌ لا نسخةَ عنده يأخذ الكتالوجَ كلَّه مرّةً، ثمّ يحفظه.
 *
 * قواعد:
 * 1. **سؤالٌ واحد في وقت**، وجوابٌ لا يُطابق ما عند الجهاز الآن يُطرح (`applyCatalog`):
 *    لا يُكتب قديمٌ فوق أحدث. وطلبٌ أثناء سؤالٍ يُصفّ خلفه.
 * 2. **ما يُعرض لا ينتظر الحفظ**، والحفظُ لا يُعلن قبل أن يُكتب: فشلُه (مساحةٌ
 *    ممتلئة) لا يوقف الصندوق — يعمل من الخادم كما كان، ويُقال إنّ الأصناف لم تُحفظ.
 * 3. **الصنفُ الذي لم يتغيّر يبقى هو هو** — نفسَ الكائن، فلا تُرسم بطاقتُه.
 * 4. **لا اتّصال:** تبقى النسخةُ كما حُفظت، ويُقال إنّها لم يُتحقَّق منها. والبيعُ
 *    بلا اتّصال كما كان: الخادمُ يحسب البيعةَ بسعره حين تصله، وما يخالفه للمراجعة.
 * 5. **متجرٌ أو فرعٌ أو لغةٌ أخرى** في الجلسة (409): لا يُطبَّق شيء ولا يُسأل بعدها.
 * 6. يُسأل كلَّ `intervalMs`، وحين يعود التبويبُ أو الاتّصال، وبعد كلّ بيعة.
 *
 * ولا تُمسّ هنا بيعةٌ ولا طابور: الكتالوجُ في قاعدته، والمبيعاتُ المعلّقة حيث هي.
 */
export default function useTillCatalog(url: string, scope: string, { intervalMs = 30000 }: { intervalMs?: number } = {}): TillCatalogState {
    const snap = useRef<CatalogSnapshot | null>(null);
    const rev = useRef<string | null>(null);
    const busy = useRef(false);
    const again = useRef<{ deep: boolean } | null>(null);
    const lost = useRef(false);
    const loaded = useRef(false);
    const [rows, setRows] = useState<TillProduct[] | null>(null);
    const [status, setStatus] = useState<TillCatalogState['status']>('loading');
    const [source, setSource] = useState<CatalogSource | null>(null);
    const [stale, setStale] = useState(false);
    const [saved, setSaved] = useState<CatalogSaved>('unknown');
    const [scopeChanged, setScopeChanged] = useState(false);

    // ‏‏الصفُّ كما تقرؤه الشاشة هو ما يُحفظ — نسخةٌ واحدةٌ في الذاكرة لا اثنتان (`fromTill` عند الوصول)
    const publish = useCallback((s: CatalogSnapshot, from: CatalogSource) => {
        setRows(orderedRows(s) as TillProduct[]);
        setSource(from);
        setStatus('ready');
    }, []);

    const persist = useCallback(async (s: CatalogSnapshot, changed: number[] | 'all') => {
        const r = await store.save(s, rev.current, changed);
        if (typeof r === 'object') {
            rev.current = r.rev;
            setSaved('yes');
            // ‏‏نسخةٌ كاملةٌ كُتبت: ما بمفتاحٍ آخر في الجهاز لا يُقرأ هنا — يُمحى
            if (changed === 'all') void store.keepOnly(s.scope);
        } else {
            setSaved(r === 'unavailable' ? 'off' : 'no');
        }
    }, []);

    const sync = useCallback(
        async (deep = false): Promise<void> => {
            if (lost.current || !loaded.current) return;
            if (busy.current) {
                again.current = { deep: deep || !!again.current?.deep };

                return;
            }
            busy.current = true;
            const asked = snap.current;
            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...csrfHeaders() },
                    credentials: 'same-origin',
                    body: JSON.stringify(askWith(scope, asked, deep)),
                });
                if (res.status === 409) {
                    const d = (await res.json().catch(() => null)) as { scope_changed?: boolean } | null;
                    if (d?.scope_changed) {
                        lost.current = true;
                        setScopeChanged(true);
                    }

                    return;
                }
                if (!res.ok) throw new Error(`catalog: ${res.status}`);
                const d = (await res.json()) as CatalogReply;
                if (d.scope !== scope) {
                    lost.current = true;
                    setScopeChanged(true);

                    return;
                }
                /*
                 * ‏‏الاسمُ المعروض الغائبُ وسعرُ البيع الغائب يُكمَلان هنا، مرّةً عند الوصول
                 * (`fromTill`): فالصفُّ المحفوظُ هو ما تقرؤه الشاشة، ويُقارَن بما عنده كما هو.
                 */
                if (Array.isArray(d.products)) fromTill(d.products);
                const next = applyCatalog(asked, d);
                if (next === 'stale') {
                    /*
                     * ‏‏جوابٌ لا يُطبَّق. فإن كان على نسخةٍ عند الجهاز (أساسُه غيرُها): يُسأل الكلُّ
                     * مرّةً، وما يُعرض يبقى حتى يصل. وإن كان الكلَّ نفسَه: لا يُعاد السؤالُ في
                     * الحال — كتالوجٌ كاملٌ يُنزَّل في حلقةٍ لا تنتهي أسوأُ من انتظار السؤال التالي.
                     */
                    if (asked) {
                        snap.current = null;
                        again.current = { deep: false };
                    } else {
                        setStale(true);
                        setStatus((s) => (s === 'ready' ? s : 'failed'));
                    }

                    return;
                }
                snap.current = next.snap;
                setStale(false);
                if (next.moved || !asked) publish(next.snap, 'server');
                // ‏‏«كما هو» بالرمز نفسِه — لا شيء يُكتب
                if (next.changed !== 'all' && next.changed.length === 0 && asked && next.snap.t === asked.t && rev.current !== null) return;
                await persist(next.snap, next.changed);
            } catch {
                // ‏‏لا اتّصال أو خطأ: النسخةُ تبقى — وجهازٌ بلا نسخة لا أصنافَ عنده بعد
                setStale(true);
                if (!snap.current) setStatus((s) => (s === 'ready' ? s : 'failed'));
            } finally {
                busy.current = false;
                if (again.current && !lost.current) {
                    const { deep: d } = again.current;
                    again.current = null;
                    void sync(d);
                }
            }
        },
        [url, scope, publish, persist],
    );

    // ‏‏أوّلًا ما في الجهاز — يُعرض حالًا — ثمّ يُسأل الخادم
    useEffect(() => {
        let cancelled = false;
        void (async () => {
            const got = await store.load(scope).catch(() => null);
            if (cancelled) return;
            if (got === 'unavailable') setSaved('off');
            else if (got) {
                // ‏‏ما حُفظ قبل أن يُكمَل (نسخةٌ أقدم) يُكمَل كما يُكمَل ما يصل — في مكانه
                fromTill([...got.snap.rows.values()]);
                snap.current = got.snap;
                rev.current = got.rev;
                setSaved('yes');
                publish(got.snap, 'device');
            }
            loaded.current = true;
            void sync();
        })();

        return () => {
            cancelled = true;
        };
    }, [scope, publish, sync]);

    useEffect(() => {
        const id = setInterval(() => void sync(), intervalMs);
        const wake = () => {
            if (!document.hidden) void sync();
        };
        document.addEventListener('visibilitychange', wake);
        window.addEventListener('online', wake);

        return () => {
            clearInterval(id);
            document.removeEventListener('visibilitychange', wake);
            window.removeEventListener('online', wake);
        };
    }, [sync, intervalMs]);

    return { rows, status, source, stale, saved, scopeChanged, refresh: sync };
}
