import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';

import DocumentsIndex from '@/Pages/Platform/Documents/Index';
import DocumentShow from '@/Pages/Platform/Documents/Show';
import DocumentTemplates from '@/Pages/Platform/Documents/Templates';
import { formInputs, type DocumentForm } from '@/lib/platformDocuments';
import { pageProps } from './setup';

/**
 * اتفاقياتُ المالك بنماذجها في لوحة المنصّة — ما يراه المديرُ قبل الإرسال وبعد التوقيع.
 *
 * ما يُقبل في كلّ حقلٍ ومن صاحبه، والبصمةُ والورقة، يحرسها PHP
 * (`AbaadsAgreementsAreSignedAsTheOwnerDesignedThemTest`). وهنا:
 *   - نافذةُ الإصدار تعرض النموذجَ وتقترح مبلغَه وتفتح حقولَ الفريق وحدها.
 *   - صفحةُ المستند تعرض ما كتبه الفريقُ وما أجاب به العميل، والفارغَ سطرًا.
 *   - شاشةُ القوالب: غيرُ المنشور موسومٌ بإصداره، والنشرُ يُعرض بنصّه ورسومه ومقدّم
 *     خدمته بتأكيدٍ واحد. وسجلٌّ أو عنوانٌ لم يُكتبا يُنبَّه إليهما ولا يمنعان.
 *     (المحرّرُ ونافذةُ الإصدار في `an-owner-edits-and-publishes-any-agreement`.)
 *   - والرسومُ الثابتة في نافذة الإصدار تُقرأ ولا تُكتب.
 */

const FORM: DocumentForm = {
    intro: null,
    highlights: [{ label: 'الرسوم', text: '25.000 ر.ع تُدفع مرة واحدة' }],
    consent: 'أقر بأنني اطلعت على تفاصيل خدمة ربط واتساب.',
    agree: 'أوافق على شروط هذا النموذج.',
    footer: null,
    sections: [
        {
            title: 'متطلبات العميل',
            after: false,
            parts: [
                { kind: 'field', by: 'client', k: 'wa', label: 'رقم واتساب المطلوب ربطه', req: true, type: 'tel', dir: 'ltr', ph: '+968' },
                { kind: 'checks', by: 'client', k: 'c0', head: 'بيانات حساب Meta', items: ['لدي حساب Meta Business Portfolio', 'اسم النشاط مسجل في Meta'] },
            ],
        },
        {
            title: 'نطاق خدمة الربط',
            after: false,
            parts: [
                { kind: 'list', items: ['ربط رقم واتساب بنظام أبعاد.'] },
                { kind: 'field', by: 'team', k: 'itype', label: 'نوع التكامل المستخدم', req: false, type: 'text', dir: 'rtl', ph: '' },
                { kind: 'field', by: 'team', k: 'eta', label: 'موعد التنفيذ المتوقع', req: false, type: 'date', dir: 'ltr', ph: '' },
            ],
        },
        {
            title: 'حالة التسليم وإقرار الاستلام',
            after: true,
            parts: [{ kind: 'field', by: 'team', k: 'rcvName', label: 'اسم المستلم', req: false, type: 'text', dir: 'rtl', ph: '' }],
        },
    ],
};

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
    title: 'نموذج طلب واتفاقية خدمة ربط واتساب', service: 'ربط واتساب', client_name: 'سالم الهنائي', client_biz: 'زهور الريان',
    business: null, plan_name: null, cycle_label: null, value: '25.000', value_unit: null, renewal: false,
    shared: null, shared_at: null, state: 'signed' as const, state_label: 'موقّع',
    created_at: '2026-10-25 10:00', expires_at: '2026-11-08 23:59', signed_at: '2026-10-25 11:00',
};

describe('the owner’s agreements, as forms', () => {
    beforeEach(() => document.body.replaceChildren());

    it('the team fills its own fields only — the client’s and the delivery’s stay out of the dialog', () => {
        expect(formInputs(FORM, 'team').map((x) => x.part.k)).toEqual(['itype', 'eta']);
        expect(formInputs(FORM, 'client').map((x) => x.part.k)).toEqual(['wa', 'c0']);
    });

    it('issuing an agreement previews its form, suggests its fee and opens the team’s fields', async () => {
        layout('super-admin.documents.index');
        Object.assign(pageProps, {
            documents: [], pagination: { current_page: 1, last_page: 1, from: 0, to: 0, total: 0, per_page: 20, prev_page_url: null, next_page_url: null },
            filters: { state: '', q: '' }, counts: { sent: 0, signed: 0 },
            templates: [{
                id: 4, published: true, version: 1, hash: 'f'.repeat(64), name: 'نموذج طلب واتفاقية خدمة ربط واتساب', service: 'ربط واتساب', title: 'نموذج طلب واتفاقية خدمة ربط واتساب',
                terms: [], form: FORM, includes_plan: false, includes_amount: true, default_amount: '25.000', default_amount_label: 'رسوم الربط (مرة واحدة)',
                amount_rule: 'fixed',
            }],
            plans: [], cycles: [], businesses: [{ id: 5, name: 'زهور الريان', plan_id: null, owner: 'سالم', phone: '+96891234567', starts_on: '2026-10-25' }],
            today: '2026-10-25', days: 14, renew: null,
        });
        render(<DocumentsIndex />);
        await userEvent.click(screen.getByRole('button', { name: 'مستند جديد' }));
        const dialog = screen.getByTestId('new-document');

        expect(within(dialog).getByDisplayValue('25.000')).toBeInTheDocument();
        // رسومٌ ثابتة كما اعتُمدت: تُقرأ ولا تُكتب هنا
        expect(within(dialog).getByTestId('amount')).toHaveAttribute('readonly');
        expect(within(dialog).getByDisplayValue('رسوم الربط (مرة واحدة)')).toBeInTheDocument();

        await userEvent.click(within(dialog).getByRole('button', { name: /معاينة النموذج/ }));
        const preview = screen.getByTestId('terms-preview');
        expect(within(preview).getByText('متطلبات العميل')).toBeInTheDocument();
        expect(within(preview).getByText('يُعبّأ هذا القسم بعد تنفيذ الخدمة')).toBeInTheDocument();

        await userEvent.click(within(dialog).getByRole('button', { name: /حقول فريق أبعاد \(2\)/ }));
        const team = screen.getByTestId('team-inputs');
        await userEvent.type(within(team).getByTestId('team-itype'), 'Cloud API');
        expect(within(team).getByTestId('team-itype')).toHaveValue('Cloud API');
        expect(within(team).getByTestId('team-eta')).toHaveAttribute('type', 'date');
        expect(within(team).queryByTestId('team-wa')).toBeNull();
        expect(within(team).queryByTestId('team-rcvName')).toBeNull();
    });

    it('the signed agreement shows what the team wrote, what the client answered, and blanks as lines', () => {
        layout('super-admin.documents.show');
        Object.assign(pageProps, {
            document: {
                ...row,
                title_en: null, terms: [], form: FORM,
                team: { itype: 'Cloud API' },
                answers: { wa: '+96891234567', c0: [1] },
                plan: null, term_starts_on: '—', term_ends_on: '—', amount: '25.000', amount_label: 'رسوم الربط (مرة واحدة)',
                client: { name: 'سالم الهنائي', biz: 'زهور الريان' }, client_phone: '+96891234567',
                notes: null, created_by: 'مدير المنصة', viewed_at: '—', cancelled_at: '—', cancel_reason: null,
                signer_ip: '127.0.0.1', signer_agent: 'x', consent_text: 'أقر', agreed_at: '2026-10-25 11:00',
                content_hash: 'a'.repeat(64), pdf_hash: 'b'.repeat(64), intact: true, signature: null,
                link: 'https://app.abaadapp.om/sign/abcdefghijklmnopqrstuvwxyz123456', whatsapp: 'https://wa.me/96891234567?text=x',
                renews: null, renewals: [], can_renew: false, subscription: null,
            },
            fields: [{ k: 'name', ar: 'اسم العميل', req: true }],
        });
        render(<DocumentShow />);

        const form = screen.getByTestId('document-form');
        expect(screen.getByText('النموذج كما وقّعه العميل')).toBeInTheDocument();
        expect(within(form).getByTestId('value-wa')).toHaveTextContent('+96891234567');
        expect(within(form).getByTestId('value-itype')).toHaveTextContent('Cloud API');
        expect(within(form).getByTestId('value-eta')).toHaveTextContent('______');
        expect(within(form).getByText('اسم النشاط مسجل في Meta').previousSibling).toHaveTextContent('☑');
        expect(within(form).getByText('لدي حساب Meta Business Portfolio').previousSibling).toHaveTextContent('☐');
        expect(screen.queryByText('الشروط والأحكام')).toBeNull();
    });

    const template = {
        id: 4, name: 'نموذج طلب واتفاقية خدمة ربط واتساب', service: 'ربط واتساب', title: 'نموذج طلب واتفاقية خدمة ربط واتساب', title_en: null,
        terms: [], form: FORM, default_amount: '25.000', default_amount_label: 'رسوم الربط (مرة واحدة)', amount_rule: 'fixed' as const,
        includes_plan: false, includes_amount: true, documents_count: 0, version: 1, status: 'draft' as const,
        published_at: null, published_by: null, hash: 'f'.repeat(64), problems: [],
        preview_url: '/super-admin/documents/templates/4/preview', preview_pdf_url: '/super-admin/documents/templates/4/preview.pdf', versions: [],
    };
    const provider = { name: 'مشاريع الذهلي الجديدة', legal_name: 'مشاريع الذهلي الجديدة', trade_name: 'أبعاد', cr: '', address: '' };

    it('an unpublished agreement is marked with its version; a blank CR or address only notes, never blocks publishing', async () => {
        layout('super-admin.documents.templates');
        Object.assign(pageProps, { templates: [template], provider, providerLine: 'مشاريع الذهلي الجديدة — الاسم التجاري للمنصة: أبعاد', providerMissing: ['رقم السجل التجاري', 'العنوان القانوني'] });
        render(<DocumentTemplates />);

        expect(screen.getByTestId('status-4')).toHaveTextContent('غير منشور · الإصدار 1');
        expect(screen.getByTestId('provider-missing')).toHaveTextContent('رقم السجل التجاري، العنوان القانوني');
        expect(screen.getByTestId('provider-missing')).toHaveTextContent('ولا تمنع النشر ولا الإصدار');
        expect(screen.getByTestId('preview-4')).toHaveAttribute('href', template.preview_url);

        await userEvent.click(screen.getByTestId('publish-4'));
        const publish = screen.getByTestId('publish-form');
        expect(within(publish).getByTestId('publish-fee')).toHaveTextContent('25.000');
        // الرسومُ مملوءةٌ في المعاينة كما ستُكتب في المستند
        expect(within(publish).getByText('25.000 ر.ع تُدفع مرة واحدة')).toBeInTheDocument();
        expect(within(publish).getByTestId('publish-submit')).toBeEnabled();
    });
});
