import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import { pageProps } from './setup';

/*
 * «دخول كمستخدم» من لوحة المنصّة: الزرُّ في القائمة وملفّ المستخدم يُرسم لمن
 * يُدخل وحده (`can_enter` من قاعدة الخادم)، ويقصد الحسابَ نفسَه لا متجرَه.
 * والشريطُ الأحمر يقول باسم من، وبأيّ دور، وفي أيّ متجر — وفيه العودة.
 */

vi.mock('@/Layouts/PlatformLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock('@/Components/DataTable', () => ({
    default: ({ rows, columns }: { rows: Array<Record<string, unknown>>; columns: Array<{ key: string; cell?: (r: unknown) => React.ReactNode }> }) => (
        <div>
            {rows.map((row) => (
                <div key={String(row.id)} data-testid={`row-${row.id}`}>
                    {columns.find((c) => c.key === 'actions')?.cell?.(row)}
                </div>
            ))}
        </div>
    ),
}));

const base = { translations: {}, context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } }, auth: { abilities: [], mayActions: [] } };

beforeEach(() => {
    vi.mocked(router.post).mockClear();
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, structuredClone(base));
});

describe('الشريط الأحمر أثناء الدخول', () => {
    it('يقول باسم من وبأيّ دور وفي أيّ متجر، وفيه العودة', async () => {
        Object.assign(pageProps, {
            auth: { ...base.auth, impersonating: true, user: { name: 'منى', role: 'sales', roleLabel: 'مبيعات' } },
            context: { ...base.context, businessName: 'متجر A' },
        });
        const { default: ImpersonationBar } = await import('@/Components/ImpersonationBar');
        render(<ImpersonationBar />);

        expect(screen.getByTestId('impersonation-who').textContent).toContain('تدخل الآن باسم منى (مبيعات) في متجر A');
        expect(screen.getByTestId('impersonation-who').textContent).toContain('يُسجَّل باسمه وباسمك معًا');
        // متجرٌ عاملٌ: لا شارةَ «للعرض فقط»
        expect(screen.queryByTestId('impersonation-limit')).toBeNull();
        await userEvent.click(screen.getByRole('button', { name: /عُد إلى لوحة المنصة/ }));
        expect(router.post).toHaveBeenCalledWith('/impersonate.stop');
    });

    it('يقول «للعرض فقط» وسببه في المتجر الممنوع على صاحبه', async () => {
        Object.assign(pageProps, {
            auth: { ...base.auth, impersonating: true, impersonationLimit: 'المتجر معطَّل', user: { name: 'منى', roleLabel: 'مبيعات' } },
            context: { ...base.context, businessName: 'متجر A' },
        });
        const { default: ImpersonationBar } = await import('@/Components/ImpersonationBar');
        render(<ImpersonationBar />);

        expect(screen.getByTestId('impersonation-limit').textContent).toBe('للعرض فقط — المتجر معطَّل');
    });

    it('لا يُرسم خارج الانتحال', async () => {
        Object.assign(pageProps, { auth: { ...base.auth, impersonating: false, user: { name: 'منى' } } });
        const { default: ImpersonationBar } = await import('@/Components/ImpersonationBar');
        render(<ImpersonationBar />);

        expect(screen.queryByTestId('impersonation-who')).toBeNull();
    });
});

const showProps = (canEnter: boolean) => ({
    user: {
        id: 7, business_id: 3, name: 'منى', email: 'm@x.om', phone: null, business: 'متجر A', business_plan: null,
        role: 'مبيعات', role_key: 'sales', status: 'نشط', last_login: '—', created: '—', can_enter: canEnter,
    },
    activities: [], roles: [], businesses: [], permissions: [], permissions_manual: false,
});

describe('ملفّ المستخدم', () => {
    it('فيه «دخول كمستخدم» لمن يُدخل، ويقصد هذا الحسابَ بعينه', async () => {
        const { default: Show } = await import('@/Pages/Platform/Users/Show');
        Object.assign(pageProps, showProps(true));
        render(<Show />);

        await userEvent.click(screen.getByRole('button', { name: /دخول كمستخدم/ }));
        expect(router.post).toHaveBeenCalledWith('/super-admin.users.impersonate/7');
    });

    it('ولا زرّ لمن لا يُدخل', async () => {
        const { default: Show } = await import('@/Pages/Platform/Users/Show');
        Object.assign(pageProps, showProps(false));
        render(<Show />);

        expect(screen.queryByRole('button', { name: /دخول كمستخدم/ })).toBeNull();
    });
});

describe('قائمة المستخدمين', () => {
    const user = (id: number, canEnter: boolean) => ({
        id, name: `مستخدم ${id}`, email: `u${id}@x.om`, phone: null, business: 'متجر A', role: 'مبيعات', status: 'نشط',
        last_login: '—', deleted: false, blocked: null, can_enter: canEnter,
    });

    it('«دخول كمستخدم» في خيارات من يُدخل وحده، ويقصد صفَّه', async () => {
        const { default: Index } = await import('@/Pages/Platform/Users/Index');
        Object.assign(pageProps, {
            users: [user(11, true), user(12, false)],
            pagination: { current_page: 1, last_page: 1, per_page: 10, total: 2 },
            filters: {}, sorts: [], roles: [], businesses: [],
        });
        render(<Index />);

        const options = (id: number) => screen.getByTestId(`row-${id}`).querySelector('button[aria-label="خيارات"]') as HTMLElement;

        await userEvent.click(options(12));
        expect(screen.queryByRole('menuitem', { name: /دخول كمستخدم/ })).toBeNull();
        await userEvent.keyboard('{Escape}');

        await userEvent.click(options(11));
        await userEvent.click(await screen.findByRole('menuitem', { name: /دخول كمستخدم/ }));
        expect(router.post).toHaveBeenCalledWith('/super-admin.users.impersonate/11');
    });
});
