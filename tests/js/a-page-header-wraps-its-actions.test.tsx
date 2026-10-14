import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import PageHeader from '@/Components/PageHeader';

/**
 * أزرارُ الترويسة تلتفّ ولا تمدّ الصفحة.
 *
 * صفحةُ الطلب بأزرارها السبعة كانت تمدّ الصفحةَ كلَّها على الجهاز اللوحيّ
 * (١٠٢٤–١٣٦٦px): صفُّ الأزرار `shrink-0` في سطرٍ واحدٍ مع العنوان لا يلتفّ،
 * فيُقصّ عمودُ «المنتج» وتسمياتُ المجاميع. وjsdom لا يقيس، فالحارسُ هنا على
 * الشرطين اللذين يصنعان الالتفاف — وقياسُ المتصفّح في دليل الـPR.
 */
describe('the page header on a tablet', () => {
    it('lets the actions wrap instead of pushing the page wider', () => {
        render(
            <PageHeader
                title="الطلب ORD-10504"
                actions={Array.from({ length: 7 }, (_, i) => <button key={i}>{'زرّ ' + i}</button>)}
            />,
        );

        const header = document.querySelector('[data-page-header]') as HTMLElement;
        expect(header).not.toBeNull();
        // الترويسةُ تلتفّ: ما لا يتّسع بجوار العنوان ينزل سطرًا
        expect(header.className).toContain('sm:flex-wrap');

        const actions = header.lastElementChild as HTMLElement;
        expect(actions.querySelectorAll('button')).toHaveLength(7);
        // وصفُّ الأزرار يضيق في سطره فتلتفّ أزرارُه — لا عرضَ ثابتًا يدفع الصفحة
        expect(actions.className).toContain('flex-wrap');
        expect(actions.className).toContain('min-w-0');
        expect(actions.className).not.toContain('shrink-0');
    });

    it('draws no actions row when there are none', () => {
        render(<PageHeader title="العملاء" />);

        const header = document.querySelector('[data-page-header]') as HTMLElement;
        expect(header.children).toHaveLength(1);
    });
});
