import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import CrmLeadsIndex from '@/Pages/Platform/Crm/Leads/Index';
import { pageProps } from './setup';

/**
 * طلبُ الموقع غيرُ المقروء يُرى في موضعين — ولا يختلط بمحادثات واتساب.
 *
 * شارةُ «العملاء المحتملون» تقرأ `crmLeadsBadge`، وشارةُ «المحادثات» تقرأ
 * `crmBadge` كما كانت، ولا تُجمعان. والصفُّ الذي له طلبُ موقعٍ لم يُقرأ يحمل
 * «طلب موقع جديد» — والخادمُ يقرّره، والشاشةُ ترسمه.
 */

const lead = (id: number, name: string, unread: boolean) => ({
    id, name, businessName: null, phone: '9123456' + id, stage: 'new', stageLabel: 'جديد', stageTone: 'info',
    status: 'active', statusLabel: 'نشط', source: 'website', sourceLabel: 'الموقع الإلكتروني',
    assignee: null, plan: null, lastContactAt: null, nextFollowUpAt: null, followUpOverdue: false,
    preview: null, url: '/super-admin/crm/leads/' + id, hasUnreadWebsiteInquiry: unread,
});

function show(crmBadge: number, crmLeadsBadge: number) {
    const base = globalThis.route as unknown as (...a: unknown[]) => string;
    globalThis.route = ((...a: unknown[]) =>
        a.length ? base(...a) : { current: () => 'super-admin.crm.leads.index' }) as never;

    Object.assign(pageProps, {
        auth: {
            abilities: [], mayActions: [], isEmployee: false,
            user: { name: 'سالم', avatar: null, roleLabel: 'مدير المنصة', role: 'super_admin', businessId: null },
        },
        context: null, notifications: null, supportBadge: 0, crmBadge, crmLeadsBadge,
        reportPages: [], locale: 'ar', csrf: 'x', flash: {},
        leads: [lead(1, 'محمد', true), lead(2, 'سالم', false)],
        pagination: { currentPage: 1, lastPage: 1, perPage: 25, total: 2, from: 1, to: 2 },
        filters: { q: '', stage: 'all', status: 'all', assignment: 'all', source: 'all' },
        counts: {}, stages: [], sources: [], statuses: [], staff: [],
    });

    return render(<CrmLeadsIndex />);
}

/** الشارةُ المرسومة على بند الشريط الجانبيّ بهذا الاسم — أو `null` */
function badgeOn(label: string): string | null {
    const item = [...document.querySelectorAll('a')].find((a) => a.textContent?.startsWith(label));
    expect(item, label).toBeTruthy();
    const pill = item!.querySelector('span.rounded-full');

    return pill ? pill.textContent : null;
}

describe('a website inquiry waiting in the CRM', () => {
    beforeEach(() => document.body.replaceChildren());

    it('puts its own count on «العملاء المحتملون» and leaves «المحادثات» on the WhatsApp count', () => {
        show(4, 2);

        expect(badgeOn('العملاء المحتملون')).toBe('2');
        expect(badgeOn('المحادثات')).toBe('4');
    });

    it('draws no pill at zero on either item', () => {
        show(0, 0);

        expect(badgeOn('العملاء المحتملون')).toBeNull();
        expect(badgeOn('المحادثات')).toBeNull();
    });

    it('marks only the row whose website inquiry is unread', () => {
        show(0, 1);

        const marks = document.querySelectorAll('[data-unread-website-inquiry]');
        expect(marks.length).toBeGreaterThan(0);
        for (const mark of marks) {
            expect(mark.textContent).toBe('طلب موقع جديد');
            expect(mark.closest('a')?.getAttribute('href')).toBe('/super-admin/crm/leads/1');
        }
        expect(screen.getAllByText('سالم').length).toBeGreaterThan(0);
    });
});
