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
    Boxes,
    Building2,
    Calculator,
    CircleAlert,
    CircleCheck,
    ClipboardList,
    Clock,
    Coins,
    CreditCard,
    FileText,
    Flower,
    GitBranch,
    Hourglass,
    Landmark,
    Layers,
    Minus,
    Package,
    PackageCheck,
    Percent,
    PiggyBank,
    Receipt,
    RefreshCw,
    ShoppingBag,
    ShoppingCart,
    Star,
    Store,
    TrendingDown,
    TrendingUp,
    Truck,
    type LucideIcon,
    UserPlus,
    Users,
    UserX,
    Wallet,
} from 'lucide-react';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface Stat {
    label: string;
    value: string;
    icon: string;
    color: string;
    trend?: string;
    up?: boolean;
    /**
     * معنى الاتجاه — حسنٌ أم سيّئ — مستقلًّا عن جهته.
     *
     * `up` وحده يقول الجهةَ واللونَ معًا: الصاعدُ أخضر. وهذا صوابٌ في
     * المبيعات وخطأٌ في التكاليف: تكلفةٌ زادت سهمُها صاعدٌ ومعناها سيّئ.
     * فمن مرّر `tone` لوّن به، والسهمُ يبقى من `up`، و`neutral` بلا سهم.
     * ومن لم يمرّره يبقى كما كان بالضبط — الصاعدُ أخضر والنازلُ أحمر.
     */
    tone?: 'good' | 'bad' | 'neutral';
    /** سطرٌ صغيرٌ تحت الرقم يقول ما يجمعه — اختياريّ */
    hint?: string;
}

/** ألوانُ معنى الاتجاه — من ألوان البطاقة نفسِها لا لوحةٍ جديدة */
const TREND_TONE: Record<NonNullable<Stat['tone']>, string> = {
    good: 'text-[#047857]',
    bad: 'text-[#b91c1c]',
    neutral: 'text-[#6b7280]',
};

/** لونُ سطر الاتجاه — بمعناه إن قيل، وإلّا بجهته كما كان */
export function trendClass(stat: Pick<Stat, 'up' | 'tone'>): string {
    if (stat.tone) {
        return TREND_TONE[stat.tone];
    }

    return stat.up ? 'text-[#047857]' : 'text-[#b91c1c]';
}

const TONE: Record<string, string> = {
    primary: 'bg-[#f5f3ff] text-[#6d28d9]',
    secondary: 'bg-[#fdf2f8] text-[#be185d]',
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
    activity: Activity,
    'alert-triangle': AlertTriangle,
    'arrow-down-circle': ArrowDownCircle,
    'badge-check': BadgeCheck,
    hourglass: Hourglass,
    'badge-x': BadgeX,
    banknote: Banknote,
    'building-2': Building2,
    calculator: Calculator,
    'circle-alert': CircleAlert,
    'circle-check': CircleCheck,
    'credit-card': CreditCard,
    flower: Flower,
    'git-branch': GitBranch,
    landmark: Landmark,
    layers: Layers,
    package: Package,
    'refresh-cw': RefreshCw,
    'piggy-bank': PiggyBank,
    receipt: Receipt,
    'shopping-bag': ShoppingBag,
    'trending-down': TrendingDown,
    'trending-up': TrendingUp,
    'user-plus': UserPlus,
    'user-x': UserX,
    users: Users,
    wallet: Wallet,
    boxes: Boxes,
    percent: Percent,
    'shopping-cart': ShoppingCart,
    store: Store,
    truck: Truck,
    coins: Coins,
    'file-text': FileText,
    clock: Clock,
    star: Star,
    'clipboard-list': ClipboardList,
    'package-check': PackageCheck,
};

function iconFor(name: string): LucideIcon {
    return ICONS[name] ?? Activity;
}

export default function StatCard({ stat, index = 0 }: { stat: Stat; index?: number }) {
    const Icon = iconFor(stat.icon);
    const t = useTranslate();

    return (
        <motion.div
            initial={{ opacity: 0, y: 10 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3, delay: index * 0.05, ease: [0.22, 1, 0.36, 1] }}
        >
            <Card className="p-4">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        {/*
                            الترجمة هنا لا في كل صفحة: أكثر الشاشات تمرّر العنوان
                            عربيًّا خامًا، فكانت بطاقات الإحصاء تبقى عربية في
                            الوضع الإنجليزي رغم وجود ترجماتها في en.json. ومن
                            يمرّر نصًّا مترجمًا مسبقًا لا يتأثّر: المفتاح غير
                            الموجود في القاموس يعود كما هو.
                        */}
                        <p className="truncate text-[13px] text-[#6b7280]">{t(stat.label)}</p>
                        <p className="mt-1.5 text-[20px] font-bold tracking-tight text-[#111]">
                            {stat.value}
                        </p>
                        {stat.hint && (
                            <p className="mt-1 text-[11.5px] leading-snug text-[#9ca3af]" data-testid="stat-hint">
                                {t(stat.hint)}
                            </p>
                        )}
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
                        className={cn('mt-3 flex items-center gap-1 text-[12px] font-medium', trendClass(stat))}
                        data-testid="stat-trend"
                        data-tone={stat.tone ?? (stat.up ? 'up' : 'down')}
                    >
                        {stat.tone === 'neutral' ? (
                            <Minus className="size-3.5" aria-hidden />
                        ) : stat.up ? (
                            <ArrowUpRight className="size-3.5" aria-hidden />
                        ) : (
                            <ArrowDownRight className="size-3.5" aria-hidden />
                        )}
                        {t(stat.trend)}
                    </p>
                )}
            </Card>
        </motion.div>
    );
}
