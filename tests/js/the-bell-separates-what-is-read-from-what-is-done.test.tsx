import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import Topbar from '@/Components/Topbar';
import { pageProps } from './setup';

/**
 * الجرسُ يفصل «قرأتُه» عن «أنجزتُه».
 *
 * ═══ ما يُقاس هنا ولا يُقاس على الخادم ═══
 *
 * الخادمُ يحرس أنّ «تم» لا تُغلق مشكلةً قائمة. وهذه الشاشةُ تحرس ما يراه
 * صاحبُ المحلّ بعد الرفض: **سببٌ مكتوب** إلى جانب الصفّ لا نافذةٌ تقول
 * «تعذّر». وردٌّ غامضٌ يجعله يضغط الزرَّ ثانيةً ويظنّ النظامَ معطوبًا.
 *
 * ويحرس أنّ الشارةَ تعدّ ما عليه الآن: المؤجَّلُ والمنجَزُ خرجا من العمل،
 * فعدُّهما يقول رقمًا لا يقابله شيءٌ يُفعل.
 */
describe('جرسُ الإشعارات القابلةِ للإنجاز', () => {
    const feed = {
        items: [
            { key: 'low-7', text: 'مخزون منخفض: وردٌ أحمر (3 متبقٍ)', url: '/admin/inventory', kind: 'task', resolve: 'auto' },
            { key: 'daily-2026-09-26', text: 'ملخّص اليوم', url: '/admin/dashboard', kind: 'info', resolve: 'manual' },
        ],
        count: 2,
        snoozed: [{ key: 'grn-3', text: 'استلامٌ ينتظر الاعتماد', url: '/admin/inventory', kind: 'task', resolve: 'auto' }],
        done: [],
    };

    /** `at` قاعدةُ الأبواب كما يسلّمها الخادم — وغيابُها يعني لوحةَ التاجر */
    const bell = (at?: string) => {
        Object.assign(pageProps, {
            auth: {
                abilities: ['inventory', 'dashboard'],
                mayActions: [],
                isEmployee: false,
                user: { name: 'المالك', avatar: null, roleLabel: 'مالك', role: 'admin', businessId: 1 },
            },
            context: { website: null, branches: [], branchId: null, branchName: '', currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, currencies: [] },
            notifications: at ? { ...feed, at } : feed,
            reportPages: [],
            locale: 'ar',
            csrf: 'x',
            flash: {},
        });

        const base = globalThis.route as unknown as (...a: unknown[]) => string;
        globalThis.route = ((...a: unknown[]) => (a.length ? base(...a) : { current: () => 'admin.products.index' })) as never;

        return render(<Topbar onMenuClick={() => {}} />);
    };

    /** آخرُ ما طُلب من الشبكة — لنقيس ما أُرسل لا ما ظهر وحده */
    let calls: { url: string; body: unknown }[] = [];

    beforeEach(() => {
        calls = [];
        vi.stubGlobal(
            'fetch',
            vi.fn(async (url: string, init?: RequestInit) => {
                calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : null });

                if (url.includes('/dismiss')) {
                    /* الخادمُ يردّ إخفاءَ الإجراء — والشاشةُ لا تعرض زرَّه أصلًا */
                    return {
                        ok: false,
                        json: async () => ({
                            ok: false,
                            outcome: 'task',
                            reason: 'هذا إجراءٌ يُتابَع لا خبرٌ يُخفى — أنجزه أو أجّله.',
                        }),
                    } as Response;
                }

                if (url.includes('/done')) {
                    return {
                        ok: false,
                        json: async () => ({
                            ok: false,
                            outcome: 'unresolved',
                            reason: 'ما زالت المشكلة قائمة: مخزون منخفض: وردٌ أحمر (3 متبقٍ)',
                        }),
                    } as Response;
                }

                if (url.includes('/history')) {
                    return { ok: true, json: async () => ({ items: [] }) } as Response;
                }

                return { ok: true, json: async () => feed } as Response;
            }),
        );
    });

    afterEach(() => vi.unstubAllGlobals());

    it('وينادي أبوابَ اللوحة التي هو فيها — لا لوحةً واحدةً مفروضة', async () => {
        /*
         * لمدير المنصّة نسخةُ الأبواب في مجموعته: `RequiresBusiness` يسوقه عن
         * أبواب لوحة النشاط، فكانت الثمانيةُ كلُّها تردّه بتحويلةٍ ولا يُرسم
         * له جرسٌ أصلًا. والقاعدةُ تُسلَّم من الخادم في `at` — واختيارُها هنا
         * يجعل المعرفةَ في موضعين يفترقان.
         */
        bell('/super-admin/notifications');
        await userEvent.click(screen.getByRole('button', { name: /الإشعارات/ }));
        await userEvent.click(screen.getByRole('tab', { name: /مكتملة/ }));

        await waitFor(() => expect(calls.some((c) => c.url === '/super-admin/notifications/history')).toBe(true));
        expect(calls.every((c) => !c.url.startsWith('/admin/notifications'))).toBe(true);
    });

    it('وبلا قاعدةٍ يبقى على أبواب لوحة النشاط كما كان', async () => {
        // توافقٌ: الردُّ القديم لا يحمل `at`، فلا ينكسر جرسُ التاجر
        bell();
        await userEvent.click(screen.getByRole('button', { name: /الإشعارات/ }));
        await userEvent.click(screen.getByRole('tab', { name: /مكتملة/ }));

        await waitFor(() => expect(calls.some((c) => c.url === '/admin/notifications/history')).toBe(true));
    });

    it('الشارةُ تعدّ ما يحتاج إجراءً — ولا تعدّ المؤجَّل', async () => {
        bell();
        await userEvent.click(screen.getByRole('button', { name: /الإشعارات/ }));

        /* الصفوفُ ثلاثةٌ في الخادم، والشارةُ تقول اثنين: المؤجَّلُ ليس عملًا اليوم */
        expect(screen.getByRole('tab', { name: /تحتاج إجراء/ })).toHaveTextContent('(2)');
        expect(screen.getByRole('tab', { name: /مؤجلة/ })).toHaveTextContent('(1)');
    });

    it('الخبرُ يُخفى ولا يُنجَز، والإجراءُ يُنجَز ولا يُخفى', async () => {
        bell();
        await userEvent.click(screen.getByRole('button', { name: /الإشعارات/ }));

        /* «تم» و«تأجيل» على الناقص وحدَه، و«حذف» على الخبر وحدَه */
        expect(screen.getAllByRole('button', { name: 'تم' })).toHaveLength(1);
        expect(screen.getAllByRole('button', { name: 'تأجيل' })).toHaveLength(1);
        expect(screen.getAllByTitle('حذف الإشعار')).toHaveLength(1);
    });

    it('ولا زرَّ إخفاءٍ على إجراءٍ يُتابَع — والخبرُ وحدَه يُخفى', async () => {
        /*
         * `dismiss` أقدمُ من حالات الإنجاز، وكان يقبل أيَّ مفتاح: فيُسكت
         * «نفد المخزون» ثلاثين يومًا والرفُّ فارغ، ملتفًّا على حارس «تم».
         * فسُدّ البابُ في الخادم، ورُفع زرُّه عن الإجراءات — فلا يُعرض بابٌ
         * يُردّ كلَّ مرّة.
         */
        bell();
        await userEvent.click(screen.getByRole('button', { name: /الإشعارات/ }));

        const news = screen.getByText('ملخّص اليوم').closest('div.group');
        const task = screen.getByText('مخزون منخفض: وردٌ أحمر (3 متبقٍ)').closest('div.group');

        expect(news?.querySelector('[title="حذف الإشعار"]')).not.toBeNull();
        expect(task?.querySelector('[title="حذف الإشعار"]')).toBeNull();
    });

    it('و«إخفاء الأخبار» لا يَعِد بإسكات ما يحتاج إجراءً', async () => {
        bell();
        await userEvent.click(screen.getByRole('button', { name: /الإشعارات/ }));

        /* الاسمُ يقول ما يفعل: الخادمُ يُبقي الإجراءات، فلا يُقال «حذف الكل» */
        expect(screen.queryByRole('button', { name: 'حذف الكل' })).toBeNull();

        await userEvent.click(screen.getByRole('button', { name: 'إخفاء الأخبار' }));
        await waitFor(() => expect(calls.some((c) => c.url.includes('/clear'))).toBe(true));
    });

    it('ورفضُ «تم» يُكتب سببُه إلى جانب الصفّ لا في نافذةٍ تُنسى', async () => {
        bell();
        await userEvent.click(screen.getByRole('button', { name: /الإشعارات/ }));
        await userEvent.click(screen.getByRole('button', { name: 'تم' }));

        await waitFor(() =>
            expect(screen.getByText(/ما زالت المشكلة قائمة/)).toBeInTheDocument(),
        );

        /* والصفُّ باقٍ: رُفض الإنجازُ فلا يختفي ما لم يُحلّ */
        expect(screen.getByText('مخزون منخفض: وردٌ أحمر (3 متبقٍ)')).toBeInTheDocument();
    });

    it('والتأجيلُ يُرسل مدّةً من المسموح لا رقمًا يخترعه المتصفّح', async () => {
        bell();
        await userEvent.click(screen.getByRole('button', { name: /الإشعارات/ }));
        await userEvent.click(screen.getByRole('button', { name: 'تأجيل' }));
        await userEvent.click(screen.getByRole('button', { name: 'يوم' }));

        const sent = calls.find((c) => c.url.includes('/snooze'));
        expect(sent?.body).toEqual({ key: 'low-7', minutes: 1440 });
    });

    it('وفتحُ الصفّ يُسجَّل قراءةً — لا إنجازًا', async () => {
        bell();
        await userEvent.click(screen.getByRole('button', { name: /الإشعارات/ }));
        /*
         * والنقرةُ بمفتاحِ أمرٍ مضغوط: تُشغّل معالِجَنا ولا تُطلق تنقّلَ
         * Inertia — فلا راوترَ في jsdom يُكمله. والمقصودُ ما يُرسَل عند
         * الفتح لا ما يفعله المتصفّح بعده.
         */
        fireEvent.click(screen.getByText('مخزون منخفض: وردٌ أحمر (3 متبقٍ)'), { metaKey: true });
        await waitFor(() => expect(calls.some((c) => c.url.includes('/notifications/open'))).toBe(true));

        expect(calls.some((c) => c.url.includes('/notifications/open'))).toBe(true);
        expect(calls.some((c) => c.url.includes('/notifications/done'))).toBe(false);
    });

    it('وقائمةُ «تحتاج إجراء» تمرّر ولا تُقصّ', async () => {
        /*
         * ═══ عطبٌ قاسه متصفّحٌ حقيقيٌّ لا jsdom ═══
         *
         * صارت للصفّ أزرارٌ تحته فطال، وستّةٌ منها تبلغ ٦٢٦ بكسل — واللوحةُ
         * `overflow-hidden`. فعلى هاتفٍ ٣٦٠×٦٠٠ كانت الصفوفُ السفلى تُقصّ
         * **ولا تُمرَّر**: تقول الشارةُ ستًّا ولا يبلغ صاحبُ المحلّ آخرَها
         * ليضغط «تم».
         *
         * وjsdom لا تحسب تخطيطًا، فلا تقيس الارتفاع. وهذا الحارسُ يقيس ما
         * تقدر عليه: أنّ الحاويَ ما زال يمرّر. والقياسُ الحقيقيُّ في حزمة
         * Playwright خارج المستودع — وهو مذكورٌ في التقرير.
         */
        bell();
        await userEvent.click(screen.getByRole('button', { name: /الإشعارات/ }));

        const row = screen.getByText('مخزون منخفض: وردٌ أحمر (3 متبقٍ)').closest('div.group');
        const list = row?.parentElement;

        expect(list?.className).toMatch(/overflow-y-auto/);
        expect(list?.className).toMatch(/max-h-/);
    });

    it('وسجلُّ المكتملة لا يُحمَّل إلّا حين يُفتح تبويبُه', async () => {
        bell();
        await userEvent.click(screen.getByRole('button', { name: /الإشعارات/ }));

        expect(calls.some((c) => c.url.includes('/history'))).toBe(false);

        await userEvent.click(screen.getByRole('tab', { name: /مكتملة/ }));

        await waitFor(() => expect(calls.some((c) => c.url.includes('/history'))).toBe(true));
    });
});
