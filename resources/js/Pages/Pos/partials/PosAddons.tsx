import { Plus, PlusCircle } from 'lucide-react';
import { useTranslate } from '@/lib/i18n';
import type { CartItem } from '@/hooks/usePosCart';
import type { Addon } from '@/types/models';
import { addonsLayoutOf, type AddonsLayout } from '@/lib/addonsLayout';

export { addonsLayoutOf, type AddonsLayout };

/*
 * إضافاتُ المتجر في نقطة البيع — شريطًا أم قسمًا كاملًا.
 *
 * تفضيلُ عرضٍ يختاره صاحبُ النشاط (`PosAddonsLayout` في الخادم). والإضافاتُ
 * هي هي في الوضعين، والسلّةُ تستقبلها بالبند نفسه (`addonLine`) — فلا طريقَ
 * بيعٍ ثانٍ ولا سعرَ يُقرأ من مكانٍ آخر.
 */

/**
 * قيمةُ تبويب «الإضافات» في صفّ الأقسام.
 *
 * ولا يُستعمل الاسمُ «الإضافات» قيمةً: متجرٌ قد يسمّي قسمَ منتجاتٍ حقيقيًّا
 * بهذا الاسم، فيختلط التبويبان. وهذه القيمة لا تُكتب في قاعدةٍ أبدًا.
 */
export const ADDONS_TAB = '__addons__';

/**
 * ما يُرسم لهذه الطريقة وهذا التبويب.
 *
 * الشريطُ: كما كان حرفًا — شريطٌ فوق الشبكة، والشبكةُ كما هي.
 *
 * القسمُ الكامل:
 *  · «الكل»: قسمُ الإضافات ثمّ المنتجات تحته.
 *  · «الإضافات»: الإضافاتُ وحدها.
 *  · قسمُ منتجاتٍ بعينه: منتجاتُه وحدها — بلا إضافاتٍ تُحشر فوقها.
 */
export function addonsView(layout: AddonsLayout, cat: string, allValue = 'الكل') {
    if (layout === 'bar') return { bar: true, section: false, products: true };

    if (cat === ADDONS_TAB) return { bar: false, section: true, products: false };

    return { bar: false, section: cat === allValue, products: true };
}

/** ما يُعرض من إضافات المتجر — النشطةُ وحدها، كما كان الشريطُ يرشّحها */
export const shownAddons = (addons: Addon[]) => addons.filter((a) => a.active);

/** الرمزُ كما كان الشريطُ يقرؤه: إيموجي، وإلّا هديّة */
export const addonEmoji = (a: Addon) => (/[^\x00-\x7F]/.test(a.icon) ? a.icon : '🎁');

/**
 * البندُ الذي يدخل السلّة — واحدٌ للشريط والقسم.
 *
 * المعرّفُ `null` و`addon_id` يحمل الإضافة: الخادمُ يُسعّر به ولا يثق بالسعر
 * المُرسَل (`CartItem::addon_id`).
 */
export function addonLine(a: Addon): Omit<CartItem, 'qty' | 'note'> {
    return { key: `a${a.id}`, id: null, addon_id: a.id, name: a.label, price: a.price, icon: addonEmoji(a), image: null };
}

interface Props {
    addons: Addon[];
    money: (v: number) => string;
    onAdd: (line: Omit<CartItem, 'qty' | 'note'>) => void;
}

/** الشريطُ — كما كان في الصفحة، نُقل ولم يتغيّر */
export function AddonsBar({ addons, money, onAdd }: Props) {
    const t = useTranslate();

    if (addons.length === 0) return null;

    return (
        <div className="mb-4 flex shrink-0 items-center gap-2 overflow-x-auto pb-1" data-testid="pos-addons-bar">
            <span className="inline-flex shrink-0 items-center gap-1 whitespace-nowrap text-xs font-bold text-[#7c3aed]">
                <PlusCircle className="size-4" /> {t('الإضافات')}:
            </span>
            {addons.map((a) => (
                <button
                    key={a.id}
                    type="button"
                    onClick={() => onAdd(addonLine(a))}
                    className="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:border-[#c4b5fd] hover:bg-[#f5f3ff]"
                >
                    <span className="text-base leading-none">{addonEmoji(a)}</span>
                    <span>{a.label}</span>
                    <span className="text-xs font-bold text-[#7c3aed]">{money(a.price)}</span>
                </button>
            ))}
        </div>
    );
}

/**
 * القسمُ الكامل — شبكةٌ بعرض شبكة المنتجات وأعمدتها.
 *
 * ولا صورَ للإضافة ولا عمودَ جديد: رمزُها واسمُها وسعرُها يكفيان بطاقة.
 * والبطاقةُ بحجمٍ يُضغط بالإصبع على الآيباد.
 */
export function AddonsSection({ addons, money, onAdd }: Props) {
    const t = useTranslate();

    if (addons.length === 0) return null;

    return (
        <section className="mb-5" data-testid="pos-addons-section">
            <h3 className="mb-3 flex items-center gap-1.5 text-sm font-bold text-[#7c3aed]">
                <PlusCircle className="size-4" /> {t('الإضافات')}
            </h3>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-4">
                {addons.map((a) => (
                    <button
                        key={a.id}
                        type="button"
                        onClick={() => onAdd(addonLine(a))}
                        data-testid={`pos-addon-card-${a.id}`}
                        className="group flex select-none flex-col rounded-2xl border border-gray-100 bg-white p-3 text-start shadow-sm transition-[border-color,box-shadow,background-color] hover:border-[#c4b5fd] hover:shadow-md active:bg-[#f5f3ff] touch:p-4"
                    >
                        <span className="flex aspect-[2/1] items-center justify-center rounded-xl bg-[#f5f3ff] text-4xl leading-none">
                            {addonEmoji(a)}
                        </span>
                        <span className="mt-2 truncate text-sm font-semibold text-gray-800">{a.label}</span>
                        <span className="mt-2 flex items-center justify-between gap-1">
                            <span className="text-sm font-bold text-[#7c3aed]">{money(a.price)}</span>
                            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#f5f3ff] text-[#7c3aed] transition-colors group-hover:bg-[#7c3aed] group-hover:text-white">
                                <Plus className="size-4" />
                            </span>
                        </span>
                    </button>
                ))}
            </div>
        </section>
    );
}
