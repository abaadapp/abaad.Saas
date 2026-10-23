/**
 * ما تحتاجه إضافاتُ المتجر — وما بقي من حمولة `composition` بعد أن زال قسمُها.
 *
 * ═══ ولمَ ملفُّ أنواعٍ بلا مكوّن ═══
 *
 * كانت هذه الأنواعُ تسكن `Composition.tsx`، وكان معها القسمُ الذي يديرها.
 * وأُزيل القسمُ — ولم تُزَل هي: `AddonOption` تصف إضافةَ متجرٍ تُدار من
 * «المعلومات الأساسية»، و`stock_items` و`products` تُغذّيان نافذتَها.
 *
 * فبقيت الأنواعُ في ملفٍّ لا شاشةَ فيه، ولم يُترك المكوّنُ كلُّه ليُستورد
 * منه نوعٌ واحد.
 *
 * ═══ والخادمُ يرسل أكثرَ ممّا هنا ═══
 *
 * `ProductController` ما زال يُرسل `variants` و`recipe` و`components`
 * و`addon_ids` في `composition` — ولا شيءَ في الواجهة يقرؤها بعد اليوم.
 * وتُركت في الخادم عن قصد: المقاساتُ والوصفاتُ القائمة تُقرأ في الكاشير
 * والمتجر وتستهلك المخزون، وحذفُ إرسالها لا يخدم شيئًا ويخاطر بما يعمل.
 *
 * فالنوعُ هنا يصف **ما تقرؤه الشاشة** لا ما يرسله الخادم. وحقولٌ زائدةٌ في
 * جسمٍ يُستقبل لا تُخلّ بنوعه.
 */

export interface AddonOption {
    value: number;
    label: string;
    name_en?: string | null;
    price: number;
    active: boolean;
    /**
     * أين تُباع — نقطةُ البيع، والموقع، أو كلاهما، أو لا شيء.
     *
     * والغيابُ يُقرأ كما كانت الإضافةُ قبل الحقلين: في الصندوق، لا في الموقع.
     */
    pos_enabled?: boolean;
    web_enabled?: boolean;
    /**
     * خاصّةٌ بهذا المنتج وحده لا بالمتجر.
     *
     * ولا سبيلَ إلى إنشاء واحدةٍ بعد اليوم — زال قسمُ التركيب ومعه خيارُ
     * «هذا المنتج فقط». ويبقى الحقلُ لأنّ في القاعدة صفوفًا كُتبت قبل ذلك:
     * تُعرض في الكاشير وتُباع، وتُستثنى من قائمة إضافات المتجر كما كانت.
     */
    private: boolean;
    product_id?: number | null;
    /** مداها: مع الجميع، أو مع منتجاتٍ محدّدة */
    scope?: 'all' | 'selected' | 'product';
    inventory_product_id: number | null;
    /** ما تأكله من ذلك الصنف في كلّ إضافةٍ تُباع — والفراغ واحدة */
    inventory_quantity?: number | null;
    product_ids?: number[];
    /**
     * من أين — خدمةٌ من المتجر، أو صنفٌ من رفّه، أو قطعةٌ من رفّ بوتيك.
     * انظر `CatalogQuickAddController::sourceOf`.
     */
    source?: AddonSource;
    stock_name?: string | null;
    boutique_id?: number | null;
    boutique_name?: string | null;
    /** مستحقُّ البوتيك عن كلّ إضافةٍ تُباع — ثمنُ القطعة × عددها، للعرض وحده */
    boutique_due?: number | null;
    /** المملوكةُ لمنتجٍ واحد: اسمُه — في صفحة الإضافات */
    owner_name?: string | null;
}

export type AddonSource = 'shop' | 'stock' | 'boutique';

/** أيُربط صنفُ بوتيكٍ بإضافة — انظر `AddonController::boutiqueLinks` */
export interface BoutiqueLinks {
    hosts: boolean;
    allowed: boolean;
    reason?: string | null;
}

export type AddonChannels = 'both' | 'pos' | 'web' | 'none';

/** أين تُباع — والغائبُ كما كانت كلُّ إضافةٍ قبل الحقلين: الصندوقُ لا الموقع */
export function addonChannels(a: Pick<AddonOption, 'pos_enabled' | 'web_enabled'>): AddonChannels {
    const pos = a.pos_enabled !== false;
    const web = a.web_enabled === true;
    if (pos && web) return 'both';
    if (pos) return 'pos';
    if (web) return 'web';
    return 'none';
}

export const CHANNEL_LABEL: Record<AddonChannels, string> = {
    both: 'نقطة البيع والموقع',
    pos: 'نقطة البيع فقط',
    web: 'الموقع فقط',
    none: 'غير متاحة في أي قناة',
};

/** نطاقُها كما يُقرأ — والمملوكةُ لمنتجٍ واحد ثالثةٌ لا تُنشأ بعد اليوم */
export function addonScope(a: Pick<AddonOption, 'scope' | 'private'>): 'all' | 'selected' | 'product' {
    if (a.private || a.scope === 'product') return 'product';
    return a.scope === 'selected' ? 'selected' : 'all';
}

/**
 * أيُعرض مع هذا المنتج؟ — قاعدةُ البيع نفسُها (`ProductAddons::passes`).
 *
 * المملوكةُ مع صاحبها، والمخصّصةُ مع منتجاتها، والعامّةُ مع الجميع ما لم
 * تكن للمنتج قائمةٌ قديمة تستثنيها (`legacy_addon_ids`). ومنتجٌ لم يُحفظ
 * بعد لا مخصّصةَ معه: لا معرّفَ له يُربط به.
 */
export function appliesTo(a: AddonOption, productId: number | null, legacy: number[] | null): boolean {
    const scope = addonScope(a);
    if (scope === 'product') return productId !== null && a.product_id === productId;
    if (scope === 'selected') return productId !== null && (a.product_ids ?? []).includes(productId);
    return legacy === null || legacy.includes(a.value);
}

/** ما تقرؤه شاشةُ المنتج من حمولة `composition` — انظر ترويسة الملفّ */
export interface CompositionData {
    addons: AddonOption[];
    /** الأصناف الصالحة لأن تكون مخزونَ إضافة — بلا ما له وصفة */
    stock_items: StockItem[];
    /** منتجات المتجر — لاختيار «تظهر مع: منتجات محدّدة» */
    products: { value: number; label: string }[];
    /** قائمةُ المنتج القديمة — `null` لا قائمة، فالعامّةُ كلُّها معه */
    legacy_addon_ids?: number[] | null;
}

/** صنفٌ يُربط بإضافة — ومعه بوتيكُه وثمنُه لخيار «منتج من بوتيك» */
export interface StockItem {
    value: number;
    label: string;
    quantity?: number;
    /** ملكُ بوتيكٍ لا يُقبل ربطُه الجديد في هذا المتجر الآن */
    boutique?: boolean;
    boutique_id?: number | null;
    boutique_name?: string | null;
    price?: number;
}
