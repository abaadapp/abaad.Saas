import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import DocumentMeta from '@/Components/DocumentMeta';
import DocumentPanel, { DocumentAside } from '@/Components/DocumentPanel';

/**
 * لوحةُ الورقة وشريطُ تعريفها — ما تعرضه شاشاتُ المستندات كلُّها.
 *
 * ═══ ولمَ تُختبر في متصفّح ═══
 *
 * اختباراتُ PHP تصل إلى ما يُرسله الخادم وتقف عنده: أنّ `paper.html` في
 * الحمولة. وما بعده — أنّ الورقة تُرسَم في إطار، وأنّ «تحميل» يحفظ و«طباعة»
 * تفتح، وأنّ حقلًا بلا قيمة لا يترك خانةً خاوية — لا يُشغّله إلّا متصفّح.
 *
 * والأفعالُ الثلاثة أصلُ هذه اللوحة: كان في الشاشة زرّان — «تصدير PDF»
 * و«تحميل» — على الرابط نفسِه تمامًا، أحدُهما يفتح لسانًا والآخر يحفظ. فيقف
 * من يريد الطباعة بينهما لا يعرف أيَّهما يوصله.
 */

const PAPER = '<html><body><h1>PO-1042</h1></body></html>';

const panel = (extra: Record<string, unknown> = {}) =>
    render(
        <DocumentPanel
            html={PAPER}
            size="A4"
            url="/admin/purchases/7/pdf"
            filename="PO-1042.pdf"
            label="أمر شراء PO-1042"
            {...extra}
        />,
    );

describe('DocumentPanel', () => {
    it('يرسم الورقة في إطارٍ باسم المستند', () => {
        panel();

        const frame = screen.getByTitle('أمر شراء PO-1042');

        expect(frame.tagName).toBe('IFRAME');
        expect(frame).toHaveAttribute('srcdoc', PAPER);
    });

    /*
     * والإطارُ لا يُنفّذ ما فيه.
     *
     * الورقةُ تحمل اسمَ عميلٍ ورقمَ صنفٍ كتبهما بشر، وتُرسم في لوحة التاجر.
     * و`allow-scripts` فيها يجعل سطرًا في حقلِ ملاحظاتٍ يعمل في جلسته.
     */
    it('لا يمنح الورقةَ إذنَ تشغيل سكربت', () => {
        panel();

        const sandbox = screen.getByTitle('أمر شراء PO-1042').getAttribute('sandbox');

        expect(sandbox).toBe('allow-same-origin');
    });

    it('يفرّق الأفعال الثلاثة: تكبيرٌ يُري، وتحميلٌ يحفظ، وطباعةٌ تفتح', () => {
        panel();

        const download = screen.getByRole('link', { name: /تحميل/ });
        expect(download).toHaveAttribute('href', '/admin/purchases/7/pdf');
        expect(download).toHaveAttribute('download', 'PO-1042.pdf');

        const print = screen.getByRole('link', { name: /طباعة/ });
        expect(print).toHaveAttribute('href', '/admin/purchases/7/pdf');
        expect(print).toHaveAttribute('target', '_blank');
        // ولا `download` على الطباعة: الحفظُ فعلٌ آخر
        expect(print).not.toHaveAttribute('download');

        expect(screen.getByRole('button', { name: /تكبير/ })).toBeInTheDocument();
    });

    /*
     * والتكبيرُ يُري الورقةَ نفسَها لا قارئَ المتصفّح.
     *
     * كانت النافذةُ تضع رابطَ الـPDF في إطار: يرسم الخادمُ ملفًّا عند كلّ
     * ضغطة، ويفتحه المتصفّح بقارئه هو — شريطُ أدواتٍ رماديّ وأزرارُ تكبيرٍ
     * وطباعةٍ فوق أزرارِ أبعاد نفسِها. والـPDF يبقى لما يُطبع ويُحفظ.
     */
    it('يُري الورقة في النافذة، ولا يفتح ملفَّ PDF في إطار', async () => {
        panel();

        await userEvent.click(screen.getByRole('button', { name: /تكبير/ }));

        const dialog = await screen.findByRole('dialog');
        const frames = Array.from(dialog.querySelectorAll('iframe'));

        expect(frames.length).toBeGreaterThan(0);
        expect(frames.every((f) => f.getAttribute('srcdoc') === PAPER)).toBe(true);
        expect(dialog.querySelector('iframe[src="/admin/purchases/7/pdf"]')).toBeNull();

        // والطباعةُ وحدها تقصد الملفّ — وهو ما يخرج على الورق
        expect(within(dialog).getByRole('link', { name: /طباعة/ })).toHaveAttribute(
            'href',
            '/admin/purchases/7/pdf',
        );
    });

    /*
     * والنافذةُ تُعار لمن يحتاجها.
     *
     * الشاشةُ الضيّقة تُنزل الورقةَ أسفل كلّ البطاقات، فيوصل إليها مختصرٌ في
     * الترويسة. وحالةٌ ثانيةٌ في الصفحة تعني نافذتين تُفتحان بزرّين.
     */
    it('يقبل فتحَ النافذة من خارج اللوحة', async () => {
        panel({ open: true, onOpenChange: () => {} });

        expect(await screen.findByRole('dialog')).toBeInTheDocument();
    });

    it('يعرض سطرًا تحت الأزرار حين يُعطاه', () => {
        panel({ note: 'مسودّة لم تُرسَل بعد' });

        expect(screen.getByText('مسودّة لم تُرسَل بعد')).toBeInTheDocument();
    });
});

describe('DocumentAside', () => {
    /* ولاصقةٌ عند الأعلى على الواسعة وحدها — والضيّقةُ تُنزلها تحت التفاصيل */
    it('يلصق الورقة عند الأعلى على الشاشات الواسعة', () => {
        const { container } = render(
            <DocumentAside>
                <p>ورقة</p>
            </DocumentAside>,
        );

        expect(container.querySelector('aside')).toBeInTheDocument();
        expect(container.querySelector('.xl\\:sticky')).toBeInTheDocument();
    });
});

describe('DocumentMeta', () => {
    it('يعرض كلَّ حقلٍ عنوانًا فوق قيمته', () => {
        render(
            <DocumentMeta
                cells={[
                    { label: 'المورّد', value: 'مشتل الربيع' },
                    { label: 'تاريخ الطلب', value: '2026-09-12', ltr: true },
                ]}
            />,
        );

        const list = screen.getByText('المورّد').closest('dl');

        expect(list).not.toBeNull();
        // وعنوانٌ فوق قيمته — `dt` ثمّ `dd`، لا سطرُ «عنوان: قيمة»
        expect(screen.getByText('المورّد').tagName).toBe('DT');
        expect(within(list!).getByText('مشتل الربيع').tagName).toBe('DD');
        expect(within(list!).getByText('2026-09-12')).toHaveAttribute('dir', 'ltr');
    });

    /* وخانةٌ خاوية تحت «مرجع المورّد» تُقرأ حقلًا نُسي — فتُحذف عند البناء */
    it('يُسقط الحقول التي لا قيمة لها', () => {
        render(
            <DocumentMeta
                cells={[
                    { label: 'المورّد', value: 'مشتل الربيع' },
                    null,
                    false,
                    undefined,
                ]}
            />,
        );

        expect(document.querySelectorAll('dt')).toHaveLength(1);
    });

    it('لا يرسم شيئًا حين لا حقلَ يُعرض', () => {
        const { container } = render(<DocumentMeta cells={[null, false]} />);

        expect(container).toBeEmptyDOMElement();
    });
});
