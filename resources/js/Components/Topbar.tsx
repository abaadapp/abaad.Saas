import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Bell,
    Building2,
    Check,
    ChevronDown,
    Globe,
    Languages,
    Menu,
    Store,
    X,
} from 'lucide-react';
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
import type { PageProps } from '@/types';

export default function Topbar({ onMenuClick }: { onMenuClick: () => void }) {
    const { auth, context, notifications, reportPages, csrf, locale } = usePage<PageProps>().props;

    /**
     * تغذية الجرس الحيّة — بديل استطلاع admin.notifications.feed الذي كان في
     * التخطيط القديم. يبدأ مما أرسله الخادم مع الصفحة ثم يستطلع كل 30 ثانية،
     * فيظهر الطلب الجديد بلا إعادة تحميل. يتوقف عند إخفاء التبويب.
     */
    const [feed, setFeed] = useState(notifications);
    useEffect(() => setFeed(notifications), [notifications]);
    useEffect(() => {
        if (!notifications) return;
        let alive = true;
        const id = setInterval(async () => {
            if (document.hidden) return;
            try {
                const res = await fetch('/admin/notifications/feed', {
                    headers: { Accept: 'application/json' },
                });
                if (!res.ok || !alive) return;
                const data = await res.json();
                if (alive) setFeed({ items: data.items ?? [], count: data.count ?? 0 });
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
     * حذف التنبيه من الجرس.
     *
     * المسار موجود منذ مدّة ولا يستدعيه إلا شاشة الإعدادات — فالتاجر الذي
     * يرى تنبيهًا انتهى أمره لا سبيل له إلى إزالته من حيث يراه. والإزالة
     * محلّية أولًا ثم تُرسل: انتظار الشبكة يجعل النقرة تبدو بلا أثر.
     */
    const post = (url: string) =>
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf ?? '',
                Accept: 'application/json',
            },
        }).catch(() => {
            // انقطاع الشبكة: يعود التنبيه عند أوّل استطلاع، ولا شيء يضيع
        });

    const dismissOne = (key: string) => {
        setFeed((f) => (f ? { items: f.items.filter((i) => i.key !== key), count: Math.max(0, f.count - 1) } : f));
        void fetch('/admin/notifications/dismiss', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf ?? '',
                Accept: 'application/json',
            },
            body: JSON.stringify({ key }),
        }).catch(() => {});
    };

    const dismissAll = () => {
        setFeed((f) => (f ? { items: [], count: 0 } : f));
        void post('/admin/notifications/clear');
    };

    const isPlatform = auth?.user.role === 'super_admin';

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
            <div className="ms-auto flex items-center gap-1.5">
                {context && (
                    <>
                        <Button asChild variant="ghost" size="icon" title={t('نقطة البيع')}>
                            <Link href={route('pos.index')}>
                                <Store />
                                <span className="sr-only">{t('نقطة البيع')}</span>
                            </Link>
                        </Button>

                        {/* موقع التاجر إن ضُبط، وإلا فزرٌّ يدلّ على الإعدادات
                            لإضافته — فلا يقف بلا وظيفة. */}
                        {context.website ? (
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
                        <DropdownMenuContent align="end" className="w-80">
                            <DropdownMenuLabel className="flex items-center justify-between gap-2">
                                <span>{t('الإشعارات')}</span>
                                {feed.items.length > 0 && (
                                    <button
                                        type="button"
                                        onClick={(ev) => {
                                            ev.preventDefault();
                                            dismissAll();
                                        }}
                                        className="text-[12px] font-normal text-[#6b7280] transition-colors hover:text-[#b91c1c]"
                                    >
                                        {t('حذف الكل')}
                                    </button>
                                )}
                            </DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            {feed.items.length === 0 ? (
                                <p className="px-3 py-8 text-center text-[13px] text-[#9ca3af]">
                                    {t('لا توجد إشعارات')}
                                </p>
                            ) : (
                                feed.items.slice(0, 6).map((item) => (
                                    /* صفٌّ لا DropdownMenuItem: زرّ الحذف داخل عنصرٍ
                                       قابل للاختيار كان يُغلق القائمة عند كل نقرة */
                                    <div
                                        key={item.key}
                                        className="group flex items-start gap-1 rounded-[8px] px-1 transition-colors hover:bg-[#f5f5f4]"
                                    >
                                        <Link
                                            href={item.url ?? '#'}
                                            className="flex min-w-0 flex-1 flex-col gap-0.5 px-2 py-2"
                                        >
                                            <span className="text-[13px] font-medium text-[#111]">{item.text}</span>
                                            {item.time && (
                                                <span className="text-[12px] text-[#6b7280]">{item.time}</span>
                                            )}
                                        </Link>
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
                                    </div>
                                ))
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
                                <span className="block text-[13px] font-medium leading-tight text-[#111]">
                                    {auth?.user.name}
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
