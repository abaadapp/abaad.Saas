import { useEffect, useState } from 'react';
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

    return (
        <header className="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-3 border-b border-[var(--ui-border,#e8e8e8)] bg-white/80 px-4 backdrop-blur-md lg:px-6">
            <Button variant="ghost" size="icon" className="lg:hidden" onClick={onMenuClick}>
                <Menu />
                <span className="sr-only">{t('القائمة')}</span>
            </Button>

            {searchUrl && <UnifiedSearch url={searchUrl} />}

            <div className="ms-auto flex items-center gap-1.5">
                {/* مبدّل الفرع */}
                {context && context.branches.length > 0 && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="sm" className="gap-1.5">
                                <GitBranch className="size-4" />
                                <span className="max-w-24 truncate">{context.branchName}</span>
                                <ChevronDown className="size-3.5" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem asChild>
                                <a href={route('admin.branch.switch', 'all')}>
                                    {!context.branchId && <Check className="size-4" />}
                                    {t('كل الفروع')}
                                </a>
                            </DropdownMenuItem>
                            {context.branches.map((branch) => (
                                <DropdownMenuItem key={branch.id} asChild>
                                    <a href={route('admin.branch.switch', branch.id)}>
                                        {context.branchId === branch.id && <Check className="size-4" />}
                                        {branch.name}
                                    </a>
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}

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

                {/* تبديل اللغة */}
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="gap-1.5"
                            aria-label={locale === 'en' ? 'Change language' : 'تغيير اللغة'}
                        >
                            <Languages className="size-4" />
                            <span className="font-medium">{locale === 'en' ? 'EN' : 'ع'}</span>
                            <ChevronDown className="size-3.5" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem onSelect={() => switchLocale('ar')}>
                            {locale === 'ar' && <Check className="size-4" />}
                            العربية
                        </DropdownMenuItem>
                        <DropdownMenuItem onSelect={() => switchLocale('en')}>
                            {locale === 'en' && <Check className="size-4" />}
                            English
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>

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
                            <DropdownMenuLabel>{t('الإشعارات')}</DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            {feed.items.length === 0 ? (
                                <p className="px-3 py-8 text-center text-[13px] text-[#9ca3af]">
                                    {t('لا توجد إشعارات')}
                                </p>
                            ) : (
                                feed.items.slice(0, 6).map((item) => (
                                    <DropdownMenuItem key={item.key} className="flex-col items-start gap-0.5" asChild>
                                        <Link href={item.url ?? '#'}>
                                            <span className="text-[13px] font-medium">{item.text}</span>
                                            {item.time && (
                                                <span className="text-[12px] text-[#6b7280]">{item.time}</span>
                                            )}
                                        </Link>
                                    </DropdownMenuItem>
                                ))
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}

                {/* حساب المستخدم */}
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <button className="flex items-center gap-2 rounded-[10px] px-1.5 py-1 transition-colors hover:bg-[rgba(17,17,17,0.045)]">
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
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-52">
                        <DropdownMenuLabel>{auth?.user.email}</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem asChild>
                            <Link href={route('profile.edit')}>
                                <UserIcon />
                                {t('الملف الشخصي')}
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
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
