import { motion } from 'framer-motion';
import {
    Activity,
    AlertTriangle,
    ArrowDownCircle,
    ArrowDownRight,
    ArrowUpRight,
    BadgeCheck,
    BadgeX,
    Banknote,
    Calculator,
    CircleCheck,
    CreditCard,
    Flower,
    Landmark,
    type LucideIcon,
    Package,
    PiggyBank,
    Receipt,
    ShoppingBag,
    TrendingDown,
    TrendingUp,
    UserX,
    Users,
    Wallet,
} from 'lucide-react';
import { Card } from '@/Components/ui/card';
import { cn } from '@/lib/utils';

export interface Stat {
    label: string;
    value: string;
    icon: string;
    color: string;
    trend?: string;
    up?: boolean;
}

const TONE: Record<string, string> = {
    primary: 'bg-[#f5f3ff] text-[#6d28d9]',
    success: 'bg-[#ecfdf5] text-[#047857]',
    warning: 'bg-[#fffbeb] text-[#d97706]',
    danger: 'bg-[#fef2f2] text-[#b91c1c]',
    info: 'bg-[#eff6ff] text-[#2563eb]',
};

/**
 * خريطة صريحة لأسماء الأيقونات القادمة من الخادم.
 * صريحة عمدًا: الاستيراد الشامل من lucide-react يضخّ المكتبة كاملة في الحزمة.
 */
const ICONS: Record<string, LucideIcon> = {
    'alert-triangle': AlertTriangle,
    'arrow-down-circle': ArrowDownCircle,
    'badge-check': BadgeCheck,
    'badge-x': BadgeX,
    banknote: Banknote,
    calculator: Calculator,
    'circle-check': CircleCheck,
    'credit-card': CreditCard,
    flower: Flower,
    landmark: Landmark,
    package: Package,
    'piggy-bank': PiggyBank,
    receipt: Receipt,
    'shopping-bag': ShoppingBag,
    'trending-down': TrendingDown,
    'trending-up': TrendingUp,
    'user-x': UserX,
    users: Users,
    wallet: Wallet,
};

function iconFor(name: string): LucideIcon {
    return ICONS[name] ?? Activity;
}

export default function StatCard({ stat, index = 0 }: { stat: Stat; index?: number }) {
    const Icon = iconFor(stat.icon);

    return (
        <motion.div
            initial={{ opacity: 0, y: 10 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3, delay: index * 0.05, ease: [0.22, 1, 0.36, 1] }}
        >
            <Card className="p-4">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <p className="truncate text-[13px] text-[#6b7280]">{stat.label}</p>
                        <p className="mt-1.5 text-[20px] font-bold tracking-tight text-[#111]">
                            {stat.value}
                        </p>
                    </div>
                    <span
                        className={cn(
                            'flex size-10 shrink-0 items-center justify-center rounded-[12px]',
                            TONE[stat.color] ?? TONE.primary,
                        )}
                    >
                        <Icon className="size-5" />
                    </span>
                </div>

                {stat.trend && (
                    <p
                        className={cn(
                            'mt-3 flex items-center gap-1 text-[12px] font-medium',
                            stat.up ? 'text-[#047857]' : 'text-[#b91c1c]',
                        )}
                    >
                        {stat.up ? (
                            <ArrowUpRight className="size-3.5" />
                        ) : (
                            <ArrowDownRight className="size-3.5" />
                        )}
                        {stat.trend}
                    </p>
                )}
            </Card>
        </motion.div>
    );
}
