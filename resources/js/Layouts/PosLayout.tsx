import { type ReactNode, useEffect } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { Check, ChevronDown, Languages, LogOut, Receipt, ReceiptText, Settings, Store, User, Users } from 'lucide-react';
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
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

const POS_NAV = [
    { label: 'نقطة البيع', icon: Store, route: 'pos.index' },
    { label: 'الطلبات', icon: ReceiptText, route: 'pos.orders' },
    { label: 'الفواتير', icon: Receipt, route: 'pos.receipts' },
    { label: 'العملاء', icon: Users, route: 'pos.customers' },
];

interface PosLayoutProps {
    title: string;
    children: ReactNode;
    /** شاشة البيع تملأ الشاشة بلا تمرير؛ الصفحات الأخرى تُمرَّر عاديًا */
    fill?: boolean;
}

export default function PosLayout({ title, children, fill = false }: PosLayoutProps) {
    const { auth, context, flash, locale } = usePage<PageProps>().props;
    const t = useTranslate();
    const current = route().current();

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

    const switchLocale = (next: 'ar' | 'en') => {
        if (next === locale) return;
        router.post(route('pos.language.update'), { locale: next }, { onSuccess: () => window.location.reload() });
    };

    return (
        <div className={cn('pos-scope flex flex-col', fill ? 'h-screen overflow-hidden' : 'min-h-screen')}>
            <Head title={title} />

            <header className="flex h-16 shrink-0 items-center gap-3 border-b border-gray-100 bg-white px-4">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-[12px] bg-[#111] text-white">
                    <Store className="size-[18px]" />
                </span>

                <nav className="flex items-center gap-1 overflow-x-auto">
                    {POS_NAV.map((item) => {
                        const Icon = item.icon;
                        const active = current === item.route;
                        return (
                            <a
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
                            </a>
                        );
                    })}
                </nav>

                <div className="ms-auto flex items-center gap-1.5">
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
                                <a href={route('profile.edit')}>
                                    <User />
                                    {t('الملف الشخصي')}
                                </a>
                            </DropdownMenuItem>
                            <DropdownMenuItem asChild>
                                <a href={route('pos.settings')}>
                                    <Settings />
                                    {t('الإعدادات')}
                                </a>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem destructive onSelect={() => router.post(route('logout'))}>
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
