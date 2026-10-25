import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, beforeEach, describe, expect, it } from 'vitest';

import DocumentsIndex from '@/Pages/Platform/Documents/Index';
import { BusinessDocuments } from '@/Pages/Platform/Businesses/Show';
import { pageProps } from './setup';

/**
 * مستنداتُ الشركة في ملفّها، ونافذةُ الإصدار تسمّي القوالبَ كلَّها.
 *
 * ما يُربط بالشركة وما يُصدر منه يحرسه PHP (`ACompanyKeepsItsDocumentsTest`). وهنا:
 *   - القوالبُ كلُّها في القائمة وتُختار — لا «غير منشور» ولا تعطيل.
 *   - «مستند جديد» من ملفّ الشركة يفتح النافذةَ والشركةُ مختارة.
 *   - بطاقةُ «مستندات الشركة» أسفلَ ملفّها تعرض مستنداتها ورابطَ مستندٍ جديد لها.
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

const row = {
    id: 9, url: '/super-admin/documents/9', pdf_url: '/super-admin/documents/9/pdf', number: 'AB-202610-0009',
    title: 'عقد اشتراك', service: 'اشتراك النظام', client_name: 'سالم الهنائي', client_biz: 'زهور الريان',
    business: { id: 5, name: 'زهور الريان' }, plan_name: 'الباقة الاحترافية', cycle_label: 'سنوي', value: '299.000', value_unit: null, renewal: false,
    shared: null, shared_at: null, state: 'signed' as const, state_label: 'موقّع',
    created_at: '2026-10-25 10:00', expires_at: '2026-11-08 23:59', signed_at: '2026-10-25 11:00',
};

const documentsPage = (over: Record<string, unknown> = {}) => {
    layout('super-admin.documents.index');
    Object.assign(pageProps, {
        documents: [row], pagination: { current_page: 1, last_page: 1, from: 1, to: 1, total: 1, per_page: 20, prev_page_url: null, next_page_url: null },
        filters: { state: '', q: '', business: '5' }, counts: { sent: 0, signed: 1 },
        templates: [
            {
                id: 1, problems: [], version: 1, hash: 'a'.repeat(64), preview_url: '/p/1', preview_pdf_url: '/p/1.pdf', name: 'عقد اشتراك أبعاد', service: 'اشتراك النظام', title: 'عقد اشتراك', terms: [{ h: 'البند', b: 'نصّه' }], form: null,
                includes_plan: false, includes_amount: false, default_amount: null, default_amount_label: null, amount_rule: null,
            },
            {
                id: 2, problems: [], version: 1, hash: 'b'.repeat(64), preview_url: '/p/2', preview_pdf_url: '/p/2.pdf', name: 'اتفاقية الاشتراك واستخدام منصة أبعاد', service: 'اشتراك النظام', title: 'اتفاقية الاشتراك', terms: [], form: null,
                includes_plan: false, includes_amount: false, default_amount: null, default_amount_label: null, amount_rule: null,
            },
        ],
        company: { id: 5, name: 'زهور الريان', url: '/super-admin/businesses/5' },
        opening: true,
        plans: [], cycles: [{ value: 'monthly', label: 'شهري', months: 1, duration: 'شهر' }],
        businesses: [
            { id: 4, name: 'مخبز الرحمة', plan_id: null, owner: 'خالد', phone: '+96892345678', starts_on: '2026-10-25' },
            { id: 5, name: 'زهور الريان', plan_id: null, owner: 'سالم', phone: '+96891234567', starts_on: '2026-11-01' },
        ],
        today: '2026-10-25', days: 14, renew: null,
        ...over,
    });

    return render(<DocumentsIndex />);
};

beforeAll(() => {
    // ‏القائمةُ المنسدلة تسأل عن مؤشّرٍ لا يملكه jsdom
    Element.prototype.hasPointerCapture = () => false;
    Element.prototype.setPointerCapture = () => {};
    Element.prototype.releasePointerCapture = () => {};
    Element.prototype.scrollIntoView = () => {};
});

describe('a company keeps its documents', () => {
    beforeEach(() => document.body.replaceChildren());

    it('“new document” from the company file opens the dialog with the company chosen', () => {
        documentsPage();
        const dialog = screen.getByTestId('new-document');

        expect(within(dialog).getAllByRole('combobox')[1]).toHaveTextContent('زهور الريان');
        expect(within(dialog).getByDisplayValue('+96891234567')).toBeInTheDocument();
        expect(screen.getByTestId('company-filter')).toHaveTextContent('مستندات زهور الريان');
    });

    it('every template can be chosen — none is greyed out for an old approval', async () => {
        const user = userEvent.setup();
        documentsPage({ opening: false, company: null, filters: { state: '', q: '' } });
        await user.click(screen.getByRole('button', { name: 'مستند جديد' }));
        const dialog = screen.getByTestId('new-document');

        expect(within(dialog).getAllByRole('combobox')[1]).not.toHaveTextContent('زهور الريان');
        await user.click(within(dialog).getAllByRole('combobox')[0]);
        const agreement = await screen.findByRole('option', { name: 'اتفاقية الاشتراك واستخدام منصة أبعاد · اشتراك النظام' });
        expect(agreement).not.toHaveAttribute('aria-disabled', 'true');
        expect(document.body.textContent).not.toContain('غير منشور');
        expect(screen.getByRole('option', { name: 'عقد اشتراك أبعاد · اشتراك النظام' })).not.toHaveAttribute('aria-disabled', 'true');
    });

    it('the company file lists its documents and links a new one to it', () => {
        Object.assign(pageProps, { translations: {} });
        render(
            <BusinessDocuments
                documents={{ rows: [row], count: 1, new_url: '/super-admin/documents?business=5&new=1', all_url: '/super-admin/documents?business=5' }}
            />,
        );
        const box = screen.getByTestId('business-documents');

        expect(within(box).getByText('عقد اشتراك')).toBeInTheDocument();
        expect(within(box).getByText('AB-202610-0009')).toBeInTheDocument();
        expect(within(box).getByText('موقّع')).toBeInTheDocument();
        expect(within(box).getByTestId('business-new-document')).toHaveAttribute('href', '/super-admin/documents?business=5&new=1');
        expect(within(box).getByTestId('business-all-documents')).toHaveAttribute('href', '/super-admin/documents?business=5');
        expect(within(box).getByRole('link', { name: 'PDF AB-202610-0009' })).toHaveAttribute('href', '/super-admin/documents/9/pdf');
    });

    it('a company without documents says so', () => {
        Object.assign(pageProps, { translations: {} });
        render(<BusinessDocuments documents={{ rows: [], count: 0, new_url: '/n', all_url: '/a' }} />);

        expect(screen.getByText('لا مستندات لهذه الشركة بعد')).toBeInTheDocument();
        expect(screen.getByTestId('business-new-document')).toHaveAttribute('href', '/n');
    });
});
