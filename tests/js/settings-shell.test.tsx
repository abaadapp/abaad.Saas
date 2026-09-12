import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { KeyRound } from 'lucide-react';
import {
    Advanced,
    PageActions,
    SettingsGroup,
    SettingsPage,
    SettingsSection,
    SetupProgress,
} from '@/Components/Settings';
import StatusPill, { readinessState } from '@/Components/StatusPill';

/**
 * هيكلُ صفحات الإعدادات — ما يُقاس منه في المتصفّح لا في المصدر.
 *
 * وثلاثةٌ تُحرَس هنا لأنّ سقوطها صامت: قسمٌ مطويٌّ لا يُفتح بلوحة المفاتيح
 * فيُقفل في وجه من لا فأرة له، وخطأُ تحقّقٍ يقع تحت الطيّ فلا يراه صاحبه،
 * وشارةُ حالٍ تقول لونًا ولا تقول نصًّا فلا يقرؤها من لا يفرّق الألوان.
 */

describe('القسم', () => {
    it('يُرسم عنصر section بعنوانٍ من المستوى الثاني', () => {
        render(<SettingsSection title="بيانات النشاط" description="ما يُطبع في رأس الورقة">نصّ</SettingsSection>);

        const head = screen.getByRole('heading', { level: 2, name: 'بيانات النشاط' });
        expect(head).toBeInTheDocument();
        expect(head.closest('section')).not.toBeNull();
        expect(screen.getByText('ما يُطبع في رأس الورقة')).toBeInTheDocument();
    });

    it('يحمل حالَه وإجراءَه إلى جانب العنوان', () => {
        render(
            <SettingsSection
                title="واتساب"
                status={<StatusPill state="ready" />}
                action={<button type="button">حدِّث</button>}
            />,
        );

        expect(screen.getByText('جاهز')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'حدِّث' })).toBeInTheDocument();
    });

    /* المجموعاتُ يفصلها خطٌّ من الحاوية، فلا يحسب كلُّ موضعٍ موقعه بنفسه */
    it('يفصل مجموعاته بخطٍّ حين يُطلب', () => {
        const { container } = render(
            <SettingsSection title="المالية" divided>
                <SettingsGroup title="الضرائب">أ</SettingsGroup>
                <SettingsGroup title="العملة">ب</SettingsGroup>
            </SettingsSection>,
        );

        expect(container.querySelector('.divide-y')).not.toBeNull();
        expect(screen.getByRole('heading', { level: 3, name: 'الضرائب' })).toBeInTheDocument();
    });
});

describe('العمود المركزي', () => {
    it('يضع سقفًا لعرض النموذج ولا يضعه للجداول', () => {
        const { container: form } = render(<SettingsPage>نموذج</SettingsPage>);
        expect(form.firstElementChild?.className).toContain('max-w-3xl');

        const { container: full } = render(<SettingsPage width="full">جدول</SettingsPage>);
        expect(full.firstElementChild?.className).not.toContain('max-w-');
    });
});

describe('القسم المطويّ', () => {
    it('مطويٌّ في أوّل فتحة — فلا يزاحم المتقدّمُ الأساسيَّ', () => {
        render(
            <Advanced title="مفتاحك الخاص" icon={KeyRound}>
                <input aria-label="المفتاح" />
            </Advanced>,
        );

        expect(screen.getByText('مفتاحك الخاص')).toBeInTheDocument();
        expect(document.querySelector('details')?.open).toBe(false);
    });

    /*
        ولوحةُ المفاتيح تفتحه كما تفتحه اليد.

        والحارسُ على الوسم لا على ضغطة المفتاح: `<summary>` داخل
        `<details>` يمنحه المتصفّحُ التركيزَ و`Enter` و`Space` وإعلانَ
        الحالة للقارئ الآليّ — ولا شيء من ذلك في `<div onClick>`. وjsdom
        لا يُركّب فعل المفتاح، فيُقاس ما يضمنه: الوسمُ نفسه، ثمّ التركيز،
        ثمّ الفتح بالتفعيل.
    */
    it('مقبضُه summary يُركَّز ويُفتح بالتفعيل', async () => {
        const user = userEvent.setup();
        render(<Advanced title="الإعدادات المتقدّمة">محتوى</Advanced>);

        const head = document.querySelector('summary');
        expect(head).not.toBeNull();
        expect(head?.parentElement?.tagName.toLowerCase()).toBe('details');

        await user.tab();
        expect(document.activeElement).toBe(head);

        await user.click(head!);
        expect(document.querySelector('details')?.open).toBe(true);
    });

    /* وخطأٌ تحت الطيّ لا يُرى: يُفتح القسم من نفسه حين يصل */
    it('ينفتح رغمًا عن الطيّ حين يصل خطأُ تحقّق', () => {
        const { rerender } = render(<Advanced title="المفتاح">محتوى</Advanced>);
        expect(document.querySelector('details')?.open).toBe(false);

        rerender(<Advanced title="المفتاح" forceOpen>محتوى</Advanced>);
        expect(document.querySelector('details')?.open).toBe(true);
    });
});

describe('شارةُ الحال', () => {
    /* لا لونَ وحده: من لا يفرّق الأخضر من الأحمر يقرأ النصّ والأيقونة */
    it('تقول حالَها نصًّا وأيقونةً لا لونًا', () => {
        const { container } = render(<StatusPill state="action" />);

        expect(screen.getByText('يحتاج إجراء')).toBeInTheDocument();
        expect(container.querySelector('svg')).not.toBeNull();
    });

    it('«متّصل» لأداةٍ لها وصلة، و«جاهز» لما سواها', () => {
        const { rerender } = render(<StatusPill state="ready" connected />);
        expect(screen.getByText('متّصل')).toBeInTheDocument();

        rerender(<StatusPill state="ready" />);
        expect(screen.getByText('جاهز')).toBeInTheDocument();
    });

    /* و«بدأ ولم يكتمل» غير «لم يبدأ» — الأولى تُكمَل والثانية تُفتح */
    it('تفرّق بين ما لم يبدأ وما بدأ ولم يكتمل', () => {
        expect(readinessState({ connected: false, ready: false })).toBe('idle');
        expect(readinessState({ connected: true, ready: false })).toBe('progress');
        expect(readinessState({ connected: true, ready: true })).toBe('ready');
    });
});

describe('شريط التقدّم', () => {
    it('يُعلن موضعه للقارئ الآليّ لا للعين وحدها', () => {
        render(<SetupProgress at={2} total={5} label="مراحل مكتملة" />);

        const bar = screen.getByRole('progressbar', { name: 'مراحل مكتملة' });
        expect(bar).toHaveAttribute('aria-valuenow', '2');
        expect(bar).toHaveAttribute('aria-valuemax', '5');
    });

    it('لا يُرسم شريطٌ لا مراحل له', () => {
        const { container } = render(<SetupProgress at={0} total={0} label="مراحل" />);
        expect(container.firstChild).toBeNull();
    });
});

describe('قدمُ الصفحة', () => {
    it('تحمل السببَ إلى جانب الزرّ حين يتعطّل', () => {
        render(
            <PageActions note="اكتب الاسم أوّلًا">
                <button type="button" disabled>أقرّ</button>
            </PageActions>,
        );

        expect(screen.getByText('اكتب الاسم أوّلًا')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'أقرّ' })).toBeDisabled();
    });
});
