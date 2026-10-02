import { cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';

import OrdersIndex from '@/Pages/Admin/Orders/Index';
import type { Order } from '@/types/models';
import { pageProps } from './setup';

/**
 * زرُّ «حذف» في قائمة المبيعات — لصاحب النشاط وحده، وبتأكيد.
 *
 * والخادمُ حارسُ ما وراءه: `TheOwnerDeletesASaleByCancellingItTest`.
 */

const done: Order = {
    id: 'INV-1', customer: 'سالم', employee: 'خالد', branch: 'الخوير',
    items_count: 1, total: 20, payment: 'نقدي', status: 'مكتمل', date: '2027-02-01 11:00',
    channel: 'pos', channel_label: 'نقطة البيع',
};

const cancelled: Order = { ...done, id: 'INV-2', status: 'ملغي' };

const base = globalThis.route as unknown as (...a: unknown[]) => string;
globalThis.route = ((...a: unknown[]) =>
    a.length ? base(...a) : { current: () => 'admin.orders.index' }) as never;

const draw = (mayDelete: boolean) => {
    cleanup();
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {},
        context: {
            currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 },
            currencies: [], branches: [], branchId: null, branchName: '', tier: null, website: null,
        },
        auth: { abilities: ['*'], mayActions: [], isEmployee: false, user: { name: 'سعود', avatar: null, roleLabel: 'مدير نشاط', role: 'admin', businessId: 1 } },
        notifications: null, reportPages: [], locale: 'ar', csrf: 'x', flash: {},
        orders: [done, cancelled],
        pagination: { current_page: 1, last_page: 1, per_page: 10, total: 2, from: 1, to: 2, links: [] },
        filters: {}, sorts: [],
        totalAmount: 20, totalCount: 2, cancelledCount: 1, websiteCount: 0, websiteAmount: 0,
        statusOptions: [], channelOptions: [],
        mayDelete,
    });

    return render(<OrdersIndex />);
};

const rowOf = (id: string) => screen.getByText(id).closest('tr') as HTMLElement;

describe('زرُّ «حذف» البيعة', () => {
    let deleted: unknown[][] = [];

    beforeEach(() => {
        deleted = [];
        vi.spyOn(router, 'delete').mockImplementation(((...a: unknown[]) => {
            deleted.push(a);
        }) as never);
    });

    afterEach(() => vi.restoreAllMocks());

    it('يظهر لصاحب النشاط بجوار «عرض»', () => {
        draw(true);
        const row = within(rowOf('INV-1'));

        expect(row.getByText('عرض')).toBeInTheDocument();
        expect(row.getByTestId('order-delete')).toHaveTextContent('حذف');
    });

    it('ولا يظهر لمن ليس صاحبَ النشاط', () => {
        draw(false);

        expect(screen.queryByTestId('order-delete')).toBeNull();
        expect(within(rowOf('INV-1')).getByText('عرض')).toBeInTheDocument();
    });

    it('ولا يظهر للبيعة الملغاة', () => {
        draw(true);

        expect(within(rowOf('INV-2')).queryByTestId('order-delete')).toBeNull();
    });

    it('يسأل قبل أن يحذف — و«إلغاء» لا يرسل شيئًا', async () => {
        draw(true);
        await userEvent.click(within(rowOf('INV-1')).getByTestId('order-delete'));

        const dialog = await screen.findByRole('dialog');
        expect(dialog).toHaveTextContent('هل تريد حذف هذه البيعة؟ سيُلغى أثرها المالي ويُعاد المخزون.');
        expect(within(dialog).getByRole('button', { name: 'حذف البيعة' })).toBeInTheDocument();

        await userEvent.click(within(dialog).getByRole('button', { name: 'إلغاء' }));
        expect(deleted).toHaveLength(0);
    });

    it('و«حذف البيعة» يرسل DELETE إلى بابها', async () => {
        draw(true);
        await userEvent.click(within(rowOf('INV-1')).getByTestId('order-delete'));

        const dialog = await screen.findByRole('dialog');
        await userEvent.click(within(dialog).getByRole('button', { name: 'حذف البيعة' }));

        expect(deleted).toHaveLength(1);
        expect(deleted[0][0]).toBe(route('admin.orders.destroy', 'INV-1'));
    });
});
