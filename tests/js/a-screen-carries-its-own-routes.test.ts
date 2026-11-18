import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { covered, ensureRoutesFor, groupOf, initialGroup, startRouteGroups } from '@/lib/route-groups';

/**
 * الصفحةُ تحمل جدولَ مجموعتها — وما تحتاجه صفحةٌ من غيرها يُضمّ قبل أن تُرسم.
 *
 * ويقابله في الخادم `EveryRouteAScreenCallsIsInItsGroupTest`.
 */

const jsonRes = (status: number, body: unknown) => ({ ok: status < 300, status, json: async () => body }) as Response;

describe('which table a page needs', () => {
    it('reads the folder of the page', () => {
        expect(groupOf('Pos/Index')).toBe('pos');
        expect(groupOf('Auth/Login')).toBe('guest');
        expect(groupOf('Admin/Products/Index')).toBe('merchant');
        expect(groupOf('Profile/Edit')).toBe('merchant');
        expect(groupOf('Platform/Dashboard')).toBe('all');
        expect(groupOf('Unknown/Page')).toBe('all');
    });

    it('takes the group the server wrote, and the whole table when it wrote none', () => {
        const doc = document.implementation.createHTMLDocument('x');
        expect(initialGroup(doc)).toBe('all');
        doc.head.innerHTML = '<meta name="route-group" content="pos">';
        expect(initialGroup(doc)).toBe('pos');
        doc.head.innerHTML = '<meta name="route-group" content="nonsense">';
        expect(initialGroup(doc)).toBe('all');
    });
});

describe('moving to a page of another group without reloading', () => {
    beforeEach(() => {
        (globalThis as { Ziggy?: unknown }).Ziggy = { routes: { 'pos.index': { uri: 'pos' } } };
    });
    afterEach(() => {
        delete (globalThis as { Ziggy?: unknown }).Ziggy;
    });

    it('fetches the dashboard group once and merges it before the page is drawn', async () => {
        startRouteGroups('pos');
        const fetcher = vi.fn(async () => jsonRes(200, { group: 'merchant', routes: { 'admin.dashboard': { uri: 'admin' } } }));

        await ensureRoutesFor('Admin/Dashboard', fetcher as never);
        await ensureRoutesFor('Admin/Products/Index', fetcher as never);
        await ensureRoutesFor('Pos/Orders', fetcher as never);

        expect(fetcher).toHaveBeenCalledTimes(1);
        expect(fetcher.mock.calls[0]).toEqual(['/route-groups/merchant', expect.anything()]);
        const routes = (globalThis as unknown as { Ziggy: { routes: Record<string, unknown> } }).Ziggy.routes;
        expect(Object.keys(routes).sort()).toEqual(['admin.dashboard', 'pos.index']);
        expect(covered('pos')).toBe(true);
    });

    it('never fetches what the loaded table already covers', async () => {
        startRouteGroups('merchant');
        const fetcher = vi.fn();
        await ensureRoutesFor('Pos/Index', fetcher as never);
        startRouteGroups('all');
        await ensureRoutesFor('Auth/Login', fetcher as never);
        expect(fetcher).not.toHaveBeenCalled();
    });

    it('throws when the group cannot be fetched so the page is opened with a full load', async () => {
        startRouteGroups('guest');
        await expect(ensureRoutesFor('Admin/Dashboard', (async () => jsonRes(401, {})) as never)).rejects.toThrow();
        await expect(ensureRoutesFor('Admin/Dashboard', (async () => { throw new TypeError('offline'); }) as never)).rejects.toThrow();
        await expect(ensureRoutesFor('Admin/Dashboard', (async () => jsonRes(200, { group: 'pos', routes: {} })) as never)).rejects.toThrow();
        expect(covered('merchant')).toBe(false);
    });
});
