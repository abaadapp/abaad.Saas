import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import Register from '@/Pages/Auth/Register';
import { pageProps } from './setup';

/**
 * معالجُ فتح المتجر — ستُّ خطواتٍ وحالةٌ واحدة.
 *
 * ═══ ولمَ يُختبر في متصفّح ═══
 *
 * اختباراتُ PHP تصل إلى الطلب الأخير وتقف عنده: أنّ متجرًا يُفتح ومالكًا
 * يُنشأ. وما قبله — أنّ «السابق» لا يمحو ما كُتب، وأنّ الخطوة لا تمرّ بحقلٍ
 * ناقص، وأنّ كلمة المرور **لا تُكتب في التخزين** — لا يُشغّله إلّا متصفّح.
 *
 * وآخرُها أهمُّها: حقلٌ يُضاف إلى النموذج يومًا قد يتسلّل إلى المحفوظ بلا أن
 * ينتبه أحد. فيُحرَس التخزينُ نفسُه لا نيّةُ كاتبه.
 */

const ACTIVITIES = [
    { value: 'محل ورود', label: 'محل ورود', icon: '🌷', hint: 'باقات ومناسبات' },
    { value: 'مطعم', label: 'مطعم', icon: '🍽️', hint: 'أطباق وأقسام' },
];

const TEAM = [
    { value: 'أنا فقط', label: 'أنا فقط' },
    { value: '2–5 موظفين', label: '2–5 موظفين' },
];

beforeEach(() => {
    Object.assign(pageProps, {
        activities: ACTIVITIES,
        teamSizes: TEAM,
        year: 2026,
        errors: {},
    });

    sessionStorage.clear();
});

afterEach(() => sessionStorage.clear());

/** يملأ الخطوة الأولى ويمضي — أربعُ خطواتٍ تسبق كلَّ اختبارٍ لما بعدها */
const firstStep = async (user: ReturnType<typeof userEvent.setup>) => {
    await user.type(screen.getByLabelText(/الاسم/), 'سالم');
    await user.type(screen.getByLabelText(/رقم الجوال/), '91234567');
    await user.click(screen.getByRole('button', { name: /متابعة/ }));
};

describe('معالج التسجيل', () => {
    it('يبدأ بالتعريف بالمستخدم، ولا يمضي بحقلٍ ناقص', async () => {
        const user = userEvent.setup();
        render(<Register />);

        expect(screen.getByText(/خلّنا نتعرّف عليك/)).toBeInTheDocument();

        /* متابعةٌ بلا اسمٍ ولا رقم تقف وتقول لماذا */
        await user.click(screen.getByRole('button', { name: /متابعة/ }));

        expect(screen.getByRole('alert')).toHaveTextContent('أكمل هذه الخطوة للمتابعة.');
        expect(screen.getByText(/خلّنا نتعرّف عليك/)).toBeInTheDocument();
    });

    it('لا يعرض إلّا الأنشطة التي يعرفها الخادم', async () => {
        const user = userEvent.setup();
        render(<Register />);

        await firstStep(user);

        expect(screen.getByText('وش نوع نشاطك؟')).toBeInTheDocument();

        /* والخياراتُ من الحمولة لا من قائمةٍ في الشاشة — فلا نشاطَ وهميّ */
        expect(screen.getAllByRole('radio')).toHaveLength(ACTIVITIES.length);
        expect(screen.getByText('محل ورود')).toBeInTheDocument();
        expect(screen.queryByText('صالون')).not.toBeInTheDocument();
    });

    /*
     * والرجوعُ لا يمحو ما كُتب.
     *
     * معالجٌ يُفرغ الحقولَ عند «السابق» يجعل المستخدم يخاف الزرّ — فيمضي
     * على خطأٍ رآه بدل أن يعود إليه.
     */
    it('يحفظ ما كُتب حين يرجع المستخدم ثمّ يتقدّم', async () => {
        const user = userEvent.setup();
        render(<Register />);

        await firstStep(user);
        await user.click(screen.getByRole('button', { name: /متابعة/ }));

        await user.type(screen.getByLabelText(/اسم المتجر/), 'زهور الخوير');
        await user.click(screen.getByRole('button', { name: /السابق/ }));
        await user.click(screen.getByRole('button', { name: /متابعة/ }));

        expect(screen.getByLabelText(/اسم المتجر/)).toHaveValue('زهور الخوير');

        /* وحتّى أوّل خطوة: الرجوعُ خطوتين لا يمسّ الاسمَ ولا الرقم */
        await user.click(screen.getByRole('button', { name: /السابق/ }));
        await user.click(screen.getByRole('button', { name: /السابق/ }));

        expect(screen.getByLabelText(/الاسم/)).toHaveValue('سالم');
        expect(screen.getByLabelText(/رقم الجوال/)).toHaveValue('91234567');
    });

    /* والاختياريّةُ تُتخطّى صراحةً — لا بتركِ الحقل فارغًا وتخمين النيّة */
    it('يُتيح تخطّي الخطوات الاختياريّة وحدها', async () => {
        const user = userEvent.setup();
        render(<Register />);

        await firstStep(user);

        // النشاطُ واسمُ المتجر ليسا اختياريّين
        expect(screen.queryByRole('button', { name: /تخطّي/ })).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: /متابعة/ }));
        await user.type(screen.getByLabelText(/اسم المتجر/), 'زهور');
        await user.click(screen.getByRole('button', { name: /متابعة/ }));

        // حجمُ الفريق اختياريّ
        expect(screen.getByText('كم شخص يعمل معك؟')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: /تخطّي/ }));

        expect(screen.getByText(/وين موقع متجرك/)).toBeInTheDocument();
    });

    it('يقول شروط كلمة المرور قبل أن يفرضها، ولا يلوّنها قبل الكتابة', async () => {
        const user = userEvent.setup();
        render(<Register />);

        await firstStep(user);
        await user.click(screen.getByRole('button', { name: /متابعة/ }));
        await user.type(screen.getByLabelText(/اسم المتجر/), 'زهور');
        await user.click(screen.getByRole('button', { name: /متابعة/ }));
        await user.click(screen.getByRole('button', { name: /تخطّي/ }));
        await user.click(screen.getByRole('button', { name: /تخطّي/ }));

        expect(screen.getByText('بيانات الدخول لإدارة متجرك')).toBeInTheDocument();

        /* الشروطُ معروضةٌ من أوّل نظرة — لا تظهر بعد الرفض */
        expect(screen.getByText('8 أحرف على الأقل')).toBeInTheDocument();
        expect(screen.getByText('رقم واحد على الأقل')).toBeInTheDocument();

        /* والزرُّ الأخير يقول ماذا يفعل */
        expect(screen.getByRole('button', { name: /ابدأ إدارة متجرك/ })).toBeInTheDocument();
    });

    /*
     * ═══ وكلمةُ المرور لا تُكتب في التخزين إطلاقًا ═══
     *
     * الحفظُ هنا `sessionStorage` — تموت بإغلاق اللسان — ومع ذلك لا تُكتب
     * فيها كلمةُ مرور: حاسوبٌ مشترَكٌ في محلّ، ولسانٌ يبقى مفتوحًا، وأدواتُ
     * المطوّر على بُعد ضغطتين.
     */
    it('لا يكتب كلمة المرور في تخزين المتصفّح', async () => {
        const user = userEvent.setup();
        render(<Register />);

        await firstStep(user);
        await user.click(screen.getByRole('button', { name: /متابعة/ }));
        await user.type(screen.getByLabelText(/اسم المتجر/), 'زهور');
        await user.click(screen.getByRole('button', { name: /متابعة/ }));
        await user.click(screen.getByRole('button', { name: /تخطّي/ }));
        await user.click(screen.getByRole('button', { name: /تخطّي/ }));

        // والحقلُ نفسُه لا زرُّ إظهاره: كلاهما يحمل الاسم
        await user.type(document.getElementById('password') as HTMLInputElement, 'abaad2026');

        const saved = sessionStorage.getItem('abaad.signup.draft') ?? '';

        expect(saved).not.toContain('abaad2026');
        expect(saved).not.toContain('password');

        // وما يُحفظ محفوظٌ فعلًا: الرجوعُ بعد تحديثٍ لا يبدأ من الصفر
        expect(saved).toContain('زهور');
    });

    it('يُظهر تقدّمًا يعكس عدد الخطوات الفعليّ', async () => {
        const user = userEvent.setup();
        render(<Register />);

        const bar = screen.getByRole('progressbar');

        expect(bar).toHaveAttribute('aria-valuenow', '1');
        expect(bar).toHaveAttribute('aria-valuemax', '6');

        await firstStep(user);

        expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '2');
    });
});
