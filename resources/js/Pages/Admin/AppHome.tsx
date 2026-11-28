import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { ClipboardList, LayoutDashboard, Link2, ReceiptText, RefreshCw, Users, type LucideIcon } from 'lucide-react';
import Logo from '@/Components/Logo';
import StatCard, { type Stat } from '@/Components/StatCard';
import { useTranslate } from '@/lib/i18n';
import { isPhone } from '@/lib/installed-app';
import type { PageProps } from '@/types';

/**
 * أبعاد تطبيقًا (`/app`) — ما يفتحه الهاتفُ حين يُثبَّت.
 *
 * شاشةٌ واحدة بلا قائمةٍ جانبيّة ولا شريطٍ علويّ: أرقامُ اليوم، ثمّ أزرارٌ
 * قليلة، ثمّ «النظام كاملًا» — واللوحةُ نفسُها تفتح داخل التطبيق.
 *
 * والأزرارُ ما أرسله الخادمُ وحده: لا يُرسل زرًّا لا يفتح صاحبُه صفحتَه
 * (`DashboardController::app`)، فلا تُخمّن الصلاحيّةُ هنا.
 */

export interface AppLink {
    key: string;
    label: string;
    url: string;
}

const ICONS: Record<string, LucideIcon> = {
    orders: ClipboardList,
    customers: Users,
    paymentLink: Link2,
    sales: ReceiptText,
};

export function AppLinks({ links }: { links: AppLink[] }) {
    if (links.length === 0) {
        return null;
    }

    return (
        <nav className="grid grid-cols-2 gap-3" data-app-links>
            {links.map((link) => {
                const Icon = ICONS[link.key] ?? LayoutDashboard;

                return (
                    <Link
                        key={link.key}
                        href={link.url}
                        className="flex min-h-24 flex-col items-center justify-center gap-2 rounded-2xl border border-gray-200 bg-white p-4 text-sm font-semibold text-gray-900 shadow-sm active:scale-[0.98]"
                    >
                        <Icon className="size-6" aria-hidden />
                        {link.label}
                    </Link>
                );
            })}
        </nav>
    );
}

export { isPhone } from '@/lib/installed-app';

export default function AppHome({ cards, links, fullUrl }: { cards: Stat[]; links: AppLink[]; fullUrl: string }) {
    const t = useTranslate();
    const { context } = usePage<PageProps>().props;
    const phone = isPhone();

    /*
     * الآيبادُ والحاسوبُ يتّسعان للنظام كلِّه: تُستبدل هذه الصفحةُ باللوحة
     * (`replace` — فلا يرجع «للخلف» إلى بابٍ يعيده إليها).
     */
    useEffect(() => {
        if (!phone) {
            router.visit(fullUrl, { replace: true });
        }
    }, [phone, fullUrl]);

    /*
     * التطبيقُ المثبَّت على iPhone لا يُسحب للتحديث: الأرقامُ تُجدَّد حين
     * يعود إلى الشاشة، وبالزرّ. ومعها الأزرار: صلاحيّةٌ سُحبت والتطبيقُ
     * مفتوح لا يبقى زرُّها يردّ بـ403.
     */
    useEffect(() => {
        if (!phone) {
            return;
        }
        const refresh = () => {
            if (document.visibilityState === 'visible') {
                router.reload({ only: ['cards', 'links'] });
            }
        };
        document.addEventListener('visibilitychange', refresh);

        return () => document.removeEventListener('visibilitychange', refresh);
    }, [phone]);

    if (!phone) {
        return <div className="min-h-dvh bg-[#f7f8f9]" aria-busy />;
    }

    return (
        <div className="admin-ui min-h-dvh bg-[#f7f8f9]">
            <Head title={t('لوحة التحكم')} />

            <div className="mx-auto flex max-w-md flex-col gap-5 px-4 pb-6 pt-4">
                <header className="flex items-center justify-between gap-3">
                    <h1 className="min-w-0 truncate text-xl font-bold text-gray-900">{context?.businessName}</h1>
                    <div className="flex shrink-0 items-center gap-3">
                        <button
                            type="button"
                            onClick={() => router.reload({ only: ['cards', 'links'] })}
                            aria-label={t('تحديث')}
                            className="grid size-10 place-items-center rounded-full border border-gray-200 bg-white text-gray-500"
                        >
                            <RefreshCw className="size-4" aria-hidden />
                        </button>
                        <Logo className="h-5 w-auto text-gray-900" />
                    </div>
                </header>

                <section className="flex flex-col gap-3" aria-label={t('اليوم')}>
                    {cards.map((stat, i) => (
                        <StatCard key={stat.label} stat={stat} index={i} />
                    ))}
                </section>

                <AppLinks links={links} />

                <Link
                    href={fullUrl}
                    className="flex items-center justify-center gap-2 rounded-2xl bg-[#111111] px-4 py-4 text-sm font-semibold text-white active:scale-[0.99]"
                >
                    <LayoutDashboard className="size-4" aria-hidden />
                    {t('فتح النظام كاملًا')}
                </Link>
            </div>
        </div>
    );
}
