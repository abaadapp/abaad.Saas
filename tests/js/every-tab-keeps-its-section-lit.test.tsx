import { describe, expect, it } from 'vitest';

import {
    CUSTOMER_TABS,
    EMPLOYEE_TABS,
    FINANCE_TABS,
    INVENTORY_TABS,
    PRODUCT_TABS,
    PURCHASE_TABS,
    WEBSITE_TABS,
    type SectionTab,
} from '@/Components/SectionTabs';
import { NAV, type NavItem } from '@/lib/nav';

/**
 * كلُّ تبويبٍ يُبقي قسمَه مضيئًا في الشريط — ولا يترك التاجرَ بلا موضع.
 *
 * ═══ العطبُ الذي وُضع له ═══
 *
 * شريطُ تبويبات القسم يفتح صفحاتٍ لا مدخلَ لها في القائمة الجانبية:
 * «المواسم» في المنتجات، و«القيود اليومية» في المالية، و«سندات الموردين»
 * في المشتريات. ولا تُضيء أنفسَها — يُضيئها العنصرُ الذي تتبعه عبر `covers`.
 *
 * فتبويبٌ يُضاف بلا أن يُغطَّى يُطفئ القائمةَ كلَّها تحت يد من يفتحه: لا
 * عنصرَ مضيء، فلا يعرف أين هو ولا إلى أيّ قسمٍ يعود. وهو عطبٌ صامت — لا
 * يُخطئ شيءٌ ولا يسقط اختبار، والشاشةُ تعمل.
 *
 * وقد وقع فعلًا: «المواسم» بقيت خارج التغطية من يوم أُضيفت.
 *
 * ═══ ولماذا يُفحص الجميع لا المواسمَ وحدها ═══
 *
 * إصلاحُ تبويبٍ بعينه يُصلح اليوم. وهذا يفحص كلَّ شريطٍ في النظام، فيسقط
 * يوم يُضاف التبويبُ القادم بلا تغطية — وهو اليومُ الذي لا يتذكّر فيه أحد.
 */

/** أشرطةُ التبويبات كلُّها والعنصرُ الذي يجب أن يُضيئها */
const BARS: Array<{ name: string; tabs: SectionTab[]; owner: string }> = [
    { name: 'المنتجات', tabs: PRODUCT_TABS, owner: 'admin.products.index' },
    { name: 'العملاء', tabs: CUSTOMER_TABS, owner: 'admin.customers.index' },
    { name: 'المشتريات', tabs: PURCHASE_TABS, owner: 'admin.purchases.index' },
    { name: 'المخزون', tabs: INVENTORY_TABS, owner: 'admin.inventory.index' },
    { name: 'المالية', tabs: FINANCE_TABS, owner: 'admin.finance.index' },
    { name: 'الموظفين', tabs: EMPLOYEE_TABS, owner: 'admin.employees.index' },
    /*
     * وشاشاتُ الموقع يملكها «الإعدادات» لا «الموقع الإلكتروني» — وهو مقصود.
     *
     * التصميمُ والصفحاتُ والنطاقُ والسيو تُضبط مرّةً ثمّ تُترك، فنُقلت إلى
     * «الإعدادات ‹ الموقع الإلكتروني»، وبقي بابُ الشريط للتشغيل اليوميّ.
     * فمن يفتح «التصميم» يجب أن تُضيء له «الإعدادات» — لا الموقع.
     */
    { name: 'الموقع الإلكتروني', tabs: WEBSITE_TABS, owner: 'admin.settings.index' },
];

const flat: NavItem[] = NAV.flatMap((g) => g.items).flatMap((i) => [i, ...(i.children ?? [])]);

/** أيُضيء هذا المسارُ عنصرًا في الشريط؟ — بالقاعدة نفسِها التي في `Sidebar` */
const lights = (path: string): boolean =>
    flat.some(
        (i) =>
            i.route === path ||
            path.startsWith(i.route.replace(/\.index$/, '.')) ||
            i.covers?.some((c) => c === path || path.startsWith(c.replace(/\.index$/, '.'))),
    );

describe('كلُّ تبويبٍ يقول أين صاحبُه', () => {
    it.each(BARS)('شريطُ $name يُبقي قسمَه مضيئًا', ({ tabs }) => {
        const dark = tabs.map((tb) => tb.routeName).filter((r) => !lights(r));

        expect(dark, `تبويباتٌ تُطفئ القائمة: ${dark.join('، ')}`).toEqual([]);
    });

    /* والمواسمُ صراحةً: هي العطبُ الذي كُتب له هذا الملفّ */
    it('والمواسمُ تُضيء «المنتجات» لا تُطفئ الشريط', () => {
        const products = flat.find((i) => i.route === 'admin.products.index');

        expect(products?.covers).toContain('admin.seasons.index');
        expect(lights('admin.seasons.index')).toBe(true);
        // وصفحةُ الموسم الواحد تتبعها — المطابقةُ بالعائلة
        expect(lights('admin.seasons.show')).toBe(true);
    });

    /* ولا يُضيء تبويبٌ قسمًا ليس قسمَه */
    it('ولا عنصرَ يدّعي ما ليس له', () => {
        const owners = BARS.flatMap((bar) =>
            bar.tabs.map((tb) => ({ tab: tb.routeName, owner: bar.owner })),
        );

        for (const { tab, owner } of owners) {
            const claimers = flat.filter((i) => i.covers?.includes(tab)).map((i) => i.route);

            if (claimers.length === 0) continue;

            expect(claimers, `«${tab}» يدّعيه أكثر من قسم`).toEqual([owner]);
        }
    });
});
