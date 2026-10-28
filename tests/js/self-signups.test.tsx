import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import Register from '@/Pages/Auth/Register';
import SelfSignupsSection, { type SelfSignupRow } from '@/Pages/Platform/Businesses/partials/SelfSignupsSection';
import { pageProps } from './setup';

/**
 * من سجّل بنفسه — ما يقرؤه المسجِّلُ قبل الإرسال، وما يقرؤه مديرُ المنصّة بعده.
 *
 * والأرقامُ والحالاتُ تُحسب في الخادم (`SelfSignupsList`) ويحرسها PHP. وهنا
 * ما لا يراه إلّا متصفّح: أنّ القسمَ يقول ما وصله بلا حساب، ويقود إلى ملفّ
 * الشركة القائم، ويقول فراغَه فراغًا — وأنّ المعالجَ يقول التجربةَ ولا يسأل
 * عن باقة.
 */

const row = (over: Partial<SelfSignupRow> = {}): SelfSignupRow => ({
    id: 7,
    name: 'Ribbon Flowers',
    owner: 'سعود',
    type: 'محل ورود',
    phone: '+96891234567',
    contactEmail: 'saud@example.com',
    loginEmail: 'saud@example.com',
    teamSize: '2–5 موظفين',
    address: 'القرم',
    city: null,
    registeredAt: '2026-10-15 09:30',
    plan: 'الباقة الأساسية',
    trialStarts: '2026-10-15',
    trialEnds: '2026-10-29',
    daysLeft: 14,
    state: 'trialing',
    status: 'نشط',
    setupConfirmedAt: null,
    lastLogin: '2026-10-15 09:30',
    products: 0,
    firstSale: null,
    lastSale: null,
    ...over,
});

describe('the self-signups section on the businesses page', () => {
    it('says the four numbers it was given — and links every row to the existing business page', () => {
        render(
            <SelfSignupsSection
                stats={{ total: 12, trialing: 5, expired: 4, subscribed: 3 }}
                recent={[row(), row({ id: 8, name: 'Gold Corner', state: 'subscribed' }), row({ id: 9, name: 'Late', state: 'expired' })]}
            />,
        );

        const section = document.querySelector('[data-self-signups]') as HTMLElement;
        expect(within(section).getByText('التسجيلات من الموقع')).toBeInTheDocument();

        for (const [label, value] of [
            ['إجمالي التسجيلات الذاتية', '12'],
            ['في التجربة الآن', '5'],
            ['انتهت تجربتهم دون اشتراك', '4'],
            ['تحوّلوا إلى مشتركين', '3'],
        ]) {
            const card = within(section).getByText(label).closest('div')!.parentElement!;
            expect(card.textContent, label).toContain(value);
        }

        // الاسمُ يفتح ملفَّ الشركة القائم — لا ملفًّا ثانيًا
        expect(within(section).getByText('Ribbon Flowers').closest('a')).toHaveAttribute('href', '/super-admin.businesses.show/7');

        // والحالةُ شارةٌ بعلامتها، والأيّامُ الباقيةُ معها
        const states = [...section.querySelectorAll('[data-signup-state]')].map((el) => el.getAttribute('data-signup-state'));
        expect(states).toEqual(['trialing', 'subscribed', 'expired']);
        expect(section.querySelector('[data-signup-state="trialing"]')!.textContent).toContain('بقي 14 يوم');

        // و«عرض الكل» يفتح صفحتَه — لا بابَ في الشريط
        expect(within(section).getByText('عرض الكل').closest('a')).toHaveAttribute('href', '/super-admin.businesses.self-signups.index');
    });

    it('says it is empty when nobody signed up yet — and offers no list to open', () => {
        render(<SelfSignupsSection stats={{ total: 0, trialing: 0, expired: 0, subscribed: 0 }} recent={[]} />);

        expect(screen.getByText('لا توجد تسجيلات ذاتية بعد')).toBeInTheDocument();
        expect(screen.queryByText('عرض الكل')).not.toBeInTheDocument();
        expect(document.querySelectorAll('[data-self-signup-row]')).toHaveLength(0);
    });

    it('shows a business that fits none of the three by its own status', () => {
        render(<SelfSignupsSection stats={{ total: 1, trialing: 0, expired: 0, subscribed: 0 }} recent={[row({ state: null, status: 'معطل' })]} />);

        const badge = document.querySelector('[data-signup-state="other"]')!;
        expect(badge.textContent).toBe('معطل');
    });
});

describe('the signup wizard on the self-signup contract', () => {
    beforeEach(() => {
        Object.assign(pageProps, {
            activities: [{ value: 'محل ورود', label: 'محل ورود', icon: '🌷', hint: '' }],
            teamSizes: [{ value: 'أنا فقط', label: 'أنا فقط' }],
            year: 2026,
            trial: { days: 14, plan: 'الباقة الأساسية' },
            errors: {},
        });
        sessionStorage.clear();
    });

    afterEach(() => {
        sessionStorage.clear();
        Object.assign(pageProps, { trial: undefined, errors: {} });
    });

    const toLastStep = async () => {
        const user = userEvent.setup();
        render(<Register />);
        await user.type(screen.getByLabelText(/الاسم/), 'سعود');
        await user.type(screen.getByLabelText(/رقم الجوال/), '91234567');
        await user.click(screen.getByRole('button', { name: /متابعة/ }));
        await user.click(screen.getByRole('button', { name: /متابعة/ }));
        await user.type(screen.getByLabelText(/اسم المتجر/), 'Ribbon');
        await user.click(screen.getByRole('button', { name: /متابعة/ }));
        await user.click(screen.getByRole('button', { name: /تخطّي/ }));
        await user.click(screen.getByRole('button', { name: /تخطّي/ }));
    };

    it('tells the trial on the last step from the server — and asks for no plan', async () => {
        await toLastStep();

        // اسمُ الباقة ومدّتُها في سطرها — من الخادم لا من نصٍّ في الواجهة
        const note = document.querySelector('[data-trial-note]')!.textContent;
        expect(note).toBe('تجربة مجانية لمدة 14 يومًا على الباقة الأساسية');
        expect(note).toContain('الباقة الأساسية');
        expect(note).toContain('14 يومًا');
        // وما لا تشمله التجربة في سطرٍ وحده: نقطةُ البيع، والإضافات
        const without = document.querySelector('[data-trial-without-pos]')!.textContent!;
        expect(without).toContain('نقطة البيع POS');
        expect(without).toContain('الإضافات');
        expect(without).toContain('فريق أبعاد');
        // لا خيارَ باقة: لا زرَّ اختيار ولا قائمة
        expect(screen.queryByRole('radio')).not.toBeInTheDocument();
        expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
    });

    it('says nothing about a trial when no basic plan is confirmed', async () => {
        Object.assign(pageProps, { trial: { days: 14, plan: null } });
        await toLastStep();

        expect(document.querySelector('[data-trial-note]')).toBeNull();
        expect(document.querySelector('[data-trial-without-pos]')).toBeNull();
    });

    it('says the door is closed from the first step when the new policy would not be stamped — and promises no trial', () => {
        Object.assign(pageProps, { closed: true });
        render(<Register />);

        expect(screen.getByRole('alert')).toHaveTextContent('التسجيل غير متاح الآن');
        // لا يُنقل إلى آخر خطوة قبل أن يكتب شيئًا
        expect(screen.getByLabelText(/الاسم/)).toBeInTheDocument();
        expect(document.querySelector('[data-trial-note]')).toBeNull();
        Object.assign(pageProps, { closed: undefined });
    });

    it('shows the closed-door refusal as the reason — not as a field error', () => {
        Object.assign(pageProps, { errors: { signup: 'التسجيل غير متاح الآن. تواصل مع فريق أبعاد وسنفتح حسابك.' } });
        render(<Register />);

        expect(screen.getByRole('alert')).toHaveTextContent('التسجيل غير متاح الآن');
        // وينتقل إلى الخطوة الأخيرة — وهي وحدها التي تُرسِل
        expect(screen.getByText('بيانات الدخول لإدارة متجرك')).toBeInTheDocument();
    });
});
