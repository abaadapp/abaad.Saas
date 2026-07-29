import { Link, router, usePage } from '@inertiajs/react';
import { Bell, Check, ChevronDown, GitBranch, LogOut, Menu, Search, User as UserIcon } from 'lucide-react';
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
import type { PageProps } from '@/types';

export default function Topbar({ onMenuClick }: { onMenuClick: () => void }) {
    const { auth, context, notifications } = usePage<PageProps>().props;

    return (
        <header className="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-3 border-b border-[var(--ui-border,#e8e8e8)] bg-white/80 px-4 backdrop-blur-md lg:px-6">
            <Button variant="ghost" size="icon" className="lg:hidden" onClick={onMenuClick}>
                <Menu />
                <span className="sr-only">القائمة</span>
            </Button>

            {auth?.user.businessId && (
                <Link
                    href={route('admin.search')}
                    className="hidden items-center gap-2 rounded-[10px] border border-[var(--ui-border,#e8e8e8)] px-3 py-2 text-sm text-[#9ca3af] transition-colors hover:bg-[#fafafa] sm:flex sm:w-64"
                >
                    <Search className="size-4" />
                    <span>بحث…</span>
                </Link>
            )}

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
                                <Link href={route('admin.branch.switch', 'all')}>
                                    {!context.branchId && <Check className="size-4" />}
                                    كل الفروع
                                </Link>
                            </DropdownMenuItem>
                            {context.branches.map((branch) => (
                                <DropdownMenuItem key={branch.id} asChild>
                                    <Link href={route('admin.branch.switch', branch.id)}>
                                        {context.branchId === branch.id && <Check className="size-4" />}
                                        {branch.name}
                                    </Link>
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
                                    <Link href={route('admin.currency.switch', currency.code)}>
                                        {context.currency.code === currency.code && <Check className="size-4" />}
                                        {currency.name}
                                    </Link>
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}

                {/* الإشعارات */}
                {notifications && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" className="relative">
                                <Bell />
                                {notifications.count > 0 && (
                                    <span className="absolute end-1.5 top-1.5 flex size-4 items-center justify-center rounded-full bg-[#dc2626] text-[10px] font-semibold text-white">
                                        {notifications.count > 9 ? '9+' : notifications.count}
                                    </span>
                                )}
                                <span className="sr-only">الإشعارات</span>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-80">
                            <DropdownMenuLabel>الإشعارات</DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            {notifications.items.length === 0 ? (
                                <p className="px-3 py-8 text-center text-[13px] text-[#9ca3af]">
                                    لا توجد إشعارات
                                </p>
                            ) : (
                                notifications.items.slice(0, 6).map((item) => (
                                    <DropdownMenuItem key={item.id} className="flex-col items-start gap-0.5">
                                        <span className="text-[13px] font-medium">{item.title}</span>
                                        {item.body && (
                                            <span className="text-[12px] text-[#6b7280]">{item.body}</span>
                                        )}
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
                                الملف الشخصي
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem destructive onSelect={() => router.post(route('logout'))}>
                            <LogOut />
                            تسجيل الخروج
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </header>
    );
}
