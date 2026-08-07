import { type ReactNode, useEffect } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { Check, ChevronDown, CreditCard, Languages, LayoutDashboard, Lock, LogOut, Receipt, ReceiptText, Settings, Store, User, UserRound, Users, Wallet } from 'lucide-react';
import { router } from '@inertiajs/react';
import { Toaster, toast } from 'sonner';
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
import ImpersonationBar from '@/Components/ImpersonationBar';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/**
 * `ability` هنا اختيارية: الرابط بلا صلاحية يظهر للجميع، والرابط الذي
 * يحملها يظهر لمن يملكها فقط. الحارس الفعلي على الخادم (middleware
 * ability على المسار) — وهذا إخفاءٌ للواجهة لا حماية.
 */
const POS_NAV = [
    { label: 'نقطة البيع', icon: Store, route: 'pos.index' },
    { label: 'الطلبات', icon: ReceiptText, route: 'pos.orders' },
    { label: 'الفواتير', icon: Receipt, route: 'pos.receipts' },
    // حصيلة الصندوق وطرق الدفع — لصاحب النشاط لا للكاشير
    { label: 'المدفوعات', icon: CreditCard, route: 'pos.payments', ability: 'finance' },
    { label: 'العملاء', icon: Users, route: 'pos.customers' },
    { label: 'الوردية', icon: Wallet, route: 'pos.shift' },
] as const;

interface PosLayoutProps {
    title: string;
    children: ReactNode;
    /** شاشة البيع تملأ الشاشة بلا تمرير؛ الصفحات الأخرى تُمرَّر عاديًا */
    fill?: boolean;
}

export default function PosLayout({ title, children, fill = false }: PosLayoutProps) {
    const { auth, context, flash, locale, csrf, posCashier } = usePage<PageProps>().props;
    const t = useTranslate();
    const current = route().current();

    // الأقسام المسموح بها تصل من الخادم — لا نُخمّنها من الدور في الواجهة
    const abilities = auth?.abilities ?? [];
    const navItems = POS_NAV.filter((i) => !('ability' in i) || abilities.includes(i.ability));

    useEffect(() => {
        if (!flash?.toast) return;
        const { msg, type } = flash.toast;
        const fn =
            type === 'success' ? toast.success
            : type === 'danger' ? toast.error
            : type === 'warning' ? toast.warning
            : toast;
        fn(msg);
    }, [flash?.toast]);

    /**
     * لا إعادة تحميل: الاتجاه يُزامَن من خاصية `dir` في app.tsx مع كل
     * استجابة، والترجمات تُعاد حسابها في كل رد. الاعتماد على reload كان
     * يترك الواجهة عربية داخل تخطيط LTR إن تعثّر.
     */
    const switchLocale = (next: 'ar' | 'en') => {
        if (next === locale) return;
        router.post(route('pos.language.update'), { locale: next }, { preserveScroll: true });
    };

    return (
        <div className={cn('pos-scope flex flex-col', fill ? 'h-screen overflow-hidden' : 'min-h-screen')}>
            <Head title={title} />

            <ImpersonationBar />

            <header className="flex h-16 shrink-0 items-center gap-3 border-b border-gray-100 bg-white px-4">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-[12px] bg-[#111] text-white">
                    <Store className="size-[18px]" />
                </span>

                <nav className="flex items-center gap-1 overflow-x-auto">
                    {navItems.map((item) => {
                        const Icon = item.icon;
                        const active = current === item.route;
                        return (
                            <Link
                                key={item.route}
                                href={route(item.route)}
                                className={cn(
                                    'flex items-center gap-2 whitespace-nowrap rounded-full px-3.5 py-2 text-sm font-medium transition-colors',
                                    active
                                        ? 'bg-[#111] text-white'
                                        : 'text-gray-600 hover:bg-gray-100 hover:text-[#111]',
                                )}
                            >
                                <Icon className="size-4" />
                                {t(item.label)}
                            </Link>
                        );
                    })}
                </nav>

                <div className="ms-auto flex items-center gap-1.5">
                    {/*
                        «الخوير • كاشير 01».
                        الجهاز هو من يقرّر الفرع، فعرضُه ليس زينة: من يقف على
                        الصندوق يرى بنظرةٍ أين يبيع، قبل أن يكتشفه من الجرد.
                    */}
                    {context?.deviceName && (
                        <span className="me-1 hidden items-center gap-1.5 rounded-full bg-[#f7f7f5] px-3 py-1 text-[12px] text-[#4b4b4b] md:flex">
                            <Store className="size-3.5 text-gray-400" />
                            <span>
                                {context.branchName} • {context.deviceName}
                            </span>
                        </span>
                    )}

                    {/*
                     * العودة إلى لوحة النشاط. تظهر لمن يدخلها فعلًا — الإشارة
                     * من الخادم لا من الدور، فلا يرى الكاشير زرًّا يقوده إلى 403.
                     */}
                    {auth?.entersPanel && (
                        <Button variant="ghost" size="sm" className="gap-1.5" asChild>
                            <Link href={route('admin.dashboard')}>
                                <LayoutDashboard className="size-4" />
                                <span className="hidden sm:inline">{t('لوحة النشاط')}</span>
                            </Link>
                        </Button>
                    )}

                    {/*
                     * اسم من تُنسب إليه البيعة، لا اسم الحساب المسجَّل. عرضه
                     * دائمًا مقصود: الخطأ الذي يكلّف هو أن يبيع موظفٌ باسم
                     * زميله ساعةً كاملة لأن أحدًا لم ينتبه لمن اختير.
                     */}
                    {posCashier && (
                        <button
                            type="button"
                            onClick={() => router.post(route('pos.cashier.leave'))}
                            className="flex items-center gap-1.5 rounded-full border border-gray-200 px-2.5 py-1 text-[13px] font-medium text-[#111] transition-colors hover:bg-gray-50"
                            title={t('تبديل الموظف')}
                        >
                            <UserRound className="size-3.5 text-gray-400" />
                            <span className="max-w-28 truncate">{posCashier.name}</span>
                        </button>
                    )}

                    {context && context.currencies.length > 1 && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="sm" className="gap-1.5">
                                    <span>{context.currency.symbol || context.currency.code}</span>
                                    <ChevronDown className="size-3.5" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuLabel>{t('عملة العرض')}</DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                {context.currencies.map((c) => (
                                    <DropdownMenuItem key={c.code} asChild>
                                        <a href={route('pos.currency.switch', c.is_base ? 'base' : c.code)}>
                                            {context.currency.code === c.code && <Check className="size-4" />}
                                            {c.name}
                                        </a>
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}

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

                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button className="flex items-center gap-2 rounded-full px-1.5 py-1 transition-colors hover:bg-gray-100">
                                <Avatar className="size-8">
                                    {auth?.user.avatar && <AvatarImage src={auth.user.avatar} alt="" />}
                                    <AvatarFallback>{initials(auth?.user.name)}</AvatarFallback>
                                </Avatar>
                                <span className="hidden text-start sm:block">
                                    <span className="block text-[13px] font-medium leading-tight text-[#111]">
                                        {auth?.user.name}
                                    </span>
                                    <span className="block text-[11px] leading-tight text-gray-400">
                                        {auth?.user.roleLabel}
                                    </span>
                                </span>
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-52">
                            <DropdownMenuItem asChild>
                                <Link href={route('profile.edit')}>
                                    <User />
                                    {t('الملف الشخصي')}
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem asChild>
                                <Link href={route('pos.settings')}>
                                    <Settings />
                                    {t('الإعدادات')}
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            {/*
                                القفل لا الخروج: تنتهي جلسة الموظف ويبقى
                                الجهاز مفعَّلًا، فيدخل التالي بأربعة أرقام.
                                والخروج الكامل يترك الشاشة عند البريد وكلمة
                                المرور — أي يستدعي مديرًا لتبديل كاشير.
                            */}
                            <DropdownMenuItem onSelect={() => router.post(route('pos.lock'))}>
                                <Lock />
                                {t('قفل الشاشة')}
                            </DropdownMenuItem>
                            <DropdownMenuItem destructive onSelect={() => logout(route('logout'), csrf)}>
                                <LogOut />
                                {t('تسجيل الخروج')}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </header>

            <main className={cn(fill ? 'min-h-0 flex-1' : 'flex-1')}>{children}</main>

            <Toaster position="bottom-center" richColors />
        </div>
    );
}
