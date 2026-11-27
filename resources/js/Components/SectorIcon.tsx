import {
    Briefcase,
    Building2,
    Car,
    Coffee,
    Flower2,
    Gift,
    GraduationCap,
    Heart,
    type LucideIcon,
    Package,
    Scissors,
    ShoppingBag,
    ShoppingCart,
    Sparkles,
    Stethoscope,
    Store,
    Truck,
    UtensilsCrossed,
    Wrench,
} from 'lucide-react';

/**
 * أيقوناتُ القطاع — القائمةُ المغلقة نفسُها في الخادم (`Sector::ICONS`).
 *
 * ومفتاحٌ لا تعرفه القائمة يُرسم متجرًا: أيقونةٌ فارغةٌ تبدو عطبًا.
 */
export const SECTOR_ICONS: Record<string, LucideIcon> = {
    store: Store,
    'shopping-bag': ShoppingBag,
    'shopping-cart': ShoppingCart,
    package: Package,
    flower: Flower2,
    gift: Gift,
    utensils: UtensilsCrossed,
    coffee: Coffee,
    scissors: Scissors,
    sparkles: Sparkles,
    stethoscope: Stethoscope,
    briefcase: Briefcase,
    building: Building2,
    'graduation-cap': GraduationCap,
    car: Car,
    wrench: Wrench,
    truck: Truck,
    heart: Heart,
};

export default function SectorIcon({ icon, className }: { icon: string; className?: string }) {
    const Icon = SECTOR_ICONS[icon] ?? Store;

    return <Icon className={className} aria-hidden />;
}
