import {
    BarChart3,
    HelpCircle,
    Image as ImageIcon,
    Images,
    LayoutPanelTop,
    MapPin,
    Megaphone,
    MessageCircle,
    Percent,
    Phone,
    Share2,
    ShieldCheck,
    ShoppingBag,
    Sparkles,
    Star,
    Tags,
    TrendingUp,
    Video,
} from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';

export interface LibraryItem {
    type: string;
    label: string;
    hint: string;
    group: string;
    unique: boolean;
    source: string | null;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    library: LibraryItem[];
    groups: string[];
    onAdd: (type: string) => void;
}

/**
 * أيقونةُ كلّ نوعٍ — علامةٌ تُميّزه في لمحة.
 *
 * ولا تُرسل من الخادم: هي زينةُ شاشةٍ لا معنًى في المستند، وإرسالُها يعني
 * أنّ تبديل أيقونةٍ تعديلٌ في `Sections::CATALOGUE` — وهو جدولٌ يوصف فيه
 * المحتوى لا شكلُ لوحةِ المحرّر. وما لا أيقونةَ له يأخذ العامّة، فقسمٌ جديد
 * في الخادم يظهر هنا بلا تعديل.
 */
const ICONS: Record<string, typeof Sparkles> = {
    hero: Sparkles,
    image_text: ImageIcon,
    banner: Megaphone,
    gallery: Images,
    video: Video,
    faq: HelpCircle,
    stats: BarChart3,
    benefits: ShieldCheck,
    testimonials: Star,
    featured_products: ShoppingBag,
    latest_products: Sparkles,
    best_sellers: TrendingUp,
    categories: Tags,
    promo: Percent,
    contact: Phone,
    map: MapPin,
    social: Share2,
    whatsapp: MessageCircle,
};

/**
 * مكتبة الأقسام — بطاقاتٌ تُمسح بالعين لا قائمةُ أسماء.
 *
 * من يفتح «أضف قسمًا» لا يعرف الفرق بين «صورة ونصّ» و«عرض خاص» من اسميهما،
 * فكانت المكتبةُ ثمانيةَ عشرَ سطرًا يقرؤها كلَّها ثمّ يختار أوّلها. وبطاقةٌ
 * فيها علامةٌ واسمٌ وسطرٌ تُقرأ في نظرة.
 *
 * والمعروضُ هنا ما يقوله الخادم وحده (`Sections::library`): قسمٌ لا يصلح
 * لوجهة الموقع أو لا يجد ما يعرضه لا يبلغ هذه الشاشة أصلًا. ولا قائمةَ ثانية
 * هنا تخالفه — وإلّا عُرض ما يُرفَض عند الإضافة.
 */
export default function AddSection({ open, onOpenChange, library, groups, onAdd }: Props) {
    const t = useTranslate();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{t('أضف قسمًا')}</DialogTitle>
                </DialogHeader>

                <div className="max-h-[62vh] space-y-5 overflow-y-auto px-5 pb-5">
                    {groups.map((group) => {
                        const items = library.filter((x) => x.group === group);

                        if (items.length === 0) return null;

                        return (
                            <div key={group}>
                                <h4 className="mb-2 text-[11px] font-semibold tracking-wide text-[#9ca3af]">
                                    {group}
                                </h4>
                                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    {items.map((x) => {
                                        const Icon = ICONS[x.type] ?? LayoutPanelTop;

                                        return (
                                            <button
                                                key={x.type}
                                                type="button"
                                                onClick={() => onAdd(x.type)}
                                                className="flex items-start gap-3 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-3 text-start transition-colors hover:border-[#c9c9c9] hover:bg-[#fafafa]"
                                            >
                                                <span className="flex size-9 shrink-0 items-center justify-center rounded-[9px] bg-[#f3f4f6] text-[#6b7280]">
                                                    <Icon className="size-[18px]" />
                                                </span>
                                                <span className="min-w-0">
                                                    <span className="flex items-center gap-1.5">
                                                        <span className="text-[13px] font-semibold text-[#111]">
                                                            {x.label}
                                                        </span>
                                                        {x.source && (
                                                            <Badge variant="info">{t('تلقائي')}</Badge>
                                                        )}
                                                    </span>
                                                    <span className="mt-0.5 block text-[12px] leading-6 text-[#6b7280]">
                                                        {x.hint}
                                                    </span>
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </DialogContent>
        </Dialog>
    );
}
