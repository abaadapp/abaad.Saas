import { fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { describe, expect, it, vi } from 'vitest';

import { SeasonAlerts, type SeasonAlert } from '@/Pages/Admin/Seasons/Index';
import { DaysPicker, presetLabel } from '@/Pages/Admin/Seasons/Show';
import { pageProps } from './setup';

const alert = (over: Partial<SeasonAlert> = {}): SeasonAlert => ({
    id: 4,
    seasonId: 3,
    season: 'رمضان 2027',
    message: 'راجع المخزون واطلب من المورّد',
    days: 30,
    startsAt: '2027-03-10',
    dueAt: '2027-02-08T09:00:00+04:00',
    ...over,
});

/**
 * شريطُ التنبيه في صفحة المواسم.
 *
 * ═══ وما يُحرَس ═══
 *
 * أنّ ما حان **يُقرأ** لا يُعدّ فقط: الرأسُ ونصُّ التاجر وبابُ الموسم وزرُّ
 * الإخفاء. وأنّ الإخفاءَ يقصد بابَ قسمه (`seasons.reminders.read`) لا بابَ
 * الجرس العامّ (`notifications.dismiss`) — فالبابان لا يتبادلان إخفاءً.
 *
 * والفارغُ لا شريطَ له: صفحةُ المواسم بلا تنبيهٍ حان تُفتح كما كانت.
 */
describe('شريطُ تنبيه الموسم', () => {
    it('يقول ما بقي ونصَّ التذكير، ويفتح الموسمَ بعينه', () => {
        Object.assign(pageProps, { translations: {} });
        render(<SeasonAlerts alerts={[alert()]} />);

        expect(screen.getByTestId('season-alerts')).toBeInTheDocument();
        expect(screen.getByText('باقي 30 يومًا على موسم رمضان 2027')).toBeInTheDocument();
        expect(screen.getByText('راجع المخزون واطلب من المورّد')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'افتح الموسم' })).toHaveAttribute(
            'href',
            '/admin.seasons.show/3',
        );
    });

    it('وزرُّ الإخفاء يقصد بابَ قسمه لا بابَ الجرس', () => {
        const post = vi.mocked(router.post);
        post.mockClear();
        render(<SeasonAlerts alerts={[alert()]} />);

        fireEvent.click(screen.getByTestId('season-alert-read-4'));

        expect(post).toHaveBeenCalledTimes(1);
        const [url] = post.mock.calls[0];
        expect(url).toBe('/admin.seasons.reminders.read/3/4');
        expect(String(url)).not.toContain('notifications');
    });

    it('وما لم يحن شيءٌ فلا شريط', () => {
        render(<SeasonAlerts alerts={[]} />);
        expect(screen.queryByTestId('season-alerts')).not.toBeInTheDocument();
    });

    /** وكلُّ تنبيهٍ بزرّه: موسمان حانا لا يُخفيان بضغطةٍ واحدة */
    it('وكلُّ موسمٍ بزرِّ إخفائه', () => {
        render(<SeasonAlerts alerts={[alert(), alert({ id: 9, seasonId: 8, season: 'العيد' })]} />);

        expect(screen.getByTestId('season-alert-4')).toBeInTheDocument();
        expect(screen.getByTestId('season-alert-9')).toBeInTheDocument();
        expect(screen.getAllByLabelText('أخفِ التنبيه')).toHaveLength(2);
    });
});

/**
 * مُنتقي المدّة — أربعةُ اختصاراتٍ ومخصّص.
 *
 * والمخصّصُ يُفتح على رقمه لا على صفر: من عنده ٤٥ يومًا يجد حقلَه مفتوحًا
 * بـ٤٥، وإلّا أعاد كتابةَ ما كتبه مرّةً.
 */
describe('مُنتقي مدّة التنبيه', () => {
    const presets = [7, 14, 30, 60];

    it('يرسم الاختصاراتَ الأربعةَ بأسمائها ومعها «مخصص»', () => {
        render(<DaysPicker presets={presets} value="30" onChange={() => {}} />);

        expect(screen.getByTestId('preset-7')).toHaveTextContent('قبل أسبوع');
        expect(screen.getByTestId('preset-14')).toHaveTextContent('قبل أسبوعين');
        expect(screen.getByTestId('preset-30')).toHaveTextContent('قبل شهر');
        expect(screen.getByTestId('preset-60')).toHaveTextContent('قبل شهرين');
        expect(screen.getByTestId('preset-custom')).toBeInTheDocument();
    });

    it('والمختارُ وحدَه مضغوط', () => {
        render(<DaysPicker presets={presets} value="14" onChange={() => {}} />);

        expect(screen.getByTestId('preset-14')).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByTestId('preset-30')).toHaveAttribute('aria-pressed', 'false');
        expect(screen.getByTestId('preset-custom')).toHaveAttribute('aria-pressed', 'false');
    });

    it('وضغطةُ اختصارٍ تردّ عددَ أيّامه', () => {
        const onChange = vi.fn();
        render(<DaysPicker presets={presets} value="30" onChange={onChange} />);

        fireEvent.click(screen.getByTestId('preset-60'));
        expect(onChange).toHaveBeenCalledWith('60');
    });

    it('وعددٌ خارجَ الاختصارات يفتح حقلَه على رقمه', () => {
        render(<DaysPicker presets={presets} value="45" onChange={() => {}} />);

        expect(screen.getByTestId('preset-custom')).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('spinbutton')).toHaveValue(45);
    });

    it('وما خرج عن الأربعة يُقال بالأيّام', () => {
        const t = (k: string, v?: Record<string, string | number>) =>
            Object.entries(v ?? {}).reduce((s, [k2, val]) => s.replaceAll(':' + k2, String(val)), k);

        expect(presetLabel(7, t)).toBe('قبل أسبوع');
        expect(presetLabel(45, t)).toBe('قبل 45 يومًا');
    });
});
