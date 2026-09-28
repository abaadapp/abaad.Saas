import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import Topbar from '@/Components/Topbar';
import { pageProps } from './setup';

/**
 * الفرعُ يُختار من الشريط نفسِه — لا من جوف قائمة الحساب.
 *
 * ═══ الحال التي أنشأت هذا الملفّ ═══
 *
 * كان الفرعُ واللغةُ قد نُقلا إلى قائمة الحساب لأنّ الشريط حمل سبعة مداخل
 * فازدحم. واللغةُ تُضبط مرّةً في العمر فموضعُها هناك صحيح — أمّا الفرعُ
 * فيُبدَّل في اليوم مرارًا، وهو يرشّح كلَّ رقمٍ في اللوحة وفي تقرير
 * المبيعات. فمن يقرأ «مبيعات الشهر» لا يعرف نطاقَها إلّا بفتح قائمةٍ
 * ليسأل عنه.
 *
 * ═══ وما يُحرَس هنا ═══
 *
 * أنّه صار في الشريط، وأنّه **لم يبقَ في الاثنين**: بابان لاختيارٍ واحدٍ
 * يفترقان يومًا، وأحدهما يُنسى فيُصلَح الآخر وحده.
 *
 * وأنّ المسار هو القائم (`admin.branch.switch`) بـ«all» أو بمعرّف الفرع —
 * لا مسارَ جديد لتبديلٍ يعمل منذ بُني.
 *
 * وأنّ من لا فروعَ له لا يرى قائمةً فارغة.
 */

const draw = (branches: { id: number; name: string }[], branchId: number | null, branchName: string) => {
    Object.assign(pageProps, {
        auth: {
            abilities: ['pos', 'products'],
            mayActions: [],
            isEmployee: false,
            user: { name: 'المالك', avatar: null, roleLabel: 'مدير', role: 'admin', businessId: 1 },
        },
        context: {
            website: null, branches, branchId, branchName,
            currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, currencies: [],
        },
        notifications: null,
        reportPages: [],
        locale: 'ar',
        csrf: 'x',
        flash: {},
    });

    const base = globalThis.route as unknown as (...a: unknown[]) => string;
    globalThis.route = ((...a: unknown[]) => (a.length ? base(...a) : { current: () => 'admin.products.index' })) as never;

    return render(<Topbar onMenuClick={() => {}} />);
};

const TWO = [{ id: 4, name: 'مسقط' }, { id: 7, name: 'صلالة' }];

/** زرُّ الفرع في الشريط — يُعرف بعنوانه لا بموضعه */
const trigger = () => screen.getByTitle('الفرع');

describe('مبدّلُ الفرع في الشريط', () => {
    it('يعرض اسمَ الفرع المختار', () => {
        draw(TWO, 4, 'مسقط');

        expect(trigger()).toHaveTextContent('مسقط');
    });

    it('ويعرض «كل الفروع» حين لا اختيار', () => {
        draw(TWO, null, '');

        expect(trigger()).toHaveTextContent('كل الفروع');
    });

    it('ويفتح الفروعَ كلَّها ومعها «كل الفروع»', async () => {
        draw(TWO, 4, 'مسقط');
        await userEvent.setup().click(trigger());

        const menu = screen.getByRole('menu');
        expect(within(menu).getByText('كل الفروع')).toBeInTheDocument();
        expect(within(menu).getByText('مسقط')).toBeInTheDocument();
        expect(within(menu).getByText('صلالة')).toBeInTheDocument();
    });

    /* والمسارُ القائم: لا بابَ جديدًا لتبديلٍ يعمل منذ بُني */
    it('ويقصد المسارَ القائم بـ«all» وبمعرّف الفرع', async () => {
        draw(TWO, null, '');
        await userEvent.setup().click(trigger());

        const links = within(screen.getByRole('menu')).getAllByRole('menuitem');

        expect(links[0]).toHaveAttribute('href', expect.stringContaining('admin.branch.switch/all'));
        expect(links[1]).toHaveAttribute('href', expect.stringContaining('admin.branch.switch/4'));
        expect(links[2]).toHaveAttribute('href', expect.stringContaining('admin.branch.switch/7'));
    });

    /*
     * وهذا موضعُ الخطأ المحتمل: يُضاف إلى الشريط ويُترك في قائمة الحساب،
     * فيصير للاختيار الواحد بابان — يُصلَح أحدهما ويبقى الآخر على حاله.
     */
    it('ولا يبقى له بابٌ ثانٍ في قائمة الحساب', async () => {
        draw(TWO, 4, 'مسقط');

        // وقائمةُ الحساب تفتح بمرور الماوس لا بالنقر — كما بُنيت
        await userEvent.setup().hover(screen.getByRole('button', { name: /المالك/ }));

        const menus = screen.getAllByRole('menu');
        const account = menus[menus.length - 1];

        expect(within(account).queryByText('كل الفروع')).toBeNull();
        expect(within(account).queryByText('صلالة')).toBeNull();
        // واللغةُ تبقى حيث كانت — لم يُمسّ غيرُ الفرع
        expect(within(account).getByText('اللغة')).toBeInTheDocument();
    });

    it('ونشاطٌ بلا فروعٍ لا يرى مبدّلًا فارغًا', () => {
        draw([], null, '');

        expect(screen.queryByTitle('الفرع')).toBeNull();
    });
});
