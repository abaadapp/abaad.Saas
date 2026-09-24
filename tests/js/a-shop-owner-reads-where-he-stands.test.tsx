import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import StoreReadinessList, { type ReadinessStep } from '@/Components/StoreReadinessList';
import { pageProps } from './setup';

/**
 * دليلُ تجهيز المتجر — اللازمُ يُفصل عن المستحسن.
 *
 * ═══ ولمَ الفصلُ يُحرَس ═══
 *
 * «بلا عنوانٍ لا يُفتح متجرك» ليست كـ«بلا نبذةٍ يبدو أقلَّ ثقة». وقائمةٌ
 * حمراءُ واحدةٌ تخلطهما تجعل صاحبَها يقرأ الكلَّ تحذيرًا — فلا يقرأ شيئًا،
 * ويبقى العنوانُ الناقص بين عشر ملاحظاتٍ تجميليّة.
 */

const step = (over: Partial<ReadinessStep>): ReadinessStep => ({
    key: 'x', label: 'خطوة', why: 'سبب', done: false, required: true, section: 'website',
    ...over,
});

describe('دليلُ تجهيز المتجر', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
        Object.assign(pageProps, { translations: {} });
    });

    it('يعدّ اللازمَ الناقص وحده — لا المستحسن', () => {
        render(
            <StoreReadinessList
                steps={[
                    step({ key: 'slug', label: 'عنوان متجرك', required: true, done: false }),
                    step({ key: 'pay', label: 'طريقة دفعٍ واحدة', required: true, done: true }),
                    step({ key: 'about', label: 'نبذة «عنّا»', required: false, done: false }),
                    step({ key: 'hero', label: 'صورة الواجهة', required: false, done: false }),
                ]}
            />,
        );

        // واحدةٌ لازمةٌ ناقصة — لا ثلاث
        expect(screen.getByTestId('readiness-summary')).toHaveTextContent('1');
        expect(screen.getByTestId('readiness-summary')).not.toHaveTextContent('3');
    });

    it('ويقول «جاهز» متى تمّ اللازمُ وإن بقي المستحسن', () => {
        render(
            <StoreReadinessList
                steps={[step({ key: 'slug', required: true, done: true }), step({ key: 'about', required: false, done: false })]}
            />,
        );

        expect(screen.getByTestId('readiness-summary')).toHaveTextContent('جاهز');
    });

    it('وسببُ الخطوة يُعرض لما لم يتمّ ويُطوى عمّا تمّ', () => {
        render(
            <StoreReadinessList
                steps={[
                    step({ key: 'slug', label: 'عنوان متجرك', why: 'بلا عنوانٍ لا يُفتح متجرك', required: true, done: false }),
                    step({ key: 'pay', label: 'طريقة دفعٍ واحدة', why: 'بلا طريقةِ دفعٍ يتوقّف القبول', required: true, done: true }),
                ]}
            />,
        );

        expect(screen.getByText(/بلا عنوانٍ لا يُفتح متجرك/)).toBeInTheDocument();
        expect(screen.queryByText(/بلا طريقةِ دفعٍ يتوقّف القبول/)).toBeNull();
    });

    it('وما تمّ يبقى معروضًا — فيرى صاحبُه ما أنجزه لا ما نقص وحده', () => {
        render(<StoreReadinessList steps={[step({ key: 'slug', label: 'عنوان متجرك', required: true, done: true })]} />);

        expect(screen.getByText('عنوان متجرك')).toBeInTheDocument();
    });

    it('ولا عنوانَ «يُحسّنه» بلا مستحسنٍ تحته', () => {
        render(<StoreReadinessList steps={[step({ key: 'slug', required: true, done: true })]} />);

        expect(screen.queryByText('ويُحسّنه')).toBeNull();
    });
});
