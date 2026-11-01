import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { describe, expect, it } from 'vitest';
import WhatsappGuidedOrders, { type GuidedOrdersView } from '@/Pages/Admin/Integrations/partials/WhatsappGuidedOrders';
import GuidedOrderBar, { type GuidedOrder, STATES } from '@/Pages/Admin/WhatsAppInbox/partials/GuidedOrderBar';

/**
 * الطلباتُ الآليّة عبر واتساب كما يراها صاحبُ المتجر وموظّفُه.
 *
 *  • المفتاحُ يقول حالَه من الخادم: «يعمل» حين تكتمل الشروطُ كلُّها، و«بانتظار»
 *    حين يُشعَل وينقص شرط — والناقصُ مسمًّى (`WhatsAppOrders::readiness`).
 *  • وفوق المحادثة: حالُ الطلب بإحدى خمس كلمات، وفاتورتُه، وما يفعله الموظّف.
 *  • ورسالتا الرابط والتأكيد بحالهما — وما لم يصل العميلَ يُعاد بزرٍّ بعد سؤال.
 *
 * والخادمُ يحكم بها: `ACustomerOrdersThroughWhatsAppTest`.
 */

const READY: GuidedOrdersView['checks'] = { number: true, inbox: true, preparation: true, payment_links: true };

const ORDER: GuidedOrder = {
    id: 7, state: 'awaiting_payment', open: true, step: null, total: 45, reason: null, error: null, items: 1,
    invoice: { id: 12, number: 'CINV-000012' }, link: { status: 'pending', open: true, url: 'https://paymob.link/x' },
    link_message: 'delivered', paid_message: null, can_resend_link: false, can_resend_paid: false, link_uncertain: false, updated_at: null,
};

describe('مفتاحُ الطلبات الآليّة', () => {
    it('يعمل حين تكتمل الشروطُ كلُّها', () => {
        render(<WhatsappGuidedOrders view={{ enabled: true, active: true, checks: READY }} mayManage />);

        expect(screen.getByText('يعمل')).toBeInTheDocument();
        expect(screen.getAllByTestId('guided-orders-checks')[0].querySelectorAll('[data-ok="1"]')).toHaveLength(4);
        expect(screen.getByTestId('guided-orders-save')).toBeInTheDocument();
    });

    it('مُشعَلٌ وتجهيزُ الفواتير مغلق: «بانتظار» — والناقصُ وحده مُعلَّم', () => {
        render(<WhatsappGuidedOrders view={{ enabled: true, active: false, checks: { ...READY, preparation: false } }} mayManage />);

        expect(screen.queryByText('يعمل')).toBeNull();
        expect(screen.getByText('بانتظار اكتمال الشروط')).toBeInTheDocument();
        const missing = screen.getByTestId('guided-orders-checks').querySelectorAll('[data-ok="0"]');
        expect(missing).toHaveLength(1);
        expect(missing[0]).toHaveTextContent('تجهيز الفواتير بعد السداد');
    });

    it('مطفأ: «متوقف» — ومن لا يملك الإعداد لا يرى زرّ الحفظ', () => {
        render(<WhatsappGuidedOrders view={{ enabled: false, active: false, checks: READY }} mayManage={false} />);

        expect(screen.getByText('متوقف')).toBeInTheDocument();
        expect(screen.queryByTestId('guided-orders-save')).toBeNull();
    });
});

describe('حالُ الطلب فوق المحادثة', () => {
    it('بانتظار الدفع: فاتورتُه برقمها، والرابطُ مفتوح، وزرّا الموظّف', () => {
        render(<GuidedOrderBar chatId={3} order={ORDER} canReply />);

        expect(screen.getByTestId('guided-order-state')).toHaveTextContent('بانتظار الدفع');
        expect(screen.getByTestId('guided-order-invoice')).toHaveTextContent('CINV-000012');
        expect(screen.getByText('رابط الدفع مفتوح')).toBeInTheDocument();
        expect(screen.getByTestId('guided-order-take-over')).toBeInTheDocument();
        expect(screen.getByTestId('guided-order-close')).toBeInTheDocument();
    });

    it('يحتاج تدخّلًا: سببُه مكتوب، ولا «تسلّم» لما تسلّمه موظّف', () => {
        render(<GuidedOrderBar chatId={3} order={{ ...ORDER, state: 'needs_human', reason: 'special_request' }} canReply />);

        expect(screen.getByTestId('guided-order-state')).toHaveTextContent('يحتاج تدخلًا بشريًا');
        expect(screen.getByTestId('guided-order-reason')).toHaveTextContent('طلب خاص');
        expect(screen.queryByTestId('guided-order-take-over')).toBeNull();
        expect(screen.getByTestId('guided-order-close')).toBeInTheDocument();
    });

    it('مدفوعٌ مُغلق، أو موظّفٌ للمشاهدة وحدها: لا أزرار', () => {
        const { unmount } = render(<GuidedOrderBar chatId={3} order={{ ...ORDER, state: 'paid', open: false }} canReply />);
        expect(screen.getByTestId('guided-order-state')).toHaveTextContent('مدفوع — في التجهيز');
        expect(screen.queryByTestId('guided-order-close')).toBeNull();
        unmount();

        render(<GuidedOrderBar chatId={3} order={ORDER} canReply={false} />);
        expect(screen.queryByTestId('guided-order-take-over')).toBeNull();
        expect(screen.queryByTestId('guided-order-close')).toBeNull();
    });

    it('رابطٌ وصل: «وصلت» ولا زرَّ إعادة', () => {
        render(<GuidedOrderBar chatId={3} order={ORDER} canReply />);

        expect(screen.getByTestId('guided-order-link-message')).toHaveTextContent('رسالة الرابط: وصلت');
        expect(screen.queryByTestId('guided-order-resend-link')).toBeNull();
    });

    it('رابطٌ لم يصل: «لم تصل» بالأحمر، والإعادةُ بعد سؤالٍ ترسل الرابطَ نفسه', async () => {
        render(<GuidedOrderBar chatId={3} order={{ ...ORDER, link_message: 'failed', can_resend_link: true }} canReply />);

        const state = screen.getByTestId('guided-order-link-message');
        expect(state).toHaveTextContent('رسالة الرابط: لم تصل');
        expect(state).toHaveAttribute('data-status', 'failed');

        const user = userEvent.setup();
        await user.click(screen.getByTestId('guided-order-resend-link'));
        expect(screen.getByText(/لا فاتورة ثانية ولا رابط ثانٍ/)).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'إرسال' }));

        expect(router.post).toHaveBeenLastCalledWith(route('admin.whatsappInbox.order.resendLink', 3), {}, { preserveScroll: true });
    });

    it('رابطٌ لم تُعرف نتيجتُه عند Paymob: يُقال ذلك، والجديدُ بقرارٍ صريح', async () => {
        const order: GuidedOrder = { ...ORDER, state: 'needs_human', reason: 'link_failed', link: null, link_message: null, can_resend_link: true, link_uncertain: true };
        render(<GuidedOrderBar chatId={3} order={order} canReply />);

        expect(screen.getByTestId('guided-order-link-uncertain')).toBeInTheDocument();
        expect(screen.getByTestId('guided-order-reason')).toHaveTextContent('تعذّر إنشاء رابط الدفع');

        const user = userEvent.setup();
        await user.click(screen.getByTestId('guided-order-resend-link'));
        expect(screen.getByText(/يُرسل وحده للعميل/)).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'طلب وإرسال' }));

        expect(router.post).toHaveBeenLastCalledWith(route('admin.whatsappInbox.order.resendLink', 3), { replace_uncertain: true }, { preserveScroll: true });
    });

    it('مدفوعٌ لم يصله التأكيد: يُعاد التأكيدُ وحده — ولمن يملك الردّ وحده', async () => {
        const paid: GuidedOrder = { ...ORDER, state: 'paid', open: false, paid_message: 'failed', can_resend_paid: true };
        const { unmount } = render(<GuidedOrderBar chatId={3} order={paid} canReply />);

        expect(screen.getByTestId('guided-order-paid-message')).toHaveTextContent('رسالة التأكيد: لم تصل');
        expect(screen.queryByTestId('guided-order-close')).toBeNull();

        const user = userEvent.setup();
        await user.click(screen.getByTestId('guided-order-resend-paid'));
        expect(screen.getByText(/لا يتغير شيء في التحصيل ولا في لوحة التجهيز/)).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'إرسال' }));
        expect(router.post).toHaveBeenLastCalledWith(route('admin.whatsappInbox.order.resendPaid', 3), {}, { preserveScroll: true });
        unmount();

        render(<GuidedOrderBar chatId={3} order={paid} canReply={false} />);
        expect(screen.queryByTestId('guided-order-resend-paid')).toBeNull();
    });

    it('الحالاتُ الستّ كلُّها لها كلمة', () => {
        expect(Object.keys(STATES).sort()).toEqual(['awaiting_payment', 'cancelled', 'collecting', 'confirming', 'needs_human', 'paid']);
    });
});
