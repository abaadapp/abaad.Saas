import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import CustomersIndex from '@/Pages/Admin/Customers/Index';
import CustomerShow from '@/Pages/Admin/Customers/Show';
import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));

/**
 * اسمُ العميل حقلٌ واحد — يُحفظ ويُقرأ كما كُتب.
 *
 * ═══ ما كان ═══
 *
 * نموذجا الإضافة والتعديل يحملان حقلًا ثانيًا «الاسم بالإنجليزية» يختار
 * بينه وبين الاسم العرضُ بلغة الواجهة، والقائمةُ والملفّ يكتبان تحت الاسم
 * اسمًا ثانيًا. فالعميلُ الواحد يُقرأ باسمين.
 *
 * ═══ وما يُحرس ═══
 *
 *   - نموذجا الإضافة والتعديل بحقل اسمٍ واحد، يُكتب بأيّ حرف.
 *   - والاسمُ في رأس الملفّ هو المحفوظ، بلا سطرٍ ثانٍ.
 *   - و`name_en` المحفوظُ قبلُ (عميلٌ نُقل اسمُه آليًّا) يُعرض في نافذة التعديل
 *     ولا يُفرض: ضغطةٌ تجعله الاسم، ولا يُحفظ شيءٌ حتى «حفظ».
 *
 * والخادمُ في `ACustomersNameIsKeptAsWrittenTest`.
 */

const base = {
    translations: {},
    context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
    auth: { abilities: [], mayActions: [], user: { businessId: 3 } },
};

const customer = (over: Record<string, unknown> = {}) => ({
    id: 41, name: 'أحمد البلوشي', name_en: null, label: 'أحمد البلوشي', phone: '99110001', email: null,
    points: 0, language: 'en', address: null, notes: null, branch_id: null, orders: 0, total_spent: 0,
    last_order: '—', ...over,
});

const show = (c: Record<string, unknown>) => {
    Object.assign(pageProps, base, {
        customer: c, orders: [], addresses: [], branches: [],
        customerFlags: { alerts: false, blocking: false, birthdays: false },
    });

    return render(<CustomerShow />);
};

const openEdit = async () => {
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'المزيد' }));
    await user.click(await screen.findByRole('menuitem', { name: /تعديل/ }));

    return { user, dialog: await screen.findByRole('dialog') };
};

beforeEach(() => {
    Object.assign(pageProps, base);
});

afterEach(() => {
    Object.assign(pageProps, { translations: {}, context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } }, auth: { abilities: [], mayActions: [] } });
});

describe('ملفُّ العميل', () => {
    it.each(['أحمد البلوشي', 'Ahmed Al Balushi', 'Mohammed محمد'])('يقرأ «%s» كما هو — بلا اسمٍ ثانٍ تحته', (name) => {
        show(customer({ name, label: name, name_en: 'Legacy' }));

        expect(screen.getByRole('heading', { name })).toBeInTheDocument();
        expect(screen.queryByText('Legacy')).toBeNull();
    });

    it('ونافذةُ التعديل بحقل اسمٍ واحد — يُكتب بأيّ حرف', async () => {
        show(customer());
        const { dialog } = await openEdit();

        const name = within(dialog).getByDisplayValue('أحمد البلوشي');
        expect(name).toHaveAttribute('dir', 'auto');
        expect(within(dialog).queryByText(/الاسم بالإنجليزية/)).toBeNull();
        expect(within(dialog).queryByTestId('customer-earlier-name')).toBeNull();
    });

    it('واسمٌ لاتينيّ حُفظ قبلُ يُعرض — وضغطةٌ تجعله الاسم، ولا يُحفظ وحده', async () => {
        show(customer({ name_en: 'Ahmed Al Balushi' }));
        const { user, dialog } = await openEdit();

        const earlier = within(dialog).getByTestId('customer-earlier-name');
        expect(earlier).toHaveTextContent('محفوظ سابقًا بالإنجليزية:');
        expect(earlier).toHaveTextContent('Ahmed Al Balushi');

        await user.click(within(earlier).getByRole('button', { name: 'استعمله اسمًا' }));

        expect(within(dialog).getByDisplayValue('Ahmed Al Balushi')).toBeInTheDocument();
        // وقد صار الاسمَ — فلا يُعرض ثانيةً
        expect(within(dialog).queryByTestId('customer-earlier-name')).toBeNull();
    });
});

describe('قائمةُ العملاء', () => {
    const index = (rows: Record<string, unknown>[]) => {
        Object.assign(pageProps, base, {
            customers: rows,
            pagination: { current_page: 1, last_page: 1, from: 1, to: rows.length, total: rows.length, prev_page_url: null, next_page_url: null },
            filters: {}, sorts: [], stats: [], branches: [], currentBranchId: null, unlanguaged: 0,
        });

        return render(<CustomersIndex />);
    };

    it('تقرأ كلَّ اسمٍ كما كُتب — بلا اسمٍ ثانٍ تحته', () => {
        index([
            customer({ id: 1, name: 'أحمد البلوشي', label: 'أحمد البلوشي', name_en: 'Legacy' }),
            customer({ id: 2, name: 'Ahmed Al Balushi', label: 'Ahmed Al Balushi' }),
            customer({ id: 3, name: 'Mohammed محمد', label: 'Mohammed محمد' }),
        ]);

        ['أحمد البلوشي', 'Ahmed Al Balushi', 'Mohammed محمد'].forEach((n) => expect(screen.getAllByText(n).length).toBeGreaterThan(0));
        expect(screen.queryByText('Legacy')).toBeNull();
    });

    it('ونافذةُ الإضافة بحقل اسمٍ واحد', async () => {
        index([]);
        const user = userEvent.setup();

        await user.click(screen.getByRole('button', { name: /إضافة عميل/ }));
        const dialog = await screen.findByRole('dialog');

        expect(within(dialog).getByText('يُحفظ ويظهر كما تكتبه — بالعربية أو بالإنجليزية')).toBeInTheDocument();
        expect(within(dialog).queryByText(/الاسم بالإنجليزية/)).toBeNull();
        expect(within(dialog).queryByPlaceholderText('e.g. Mohammed Salem')).toBeNull();
    });
});
