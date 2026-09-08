import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { InvoiceNumberField } from '@/Pages/Admin/CustomerInvoices/Create';

/**
 * رقمُ الفاتورة قبل حفظها — يُقرأ ويُنسخ ولا يُكتب.
 *
 * كان الحقل معطَّلًا: هيئتُه تقول «حقل» فتدعو إلى الضغط، وضغطُه لا يفعل
 * شيئًا — ولا يُحدَّد نصُّه أصلًا. فمن أراد أن ينقل الرقم إلى رسالةٍ أو إلى
 * أمر شراء عميله نقله بعينه، وهو ثمانيةُ محارف.
 *
 * وهذا سلوكُ متصفّحٍ لا شيءَ منه يبلغ الخادم: لا يُثبته اختبارُ PHP.
 */

const write = vi.fn();

/*
 * والترتيبُ مقصود: `userEvent.setup()` يركّب حافظتَه هو على `navigator`.
 *
 * فتركيبُ حافظتِنا قبله يُمحى بلا كلمة، وتمرّ الحالةُ الفاشلة خضراءَ — نسخٌ
 * يقع في حافظة المُشغِّل لا في مَزيَّفنا، و«نُسخ» تُعلَن على رفضٍ صنعناه.
 * فيُبنى المستخدمُ أوّلًا ثمّ تُركَّب الحافظةُ فوق حافظته.
 */
function actor(clipboard: () => Promise<void> = () => Promise.resolve()) {
    const user = userEvent.setup();

    write.mockImplementation(clipboard);
    Object.defineProperty(navigator, 'clipboard', {
        value: { writeText: write },
        configurable: true,
    });

    return user;
}

beforeEach(() => {
    write.mockReset();
});

afterEach(() => {
    // ولا تُترك الحافظةُ المزيّفة لمن بعدُ
    Reflect.deleteProperty(navigator, 'clipboard');
});

describe('رقمُ الفاتورة يُنسخ بضغطة', () => {
    /**
     * وهذا هو الحارس: `disabled` يمنع الضغطَ والتركيزَ وتحديدَ النصّ معًا.
     * فمن أعاده أعاد الحقلَ صامتًا وإن بقي كلُّ ما تحته.
     */
    it('يُقرأ ولا يُكتب — ولا يكون معطَّلًا', () => {
        render(<InvoiceNumberField number="INV-000148" />);

        const box = screen.getByLabelText('رقم الفاتورة');

        expect(box).toHaveValue('INV-000148');
        expect(box).toHaveAttribute('readonly');
        expect(box).not.toBeDisabled();
    });

    it('الضغطُ على الحقل ينسخ الرقم ويقول إنّه نُسخ', async () => {
        const user = actor();
        render(<InvoiceNumberField number="INV-000148" />);

        await user.click(screen.getByLabelText('رقم الفاتورة'));

        expect(write).toHaveBeenCalledWith('INV-000148');
        expect(await screen.findByText('نُسخ')).toBeInTheDocument();
    });

    it('والزرُّ يفعل ما يفعله الحقل', async () => {
        const user = actor();
        render(<InvoiceNumberField number="INV-000148" />);

        await user.click(screen.getByRole('button', { name: 'نسخ رقم الفاتورة' }));

        expect(write).toHaveBeenCalledWith('INV-000148');
        expect(await screen.findByText('نُسخ')).toBeInTheDocument();
    });

    /**
     * ولا يُقال «نُسخ» قبل أن يقع النسخ.
     *
     * كتابةُ الحافظة وعدٌ لا فعلٌ فوريّ، وقد يبطؤ أو يُردّ. فإعلانُ النجاح
     * عند الضغط بدل انتظار الوعد يُظهر «نُسخ» على نسخٍ لم يقع بعدُ — ويبقى
     * ظاهرًا ما دام الوعد معلَّقًا. وهذا الحارسُ يمسك الوعدَ معلَّقًا ليسأل.
     */
    it('لا يُعلن النسخ ما دام الوعد معلَّقًا', async () => {
        let release: () => void = () => {};
        const user = actor(() => new Promise<void>((resolve) => { release = resolve; }));
        render(<InvoiceNumberField number="INV-000148" />);

        await user.click(screen.getByLabelText('رقم الفاتورة'));

        expect(write).toHaveBeenCalledWith('INV-000148');
        expect(screen.queryByText('نُسخ')).not.toBeInTheDocument();

        release();
        expect(await screen.findByText('نُسخ')).toBeInTheDocument();
    });

    /**
     * ومتصفّحٌ يمنع الحافظة — على أصلٍ غير آمن أو بإعدادٍ — لا يترك المستخدم
     * بلا طريق: النصُّ يُحدَّد قبل المحاولة، فيبقى محدَّدًا ينسخه بلوحته.
     * ولا تُعلَن كذبةُ «نُسخ» على نسخٍ لم يقع.
     */
    it('وإن مُنعت الحافظة يبقى الرقم محدَّدًا ولا يُقال إنّه نُسخ', async () => {
        const user = actor(() => Promise.reject(new Error('blocked')));
        render(<InvoiceNumberField number="INV-000148" />);

        const box = screen.getByLabelText<HTMLInputElement>('رقم الفاتورة');
        await user.click(box);

        expect(box.selectionStart).toBe(0);
        expect(box.selectionEnd).toBe('INV-000148'.length);
        expect(screen.queryByText('نُسخ')).not.toBeInTheDocument();
        expect(screen.getByText('اضغط لنسخ الرقم')).toBeInTheDocument();
    });
});
