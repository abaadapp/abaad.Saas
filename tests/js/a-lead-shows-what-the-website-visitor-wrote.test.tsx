import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/**
 * ملفُّ العميل المحتمل يرسم «طلب الموقع الإلكتروني» ممّا يصله — ولا يقرأ نصًّا.
 *
 * الخادمُ يستخرج الطلبات من ملاحظاتها (`websiteInquiries`)، والشاشةُ ترسمها:
 * كلُّها بترتيبها، والحقلُ الفارغ لا يُرسم، والرسالةُ بأسطرها. ومن لا طلبَ له
 * من الموقع لا قسمَ له. والملاحظةُ الأصليّة باقيةٌ في «ملاحظات».
 */

vi.mock('@/Layouts/PlatformLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: CrmLeadShow } = await import('@/Pages/Platform/Crm/Leads/Show');

const LEAD = {
    id: 7, name: 'Abaad Website Test', businessName: 'Abaad Website Test', phone: '90000000',
    stage: 'new', stageLabel: 'جديد', stageTone: 'info', status: 'active', statusLabel: 'نشط',
    source: 'website', sourceLabel: 'الموقع الإلكتروني', assignee: null, plan: null,
    lastContactAt: '2026-10-14 13:24', nextFollowUpAt: null, followUpOverdue: false, preview: null, url: '/x',
    phoneRaw: '90000000', phoneNormalized: '96890000000', wilayat: null, branchesCount: null, currentSystem: null,
    planId: null, expectedValue: null, assigneeId: null, assignedBy: null, assignedAt: null,
    firstContactAt: '2026-10-14 13:24', nextFollowUpRaw: null, tags: [], lostReason: null,
    lostReasonLabel: null, lostNote: null, convertedAt: null, business: null,
};

const FIRST = {
    id: 11, name: 'Abaad Website Test', businessName: 'Abaad Website Test', phone: '90000000',
    email: 'abaadappom@gmail.com', activity: 'أخرى', locale: 'العربية',
    message: 'Website to CRM production verification', receivedAt: '2026-10-14 13:24',
};

function show(websiteInquiries: unknown[], notes: unknown[] = []) {
    Object.assign(pageProps, {
        lead: LEAD, existingMerchant: null, websiteInquiries, notes, tasks: [], timeline: [],
        stages: [], lostReasons: [], sources: [], staff: [], plans: [], taskPriorities: [], businesses: [],
    });
    return render(<CrmLeadShow />);
}

const section = () => document.querySelector('[data-website-inquiries]') as HTMLElement | null;

describe('the website request on a lead page', () => {
    beforeEach(() => document.body.replaceChildren());

    it('draws one request with every field the visitor gave', () => {
        show([FIRST], [{ id: 1, author: 'الموقع الإلكتروني', body: 'طلب تواصل من الموقع الإلكتروني\n…', at: '2026-10-14 13:24' }]);

        const box = section();
        expect(box).not.toBeNull();
        const s = within(box!);
        expect(s.getByText('طلب الموقع الإلكتروني')).toBeTruthy();
        for (const v of ['abaadappom@gmail.com', 'أخرى', 'العربية', 'Website to CRM production verification', '2026-10-14 13:24', '90000000']) {
            expect(s.getByText(v)).toBeTruthy();
        }
        // طلبٌ واحد بلا عدّاد، ولا «غير معروف» في طلبٍ لم يُخترع فيه شيء
        expect(s.queryByText(/طلبات$/)).toBeNull();
        expect(s.queryByText('غير معروف')).toBeNull();
        // والملاحظةُ الأصليّة باقيةٌ في «ملاحظات»
        expect(screen.getByText(/^طلب تواصل من الموقع الإلكتروني/)).toBeTruthy();
    });

    it('draws every request in the order it arrives, newest first, with its own date and message', () => {
        show([
            { ...FIRST, id: 12, message: 'الطلب الثاني\nبسطرين', receivedAt: '2026-10-16 09:00', activity: 'التجارة الإلكترونية' },
            { ...FIRST, id: 11, message: 'الطلب الأول', receivedAt: '2026-10-14 13:24' },
        ]);

        const items = [...section()!.querySelectorAll('[data-website-inquiry]')];
        expect(items).toHaveLength(2);
        expect(items[0].textContent).toContain('2026-10-16 09:00');
        expect(items[0].textContent).toContain('الطلب الثاني');
        expect(items[1].textContent).toContain('2026-10-14 13:24');
        expect(items[1].textContent).toContain('الطلب الأول');
        expect(within(section()!).getByText('2 طلبات')).toBeTruthy();
        // والرسالةُ بأسطرها كما كُتبت
        expect(within(items[0] as HTMLElement).getByText((_, el) => el?.textContent === 'الطلب الثاني\nبسطرين' && el.tagName === 'P')).toBeTruthy();
    });

    it('skips empty fields instead of inventing them', () => {
        show([{ ...FIRST, email: null, phone: null, locale: null, activity: null, message: null }]);

        const s = within(section()!);
        for (const label of ['البريد الإلكتروني', 'رقم الهاتف', 'لغة الصفحة', 'نوع النشاط', 'الرسالة']) {
            expect(s.queryByText(label)).toBeNull();
        }
        expect(s.getByText('الاسم')).toBeTruthy();
    });

    it('draws no section for a lead that never wrote from the website', () => {
        show([], [{ id: 1, author: 'سالم', body: 'اتصلتُ به', at: '2026-10-14 10:00' }]);

        expect(section()).toBeNull();
        expect(screen.queryByText('طلب الموقع الإلكتروني')).toBeNull();
        expect(screen.getByText('اتصلتُ به')).toBeTruthy();
    });
});
