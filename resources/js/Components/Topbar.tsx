import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Bell,
    Building2,
    Check,
    ChevronDown,
    CircleAlert,
    Clock,
    Globe,
    Languages,
    Menu,
    RotateCcw,
    Store,
    X,
} from 'lucide-react';
import GoldBadge from '@/Components/GoldBadge';
import UnifiedSearch from '@/Components/UnifiedSearch';
import { Avatar, AvatarFallback, AvatarImage } from '@/Components/ui/avatar';
import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { initials } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { logout } from '@/lib/logout';
import { adminPages, platformPages, visibleTo } from '@/lib/pages';
import type { Notification, NotificationPast, PageProps } from '@/types';

/** قائمةُ «المؤجَّلة» و«المكتملة» — صفوفٌ تُقرأ ويُتراجَع عنها، بلا أزرار إنجاز */
function NoticeList({
    rows,
    empty,
    onUndo,
    loading,
    t,
}: {
    rows: { key: string; text: string; url?: string; note: string; undo: boolean }[];
    empty: string;
    onUndo: (key: string) => void;
    loading: boolean;
    t: (s: string) => string;
}) {
    if (loading) {
        return <p className="px-3 py-8 text-center text-[13px] text-[#9ca3af]">{t('جارٍ التحميل…')}</p>;
    }

    if (rows.length === 0) {
        return <p className="px-3 py-8 text-center text-[13px] text-[#9ca3af]">{empty}</p>;
    }

    return (
        <div className="max-h-[55vh] overflow-y-auto">
            {rows.map((r) => (
                <div key={r.key} className="flex items-start gap-1 rounded-[8px] px-1 hover:bg-[#f5f5f4]">
                    <Link href={r.url ?? '#'} className="flex min-w-0 flex-1 flex-col gap-0.5 px-2 py-2">
                        <span className="text-[13px] text-[#374151]">{r.text}</span>
                        <span className="text-[11px] text-[#9ca3af]">{r.note}</span>
                    </Link>
                    {r.undo && (
                        <button
                            type="button"
                            onClick={(e) => {
                                e.preventDefault();
                                onUndo(r.key);
                            }}
                            aria-label={t('إعادة إلى تحتاج إجراء')}
                            title={t('إعادة إلى تحتاج إجراء')}
                            className="mt-2 flex size-6 shrink-0 items-center justify-center rounded-full text-[#9ca3af] transition-colors hover:bg-[#e8e8e6] hover:text-[#111]"
                        >
                            <RotateCcw className="size-3.5" />
                        </button>
                    )}
                </div>
            ))}
        </div>
    );
}

export default function Topbar({ onMenuClick }: { onMenuClick: () => void }) {
    const { auth, context, notifications, reportPages, csrf, locale } = usePage<PageProps>().props;

    /**
     * تغذية الجرس الحيّة — بديل استطلاع admin.notifications.feed الذي كان في
     * التخطيط القديم. يبدأ مما أرسله الخادم مع الصفحة ثم يستطلع كل 30 ثانية،
     * فيظهر الطلب الجديد بلا إعادة تحميل. يتوقف عند إخفاء التبويب.
     */
    const [feed, setFeed] = useState(notifications);
    useEffect(() => setFeed(notifications), [notifications]);

    const pull = async () => {
        const res = await fetch('/admin/notifications/feed', { headers: { Accept: 'application/json' } });
        if (!res.ok) return null;
        const data = await res.json();
        return {
            items: (data.items ?? []) as Notification[],
            count: (data.count ?? 0) as number,
            snoozed: (data.snoozed ?? []) as Notification[],
            done: (data.done ?? []) as Notification[],
        };
    };

    useEffect(() => {
        if (!notifications) return;
        let alive = true;
        const id = setInterval(async () => {
            if (document.hidden) return;
            try {
                const next = await pull();
                if (alive && next) setFeed(next);
            } catch {
                // أخطاء الشبكة العابرة تُتجاهل
            }
        }, 30000);
        return () => {
            alive = false;
            clearInterval(id);
        };
    }, [notifications]);
    const t = useTranslate();

    /*
     * ═══ التنبيهُ إجراءٌ يُتابَع ═══
     *
     * ثلاثةُ تبويبات، والشارةُ تعدّ الأوّلَ وحدَه — فالمؤجَّلُ والمنجَزُ
     * خرجا من «ما عليك الآن»، وشارةٌ تعدّهما تقول رقمًا لا يقابله عمل.
     *
     * و«تم» لا تُصدَّق هنا: تُرسَل إلى الخادم فيسأل مصدرَ التنبيه. فإن ردّ
     * بأنّ المشكلةَ قائمة عُرض سببُه تحت الصفّ نفسِه — لا نافذةٌ تُغلق
     * فتُنسى، بل سطرٌ يبقى إلى جانب زرَّي «افتح» و«أجّل».
     */
    const [tab, setTab] = useState<'active' | 'snoozed' | 'done'>('active');
    /* سببُ رفض الإنجاز — مفتاحٌ إلى نصّ، يُمحى عند أوّل نجاحٍ أو تأجيل */
    const [blocked, setBlocked] = useState<Record<string, string>>({});
    /* أيُّ صفٍّ فُتحت قائمةُ مُدَدِ تأجيله */
    const [picking, setPicking] = useState<string | null>(null);
    /* سجلُّ ما انتهى — لا يُحمَّل مع الاستطلاع، بل عند فتح تبويبه */
    const [past, setPast] = useState<NotificationPast[] | null>(null);

    const durations: [number, string][] = [
        [60, t('ساعة')],
        [240, t('٤ ساعات')],
        [1440, t('يوم')],
        [4320, t('٣ أيام')],
        [10080, t('أسبوع')],
    ];

    const send = async (url: string, body: Record<string, unknown>) => {
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf ?? '',
                    Accept: 'application/json',
                },
                body: JSON.stringify(body),
            });
            return { ok: res.ok, data: await res.json().catch(() => ({})) };
        } catch {
            /* انقطاعُ الشبكة: لا شيء يتغيّر، ويعود الصفُّ عند أوّل استطلاع */
            return { ok: false, data: {} };
        }
    };

    /* بعد كلّ فعلٍ يُعاد سؤالُ الخادم: ثلاثُ قوائمَ تُصان باليد تفترق */
    const refresh = async () => {
        const next = await pull();
        if (next) setFeed(next);
    };

    const finish = async (key: string) => {
        const { ok, data } = await send('/admin/notifications/done', { key });

        if (!ok) {
            setBlocked((b) => ({ ...b, [key]: String(data.reason ?? t('تعذّر إنهاء هذا الإشعار')) }));
            return;
        }

        setBlocked(({ [key]: _gone, ...rest }) => rest);
        setPast(null);
        await refresh();
    };

    const snoozeFor = async (key: string, minutes: number) => {
        setPicking(null);
        const { ok } = await send('/admin/notifications/snooze', { key, minutes });
        if (!ok) return;
        setBlocked(({ [key]: _gone, ...rest }) => rest);
        await refresh();
    };

    const reopen = async (key: string) => {
        const { ok } = await send('/admin/notifications/reopen', { key });
        if (!ok) return;
        setPast(null);
        await refresh();
    };

    /* فُتح فقُرئ — ولا ينتظر أحدٌ الشبكةَ ليتنقّل */
    const markSeen = (key: string) => void send('/admin/notifications/open', { key });

    useEffect(() => {
        if (tab !== 'done' || past !== null) return;
        let alive = true;
        void fetch('/admin/notifications/history', { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : { items: [] }))
            .then((d) => alive && setPast((d.items ?? []) as NotificationPast[]))
            .catch(() => alive && setPast([]));
        return () => {
            alive = false;
        };
    }, [tab, past]);

    /*
     * إخفاء الخبر من الجرس.
     *
     * والإزالة محلّية أولًا ثم تُرسل: انتظار الشبكة يجعل النقرة تبدو بلا
     * أثر. فإن ردّها الخادمُ عاد الصفُّ وظهر سببُه.
     */
    const dismissOne = async (key: string) => {
        setFeed((f) => (f ? { ...f, items: f.items.filter((i) => i.key !== key), count: Math.max(0, f.count - 1) } : f));

        const { ok, data } = await send('/admin/notifications/dismiss', { key });

        /* ردَّه الخادمُ — فيعود الصفُّ ويُكتب سببُه، ولا يختفي شيءٌ لم يُخفَ */
        if (!ok) {
            setBlocked((b) => ({ ...b, [key]: String(data.reason ?? t('تعذّر إخفاء هذا الإشعار')) }));
            await refresh();
        }
    };

    /*
     * ═══ «حذف الكل» صار «إخفاء الأخبار» ═══
     *
     * الخادمُ صار يُبقي الإجراءات ويُخفي الأخبارَ وحدَها. وزرٌّ يقول «حذف
     * الكل» ثمّ يبقى نصفُ القائمة يُقرأ عطبًا لا قاعدة. والاسمُ يقول ما
     * يفعل، والقائمةُ تُقرأ من الخادم بعده فلا تُصان باليد.
     */
    const hideNews = async () => {
        await send('/admin/notifications/clear', {});
        await refresh();
    };

    /* ولا يُعرض إن لم يكن ثمّ خبرٌ يُخفى */
    const hasNews = (feed?.items ?? []).some((i) => i.kind !== 'task');

    const isPlatform = auth?.user.role === 'super_admin';
    const abilities = auth?.abilities ?? [];

    /**
     * البحث الموحّد — لكل لوحة مسارها. مدير المنصة لا يملك business_id،
     * فربط الزر به وحده كان يخفي بحث المنصة رغم وجود مساره.
     */
    const searchUrl =
        isPlatform ? route('super-admin.search')
        : auth?.user.businessId ? route('admin.search')
        : null;

    /*
     * دليلُ الصفحات يُبنى مرّةً لا مع كل رسم.
     *
     * بناؤه يستدعي `route()` لكل صفحةٍ في النظام — خمسًا وأربعين مرّة —
     * والشريط العلوي يُعاد رسمه مع كل استطلاعٍ للجرس، أي مرّتين في الدقيقة.
     */
    const pages = useMemo(
        () => visibleTo(isPlatform ? platformPages() : adminPages(reportPages ?? []), auth),
        [isPlatform, reportPages, auth],
    );

    /**
     * قائمة الحساب تفتح بمرور الماوس (كطلب المالك) لا بالنقر وحده. مهلةُ
     * إغلاقٍ صغيرة تُبقيها مفتوحة أثناء عبور الفجوة بين الزرّ ومحتواها فلا
     * تُغلق قبل الوصول إليها. النقر ولوحة المفاتيح وEscape تبقى تعمل عبر
     * onOpenChange، وmodal=false كي يبقى بقيّة الهيدر قابلًا للمرور عليه.
     */
    const [accountOpen, setAccountOpen] = useState(false);
    const closeTimer = useRef<number | null>(null);
    const openAccount = () => {
        if (closeTimer.current) {
            clearTimeout(closeTimer.current);
            closeTimer.current = null;
        }
        setAccountOpen(true);
    };
    const closeAccountSoon = () => {
        if (closeTimer.current) clearTimeout(closeTimer.current);
        closeTimer.current = window.setTimeout(() => setAccountOpen(false), 140);
    };
    useEffect(() => () => {
        if (closeTimer.current) clearTimeout(closeTimer.current);
    }, []);

    return (
        /*
         * الترويسة مثبّتة تحت شريط الانتحال إن وُجد (--chrome-top، وإلا صفر).
         *
         * وbg-white/95 أساسًا و80 عند دعم backdrop-filter: الشفافية بلا ضبابٍ
         * تجعل نصّ الصفحة يظهر خلف الترويسة عند التمرير — وهو ما يحدث حين
         * يتعذّر التمويه. وtransform-gpu يرفعها إلى طبقةٍ مستقلة فلا تتأخّر
         * عن الصفحة أثناء التمرير باللمس على الآيباد.
         */
        <header className="sticky top-[var(--chrome-top,0px)] z-30 flex h-16 shrink-0 transform-gpu items-center gap-3 border-b border-[var(--ui-border,#e8e8e8)] bg-white/95 px-4 backdrop-blur-md supports-[backdrop-filter]:bg-white/80 lg:px-6">
            <Button variant="ghost" size="icon" className="lg:hidden" onClick={onMenuClick}>
                <Menu />
                <span className="sr-only">{t('القائمة')}</span>
            </Button>

            {searchUrl && <UnifiedSearch url={searchUrl} pages={pages} />}

            {/*
             * أربعة أزرار لا أكثر: الكاشير، والموقع، والإشعارات، والحساب.
             *
             * كان الشريط يحمل سبعة مداخل — عملة ولغة وفرع مستقلّة إلى جانبها —
             * فصار صفًّا من القوائم يزاحم بعضه على الشاشات المتوسطة. واللغة
             * والفرع ليسا وجهتين بل تفضيلان يُضبطان ثم يُنسيان، فموضعهما قائمة
             * الحساب حيث بقيّة ما يخصّ من يقف أمام الشاشة.
             */}
            <div className="ms-auto flex shrink-0 items-center gap-1.5">
                {context && (
                    <>
                        {/*
                         * وكلُّ زرٍّ يتبع قسمَه: الكاشير الذي لم يُمنح «الموقع»
                         * كان يرى الكرةَ الأرضيّة وتفتح له موقعَ المتجر أو شاشةَ
                         * ضبطه — وهو ما لا يُفتح له من الشريط الجانبيّ. والأقسامُ
                         * تصل من الخادم (`auth.abilities`) لا تُخمَّن من الدور.
                         */}
                        {abilities.includes('pos') && (
                            <Button asChild variant="ghost" size="icon" title={t('نقطة البيع')}>
                                <Link href={route('pos.index')}>
                                    <Store />
                                    <span className="sr-only">{t('نقطة البيع')}</span>
                                </Link>
                            </Button>
                        )}

                        {/* موقع التاجر إن ضُبط، وإلا فزرٌّ يدلّ على الإعدادات
                            لإضافته — فلا يقف بلا وظيفة. ولمن يملك قسمَ الموقع وحده. */}
                        {!abilities.includes('website') ? null : context.website ? (
                            <Button asChild variant="ghost" size="icon" title={t('الموقع الإلكتروني')}>
                                <a href={context.website} target="_blank" rel="noopener noreferrer">
                                    <Globe />
                                    <span className="sr-only">{t('الموقع الإلكتروني')}</span>
                                </a>
                            </Button>
                        ) : (
                            /*
                             * إلى شاشة الموقع في أدوات التسويق لا إلى بيانات
                             * النشاط.
                             *
                             * الموقع صار قسمًا قائمًا: نطاقٌ ونشرٌ وجملةٌ
                             * تعريفية وما يراه الزائر. فمن يضغط الزرّ ليضيف
                             * موقعه يصل إلى حيث يُضبط كلّه، لا إلى حقلٍ في
                             * صفحة الإعدادات لا يفعل غير تشغيل هذا الزرّ.
                             */
                            <Button asChild variant="ghost" size="icon" title={t('أضف الموقع الإلكتروني')}>
                                <Link href={route('admin.settings.index', { section: 'website' })}>
                                    <Globe />
                                    <span className="sr-only">{t('أضف الموقع الإلكتروني')}</span>
                                </Link>
                            </Button>
                        )}
                    </>
                )}

                {/* الإشعارات */}
                {feed && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" className="relative">
                                <Bell />
                                {feed.count > 0 && (
                                    <span className="absolute end-1.5 top-1.5 flex size-4 items-center justify-center rounded-full bg-[#dc2626] text-[10px] font-semibold text-white">
                                        {feed.count > 9 ? '9+' : feed.count}
                                    </span>
                                )}
                                <span className="sr-only">{t('الإشعارات')}</span>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-[21rem] max-w-[calc(100vw-1.5rem)]">
                            <DropdownMenuLabel className="flex items-center justify-between gap-2">
                                <span>{t('الإشعارات')}</span>
                                {tab === 'active' && hasNews && (
                                    <button
                                        type="button"
                                        onClick={(ev) => {
                                            ev.preventDefault();
                                            void hideNews();
                                        }}
                                        className="text-[12px] font-normal text-[#6b7280] transition-colors hover:text-[#b91c1c]"
                                    >
                                        {t('إخفاء الأخبار')}
                                    </button>
                                )}
                            </DropdownMenuLabel>

                            {/* ثلاثةُ تبويبات — والرقمُ على الأوّل وحدَه */}
                            <div className="flex gap-1 px-2 pb-1.5" role="tablist">
                                {(
                                    [
                                        ['active', t('تحتاج إجراء'), feed.count],
                                        ['snoozed', t('مؤجلة'), feed.snoozed.length],
                                        ['done', t('مكتملة'), null],
                                    ] as [typeof tab, string, number | null][]
                                ).map(([id, label, n]) => (
                                    <button
                                        key={id}
                                        type="button"
                                        role="tab"
                                        aria-selected={tab === id}
                                        onClick={(ev) => {
                                            ev.preventDefault();
                                            setTab(id);
                                        }}
                                        className={`flex-1 rounded-[8px] px-2 py-1.5 text-[12px] transition-colors ${
                                            tab === id
                                                ? 'bg-[#111] font-medium text-white'
                                                : 'text-[#6b7280] hover:bg-[#f5f5f4]'
                                        }`}
                                    >
                                        {label}
                                        {n ? ` (${n > 9 ? '9+' : n})` : ''}
                                    </button>
                                ))}
                            </div>
                            <DropdownMenuSeparator />

                            {tab === 'done' ? (
                                <NoticeList
                                    empty={t('لا شيء مكتمل بعد')}
                                    rows={[
                                        ...feed.done.map((i) => ({
                                            key: i.key,
                                            text: i.text,
                                            url: i.url,
                                            note: t('أنجزتَه — ولم يزل سببُه'),
                                            undo: true,
                                        })),
                                        ...(past ?? []).map((r) => ({
                                            key: 'past-' + r.key + r.at,
                                            text: r.text,
                                            url: r.url ?? undefined,
                                            note: r.state === 'done' ? t('أنجزتَه') : t('حُلّ تلقائيًا'),
                                            undo: false,
                                        })),
                                    ]}
                                    onUndo={reopen}
                                    loading={past === null}
                                    t={t}
                                />
                            ) : tab === 'snoozed' ? (
                                <NoticeList
                                    empty={t('لا شيء مؤجل')}
                                    rows={feed.snoozed.map((i) => ({
                                        key: i.key,
                                        text: i.text,
                                        url: i.url,
                                        note: t('مؤجل'),
                                        undo: true,
                                    }))}
                                    onUndo={reopen}
                                    loading={false}
                                    t={t}
                                />
                            ) : feed.items.length === 0 ? (
                                <p className="px-3 py-8 text-center text-[13px] text-[#9ca3af]">
                                    {t('لا توجد إشعارات')}
                                </p>
                            ) : (
                                /*
                                 * ═══ وستّةُ صفوفِ إجراءٍ لا تسع شاشةَ هاتف ═══
                                 *
                                 * صارت للصفّ أزرارٌ تحته، فطال. وستّةٌ منها تبلغ
                                 * ٦٢٦ بكسل — واللوحةُ `overflow-hidden`، فالصفوفُ
                                 * السفلى **تُقصّ ولا تُمرَّر**: يرى صاحبُ المحلّ
                                 * الشارةَ تقول ستًّا ولا يبلغ آخرَها ليضغط «تم».
                                 *
                                 * قِيس على ٣٦٠×٦٠٠ و٣٩٠×٦٦٤، وكلتاهما تخرج.
                                 * و`55vh` تسع خمسةَ صفوفٍ على أقصر شاشةٍ وتمرّر
                                 * ما بعدها، وتتّسع على الحاسوب بلا فراغ.
                                 */
                                <div className="max-h-[55vh] overflow-y-auto">
                                {feed.items.slice(0, 6).map((item) => (
                                    /* صفٌّ لا DropdownMenuItem: زرّ الحذف داخل عنصرٍ
                                       قابل للاختيار كان يُغلق القائمة عند كل نقرة */
                                    <div
                                        key={item.key}
                                        className="group rounded-[8px] px-1 transition-colors hover:bg-[#f5f5f4]"
                                    >
                                        <div className="flex items-start gap-1">
                                            <Link
                                                href={item.url ?? '#'}
                                                onClick={() => item.kind === 'task' && markSeen(item.key)}
                                                className="flex min-w-0 flex-1 flex-col gap-0.5 px-2 py-2"
                                            >
                                                <span className="text-[13px] font-medium text-[#111]">{item.text}</span>
                                                {item.time && (
                                                    <span className="text-[12px] text-[#6b7280]">{item.time}</span>
                                                )}
                                            </Link>
                                            {/* خبرٌ يُخفى، وإجراءٌ يُنجَز أو يُؤجَّل — ولا يُعرض الاثنان معًا */}
                                            {item.kind === 'task' ? null : (
                                                <button
                                                    type="button"
                                                    onClick={(e) => {
                                                        e.preventDefault();
                                                        e.stopPropagation();
                                                        dismissOne(item.key);
                                                    }}
                                                    aria-label={t('حذف الإشعار')}
                                                    title={t('حذف الإشعار')}
                                                    className="mt-2 flex size-6 shrink-0 items-center justify-center rounded-full text-[#9ca3af] transition-colors hover:bg-[#e8e8e6] hover:text-[#111]"
                                                >
                                                    <X className="size-3.5" />
                                                </button>
                                            )}
                                        </div>

                                        {item.kind === 'task' && (
                                            <div className="flex flex-wrap items-center gap-1 px-2 pb-2">
                                                <button
                                                    type="button"
                                                    onClick={(e) => {
                                                        e.preventDefault();
                                                        void finish(item.key);
                                                    }}
                                                    className="flex items-center gap-1 rounded-[6px] bg-[#111] px-2 py-1 text-[12px] font-medium text-white transition-opacity hover:opacity-85"
                                                >
                                                    <Check className="size-3" />
                                                    {t('تم')}
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={(e) => {
                                                        e.preventDefault();
                                                        setPicking((k) => (k === item.key ? null : item.key));
                                                    }}
                                                    aria-expanded={picking === item.key}
                                                    className="flex items-center gap-1 rounded-[6px] border border-[#e5e5e3] px-2 py-1 text-[12px] text-[#374151] transition-colors hover:bg-[#e8e8e6]"
                                                >
                                                    <Clock className="size-3" />
                                                    {t('تأجيل')}
                                                </button>
                                                {item.seen && (
                                                    <span className="text-[11px] text-[#9ca3af]">{t('مقروء')}</span>
                                                )}
                                            </div>
                                        )}

                                        {picking === item.key && (
                                            <div className="flex flex-wrap gap-1 px-2 pb-2">
                                                {durations.map(([m, label]) => (
                                                    <button
                                                        key={m}
                                                        type="button"
                                                        onClick={(e) => {
                                                            e.preventDefault();
                                                            void snoozeFor(item.key, m);
                                                        }}
                                                        className="rounded-[6px] bg-[#f5f5f4] px-2 py-1 text-[11px] text-[#374151] transition-colors hover:bg-[#e8e8e6]"
                                                    >
                                                        {label}
                                                    </button>
                                                ))}
                                            </div>
                                        )}

                                        {/* ولمَ لم تُحتسب — بنصّ المصدر نفسِه لا بـ«تعذّر» */}
                                        {blocked[item.key] && (
                                            <p className="mx-2 mb-2 flex items-start gap-1 rounded-[6px] bg-[#fef2f2] px-2 py-1.5 text-[11px] text-[#b91c1c]">
                                                <CircleAlert className="mt-px size-3 shrink-0" />
                                                <span>{blocked[item.key]}</span>
                                            </p>
                                        )}
                                    </div>
                                ))}
                                </div>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}

                {/* الحساب — قائمة صفوف تفتح بمرور الماوس: رأس المستخدم، الفرع
                    الافتراضي واختياره، اللغة كمبدّل مقسّم، إدارة الحساب،
                    والخروج. «المظهر» و«اشتراك ركاز» مؤجّلان بطلب المالك. */}
                <DropdownMenu open={accountOpen} onOpenChange={setAccountOpen} modal={false}>
                    <DropdownMenuTrigger asChild>
                        <button
                            onMouseEnter={openAccount}
                            onMouseLeave={closeAccountSoon}
                            className="flex items-center gap-2 rounded-[10px] px-1.5 py-1 transition-colors hover:bg-[rgba(17,17,17,0.045)]"
                        >
                            <Avatar className="size-8">
                                {auth?.user.avatar && <AvatarImage src={auth.user.avatar} alt="" />}
                                <AvatarFallback>{initials(auth?.user.name)}</AvatarFallback>
                            </Avatar>
                            <span className="hidden text-start sm:block">
                                <span className="flex items-center gap-1.5 text-[13px] font-medium leading-tight text-[#111]">
                                    {auth?.user.name}
                                    <GoldBadge tier={context?.tier} compact />
                                </span>
                                <span className="block text-[11px] leading-tight text-[#9ca3af]">
                                    {auth?.user.roleLabel}
                                </span>
                            </span>
                            <ChevronDown className="hidden size-3.5 text-[#9ca3af] sm:block" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="end"
                        className="w-72 p-2"
                        onMouseEnter={openAccount}
                        onMouseLeave={closeAccountSoon}
                    >
                        {/* رأس المستخدم: الاسم والبريد والصورة */}
                        <div className="flex items-center gap-3 px-1.5 pb-2 pt-1">
                            <Avatar className="size-10">
                                {auth?.user.avatar && <AvatarImage src={auth.user.avatar} alt="" />}
                                <AvatarFallback>{initials(auth?.user.name)}</AvatarFallback>
                            </Avatar>
                            <div className="min-w-0 text-start">
                                <p className="truncate text-[14px] font-bold text-[#111]">{auth?.user.name}</p>
                                <p className="truncate text-[12px] text-[#6b7280]">{auth?.user.email}</p>
                            </div>
                        </div>

                        <DropdownMenuSeparator />

                        {/* الفرع الافتراضي — مرشِّح كل رقمٍ في اللوحة، فاسمه
                            مكتوبٌ في العنوان لا في القائمة وحدها */}
                        {context && context.branches.length > 0 && (
                            <>
                                <DropdownMenuLabel className="flex items-center gap-2 text-[12px] font-normal text-[#9ca3af]">
                                    <Building2 className="size-3.5" />
                                    {t('الفرع')}
                                    <span className="ms-auto truncate font-medium text-[#111]">
                                        {context.branchName || t('كل الفروع')}
                                    </span>
                                </DropdownMenuLabel>
                                <DropdownMenuItem asChild>
                                    <a href={route('admin.branch.switch', 'all')} className="text-[14px]">
                                        <span className="flex-1">{t('كل الفروع')}</span>
                                        {!context.branchId && <Check className="size-4" />}
                                    </a>
                                </DropdownMenuItem>
                                {context.branches.map((branch) => (
                                    <DropdownMenuItem key={branch.id} asChild>
                                        <a href={route('admin.branch.switch', branch.id)} className="text-[14px]">
                                            <span className="flex-1 truncate">{branch.name}</span>
                                            {context.branchId === branch.id && <Check className="size-4" />}
                                        </a>
                                    </DropdownMenuItem>
                                ))}
                                <DropdownMenuSeparator />
                            </>
                        )}

                        {/* اللغة — والتبديل يُرسَل إلى مسار اللوحة التي يقف
                            عليها: لمدير المنصة مسارُه وللتاجر مسارُه، ومسارٌ
                            واحد يعني ٤٠٣ لأحدهما */}
                        <DropdownMenuLabel className="flex items-center gap-2 text-[12px] font-normal text-[#9ca3af]">
                            <Languages className="size-3.5" />
                            {t('اللغة')}
                        </DropdownMenuLabel>
                        {(['ar', 'en'] as const).map((code) => (
                            <DropdownMenuItem
                                key={code}
                                className="text-[14px]"
                                onSelect={() => {
                                    if (code === locale) return;
                                    router.post(
                                        route(isPlatform ? 'super-admin.language.update' : 'admin.language.update'),
                                        { locale: code },
                                        { preserveScroll: true },
                                    );
                                }}
                            >
                                <span className="flex-1">{code === 'ar' ? 'العربية' : 'English'}</span>
                                {locale === code && <Check className="size-4" />}
                            </DropdownMenuItem>
                        ))}

                        <DropdownMenuSeparator />

                        {/*
                         * «حسابي» — راتبُه ومسيراته.
                         *
                         * كانت تُفتح من نقطة البيع وحدها، فمن يدخل اللوحة —
                         * بائعٌ أو أمينُ مخزنٍ أو محاسب — لا يجد بابًا إلى
                         * راتبه إلّا أن يعرف عنوانها. ومن لا يدخل اللوحة
                         * يجدها في شريط نقطة البيع كما كان.
                         *
                         * ولا تُعرض لصاحب النشاط: ملفُّه ليس ملفَّ موظّف —
                         * لا مسيرةَ له ولا راتبَ مسجَّلًا، فتُفتح على فراغ.
                         *
                         * ولا لمدير المنصّة: كان الشرط «دورُه ليس admin»،
                         * ومديرُ المنصّة ليس admin — فيُرسم له البند. فإن
                         * ضغطه قذفه `RequiresBusiness` إلى لوحة المنصّة
                         * برسالةٍ عن «لوحة النشاط» لا عن راتب. بابٌ مرسومٌ
                         * لا يؤدّي إلى شيء — وهو ليس موظّفًا أصلًا.
                         *
                         * والسؤالُ يأتي من الخادم (`auth.isEmployee`) لا
                         * يُحسب هنا: قاعدتان تفترقان يومًا — انظر
                         * `User::isEmployee` و`MeController::show`.
                         *
                         * وتُشترط صلاحية «نقطة البيع» لأنّ المسار تحتها: من
                         * لا يملكها يُردّ بـ٤٠٣ — وبابٌ معروضٌ لا يُفتح أسوأ
                         * من بابٍ لا يُعرض.
                         */}
                        {auth?.isEmployee && auth?.abilities.includes('pos') && (
                            <DropdownMenuItem asChild>
                                <Link href={route('pos.me')} className="text-[14px] font-medium">
                                    {t('حسابي وراتبي')}
                                </Link>
                            </DropdownMenuItem>
                        )}

                        {/* إدارة الحساب */}
                        <DropdownMenuItem asChild>
                            <Link href={route('profile.edit')} className="text-[14px] font-medium">
                                {t('إدارة حسابك')}
                            </Link>
                        </DropdownMenuItem>

                        <DropdownMenuSeparator />

                        {/* الخروج */}
                        <DropdownMenuItem
                            destructive
                            onSelect={() => logout(route('logout'), csrf)}
                            className="justify-center text-[14px] font-semibold"
                        >
                            {t('تسجيل الخروج')}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </header>
    );
}
