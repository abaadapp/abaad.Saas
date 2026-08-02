import { router, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { PauseCircle, Play, Trash2 } from 'lucide-react';
import PosLayout from '@/Layouts/PosLayout';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { HeldOrder } from '@/types/models';

export default function PosOrders() {
    const { heldOrders, context } = usePage<PageProps<{ heldOrders: HeldOrder[] }>>().props;
    const t = useTranslate();
    const currency = context!.currency;

    return (
        <PosLayout title={t('الطلبات المعلّقة')}>
            <div className="mx-auto max-w-5xl p-4">
                <div className="mb-4 flex items-center justify-between">
                    <h1 className="text-[20px] font-bold text-[#111]">{t('الطلبات المعلّقة')}</h1>
                    <span className="text-sm text-gray-500">
                        {number(heldOrders.length)} {t('طلب')}
                    </span>
                </div>

                {heldOrders.length === 0 ? (
                    <Card className="flex flex-col items-center py-16 text-center">
                        <div className="mb-3 flex size-16 items-center justify-center rounded-2xl bg-gray-100 text-gray-400">
                            <PauseCircle className="size-8" />
                        </div>
                        <p className="font-semibold text-gray-600">{t('لا توجد طلبات معلّقة')}</p>
                    </Card>
                ) : (
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {heldOrders.map((o, i) => (
                            <motion.div
                                key={o.order_id}
                                initial={{ opacity: 0, y: 10 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ duration: 0.22, delay: Math.min(i * 0.04, 0.3) }}
                            >
                                <Card className="p-4">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <p className="truncate font-semibold text-[#111]">{o.id}</p>
                                            <p className="mt-0.5 truncate text-[13px] text-gray-500">{o.customer}</p>
                                        </div>
                                        <span className="shrink-0 text-[15px] font-bold text-[#111]">
                                            {money(o.total, currency)}
                                        </span>
                                    </div>

                                    <p className="mt-2 text-[12px] text-gray-400">
                                        {t('الكاشير')}: {o.employee}
                                        {o.items_count != null && ` · ${o.items_count} ${t('عنصر')}`}
                                    </p>

                                    <div className="mt-3 flex items-center gap-2">
                                        <Button className="flex-1 rounded-full" asChild>
                                            <a href={route('pos.orders.resume', o.order_id)}>
                                                <Play />
                                                {t('استكمال')}
                                            </a>
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="shrink-0 rounded-full text-[#b91c1c]"
                                            title={t('حذف الطلب')}
                                            onClick={() => {
                                                if (!confirm(t('حذف الطلب المعلّق نهائيًا؟'))) return;
                                                router.delete(route('pos.orders.discard', o.order_id), {
                                                    preserveScroll: true,
                                                });
                                            }}
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                </Card>
                            </motion.div>
                        ))}
                    </div>
                )}
            </div>
        </PosLayout>
    );
}
