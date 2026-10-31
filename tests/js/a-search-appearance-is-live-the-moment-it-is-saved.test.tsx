import { act, fireEvent, render, renderHook, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import Seo from '@/Pages/Admin/Website/Seo';
import SimpleSeo from '@/Pages/Admin/Website/SimpleSeo';
import { LengthNote, ReadinessList, SaveState, useSaveFeedback } from '@/Pages/Admin/Website/search/SearchParts';
import SeoSection from '@/Pages/Admin/Website/theme/sections/Seo';
import { pageProps } from './setup';
import { THEME_SITE, themeForm } from './theme-form';

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));

/**
 * «الظهور في البحث» — ما يقرؤه صاحبُ المتجر وهو يكتب، وبعد أن يحفظ.
 *
 * والحفظُ يسري على موقعه المنشور بلا نشر (`StoreSeo::save`، `Published::forBusiness`).
 * فتقول الشاشةُ ذلك قبل أن يضغط، وتقول «غير محفوظ» حين يكون، و«حُفظ» ومتى،
 * وتقول ما تعثّر ولا تمحو ما كتبه — وتقول إنّ «اسمح» إذنٌ لا وعدٌ بالفهرسة.
 */

const LIMITS = { title: 60, desc: 160, titleMin: 15, descMin: 70 };

const READINESS = {
    published: true,
    indexable: true,
    canonical: 'https://app.abaadapp.om/s/wrood',
    sitemap: 'https://app.abaadapp.om/s/wrood/sitemap.xml',
    items: [
        { key: 'published', label: 'الموقع منشور', state: 'pass', detail: 'يفتحه الزائر على عنوانه الآن.', fix: null },
        { key: 'title', label: 'عنوان الموقع في البحث', state: 'warn', detail: 'لم تكتب عنوانًا — يظهر «ورود» وحده.', fix: 'اكتب ما تبيعه وأين، 15 حرفًا فأكثر.' },
        { key: 'robots', label: 'السماح لمحرّكات البحث (robots)', state: 'pass', detail: null, fix: null },
        { key: 'canonical', label: 'العنوان الأصليّ (canonical)', state: 'pass', detail: 'https://app.abaadapp.om/s/wrood', fix: null },
        { key: 'sitemap', label: 'خريطة الموقع (sitemap)', state: 'pass', detail: 'https://app.abaadapp.om/s/wrood/sitemap.xml', fix: null },
    ],
} as const;

const reset = (over: Record<string, unknown> = {}) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {},
        dir: 'rtl',
        auth: { abilities: ['website'], mayActions: [] },
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        site: { id: 1, name: 'ورود', goal: 'store', goal_label: 'متجر', template: 'modern', state: 'published', sells: true, maintenance: false, published_at: null, saved_at: null, changes: false, url: 'https://app.abaadapp.om/s/wrood', tokens: {} },
        seo: { title: 'ورود مسقط · باقات', description: '', image: '', index: true },
        pages: [],
        domain: { custom: null, platform: null, path: 'https://app.abaadapp.om/s/wrood' },
        limits: LIMITS,
        savedAt: '2027-02-10 11:00',
        pending: [],
        readiness: READINESS,
        ...over,
    });
};

describe('شاشةُ الموقع المبنيّ', () => {
    beforeEach(() => reset());

    it('تعرض النتيجةَ كما ستظهر، ووقتَ آخر حفظ، وأنّ الحفظ يسري بلا نشر', () => {
        render(<Seo />);

        expect(screen.getByTestId('seo-preview')).toHaveTextContent('ورود مسقط · باقات');
        expect(screen.getByTestId('seo-preview')).toHaveTextContent('https://app.abaadapp.om/s/wrood');
        expect(screen.getByTestId('seo-saved-at')).toHaveTextContent('آخر حفظ: 2027-02-10 11:00');
        expect(screen.getByTestId('seo-save-state')).toHaveTextContent('بلا نشر');
        expect(screen.queryByTestId('seo-unsaved')).toBeNull();
    });

    /** وما يكتبه يُرى في المعاينة ويُقال «غير محفوظ» قبل أن يخرج */
    it('تقول «تغييرات غير محفوظة» حين يكتب، وتحدّث المعاينة', () => {
        render(<Seo />);

        fireEvent.change(screen.getByPlaceholderText('ورود'), { target: { value: 'ورود مسقط · باقات وهدايا تُوصَّل اليوم' } });

        expect(screen.getByTestId('seo-unsaved')).toHaveTextContent('تغييرات غير محفوظة');
        expect(screen.getByTestId('seo-preview')).toHaveTextContent('ورود مسقط · باقات وهدايا تُوصَّل اليوم');
        expect(screen.getByRole('button', { name: /حفظ/ })).not.toBeDisabled();
    });

    it('ولا يُحفظ ما لم يتغيّر', () => {
        render(<Seo />);

        expect(screen.getByRole('button', { name: /حفظ/ })).toBeDisabled();
    });

    /**
     * وما حُفظ قبل التحديث ولم يُنشر يُعرض ليُستعمل — لا يُطبَّق عنه ولا يُرمى.
     */
    it('تعرض ما حُفظ قبل التحديث ولم يُنشر، وتملأ به الحقول حين يطلب', () => {
        reset({ pending: { title: 'حفظٌ قديمٌ لم يُنشر', index: '0' }, savedAt: null });
        render(<Seo />);

        const notice = screen.getByTestId('seo-pending');
        expect(notice).toHaveTextContent('حفظٌ قديمٌ لم يُنشر');
        expect(notice).toHaveTextContent('مخفيّ عن محرّكات البحث');
        expect(screen.getByTestId('seo-saved-at')).toHaveTextContent('لم تحفظ من هذه الشاشة بعد.');

        fireEvent.click(screen.getByTestId('seo-use-pending'));

        expect(screen.getByTestId('seo-preview')).toHaveTextContent('حفظٌ قديمٌ لم يُنشر');
        expect(screen.getByTestId('seo-unsaved')).toBeInTheDocument();
    });

    it('وتعرض جاهزيّة الفهرسة، وأنّ الإذنَ ليس فهرسة', () => {
        render(<Seo />);

        expect(screen.getByTestId('readiness-title')).toHaveAttribute('data-state', 'warn');
        expect(screen.getByTestId('readiness-sitemap')).toHaveTextContent('https://app.abaadapp.om/s/wrood/sitemap.xml');
        expect(screen.getByTestId('seo-not-a-promise')).toHaveTextContent('لا يعني أنّ Google فهرس موقعك فعلًا');
    });
});

describe('الواجهةُ الخاصّة — بلغتيها', () => {
    beforeEach(() => reset());

    it('تعرض نتيجةً إنجليزيّةً على عنوانها ?lang=en', () => {
        render(
            <SeoSection
                form={themeForm({ store_seo_title_en: 'Ribbon — flowers & gifts in Muscat' })}
                site={THEME_SITE}
                fallback={{ title: 'RIBBON', description: 'محلُّ ورد.' }}
                fallbackEn={{ title: 'RIBBON', description: 'A flower shop.' }}
                limits={LIMITS}
            />,
        );

        const en = screen.getByTestId('seo-preview-en');
        expect(en).toHaveAttribute('dir', 'ltr');
        expect(en).toHaveTextContent('https://ribbon.abaadapp.om?lang=en');
        expect(en).toHaveTextContent('Ribbon — flowers & gifts in Muscat');
        expect(en).toHaveTextContent('A flower shop.');
    });
});

describe('تنبيهُ الطول', () => {
    beforeEach(() => reset());

    it.each([
        ['', 'فارغ — يظهر ما يُحسب من اسم متجرك.', 'warn'],
        ['ورود', 'قصير', 'warn'],
        ['ا'.repeat(61), 'طويل', 'warn'],
        ['ورود مسقط · باقات وهدايا', 'طولٌ مناسب.', 'ok'],
    ])('عنوانٌ «%s»', (value, text, tone) => {
        render(<LengthNote value={value} kind="title" min={15} max={60} testId="note" />);

        expect(screen.getByTestId('note')).toHaveTextContent(text);
        expect(screen.getByTestId('note')).toHaveAttribute('data-tone', tone);
    });

    it('ووصفٌ فارغ يقول إنّ غوغل يختار عنه', () => {
        render(<LengthNote value="" kind="desc" min={70} max={160} testId="note" />);

        expect(screen.getByTestId('note')).toHaveTextContent('غوغل يختار سطرًا من صفحتك');
    });
});

describe('حين يتعثّر الحفظ — وما كُتب باقٍ', () => {
    beforeEach(() => reset());

    it('يقول تعذّر الخادم ويمنع نافذةَ الخطأ من أن تستبدل الشاشة', () => {
        const { result } = renderHook(() => useSaveFeedback());

        let handled: boolean | undefined;
        act(() => {
            handled = result.current.options.onHttpException({ status: 500 });
        });

        expect(handled).toBe(false);
        expect(result.current.failure).toMatch(/لم يُحفظ شيء، وتعديلاتك باقية/);
    });

    it('ويقول انتهاء الجلسة وانقطاع الاتصال بكلامهما', () => {
        const { result } = renderHook(() => useSaveFeedback());

        act(() => {
            result.current.options.onHttpException({ status: 419 });
        });
        expect(result.current.failure).toMatch(/انتهت جلستك/);

        let handled: boolean | undefined;
        act(() => {
            handled = result.current.options.onNetworkError();
        });
        expect(handled).toBe(false);
        expect(result.current.failure).toMatch(/تعذّر الاتصال/);

        // ومحاولةٌ جديدة تمحو الخبرَ القديم
        act(() => result.current.options.onStart());
        expect(result.current.failure).toBeNull();
    });

    it('ويُري الخطأ في مكانه، و«حُفظ» بعد النجاح', () => {
        const { rerender } = render(<SaveState dirty savedAt={null} justSaved={false} failure="تعذّر الحفظ على الخادم — لم يُحفظ شيء، وتعديلاتك باقية. أعد المحاولة." />);

        expect(screen.getByTestId('seo-save-failed')).toHaveTextContent('تعديلاتك باقية');

        rerender(<SaveState dirty={false} savedAt="2027-02-10 12:00" justSaved failure={null} />);

        expect(screen.getByTestId('seo-saved')).toHaveTextContent('حُفظ وطُبّق على موقعك المنشور.');
        expect(screen.getByTestId('seo-saved-at')).toHaveTextContent('2027-02-10 12:00');
    });
});

describe('جاهزيّةُ الفهرسة', () => {
    beforeEach(() => reset());

    it('تقول ما يُصلح تحت ما ينقص، ولا تقوله تحت ما سلم', () => {
        render(<ReadinessList readiness={READINESS as never} />);

        expect(screen.getByTestId('readiness-title')).toHaveTextContent('اكتب ما تبيعه وأين');
        expect(screen.getByTestId('readiness-published')).toHaveAttribute('data-state', 'pass');
        expect(screen.getByTestId('readiness-canonical')).toHaveTextContent('https://app.abaadapp.om/s/wrood');
    });
});

describe('صفحةُ المتجر البسيطة — لها شاشتُها', () => {
    const simple = (over: Record<string, unknown> = {}) =>
        reset({
            site: { name: 'ليلي', url: 'https://app.abaadapp.om/s/lily' },
            seo: { title: '', desc: '', index: true },
            fallback: { title: 'ورود ليلي', description: 'باقاتُ وردٍ وهدايا في مسقط.' },
            pending: [],
            savedAt: null,
            ...over,
        });

    it('تعرض ما يُحسب حين لا يكتب، وتقول «غير محفوظ» حين يكتب', () => {
        simple();
        render(<SimpleSeo />);

        const box = screen.getByTestId('seo-preview');
        expect(box).toHaveTextContent('ورود ليلي');
        expect(box).toHaveTextContent('باقاتُ وردٍ وهدايا في مسقط.');
        expect(box).toHaveTextContent('https://app.abaadapp.om/s/lily');
        expect(screen.getByRole('button', { name: /حفظ/ })).toBeDisabled();

        fireEvent.change(screen.getByLabelText('عنوان متجرك في البحث'), { target: { value: 'ورود ليلي · باقات مسقط' } });

        expect(box).toHaveTextContent('ورود ليلي · باقات مسقط');
        expect(screen.getByTestId('seo-unsaved')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /حفظ/ })).not.toBeDisabled();
    });

    /** والصفحةُ عربيّةٌ وحدها — فلا حقلَ إنجليزيٌّ يُكتب لصفحةٍ لا وجود لها */
    it('بلا حقولٍ إنجليزيّة، وتقول إنّ إطفاء الفهرسة لا يُغلق المتجر', () => {
        simple({ seo: { title: '', desc: '', index: false } });
        render(<SimpleSeo />);

        expect(screen.queryByLabelText('Search title — English')).toBeNull();
        expect(screen.queryByTestId('seo-preview-en')).toBeNull();
        expect(screen.getByTestId('seo-not-closed')).toHaveTextContent('لا يُغلق متجرك');
        expect(screen.getByTestId('seo-readiness')).toBeInTheDocument();
    });

    /** وإخفاءٌ حُفظ قبل أن تقرأه الصفحة يُعرض معلّقًا — لا يُطبَّق حتّى يختاره */
    it('تعرض القيم القديمة معلّقةً، وزرّ «استعمل» يُطفئ الفهرسة معها', () => {
        simple({ pending: { store_seo_title: 'عنوانٌ قديم', store_seo_index: '0' } });
        render(<SimpleSeo />);

        const notice = screen.getByTestId('seo-pending');
        expect(notice).toHaveTextContent('عنوانٌ قديم');
        expect(notice).toHaveTextContent('مخفيّ عن نتائج البحث');
        expect(screen.queryByTestId('seo-not-closed')).toBeNull();

        fireEvent.click(screen.getByTestId('seo-use-pending'));

        expect(screen.getByLabelText('عنوان متجرك في البحث')).toHaveValue('عنوانٌ قديم');
        expect(screen.getByTestId('seo-not-closed')).toBeInTheDocument();
    });
});
