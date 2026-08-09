import { useEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Bell,
    Check,
    ChevronDown,
    GitBranch,
    Languages,
    LogOut,
    Menu,
    User as UserIcon,
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
import type { PageProps } from '@/types';

export default function Topbar({ onMenuClick }: { onMenuClick: () => void }) {
    const { auth, context, notifications, locale, csrf } = usePage<PageProps>().props;

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

    /**
     * تبديل اللغة. الخادم يحفظها في الجلسة وفي إعدادات النشاط ثم يعيد
     * التوجيه، لكن اتجاه الصفحة (dir) يُحسم في قالب الجذر — فلا يكفي تحديث
     * Inertia الجزئي، نحتاج إعادة تحميل كاملة ليُعاد بناء الصفحة بالاتجاه الصحيح.
     */
    const isPlatform = auth?.user.role === 'super_admin';

    const switchLocale = (next: 'ar' | 'en') => {
        if (next === locale) return;

        // لكل لوحة مسارها: مسار لوحة التاجر يحرسه middleware الأدوار،
        // فمدير المنصة يُرفض عليه بـ403 ولا تتغيّر لغته.
        router.post(
            route(isPlatform ? 'super-admin.language.update' : 'admin.language.update'),
            { locale: next },
            { preserveScroll: true },
        );
    };

    /**
     * البحث الموحّد — لكل لوحة مسارها. مدير المنصة لا يملك business_id،
     * فربط الزر به وحده كان يخفي بحث المنصة رغم وجود مساره.
     */
    const searchUrl =
        isPlatform ? route('super-admin.search')
        : auth?.user.businessId ? route('admin.search')
        : null;

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
        <header className="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-3 border-b border-[var(--ui-border,#e8e8e8)] bg-white/80 px-4 backdrop-blur-md lg:px-6">
            <Button variant="ghost" size="icon" className="lg:hidden" onClick={onMenuClick}>
                <Menu />
                <span className="sr-only">{t('القائمة')}</span>
            </Button>

            {searchUrl && <UnifiedSearch url={searchUrl} />}

            <div className="ms-auto flex items-center gap-1.5">
                {/* مبدّل العملة — يظهر فقط عند وجود أكثر من واحدة */}
                {context && context.currencies.length > 1 && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="sm" className="gap-1.5">
                                <span>{context.currency.symbol || context.currency.code}</span>
                                <ChevronDown className="size-3.5" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            {context.currencies.map((currency) => (
                                <DropdownMenuItem key={currency.code} asChild>
                                    <a href={route('admin.currency.switch', currency.code)}>
                                        {context.currency.code === currency.code && <Check className="size-4" />}
                                        {currency.name}
                                    </a>
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
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

                {/* الحساب — قائمة موحّدة تفتح بمرور الماوس: المستخدم، الفرع
                    وتحديده، اللغة، إدارة الحساب، والخروج. «المظهر» مؤجّل حتى
                    يوجد وضعٌ داكن حقيقيّ يشمل الواجهة كلّها. */}
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
                        className="w-64"
                        onMouseEnter={openAccount}
                        onMouseLeave={closeAccountSoon}
                    >
                        {/* المستخدم: الاسم والبريد */}
                        <div className="flex items-center gap-2.5 px-2 py-2">
                            <Avatar className="size-9">
                                {auth?.user.avatar && <AvatarImage src={auth.user.avatar} alt="" />}
                                <AvatarFallback>{initials(auth?.user.name)}</AvatarFallback>
                            </Avatar>
                            <div className="min-w-0">
                                <p className="truncate text-[13px] font-semibold text-[#111]">
                                    {auth?.user.name}
                                </p>
                                <p className="truncate text-[12px] text-[#6b7280]">{auth?.user.email}</p>
                            </div>
                        </div>

                        {/* الفرع وتحديده */}
                        {context && context.branches.length > 0 && (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuLabel>{t('الفرع')}</DropdownMenuLabel>
                                <DropdownMenuItem asChild>
                                    <a href={route('admin.branch.switch', 'all')}>
                                        <GitBranch />
                                        <span className="flex-1">{t('كل الفروع')}</span>
                                        {!context.branchId && <Check className="size-4" />}
                                    </a>
                                </DropdownMenuItem>
                                {context.branches.map((branch) => (
                                    <DropdownMenuItem key={branch.id} asChild>
                                        <a href={route('admin.branch.switch', branch.id)}>
                                            <GitBranch />
                                            <span className="flex-1 truncate">{branch.name}</span>
                                            {context.branchId === branch.id && <Check className="size-4" />}
                                        </a>
                                    </DropdownMenuItem>
                                ))}
                            </>
                        )}

                        {/* اللغة */}
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel>{t('اللغة')}</DropdownMenuLabel>
                        <DropdownMenuItem onSelect={() => switchLocale('ar')}>
                            <Languages />
                            <span className="flex-1">العربية</span>
                            {locale === 'ar' && <Check className="size-4" />}
                        </DropdownMenuItem>
                        <DropdownMenuItem onSelect={() => switchLocale('en')}>
                            <Languages />
                            <span className="flex-1">English</span>
                            {locale === 'en' && <Check className="size-4" />}
                        </DropdownMenuItem>

                        {/* إدارة الحساب والخروج */}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem asChild>
                            <Link href={route('profile.edit')}>
                                <UserIcon />
                                {t('إدارة حسابك')}
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem destructive onSelect={() => logout(route('logout'), csrf)}>
                            <LogOut />
                            {t('تسجيل الخروج')}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </header>
    );
}
