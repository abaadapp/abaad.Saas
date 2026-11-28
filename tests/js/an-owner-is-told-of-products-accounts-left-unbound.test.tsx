import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { UnboundBoutiqueAccountsNotice } from '@/Components/UnboundBoutiqueAccounts';

/**
 * تنبيهُ صاحب النشاط: حساباتُ «المنتجات» التي لم تُربط ببوتيك — اسمٌ لكلٍّ برابطٍ إلى
 * شاشة تعديله، ولا شيءَ حين لا يبقى منها شيء. والقائمةُ من الخادم
 * (`BoutiqueAccount::unbound`، ويحرسه `ABoutiqueAccountIsSentOutWhenItsBindingChangesTest`).
 */
describe('تنبيهُ الحسابات غير المربوطة', () => {
    it('يسمّي كلَّ حسابٍ برابطٍ إلى تعديله، ويقول إنّ شيئًا لا يُربط وحده', () => {
        render(<UnboundBoutiqueAccountsNotice accounts={[{ id: 7, name: 'حسابُ منتجاتٍ قديم' }, { id: 9, name: 'منتجاتٌ ولوحة' }]} />);

        const notice = screen.getByTestId('unbound-boutique-accounts');
        expect(notice).toHaveTextContent('حساباتٌ تفتح أصناف المتجر كلَّها ولم تُربط ببوتيك');
        expect(notice).toHaveTextContent('لا يُربط حسابٌ ولا يُعطَّل تلقائيًّا');
        const first = screen.getByRole('link', { name: 'حسابُ منتجاتٍ قديم' });
        expect(first.getAttribute('href')).toContain('admin.employees.edit');
        expect(first.getAttribute('href')).toContain('7');
        expect(screen.getAllByRole('link')).toHaveLength(2);
        // ولا زرَّ إغلاق: يبقى ما دام الحسابُ كما هو
        expect(screen.queryByRole('button')).toBeNull();
    });

    it('ولا شيءَ بلا حسابات، أو لمن لم تُرسَل له القائمة', () => {
        expect(render(<UnboundBoutiqueAccountsNotice accounts={[]} />).container).toBeEmptyDOMElement();
        expect(render(<UnboundBoutiqueAccountsNotice accounts={null} />).container).toBeEmptyDOMElement();
        expect(render(<UnboundBoutiqueAccountsNotice />).container).toBeEmptyDOMElement();
    });
});
