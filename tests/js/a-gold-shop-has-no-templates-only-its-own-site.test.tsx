import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import Hub from '@/Pages/Admin/Website/Hub';
import { pageProps } from './setup';

/*
 * والهيكلُ يُستبدل بجسدٍ فارغ: الشريطُ الجانبيّ والعلويّ يقرآن جلسةً كاملة،
 * وما يُختبر هنا الشاشةُ لا هيكلُها.
 */
vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));

/**
 * لوحةُ الموقع لمن لبس واجهةً خاصّة — RIBBON — تقول إنّها موقعه، وتقود
 * ضبطَه إلى الإعدادات لا إلى شاشات البانِي، وتقول أين تصل طلباتُه.
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

describe('لوحةُ الواجهة الخاصّة', () => {
    beforeEach(() => {
        Object.assign(pageProps, { translations: {}, context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } } });
    });

    it('تقول إنّ الموقع واجهةُ RIBBON وتقود الإعدادات إلى بطاقة المتجر لا إلى البانِي', () => {
        Object.assign(pageProps, base);
        render(<Hub />);

        expect(screen.getByText(/واجهة RIBBON/)).toBeInTheDocument();
        expect(screen.getByText('منشور')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /افتح الموقع/ })).toHaveAttribute('href', 'https://ribbon.abaadapp.om');
        expect(screen.getByRole('link', { name: /إعدادات الموقع/ })).toHaveAttribute('href', '/admin.settings.index/website');

        // والطلباتُ حقيقيّةٌ تصل «الطلبات» — لا «تصلك على واتساب»
        expect(screen.getByText(/طلبًا حقيقيًّا بقناة الموقع/)).toBeInTheDocument();
        expect(screen.getByText(/طلبات الموقع اليوم/)).toHaveTextContent('2');
        expect(screen.getByRole('link', { name: 'افتح الطلبات' })).toHaveAttribute('href', '/admin.orders.index');
        expect(screen.queryByText(/واتساب/)).toBeNull();
    });

    it('وغيرُ المنشورة تقول «غير منشور» و«لا يفتح بعد» — لا «مسوّدة»', () => {
        Object.assign(pageProps, {
            ...base,
            site: { ...base.site, state: 'draft', url: null },
            readiness: [{ key: 'published', label: 'النشر', ok: false, optional: false, detail: '«نشر المتجر» مُطفأ في الإعدادات — لا يفتح زبونٌ موقعك حتى تُفعّله.' }],
        });
        render(<Hub />);

        expect(screen.getByText('غير منشور')).toBeInTheDocument();
        expect(screen.queryByText(/مسوّدة/)).toBeNull();
        expect(screen.getByText('موقعك لا يفتح بعد:')).toBeInTheDocument();
        expect(screen.getByText(/«نشر المتجر» مُطفأ/)).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /افتح الموقع/ })).toBeNull();
    });

    it('ولوحةُ البانِي على حالها لمن لم يلبس واجهة', () => {
        Object.assign(pageProps, { ...base, theme: null, orders: undefined, channel: 'whatsapp', site: { ...base.site, template: 'mono', state: 'draft', url: null } });
        render(<Hub />);

        expect(screen.getByText('مسوّدة — لم يُنشر بعد')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /إعدادات الموقع/ })).toHaveAttribute('href', '/admin.website.site');
        expect(screen.getByText(/تصلك على واتساب/)).toBeInTheDocument();
        expect(screen.queryByText(/طلبات الموقع اليوم/)).toBeNull();
    });
});
