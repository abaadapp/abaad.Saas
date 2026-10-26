import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import DocumentsIndex from '@/Pages/Platform/Documents/Index';
import DocumentShow from '@/Pages/Platform/Documents/Show';
import { addMonths, POLL_MS } from '@/lib/platformDocuments';
import { pageProps } from './setup';

/**
 * قسم المستندات في لوحة المنصّة — ما يراه المديرُ قبل الإرسال وبعد التوقيع.
 *
 * الإصدارُ والتوقيعُ والبصمةُ يحرسها PHP (`AClientSignsTheDocumentAbaadSentHimTest`
 * و`AnAbaadContractGoesOutAndComesBackSignedTest`). وهنا ما لا يراه إلّا متصفّح:
 *   - نافذةُ الإصدار تسأل عن الباقة ومدّتها حين يتضمّنها القالب، وتعرض بنوده.
 *   - المستندُ المُرسَل يعرض رابطَه ونسخَه وواتسابَه، ويسجّل ما فعله المدير.
 *   - القائمةُ والصفحةُ تسألان الخادمَ ما دام شيءٌ ينتظر التوقيع، وتسكتان بعده.
 *   - الموقَّعُ يعرض توقيعَه وإقرارَه وبصمتَه، والاشتراكُ لا يُمدَّد بالتوقيع.
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
    id: 3, url: '/super-admin/documents/3', pdf_url: '/super-admin/documents/3/pdf', number: 'AB-202610-0003',
    title: 'عقد اشتراك', service: 'اشتراك النظام',
    client_name: 'سالم الهنائي', client_biz: 'زهور الريان', business: null,
    plan_name: 'الباقة الاحترافية', cycle_label: 'سنوي', value: '299.000', value_unit: 'سنويًا', renewal: false,
    shared: null, shared_at: null, state: 'sent' as const,
    state_label: 'بانتظار التوقيع', issued_on: '2026/10/24', created_at: '2026-10-24 10:00', expires_at: '2026-11-07 23:59', signed_at: '—',
    ...over,
});

const CONTRACT = {
    id: 1, name: 'عقد اشتراك أبعاد', service: 'اشتراك النظام', title: 'عقد اشتراك',
    terms: [{ h: 'مدة الاشتراك:', b: 'شهرًا واحدًا للدورة الشهرية، أو ستة أشهر، أو اثني عشر شهرًا.' }],
    includes_plan: true, includes_amount: false,
    problems: [], version: 1, hash: 'a'.repeat(64), preview_url: '/p/1', preview_pdf_url: '/p/1.pdf',
};

function index(over: Record<string, unknown> = {}) {
    layout('super-admin.documents.index');
    Object.assign(pageProps, {
        documents: [row()],
        pagination: { current_page: 1, last_page: 1, from: 1, to: 1, total: 1, per_page: 20, prev_page_url: null, next_page_url: null },
        filters: { state: '', q: '' },
        counts: { sent: 1, signed: 0 },
        templates: [CONTRACT],
        plans: [{ id: 2, name: 'الباقة الاحترافية', prices: { monthly: '29.900', semiannual: '179.400', yearly: '299.000' } }],
        cycles: [
            { value: 'monthly', label: 'شهري', months: 1, duration: 'شهر واحد' },
            { value: 'semiannual', label: 'نصف سنوي', months: 6, duration: '6 أشهر' },
            { value: 'yearly', label: 'سنوي', months: 12, duration: '12 شهرًا' },
        ],
        businesses: [{ id: 5, name: 'زهور الريان', plan_id: 2, owner: 'سالم', phone: '+96891234567', starts_on: '2026-12-01' }],
        today: '2026-10-24',
        days: 14,
        renew: null,
        ...over,
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
            plan: { name: 'الباقة الاحترافية', cycle_label: 'سنوي', unit: 'سنويًا', duration: '12 شهرًا', price: '299.000', limits: 'حتى 3 فروع' },
            term_starts_on: '2026-12-01', term_ends_on: '2027-12-01',
            amount: null, amount_label: null,
            client: { name: 'سالم الهنائي', biz: 'زهور الريان' },
            client_phone: '+96891234567',
            notes: null, created_by: 'مدير المنصة', viewed_at: '—', cancelled_at: '—', cancel_reason: null,
            signer_ip: null, signer_agent: null, consent_text: null, agreed_at: null,
            content_hash: null, pdf_hash: null, intact: null, signature: null,
            link: 'https://app.abaadapp.om/sign/abcdefghijklmnopqrstuvwxyz123456',
            whatsapp: 'https://wa.me/96891234567?text=x',
            renews: null, renewals: [], can_renew: false, subscription: null,
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
    beforeEach(() => {
        document.body.replaceChildren();
        vi.mocked(router.reload).mockClear();
        vi.mocked(router.post).mockClear();
    });
    afterEach(() => vi.useRealTimers());

    it('lists the document with its client, plan, value, state and a PDF link — and the sidebar names the section', () => {
        index();

        expect(screen.getByText('زهور الريان')).toBeInTheDocument();
        expect(screen.getAllByText('بانتظار التوقيع').length).toBeGreaterThan(0);
        expect(screen.getByText('عقد اشتراك').closest('a')).toHaveAttribute('href', '/super-admin/documents/3');
        expect(screen.getByText('299.000')).toBeInTheDocument();
        expect(screen.getByLabelText('تحميل PDF')).toHaveAttribute('href', '/super-admin/documents/3/pdf');
        expect(screen.getAllByText('عقود التجديد').length).toBeGreaterThan(0);
        expect([...document.querySelectorAll('a')].some((a) => a.textContent?.startsWith('المستندات'))).toBe(true);
    });

    it('asks the server again while something awaits a signature, and stays quiet when nothing does', () => {
        vi.useFakeTimers();
        const waiting = index();
        act(() => vi.advanceTimersByTime(POLL_MS + 10));
        expect(router.reload).toHaveBeenCalledWith({ only: ['documents', 'counts', 'pagination'] });

        waiting.unmount();
        vi.mocked(router.reload).mockClear();
        index({ counts: { sent: 0, signed: 3 } });
        act(() => vi.advanceTimersByTime(POLL_MS * 3));
        expect(router.reload).not.toHaveBeenCalled();
    });

    it('asks for the plan and its term, previews the terms, and ends the term by the cycle', async () => {
        index();
        await userEvent.click(screen.getByRole('button', { name: 'مستند جديد' }));
        expect(screen.getByText('دورة الاشتراك')).toBeInTheDocument();

        const start = document.querySelector('[data-testid="term-start"]') as HTMLInputElement;
        const end = document.querySelector('[data-testid="term-end"]') as HTMLInputElement;
        expect(start.value).toBe('2026-10-24');
        expect(end.value).toBe('2026-11-24');

        await userEvent.click(screen.getByRole('button', { name: /معاينة البنود/ }));
        expect(document.querySelector('[data-testid="terms-preview"]')?.textContent).toContain('ستة أشهر');

        // النشاطُ المسجّل: هاتفُه، وبدايةُ المدّة من نهاية اشتراكه الجاري
        const select = [...document.querySelectorAll('select')].find((s) => [...s.options].some((o) => o.value === '5'))!;
        await userEvent.selectOptions(select, '5');
        expect((document.querySelector('[data-testid="client-phone"]') as HTMLInputElement).value).toBe('+96891234567');
        expect(start.value).toBe('2026-12-01');
        expect(end.value).toBe('2027-01-01');
    });

    it('ends a month from the 31st on the last day of the next month, as the server does', () => {
        expect(addMonths('2026-01-31', 1)).toBe('2026-02-28');
        expect(addMonths('2026-08-31', 6)).toBe('2027-02-28');
        expect(addMonths('2026-10-24', 12)).toBe('2027-10-24');
    });

    it('opens a renewal of a signed contract with its number and without the client tabs', () => {
        index({
            renew: { id: 9, number: 'AB-202610-0009', client: 'سالم — زهور الريان', phone: '+96891234567', template_id: 1, plan_id: 2, cycle: 'yearly', starts_on: '2027-10-24' },
        });

        expect(document.querySelector('[data-testid="renewal-of"]')?.textContent).toContain('AB-202610-0009');
        expect(screen.queryByRole('tab', { name: 'عميل جديد' })).toBeNull();
        expect((document.querySelector('[data-testid="term-start"]') as HTMLInputElement).value).toBe('2027-10-24');
        expect((document.querySelector('[data-testid="term-end"]') as HTMLInputElement).value).toBe('2028-10-24');
    });

    it('gives a sent document its copy and WhatsApp buttons, and records what the admin did — not that it arrived', async () => {
        show();

        const box = document.querySelector('[data-testid="document-link"]') as HTMLElement;
        expect(box.textContent).toContain('https://app.abaadapp.om/sign/abcdefghijklmnopqrstuvwxyz123456');
        expect(screen.getByRole('button', { name: /نسخ رابط التوقيع/ })).toBeInTheDocument();
        const wa = screen.getByText('إرسال عبر واتساب').closest('a') as HTMLAnchorElement;
        expect(wa).toHaveAttribute('href', 'https://wa.me/96891234567?text=x');
        expect(document.querySelector('[data-testid="whatsapp-note"]')?.textContent).toContain('لا يعني أن الرسالة أُرسلت أو وصلت');

        wa.addEventListener('click', (e) => e.preventDefault());
        await userEvent.click(wa);
        expect(router.post).toHaveBeenCalledWith('/super-admin.documents.shared/3', { via: 'whatsapp' }, expect.objectContaining({ only: ['document'] }));
        expect(screen.getByText('يكمله العميل')).toBeInTheDocument();
        expect(document.querySelector('[data-testid="plan-term"]')?.textContent).toContain('2027-12-01');
    });

    it('keeps asking about a sent document, and stops once it is signed', () => {
        vi.useFakeTimers();
        const sent = show();
        act(() => vi.advanceTimersByTime(POLL_MS + 10));
        expect(router.reload).toHaveBeenCalledWith({ only: ['document'] });

        sent.unmount();
        vi.mocked(router.reload).mockClear();
        show({ state: 'signed', state_label: 'موقّع', signed_at: '2026-10-25 12:00' });
        act(() => vi.advanceTimersByTime(POLL_MS * 3));
        expect(router.reload).not.toHaveBeenCalled();
    });

    it('shows a signed document its signer, signature, consent, fingerprint and archived paper — and no link to send again', () => {
        show({
            state: 'signed', state_label: 'موقّع', signed_at: '2026-10-25 12:00',
            signature: 'data:image/png;base64,AAAA', content_hash: 'f'.repeat(64), pdf_hash: 'e'.repeat(64), intact: true,
            signer_ip: '203.0.113.9', consent_text: 'أقر بأن البيانات أعلاه صحيحة…', agreed_at: '2026-10-25 12:00',
            can_renew: true,
        });

        expect(document.querySelector('[data-testid="document-link"]')).toBeNull();
        expect(document.querySelector('[data-testid="document-signed"]')?.textContent).toContain('سالم الهنائي');
        expect(screen.getByAltText('التوقيع')).toHaveAttribute('src', 'data:image/png;base64,AAAA');
        expect(document.querySelector('[data-testid="consent-record"]')?.textContent).toContain('أقر بأن البيانات');
        expect(document.querySelector('[data-testid="paper-archived"]')?.textContent).toContain('e'.repeat(64));
        expect(document.body.textContent).toContain('f'.repeat(64));
        expect(screen.queryByRole('button', { name: /إلغاء الرابط/ })).toBeNull();
        expect(screen.getByText('عقد تجديد').closest('a')).toHaveAttribute('href', '/super-admin.documents.index/3');
        expect(screen.queryByText('تغيّر محتوى المستند بعد التوقيع — البصمة لا تطابق')).toBeNull();
    });

    it('shows the document number and issue date as the paper prints them — read-only, never a field to type in', () => {
        show({ state: 'signed', state_label: 'موقّع', signed_at: '2026-10-25 12:00' });

        const identity = document.querySelector('[data-testid="document-identity"]') as HTMLElement;
        expect(within(identity).getByTestId('document-number')).toHaveTextContent('AB-202610-0003');
        expect(within(identity).getByTestId('document-issued-on')).toHaveTextContent('2026/10/24');
        expect(identity.textContent).toContain('تاريخ الإصدار');
        expect(identity.querySelector('input, textarea, select')).toBeNull();
    });

    it('lists each document with its number and issue date', () => {
        index();
        expect(screen.getByText('AB-202610-0003 · 2026/10/24')).toBeInTheDocument();
    });

    it('says the subscription is extended by paying its renewal invoice, not by signing', () => {
        show({
            state: 'signed', state_label: 'موقّع', business: { id: 5, name: 'زهور الريان' },
            subscription: { plan: 'الباقة الاحترافية', starts_at: '2026-01-01', ends_at: '2026-12-01', status: 'نشط', pending_invoice: 'INV-P-0007', pending_amount: '299.000', pending_payment: 'غير مدفوع' },
        });

        const card = document.querySelector('[data-testid="subscription"]') as HTMLElement;
        expect(card.textContent).toContain('INV-P-0007');
        expect(card.textContent).toContain('توقيع العقد لا يمدد الاشتراك');
    });

    it('warns when the signed content no longer matches its fingerprint', () => {
        show({ state: 'signed', state_label: 'موقّع', content_hash: 'a'.repeat(64), intact: false });

        expect(screen.getByText('تغيّر محتوى المستند بعد التوقيع — البصمة لا تطابق')).toBeInTheDocument();
    });
});
