import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { pageProps } from './setup';

const { default: Sidebar } = await import('@/Components/Sidebar');
const { adminPages, visibleTo } = await import('@/lib/pages');

/**
 * الشاشةُ تعرض ما تبيعه الباقة — والخادمُ يحرسه على كلّ حال.
 *
 * الموقعُ وأدواتُ التسويق (التقييمات والكوبونات) قدرتان: باقةٌ لا تبيعهما لا
 * يُرسم لها بابُهما في الشريط ولا في البحث السريع — بابٌ يُعرض ويُردّ بـ403
 * يجعل صاحبه يظنّ العطب في النظام. والولاءُ وواتساب والتطبيقاتُ التكامليّة
 * لكلٍّ قدرتُه. والأساسيّةُ (2026-10-13، الثاني) لا تفتح شيئًا منها.
 */

type Props = Record<string, unknown>;

const ALL = { reports_advanced: true, custom_permissions: true, loyalty: true, whatsapp: true, website: true, marketing_tools: true, integrations: true };
const BASIC = { reports_advanced: false, custom_permissions: false, loyalty: false, whatsapp: false, website: false, marketing_tools: false, integrations: false };

const reset = (planFeatures: Record<string, boolean>) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Props)[k];
    Object.assign(pageProps, {
        translations: {},
        auth: { abilities: ['marketing', 'website', 'settings', 'integrations'], mayActions: [], planFeatures },
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, hosted: [] },
    });
};

const GROWTH = ['الموقع الإلكتروني', 'تقييمات العملاء', 'الكوبونات والعروض', 'التحليلات وفحص الظهور', 'برنامج ولاء', 'إشعارات واتساب', 'التطبيقات التكاملية'];

describe('الشريط الجانبيّ', () => {
    // والشريطُ يسأل `route().current()` ليُضيء بابَه — وصفحةُ واتساب تفتح مجموعةَ التسويق فتُرسم أبناؤها
    const realRoute = globalThis.route;

    beforeEach(() => {
        globalThis.route = ((name?: string) =>
            name === undefined ? { current: () => 'admin.marketing.whatsapp' } : `/${name}`) as never;
    });

    afterEach(() => {
        globalThis.route = realRoute;
    });

    it('الأساسيّة: لا موقعَ ولا أدواتِ تسويق ولا ولاء ولا واتساب ولا تطبيقاتٍ تكامليّة', () => {
        reset(BASIC);
        render(<Sidebar open onClose={() => {}} />);

        for (const label of GROWTH) {
            expect(screen.queryByText(label), label).toBeNull();
        }
    });

    it('الاحترافيّة: كلُّها ظاهرة', () => {
        reset(ALL);
        render(<Sidebar open onClose={() => {}} />);

        for (const label of GROWTH) {
            expect(screen.getAllByText(label).length, label).toBeGreaterThan(0);
        }
    });

    it('والتطبيقاتُ التكامليّة بقدرتها وحدها — لا تأخذ معها الموقعَ ولا واتساب', () => {
        reset({ ...ALL, integrations: false });
        render(<Sidebar open onClose={() => {}} />);

        expect(screen.queryByText('التطبيقات التكاملية')).toBeNull();
        expect(screen.getAllByText('الموقع الإلكتروني').length).toBeGreaterThan(0);
        expect(screen.getByText('إشعارات واتساب')).toBeInTheDocument();
    });

    it('والولاءُ بقدرته وحدها — لا يسقط بسقوط أدوات التسويق', () => {
        // وواتساب مفتوحٌ هنا: الشريطُ يفتح مجموعةَ التسويق من صفحته (`route().current()` أعلاه)
        reset({ ...BASIC, loyalty: true, whatsapp: true });
        render(<Sidebar open onClose={() => {}} />);

        expect(screen.getByText('برنامج ولاء')).toBeInTheDocument();
        expect(screen.queryByText('الكوبونات والعروض')).toBeNull();
    });
});

describe('البحث السريع', () => {
    const labels = (planFeatures: Record<string, boolean>) => {
        reset(planFeatures);

        return visibleTo(adminPages(), pageProps.auth as never).map((p) => `${p.group}:${p.label}`);
    };

    it('لا يقود الأساسيّةَ إلى الموقع — لا من قسمه ولا من بطاقة الإعدادات', () => {
        const shown = labels(BASIC);

        expect(shown.filter((l) => l.endsWith(':الموقع الإلكتروني'))).toEqual([]);
        expect(shown).not.toContain('أدوات التسويق:الكوبونات والعروض');
    });

    it('ويقود الاحترافيّةَ إليه', () => {
        const shown = labels(ALL);

        expect(shown).toContain('الإعدادات:الموقع الإلكتروني');
    });
});
