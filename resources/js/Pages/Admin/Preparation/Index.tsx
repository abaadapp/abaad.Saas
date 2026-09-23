import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { AlertTriangle, Bell, BellOff, Columns3, LayoutGrid, RefreshCw, Store, Truck, WifiOff } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Tabs, { type TabItem } from '@/Components/Tabs';
import { useConfirm } from '@/Components/ConfirmDialog';
import { statusDot } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import useBoardLive from '@/hooks/useBoardLive';
import useNewArrivals from '@/hooks/useNewArrivals';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import PrepCard from './partials/PrepCard';
import PrepDetails from './partials/PrepDetails';
import { checkProgress, type PrepOrder, type PrepProps } from './partials/types';

/**
 * لوحة التجهيز — شاشة من يصنع الباقة.
 *
 * تستعمل تخطيط اللوحة وبطاقاتها وأزرارها وشاراتها كما هي: هي صفحةٌ جديدة
 * لأنّ الميزة جديدة، لا لأنّ لها شكلًا خاصًّا.
 *
 * ولا يُعرض فيها سعرٌ ولا إجمالي: من يجهّز الورد لا يحتاج أن يعرف هامش
 * المحلّ ليضع ساقًا في مزهرية — والخادم لا يرسل تلك الأعمدة أصلًا.
 *
 * ═══ وتُحدِّث نفسَها ═══
 *
 * الطلب يُباع في الصندوق ويصل الطاولةَ بعد عشرين ثانية لا بعد أن يتذكّر
 * أحدٌ أن يضغط F5. انظر `useBoardLive` — وما فيها من أبواب تُغلق الاستطلاع.
 */
const NO_COUNTS = { all: 0, overdue: 0, today: 0, tomorrow: 0 };
const NO_TYPE_COUNTS = { all: 0, delivery: 0, pickup: 0 };

/** ما تُجلب وحدها عند الاستطلاع — لا الصفحةُ كلُّها */
const LIVE_PROPS = ['orders', 'counts', 'typeCounts', 'columnCounts', 'truncated', 'fetchedAt'];

/** أسماءُ الأعمدة — مفاتيحُها من `PreparationController::COLUMNS` */
const COLUMN_LABELS: Record<string, string> = {
    waiting: 'بانتظار التجهيز',
    preparing: 'قيد التجهيز',
    ready: 'جاهز',
    out: 'خرج للتوصيل',
    failed: 'تعذّر التوصيل',
    other: 'أخرى',
};

/** الحالةُ التي تُسأل عندها قائمةُ التحقّق — وتُسأل لا تمنع */
const READY = 'جاهز';

/** تفضيلاتُ العرض تخصّ الجهاز لا الحساب: شاشةُ الطاولة تُضبط مرّةً وتبقى */
function remembered(key: string, fallback: string): string {
    try {
        return localStorage.getItem(key) ?? fallback;
    } catch {
        // متصفّحٌ يمنع التخزين — التفضيلُ يعيش هذه الجلسة ولا تسقط الشاشة
        return fallback;
    }
}

function remember(key: string, value: string): void {
    try {
        localStorage.setItem(key, value);
    } catch {
        /* لا شيء — انظر `remembered` */
    }
}

export default function PreparationIndex() {
    const page = usePage<PageProps<PrepProps>>().props;
    const t = useTranslate();
    const [ask, confirmDialog] = useConfirm();

    /*
     * قيمٌ بديلة لكل خاصيّة — لا لأنّ الخادم قد ينساها، بل لأنّ نسخة الشاشة
     * ونسخة الخادم قد تفترقان لحظة النشر: المتصفّح يحمل ملفًّا جديدًا يقرأ
     * `columnCounts` والخادم لم يُرسلها بعد. وقراءة حقلٍ من `undefined` تُسقط
     * الشجرة كلّها فتبيضّ الشاشة — لأجل عدّادٍ لا لأجل الطلبات.
     */
    const orders = page.orders ?? [];
    const filters = page.filters ?? { when: null, type: null };
    const counts = page.counts ?? NO_COUNTS;
    const typeCounts = page.typeCounts ?? NO_TYPE_COUNTS;
    const columns = page.columns ?? {};
    const columnCounts = page.columnCounts ?? {};
    const truncated = page.truncated ?? null;

    const [view, setView] = useState(() => remembered('prep.view', 'cards'));
    const [sound, setSound] = useState(() => remembered('prep.sound', '0') === '1');
    const [open, setOpen] = useState<string | null>(null);
    /** أرقامُ الطلبات التي يُنتظر جوابُ نقلها — أزرارُها معطّلة حتى يصل */
    const [busy, setBusy] = useState<string[]>([]);
    /*
     * وعددُ الكتاباتِ الجارية — نقلًا كانت أو تأشيرًا.
     *
     * ═══ ولمَ ليس «النافذة مفتوحة» ═══
     *
     * أوّلُ ما كتبتُ أوقف الاستطلاعَ ما دامت نافذةُ التفاصيل مفتوحة — وهو
     * خطأ: القائمةُ يجب أن تتزامن بين الموظّفين، ومن يقرأ تفاصيل طلبٍ على
     * شاشةٍ لا يرى ما أشّره زميلُه على الشاشة الثانية ما دامت نافذتُه مفتوحة.
     * وهي الحالُ التي وُضع التزامنُ لأجلها أصلًا: اثنان يجهّزان طلبًا واحدًا.
     *
     * فالوقفُ لحظةَ الكتابة وحدها: بين إرسال الضغطة ووصول جوابها لا تُسحب
     * البطاقةُ من تحت اليد ولا يرتدّ المربّعُ إلى حاله القديم.
     */
    const [writing, setWriting] = useState(0);

    /*
     * عمرُ الحمولة — به تُحسب البقيّة بلا تفسير تاريخ.
     *
     * الخادمُ يرسل الدقائقَ الباقية لحظةَ القراءة، والشاشةُ تطرح منها ما
     * انقضى. والنبضةُ كلَّ نصف دقيقةٍ تُعيد الرسم فتتحرّك الأرقام وإن وقف
     * الاستطلاع — شاشةٌ في تبويبٍ مخفيّ عادت إليها العينُ بعد ساعة.
     */
    const receivedAt = useRef(Date.now());
    const [tick, setTick] = useState(0);
    useEffect(() => {
        receivedAt.current = Date.now();
        setTick((n) => n + 1);
    }, [page.fetchedAt]);
    useEffect(() => {
        const id = setInterval(() => setTick((n) => n + 1), 30000);

        return () => clearInterval(id);
    }, []);
    // `tick` تُقرأ ليعرف المحرّر أنّها اعتماديّةٌ مقصودة لا سطرٌ زائد
    const ageMs = useMemo(() => Date.now() - receivedAt.current, [tick]);

    const { refresh, refreshing, failed } = useBoardLive({
        only: LIVE_PROPS,
        paused: writing > 0,
    });

    const numbers = useMemo(() => orders.map((o) => o.number), [orders]);
    const { fresh, acknowledge, armSound } = useNewArrivals(numbers, sound);

    const tabs: TabItem[] = [
        { key: 'all', label: 'الكل', count: counts.all },
        { key: 'overdue', label: 'متأخّر', count: counts.overdue, dot: statusDot('ملغي') },
        { key: 'today', label: 'اليوم', count: counts.today },
        { key: 'tomorrow', label: 'غدًا', count: counts.tomorrow },
    ];

    /*
     * التنفيذ مبدّلٌ بجانب التبويبات لا صفٌّ ثانٍ منها.
     *
     * ضربُه في النوافذ الأربع يعني ثمانية تبويبات، ولا أحد يقرأ ثمانية.
     * والمرشّحان يعملان معًا: «توصيل اليوم» اختيارٌ واحد من كلٍّ منهما.
     */
    const types = [
        { key: 'all', label: 'الكل', icon: null, count: typeCounts.all },
        { key: 'delivery', label: 'توصيل', icon: Truck, count: typeCounts.delivery },
        // «استلام» وحدها تُترجَم Receive في مواضع أخرى — والمقصود هنا الأخذ من المحلّ
        { key: 'pickup', label: 'استلام من المحل', icon: Store, count: typeCounts.pickup },
    ];

    // المرشّحان يُحفظان معًا: تبديل النافذة لا يُسقط التنفيذ المختار
    const go = (next: { when?: string | null; type?: string | null }) => {
        const when = next.when !== undefined ? next.when : filters.when;
        const type = next.type !== undefined ? next.type : filters.type;

        router.get(
            route('admin.preparation.index'),
            {
                ...(when && when !== 'all' ? { when } : {}),
                ...(type && type !== 'all' ? { type } : {}),
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    /*
     * النقل — والزرُّ يُعطَّل حتى يصل الجواب.
     *
     * ضغطتان متتاليتان على «جاهز» كانتا تُرسلان نداءين: الأوّل ينقل والثاني
     * يُردّ بـ«لا يمكن نقل الطلب من جاهز إلى جاهز»… أو ما هو أسوأ إن كان
     * الزرُّ «خرج للتوصيل» وقد صار الطلبُ إليه فعلًا.
     *
     * والحارسُ في الخادم على كلّ حال (`OrderTransition` بقفلها) — وهذا يمنع
     * أن يقرأ من يجهّز رفضًا سبّبه هو.
     */
    const move = useCallback(
        (number: string, status: string) => {
            setBusy((b) => (b.includes(number) ? b : [...b, number]));
            setWriting((n) => n + 1);
            router.post(
                route('admin.preparation.move', number),
                { status },
                {
                    preserveScroll: true,
                    /*
                     * ولا `preserveState`: الردُّ يعيد الصفحةَ بخصائصَ جديدة،
                     * وبها تُقرأ الحالُ الحقيقيّة بعد النقل — أو بعد رفضِه.
                     * فمن رُفض نقلُه لأنّ زميلَه ألغى الطلب يرى الحالَ الجديدة
                     * على البطاقة مع رسالة الرفض، لا حالًا قديمةً يضغطها ثانيةً.
                     */
                    onFinish: () => {
                        setBusy((b) => b.filter((n) => n !== number));
                        setWriting((n) => n - 1);
                    },
                },
            );
        },
        [],
    );

    /*
     * و«جاهز» تسأل عمّا لم يُؤشَّر — ولا تمنع.
     *
     * المنعُ يوقف طلباتٍ قديمةً لا قائمةَ لها، وطلباتٍ غيرَ معتادةٍ لا تنطبق
     * عليها البنود، ومحلًّا لم يعتد القائمةَ أصلًا. والسؤالُ يقول العدد ثمّ
     * يمضي بمن أراد: تذكيرٌ لا بوّابة.
     */
    const moveChecked = useCallback(
        async (o: PrepOrder, status: string) => {
            if (status === READY) {
                const { done, total } = checkProgress(o);
                if (done < total) {
                    // والنصُّ خامٌ لا مترجَم: `useConfirm` تُمرّره على `t` بقيمه
                    const ok = await ask({
                        title: 'قائمة التجهيز غير مكتملة',
                        message: 'بقي :n من :total دون تأشير. تريد وسمه «جاهز» على كل حال؟',
                        action: 'نعم، جاهز',
                        values: { n: total - done, total },
                    });
                    if (! ok) return;
                }
            }

            move(o.number, status);
        },
        [ask, move],
    );

    const toggle = useCallback((number: string, key: string, checked: boolean) => {
        setWriting((n) => n + 1);
        router.post(
            route('admin.preparation.check', number),
            { key, checked },
            {
                preserveScroll: true,
                preserveState: true,
                // الخصائصُ الحيّةُ وحدها تعود: الجوابُ يحمل العلامةَ الجديدة
                // فتُرسم من الخادم لا من حالٍ محليّةٍ تُخمّن ما وقع
                only: LIVE_PROPS,
                onFinish: () => setWriting((n) => n - 1),
            },
        );
    }, []);

    const current = open ? (orders.find((o) => o.number === open) ?? null) : null;
    /*
     * ونافذةٌ فُتحت لطلبٍ غادر اللوحة تبقى مفتوحةً وتقول ذلك.
     *
     * إغلاقُها تلقائيًّا يُخفي السببَ: من يقرأ التفاصيل تختفي شاشتُه فجأةً
     * ولا يعرف أنّ زميلًا نقل الطلب. فتُحفظ اللقطةُ الأخيرة ويُرفع سطرٌ فوقها.
     */
    const lastSeen = useRef<PrepOrder | null>(null);
    if (current) lastSeen.current = current;
    const shown = current ?? (open ? lastSeen.current : null);

    const groups = useMemo(() => {
        const known = new Set(Object.values(columns).flat());
        const out: { key: string; orders: PrepOrder[] }[] = Object.keys(columns).map((key) => ({
            key,
            orders: orders.filter((o) => columns[key].includes(o.status)),
        }));
        // وما لا عمودَ له يُعرض في «أخرى» ولا يُبتلع — حالٌ قديمةٌ في القاعدة
        const rest = orders.filter((o) => ! known.has(o.status));
        if (rest.length > 0 || (columnCounts.other ?? 0) > 0) out.push({ key: 'other', orders: rest });

        return out;
    }, [orders, columns, columnCounts]);

    /*
     * وبناةُ الروابط تُثبَّت هويّتُها.
     *
     * `PrepDetails` تجلب خطَّ الحال في `useEffect` معتمدًا عليها. ودالّةٌ
     * تُكتب في مكانها تُنشأ من جديدٍ في كلّ رسم — واللوحة تُعاد رسمُها كلّ
     * نصف دقيقةٍ للنبضة ومع كلّ استطلاع — فيُعاد الجلبُ بلا سبب، عشراتِ
     * المرّات ما دامت النافذة مفتوحة.
     */
    const deliveryNoteUrl = useCallback((n: string) => route('admin.preparation.deliveryNote', n), []);
    const timelineUrl = useCallback((n: string) => route('admin.preparation.timeline', n), []);

    const card = (o: PrepOrder) => (
        <PrepCard
            key={o.number}
            order={o}
            ageMs={ageMs}
            fresh={fresh.includes(o.number)}
            busy={busy.includes(o.number)}
            onOpen={() => setOpen(o.number)}
            onMove={(s) => moveChecked(o, s)}
        />
    );

    return (
        <AdminLayout title="لوحة التجهيز">
            <PageHeader
                title="لوحة التجهيز"
                subtitle={t('الطلبات التي تنتظر التجهيز، مرتّبةً بموعدها — والمتأخّر أوّلًا')}
            />

            {/* ═══ شريطُ الحال: ما وصل، وحالُ الاستطلاع، ومبدّلُ العرض ═══ */}
            <div className="mb-3 flex flex-wrap items-center gap-2">
                {fresh.length > 0 && (
                    <Button
                        type="button"
                        size="sm"
                        variant="primary"
                        className="rounded-full"
                        data-testid="prep-fresh"
                        onClick={acknowledge}
                    >
                        <Bell className="size-4" />
                        {t('وصل :n طلبًا جديدًا', { n: fresh.length })}
                    </Button>
                )}

                {failed && (
                    <span
                        data-testid="prep-stale"
                        className="flex items-center gap-1.5 rounded-full bg-[#fef2f2] px-3 py-1.5 text-[12px] font-medium text-[#b91c1c]"
                    >
                        <WifiOff className="size-3.5" />
                        {t('تعذّر التحديث — المعروض قد يكون قديمًا')}
                    </span>
                )}

                <div className="ms-auto flex items-center gap-1">
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="rounded-full"
                        aria-pressed={sound}
                        data-testid="prep-sound"
                        title={t(sound ? 'إيقاف تنبيه الصوت' : 'تشغيل تنبيه الصوت')}
                        onClick={() => {
                            const next = ! sound;
                            setSound(next);
                            remember('prep.sound', next ? '1' : '0');
                            // والتفعيلُ نفسُه تفاعلٌ — فتُجرَّب النغمة ليُعرف أنّها تعمل
                            if (next) armSound();
                        }}
                    >
                        {sound ? <Bell className="size-4" /> : <BellOff className="size-4" />}
                        <span className="sr-only">{t('تنبيه صوتي')}</span>
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="rounded-full"
                        disabled={refreshing}
                        data-testid="prep-refresh"
                        onClick={refresh}
                    >
                        <RefreshCw className={cn('size-4', refreshing && 'animate-spin')} />
                        {t('تحديث')}
                    </Button>
                    {[
                        { key: 'cards', icon: LayoutGrid, label: 'بطاقات' },
                        { key: 'columns', icon: Columns3, label: 'أعمدة' },
                    ].map((v) => {
                        const Icon = v.icon;

                        return (
                            <Button
                                key={v.key}
                                type="button"
                                size="sm"
                                variant={view === v.key ? 'primary' : 'ghost'}
                                className="rounded-full"
                                aria-pressed={view === v.key}
                                data-testid={`prep-view-${v.key}`}
                                onClick={() => {
                                    setView(v.key);
                                    remember('prep.view', v.key);
                                }}
                            >
                                <Icon className="size-4" />
                                {t(v.label)}
                            </Button>
                        );
                    })}
                </div>
            </div>

            <div className="mb-4">
                <Tabs
                    tabs={tabs}
                    current={filters.when ?? 'all'}
                    onChange={(when) => go({ when })}
                    trailing={
                        <div className="mb-2 flex items-center gap-1">
                            {types.map((x) => {
                                const active = (filters.type ?? 'all') === x.key;
                                const Icon = x.icon;

                                return (
                                    <Button
                                        key={x.key}
                                        type="button"
                                        size="sm"
                                        variant={active ? 'primary' : 'ghost'}
                                        className="rounded-full"
                                        aria-pressed={active}
                                        onClick={() => go({ type: x.key })}
                                    >
                                        {Icon && <Icon className="size-4" />}
                                        {t(x.label)}
                                        {!! x.count && (
                                            <span className={cn('text-[12px]', active ? 'opacity-70' : 'text-[#9ca3af]')}>
                                                {x.count}
                                            </span>
                                        )}
                                    </Button>
                                );
                            })}
                        </div>
                    }
                />
            </div>

            {/*
              * ما لم يُعرض يُقال عددُه.
              *
              * اللوحة تُرسم حتى سقفها ثم تنتهي؛ وكانت تنتهي صامتة — فيقرأ من
              * يجهّز آخرَ بطاقةٍ ويظنّ أنّه فرغ. والفرق بين «فرغتُ» و«بقي
              * خمسون» هو الفرق بين باقةٍ تصل وباقةٍ لا تصل.
              */}
            {truncated && (
                <Card className="mb-4 flex items-center gap-2 border-[#fde68a] bg-[#fffbeb] p-4 text-sm text-[#92400e]">
                    <AlertTriangle className="size-4 shrink-0" />
                    <span>
                        {t('تُعرض :shown من :total طلبًا. ضيّق بالتبويبات أو بنوع التنفيذ لرؤية الباقي.', {
                            shown: truncated.shown,
                            total: truncated.total,
                        })}
                    </span>
                </Card>
            )}

            {orders.length === 0 ? (
                <Card className="p-6 text-center text-sm text-[#6b7280]">
                    {/* «لا شيء» مع مرشّحٍ قائم يُقرأ «لا شيء أبدًا» — فيُقال أيّهما أفرغها */}
                    {filters.type === 'delivery'
                        ? t('لا طلبات توصيل تنتظر التجهيز')
                        : filters.type === 'pickup'
                          ? t('لا طلبات استلام تنتظر التجهيز')
                          : t('لا طلبات تنتظر التجهيز')}
                </Card>
            ) : view === 'columns' ? (
                /*
                 * الأعمدةُ تُمرَّر أفقيًّا على الجوّال ولا تُطوى.
                 *
                 * خمسةُ أعمدةٍ على عرض هاتفٍ تعني عمودًا بعرض إصبعين لا تُقرأ
                 * بطاقتُه. والتمريرُ الأفقيّ يُبقي كلَّ عمودٍ بعرضه ويُظهر
                 * حافّةَ التالي — إشارةٌ إلى أنّ ثمّة مزيدًا.
                 */
                <div data-testid="prep-columns" className="-mx-1 flex gap-3 overflow-x-auto px-1 pb-2">
                    {groups.map((g) => (
                        <section key={g.key} className="flex w-[280px] shrink-0 flex-col gap-3 sm:w-[320px]">
                            <header className="flex items-baseline justify-between rounded-[12px] bg-[#f7f7f5] px-3 py-2">
                                <h3 className="text-sm font-bold text-[#111]">{t(COLUMN_LABELS[g.key] ?? g.key)}</h3>
                                {/*
                                  * والرقمان حين تُقصّ اللوحة: المعروضُ من الكلّ.
                                  *
                                  * رقمٌ واحدٌ فوق عمودٍ مقصوص يقول «١٢» وتحته مئة —
                                  * وهو عينُ الكذب الذي وُضع شريطُ الاقتطاع ليمنعه.
                                  */}
                                <span className="text-[12px] tabular-nums text-[#6b7280]">
                                    {truncated && g.orders.length !== (columnCounts[g.key] ?? 0)
                                        ? `${g.orders.length}/${columnCounts[g.key] ?? 0}`
                                        : (columnCounts[g.key] ?? g.orders.length)}
                                </span>
                            </header>
                            {g.orders.length === 0 ? (
                                <p className="rounded-[12px] border border-dashed border-[var(--ui-border,#e8e8e8)] p-4 text-center text-[12px] text-[#9ca3af]">
                                    {t('لا شيء هنا')}
                                </p>
                            ) : (
                                g.orders.map(card)
                            )}
                        </section>
                    ))}
                </div>
            ) : (
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">{orders.map(card)}</div>
            )}

            <PrepDetails
                order={shown}
                gone={open !== null && current === null}
                busy={shown ? busy.includes(shown.number) : false}
                onClose={() => setOpen(null)}
                onMove={(s) => shown && moveChecked(shown, s)}
                onToggle={(key, checked) => shown && toggle(shown.number, key, checked)}
                deliveryNoteUrl={deliveryNoteUrl}
                timelineUrl={timelineUrl}
            />

            {confirmDialog}
        </AdminLayout>
    );
}
