import { useState } from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/**
 * «اترك المبلغ الزائد رصيدًا للعميل» في نافذة التحصيل — من جهة الشاشة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - لا يظهر الخيارُ ما لم يزد المبلغُ على الباقي، ولا يُختار وحدَه.
 *   - قبل الحفظ يُقال المستحقُّ على الفاتورة والزائدُ بالعملة.
 *   - يُرسل `leave_excess` صادقًا لمن اختاره وحده، وكاذبًا إن نزل المبلغُ إلى الباقي بعد اختياره.
 *   - ومفتاحُ المحاولة يُرسل مع كلّ حفظ.
 *
 * والخادمُ يحرس ما يقع (`AnInvoiceIsCollectedOnceAndNeverPastWhatItOwesTest`، قسم الجولة 16).
 */

const sent: { url: string; data: Record<string, unknown> }[] = [];

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');

    const useForm = (initial: Record<string, unknown>) => {
        const [data, setData] = useState(initial);
        let transform = (d: Record<string, unknown>) => d;

        return {
            data,
            errors: {} as Record<string, string>,
            processing: false,
            setData: (key: string, value: unknown) => setData((d) => ({ ...d, [key]: value })),
            transform: (fn: (d: Record<string, unknown>) => Record<string, unknown>) => {
                transform = fn;
            },
            post: (url: string) => sent.push({ url, data: transform(data) }),
        };
    };

    return { ...actual, usePage: () => ({ props: pageProps, url: '/', component: 'Test' }), Head: () => null, useForm };
});

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));
vi.mock('@/Components/PaperFrame', () => ({ default: () => null }));

const { PayForm } = await import('@/Pages/Admin/CustomerInvoices/Show');

const invoice = (outstanding: number) =>
    ({ id: 9, customer_id: 7, outstanding, total: 25, tax_total: 0 }) as unknown as Parameters<typeof PayForm>[0]['invoice'];

const form = (outstanding = 15) =>
    render(<PayForm invoice={invoice(outstanding)} accounts={[]} methods={['نقدي', 'تحويل']} bankMethods={['تحويل']} onDone={() => {}} />);

const amount = () => screen.getAllByRole('textbox')[0];

describe('تركُ الزائد رصيدًا للعميل', () => {
    beforeEach(() => {
        sent.length = 0;
    });

    it('لا يظهر والمبلغُ هو الباقي — ويُرسل التحصيلُ بلا خيارٍ وبمفتاحه', async () => {
        form(15);

        expect(screen.queryByTestId('leave-excess')).toBeNull();
        await userEvent.click(screen.getByRole('button', { name: 'حفظ التحصيل' }));

        expect(sent).toHaveLength(1);
        expect(sent[0].url).toBe('/admin/customer-payments');
        expect(sent[0].data.leave_excess).toBe(false);
        expect(String(sent[0].data.request_key)).not.toBe('');
        expect(sent[0].data.amount).toBe('15');
    });

    it('مبلغٌ أكبرُ من الباقي: يُقال المستحقُّ والزائد، والخيارُ غيرُ مختار', async () => {
        form(15);

        await userEvent.clear(amount());
        await userEvent.type(amount(), '20');

        const box = screen.getByTestId('leave-excess');
        expect(screen.getByTestId('excess-due').textContent).toContain('15.000');
        expect(screen.getByTestId('excess-amount').textContent).toContain('5.000');
        expect(box.textContent).toContain('اترك المبلغ الزائد رصيدًا للعميل');
        expect((screen.getByRole('checkbox') as HTMLInputElement).checked).toBe(false);
        expect(box.textContent).toContain('بدون هذا الخيار يُردّ');

        // ومن لم يختره يُرسل بلا خيار — فيردّه الخادم بالباقي
        await userEvent.click(screen.getByRole('button', { name: 'حفظ التحصيل' }));
        expect(sent[0].data.leave_excess).toBe(false);
    });

    it('ومن اختاره يُرسله — ومن اختاره ثمّ أنزل المبلغَ إلى الباقي لا يُرسله', async () => {
        form(15);

        await userEvent.clear(amount());
        await userEvent.type(amount(), '20');
        await userEvent.click(screen.getByRole('checkbox'));
        await userEvent.click(screen.getByRole('button', { name: 'حفظ التحصيل' }));
        expect(sent[0].data.leave_excess).toBe(true);
        expect(sent[0].data.amount).toBe('20');

        await userEvent.clear(amount());
        await userEvent.type(amount(), '15');
        expect(screen.queryByTestId('leave-excess')).toBeNull();
        await userEvent.click(screen.getByRole('button', { name: 'حفظ التحصيل' }));
        expect(sent[1].data.leave_excess).toBe(false);
    });

    it('وفاتورةٌ لم يبقَ عليها شيء: لا خيار — الدفعةُ المقدّمة بابُها «تحصيل على حساب العميل»', async () => {
        form(0);

        await userEvent.clear(amount());
        await userEvent.type(amount(), '5');

        expect(screen.queryByTestId('leave-excess')).toBeNull();
    });
});
