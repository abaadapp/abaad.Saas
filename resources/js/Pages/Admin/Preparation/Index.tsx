import { router, usePage } from '@inertiajs/react';
import { AlertTriangle, Clock, Gift, MapPin, Phone, Store, Truck, User } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Tabs, { type TabItem } from '@/Components/Tabs';
import { Badge, statusDot } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface PrepItem {
    name: string;
    qty: number;
    note: string | null;
    image: string | null;
}

interface PrepOrder {
    number: string;
    status: string;
    fulfillment: string | null;
    scheduled_for: string | null;
    overdue: boolean;
    recipient: string | null;
    recipient_phone: string | null;
    address: string | null;
    occasion: string | null;
    card_message: string | null;
    sender: string | null;
    hide_sender: boolean;
    delivery_notes: string | null;
    internal_notes: string | null;
    branch: string | null;
    items: PrepItem[];
    /** ما يجوز الانتقال إليه — يصل من الخادم، والحارس هناك أيضًا */
    next: string[];
}

interface Props {
    orders: PrepOrder[];
    filters: { when: string | null; type: string | null };
    counts: { all: number; overdue: number; today: number; tomorrow: number };
    typeCounts: { all: number; delivery: number; pickup: number };
}

/**
 * لوحة التجهيز — شاشة من يصنع الباقة.
 *
 * تستعمل تخطيط اللوحة وبطاقاتها وأزرارها وشاراتها كما هي: هي صفحةٌ جديدة
 * لأنّ الميزة جديدة، لا لأنّ لها شكلًا خاصًّا.
 *
 * ولا يُعرض فيها سعرٌ ولا إجمالي: من يجهّز الورد لا يحتاج أن يعرف هامش
 * المحلّ ليضع ساقًا في مزهرية — والخادم لا يرسل تلك الأعمدة أصلًا.
 */
export default function PreparationIndex() {
    const { orders, filters, counts, typeCounts } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

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

    const move = (number: string, status: string) =>
        router.post(route('admin.preparation.move', number), { status }, { preserveScroll: true });

    return (
        <AdminLayout title="لوحة التجهيز">
            <PageHeader
                title="لوحة التجهيز"
                subtitle={t('الطلبات التي تنتظر التجهيز، مرتّبةً بموعدها — والمتأخّر أوّلًا')}
            />

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

            {orders.length === 0 ? (
                <Card className="p-6 text-center text-sm text-[#6b7280]">
                    {/* «لا شيء» مع مرشّحٍ قائم يُقرأ «لا شيء أبدًا» — فيُقال أيّهما أفرغها */}
                    {filters.type === 'delivery'
                        ? t('لا طلبات توصيل تنتظر التجهيز')
                        : filters.type === 'pickup'
                          ? t('لا طلبات استلام تنتظر التجهيز')
                          : t('لا طلبات تنتظر التجهيز')}
                </Card>
            ) : (
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {orders.map((o) => (
                        <Card key={o.number} className="flex flex-col gap-3 p-5">
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <p className="font-bold text-[#111]">{o.number}</p>
                                    <p className="mt-0.5 flex items-center gap-1.5 text-[12px] text-[#6b7280]">
                                        <Clock className="size-3.5" />
                                        <span dir="ltr">{o.scheduled_for ?? '—'}</span>
                                    </p>
                                </div>
                                <div className="flex flex-col items-end gap-1.5">
                                    <Badge status={o.status}>{t(o.status)}</Badge>
                                    {o.overdue && (
                                        <span className="flex items-center gap-1 text-[12px] font-medium text-[#b91c1c]">
                                            <AlertTriangle className="size-3.5" />
                                            {t('متأخّر')}
                                        </span>
                                    )}
                                </div>
                            </div>

                            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-[#6b7280]">
                                {o.fulfillment && (
                                    <span className="flex items-center gap-1">
                                        <Truck className="size-3.5" />
                                        {t(o.fulfillment === 'delivery' ? 'توصيل' : 'استلام من المحل')}
                                    </span>
                                )}
                                {o.recipient && (
                                    <span className="flex items-center gap-1">
                                        <User className="size-3.5" />
                                        {o.recipient}
                                    </span>
                                )}
                                {o.recipient_phone && (
                                    <span className="flex items-center gap-1" dir="ltr">
                                        <Phone className="size-3.5" />
                                        {o.recipient_phone}
                                    </span>
                                )}
                                {o.occasion && <span>{t(o.occasion)}</span>}
                            </div>

                            {o.address && (
                                <p className="flex items-start gap-1.5 text-[12px] text-[#6b7280]">
                                    <MapPin className="mt-0.5 size-3.5 shrink-0" />
                                    {o.address}
                                </p>
                            )}

                            <ul className="flex flex-col gap-2 border-t border-[var(--ui-border,#e8e8e8)] pt-3 text-sm">
                                {o.items.map((i, idx) => (
                                    <li key={idx} className="flex items-center gap-2.5">
                                        {i.image ? (
                                            <img
                                                src={i.image}
                                                alt=""
                                                className="size-9 shrink-0 rounded-[8px] object-cover"
                                            />
                                        ) : (
                                            <span className="size-9 shrink-0 rounded-[8px] bg-gray-100" />
                                        )}
                                        <span className="flex-1 text-[#111]">{i.name}</span>
                                        <span className="font-bold tabular-nums text-[#111]">×{i.qty}</span>
                                    </li>
                                ))}
                            </ul>

                            {o.card_message && (
                                <div className="rounded-[10px] bg-[#fdf2f8] p-3 text-[12px] text-[#831843]">
                                    <p className="mb-1 flex items-center gap-1.5 font-medium">
                                        <Gift className="size-3.5" />
                                        {t('بطاقة الإهداء')}
                                    </p>
                                    <p className="leading-relaxed">{o.card_message}</p>
                                    {o.sender && (
                                        <p className="mt-1 text-[#9d174d]">
                                            {t('من')}: {o.sender}
                                            {o.hide_sender && ` · ${t('مخفيّ عن المستلِم')}`}
                                        </p>
                                    )}
                                </div>
                            )}

                            {(o.delivery_notes || o.internal_notes) && (
                                <div className="rounded-[10px] bg-gray-50 p-3 text-[12px] text-[#6b7280]">
                                    {o.delivery_notes && <p>{o.delivery_notes}</p>}
                                    {o.internal_notes && <p className="mt-1">{o.internal_notes}</p>}
                                </div>
                            )}

                            {o.next.length > 0 && (
                                <div className="flex flex-wrap gap-2 border-t border-[var(--ui-border,#e8e8e8)] pt-3">
                                    {o.next.map((s) => (
                                        <Button
                                            key={s}
                                            variant="outline"
                                            size="sm"
                                            onClick={() => move(o.number, s)}
                                        >
                                            {t(s)}
                                        </Button>
                                    ))}
                                </div>
                            )}
                        </Card>
                    ))}
                </div>
            )}
        </AdminLayout>
    );
}
