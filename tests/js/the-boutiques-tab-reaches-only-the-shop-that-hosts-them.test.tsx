import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import SectionTabs, { PRODUCT_TABS } from '@/Components/SectionTabs';
import { pageProps } from './setup';

/**
 * تبويبُ «البوتيكات» يبلغ من يُؤويها وحده.
 *
 * ═══ ولمَ سؤالٌ ثالث ═══
 *
 * للشريط سؤالان: القسمُ («أيّ شاشةٍ تفتح») والفعلُ («كم من الضرر تستطيع»).
 * وهذا ثالثٌ: «أهذه الشاشةُ من شأن محلّك أصلًا؟». فمحلٌّ لا يُؤجّر ركنًا
 * لبوتيك لا يُزاد في شريطه تبويبٌ يسأل عمّا لا يعرف — والقسمُ لا يفرّق،
 * لأنّ «المنتجات» يملكها الاثنان.
 */

const draw = (hosted: string[], abilities: string[] = ['products']) => {
    Object.assign(pageProps, {
        translations: {},
        auth: { abilities, mayActions: [] },
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, hosted },
    });

    return render(<SectionTabs tabs={PRODUCT_TABS} current="admin.products.index" />);
};

describe('تبويبُ البوتيكات', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    });

    it('يُعرض لمن يُؤوي بوتيكات', () => {
        draw(['boutiques']);

        expect(screen.getByText('البوتيكات')).toBeInTheDocument();
        // والتبويبان القائمان لا يُزاحان
        expect(screen.getByText('المنتجات')).toBeInTheDocument();
        expect(screen.getByText('المواسم')).toBeInTheDocument();
    });

    it('ولا يُعرض لمن لا يُؤويها', () => {
        draw([]);

        expect(screen.queryByText('البوتيكات')).toBeNull();
        expect(screen.getByText('المنتجات')).toBeInTheDocument();
        expect(screen.getByText('المواسم')).toBeInTheDocument();
    });

    /** والمشتركُ الغائب يُقرأ «لا يُؤوي» — لا يسقط الشريطُ كلُّه */
    it('ولا يسقط الشريط حين لا يصل المفتاح أصلًا', () => {
        Object.assign(pageProps, {
            translations: {},
            auth: { abilities: ['products'], mayActions: [] },
            context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        });

        render(<SectionTabs tabs={PRODUCT_TABS} current="admin.products.index" />);

        expect(screen.queryByText('البوتيكات')).toBeNull();
        expect(screen.getByText('المنتجات')).toBeInTheDocument();
    });

    /**
     * والقسمُ يبقى حارسًا فوق المفتاح.
     *
     * فمن يُؤوي بوتيكاتٍ ولا يملك «المنتجات» — موظّفٌ في المالية مثلًا —
     * لا يرى التبويب. والحارسان يجتمعان ولا يُغني أحدهما عن الآخر.
     */
    it('ومن لا يملك «المنتجات» لا يراه ولو أُوِيت', () => {
        draw(['boutiques'], ['finance']);

        expect(screen.queryByText('البوتيكات')).toBeNull();
        expect(screen.queryByText('المنتجات')).toBeNull();
    });
});
