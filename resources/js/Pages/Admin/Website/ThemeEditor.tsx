import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Check,
    ChevronDown,
    ChevronLeft,
    CircleAlert,
    ExternalLink,
    Eye,
    EyeOff,
    Loader2,
    Monitor,
    RefreshCw,
    Save,
    Smartphone,
    Tablet,
} from 'lucide-react';

import AdminLayout from '@/Layouts/AdminLayout';
import { useConfirm } from '@/Components/ConfirmDialog';
import Field from '@/Components/Field';
import StoreImageField from '@/Components/StoreImageField';
import Toggle from '@/Components/Toggle';
import { Button } from '@/Components/ui/button';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { uploads, useUploadFailures, useUploadsInFlight } from '@/lib/uploads';
import { cn } from '@/lib/utils';
import { type ArrivalsCuration, CategoriesPanel, type CatalogTools, NewArrivalsPanel } from './theme/CatalogTools';
import CatalogFitField from './theme/CatalogFitField';
import CollectionsField, { parseCollections } from './theme/CollectionsField';
import FooterField from './theme/FooterField';
import MenuField, { parseMenu } from './theme/MenuField';
import { parsePages, withoutEmptyBlocks } from './theme/pages';
import ShopRowField from './theme/ShopRowField';
import type { TargetSources } from './theme/StoreTargets';
import { AlignField, type ShortcutCategory, ShortcutsField } from './theme/HeaderFields';
import ProductListField, { keepShown, type ListProduct } from './theme/ProductListField';
import ShelfOrderPanel, { type ShelfOrderSeed } from './theme/ShelfOrderField';
import PublishBar, { type PublishState } from './theme/PublishBar';
import ThemeHeader, { type ThemeShell } from './theme/Shell';
import VisualBarField from './theme/VisualBarField';

/** صفحةٌ من صفحات القالب الأربع — كما تقرؤها `StoreNav::rows` */
export interface EditorPage {
    key: string;
    path: string;
    fixed: boolean;
    on: boolean;
    blocked: string | null;
}

/**
 * بابُ تحرير كلّ صفحة — ولكلٍّ بابٌ مختلف.
 *
 * فمحرّرُ الأقسام هذا للرئيسية وحدَها: «المتجر» بضاعتُه تُكتب في المنتجات،
 * و«من نحن» نبذتُها هي نبذةُ قسم «عنّا» في هذه الصفحة نفسِها، و«تواصل معنا»
 * هاتفُه وعنوانُه في إعدادات النشاط.
 *
 * والمنتقي ينقل إلى الباب ولا يدّعي أنّه يُحرّرها هنا — ومنتقًى يُبدّل
 * الاسمَ ولا يُبدّل ما تحته مقبضٌ شكليّ.
 */
const PAGE_DOOR: Record<string, { label: string; route: string; row: string | null }> = {
    home: { label: 'الرئيسية', route: 'admin.website.design', row: null },
    shop: { label: 'المتجر', route: 'admin.products.index', row: null },
    about: { label: 'من نحن', route: 'admin.website.design', row: 'about' },
    contact: { label: 'تواصل معنا', route: 'admin.settings.index', row: null },
};

/**
 * منتقي الصفحات — يقول أيَّ صفحةٍ تُحرَّر، وينقل إلى باب أختها.
 *
 * ═══ ولمَ ينقل ولا يُبدّل ═══
 *
 * صفحاتُ القالب أربعٌ ومحتواها في أربعة أبواب، ومحرّرُ الأقسام هذا للرئيسية
 * وحدَها. فمنتقٍ يُبدّل الاسمَ ويترك الأقسامَ كما هي مقبضٌ يكذب: يقرأ صاحبُه
 * «تواصل معنا» ويرتّب أقسامَ الرئيسية وهو يحسبها صفحةَ التواصل.
 *
 * وصفحةٌ مُطفأةٌ أو بلا محتوًى لا تُعرض — `StoreNav::rows` تُسقطها كما
 * تُسقطها من قائمة زبونه، فلا يفتح المنتقي بابًا يردّ «غير موجود».
 */
function PagePicker({ pages }: { pages: EditorPage[] }) {
    const t = useTranslate();
    const live = pages.filter((p) => p.on && p.blocked === null && PAGE_DOOR[p.key]);

    return (
        <select
            data-testid="page-picker"
            aria-label={t('الصفحة التي تُحرَّر')}
            value="home"
            onChange={(e) => {
                const door = PAGE_DOOR[e.target.value];

                if (door) router.visit(route(door.route, door.row ? { row: door.row } : undefined));
            }}
            className="min-w-0 rounded-[8px] border border-[var(--ui-border,#e8e8e8)] bg-white px-2.5 py-1.5 text-[13px] font-semibold text-[#111]"
        >
            {live.map((p) => (
                <option key={p.key} value={p.key}>
                    {t(PAGE_DOOR[p.key].label)}
                </option>
            ))}
        </select>
    );
}

/** مقاساتُ المعاينة — عرضُ الجهاز كما يفتحه الزبون، لا كسرٌ من شاشة التاجر */
export type PreviewSize = 'desktop' | 'tablet' | 'phone';

export const DEVICE_WIDTH: Record<PreviewSize, number> = {
    desktop: 1280,
    tablet: 820,
    phone: 390,
};

/**
 * ارتفاعُ ما يُرى من المعاينة — ثابتٌ مهما تبدّل المقاس.
 *
 * والإطارُ يأخذ منه مقسومًا على المقياس ليعود بعد التصغير إليه: فصندوقُ
 * المعاينة لا يصير شريطًا ارتفاعُه ٢٩٥ بكسل حين يُختار الكمبيوتر، ويرى
 * التاجرُ من صفحته قدرًا واحدًا في المقاسات الثلاثة.
 */
export const FRAME_HEIGHT = 'max(480px, 70vh)';

/**
 * ما يُصغَّر به الإطارُ ليسع عمودَ المعاينة.
 *
 * ولا يُكبَّر أبدًا: `Math.min(1, ...)` تمنع هاتفًا عرضُه ٣٩٠ أن يُنفخ إلى
 * عرض العمود فيُري التاجرَ حروفًا أكبرَ ممّا يراها زبونُه.
 *
 * وعمودٌ لم يُقَس بعد (`avail === 0` في أوّل رسمة، وفي jsdom دائمًا) يردّ
 * الواحدَ لا القسمةَ على صفر.
 */
export function previewFit(want: number, avail: number): number {
    if (avail <= 0) return 1;

    return Math.min(1, avail / want);
}

export interface EditorField {
    key: string;
    kind: 'text' | 'textarea' | 'image' | 'toggle' | 'featured' | 'align' | 'shortcuts' | 'products' | 'collections' | 'visualbar' | 'menu' | 'pages' | 'shoprow' | 'catalogfit' | 'footer';
    label: string;
    hint?: string;
    dir?: string;
    gate?: string;
    /** أكثرُ ما تختاره `products` — من الخادم، لا رقمٌ ثانٍ هنا */
    max?: number;
    /** وأكثرُ ما في المجموعة الواحدة — لـ`collections` */
    max_products?: number;
    /** وأكثرُ الصفحات المخصّصة ووحداتِ كلٍّ منها — لـ`menu` */
    max_pages?: number;
    max_blocks?: number;
    /** مفاتيحُ يرسمها هذا الحقلُ في مواضعها منه — فلا تُرسم في الصفّ ثانيةً */
    owns?: string[];
    /** مفتاحُ طريقة عرض الصورة — لـ`image`، يُحفظ معها في صفّها */
    fit?: string;
    /** ونسبةُ إطارها في المتجر — لمعاينتها كما ستُرى */
    ratio?: string;
}

export interface EditorRow {
    key: string;
    label: string;
    hint: string;
    fixed: boolean;
    /** بابٌ أوّل (`main`) أو تحت «إعدادات متقدّمة» (`advanced`) — `PageEditor::rows` */
    group: 'main' | 'advanced';
    on: boolean;
    silent: string | null;
    source: { label: string; route: string } | null;
    fields: EditorField[];
}

interface Props extends ThemeShell {
    /** حالُ النشر — و`null` لمن لم يُفتح له النظام بعد */
    publishing: PublishState | null;
    /** صفحاتُ القالب الأربع — للمنتقي، لا للتحرير هنا */
    pages: EditorPage[];
    rows: EditorRow[];
    values: Record<string, string>;
    order: string[];
    /**
     * الترتيبُ كلُّه بالمطفأ في موضعه — `['cats', '!best', 'new']` (`StorePage::draftLayout`).
     * وغيابُه يعني ما كان: المشغَّلُ (`order`) ثمّ المطفأُ بترتيب الصفوف.
     */
    layout?: string[];
    maxFeatured: number;
    products: ListProduct[];
    /** ما اختاره في مجموعاته وصفحاته ثمّ أخفاه — يُعرض باسمه ولا يُسقَط */
    hiddenProducts?: ListProduct[];
    /** هاتفُه وبريدُه وعنوانُه من «بيانات النشاط» — يقرؤها محرّرُ التذييل */
    contact?: { phone?: string; email?: string; address?: string };
    /** فئاتُ متجره لاختصارات صفّ «المتجر» في رأسه — انظر `StoreHeader` */
    shortcutCategories?: ShortcutCategory[];
    maxShortcuts?: number;
    /**
     * لوحتا الفئات و«وصل حديثًا» — لمتجرٍ في قائمتها وحده (`CatalogTools`).
     * وغيابُها يعني الشاشةَ كما كانت: سطرُ «يُكتب في…» في الصفّين.
     */
    catalogTools?: CatalogTools | null;
    /**
     * ترتيبُ المنتجات في الرفّ — لمتجرٍ في قائمته وحده (`ShelfOrder::forEditor`).
     * وغيابُه يعني الرفَّ بالاسم كما كان، ولا لوحةَ تُرسم.
     */
    shelfOrder?: ShelfOrderSeed | null;
}

/** ما يقوله سطرُ الحال — وكلٌّ منها حالٌ وقعت لا حالٌ مفترضة */
type Status = 'clean' | 'saving' | 'waiting' | 'saved' | 'failed';

/** علامةُ القسم المطفأ في موضعه — `StorePage::HIDDEN` */
const HIDDEN = '!';

/**
 * محرّرُ صفحة الواجهة الخاصّة — الصفوفُ إلى جانب الصفحة نفسِها.
 *
 * ═══ ولمَ لا تبقى في الإعدادات ═══
 *
 * كانت ثلاثون مقبضًا في بطاقةٍ واحدة مرتّبةً بترتيب ما أُضيف: عنوانُ الواجهة
 * تحت «شكل الصفحة»، وصورةُ الشريط على بعد شاشتين من المفتاح الذي يُشغّل
 * الشريط، وسطرُ التذييل فوق قائمة الأقسام وهو ليس قسمًا. فمن أراد أن يبدّل
 * شيئًا في صفحته لم يكن له موضعٌ يبحث فيه — وسائرُ متاجر أبعاد لها محرّر.
 *
 * فصارت له صفوفٌ بترتيب ما يراه زبونُه، وكلُّ حقلٍ في الصفّ الذي يظهر فيه،
 * والصفحةُ إلى جانبها تتحدّث بعد كلّ حفظ.
 *
 * ═══ ولا «انشر» هنا ═══
 *
 * الواجهةُ تقرأ الإعدادات مباشرةً، فما يُحفظ يراه زبونُه في اللحظة نفسِها.
 * وزرُّ نشرٍ لا يؤجّل شيئًا يَعِد بما لا يفعل.
 */
export default function ThemeEditor({
    site: shell,
    publishing,
    pages,
    rows,
    values,
    order,
    layout: savedLayout,
    maxFeatured,
    products,
    hiddenProducts = [],
    contact = {},
    shortcutCategories = [],
    maxShortcuts = 6,
    catalogTools = null,
    shelfOrder = null,
}: Props) {
    const t = useTranslate();

    /*
        الترتيبُ محلّيٌّ لأنّه يُكتب بالضغط — والباقي يأتي من الخادم بعد كلّ حفظ.

        وهو القائمةُ كلُّها بالمطفأ في موضعه (`!best`) لا المشغَّلُ وحده: فقسمٌ
        يُطفأ ثمّ يُغلق المحرّرُ ويُفتح يجد موضعَه محفوظًا. والمشغَّلُ (`chosen`)
        يُقرأ منها، فلا تفترق قائمتان.
    */
    const [layout, setLayout] = useState<string[]>(savedLayout ?? order);
    const chosen = useMemo(() => layout.filter((k) => ! k.startsWith(HIDDEN)), [layout]);
    /*
        والصفُّ المفتوح أوّلَ ما تُفتح الشاشة — من الرابط إن جاء به (`?row=cats`).

        «ما يحتاج انتباهك» في لوحة الموقع يقول «فئاتٌ بلا صورة بطاقة» ويقود
        إلى هنا. ولو فُتح المحرّرُ مطويًّا كلُّه لَبحث عن الصفّ في قائمةٍ
        طويلة — والبندُ قال له أين.
    */
    const [open, setOpen] = useState<string | null>(() => {
        try {
            return new URLSearchParams(window.location.search).get('row');
        } catch {
            return null;
        }
    });
    /*
        و«إعدادات متقدّمة» مطويّةٌ — إلّا إن جاء الرابطُ بصفٍّ منها (`?row=cats`)
        فتُفتح عليه: البندُ الذي قاد إلى هنا لا يقود إلى صفٍّ مخفيّ.
    */
    const [showAdvanced, setShowAdvanced] = useState(() => open !== null && rows.some((r) => r.key === open && r.group === 'advanced'));
    const [status, setStatus] = useState<Status>('clean');
    const [frame, setFrame] = useState(0);

    /*
     * مقاسُ المعاينة — ثلاثةٌ كما عند جاره في البانِي.
     *
     * والتابلت ليس زينةً بين الاثنين: شبكةُ البطاقات في قالب الواجهة تنكسر
     * عنده لا عند الهاتف (ثلاثٌ في الصفّ تصير اثنتين)، فمن عاين على
     * الطرفين وحدَهما لم يرَ الحالَ التي تنكسر فيها صفحتُه.
     *
     * والعرضُ بالبكسل لا بالنسبة (`DEVICE_WIDTH`): ثلاثتُها مقاساتُ ما
     * يفتحه زبونُه فعلًا، لا كسرٌ من عرض شاشة التاجر.
     */
    const [size, setSize] = useState<PreviewSize>('desktop');

    /*
        ═══ والمقاسُ يُرسم بعرضه الحقيقيّ ثمّ يُصغَّر ليُرى ═══

        كان الإطارُ يأخذ `w-[820px] max-w-full` — وعمودُ المعاينة نصفُ
        الصفحة، يقف عند ٧٦٦ بكسل مهما اتّسعت الشاشة. فالـ٨٢٠ لا تسري
        أبدًا، و«تابلت» زرٌّ يُضيء ولا يُبدّل شيئًا.

        والصوابُ ما في `preview/SitePreview`: الإطارُ يُعطى عرضَ الجهاز
        كاملًا فتشتغل فيه استعلاماتُ القالب كما تشتغل عند الزبون، ثمّ
        يُصغَّر بـ`transform` ليسع العمود. و`transform` تُصغّر ما يُرسم
        ولا تُصغّر ما يشغله في التخطيط، فيُضبط ارتفاعُ الصندوق بالنسبة
        نفسِها وإلّا بقي تحته فراغٌ لا يُفهم من أين جاء.

        و«الكمبيوتر» صار عرضًا حقيقيًّا كذلك: كان يأخذ عرضَ العمود — أي
        ٥٤٠ بكسل — فيُري التاجرَ تخطيطَ الهاتف ويُسمّيه كمبيوتر.
    */
    const stage = useRef<HTMLDivElement>(null);
    const [avail, setAvail] = useState(0);

    useEffect(() => {
        const box = stage.current;

        if (! box) return;

        const read = () => setAvail(box.clientWidth);

        read();

        const observer = new ResizeObserver(read);

        observer.observe(box);

        return () => observer.disconnect();
    }, []);

    const want = DEVICE_WIDTH[size];
    const scale = previewFit(want, avail);

    /*
        و«وصل حديثًا» اليدويّ في النموذج نفسِه — لمن وصله وحده.

        فالمعاينةُ ترسم ما اختاره قبل أن يحفظ كسائر الحقول، والحفظُ يمرّ بـ
        `save` بمفتاحَيه. والقائمةُ تبدأ بما يُعرض الآن وحده: صنفٌ أُخفي بعد
        اختياره لا يُرسَل فيُردّ الحفظُ كلُّه من أجله.
    */
    const curated = catalogTools?.new_arrivals_mode !== undefined && catalogTools?.new_arrival_ids !== undefined;

    /*
        وقوائمُ الأصناف تبدأ بما يُعرض الآن وحده — كما تبدأ «وصل حديثًا»:
        صنفٌ أُخفي بعد اختياره لا يُرسَل فيُردّ الحفظُ كلُّه من أجله.
    */
    /*
        والمجموعاتُ والصفحاتُ لا تُصفّى: صنفٌ أخفاه بعد اختياره يبقى فيها موسومًا
        «مخفيّ الآن» (`ChosenProducts`) — كانت تُصفّى فيضيع مع أوّل حفظ. والخادمُ
        يقبله ما دام من متجره، ولا يرسمه حتّى يعود.
    */
    const lists = Object.fromEntries(
        rows.flatMap((r) => r.fields)
            .filter((f) => f.kind === 'products')
            .map((f) => [f.key, keepShown(values[f.key] ?? '', products)]),
    );

    const form = useForm<Record<string, string>>({
        ...values,
        ...lists,
        ...(curated
            ? {
                  store_new_arrivals_mode: catalogTools!.new_arrivals_mode!,
                  store_new_arrivals: catalogTools!.new_arrival_ids!
                      .filter((id) => products.some((p) => p.id === id))
                      .join(','),
              }
            : {}),
        // وترتيبُ الرفّ في النموذج نفسِه — فتُريه المعاينةُ قبل أن يُحفظ
        ...(shelfOrder ? { store_shelf_order: shelfOrder.value } : {}),
    });

    /*
        ═══ المعاينةُ تُرسَل بنموذجٍ إلى الإطار — لا برابط ═══

        وما يُعاين هو ما **لم يُحفظ بعد**: يكتب صاحبُ المحلّ عنوانًا فيراه
        كما يراه زبونُه قبل أن يُطبّقه على موقعٍ يعمل.

        وبنموذجٍ `POST` لا برابطٍ فيه القيم: الحمولةُ تبلغ آلافَ الحروف
        (نبذةٌ ونصُّ قسمٍ وروابطُ صور)، ورابطٌ بهذا الطول يُقصّ في الطريق.
        ولأنّه `POST` لا يُحفظ في تاريخ المتصفّح ولا يُشارَك: رابطُ معاينةٍ
        مُشارَكٌ يجب أن يفتح المتجرَ كما هو محفوظ — لا كما كان أحدُهم يجرّب.

        ولا حالةَ على الخادم: التراكبُ يعيش عمرَ الطلب ويموت معه — انظر
        `MarketingSettings::overlay`.
    */
    const previewForm = useRef<HTMLFormElement>(null);

    /*
        وما يُعاين: الرئيسيّةُ، أو رفُّ مجموعةٍ بعينها — قبل الحفظ كسائر الحقول.

        والمجموعةُ لا تُرى في الرئيسيّة: تُفتح من صفّ خيارات المتجر. فزرُّ
        «عاين صفحة المجموعة» يُرسل المسوّدةَ نفسَها إلى رفّها (`?page=shop`).
    */
    const [target, setTarget] = useState<{ page: 'shop'; collection: string } | { page: 'page'; slug: string } | null>(null);

    /*
        ═══ وما حُفظ آخرَ مرّة — ليُقال ما لم يُحفظ بعد ═══

        كلُّ مفتاحٍ بقيمته يومَ فُتحت الشاشة، ثمّ بما أُرسل في كلّ حفظ. فالصفُّ الذي
        فيه تعديلٌ لم يُحفظ يُوسم «غير محفوظ»، ومن أغلق الصفحةَ أو انتقل منها
        يُسأل قبل أن يذهب ما كتبه — لا يضيع بصمت.

        والصفحاتُ تُرسَل بلا وحدةٍ فارغة (`withoutEmptyBlocks`) فتُقاس كذلك.
    */
    const outgoing = (k: string, v: string) => (k === 'store_custom_pages' ? withoutEmptyBlocks(v) : v);
    const saved = useRef<Record<string, string> | null>(null);

    if (saved.current === null) {
        saved.current = Object.fromEntries(Object.entries(form.data).map(([k, v]) => [k, outgoing(k, v ?? '')]));
    }

    /*
        والرمزُ من خصائص Inertia لا من وسم `meta`.

        الوسمُ يُطبع مرّةً عند أوّل تحميل ولا يتغيّر، والدخولُ يجدّد رمزَ
        الجلسة — فيبقى الوسمُ على رمزٍ مُبطَل ويُردّ كلُّ نموذجٍ يقرؤه
        بـ٤١٩. وخاصّيةُ `csrf` تتجدّد مع كلّ استجابة. انظر `lib/csrf.ts`.
    */
    const token = String((usePage().props as { csrf?: string }).csrf ?? '');

    const draft = useMemo(
        () => JSON.stringify({ ...form.data, store_sections: layout.join(',') }),
        [form.data, layout],
    );

    const paint = () => previewForm.current?.submit();

    /*
        وتُرسَل بعد أن يسكت القلم لا مع كلّ حرف: تصييرُ الصفحة كاملةً عند
        كلّ ضغطةِ مفتاح يُثقل الخادمَ ويُرعش الإطار تحت عين الكاتب.
    */
    /*
        وعلى الهاتف أبطأ، ولا يُرسم ما لا يُرى.

        الإطارُ يرسم المتجرَ كلَّه بعرض الحاسوب وصورِه، ويُعاد مع كلّ توقّف —
        وعلى iPhone يتراكم هذا مع صورٍ تُرفع فتُغلق الصفحةُ («Can't open this
        page»). فالمعاينةُ تحت المحرّر على الهاتف لا تُرسم وهي خارج الشاشة،
        وتُرسم مرّةً بآخر المسوّدة حين تُرى؛ والتوقّفُ عليه أطول.
    */
    const [seen, setSeen] = useState(true);
    useEffect(() => {
        const el = stage.current;
        if (! el || typeof IntersectionObserver === 'undefined') return;

        const io = new IntersectionObserver(([e]) => setSeen(e.isIntersecting));
        io.observe(el);

        return () => io.disconnect();
    }, []);
    const narrow = useMemo(() => typeof window !== 'undefined' && window.matchMedia?.('(max-width: 1023px)').matches === true, []);

    useEffect(() => {
        if (! seen) return;

        const id = window.setTimeout(paint, narrow ? 1500 : 600);

        return () => window.clearTimeout(id);
    }, [draft, frame, target, seen]);

    const byKey = useMemo(() => new Map(rows.map((r) => [r.key, r])), [rows]);

    /*
     * الصفوفُ بترتيب القائمة كلِّها: المشغَّلُ والمطفأُ كلٌّ في موضعه، ثمّ ما
     * لم يُذكر فيها بترتيب الصفوف. والمطفأُ يبقى معروضًا ليُرفع ثانية — لا
     * يخرج من الشاشة إلى العدم.
     */
    const movable = useMemo(() => {
        const placed = layout.map((k) => (k.startsWith(HIDDEN) ? k.slice(HIDDEN.length) : k)).filter((k) => byKey.has(k));
        const rest = rows.filter((r) => ! r.fixed && ! placed.includes(r.key)).map((r) => r.key);

        return [...placed, ...rest];
    }, [rows, layout, byKey]);

    /** القائمةُ كلُّها كما تُحفظ — المشغَّلُ باسمه والمطفأُ بعلامته، كلٌّ في موضعه */
    const written = (keys: string[], on: (k: string) => boolean) => keys.map((k) => (on(k) ? k : HIDDEN + k));

    const refresh = () => setFrame((n) => n + 1);

    /*
        ═══ حفظٌ واحدٌ في الطريق — وما يُعدّ محفوظًا هو ما أُرسل ═══

        الحفظُ والترتيبُ زيارتا Inertia، والزيارةُ تقطع ما قبلها: حفظُ صفٍّ يقطع
        حفظَ صفٍّ آخر، وسهمُ الترتيب يقطع حفظَ الصفحات — فيُكتب أحدهما أو لا يُكتب،
        ولا يعرف المحرّرُ أيّهما. فكلُّ ما يُحفظ يمرّ بطابورٍ واحد: طلبٌ في الطريق،
        وما يُضغط أثناءه ينتظره ثمّ يُرسَل بآخر ما في الشاشة. فلا يصل جوابٌ قديم
        بعد جديد، ولا يُكتب ترتيبٌ قديمٌ فوق أحدث.

        وما يُوسم محفوظًا هو ما أُرسل حرفًا (`sent`) — لا ما في الشاشة ساعةَ الجواب:
        ما كُتب والحفظُ في الطريق يبقى «غير محفوظ» حتّى يُحفظ.

        وحفظٌ ضُغط وصورةٌ تُرفع ينتظر رفعَها (`uploads`)، ثمّ يُرسل الصفحةَ بصورتها.

        وما فشل لا يمسّ الشاشة: يبقى ما كُتب كما هو، ويُقال لماذا (الحقلُ المرفوض،
        انتهاءُ الجلسة، انقطاعُ الاتّصال)، ويُعاد الحفظُ بضغطة.
    */
    const now = useRef({ data: form.data, form });
    now.current = { data: form.data, form };
    const queue = useRef<{ busy: boolean; keys: Set<string>; order: string[] | null }>({ busy: false, keys: new Set(), order: null });
    const uploading = useUploadsInFlight();
    const [failure, setFailure] = useState<string | null>(null);
    // وصورةٌ لم تُرفع: ما سواها حُفظ وهي لم تُحفظ — فلا يُقال «حُفظ» وحده
    const imageLeft = useUploadFailures() > 0 && status === 'saved';
    const imageLeftText = t('حُفظ ما سوى الصورة — صورةٌ لم تُرفع فلم تُحفظ. أعد رفعها من حقلها ثمّ احفظ.');

    const failedBecause = (status: number) =>
        status === 419 || status === 401
            ? t('انتهت جلستك — لم يُحفظ شيء. حدّث الصفحة وادخل من جديد: تعديلاتك محفوظة على هذا الجهاز وتُعرض لتسترجعها.')
            : status === 413
              ? t('ما تحفظه أكبر ممّا يقبله الخادم — احذف بعض المحتوى ثمّ احفظ.')
              : t('تعذّر الحفظ على الخادم — لم يُحفظ شيء، وتعديلاتك باقية. أعد المحاولة.');

    const send = (payload: Record<string, string>, keys: string[] | null) => {
        queue.current.busy = true;
        setStatus('saving');
        setFailure(null);
        let ok = false;

        const options = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                ok = true;
                if (keys) keys.forEach((k) => { saved.current![k] = payload[k]; });
            },
            // والمرفوضُ يُقال تحت حقله — وهنا أين يُنظر
            onError: () => setFailure(t('راجع ما عُلِّم بالأحمر ثمّ احفظ — ما كتبته باقٍ.')),
            onHttpException: (response: { status: number }) => {
                setFailure(failedBecause(response.status));

                return false;
            },
            onNetworkError: () => {
                setFailure(t('تعذّر الاتصال — لم يُحفظ شيء. تعديلاتك باقية هنا وعلى هذا الجهاز؛ احفظ حين يعود الاتصال.'));

                return false;
            },
            onCancel: () => setFailure(t('لم يكتمل الحفظ — أعد المحاولة.')),
            onFinish: () => {
                if (keys) now.current.form.transform((d) => d);
                queue.current.busy = false;
                const more = queue.current.order !== null || queue.current.keys.size > 0;
                if (! ok) {
                    setStatus('failed');
                } else if (! more) {
                    setStatus('saved');
                    refresh();
                }
                flush();
            },
        };

        // والترتيبُ زيارةٌ وحده؛ والمفاتيحُ بالنموذج — يحمل ما أُرسل حرفًا (`payload`) ويُري أخطاءَ حقوله
        if (keys === null) {
            router.post(route('admin.marketing.store.save'), payload, options);

            return;
        }

        const f = now.current.form;
        f.transform(() => payload);
        f.post(route('admin.marketing.store.save'), options);
    };

    const flush = () => {
        const q = queue.current;
        if (q.busy) return;

        if (q.order !== null) {
            const next = q.order;
            q.order = null;
            send({ store_sections: next.join(',') }, null);

            return;
        }

        if (q.keys.size === 0) return;

        // والصورةُ تُرفع: ينتظرها ثمّ يُرسل (`useEffect` أدناه)
        if (uploads.count() > 0) {
            setStatus('waiting');

            return;
        }

        const keys = [...q.keys];
        q.keys.clear();
        const data = now.current.data;
        send(Object.fromEntries(keys.map((k) => [k, outgoing(k, data[k] ?? '')])), keys);
    };

    useEffect(() => {
        if (uploading === 0) flush();
    }, [uploading]);

    /** حفظُ مفاتيحَ بعينها — لا حمولةَ الشاشة كلَّها */
    const save = (keys: string[]) => {
        keys.forEach((k) => queue.current.keys.add(k));
        setStatus('saving');
        flush();
    };

    /*
        والترتيبُ يُحفظ بالضغطة — لا ينتظر زرًّا، فالمعاينةُ تُجيب فورًا. وفي الطابور
        نفسِه: ضغطتان سريعتان على السهم والحفظُ في الطريق تُرسلان آخرَهما وحده.
    */
    const writeOrder = (next: string[]) => {
        setLayout(next);
        queue.current.order = next;
        setStatus('saving');
        flush();
    };

    /*
     * ولا تُطفأ كلُّها.
     *
     * قائمةٌ فارغةٌ تُقرأ «ما كان» في `StorePage::order` فتعود الأقسامُ
     * السبعةُ كلُّها — وهي قاعدةٌ صحيحةٌ لترقيةٍ تنزل على متجرٍ قائم، وكذبةٌ
     * هنا: يُطفئ آخرَ قسمٍ فيراها كلَّها ترجع. فيُمنع الإطفاءُ الأخير ويُقال
     * لمَ — لا يُترك يُجرَّب فيُربك.
     */
    const last = chosen.length === 1;

    /*
        وإطفاءُ قسمٍ ثمّ تشغيلُه يُعيده إلى موضعه — ولو أُغلق المحرّرُ بينهما.

        العينُ تقلب علامةَ القسم في موضعه ولا تنقله: المطفأُ يُكتب `!best` حيث
        كان، فيُحفظ موضعُه في المسوّدة لا في ذاكرة الشاشة وحدها.
    */
    const toggleRow = (key: string) =>
        writeOrder(written(movable, (k) => (k === key ? ! chosen.includes(k) : chosen.includes(k))));

    /*
        والسهمُ يبادل القسمَ بأقرب قسمٍ مشغَّلٍ في جهته — كما يراه الزائر.

        والمطفأُ بينهما يبقى في موضعه: لا يُقفز عليه بضغطةٍ لا تغيّر في الصفحة
        شيئًا، ولا يُزاح فيعود يومَ يُشغَّل إلى غير موضعه.
    */
    const move = (key: string, by: number) => {
        const i = movable.indexOf(key);
        let j = i + by;

        while (j >= 0 && j < movable.length && ! chosen.includes(movable[j])) j += by;

        if (i < 0 || ! chosen.includes(key) || j < 0 || j >= movable.length) return;

        const next = [...movable];
        [next[i], next[j]] = [next[j], next[i]];
        writeOrder(written(next, (k) => chosen.includes(k)));
    };

    /* المختارات — بترتيب اختيارها، وأربعةٌ لا أكثر */
    const featured = (form.data.store_featured ?? '')
        .split(',')
        .map((v) => Number(v.trim()))
        .filter((v) => Number.isInteger(v) && v > 0);

    const toggleFeatured = (id: number) =>
        form.setData(
            'store_featured',
            (featured.includes(id)
                ? featured.filter((v) => v !== id)
                : [...featured, id].slice(0, maxFeatured)
            ).join(','),
        );

    const curation: ArrivalsCuration | null = curated
        ? {
              mode: form.data.store_new_arrivals_mode === 'manual' ? 'manual' : 'auto',
              ids: (form.data.store_new_arrivals ?? '')
                  .split(',')
                  .map((v) => Number(v.trim()))
                  .filter((v) => Number.isInteger(v) && v > 0),
              products,
              onChange: (mode, ids) =>
                  form.setData((d) => ({ ...d, store_new_arrivals_mode: mode, store_new_arrivals: ids.join(',') })),
              onSave: () => save(['store_new_arrivals_mode', 'store_new_arrivals']),
              saving: status === 'saving' || status === 'waiting',
              error: (form.errors as Record<string, string | undefined>).store_new_arrivals
                  ?? (form.errors as Record<string, string | undefined>).store_new_arrivals_mode,
          }
        : null;

    /*
        وما يُختار وجهةً لرابطٍ أو بطاقةٍ أو زرّ — كما في الشاشة الآن، ولو لم يُحفظ بعد:
        صفحةٌ أُنشئت للتوّ أو مجموعةٌ سُمّيت قبل لحظة تُختار في الحال.
    */
    const sources: TargetSources = {
        collections: parseCollections(form.data.store_collections),
        pages: parsePages(form.data.store_custom_pages).map(({ slug, title_ar, title_en, visible }) => ({ slug, title_ar, title_en, visible })),
        categories: shortcutCategories,
        products,
        storePages: pages,
    };

    /*
        ═══ وما لم يُحفظ لا يضيع بلا سؤال ═══

        مفاتيحُ تفارق ما حُفظ آخرَ مرّة. ومن أغلق الصفحة وفيها تعديلٌ لم يُحفظ
        يسأله المتصفّحُ (`beforeunload` — بابُه وحده يُسأل منه). ومن انتقل منها
        داخل لوحته يسأله المحرّرُ بنافذته (`useConfirm`): يُوقَف الانتقالُ، فإن
        قال «اخرج» أُعيد كما كان. والحفظُ نفسُه `POST` فلا يُسأل عنه.
    */
    const dirty = Object.keys(form.data).filter((k) => outgoing(k, form.data[k] ?? '') !== (saved.current![k] ?? ''));
    const rowDirty = (r: EditorRow) => r.fields.some((f) => dirty.includes(f.key) || (f.fit !== undefined && dirty.includes(f.fit)));
    const [ask, leaveDialog] = useConfirm();
    const leave = useRef(false);

    useEffect(() => {
        if (dirty.length === 0) return;

        const warn = (e: BeforeUnloadEvent) => {
            e.preventDefault();
            e.returnValue = '';
        };

        window.addEventListener('beforeunload', warn);

        const off = router.on('before', (event) => {
            const visit = event.detail.visit;

            if (visit.method !== 'get' || leave.current) return;

            event.preventDefault();
            void ask({ title: 'تعديلات لم تُحفظ', message: 'فيه تعديلات لم تُحفظ — تخرج وتتركها؟', action: 'اخرج وتركها', danger: true }).then((go) => {
                if (! go) return;

                leave.current = true;
                router.visit(visit.url, { preserveScroll: visit.preserveScroll, replace: visit.replace });
            });
        });

        return () => {
            window.removeEventListener('beforeunload', warn);
            off();
        };
    }, [dirty.length > 0]);

    /*
        ═══ ونسخةٌ على هذا الجهاز ممّا لم يُحفظ ═══

        `beforeunload` يسأل من يغلق الصفحة — ولا يسأل متصفّحًا انهار («Can't open
        this page» على iPhone)، ولا جلسةً انتهت، ولا اتّصالًا انقطع. فما لم يُحفظ
        يُكتب على هذا الجهاز بعد كلّ توقّف، بمفتاحٍ لكلّ نشاطٍ ومستخدم، ويُعرض حين
        يُفتح المحرّرُ ثانيةً: «استرجعها» تعيده إلى الشاشة غيرَ محفوظ — لا يُحفظ
        وحده، والحفظُ والنشرُ كما كانا — و«تجاهلها» تمحوه. وما حُفظ يخرج منها.
    */
    const who = (usePage().props as { auth?: { user?: { id?: number; businessId?: number } } }).auth?.user;
    const backupKey = `abaad.editor.unsaved.${who?.businessId ?? 0}.${who?.id ?? 0}`;
    const [backup, setBackup] = useState<{ at: number; data: Record<string, string> } | null>(() => {
        try {
            const raw = window.localStorage.getItem(backupKey);
            const kept = raw ? (JSON.parse(raw) as { at?: unknown; data?: unknown }) : null;
            if (! kept || typeof kept.data !== 'object' || kept.data === null) return null;

            const data = Object.fromEntries(
                Object.entries(kept.data as Record<string, unknown>).filter(
                    ([k, v]) => typeof v === 'string' && k in saved.current! && outgoing(k, v) !== saved.current![k],
                ),
            ) as Record<string, string>;

            return Object.keys(data).length > 0 ? { at: Number(kept.at) || 0, data } : null;
        } catch {
            return null;
        }
    });
    const unsaved = JSON.stringify(Object.fromEntries(dirty.map((k) => [k, form.data[k] ?? ''])));

    useEffect(() => {
        // ونسخةٌ تنتظر قرارَ صاحبها لا يُكتب فوقها
        if (backup) return;

        const id = window.setTimeout(() => {
            try {
                if (dirty.length === 0) window.localStorage.removeItem(backupKey);
                else window.localStorage.setItem(backupKey, JSON.stringify({ at: Date.now(), data: JSON.parse(unsaved) }));
            } catch {
                // تصفّحٌ خاصّ أو مساحةٌ ممتلئة — المحرّرُ يعمل بدونها
            }
        }, 700);

        return () => window.clearTimeout(id);
    }, [unsaved, backup]);

    const restoreBackup = () => {
        const kept = backup;
        if (! kept) return;

        form.setData((d) => ({ ...d, ...Object.fromEntries(Object.entries(kept.data).filter(([k]) => k in d)) }));
        setBackup(null);
    };

    const discardBackup = () => {
        try {
            window.localStorage.removeItem(backupKey);
        } catch {
            // لا شيء يُمحى
        }
        setBackup(null);
    };

    const field = (f: EditorField) => {
        if (f.gate && (form.data[f.gate] ?? '0') !== '1') return null;

        const error = form.errors[f.key as keyof typeof form.errors] as string | undefined;

        if (f.kind === 'image') {
            return (
                <StoreImageField
                    key={f.key}
                    name={f.key}
                    label={f.label}
                    hint={f.hint}
                    value={form.data[f.key] ?? ''}
                    onChange={(v) => form.setData(f.key, v)}
                    {...(f.fit
                        ? { fit: form.data[f.fit] ?? '', onFitChange: (v: string) => form.setData(f.fit!, v), ratio: f.ratio }
                        : {})}
                />
            );
        }

        if (f.kind === 'shoprow') {
            return (
                <ShopRowField
                    key={f.key}
                    label={f.label}
                    hint={f.hint}
                    value={form.data[f.key] ?? ''}
                    collections={parseCollections(form.data.store_collections)}
                    shortcutIds={(form.data.store_shop_nav_categories ?? '').split(',').map(Number).filter((n) => n > 0)}
                    categories={shortcutCategories}
                    onChange={(v) => form.setData(f.key, v)}
                    error={error}
                />
            );
        }

        if (f.kind === 'menu') {
            return (
                <MenuField
                    key={f.key}
                    label={f.label}
                    hint={f.hint}
                    value={form.data[f.key] ?? ''}
                    pagesValue={form.data.store_custom_pages ?? ''}
                    sources={sources}
                    products={products}
                    hidden={hiddenProducts}
                    collections={parseCollections(form.data.store_collections)}
                    max={f.max ?? 10}
                    maxPages={f.max_pages ?? 12}
                    maxBlocks={f.max_blocks ?? 20}
                    onChange={(v) => form.setData(f.key, v)}
                    onPagesChange={(v) => form.setData('store_custom_pages', v)}
                    onPreviewPage={(slug) => setTarget({ page: 'page', slug })}
                    error={error ?? (form.errors as Record<string, string | undefined>).store_custom_pages}
                />
            );
        }

        // والصفحاتُ تُكتب في القائمة نفسِها (`owns`) — لا مقبضَ لها وحدها
        if (f.kind === 'pages') return null;

        if (f.kind === 'footer') {
            const errors = form.errors as Record<string, string | undefined>;

            return (
                <FooterField
                    key={f.key}
                    label={f.label}
                    hint={f.hint}
                    value={form.data[f.key] ?? ''}
                    onChange={(v) => form.setData(f.key, v)}
                    owned={Object.fromEntries((f.owns ?? []).map((k) => [k, form.data[k] ?? '']))}
                    onOwned={(k, v) => form.setData(k, v)}
                    menu={parseMenu(form.data.store_menu)}
                    collections={parseCollections(form.data.store_collections)}
                    contact={contact}
                    maxText={f.max ?? 2}
                    error={error ?? (f.owns ?? []).map((k) => errors[k]).find(Boolean)}
                />
            );
        }

        if (f.kind === 'catalogfit') {
            return (
                <CatalogFitField
                    key={f.key}
                    label={f.label}
                    hint={f.hint}
                    value={form.data[f.key] ?? ''}
                    products={products}
                    onChange={(v) => form.setData(f.key, v)}
                    error={error}
                />
            );
        }

        if (f.kind === 'toggle') {
            return (
                <Toggle
                    key={f.key}
                    on={(form.data[f.key] ?? '0') === '1'}
                    onChange={(v) => form.setData(f.key, v ? '1' : '0')}
                    label={f.label}
                    hint={f.hint}
                />
            );
        }

        if (f.kind === 'align') {
            return (
                <AlignField
                    key={f.key}
                    label={f.label}
                    value={form.data[f.key] ?? ''}
                    onChange={(v) => form.setData(f.key, v)}
                    error={error}
                />
            );
        }

        if (f.kind === 'shortcuts') {
            return (
                <ShortcutsField
                    key={f.key}
                    label={f.label}
                    hint={f.hint}
                    value={form.data[f.key] ?? ''}
                    categories={shortcutCategories}
                    max={maxShortcuts}
                    onChange={(v) => form.setData(f.key, v)}
                    error={error}
                />
            );
        }

        if (f.kind === 'collections') {
            return (
                <CollectionsField
                    key={f.key}
                    name={f.key}
                    label={f.label}
                    hint={f.hint}
                    value={form.data[f.key] ?? ''}
                    products={products}
                    hidden={hiddenProducts}
                    savedSlugs={parseCollections(saved.current!.store_collections).map((c) => c.slug)}
                    max={f.max ?? 20}
                    maxProducts={f.max_products ?? 60}
                    onChange={(v) => form.setData(f.key, v)}
                    onPreview={(slug) => setTarget({ page: 'shop', collection: slug })}
                    error={error}
                />
            );
        }

        if (f.kind === 'visualbar') {
            return (
                <VisualBarField
                    key={f.key}
                    name={f.key}
                    label={f.label}
                    hint={f.hint}
                    value={form.data[f.key] ?? ''}
                    /* والمجموعاتُ كما في الشاشة الآن — بطاقةٌ لمجموعةٍ أُنشئت للتوّ تُختار قبل أن تُحفظ */
                    collections={parseCollections(form.data.store_collections)}
                    categories={shortcutCategories}
                    products={products}
                    pages={pages}
                    customPages={sources.pages}
                    max={f.max ?? 12}
                    onChange={(v) => form.setData(f.key, v)}
                    error={error}
                />
            );
        }

        if (f.kind === 'products') {
            return (
                <ProductListField
                    key={f.key}
                    name={f.key}
                    label={f.label}
                    hint={f.hint}
                    value={form.data[f.key] ?? ''}
                    products={products}
                    max={f.max ?? 4}
                    onChange={(v) => form.setData(f.key, v)}
                    error={error}
                />
            );
        }

        if (f.kind === 'featured') {
            return (
                <Field key={f.key} label={f.label} hint={f.hint} error={error}>
                    {products.length === 0 ? (
                        <p className="text-[13px] text-[#6b7280]">
                            {t('لا صنفَ معروضًا في متجرك بعد.')}
                        </p>
                    ) : (
                        <ul className="max-h-[240px] divide-y divide-[var(--ui-border,#e8e8e8)] overflow-y-auto rounded-[12px] border border-[var(--ui-border,#e8e8e8)]">
                            {products.map((p) => {
                                const on = featured.includes(p.id);

                                return (
                                    <li key={p.id} className="flex items-center gap-3 px-3 py-2">
                                        <input
                                            type="checkbox"
                                            checked={on}
                                            aria-label={p.name}
                                            disabled={! on && featured.length >= maxFeatured}
                                            onChange={() => toggleFeatured(p.id)}
                                            className="size-4 accent-[#111] disabled:opacity-30"
                                        />
                                        {p.image && (
                                            <img src={p.image} alt="" className="size-8 shrink-0 rounded-lg object-cover" />
                                        )}
                                        <span className="min-w-0 flex-1 truncate text-[13px] text-[#111]">{p.name}</span>
                                        {on && (
                                            <span className="text-[11px] text-[#6b7280]">{featured.indexOf(p.id) + 1}</span>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </Field>
            );
        }

        return (
            <Field key={f.key} label={f.label} hint={f.hint} error={error}>
                {f.kind === 'textarea' ? (
                    <Textarea
                        rows={4}
                        dir={f.dir}
                        value={form.data[f.key] ?? ''}
                        onChange={(e) => form.setData(f.key, e.target.value)}
                        aria-label={t(f.label)}
                    />
                ) : (
                    <Input
                        dir={f.dir}
                        value={form.data[f.key] ?? ''}
                        onChange={(e) => form.setData(f.key, e.target.value)}
                        aria-label={t(f.label)}
                    />
                )}
            </Field>
        );
    };

    const row = (r: EditorRow) => {
        const expanded = open === r.key;
        const i = chosen.indexOf(r.key);
        // ومفاتيحُ يرسمها حقلٌ آخرُ في مواضعها منه — القائمةُ صفحاتِها، والتذييلُ سطرَه
        const owned = new Set(r.fields.flatMap((f) => f.owns ?? []));
        const unsaved = rowDirty(r);

        /*
            ولوحةُ الصفّ تُغني عن سطر «يُكتب في…» حين تصل: فيها أبوابُه كلُّها.
            ومن لم تصله يبقى على السطر — فالمصدرُ لا يُحذف من `PageEditor`.
        */
        const panel =
            catalogTools && r.key === 'cats' ? (
                <CategoriesPanel categories={catalogTools.categories} onCardChange={paint} />
            ) : catalogTools && r.key === 'new' ? (
                <NewArrivalsPanel items={catalogTools.new_arrivals} curation={curation} />
            ) : shelfOrder && r.key === 'collections' ? (
                /* تحت مجموعات المتجر وشريطه — هو رفُّ «المتجر» نفسُه */
                <ShelfOrderPanel
                    seed={shelfOrder}
                    value={form.data.store_shelf_order ?? ''}
                    onChange={(v) => form.setData('store_shelf_order', v)}
                    onSave={() => save(['store_shelf_order'])}
                    saving={status === 'saving' || status === 'waiting'}
                    dirty={(form.data.store_shelf_order ?? '') !== (saved.current!.store_shelf_order ?? '')}
                    error={(form.errors as Record<string, string | undefined>).store_shelf_order}
                    categories={shortcutCategories}
                />
            ) : null;

        return (
            <li key={r.key} className="bg-white" data-testid={`row-${r.key}`}>
                <div className="flex items-center gap-3 px-4 py-3">
                    {r.fixed ? (
                        /* والواجهةُ والتذييلُ هويّةُ الصفحة لا قسمًا يُطفأ — فلا مقبضَ لهما */
                        <span className="size-7 shrink-0" />
                    ) : (
                        /*
                            وعينٌ لا مربّعُ اختيار — كما في ODAY.
                            والمربّعُ يقول «مُختار»، والسؤالُ هنا «أيُرى أم
                            لا يُرى». و`role="switch"` يُبقي للقارئ الصوتيّ
                            حالًا يقرؤها، فالشكلُ تبدّل والمعنى لم يتبدّل.
                        */
                        <button
                            type="button"
                            role="switch"
                            aria-checked={r.on}
                            aria-label={t(r.label)}
                            disabled={r.on && last}
                            onClick={() => toggleRow(r.key)}
                            className={cn(
                                'flex size-7 shrink-0 items-center justify-center rounded-[8px] transition-colors',
                                r.on ? 'text-[#111] hover:bg-[#f3f4f6]' : 'text-[#c4c4c4] hover:bg-[#f3f4f6]',
                                'disabled:pointer-events-none disabled:opacity-30',
                            )}
                        >
                            {r.on ? <Eye className="size-4" /> : <EyeOff className="size-4" />}
                        </button>
                    )}

                    <button
                        type="button"
                        onClick={() => setOpen(expanded ? null : r.key)}
                        className="min-w-0 flex-1 text-start"
                    >
                        <span className={cn('flex items-center gap-1.5 truncate text-[13px] font-medium', r.on ? 'text-[#111]' : 'text-[#9ca3af]')}>
                            {t(r.label)}
                            {unsaved && (
                                <span className="shrink-0 rounded-full bg-[#fef3c7] px-2 py-0.5 text-[10px] font-medium text-[#b45309]" data-testid={`row-${r.key}-unsaved`}>
                                    {t('غير محفوظ')}
                                </span>
                            )}
                        </span>
                        <span className="block truncate text-[12px] text-[#9ca3af]">{t(r.hint)}</span>
                    </button>

                    {! r.fixed && (
                        <>
                            <button
                                type="button"
                                aria-label={`${t('ارفع')} ${t(r.label)}`}
                                disabled={! r.on || i <= 0}
                                onClick={() => move(r.key, -1)}
                                className="flex size-10 shrink-0 items-center justify-center rounded-md text-[13px] text-[#6b7280] enabled:hover:bg-[#f3f4f6] disabled:opacity-30"
                            >
                                ↑
                            </button>
                            <button
                                type="button"
                                aria-label={`${t('أنزل')} ${t(r.label)}`}
                                disabled={! r.on || i < 0 || i >= chosen.length - 1}
                                onClick={() => move(r.key, 1)}
                                className="flex size-10 shrink-0 items-center justify-center rounded-md text-[13px] text-[#6b7280] enabled:hover:bg-[#f3f4f6] disabled:opacity-30"
                            >
                                ↓
                            </button>
                        </>
                    )}

                    <ChevronDown
                        className={cn('size-4 shrink-0 text-[#d1d5db] transition-transform', expanded && 'rotate-180')}
                    />
                </div>

                {/*
                    و«مُشغَّلٌ ولا يظهر» يُقال في صفّه لا في آخر الشاشة.

                    من شغّل «آراء الزبائن» ثمّ فتح متجره فلم يجدها يظنّ العطبَ
                    في النظام — والسببُ عندنا مكتوب.
                */}
                {r.silent && (
                    <p
                        data-testid={`silent-${r.key}`}
                        className="mx-4 mb-3 flex items-start gap-2 rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] leading-relaxed text-[#b45309]"
                    >
                        <EyeOff className="mt-0.5 size-3.5 shrink-0" />
                        {t(r.silent)}
                    </p>
                )}

                {expanded && (
                    <div className="border-t border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] px-4 py-4">
                        <div className="space-y-4">{r.fields.filter((f) => ! owned.has(f.key)).map(field)}</div>

                        {panel}

                        {/*
                            وما لا يُكتب هنا يُقال أين يُكتب.

                            «تسوّق حسب الفئة» تُبنى من فئاته، و«آراء الزبائن»
                            ممّا نشره منها. وحقلٌ يُنسخ إلى هنا يعني عمودًا
                            يُكتب من بابين — فيُشار إلى بابه بدل أن يُفتح ثانٍ.
                        */}
                        {r.source && ! panel && (
                            <p className="mt-4 flex flex-wrap items-center gap-1.5 text-[12px] text-[#6b7280]">
                                {t('محتوى هذا القسم يُكتب في:')}
                                <Link
                                    href={route(r.source.route)}
                                    className="inline-flex items-center gap-1 font-medium text-[#111] underline"
                                >
                                    {t(r.source.label)}
                                    <ChevronLeft className="size-3.5" />
                                </Link>
                            </p>
                        )}

                        {r.fields.length > 0 && (
                            <div className="mt-4 flex justify-end">
                                <Button
                                    type="button"
                                    size="sm"
                                    loading={status === 'saving' || status === 'waiting'}
                                    onClick={() => save(r.fields.flatMap((f) => (f.fit ? [f.key, f.fit] : [f.key])))}
                                    data-testid={`row-${r.key}-save`}
                                >
                                    <Save />
                                    {t('احفظ')}
                                </Button>
                            </div>
                        )}
                        {r.fields.length > 0 && (status === 'waiting' || (status === 'failed' && failure) || imageLeft) && (
                            <p
                                role={status === 'waiting' ? 'status' : 'alert'}
                                data-testid={`row-${r.key}-save-status`}
                                className={cn('mt-2 text-end text-[12px]', status === 'waiting' ? 'text-[#6b7280]' : 'text-[#b91c1c]')}
                            >
                                {status === 'waiting' ? t('ينتظر اكتمال رفع الصورة ثمّ يحفظ…') : imageLeft ? imageLeftText : failure}
                            </p>
                        )}
                    </div>
                )}
            </li>
        );
    };

    /*
        ═══ أربعةُ أبوابٍ أوّلًا، وما سواها تحت «إعدادات متقدّمة» ═══

        الواجهةُ ومجموعاتُ المتجر والقائمةُ وصفحاتُها والتذييل — ما يبني به متجرَه.
        وما يُضبط مرّةً (رأسُ المتجر، وأقسامُ الرئيسية بترتيبها، وعرضُ الصور، و«أضف
        مع طلبك»، والشريطُ المصوّر حين يُطفأ) مطويٌّ تحتها: لا يُحذف ولا يتبدّل.
        والقسمةُ من الخادم (`PageEditor::rows`)، لا من هنا.
    */
    const main = rows.filter((r) => r.group === 'main');
    const advanced = rows.filter((r) => r.group === 'advanced' && r.fixed);
    const head = advanced.find((r) => r.key === 'head');
    const tail = advanced.filter((r) => r.key !== 'head');

    return (
        <AdminLayout title="الموقع الإلكتروني">
            {/*
                والتبويبُ المضيءُ «التصميم» لا «المحرّر».

                فالمحرّرُ ليس تبويبًا في الشريط: هو ما يصل إليه «التصميم»
                كما يصل إليه عند جاره (انظر `DesignController::index`).
                و«التصميم» اسمُ مساره `admin.website.design` — فيُمرَّر هو،
                ولو تُرك `…editor` لَأضاء المُطابِقُ بالبادئة تبويبًا
                بالقرعة: ستّتُها تبدأ بـ`admin.website.`.
            */}
            <ThemeHeader
                site={shell}
                current="admin.website.design"
                /*
                    والوصفُ يقول الحقّ في الحالين: من فُتح له النشر يحفظ
                    فينتظر، ومن لم يُفتح له يحفظ فيظهر. وسطرٌ واحدٌ لحالين
                    يكذب على أحدهما.
                */
                subtitle={t(publishing
                    ? 'رتّب أقسام صفحتك واكتب فيها — ثمّ انشر ليراه زبونك'
                    : 'رتّب أقسام صفحتك واكتب فيها — وما تحفظه يراه زبونك في الحال')}
            />

            {/* وشريطُ النشر لمن فُتح له وحدَه — ومقبضٌ لا يعمل أسوأ من غيابه */}
            {publishing && <PublishBar state={publishing} />}
            {leaveDialog}

            <div className="mb-4 flex items-center justify-end gap-2">
                <span className="flex items-center gap-1.5 text-[12px] text-[#6b7280]">
                    {(status === 'saving' || status === 'waiting') && <Loader2 className="size-3.5 animate-spin" />}
                    {status === 'saved' && ! imageLeft && <Check className="size-3.5 text-[#059669]" />}
                    {(status === 'failed' || imageLeft) && <CircleAlert className="size-3.5 text-[#b91c1c]" />}
                    {imageLeft && <span data-testid="editor-image-not-saved">{imageLeftText}</span>}
                    {status !== 'clean' &&
                        ! imageLeft &&
                        t(status === 'saving' ? 'يُحفظ…' : status === 'waiting' ? 'ينتظر اكتمال رفع الصورة ثمّ يحفظ…' : status === 'saved' ? 'حُفظ' : 'تعذّر الحفظ')}
                    {status === 'failed' && failure && <span data-testid="editor-save-failure">— {failure}</span>}
                </span>
            </div>

            {backup && (
                <div
                    role="status"
                    data-testid="editor-unsaved-backup"
                    className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-[12px] bg-[#fffbeb] px-4 py-3 text-[13px] text-[#92400e] ring-1 ring-[#fde68a]"
                >
                    <span>
                        {t('على هذا الجهاز تعديلاتٌ لم تُحفظ (:when) — تسترجعها؟ تعود إلى الشاشة ولا تُحفظ حتى تضغط «احفظ».', {
                            when: new Date(backup.at).toLocaleString(),
                        })}
                    </span>
                    <span className="flex gap-2">
                        <Button type="button" size="sm" onClick={restoreBackup} data-testid="editor-unsaved-restore">
                            {t('استرجعها')}
                        </Button>
                        <Button type="button" size="sm" variant="ghost" onClick={discardBackup} data-testid="editor-unsaved-discard">
                            {t('تجاهلها')}
                        </Button>
                    </span>
                </div>
            )}

            {/*
                ولا يُشترط النشرُ لفتح المحرّر — فمن يجهّز متجره يرتّب صفحتَه
                قبل أن يفتحها، ويُقال له إنّها لا تُفتح بعد ويُشار إلى بابها.
            */}
            {! shell.published && (
                <p className="mb-4 flex flex-wrap items-center gap-1.5 rounded-[12px] bg-[#eff6ff] px-4 py-3 text-[13px] leading-relaxed text-[#1d4ed8]">
                    {t('متجرك لا يفتحه أحدٌ بعد — رتّب صفحتك هنا، ثمّ انشره من')}
                    <Link href={route('admin.website.domain')} className="font-medium underline">
                        {t('العنوان والنشر')}
                    </Link>
                </p>
            )}

            {/*
                ═══ شريطُ المحرّر — ما يُحرَّر، وبأيّ مقاسٍ يُرى ═══

                ثلاثةُ مواضع كما في ODAY: المنتقي حيث تبدأ القراءة، والمقاسات
                في الوسط فوق المعاينة التي تخصّها، والأفعالُ في الطرف.
            */}
            <div
                data-testid="editor-bar"
                className="mb-4 flex flex-wrap items-center gap-3 rounded-[14px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2"
            >
                <div className="flex min-w-0 items-center gap-2">
                    <span className="shrink-0 text-[13px] text-[#6b7280]">{t('تُحرّر')}</span>
                    <PagePicker pages={pages} />
                </div>

                {/* والمقاساتُ أزرارٌ لا شاشات — الصفحةُ واحدةٌ يضيق بها الإطار */}
                <div
                    data-testid="preview-sizes"
                    className="flex overflow-hidden rounded-[8px] border border-[var(--ui-border,#e8e8e8)] sm:mx-auto"
                >
                    {([
                        ['desktop', Monitor, 'عرض الكمبيوتر'],
                        ['tablet', Tablet, 'عرض التابلت'],
                        ['phone', Smartphone, 'عرض الجوال'],
                    ] as const).map(([key, Icon, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setSize(key)}
                            aria-pressed={size === key}
                            aria-label={t(label)}
                            data-testid={'preview-' + key}
                            className={cn(
                                'px-2.5 py-1.5',
                                size === key ? 'bg-[#111] text-white' : 'text-[#6b7280]',
                            )}
                        >
                            <Icon className="size-3.5" />
                        </button>
                    ))}
                </div>

                <div className="flex items-center gap-1">
                    <Button type="button" variant="ghost" size="sm" onClick={refresh}>
                        <RefreshCw />
                        {t('تحديث')}
                    </Button>
                    {/* ورابطُ المتجر لا يُعرض إلّا إن كان يُفتح */}
                    {shell.url && (
                        <Button variant="ghost" size="sm" asChild>
                            <a href={shell.url} target="_blank" rel="noreferrer">
                                <ExternalLink />
                                {t('افتح متجرك')}
                            </a>
                        </Button>
                    )}
                </div>
            </div>

            {/*
                ═══ والمعاينةُ أوّلًا فتقع يمينًا ═══

                الصفحةُ عربيّةٌ، وأوّلُ ابنٍ في الشبكة يقع في يمينها. وكانت
                القائمةُ هي الأوّلَ فأخذت اليمينَ ونصفَ العرض، والمعاينةُ
                تُزاحَم في اليسار — وهي المقصودةُ من المحرّر.

                فصارت المعاينةُ تأخذ ما بقي، واللوحةُ عرضًا ثابتًا.

                ═══ ولمَ ثابتٌ لا كسرٌ ═══

                جُرّبت بالكسر (ثلثان وثلث) فانكسرت على التابلت: عمودُ اللوحة
                صار مئتين وثلاثين بكسلًا، فقُصّت أسماءُ الأقسام إلى «تسوّق …»
                و«الأكثر مب…». وقائمةٌ لا تُقرأ أسماؤها ليست قائمة.

                والعتبةُ `xl` لا `lg`: بين الألف والألفِ ومئتين لا يتّسع
                الصفُّ لعمودَين، فتنزل اللوحةُ تحت المعاينة بعرضها كاملًا.
            */}
            <div className="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
                <div className="xl:order-2">
                    <ul className="divide-y divide-[var(--ui-border,#e8e8e8)] overflow-hidden rounded-[16px] border border-[var(--ui-border,#e8e8e8)]" data-testid="rows-main">
                        {main.map(row)}
                    </ul>

                    <div className="mt-4 overflow-hidden rounded-[16px] border border-[var(--ui-border,#e8e8e8)]">
                        <button
                            type="button"
                            onClick={() => setShowAdvanced(! showAdvanced)}
                            aria-expanded={showAdvanced}
                            data-testid="advanced-toggle"
                            className="flex w-full items-center gap-3 bg-white px-4 py-3 text-start"
                        >
                            <span className="min-w-0 flex-1">
                                <span className="block text-[13px] font-medium text-[#111]">{t('إعدادات متقدمة')}</span>
                                <span className="block truncate text-[12px] text-[#9ca3af]">{t('رأس المتجر، أقسام الرئيسية وترتيبها، عرض الصور، وغيرها')}</span>
                            </span>
                            <ChevronDown className={cn('size-4 shrink-0 text-[#d1d5db] transition-transform', showAdvanced && 'rotate-180')} />
                        </button>
                        {showAdvanced && (
                            <ul className="divide-y divide-[var(--ui-border,#e8e8e8)] border-t border-[var(--ui-border,#e8e8e8)]" data-testid="rows-advanced">
                                {head && row(head)}
                                <li className="bg-[#fafafa] px-4 py-2 text-[12px] font-semibold text-[#6b7280]">{t('أقسام «الرئيسية»')}</li>
                                {movable.map((k) => {
                                    const r = byKey.get(k);

                                    return r ? row(r) : null;
                                })}
                                {tail.map(row)}
                            </ul>
                        )}
                    </div>

                    {/* ولمَ لا يُطفأ الأخير — يُقال، لا يُترك يُجرَّب فيُربك */}
                    {last && (
                        <p data-testid="last-section" className="mt-3 text-[12px] leading-relaxed text-[#6b7280]">
                            {t('لا تُطفأ كلُّها — صفحةٌ بلا قسمٍ واحد تعود إلى ترتيبها الأصليّ.')}
                        </p>
                    )}

                    {/*
                        وما ليس من الصفحة يُقال أين هو: الدفعُ والتوصيلُ
                        والعنوانُ والنشر بطاقةٌ في الإعدادات — لا صفٌّ هنا.
                    */}
                    <p className="mt-4 flex flex-wrap items-center gap-1.5 text-[13px] text-[#6b7280]">
                        {t('والدفعُ والتوصيلُ في')}
                        <Link
                            href={route('admin.website.shop')}
                            className="inline-flex items-center gap-1 font-medium text-[#111] underline"
                        >
                            {t('المتجر والطلبات')}
                            <ChevronLeft className="size-3.5" />
                        </Link>
                    </p>
                </div>

                {/* والمعاينةُ صفحتُه نفسُها في إطار — لا رسمٌ يشبهها */}
                <div className="xl:order-1 xl:sticky xl:top-4 xl:self-start">
                    {/* ومعاينةُ رفّ مجموعةٍ تُقال، ويُعاد منها إلى الرئيسيّة بضغطة */}
                    {target && (
                        <p data-testid="preview-target" className="mb-2 flex items-center justify-between gap-2 rounded-[10px] bg-[#eff6ff] px-3 py-2 text-[12px] text-[#1d4ed8]">
                            <span dir="ltr">{target.page === 'shop' ? `/shop?collection=${target.collection}` : `/page/${target.slug}`}</span>
                            <button type="button" className="font-medium underline" onClick={() => setTarget(null)}>
                                {t('عُد إلى الرئيسية')}
                            </button>
                        </p>
                    )}
                    <div className="overflow-hidden rounded-[16px] border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] p-2">
                        {/*
                            النموذجُ مخفيٌّ ويُصيب الإطارَ باسمه: هو الذي
                            يحمل المسوّدة، والإطارُ يعرض جوابَه.
                        */}
                        <form
                            ref={previewForm}
                            method="POST"
                            action={route('admin.store.preview', target ?? undefined)}
                            target="rb-preview"
                            className="hidden"
                            data-testid="preview-form"
                        >
                            <input type="hidden" name="_token" value={token} />
                            <input type="hidden" name="draft" value={draft} />
                        </form>
                        {/* والمقياسُ يُقاس على هذا الصندوق — بلا حشوٍ يكذب عليه */}
                        <div ref={stage} className="w-full">
                            <div
                                data-testid="preview-stage"
                                className="mx-auto overflow-hidden transition-[width]"
                                style={{ width: want * scale, height: FRAME_HEIGHT }}
                            >
                                <iframe
                                    key={frame}
                                    name="rb-preview"
                                    title={t('معاينة المتجر')}
                                    data-testid="preview-frame"
                                    className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] bg-white"
                                    style={{
                                        width: want,
                                        /* والارتفاعُ يُقسَم كما يُقسَم العرض — وإلّا صار الصندوقُ شريطًا */
                                        height: `calc(${FRAME_HEIGHT} / ${scale})`,
                                        transform: `scale(${scale})`,
                                        /* والمبدأُ يمينٌ لا يسار: الصفحةُ عربيّةٌ تبدأ من هناك */
                                        transformOrigin: 'top right',
                                    }}
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
