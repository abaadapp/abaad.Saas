import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import SeasonDialog, { type SeasonFields } from '@/Pages/Admin/Seasons/SeasonDialog';

/**
 * «يتكرر كل سنة» — مقبضٌ لم يكن في الشاشة.
 *
 * كان الموسمُ تاريخين ثابتين، فيُعيد التاجرُ إنشاءَ رمضانَ كلَّ عام: أصنافٌ
 * تُربط من جديد، وتذكيراتٌ تُكتب من جديد، وتقريرُ العام الماضي في صفحةٍ
 * أخرى لا تُقارن بهذه.
 *
 * والتقويمُ يُسأل عنه صراحةً: الميلاديُّ يعود في يومه، والهجريُّ يتقدّم نحو
 * أحدَ عشرَ يومًا في السنة الميلاديّة — فخيارٌ واحدٌ لهما يجعل أحدَهما خطأً.
 */

const BASE: SeasonFields = {
    name: 'رمضان',
    name_en: 'Ramadan',
    starts_at: '2026-02-18',
    ends_at: '2026-03-19',
    active: true,
    show_in_pos: true,
    show_on_website: true,
};

const draw = (props: Partial<React.ComponentProps<typeof SeasonDialog>> = {}) =>
    render(<SeasonDialog open onClose={() => {}} {...props} />);

/** مقبضُ التكرار مفتاحٌ لا نصّ — يُضغط بدوره لا باسمه المعروض */
const flipRepeat = () => userEvent.click(screen.getByRole('switch', { name: 'يتكرر كل سنة' }));

/** زرُّ الخيار المختار: في دائرته نقطةٌ سوداء */
const card = (label: string) => screen.getByText(label).closest('button')!;
const picked = (label: string) => card(label).querySelector('.size-2') !== null;

describe('الموسمُ يعود كلَّ سنة', () => {
    it('يعرض مقبضَ «يتكرر كل سنة»', () => {
        draw();

        expect(screen.getByText('يتكرر كل سنة')).toBeInTheDocument();
    });

    /** والأصلُ «لمرّةٍ واحدة»: لا يُفرض التكرارُ على من لم يطلبه */
    it('لا يسأل عن التقويم ما لم يُطلب التكرار', () => {
        draw();

        expect(screen.queryByText('بأيّ تقويم يعود؟')).not.toBeInTheDocument();
    });

    it('يسأل عن التقويم حين يُطلب التكرار', async () => {
        draw();

        await flipRepeat();

        expect(screen.getByText('بأيّ تقويم يعود؟')).toBeInTheDocument();
        expect(screen.getByText('ميلادي')).toBeInTheDocument();
        expect(screen.getByText('هجري')).toBeInTheDocument();
        expect(picked('ميلادي')).toBe(true);
    });

    /** والهجريُّ يقول ما يعنيه: موعدُه يُحسب ولا يُفترض */
    it('ينبّه أنّ الهجريَّ محسوبٌ من أم القرى ويُصحَّح عند الاختلاف', async () => {
        draw();

        await flipRepeat();
        await userEvent.click(screen.getByText('هجري'));

        expect(picked('هجري')).toBe(true);
        expect(screen.getByText(/أم القرى/)).toBeInTheDocument();
    });

    /** وما لا يحسبه الخادمُ لا يُعرض بابًا يُردّ عند الحفظ */
    it('يقفل الهجريَّ حين لا يحسبه الخادم', async () => {
        draw({ hijriAvailable: false });

        await flipRepeat();

        expect((card('هجري') as HTMLButtonElement).disabled).toBe(true);
        expect((card('ميلادي') as HTMLButtonElement).disabled).toBe(false);
    });

    /**
     * وشاشةُ التعديل تُفتح على **المرساة** لا على الدورة المعروضة.
     *
     * صفحةُ الموسم تعرض تواريخَ هذا العام — وهو ما يسأل عنه صاحبُه. ولو فُتح
     * النموذجُ عليها لَأزاح أوّلَ حفظةٍ مرساةَ الموسم إلى السنة الجارية.
     */
    it('يفتح التعديلَ على المرساة لا على دورة هذا العام', () => {
        draw({
            season: {
                ...BASE,
                id: 3,
                repeats: true,
                calendar: 'hijri',
                starts_at: '2027-02-08',
                ends_at: '2027-03-08',
                anchor_starts_at: '2026-02-18',
                anchor_ends_at: '2026-03-19',
            },
        });

        expect((screen.getByLabelText('بداية الموسم') as HTMLInputElement).value).toBe('2026-02-18');
        expect((screen.getByLabelText('نهاية الموسم') as HTMLInputElement).value).toBe('2026-03-19');
    });

    /** ويُفتح على حال الموسم: متكرّرٌ هجريٌّ يُقرأ كما هو */
    it('يفتح الموسمَ المتكرّر على تقويمه', () => {
        draw({ season: { ...BASE, id: 3, repeats: true, calendar: 'hijri' } });

        expect(picked('هجري')).toBe(true);
    });
});
