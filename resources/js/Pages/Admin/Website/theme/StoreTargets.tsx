import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { StoreCollection } from './CollectionsField';
import type { ListProduct } from './ProductListField';

/**
 * وجهةٌ داخل المتجر — ما تفتحه بطاقةُ الشريط ورابطُ القائمة وزرُّ الصفحة.
 *
 * مصدرٌ واحدٌ للثلاثة كما في الخادم (`Store\StoreTarget`): نوعٌ مغلقٌ وقيمةٌ من
 * هذا المتجر، تُختار من قائمةٍ ولا تُكتب — فلا خطأُ حرفٍ يقود إلى «غير موجود».
 *
 * والرابطُ المكتوب (`url`) لرابط القائمة وزرّ الصفحة وحدهما (`withUrl`): مسارٌ
 * في متجره يبدأ بـ`/`، أو عنوانٌ كاملٌ بـ`https://`. وحقلُه لا يظهر إلّا حين
 * يُختار — لا يُطلب رابطٌ ممّن لم يُرده.
 */

export type TargetType = 'home' | 'shop' | 'best' | 'about' | 'contact' | 'collection' | 'category' | 'product' | 'page' | 'url';

export interface StoreTargetValue {
    type: TargetType;
    value: string | number;
}

export const TARGET_TYPES: TargetType[] = ['home', 'shop', 'best', 'about', 'contact', 'collection', 'category', 'product', 'page', 'url'];

/** صفحةٌ مخصّصةٌ كما يحتاجها المنتقي — اسمُها المختصر وعنوانُها وظهورُها */
export interface TargetPage {
    slug: string;
    title_ar: string;
    title_en: string;
    visible: boolean;
}

/** الوجهةُ مفتاحًا واحدًا لقائمة الاختيار — `collection:graduation` */
export function targetKey(t: StoreTargetValue): string {
    return ['collection', 'category', 'product', 'page'].includes(t.type) ? `${t.type}:${t.value}` : t.type;
}

export function fromKey(key: string): StoreTargetValue {
    const [type, value = ''] = key.split(':') as [TargetType, string?];

    if (type === 'category' || type === 'product') return { type, value: Number(value) };

    return { type, value: type === 'collection' || type === 'page' ? value : '' };
}

/**
 * رابطٌ مكتوبٌ يُقبل — بقاعدة الخادم نفسِها (`StoreTarget::safeUrl`): مسارٌ يبدأ
 * بـ`/` وحدها، أو عنوانٌ بـ`http(s)` له نطاق. ولا مسافةَ ولا رمزَ تحكّم.
 */
export function safeUrl(raw: string): string | null {
    const url = raw.trim();

    if (url === '' || url.length > 300 || /[\s<>"'\\\u0000-\u001f\u007f]/.test(url)) return null;
    if (url.startsWith('/')) return url.startsWith('//') ? null : url;

    return /^https?:\/\/[^/?#]+\.[^/?#]+/i.test(url) ? url : null;
}

/** وجهةٌ محفوظة ← وجهة — أو `null` لما لا يُقرأ */
export function parseTarget(raw: unknown): StoreTargetValue | null {
    if (! raw || typeof raw !== 'object') return null;

    const t = raw as { type?: unknown; value?: unknown };

    if (! TARGET_TYPES.includes(t.type as TargetType)) return null;

    const type = t.type as TargetType;

    if (type === 'url') return { type, value: String(t.value ?? '') };

    return { type, value: type === 'category' || type === 'product' ? Number(t.value) : type === 'collection' || type === 'page' ? String(t.value ?? '') : '' };
}

export interface TargetSources {
    collections: StoreCollection[];
    pages: TargetPage[];
    categories: { id: number; name: string; shown?: number }[];
    products: ListProduct[];
    /** صفحاتُ القالب — «من نحن» و«تواصل معنا» وجهتان ما دامتا تُفتحان */
    storePages: { key: string; on: boolean; blocked: string | null }[];
}

/** أتُفتح الوجهةُ الآن؟ — ما لا يُفتح لا يُرسم في المتجر، فيُقال هنا قبل الحفظ */
export function reaches(target: StoreTargetValue, from: TargetSources): boolean {
    const pageOn = (key: string) => from.storePages.some((p) => p.key === key && p.on && p.blocked === null);

    switch (target.type) {
        case 'collection': return from.collections.some((c) => c.slug === target.value && c.visible);
        case 'page': return from.pages.some((p) => p.slug === target.value && p.visible);
        case 'category': return from.categories.some((c) => c.id === Number(target.value) && (c.shown ?? 1) > 0);
        case 'product': return from.products.some((p) => p.id === Number(target.value));
        case 'about': case 'contact': return pageOn(target.type);
        case 'url': return safeUrl(String(target.value)) !== null;
        default: return true;
    }
}

/**
 * منتقي الوجهة — صفحاتُ المتجر، ثمّ صفحاتُه المخصّصة، ثمّ مجموعاتُه وفئاتُه
 * ومنتجاتُه، ثمّ الرابطُ المكتوب إن قُبل هنا. والمخفيُّ يُعرض موسومًا: يُختار
 * ليُظهَر بعدُ، ويُقال إنّه لا يُفتح الآن.
 */
export function TargetSelect({
    value,
    onChange,
    from,
    label,
    testid,
    withProducts = true,
    withUrl = false,
}: {
    value: StoreTargetValue;
    onChange: (t: StoreTargetValue) => void;
    from: TargetSources;
    label: string;
    testid: string;
    withProducts?: boolean;
    withUrl?: boolean;
}) {
    const t = useTranslate();
    const pageOn = (key: string) => from.storePages.some((p) => p.key === key && p.on && p.blocked === null);
    const typed = value.type === 'url';
    const bad = typed && String(value.value).trim() !== '' && safeUrl(String(value.value)) === null;

    return (
        <div className="space-y-2">
            <select
                value={targetKey(value)}
                onChange={(e) => onChange(fromKey(e.target.value))}
                aria-label={t(label)}
                data-testid={testid}
                className="h-11 w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 text-[13px] text-[#111]"
            >
                <optgroup label={t('صفحات المتجر')}>
                    <option value="home">{t('الرئيسية')}</option>
                    <option value="shop">{t('كل المنتجات')}</option>
                    <option value="best">{t('الأكثر مبيعًا')}</option>
                    {pageOn('about') && <option value="about">{t('من نحن')}</option>}
                    {pageOn('contact') && <option value="contact">{t('تواصل معنا')}</option>}
                </optgroup>
                {from.pages.length > 0 && (
                    <optgroup label={t('صفحاتك المخصّصة')}>
                        {from.pages.map((p) => (
                            <option key={p.slug} value={`page:${p.slug}`}>
                                {(p.title_ar || p.title_en || p.slug) + (p.visible ? '' : ` — ${t('مخفيّة')}`)}
                            </option>
                        ))}
                    </optgroup>
                )}
                {from.collections.length > 0 && (
                    <optgroup label={t('المجموعات')}>
                        {from.collections.map((x) => (
                            <option key={x.slug} value={`collection:${x.slug}`}>
                                {(x.title_ar || x.title_en || x.slug) + (x.visible ? '' : ` — ${t('مخفيّة')}`)}
                            </option>
                        ))}
                    </optgroup>
                )}
                {from.categories.length > 0 && (
                    <optgroup label={t('الفئات')}>
                        {from.categories.map((x) => (
                            <option key={x.id} value={`category:${x.id}`}>{x.name}</option>
                        ))}
                    </optgroup>
                )}
                {withProducts && from.products.length > 0 && (
                    <optgroup label={t('المنتجات')}>
                        {from.products.map((x) => (
                            <option key={x.id} value={`product:${x.id}`}>{x.name}</option>
                        ))}
                    </optgroup>
                )}
                {withUrl && (
                    <optgroup label={t('رابط آخر')}>
                        <option value="url">{t('رابط تكتبه — داخل متجرك أو خارجه')}</option>
                    </optgroup>
                )}
            </select>
            {typed && (
                <>
                    <Input
                        dir="ltr"
                        value={String(value.value)}
                        maxLength={300}
                        placeholder="https://… /shop"
                        onChange={(e) => onChange({ type: 'url', value: e.target.value })}
                        aria-label={t('الرابط')}
                        data-testid={`${testid}-url`}
                    />
                    <p className={bad ? 'text-[11px] text-[#b91c1c]' : 'text-[11px] text-[#9ca3af]'} data-testid={`${testid}-url-hint`}>
                        {bad
                            ? t('الرابط غير صالح — اكتب رابطًا يبدأ بـ https:// أو بـ /.')
                            : t('رابطٌ كامل يبدأ بـ https:// يُفتح في نافذةٍ جديدة، أو مسارٌ في متجرك يبدأ بـ /')}
                    </p>
                </>
            )}
        </div>
    );
}
