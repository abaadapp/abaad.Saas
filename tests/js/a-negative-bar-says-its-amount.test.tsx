import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import './setup';

const { default: BarChart } = await import('@/Components/charts/BarChart');

/**
 * صافٍ سالب في «طرق الدفع» يُقال رقمًا — لا يُخفى ولا يُرسم شريطًا مقلوبًا.
 *
 * ═══ العطب الذي وُضع له ═══
 *
 * فواتيرُ العملاء في شهرٍ أُلغيت فيه فاتورةُ شهرٍ مضى صافيها سالب. كان الخادم
 * يُسقطها فتزيد الأشرطةُ على بطاقة «مبيعات الشهر»، ولو وصلت لَدخلت المقسومَ
 * عليه فصارت نسبةُ «نقدي» فوق المئة، وعرضُ شريطها `-x%`.
 *
 * ═══ ما يُحرس ═══
 *
 *   - السالبُ يُكتب برقمه، بلا نسبةٍ وبلا عرض.
 *   - نسبُ الموجب من الموجب وحده، فلا تتجاوز المئة.
 *   - وما لا سالبَ فيه — كلُّ شاشةٍ أخرى تستعمل المكوّن — نسبُه كما كانت.
 */
describe('BarChart — الصافي السالب', () => {
    it('يُكتب السالبُ برقمه بلا نسبةٍ ولا عرض، ونسبةُ الموجب من الموجب وحده', () => {
        render(<BarChart labels={['نقدي', 'فواتير العملاء']} series={[50, -210]} format={(v) => `${v} ر.ع`} />);

        const invoices = screen.getByText('فواتير العملاء').closest('.group') as HTMLElement;
        expect(invoices).toHaveAttribute('data-negative', 'true');
        expect(invoices).toHaveTextContent('-210 ر.ع');
        expect(invoices).not.toHaveTextContent('%');
        const bar = invoices.querySelector('.h-full') as HTMLElement;
        expect(bar.style.width).not.toMatch(/-/);

        const cash = screen.getByText('نقدي').closest('.group') as HTMLElement;
        expect(cash).not.toHaveAttribute('data-negative');
        expect(cash).toHaveTextContent('50 ر.ع(100%)');
    });

    it('وما لا سالبَ فيه نسبُه كما كانت', () => {
        render(<BarChart labels={['نقدي', 'بطاقة', 'تحويل']} series={[3, 1, 0]} />);

        expect(screen.getByText('نقدي').closest('.group')).toHaveTextContent('(75%)');
        expect(screen.getByText('بطاقة').closest('.group')).toHaveTextContent('(25%)');
        expect(screen.getByText('تحويل').closest('.group')).toHaveTextContent('(0%)');
        expect(document.querySelector('[data-negative]')).toBeNull();
    });
});
