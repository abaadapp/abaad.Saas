import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import SectionTabs, { INVENTORY_TABS } from '@/Components/SectionTabs';
import { pageProps } from './setup';

/**
 * تبويبا «نقل المخزون بين الفروع» و«طلبات تزويد الفروع» — للمؤسسات، ولمن له فيهما فعل.
 *
 * سؤالان يجتمعان فوق القسم: هل تفتح الباقةُ القدرة (`planFeatures`)، وهل للموظّف
 * فعلٌ من أفعال عمليات الفروع (`mayActions`). والخادمُ يردّ ٤٠٣ على من لا يملك
 * أحدهما (`CheckPlanFeature`، `BranchTransferController::enter`)، فتبويبٌ يقود
 * إليه يجعل صاحبه يظنّ العطب في النظام.
 */

const ENTERPRISE = {
    enterprise_transfers: true,
    enterprise_replenishment: true,
};

const draw = (planFeatures: Record<string, boolean>, mayActions: string[], abilities: string[] = ['inventory']) => {
    Object.assign(pageProps, {
        translations: {},
        auth: { abilities, mayActions, planFeatures },
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, hosted: [] },
    });

    return render(<SectionTabs tabs={INVENTORY_TABS} current="admin.inventory.index" />);
};

describe('تبويبا عمليات الفروع', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    });

    it('يظهران لموظّفٍ له فعلٌ فيهما في متجرٍ على المؤسسات', () => {
        draw(ENTERPRISE, ['transfer.receive']);

        expect(screen.getByText('نقل المخزون بين الفروع')).toBeInTheDocument();
        expect(screen.getByText('طلبات تزويد الفروع')).toBeInTheDocument();
        // والتبويباتُ القائمة لا تُزاح
        expect(screen.getByText('المنتجات')).toBeInTheDocument();
    });

    it('ولا يظهران في الاحترافيّة ولو ملك الموظّفُ الأفعالَ كلَّها', () => {
        draw({ enterprise_transfers: false, enterprise_replenishment: false, website: true }, ['transfer.create', 'transfer.approve', 'supply.create']);

        expect(screen.queryByText('نقل المخزون بين الفروع')).toBeNull();
        expect(screen.queryByText('طلبات تزويد الفروع')).toBeNull();
        expect(screen.getByText('المنتجات')).toBeInTheDocument();
    });

    /** والمفتاحُ الغائبُ مغلق — لا مفتوحٌ كما تقرأ الشاشةُ القدراتِ القديمة */
    it('ولا يظهران حين لا تصل القدرةُ أصلًا', () => {
        draw({}, ['transfer.create']);

        expect(screen.queryByText('نقل المخزون بين الفروع')).toBeNull();
    });

    it('ولا يظهران لبائعٍ لا فعلَ له فيهما ولو كان المتجرُ على المؤسسات', () => {
        draw(ENTERPRISE, ['order.edit']);

        expect(screen.queryByText('نقل المخزون بين الفروع')).toBeNull();
        expect(screen.queryByText('طلبات تزويد الفروع')).toBeNull();
    });

    it('يظهر النقلُ وحده حين تفتح الباقةُ النقلَ دون التزويد', () => {
        draw({ enterprise_transfers: true, enterprise_replenishment: false }, ['transfer.view']);

        expect(screen.getByText('نقل المخزون بين الفروع')).toBeInTheDocument();
        expect(screen.queryByText('طلبات تزويد الفروع')).toBeNull();
    });
});
