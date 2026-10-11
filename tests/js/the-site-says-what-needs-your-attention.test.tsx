import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import Hub, { type AttentionItem } from '@/Pages/Admin/Website/Hub';
import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));

/**
 * «ما يحتاج انتباهك في موقعك» — بندٌ لكلّ ما ينقص، وأسماؤه، وبابُ إصلاحه.
 *
 * والشاشةُ لا تحسب شيئًا: تعرض ما بناه الخادم (`Store\SiteAttention`) —
 * فما يُحرس هنا أنّ كلّ بندٍ يُقرأ ويُضغط، وأنّ القائمةَ الفارغة تقول ذلك.
 */
const base = {
    site: { id: 0, name: 'RIBBON', goal: 'store', goal_label: 'بيع المنتجات', template: 'ribbon', state: 'published', sells: true, maintenance: false, published_at: null, saved_at: null, changes: false, url: 'https://ribbon.abaadapp.om', tokens: {} },
    readiness: [],
    channel: 'checkout',
    sells: true,
    counts: { shown: 3, hidden: 1, out: 0 },
    products: [],
    may: { products: true, configure: true },
    theme: 'ribbon',
    orders: { today: 2 },
};

const ITEMS: AttentionItem[] = [
    {
        key: 'photos',
        title: 'منتجات منشورة بلا صورة: 7',
        why: 'الزبون يرى صورة بديلة بدل منتجك.',
        items: [
            { name: 'باقة الربيع', href: '/admin/products/4/edit' },
            { name: 'علبة شوكولاتة', href: '/admin/products/9/edit' },
        ],
        more: 5,
        action: null,
    },
    {
        key: 'card_images',
        title: 'فئات تظهر بلا صورة بطاقة: 1',
        why: 'بطاقتها في «تسوّق حسب الفئة» خطوطٌ ملوّنة بدل صورة.',
        items: [{ name: 'منتجات', href: null }],
        more: 0,
        action: { label: 'تسوّق حسب الفئة', href: '/admin/website/editor?row=cats' },
    },
    {
        key: 'links_off',
        title: 'روابط الدفع غير مفعّلة — ينقص مفتاح API أو البيئة',
        why: 'لا تستطيع أن ترسل لعميلك رابطًا يدفع به.',
        items: [],
        more: 0,
        action: { label: 'إعدادات Paymob', href: '/admin/integrations/paymob' },
    },
];

describe('ما يحتاج انتباهك في موقعك', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
        Object.assign(pageProps, { translations: {}, context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } } });
    });

    it('يعدّ البنود ويقول لكلٍّ سببَه وأسماءه وبابَه', () => {
        Object.assign(pageProps, base, { attention: ITEMS });
        render(<Hub />);

        const box = screen.getByTestId('attention');
        expect(within(box).getByText('ما يحتاج انتباهك في موقعك')).toBeInTheDocument();
        expect(within(box).getByText('3')).toBeInTheDocument();

        const photos = screen.getByTestId('attention-photos');
        expect(within(photos).getByText('منتجات منشورة بلا صورة: 7')).toBeInTheDocument();
        expect(within(photos).getByText('الزبون يرى صورة بديلة بدل منتجك.')).toBeInTheDocument();
        expect(within(photos).getByRole('link', { name: 'باقة الربيع' })).toHaveAttribute('href', '/admin/products/4/edit');
        expect(within(photos).getByText(/و5 غيرها/)).toBeInTheDocument();

        // اسمٌ بلا صفحةٍ يُكتب ولا يُربط، وزرُّ البند يقود إلى بابه
        const cards = screen.getByTestId('attention-card_images');
        expect(within(cards).queryByRole('link', { name: 'منتجات' })).toBeNull();
        expect(within(cards).getByText('منتجات')).toBeInTheDocument();
        expect(within(cards).getByRole('link', { name: 'تسوّق حسب الفئة' })).toHaveAttribute('href', '/admin/website/editor?row=cats');

        expect(within(screen.getByTestId('attention-links_off')).getByRole('link', { name: 'إعدادات Paymob' })).toHaveAttribute(
            'href',
            '/admin/integrations/paymob',
        );
    });

    it('وبلا ما ينقص: «موقعك مكتمل» — لا صندوقٌ فارغ', () => {
        Object.assign(pageProps, base, { attention: [] });
        render(<Hub />);

        expect(screen.queryByTestId('attention')).toBeNull();
        expect(screen.getByTestId('attention-clear')).toHaveTextContent('موقعك مكتمل — لا شيء ينقصه الآن.');
    });

    it('وموقعٌ لم تصله القائمةُ أصلًا لا يُقال عنه شيء', () => {
        Object.assign(pageProps, base);
        render(<Hub />);

        expect(screen.queryByTestId('attention')).toBeNull();
        expect(screen.queryByTestId('attention-clear')).toBeNull();
    });
});
