import { useRef, useState } from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/**
 * مديرُ المنصّة يحرّر أيَّ اتفاقيةٍ كاملةً ويُصدر منها بتأكيدٍ واحد.
 *
 * ما يُقبل ويُحفظ ويُنشر يحرسه PHP (`AnAgreementIsEditedWholeFromTheScreenTest`،
 * `AnAgreementIsNotSentBeforeItsOwnerApprovesItTest`). وهنا ما تفعله الشاشات:
 *   - المحرّرُ يكتب الأقسامَ والأجزاءَ والجداولَ كما يقرؤها الخادم، والمعاينةُ تعرضها
 *     برسومها، وإزالةُ رمزٍ يُنبَّه إليها.
 *   - النشرُ من شاشة القوالب تأكيدٌ واحدٌ بلا كلمة مرور، على بصمة ما يُعرض.
 *   - نافذةُ الإصدار تعرض القوالب كلَّها قابلةً للاختيار، وغيرُ المنشور يُنشر ويُصدر بتأكيدٍ واحد.
 *   - وبطاقةُ مستندات الشركة بروابطها، وفي صفحة التعديل تُفتح في تبويبٍ جديد.
 */

const sent: { url: string; payload: Record<string, unknown> }[] = [];

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');

    const useForm = (initial: Record<string, unknown>) => {
        const [data, setData] = useState(initial);
        const shape = useRef<(d: Record<string, unknown>) => Record<string, unknown>>((d) => d);
        const send = (url: string) => sent.push({ url, payload: shape.current({ ...data }) });

        return {
            data,
            errors: {} as Record<string, string>,
            processing: false,
            setData: (key: unknown, value?: unknown) =>
                setData((d) =>
                    typeof key === 'function'
                        ? (key as (x: Record<string, unknown>) => Record<string, unknown>)(d)
                        : typeof key === 'string'
                          ? { ...d, [key]: value }
                          : { ...d, ...(key as Record<string, unknown>) },
                ),
            transform: (fn: (d: Record<string, unknown>) => Record<string, unknown>) => {
                shape.current = fn;
            },
            post: send,
            put: send,
            reset: () => setData(initial),
            clearErrors: () => {},
            recentlySuccessful: false,
            isDirty: false,
        };
    };

    return {
        ...actual,
        usePage: () => ({ props: pageProps, url: '/', component: 'Test' }),
        router: { visit: vi.fn(), get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), reload: vi.fn(), on: vi.fn(() => () => {}) },
        Head: () => null,
        useForm,
    };
});

vi.mock('@/Layouts/PlatformLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: DocumentTemplates } = await import('@/Pages/Platform/Documents/Templates');
const { default: DocumentsIndex } = await import('@/Pages/Platform/Documents/Index');
const { BusinessDocuments } = await import('@/Pages/Platform/Businesses/partials/BusinessDocuments');

const FORM = {
    intro: null,
    highlights: [{ label: 'الرسوم', text: '{fee} ر.ع تُدفع مرة واحدة' }],
    consent: 'أقر بأن رسوم الربط {fee_short} ريالًا.',
    agree: 'أوافق على شروط هذا النموذج.',
    footer: null,
    sections: [
        {
            title: 'متطلبات العميل',
            after: false,
            parts: [{ kind: 'field' as const, by: 'client' as const, k: 'wa', label: 'رقم واتساب', req: true, type: 'tel', dir: 'ltr', ph: '+968' }],
        },
        {
            title: 'قوالب الرسائل',
            after: false,
            parts: [
                {
                    kind: 'table' as const, by: 'client' as const, k: 'tpl', head: ['القالب', 'مطلوب؟', 'الملاحظات'],
                    types: ['check', 'text'], ph: ['', ''], rows: ['ترحيب', 'فاتورة'], picks: [],
                },
                { kind: 'note' as const, text: 'تُراجع القوالب عند Meta.' },
            ],
        },
    ],
};

const template = (over: Record<string, unknown> = {}) => ({
    id: 4, name: 'نموذج ربط واتساب', service: 'ربط واتساب', title: 'نموذج ربط واتساب', title_en: null,
    terms: [], form: FORM, default_amount: '25.000', default_amount_label: 'رسوم الربط', amount_rule: 'fixed' as const,
    includes_plan: false, includes_amount: true, documents_count: 0, version: 1, status: 'draft' as const,
    published_at: null, published_by: null, hash: 'f'.repeat(64), problems: [],
    preview_url: '/super-admin/documents/templates/4/preview', preview_pdf_url: '/super-admin/documents/templates/4/preview.pdf',
    versions: [{ version: 1, hash: 'ffffffffffff', saved_at: '2026-10-25 10:00', saved_by: null, published_at: null, published_by: null }],
    ...over,
});

const templatesPage = (tpl = template()) => {
    Object.assign(pageProps, {
        templates: [tpl],
        provider: { name: 'مشاريع الذهلي الجديدة', legal_name: 'مشاريع الذهلي الجديدة', trade_name: 'أبعاد', cr: '', address: '' },
        providerLine: 'مشاريع الذهلي الجديدة — الاسم التجاري للمنصة: أبعاد',
        providerMissing: [],
    });
    return render(<DocumentTemplates />);
};

beforeAll(() => {
    Element.prototype.hasPointerCapture = () => false;
    Element.prototype.setPointerCapture = () => {};
    Element.prototype.releasePointerCapture = () => {};
});

beforeEach(() => {
    sent.length = 0;
    document.body.replaceChildren();
});

describe('the template editor', () => {
    it('edits sections, parts and tables, previews them with their fee, and saves them as the server reads them', async () => {
        const user = userEvent.setup();
        templatesPage();
        await user.click(screen.getByTestId('edit-4'));
        const dialog = screen.getByTestId('template-form');

        // عنوانُ القسم الأوّل، وقسمٌ جديدٌ بحقلٍ وجدول
        const title = within(dialog).getByTestId('section-title-0');
        await user.clear(title);
        await user.type(title, 'بيانات العميل');
        await user.click(within(dialog).getByTestId('add-section'));
        await user.type(within(dialog).getByTestId('section-title-2'), 'بيانات إضافية');
        const added = within(dialog).getByTestId('section-2');
        await user.selectOptions(within(added).getByRole('combobox', { name: 'نوع الجزء' }), 'field');
        await user.click(within(added).getByTestId('add-part-2'));
        await user.type(within(added).getByRole('textbox', { name: 'اسم الحقل' }), 'معرّف حساب Meta');
        await user.click(within(added).getByLabelText('إلزامي — لا يُرسل التوقيع بدونه'));

        // وعنوانُ عمودٍ في جدول القسم الثاني، وحذفُ ملاحظته
        await user.click(within(within(dialog).getByTestId('section-1')).getByRole('button', { name: 'فتح القسم' }));
        const tableBox = within(dialog).getByTestId('table-editor');
        const head = within(tableBox).getByRole('textbox', { name: 'عنوان العمود 2' });
        await user.clear(head);
        await user.type(head, 'ملاحظات العميل');
        await user.click(within(within(dialog).getByTestId('part-1-1')).getByRole('button', { name: 'حذف الجزء' }));

        // والمعاينةُ بالرسوم المملوءة والأقسام كما حُرِّرت
        await user.click(within(dialog).getByTestId('tab-preview'));
        const preview = within(dialog).getByTestId('live-preview');
        expect(within(preview).getByText('بيانات العميل')).toBeInTheDocument();
        expect(within(preview).getByText('بيانات إضافية')).toBeInTheDocument();
        expect(within(preview).getByText('25.000 ر.ع تُدفع مرة واحدة')).toBeInTheDocument();
        expect(preview.textContent).not.toContain('{fee}');

        await user.click(within(dialog).getByTestId('save-template'));
        expect(sent).toHaveLength(1);
        expect(sent[0].url).toContain('super-admin.documents.templates.update');
        const saved = sent[0].payload.form as typeof FORM;
        expect(sent[0].payload.terms).toEqual([]);
        expect(saved.sections.map((s) => s.title)).toEqual(['بيانات العميل', 'قوالب الرسائل', 'بيانات إضافية']);
        expect(saved.sections[2].parts[1]).toMatchObject({ kind: 'field', by: 'client', k: 'field1', label: 'معرّف حساب Meta', req: true });
        expect(saved.sections[1].parts).toHaveLength(1);
        expect(saved.sections[1].parts[0]).toMatchObject({ kind: 'table', head: ['القالب', 'مطلوب؟', 'ملاحظات العميل'], types: ['check', 'text'] });
        expect(saved.highlights[0].text).toBe('{fee} ر.ع تُدفع مرة واحدة');
        expect(sent[0].payload.amount_rule).toBe('fixed');
    });

    it('warns when a token the text named is removed', async () => {
        const user = userEvent.setup();
        templatesPage();
        await user.click(screen.getByTestId('edit-4'));
        const dialog = screen.getByTestId('template-form');
        const text = within(dialog).getByRole('textbox', { name: 'نص السطر البارز' });
        await user.clear(text);
        await user.type(text, 'خمسة وعشرون ريالًا');

        expect(within(dialog).getByTestId('removed-tokens')).toHaveTextContent('{fee}');
    });

    it('publishes with one confirmation and no password, on the fingerprint it shows', async () => {
        const user = userEvent.setup();
        templatesPage();
        expect(screen.getByTestId('status-4')).toHaveTextContent('غير منشور · الإصدار 1');

        await user.click(screen.getByTestId('publish-4'));
        const dialog = screen.getByTestId('publish-form');
        expect(within(dialog).getByTestId('publish-fee')).toHaveTextContent('25.000');
        expect(dialog.querySelector('input[type="password"]')).toBeNull();
        await user.click(within(dialog).getByTestId('publish-submit'));

        expect(sent).toEqual([{ url: expect.stringContaining('super-admin.documents.templates.publish'), payload: { hash: 'f'.repeat(64) } }]);
    });

    it('a template the server finds incomplete says why and cannot be published', async () => {
        const user = userEvent.setup();
        templatesPage(template({ problems: ['اكتب رسوم القالب قبل نشره — نصّه يذكرها.'] }));
        await user.click(screen.getByTestId('publish-4'));

        expect(screen.getByTestId('publish-problems')).toHaveTextContent('اكتب رسوم القالب قبل نشره');
        expect(screen.getByTestId('publish-submit')).toBeDisabled();
    });

    it('a published template is paused, not published again', () => {
        templatesPage(template({ status: 'published', published_at: '2026-10-25 10:00', published_by: 'مدير المنصة' }));

        expect(screen.getByTestId('status-4')).toHaveTextContent('منشور · الإصدار 1');
        expect(screen.getByTestId('pause-4')).toBeInTheDocument();
        expect(screen.queryByTestId('publish-4')).toBeNull();
        expect(screen.getByText('نشره مدير المنصة في 2026-10-25 10:00')).toBeInTheDocument();
    });
});

describe('issuing from any template', () => {
    const issuePage = () => {
        Object.assign(pageProps, {
            documents: [], pagination: { current_page: 1, last_page: 1, from: 0, to: 0, total: 0, per_page: 20, prev_page_url: null, next_page_url: null },
            filters: { state: '', q: '', business: '5' }, counts: { sent: 0, signed: 0 },
            templates: [
                { id: 1, published: true, version: 1, hash: 'a'.repeat(64), name: 'عقد اشتراك أبعاد', service: 'اشتراك النظام', title: 'عقد اشتراك', terms: [{ h: '', b: 'بند' }], form: null, includes_plan: false, includes_amount: false, default_amount: null, default_amount_label: null, amount_rule: null },
                { id: 4, published: false, version: 2, hash: 'b'.repeat(64), name: 'نموذج ربط واتساب', service: 'ربط واتساب', title: 'نموذج ربط واتساب', terms: [], form: { ...FORM, highlights: [{ label: 'الرسوم', text: '25.000 ر.ع' }] }, includes_plan: false, includes_amount: true, default_amount: '25.000', default_amount_label: 'رسوم الربط', amount_rule: 'fixed' },
            ],
            company: { id: 5, name: 'زهور الريان', url: '/super-admin/businesses/5' },
            opening: true,
            plans: [], cycles: [{ value: 'monthly', label: 'شهري', months: 1, duration: 'شهر' }],
            businesses: [{ id: 5, name: 'زهور الريان', plan_id: null, owner: 'سالم', phone: '+96891234567', starts_on: '2026-10-25' }],
            today: '2026-10-25', days: 14, renew: null,
            auth: { abilities: [], mayActions: [], isEmployee: false, user: { name: 'سالم', avatar: null, roleLabel: 'مدير المنصة', role: 'super_admin', businessId: null } },
        });
        return render(<DocumentsIndex />);
    };

    it('lists every template as choosable and issues an unpublished one after a single confirmation', async () => {
        const user = userEvent.setup();
        issuePage();
        const dialog = screen.getByTestId('new-document');

        await user.click(within(dialog).getAllByRole('combobox')[0]);
        const options = await screen.findAllByRole('option');
        expect(options.map((o) => o.textContent)).toEqual(['عقد اشتراك أبعاد · اشتراك النظام', 'نموذج ربط واتساب · ربط واتساب — غير منشور']);
        options.forEach((o) => expect(o).not.toHaveAttribute('aria-disabled', 'true'));
        await user.click(options[1]);

        expect(within(dialog).getByTestId('unpublished-note')).toHaveTextContent('الإصدار 2 من هذا القالب غير منشور');
        await user.click(within(dialog).getByTestId('issue-submit'));
        expect(sent).toHaveLength(0);
        expect(within(dialog).getByTestId('publish-confirm')).toHaveTextContent('تأكيد نشر الإصدار 2 وإصدار المستند');

        await user.click(within(dialog).getByRole('button', { name: 'تأكيد النشر والإصدار' }));
        expect(sent).toHaveLength(1);
        expect(sent[0].payload).toMatchObject({ template_id: '4', business_id: '5', publish: true, template_hash: 'b'.repeat(64) });
    });

    it('a published template issues straight away, with no confirmation step', async () => {
        const user = userEvent.setup();
        issuePage();
        const dialog = screen.getByTestId('new-document');

        expect(within(dialog).queryByTestId('unpublished-note')).toBeNull();
        await user.click(within(dialog).getByTestId('issue-submit'));
        expect(sent).toHaveLength(1);
        expect(sent[0].payload).not.toHaveProperty('publish');
    });
});

describe('the company documents card', () => {
    const rows = [{
        id: 9, url: '/super-admin/documents/9', pdf_url: '/super-admin/documents/9/pdf', number: 'AB-202610-0009',
        title: 'نموذج ربط واتساب', service: 'ربط واتساب', client_name: 'سالم', client_biz: 'زهور الريان',
        business: { id: 5, name: 'زهور الريان' }, plan_name: null, cycle_label: null, value: '25.000', value_unit: null, renewal: false,
        shared: 'فُتح واتساب', shared_at: '2026-10-25 10:05', state: 'sent' as const, state_label: 'بانتظار التوقيع',
        created_at: '2026-10-25 10:00', expires_at: '2026-11-08 23:59', signed_at: '—',
    }];
    const docs = { rows, count: 1, new_url: '/super-admin/documents?business=5&new=1', all_url: '/super-admin/documents?business=5' };

    it('names, numbers and dates each document with its state, details and paper', () => {
        Object.assign(pageProps, { translations: {} });
        render(<BusinessDocuments documents={docs} />);
        const row = screen.getByTestId('business-document-9');

        expect(screen.getByText('مستندات الشركة')).toBeInTheDocument();
        expect(within(row).getByText('نموذج ربط واتساب')).toBeInTheDocument();
        expect(within(row).getByText('ربط واتساب')).toBeInTheDocument();
        expect(within(row).getByText('AB-202610-0009')).toBeInTheDocument();
        expect(within(row).getByText('2026-10-25 10:00')).toBeInTheDocument();
        expect(within(row).getByText('بانتظار التوقيع')).toBeInTheDocument();
        expect(within(row).getByText('فُتح واتساب')).toBeInTheDocument();
        expect(within(row).getByRole('link', { name: 'تفاصيل AB-202610-0009' })).toHaveAttribute('href', '/super-admin/documents/9');
        expect(within(row).getByRole('link', { name: 'PDF AB-202610-0009' })).toHaveAttribute('href', '/super-admin/documents/9/pdf');
        expect(screen.getByTestId('business-new-document')).toHaveAttribute('href', docs.new_url);
        expect(screen.getByTestId('business-all-documents')).toHaveAttribute('href', docs.all_url);
    });

    it('on the edit page every link opens a new tab, so unsaved changes stay', () => {
        Object.assign(pageProps, { translations: {} });
        render(<BusinessDocuments documents={docs} newTab />);

        for (const link of screen.getAllByRole('link')) {
            expect(link).toHaveAttribute('target', '_blank');
        }
        expect(screen.getByText(/تُفتح في تبويب جديد/)).toBeInTheDocument();
    });
});
