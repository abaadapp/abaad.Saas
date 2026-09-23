import { render, renderHook, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import useNewArrivals from '@/hooks/useNewArrivals';
import PrepCard from '@/Pages/Admin/Preparation/partials/PrepCard';
import PrepDetails from '@/Pages/Admin/Preparation/partials/PrepDetails';
import { minutesLeft, spanOf, urgencyOf } from '@/Pages/Admin/Preparation/partials/schedule';
import { checkProgress, checklistKeys, type PrepOrder } from '@/Pages/Admin/Preparation/partials/types';

/**
 * لوحةُ التجهيز: الموعدُ يُقرأ بلا تفسير تاريخ، والمربّعاتُ تُعدّ، والوصولُ
 * الجديد يُقال مرّةً.
 *
 * وأثقلُ ما يُحرَس هنا `minutesLeft`: لو فُسِّر التاريخ في المتصفّح لَقرأ من
 * يجهّز «بعد ساعتين» لطلبٍ فات موعدُه — على جهاز طاولةٍ لم يُضبط توقيتُه.
 */

const order = (over: Partial<PrepOrder> = {}): PrepOrder => ({
    number: 'INV-1',
    status: 'قيد التجهيز',
    customer: 'سارة',
    fulfillment: 'delivery',
    scheduled_for: '2026-09-23 14:00',
    scheduled: { date: '2026-09-23', time: '14:00', day: 'today', minutes_left: 120 },
    overdue: false,
    recipient: null,
    recipient_phone: null,
    address: null,
    occasion: null,
    card_message: null,
    sender: null,
    hide_sender: false,
    delivery_notes: null,
    internal_notes: null,
    branch: null,
    items: [{ id: 7, name: 'باقة', qty: 2, note: null, image: null, addons: [] }],
    next: ['جاهز'],
    checks: {},
    ...over,
});

describe('الموعد يُقرأ بلا تفسير تاريخ', () => {
    it('يطرح ما انقضى منذ وصول الحمولة', () => {
        const s = order().scheduled;

        expect(minutesLeft(s, 0)).toBe(120);
        // ربعُ ساعةٍ مرّت على الشاشة — والباقي يَنقص بقدرها
        expect(minutesLeft(s, 15 * 60000)).toBe(105);
    });

    /* شاشةٌ في تبويبٍ مخفيّ لا تستطلع — فالانقضاءُ وحده يقول إنّ الموعد فات */
    it('وشاشةٌ تُركت ساعتين تقلب الباقي إلى تأخير', () => {
        expect(minutesLeft(order().scheduled, 150 * 60000)).toBe(-30);
    });

    it('وبلا موعدٍ لا رقم', () => {
        expect(minutesLeft(null, 0)).toBeNull();
    });

    it('ودرجاتُ الإلحاح ثلاثٌ لا لونان', () => {
        expect(urgencyOf(-1)).toBe('late');
        expect(urgencyOf(0)).toBe('soon');
        expect(urgencyOf(60)).toBe('soon');
        expect(urgencyOf(61)).toBe('later');
        expect(urgencyOf(null)).toBe('later');
    });

    it('والمدّةُ تُفكّ ساعاتٍ ودقائقَ بمفتاحٍ يقول أيَّ الجهتين', () => {
        expect(spanOf(200)).toEqual({ key: 'left', hours: 3, minutes: 20 });
        expect(spanOf(-200)).toEqual({ key: 'late', hours: 3, minutes: 20 });
        expect(spanOf(null)).toBeNull();
    });
});

describe('قائمة التحقّق', () => {
    it('مفاتيحُها من لقطة الطلب: بنودٌ وإضافاتٌ ومهمّتان', () => {
        const o = order({
            items: [
                {
                    id: 7,
                    name: 'باقة',
                    qty: 1,
                    note: null,
                    image: null,
                    addons: [{ id: 3, name: 'دبّ', qty: 1 }],
                },
            ],
        });

        expect(checklistKeys(o)).toEqual(['task:packaging', 'task:instructions', 'item:7', 'addon:3']);
    });

    it('وتعدّ ما أُشّر من الكلّ', () => {
        const o = order({ checks: { 'item:7': { by: 'سالم', at: '2026-09-23 10:00' } } });

        expect(checkProgress(o)).toEqual({ done: 1, total: 3 });
    });

    /* بندٌ يُحذف من الطلب يختفي مربّعُه — ولا يبقى مؤشَّرٌ لما لا وجود له */
    it('وعلامةٌ لمفتاحٍ لم يعد في الطلب لا تُعدّ', () => {
        const o = order({ checks: { 'item:999': { by: 'سالم', at: '2026-09-23 10:00' } } });

        expect(checkProgress(o).done).toBe(0);
    });
});

describe('ما وصل بعد فتح الشاشة', () => {
    it('أوّلُ تحميلٍ ليس وصولًا', () => {
        const { result } = renderHook(({ n }) => useNewArrivals(n, false), {
            initialProps: { n: ['INV-1', 'INV-2'] },
        });

        expect(result.current.fresh).toEqual([]);
    });

    it('وما جاء بعده يُوسَم', () => {
        const { result, rerender } = renderHook(({ n }) => useNewArrivals(n, false), {
            initialProps: { n: ['INV-1'] },
        });

        rerender({ n: ['INV-1', 'INV-2'] });

        expect(result.current.fresh).toEqual(['INV-2']);
    });

    /* المرشّحُ يُبدَّل ذهابًا وإيابًا — والمرئيُّ سابقًا ليس جديدًا */
    it('والعودةُ إلى ما رُئي لا تُعدّ وصولًا', () => {
        const { result, rerender } = renderHook(({ n }) => useNewArrivals(n, false), {
            initialProps: { n: ['INV-1', 'INV-2'] },
        });

        rerender({ n: ['INV-1'] });
        rerender({ n: ['INV-1', 'INV-2'] });

        expect(result.current.fresh).toEqual([]);
    });

    /* بديلٌ لسياق الصوت: jsdom لا يملكه، والمقصودُ عددُ النغمات لا صوتُها */
    const listen = () => {
        const beep = vi.fn();
        vi.stubGlobal(
            'AudioContext',
            class {
                currentTime = 0;
                destination = {};
                resume() {}
                createGain() {
                    return {
                        gain: { setValueAtTime() {}, exponentialRampToValueAtTime() {} },
                        connect: (x: unknown) => x,
                    };
                }
                createOscillator() {
                    beep();

                    return { frequency: { value: 0 }, connect: (x: unknown) => x, start() {}, stop() {} };
                }
            },
        );

        return beep;
    };

    it('والنغمةُ مرّةً للطلب الواحد لا في كلّ استطلاع', () => {
        const beep = listen();
        const { rerender } = renderHook(({ n }) => useNewArrivals(n, true), {
            initialProps: { n: ['INV-1'] },
        });

        rerender({ n: ['INV-1', 'INV-2'] });
        rerender({ n: ['INV-1', 'INV-2'] });
        rerender({ n: ['INV-1', 'INV-2'] });

        expect(beep).toHaveBeenCalledTimes(1);
    });

    /*
     * والمفتاحُ مطفأٌ افتراضيًّا — ومطفأٌ يعني صامتًا.
     *
     * محلٌّ فيه ثلاثُ شاشاتٍ على الطاولة يرنّ ثلاثًا لكلّ طلب، ومن لم يطلب
     * الصوتَ لا يُفرض عليه. والشارةُ الحمراء تبقى تقول إنّ شيئًا وصل.
     */
    it('ولا صوتَ حين يكون المفتاح مطفأً', () => {
        const beep = listen();
        const { result, rerender } = renderHook(({ n }) => useNewArrivals(n, false), {
            initialProps: { n: ['INV-1'] },
        });

        rerender({ n: ['INV-1', 'INV-2'] });

        expect(beep).not.toHaveBeenCalled();
        // والوصولُ يُقال بالشارة وإن صمت الجرس
        expect(result.current.fresh).toEqual(['INV-2']);
    });
});

describe('البطاقة المختصرة', () => {
    const noop = () => {};

    beforeEach(() => {
        vi.unstubAllGlobals();
    });

    it('تقول الإلحاح بأكثر من لون', () => {
        render(<PrepCard order={order()} ageMs={150 * 60000} onOpen={noop} onMove={noop} />);

        expect(screen.getByTestId('prep-card')).toHaveAttribute('data-urgency', 'late');
        // كلمةٌ تُقرأ إلى جانب اللون — لا لونٌ وحده
        expect(screen.getByTestId('prep-span')).toHaveTextContent('متأخّر');
    });

    it('وتفتح التفاصيل ولا تعرضها', () => {
        const open = vi.fn();
        render(
            <PrepCard
                order={order({ card_message: 'كل عام وأنتِ بخير' })}
                ageMs={0}
                onOpen={open}
                onMove={noop}
            />,
        );

        // نصُّ البطاقة خلف الزرّ لا عليه: البطاقةُ تقول «ما التالي» لا «ما فيه»
        expect(screen.queryByText('كل عام وأنتِ بخير')).toBeNull();
        screen.getByTestId('prep-open').click();
        expect(open).toHaveBeenCalledOnce();
    });

    /*
     * وملاحظةُ السطر تُرفع إلى البطاقة حين لا ملاحظةَ داخليّة.
     *
     * البطاقةُ تعرض واحدةً فقط، والترتيبُ مقصود: الداخليّةُ أوّلًا لأنّها ما
     * يكتبه صاحبُ المحلّ لمن يجهّز، ثمّ ملاحظةُ أوّل بندٍ يحملها. وكسرُ هذا
     * الترتيب يُخفي الأهمَّ خلف زرٍّ لا يُضغط في زحمة العمل.
     */
    it('وترفع ملاحظة السطر إلى البطاقة حين لا ملاحظةَ داخليّة', () => {
        render(
            <PrepCard
                order={order({
                    items: [{ id: 7, name: 'باقة', qty: 1, note: 'بلا ورد أحمر', image: null, addons: [] }],
                })}
                ageMs={0}
                onOpen={noop}
                onMove={noop}
            />,
        );

        expect(screen.getByText('بلا ورد أحمر')).toBeInTheDocument();
    });

    it('والداخليّةُ تتقدّمها', () => {
        render(
            <PrepCard
                order={order({
                    internal_notes: 'غلّفها في العلبة الفاخرة',
                    items: [{ id: 7, name: 'باقة', qty: 1, note: 'بلا ورد أحمر', image: null, addons: [] }],
                })}
                ageMs={0}
                onOpen={noop}
                onMove={noop}
            />,
        );

        expect(screen.getByText('غلّفها في العلبة الفاخرة')).toBeInTheDocument();
        // والمزحومةُ تُعدّ ولا تُعرض — «+1» تقول إنّ خلف الزرّ مزيدًا
        expect(screen.queryByText('بلا ورد أحمر')).toBeNull();
        expect(screen.getByText('+1')).toBeInTheDocument();
    });

    it('وتعدّ ما أُشّر', () => {
        render(
            <PrepCard
                order={order({ checks: { 'item:7': { by: 'سالم', at: '2026-09-23 10:00' } } })}
                ageMs={0}
                onOpen={noop}
                onMove={noop}
            />,
        );

        expect(screen.getByTestId('prep-progress')).toHaveTextContent('1/3');
    });

    /* نقلٌ جارٍ: ضغطتان متتاليتان تُرسلان نداءين، والثاني يُردّ بلا معنى */
    it('وأزرارُ النقل تُعطَّل ما دام النقل جاريًا', () => {
        render(<PrepCard order={order()} ageMs={0} busy onOpen={noop} onMove={noop} />);

        expect(screen.getByRole('button', { name: 'جاهز' })).toBeDisabled();
    });
});

describe('نافذة التفاصيل', () => {
    const noop = () => {};
    const timelineUrl = (n: string) => `/timeline/${n}`;
    const deliveryNoteUrl = (n: string) => `/note/${n}`;

    const open = (o: PrepOrder) => (
        <PrepDetails
            order={o}
            gone={false}
            onClose={noop}
            onMove={noop}
            onToggle={noop}
            deliveryNoteUrl={deliveryNoteUrl}
            timelineUrl={timelineUrl}
        />
    );

    /*
     * خطُّ الحال يُجلب مرّةً للطلب الواحد.
     *
     * اللوحة تستطلع كلّ عشرين ثانية وتنبض كلّ نصف دقيقة، فتصل النافذةَ
     * حمولةٌ جديدة في كلّ مرّة — وهي الطلبُ نفسُه بكائنٍ جديد. ولو كان الجلبُ
     * معتمدًا على الكائن لا على رقمه لَانهالت النداءات على الخادم ما دامت
     * النافذة مفتوحة، وهي تبقى مفتوحةً حتى يُجهَّز الطلب.
     */
    it('تجلب سجل الحركة مرّةً للطلب الواحد مهما تكرّر الاستطلاع', async () => {
        const fetcher = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ events: [] }) });
        vi.stubGlobal('fetch', fetcher);

        const { rerender } = render(open(order()));
        // حمولةٌ جديدةٌ للطلب نفسِه — هذا ما يصل مع كلّ استطلاع
        rerender(open(order()));
        rerender(open(order({ status: 'جاهز' })));
        await Promise.resolve();

        expect(fetcher).toHaveBeenCalledTimes(1);
        expect(fetcher).toHaveBeenCalledWith('/timeline/INV-1', expect.anything());
        vi.unstubAllGlobals();
    });

    /* وكلُّ ما كان على البطاقة انتقل إلى هنا — لا حقلَ سقط */
    it('تعرض ما رُفع عن البطاقة: البطاقةَ والملاحظاتِ والمستلِمَ والفرع', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => ({ events: [] }) }));

        render(
            open(
                order({
                    card_message: 'كل عام وأنتِ بخير',
                    sender: 'أحمد',
                    hide_sender: true,
                    internal_notes: 'غلّفها في العلبة الفاخرة',
                    delivery_notes: 'اتصل قبل الوصول',
                    recipient: 'سارة',
                    recipient_phone: '+96890000001',
                    address: 'الخوير',
                    branch: 'الرئيسي',
                    occasion: 'عيد ميلاد',
                }),
            ),
        );

        for (const text of [
            'كل عام وأنتِ بخير',
            'أحمد',
            'غلّفها في العلبة الفاخرة',
            'اتصل قبل الوصول',
            'سارة',
            '+96890000001',
            'الخوير',
            'الرئيسي',
            'عيد ميلاد',
        ]) {
            // `getAllBy` لا `getBy`: نصٌّ قد يقع في عقدتين (الفرعُ في السطر وفي عنوانه)
            expect(screen.getAllByText(text, { exact: false }).length).toBeGreaterThan(0);
        }

        vi.unstubAllGlobals();
    });

    /* ومربّعُ كلِّ بندٍ وإضافةٍ ومهمّة — بمفتاحه لا بترتيبه */
    it('وترسم مربّعًا لكل بندٍ وإضافةٍ ومهمّة', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => ({ events: [] }) }));

        render(
            open(
                order({
                    items: [
                        {
                            id: 7,
                            name: 'باقة',
                            qty: 1,
                            note: null,
                            image: null,
                            addons: [{ id: 3, name: 'دبّ', qty: 1 }],
                        },
                    ],
                }),
            ),
        );

        expect(screen.getByTestId('prep-check-item:7')).not.toBeChecked();
        expect(screen.getByTestId('prep-check-addon:3')).not.toBeChecked();
        expect(screen.getByTestId('prep-check-task:packaging')).not.toBeChecked();
        expect(screen.getByTestId('prep-check-task:instructions')).not.toBeChecked();

        vi.unstubAllGlobals();
    });

    /*
     * وملاحظةُ السطر تُرسم — «بلا ورد أحمر» هي كيفَ يُصنع لا ماذا.
     *
     * وهي أوجبُ ما على الطاولة: الاسمُ يقول ماذا، والملاحظةُ تقول كيف. وكانت
     * تصل الخادمَ ولا تُرسم يومًا — فحُرست منذُها في الطرفين.
     */
    it('وترسم ملاحظة السطر مع بندها', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => ({ events: [] }) }));

        render(
            open(
                order({
                    items: [{ id: 7, name: 'باقة', qty: 1, note: 'بلا ورد أحمر', image: null, addons: [] }],
                }),
            ),
        );

        expect(screen.getByText('بلا ورد أحمر')).toBeInTheDocument();

        vi.unstubAllGlobals();
    });

    /* وما أشّره الزميلُ يُرى مؤشَّرًا باسمه — وهو ثمرةُ التزامن */
    it('وتُظهر من وضع العلامة ومتى', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => ({ events: [] }) }));

        render(open(order({ checks: { 'item:7': { by: 'سالم', at: '2026-09-23 10:00' } } })));

        expect(screen.getByTestId('prep-check-item:7')).toBeChecked();
        expect(screen.getAllByText('سالم', { exact: false }).length).toBeGreaterThan(0);

        vi.unstubAllGlobals();
    });
});
