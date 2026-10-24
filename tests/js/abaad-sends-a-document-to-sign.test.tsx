import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';

import DocumentsIndex from '@/Pages/Platform/Documents/Index';
import DocumentShow from '@/Pages/Platform/Documents/Show';
import { pageProps } from './setup';

/**
 * قسم المستندات في لوحة المنصّة — ما يراه المديرُ قبل الإرسال وبعد التوقيع.
 *
 * الإصدارُ والتوقيعُ والبصمةُ يحرسها PHP (`AClientSignsTheDocumentAbaadSentHimTest`).
 * وهنا ما لا يراه إلّا متصفّح: أنّ نافذةَ الإصدار تسأل عن الباقة حين يتضمّنها
 * القالب وعن المبلغ حين يتضمّنه، وأنّ المستندَ المُرسَل يعرض رابطَه ونسخَه
 * وواتسابه، والموقَّعَ توقيعَه وبصمتَه ولا رابطَ له.
 */

const layout = (current: string) => {
    const base = globalThis.route as unknown as (...a: unknown[]) => string;
    globalThis.route = ((...a: unknown[]) => (a.length ? base(...a) : { current: () => current })) as never;
    Object.assign(pageProps, {
        auth: {
            abilities: [], mayActions: [], isEmployee: false,
            user: { name: 'سالم', avatar: null, roleLabel: 'مدير المنصة', role: 'super_admin', businessId: null },
        },
        context: null, notifications: null, supportBadge: 0, crmBadge: 0, crmLeadsBadge: 0,
        reportPages: [], locale: 'ar', csrf: 'x', flash: {},
    });
};

const row = (over = {}) => ({
    id: 3, url: '/super-admin/documents/3', number: 'AB-202610-0003', title: 'عقد اشتراك', service: 'اشتراك النظام',
    client_name: 'سالم الهنائي', client_biz: 'زهور الريان', business: null, state: 'sent' as const,
    state_label: 'بانتظار التوقيع', created_at: '2026-10-24 10:00', expires_at: '2026-11-07 23:59', signed_at: '—',
    ...over,
});

function index(templates: { includes_plan: boolean; includes_amount: boolean }[]) {
    layout('super-admin.documents.index');
    Object.assign(pageProps, {
        documents: [row()],
        pagination: { current_page: 1, last_page: 1, from: 1, to: 1, total: 1, per_page: 20, prev_page_url: null, next_page_url: null },
        filters: { state: '', q: '' },
        counts: { sent: 1, signed: 0 },
        templates: templates.map((t, i) => ({ id: i + 1, name: 'قالب ' + (i + 1), service: null, ...t })),
        plans: [{ id: 2, name: 'الباقة الاحترافية', prices: { monthly: '29.900', semiannual: '179.400', yearly: '299.000' } }],
        cycles: [{ value: 'monthly', label: 'شهري' }, { value: 'yearly', label: 'سنوي' }],
        businesses: [{ id: 5, name: 'زهور الريان', plan_id: 2, owner: 'سالم', phone: '+968' }],
        days: 14,
    });

    return render(<DocumentsIndex />);
}

function show(over = {}) {
    layout('super-admin.documents.show');
    Object.assign(pageProps, {
        document: {
            ...row(),
            title_en: 'Subscription Agreement',
            terms: [{ h: 'موضوع العقد:', b: 'يمنح الطرف الأول…' }],
            plan: { name: 'الباقة الاحترافية', cycle_label: 'سنوي', unit: 'سنويًا', price: '299.000', limits: 'حتى 3 فروع' },
            amount: null, amount_label: null,
            client: { name: 'سالم الهنائي', biz: 'زهور الريان' },
            notes: null, created_by: 'مدير المنصة', viewed_at: '—', cancelled_at: '—', cancel_reason: null,
            signer_ip: null, signer_agent: null, content_hash: null, intact: null, signature: null,
            link: 'https://app.abaadapp.om/sign/abcdefghijklmnopqrstuvwxyz123456',
            whatsapp: 'https://wa.me/96891234567?text=x',
            ...over,
        },
        fields: [
            { k: 'name', ar: 'اسم العميل', req: true },
            { k: 'cr', ar: 'السجل التجاري', req: true, dir: 'ltr' },
        ],
    });

    return render(<DocumentShow />);
}

describe('the platform documents section', () => {
    beforeEach(() => document.body.replaceChildren());

    it('lists the document with its client and state, and the sidebar names the section', () => {
        index([{ includes_plan: true, includes_amount: false }]);

        expect(screen.getByText('زهور الريان')).toBeInTheDocument();
        expect(screen.getAllByText('بانتظار التوقيع').length).toBeGreaterThan(0);
        expect(screen.getByText('عقد اشتراك').closest('a')).toHaveAttribute('href', '/super-admin/documents/3');
        expect([...document.querySelectorAll('a')].some((a) => a.textContent?.startsWith('المستندات'))).toBe(true);
    });

    it('asks for the plan when the template carries it', async () => {
        index([{ includes_plan: true, includes_amount: false }]);
        await userEvent.click(screen.getByRole('button', { name: 'مستند جديد' }));
        expect(screen.getByText('دورة الاشتراك')).toBeInTheDocument();
        expect(screen.queryByText('المبلغ (ر.ع)')).toBeNull();
    });

    it('asks for the amount when the template carries it — and a new client is written by hand', async () => {
        index([{ includes_plan: false, includes_amount: true }]);
        await userEvent.click(screen.getByRole('button', { name: 'مستند جديد' }));
        expect(screen.getByText('المبلغ (ر.ع)')).toBeInTheDocument();
        expect(screen.queryByText('دورة الاشتراك')).toBeNull();

        // وعميلٌ جديد يُكتب اسمُه ونشاطُه بلا حساب
        await userEvent.click(screen.getByRole('tab', { name: 'عميل جديد' }));
        expect(screen.getByText('اسم النشاط')).toBeInTheDocument();
    });

    it('shows a sent document its link, a copy button and a WhatsApp link the admin presses himself', () => {
        show();

        const box = document.querySelector('[data-testid="document-link"]') as HTMLElement;
        expect(box.textContent).toContain('https://app.abaadapp.om/sign/abcdefghijklmnopqrstuvwxyz123456');
        expect(screen.getByText('إرسال عبر واتساب').closest('a')).toHaveAttribute('href', 'https://wa.me/96891234567?text=x');
        expect(screen.getByText('يكمله العميل')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /إلغاء الرابط/ })).toBeInTheDocument();
    });

    it('shows a signed document its signature and fingerprint — and no link to send again', () => {
        show({
            state: 'signed', state_label: 'موقّع', signed_at: '2026-10-25 12:00',
            signature: 'data:image/png;base64,AAAA', content_hash: 'f'.repeat(64), intact: true,
            signer_ip: '203.0.113.9',
        });

        expect(document.querySelector('[data-testid="document-link"]')).toBeNull();
        expect(screen.getByAltText('التوقيع')).toHaveAttribute('src', 'data:image/png;base64,AAAA');
        expect(document.body.textContent).toContain('f'.repeat(64));
        expect(screen.queryByRole('button', { name: /إلغاء الرابط/ })).toBeNull();
        expect(screen.queryByText('تغيّر محتوى المستند بعد التوقيع — البصمة لا تطابق')).toBeNull();
    });

    it('warns when the signed content no longer matches its fingerprint', () => {
        show({ state: 'signed', state_label: 'موقّع', content_hash: 'a'.repeat(64), intact: false });

        expect(screen.getByText('تغيّر محتوى المستند بعد التوقيع — البصمة لا تطابق')).toBeInTheDocument();
    });
});
