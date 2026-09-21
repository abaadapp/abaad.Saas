import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { SeasonCard, dateRange, type SeasonRow } from '@/Pages/Admin/Seasons/Index';
import SeasonDialog from '@/Pages/Admin/Seasons/SeasonDialog';
import { pageProps } from './setup';

/**
 * بطاقةُ الموسم تقول ما هو، ونموذجُه لا يقبل نهايةً قبل بدايته من الشاشة.
 */
const season = (over: Partial<SeasonRow> = {}): SeasonRow => ({
    id: 3,
    name: 'رمضان 2027',
    name_en: 'Ramadan 2027',
    label: 'رمضان 2027',
    starts_at: '2027-02-08',
    ends_at: '2027-03-09',
    active: true,
    show_in_pos: true,
    show_on_website: false,
    status: 'upcoming',
    statusLabel: 'قادم',
    daysUntil: 7,
    productsCount: 32,
    remindersCount: 3,
    nextReminder: { at: '2027-01-08T09:00:00+04:00', message: 'الاستعداد للموسم' },
    ...over,
});

describe('بطاقةُ الموسم', () => {
    it('تقول الاسمَ والمدّةَ والحالةَ والعدَّين والتذكيرَ القادم، وتفتح الموسمَ بعينه', () => {
        Object.assign(pageProps, { translations: {} });
        const onEdit = vi.fn();
        render(<SeasonCard season={season()} locale="ar" onEdit={onEdit} />);

        expect(screen.getByText('رمضان 2027')).toBeInTheDocument();
        expect(screen.getByText('قادم')).toBeInTheDocument();
        expect(screen.getByText(':n منتج'.replace(':n', '32'))).toBeInTheDocument();
        expect(screen.getByText(/الاستعداد للموسم/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'فتح' })).toHaveAttribute('href', '/admin.seasons.show/3');

        fireEvent.click(screen.getByRole('button', { name: 'تعديل' }));
        expect(onEdit).toHaveBeenCalledTimes(1);
    });

    it('والمفتاحُ المطفأ يُشطب لا يُخفى', () => {
        render(<SeasonCard season={season()} locale="ar" onEdit={() => {}} />);
        expect(screen.getByText('الموقع الإلكتروني').className).toContain('line-through');
        expect(screen.getByText('نقطة البيع').className).not.toContain('line-through');
    });

    it('والمدّةُ تُكتب بلغة الواجهة', () => {
        expect(dateRange('2027-02-08', '2027-03-09', 'en')).toBe('February 8 — March 9, 2027');
        expect(dateRange('2026-12-20', '2027-01-05', 'en')).toBe('December 20, 2026 — January 5, 2027');
    });
});

describe('نموذجُ الموسم', () => {
    it('حقولُه السبعة مرسومة والمفاتيحُ الثلاثة مضاءةٌ افتراضًا', () => {
        render(<SeasonDialog open onClose={() => {}} />);

        expect(screen.getByLabelText('اسم الموسم')).toBeInTheDocument();
        expect(screen.getByLabelText('بداية الموسم')).toBeInTheDocument();
        expect(screen.getByLabelText('نهاية الموسم')).toBeInTheDocument();
        const switches = screen.getAllByRole('switch');
        expect(switches).toHaveLength(3);
        switches.forEach((s) => expect(s).toHaveAttribute('aria-checked', 'true'));
        expect(screen.getByRole('button', { name: 'إنشاء الموسم' })).toBeInTheDocument();
    });

    it('ونهايةُ الموسم لا تُختار قبل بدايته', () => {
        render(<SeasonDialog open onClose={() => {}} />);
        fireEvent.change(screen.getByLabelText('بداية الموسم'), { target: { value: '2027-02-08' } });
        expect(screen.getByLabelText('نهاية الموسم')).toHaveAttribute('min', '2027-02-08');
    });

    it('وموسمٌ قائمٌ يُفتح للتعديل بقيمه', () => {
        render(<SeasonDialog open onClose={() => {}} season={season({ show_on_website: false })} />);
        expect(screen.getByLabelText('اسم الموسم')).toHaveValue('رمضان 2027');
        expect(screen.getByRole('button', { name: 'حفظ التغييرات' })).toBeInTheDocument();
        expect(screen.getAllByRole('switch')[2]).toHaveAttribute('aria-checked', 'false');
    });
});
