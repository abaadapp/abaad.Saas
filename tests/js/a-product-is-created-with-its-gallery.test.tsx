import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { router as core } from '@inertiajs/core';
import { router } from '@inertiajs/react';

import { pageProps } from './setup';
import ProductForm from '@/Pages/Admin/Products/partials/ProductForm';
import Gallery, { type GalleryImage } from '@/Pages/Admin/Products/partials/Gallery';

/*
 * والتصغيرُ في المتصفّح يحتاج canvas لا يملكه jsdom — فيُستبدل بما يُعرف
 * ما طُلب منه. وافتراضُه أن يعيد الملفَّ كما هو، كما يفعل حين يتعذّر.
 */
const shrinkMock = vi.hoisted(() => vi.fn(async (f: File, _side?: number) => f));
vi.mock('@/Components/StoreImageField', async (original) => ({
    ...(await original<typeof import('@/Components/StoreImageField')>()),
    shrink: shrinkMock,
}));

/**
 * صورُ المنتج تُختار مع إضافته — حتى خمس، وتُرى وتُرتَّب قبل الحفظ.
 *
 * ═══ ما يُحرس ═══
 *
 *   - شاشةُ الإضافة تقبل عدّةَ صورٍ دفعةً واحدة، وتعرضها قبل الحفظ، وتقول
 *     «٢ / ٥».
 *   - السادسةُ تُردّ قبل الإرسال، وزرُّ الإضافة يختفي عند الخامسة.
 *   - النجمةُ تختار الرئيسية، والحذفُ يُخرج صورةً لم تُحفظ — وما يُرسل هو ما
 *     تراه: الرئيسيةُ في `image` والباقي في `images` بترتيبه.
 *   - السهمان يرتّبان الصور قبل الحفظ وفي التعديل، وما قُدِّم إلى الأولى صار الرئيسية.
 *   - صورةُ الهاتف الثقيلة تُصغَّر في المتصفّح قبل أن تُعرض وتُرسل — والخفيفةُ كما هي.
 *   - الثقيلةُ ومجموعُ الحفظة يُقالان قبل الإرسال — حدودُ الخادم نفسُها.
 *   - المعرضُ في التعديل: صنفٌ بخمسٍ لا يُضاف إليه، وقديمٌ بثمانٍ تُعرض صورُه
 *     كلُّها وتُحذف وتُرقّى ولا يُضاف إليه.
 *
 * وما يحرسه الخادم: `AProductIsCreatedWithItsWholeGalleryTest`.
 */

const LIMITS = { perFile: 2048, batch: 7680 };

const photo = (name: string, kb = 10) => new File([new Uint8Array(kb * 1024)], name, { type: 'image/jpeg' });

let sent: Record<string, unknown>[] = [];
let made = 0;

/*
 * وما أُرسل يُقرأ بأسماء ملفّاته لا بهويّتها: `useForm` ينسخ بياناته نسخًا
 * عميقًا، والنسخةُ ملفٌّ جديدٌ بالمحتوى نفسه والاسم نفسه.
 */
const names = (value: unknown) =>
    (Array.isArray(value) ? value : [value]).map((f) => (f instanceof File ? f.name : f));

beforeEach(() => {
    sent = [];
    made = 0;
    Object.assign(pageProps, { context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } } });
    vi.spyOn(core, 'post').mockImplementation(((_url: string, data: Record<string, unknown>) => {
        sent.push(data);
    }) as never);
    // jsdom لا يصنع روابط معاينة — فيُعطى ما يُعرف به كلُّ رابط
    URL.createObjectURL = vi.fn(() => `blob:draft-${++made}`);
    URL.revokeObjectURL = vi.fn();
    vi.mocked(router.post).mockClear();
    vi.mocked(router.put).mockClear();
    shrinkMock.mockReset();
    shrinkMock.mockImplementation(async (f: File) => f);
});

afterEach(() => vi.restoreAllMocks());

const drawCreate = (limits = LIMITS) => {
    render(<ProductForm categories={[]} currencyLabel="ر.ع" galleryMax={5} galleryLimits={limits} />);
    fireEvent.click(screen.getByRole('tab', { name: 'صور المنتج' }));
};

// والاختيارُ يُنتظر: الثقيلةُ تُصغَّر قبل أن تُعرض (`prepared`)
const pick = async (...files: File[]) => {
    await act(async () => {
        fireEvent.change(screen.getByTestId('gallery-input'), { target: { files } });
    });
};
const tiles = () => screen.queryAllByTestId('gallery-tile');
const shown = () => tiles().map((t) => t.querySelector('img')?.getAttribute('src'));
const count = () => screen.getByTestId('gallery-count').textContent;

const save = async () => {
    fireEvent.click(screen.getByRole('tab', { name: 'المعلومات الأساسية' }));
    fireEvent.change(screen.getByPlaceholderText('مثال: باقة ورد أحمر'), { target: { value: 'باقة الربيع' } });
    fireEvent.click(screen.getByRole('tab', { name: 'التسعير' }));
    fireEvent.change(screen.getAllByPlaceholderText('0.000')[0], { target: { value: '18.5' } });
    await act(async () => {
        fireEvent.submit(document.querySelector('form')!);
    });
};

describe('صورُ المنتج في شاشة الإضافة', () => {
    it('تُختار عدّةُ صورٍ دفعةً واحدة وتُرى قبل الحفظ — والأولى رئيسية', async () => {
        drawCreate();

        expect(count()).toBe('0 / 5');
        expect(screen.getByText('اختر صورة أو أكثر')).toBeInTheDocument();
        expect(screen.queryByText('احفظ المنتج أوّلًا لتضيف صورًا أخرى وتختار الرئيسية بينها.')).toBeNull();

        await pick(photo('a.jpg'), photo('b.jpg'));

        expect(shown()).toEqual(['blob:draft-1', 'blob:draft-2']);
        expect(tiles().map((t) => t.dataset.main)).toEqual(['true', 'false']);
        expect(within(tiles()[0]).getByText('الرئيسية')).toBeInTheDocument();
        expect(count()).toBe('2 / 5');
    });

    it('السادسةُ تُردّ قبل الإرسال — وزرُّ الإضافة يختفي عند الخامسة', async () => {
        drawCreate();

        await pick(...['1', '2', '3', '4', '5', '6'].map((n) => photo(`${n}.jpg`)));
        expect(screen.getByRole('alert')).toHaveTextContent('اخترتَ 6 صور، والمتبقّي 5 من 5.');
        expect(tiles()).toHaveLength(0);

        await pick(photo('1.jpg'), photo('2.jpg'), photo('3.jpg'), photo('4.jpg'));
        await pick(photo('5.jpg'), photo('6.jpg'));
        expect(screen.getByRole('alert')).toHaveTextContent('اخترتَ 2 صور، والمتبقّي 1 من 5.');
        expect(tiles()).toHaveLength(4);

        await pick(photo('5.jpg'));
        expect(tiles()).toHaveLength(5);
        expect(count()).toBe('5 / 5');
        expect(screen.queryByTestId('gallery-input')).toBeNull();
        expect(screen.getByText('بلغتَ الحدّ الأقصى — احذف صورةً لتضيف غيرها.')).toBeInTheDocument();
    });

    it('النجمةُ تختار الرئيسية — وتُرسل في `image` والباقي في `images`', async () => {
        drawCreate();
        await pick(photo('a.jpg'), photo('b.jpg'), photo('c.jpg'));

        fireEvent.click(within(tiles()[2]).getByRole('button', { name: 'اجعلها الرئيسية' }));

        // تبديلٌ كما في المعرض: المختارةُ تصعد، والرئيسيةُ القديمة تنزل مكانها
        expect(shown()).toEqual(['blob:draft-3', 'blob:draft-2', 'blob:draft-1']);
        expect(tiles()[0].dataset.main).toBe('true');

        await save();

        expect(sent).toHaveLength(1);
        expect(names(sent[0].image)).toEqual(['c.jpg']);
        expect(names(sent[0].images)).toEqual(['b.jpg', 'a.jpg']);
    });

    it('صورةٌ تُحذف قبل الحفظ لا تُرسل — ويُطلق رابطُ معاينتها', async () => {
        drawCreate();
        await pick(photo('a.jpg'), photo('b.jpg'), photo('c.jpg'));

        fireEvent.click(within(tiles()[1]).getByRole('button', { name: 'حذف الصورة' }));

        // بلا سؤال: صورةٌ لم تُحفظ تُختار ثانيةً بضغطة
        expect(screen.queryByRole('dialog')).toBeNull();
        expect(shown()).toEqual(['blob:draft-1', 'blob:draft-3']);
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:draft-2');
        expect(count()).toBe('2 / 5');

        await save();

        expect(names(sent[0].image)).toEqual(['a.jpg']);
        expect(names(sent[0].images)).toEqual(['c.jpg']);
    });

    it('حذفُ الرئيسية يُصعد التالية', async () => {
        drawCreate();
        await pick(photo('a.jpg'), photo('b.jpg'));

        fireEvent.click(within(tiles()[0]).getByRole('button', { name: 'حذف الصورة' }));
        await save();

        expect(names(sent[0].image)).toEqual(['b.jpg']);
        expect(sent[0].image).toBeInstanceOf(File);
        expect(sent[0].images).toEqual([]);
    });

    it('التنقّلُ بين الأقسام لا يُفقد ما اختير', async () => {
        drawCreate();
        await pick(photo('a.jpg'), photo('b.jpg'));

        fireEvent.click(screen.getByRole('tab', { name: 'التسعير' }));
        fireEvent.click(screen.getByRole('tab', { name: 'صور المنتج' }));

        expect(shown()).toEqual(['blob:draft-1', 'blob:draft-2']);
        expect(URL.revokeObjectURL).not.toHaveBeenCalled();
    });

    it('الثقيلةُ ومجموعُ الحفظة يُقالان قبل الإرسال', async () => {
        drawCreate({ perFile: 100, batch: 250 });

        await pick(photo('big.jpg', 150));
        expect(screen.getByRole('alert')).toHaveTextContent('صورةٌ أثقل من 0.1 ميغابايت لا تُرفع: big.jpg');

        await pick(photo('a.jpg', 90), photo('b.jpg', 90));
        expect(tiles()).toHaveLength(2);
        expect(screen.queryByRole('alert')).toBeNull();

        // الثالثةُ تُقاس مع ما اختير قبلها: الحفظةُ واحدة
        await pick(photo('c.jpg', 90));
        expect(screen.getByRole('alert')).toHaveTextContent(
            'مجموع الصور 0.3 ميغابايت، وأقصى ما يُحفظ مرّةً واحدة 0.2 — احذف صورةً أو اختر أخفّ منها، أو أضف الباقي بعد الحفظ.',
        );
        expect(tiles()).toHaveLength(2);
    });

    it('السهمان يرتّبان قبل الحفظ — وما قُدِّم إلى الأولى صار الرئيسية', async () => {
        drawCreate();
        await pick(photo('a.jpg'), photo('b.jpg'), photo('c.jpg'));

        // الأولى لا تتقدّم، والأخيرة لا تتأخّر
        expect(within(tiles()[0]).queryByRole('button', { name: 'قدّمها' })).toBeNull();
        expect(within(tiles()[2]).queryByRole('button', { name: 'أخّرها' })).toBeNull();

        fireEvent.click(within(tiles()[0]).getByRole('button', { name: 'أخّرها' }));
        expect(shown()).toEqual(['blob:draft-2', 'blob:draft-1', 'blob:draft-3']);
        expect(tiles()[0].dataset.main).toBe('true');

        fireEvent.click(within(tiles()[2]).getByRole('button', { name: 'قدّمها' }));
        expect(shown()).toEqual(['blob:draft-2', 'blob:draft-3', 'blob:draft-1']);

        await save();

        expect(names(sent[0].image)).toEqual(['b.jpg']);
        expect(names(sent[0].images)).toEqual(['c.jpg', 'a.jpg']);
    });

    it('صورةُ الهاتف الثقيلة تُصغَّر قبل أن تُعرض وتُرسل — والخفيفةُ كما هي', async () => {
        // حدُّ الإنتاج: ملفٌّ ٢ ميغا وطلبٌ ٧٫٥ — فنصيبُ الصورة من خمسٍ ١٫٥
        drawCreate({ perFile: 2048, batch: 7680 });
        shrinkMock.mockImplementation(async (f: File) =>
            new File([new Uint8Array(300 * 1024)], f.name.replace(/\.\w+$/, '.jpg'), { type: 'image/jpeg' }));

        await pick(photo('phone.jpg', 3500), photo('mid.jpg', 1700), photo('light.jpg', 500));

        // الثقيلةُ على الحدّ، والتي دونه وفوق نصيبها — لا الخفيفة
        expect(shrinkMock.mock.calls.map(([f, side]) => [f.name, side])).toEqual([['phone.jpg', 1600], ['mid.jpg', 1600]]);
        expect(screen.queryByRole('alert')).toBeNull();
        expect(tiles()).toHaveLength(3);

        await save();

        expect(names(sent[0].image)).toEqual(['phone.jpg']);
        expect((sent[0].image as File).size).toBe(300 * 1024);
        expect((sent[0].images as File[]).map((f) => f.size)).toEqual([300 * 1024, 500 * 1024]);
    });

    it('بلا صورٍ يُحفظ المنتجُ بلا صورة', async () => {
        drawCreate();
        await save();

        expect(sent[0].image).toBeNull();
        expect(sent[0].images).toEqual([]);
    });
});

/* ═════════════ المعرضُ في التعديل ═════════════ */

const stored = (n: number): GalleryImage[] =>
    Array.from({ length: n }, (_, i) => ({ id: i === 0 ? null : i, url: `/storage/products/${i}.webp`, main: i === 0 }));

describe('المعرضُ في التعديل', () => {
    const draw = (images: GalleryImage[]) =>
        render(<Gallery productId={9} images={images} max={5} limits={LIMITS} />);

    it('صنفٌ بخمسٍ لا يُضاف إليه — «٥ / ٥»', async () => {
        draw(stored(5));

        expect(count()).toBe('5 / 5');
        expect(screen.queryByTestId('gallery-input')).toBeNull();
        expect(screen.getByText('بلغتَ الحدّ الأقصى — احذف صورةً لتضيف غيرها.')).toBeInTheDocument();
    });

    it('صنفٌ قديمٌ بثمانٍ تُعرض صورُه كلُّها، وتُحذف وتُرقّى، ولا يُضاف إليه', async () => {
        draw(stored(8));

        expect(tiles()).toHaveLength(8);
        expect(count()).toBe('8 / 5');
        expect(screen.queryByTestId('gallery-input')).toBeNull();
        expect(screen.getByText(
            'لهذا المنتج 8 صور، أكثر من الحدّ الحالي (5). تبقى كلّها كما هي، ولا تُضاف صورةٌ جديدة حتى تحذف ما يُنزل عددها دون الحدّ.',
        )).toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: 'حذف الصورة' })).toHaveLength(8);
        expect(screen.getAllByRole('button', { name: 'اجعلها الرئيسية' })).toHaveLength(7);

        fireEvent.click(within(tiles()[3]).getByRole('button', { name: 'اجعلها الرئيسية' }));
        expect(router.post).toHaveBeenCalledWith('/admin.products.images.promote/9/3', {}, expect.anything());
    });

    it('السهمان يرتّبان المعرضَ بعد الرئيسية — والرئيسيةُ بالنجمة وحدها', async () => {
        draw(stored(4));

        // الرئيسيةُ بلا سهمين، وأوّلُ المعرض لا يتقدّم إليها
        expect(within(tiles()[0]).queryByRole('button', { name: 'قدّمها' })).toBeNull();
        expect(within(tiles()[0]).queryByRole('button', { name: 'أخّرها' })).toBeNull();
        expect(within(tiles()[1]).queryByRole('button', { name: 'قدّمها' })).toBeNull();
        expect(within(tiles()[3]).queryByRole('button', { name: 'أخّرها' })).toBeNull();

        fireEvent.click(within(tiles()[1]).getByRole('button', { name: 'أخّرها' }));
        expect(router.put).toHaveBeenCalledWith('/admin.products.images.order/9', { ids: [2, 1, 3] }, expect.anything());

        // والأزرارُ تنتظر حتى يعود الطلب — فلا يُرسل ترتيبٌ فوق ترتيبٍ لم يُحفظ
        expect(within(tiles()[3]).getByRole('button', { name: 'قدّمها' })).toBeDisabled();

        cleanup();
        draw(stored(4));
        fireEvent.click(within(tiles()[3]).getByRole('button', { name: 'قدّمها' }));
        expect(vi.mocked(router.put).mock.calls[1][1]).toEqual({ ids: [1, 3, 2] });
    });

    it('صورةُ الهاتف الثقيلة تُصغَّر قبل أن تُرفع إلى المعرض', async () => {
        draw(stored(2));
        shrinkMock.mockImplementation(async (f: File) => new File([new Uint8Array(200 * 1024)], f.name, { type: 'image/jpeg' }));

        await pick(photo('phone.jpg', 4000));

        expect(shrinkMock).toHaveBeenCalledTimes(1);
        const data = vi.mocked(router.post).mock.calls[0][1] as FormData;
        expect((data.getAll('images[]') as File[]).map((f) => f.size)).toEqual([200 * 1024]);
    });

    it('الدفعةُ التي تتجاوز السقف تُردّ قبل الإرسال', async () => {
        draw(stored(3));

        await pick(photo('x.jpg'), photo('y.jpg'), photo('z.jpg'));
        expect(screen.getByRole('alert')).toHaveTextContent('اخترتَ 3 صور، والمتبقّي 2 من 5.');
        expect(router.post).not.toHaveBeenCalled();

        await pick(photo('x.jpg'), photo('y.jpg'));
        expect(router.post).toHaveBeenCalledTimes(1);
        expect(vi.mocked(router.post).mock.calls[0][0]).toBe('/admin.products.images.store/9');
    });
});
