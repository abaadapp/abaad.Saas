import { act, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CopyButton from '@/Components/CopyButton';

/**
 * زرُّ «انسخ» — واحدٌ في النظام كلّه بعد أن كان ستّة.
 *
 * وثلاثةٌ من الستّة كانت `navigator.clipboard?.writeText(x)` ثمّ
 * `setCopied(true)` في السطر الذي يليه — فتقول «نُسخ» ولو لم يقع نسخ.
 * وأخطرُ مواضعها كلمةُ مرورٍ تُعرض مرّةً واحدة ولا تُقرأ من القاعدة أبدًا:
 * يقرأ المشغّل «نُسخت»، ويغلق النافذة، وتذهب الكلمة.
 */

const write = vi.fn();

/*
 * والترتيبُ مقصود: `userEvent.setup()` يركّب حافظتَه هو على `navigator`،
 * فتركيبُ حافظتِنا قبله يُمحى بلا كلمة وتمرّ الحالةُ الفاشلة خضراء.
 */
function actor(clipboard: () => Promise<void> = () => Promise.resolve()) {
    const user = userEvent.setup();

    write.mockImplementation(clipboard);
    Object.defineProperty(navigator, 'clipboard', {
        value: { writeText: write },
        configurable: true,
    });

    return user;
}

beforeEach(() => {
    write.mockReset();
});

afterEach(() => {
    Reflect.deleteProperty(navigator, 'clipboard');
});

describe('زرُّ النسخ', () => {
    it('ينسخ النصَّ ويقول إنّه نُسخ', async () => {
        const user = actor();
        render(<CopyButton text="INV-000148" label="نسخ" />);

        await user.click(screen.getByRole('button', { name: 'نسخ' }));

        expect(write).toHaveBeenCalledWith('INV-000148');
        expect(await screen.findByRole('button', { name: 'نُسخ' })).toBeInTheDocument();
    });

    /** وكلمةُ المرور «نُسخت» لا «نُسخ» — والقولُ يُملى من موضع الاستعمال */
    it('يقول ما يُملى عليه بعد النسخ', async () => {
        const user = actor();
        render(<CopyButton text="Ab-12-Xy" label="نسخ" done="نُسخت" />);

        await user.click(screen.getByRole('button', { name: 'نسخ' }));

        expect(await screen.findByRole('button', { name: 'نُسخت' })).toBeInTheDocument();
    });

    /** وأيقونةٌ وحدها: من لا يرى الأيقونة يسمع الاسم، ويتبدّل كما تتبدّل */
    it('الأيقونةُ وحدها تحمل اسمًا يُقرأ ويتبدّل', async () => {
        const user = actor();
        render(<CopyButton text="o@abaad.om" variant="ghost" size="icon-sm" />);

        const button = screen.getByRole('button', { name: 'نسخ' });
        expect(button).toHaveAttribute('title', 'نسخ');

        await user.click(button);

        expect(await screen.findByRole('button', { name: 'نُسخ' })).toBeInTheDocument();
    });

    /**
     * ═══ الحارسُ الذي جُمعت الأزرارُ من أجله ═══
     *
     * `navigator.clipboard` غيرُ موجودٍ على أصلٍ غير آمن ومحجوبٌ في بعض
     * المتصفّحات. فلا يُعلَن نجاحٌ لم يقع — والزرُّ يبقى على قوله الأوّل
     * فيعرف من ضغطه أنّ عليه أن يحدّد النصَّ بيده.
     */
    it('لا يقول «نُسخ» حين تُمنع الحافظة', async () => {
        const user = actor(() => Promise.reject(new Error('blocked')));
        render(<CopyButton text="Ab-12-Xy" label="نسخ" done="نُسخت" />);

        await user.click(screen.getByRole('button', { name: 'نسخ' }));

        expect(write).toHaveBeenCalledWith('Ab-12-Xy');
        expect(screen.getByRole('button', { name: 'نسخ' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'نُسخت' })).not.toBeInTheDocument();
    });

    /**
     * وحالةُ الأصل غير الآمن: لا حافظةَ على الكائن أصلًا.
     *
     * وهي الحالةُ التي كانت `?.` تُسكتها: تردّ `undefined` بلا خطأ، فيمضي
     * السطرُ التالي يقول «نُسخت». وبلا هذا الحارس تمرّ إعادتُها خضراء —
     * لأنّ كلَّ اختبارٍ آخر يركّب حافظةً موجودة.
     */
    it('لا يقول «نُسخ» حين لا حافظةَ على الكائن أصلًا', async () => {
        const user = userEvent.setup();
        // بعد المُشغِّل لا قبله: `setup()` يركّب حافظتَه هو
        Reflect.deleteProperty(navigator, 'clipboard');

        render(<CopyButton text="Ab-12-Xy" label="نسخ" done="نُسخت" />);

        await user.click(screen.getByRole('button', { name: 'نسخ' }));

        expect(screen.getByRole('button', { name: 'نسخ' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'نُسخت' })).not.toBeInTheDocument();
    });

    /** ولا يُعلن النسخ ما دام الوعد معلَّقًا — الكتابةُ وعدٌ لا فعلٌ فوريّ */
    it('لا يُعلن النسخ قبل أن يفي الوعد', async () => {
        let release: () => void = () => {};
        const user = actor(() => new Promise<void>((resolve) => { release = resolve; }));
        render(<CopyButton text="INV-000148" label="نسخ" />);

        await user.click(screen.getByRole('button', { name: 'نسخ' }));

        expect(screen.queryByRole('button', { name: 'نُسخ' })).not.toBeInTheDocument();

        release();
        expect(await screen.findByRole('button', { name: 'نُسخ' })).toBeInTheDocument();
    });

    /**
     * ويعود إلى قوله الأوّل بعد لحظة.
     *
     * فعلامةُ الصحّ الباقية تقول «نُسخ» عن ضغطةٍ مضت منذ دقيقة — ومن ضغط
     * ثانيةً بعد أن نسخ غيرَه لا يرى فرقًا يخبره أنّ الثانية وقعت.
     */
    it('يعود إلى «نسخ» بعد مهلته', async () => {
        /*
         * وبـ`fireEvent` لا `userEvent`: مُشغِّلُ المستخدم ينتظر مؤقّتاتٍ
         * حقيقيّة، فيتعلّق مع المزيَّفة ويأخذ معه الاختبارَ الذي يليه.
         */
        vi.useFakeTimers();

        try {
            write.mockImplementation(() => Promise.resolve());
            Object.defineProperty(navigator, 'clipboard', {
                value: { writeText: write },
                configurable: true,
            });

            render(<CopyButton text="INV-000148" label="نسخ" />);

            await act(async () => {
                fireEvent.click(screen.getByRole('button', { name: 'نسخ' }));
            });
            expect(screen.getByRole('button', { name: 'نُسخ' })).toBeInTheDocument();

            await act(async () => {
                vi.advanceTimersByTime(2000);
            });
            expect(screen.getByRole('button', { name: 'نسخ' })).toBeInTheDocument();
        } finally {
            vi.useRealTimers();
        }
    });

    /**
     * وثلاثةُ أزرارٍ متجاورة: يُضيء المضغوطُ وحدَه.
     *
     * وهذا سببُ أنّ الحالة نصٌّ لا رايةٌ منطقيّة — وسببُ رفعِ السطر الجامع
     * الذي كان يقول «نُسخ.» أسفل ثلاثة أزرارٍ بلا أن يقول أيَّها.
     */
    it('لا يُضيء زرٌّ بضغطةِ جاره', async () => {
        const user = actor();
        render(
            <>
                <CopyButton text="المالك" label="نسخ المالك" />
                <CopyButton text="الكاشير" label="نسخ الكاشير" />
            </>,
        );

        await user.click(screen.getByRole('button', { name: 'نسخ المالك' }));

        expect(await screen.findByRole('button', { name: 'نُسخ' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'نسخ الكاشير' })).toBeInTheDocument();
    });
});
