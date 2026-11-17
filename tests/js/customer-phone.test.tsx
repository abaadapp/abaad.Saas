import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { phoneProblem, phoneRefusal, phoneStatus } from '@/lib/customer-phone';
import { CustomerPhoneReviewBanner, PhoneStatusBadge } from '@/Components/CustomerPhoneReview';
import shared from '../fixtures/customer-phone-cases.json';

/**
 * رقمُ العميل في الشاشة بقاعدة الخادم (`CustomerPhone`) — الحالاتُ نفسُها
 * يقرؤها `AnOmaniCustomerPhoneIsEightDigitsTest`. يوم يفترق طرفٌ يسقط.
 */
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');
    return { ...actual, router: { get: vi.fn() }, usePage: () => ({ props: { translations: {} } }) };
});

type ProblemCase = { raw: string | null; expect: string | null; why: string };
type StatusCase = { phone: string | null; contact: string | null; name: string; expect: string | null };

describe('القاعدةُ المشتركة', () => {
    it('كلُّ حالةٍ تُقرأ كما يقرؤها الخادم', () => {
        for (const c of (shared as { problem: ProblemCase[] }).problem) {
            expect(phoneProblem(c.raw), `${c.why} — ${JSON.stringify(c.raw)}`).toBe(c.expect);
        }
        for (const c of (shared as { status: StatusCase[] }).status) {
            expect(phoneStatus(c.phone, c.contact, c.name), JSON.stringify(c)).toBe(c.expect);
        }
    });

    it('نصُّ الرفض: ثماني خانات، والفراغُ والدوليُّ مقبولان', () => {
        expect(phoneRefusal('9123456')).toBe('رقم الهاتف العُماني يجب أن يتكون من 8 أرقام.');
        expect(phoneRefusal('٩١٢٣٤٥٦٧')).toBeNull();
        expect(phoneRefusal('')).toBeNull();
        expect(phoneRefusal('+971501234567')).toBeNull();
    });
});

describe('تنبيهُ بيانات العملاء', () => {
    it('يقول العددَ ويدعو إلى المراجعة', () => {
        render(<CustomerPhoneReviewBanner count={12} />);

        expect(screen.getByTestId('customer-phone-review')).toHaveTextContent('تنبيه بيانات العملاء');
        expect(screen.getByTestId('customer-phone-review')).toHaveTextContent('يوجد لديك (12) عميل يحتاج إلى مراجعة رقم الهاتف أو تحديثه.');
        expect(screen.getByRole('button', { name: 'مراجعة العملاء' })).toBeInTheDocument();
    });

    it('ولا يظهر بلا أرقامٍ تحتاج تحديثًا', () => {
        const { container } = render(<CustomerPhoneReviewBanner count={0} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('وحالةُ الرقم بجانبه، ولا شيء لرقمٍ صالح', () => {
        const { rerender, container } = render(<PhoneStatusBadge status="incomplete" />);
        expect(screen.getByTestId('phone-status')).toHaveTextContent('رقم غير مكتمل');
        rerender(<PhoneStatusBadge status="missing" />);
        expect(screen.getByTestId('phone-status')).toHaveTextContent('رقم مفقود');
        rerender(<PhoneStatusBadge status="invalid" />);
        expect(screen.getByTestId('phone-status')).toHaveTextContent('رقم غير صحيح');
        rerender(<PhoneStatusBadge status={null} />);
        expect(container).toBeEmptyDOMElement();
    });
});
