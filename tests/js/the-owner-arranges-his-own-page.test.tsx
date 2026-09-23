import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import StoreImageField from '@/Components/StoreImageField';
import { pageProps } from './setup';

/**
 * حقلُ صورة المتجر: يعرض ما اختير، ويُزال بضغطة.
 *
 * ═══ ولمَ لا يحمل ملفًّا في نموذج المتجر ═══
 *
 * نموذجُ المتجر JSON، وإدخالُ ملفٍّ فيه يقلبه `multipart` كلَّه من أجل
 * حقلين — ودرسُه مكتوبٌ في هذا المستودع مرّتين: `FormData` تكتب `null`
 * نصًّا فارغًا، فيمحو الحفظُ أعمدةً لم تُمسّ (الشعارُ ضاع بها مرّةً).
 *
 * فالرفعُ ببابه، وما يعود رابطٌ يُحفظ نصًّا — وهذا ما يُحرَس هنا.
 */
describe('حقلُ صورة المتجر', () => {
    const draw = (value: string, onChange = () => {}) => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
        Object.assign(pageProps, { translations: {} });

        return render(<StoreImageField label="صورة الواجهة" value={value} onChange={onChange} />);
    };

    it('يعرض الصورة المختارة', () => {
        const { container } = draw('/storage/website/1/hero.jpg');

        const img = container.querySelector('img');
        expect(img).toHaveAttribute('src', '/storage/website/1/hero.jpg');
    });

    /** وبلا صورةٍ لا يُعرض إطارٌ مكسور — علامةٌ تقول «اختر» */
    it('وبلا صورةٍ لا يعرض صورةً مكسورة', () => {
        const { container } = draw('');

        expect(container.querySelector('img')).toBeNull();
    });

    it('ويُزال ما اختير بضغطة', async () => {
        let picked = '/storage/website/1/hero.jpg';
        draw(picked, () => {
            picked = '';
        });

        screen.getByRole('button', { name: /أزل/ }).click();

        expect(picked).toBe('');
    });

    /** ولا زرَّ إزالةٍ لحقلٍ فارغ — زرٌّ لا يفعل شيئًا يُربك */
    it('ولا زرَّ إزالةٍ لحقلٍ فارغ', () => {
        draw('');

        expect(screen.queryByRole('button', { name: /أزل/ })).toBeNull();
    });

    /** والحقلُ يقبل الصور وحدها — الحارسُ في الخادم كذلك */
    it('ويقبل الصور وحدها', () => {
        const { container } = draw('');

        expect(container.querySelector('input[type=file]')).toHaveAttribute('accept', 'image/*');
    });
});
