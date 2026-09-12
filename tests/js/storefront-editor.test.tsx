import { readFileSync } from 'node:fs';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import SitePreview from '@/Pages/Admin/Website/preview/SitePreview';
import type { SiteDocument } from '@/Pages/Admin/Website/preview/types';

/**
 * المعاينةُ هي لوحةُ التحكّم — لا صورةٌ إلى جانبها.
 *
 * صاحبُ المتجر لا يبحث عن اسم القسم في قائمةٍ ليعدّله: يضغطه في موقعه. وهذا
 * الملفّ يحرس الطريق الذي يجعل ذلك ممكنًا — صندوقٌ فوق كلّ قسم يعرف مفتاحه:
 * `slot:header` للترويسة، و`index:2` للقسم الثالث في الصفحة، و`slot:footer`
 * للتذييل.
 *
 * ولمَ يُحرَس؟ لأنّ انكساره صامت: الصناديقُ تُقاس من الشجرة (`data-w-index`
 * وأبناءُ `.w-site`)، فتبديلُ وسمٍ في طبقة الرسم يُسقطها كلَّها بلا خطأ في
 * الطرفية ولا اختبارٍ أحمر — ويبقى الموقعُ يُرسم كما كان، غير أنّ ضغطه لا
 * يفعل شيئًا.
 */

const section = (type: string, data: Record<string, unknown> = {}) => ({
    type,
    visible: true,
    source: null,
    data,
});

const doc = (): SiteDocument =>
    ({
        schema_version: 2,
        version: 2,
        name: 'ورود مسقط',
        goal: 'store',
        template: 'modern',
        theme: {},
        tokens: {
            primary: '#2563eb',
            background: '#ffffff',
            text: '#0f172a',
            font: 'system',
            radius: 'medium',
            button: 'solid',
            on_primary: '#ffffff',
            surface: '#f7f7f7',
            border: '#e6e6e6',
            muted: '#6b6b6b',
            radius_px: 12,
        },
        seo: null,
        maintenance: false,
        maintenance_message: null,
        globals: [
            { ...section('header', { links: [{ label: 'الرئيسية', href: '/' }] }), slot: 'header' },
            { ...section('footer', { copyright: 'كل الحقوق محفوظة' }), slot: 'footer' },
        ],
        pages: [
            {
                key: 'home',
                title: 'الرئيسية',
                slug: '/',
                status: 'published',
                is_home: true,
                removable: false,
                seo: null,
                sections: [
                    section('hero', { title: 'أجمل الورود' }),
                    section('benefits', { title: 'لماذا نحن', items: [] }),
                ],
            },
        ],
        brand: {
            name: 'ورود مسقط',
            logo: null,
            tagline: '',
            phone: '',
            email: '',
            address: '',
            whatsapp: '',
            social: [],
            payments: [],
        },
    }) as unknown as SiteDocument;

const labels = {
    'slot:header': 'الترويسة',
    'index:0': 'الواجهة الرئيسية',
    'index:1': 'لماذا نحن',
    'slot:footer': 'التذييل',
};

describe('الإمساك بالموقع في المعاينة', () => {
    it('لكلّ قسمٍ صندوقٌ يحمل اسمه — والترويسةُ والتذييل معها', () => {
        render(
            <SitePreview doc={doc()} device="desktop" onPick={() => {}} labels={labels} />,
        );

        for (const name of Object.values(labels)) {
            expect(screen.getByRole('button', { name: `عدّل ${name}` })).toBeInTheDocument();
        }
    });

    /**
     * والمفتاحُ الذي يصل اللوحةَ هو مفتاحُ القسم المضغوط بعينه.
     *
     * خطأُ رقمٍ واحد هنا يفتح للتاجر حقولَ قسمٍ غير الذي ضغطه — فيكتب في
     * موضعٍ ويرى التغيير في آخر، ولا شيء يقول له لماذا.
     */
    it('الضغطُ يبلّغ مفتاح القسم لا غيره', async () => {
        const onPick = vi.fn();

        render(<SitePreview doc={doc()} device="desktop" onPick={onPick} labels={labels} />);

        await userEvent.click(screen.getByRole('button', { name: 'عدّل الترويسة' }));
        expect(onPick).toHaveBeenLastCalledWith('slot:header');

        await userEvent.click(screen.getByRole('button', { name: 'عدّل لماذا نحن' }));
        expect(onPick).toHaveBeenLastCalledWith('index:1');

        await userEvent.click(screen.getByRole('button', { name: 'عدّل التذييل' }));
        expect(onPick).toHaveBeenLastCalledWith('slot:footer');
    });

    /**
     * ومعاينةٌ تُرى ولا تُمسك لا صناديقَ فيها — بطاقةُ القالب في شاشة الإنشاء.
     *
     * ويُسأل عن صناديق الإمساك بأسمائها لا عن الأزرار كلِّها: الموقعُ نفسُه
     * فيه أزرار (زرُّ القائمة في الهاتف مثلًا)، وهي ليست من هذا الشأن.
     */
    it('بلا مُستقبِلٍ للضغط لا صناديق', () => {
        render(<SitePreview doc={doc()} device="desktop" labels={labels} />);

        expect(screen.queryAllByRole('button', { name: /^عدّل / })).toHaveLength(0);
    });
});

/**
 * ومساحةُ العمل ليست صفحةَ إعدادات.
 *
 * ما يُحرَس هنا بنيةٌ لا شكل: أنّ المحرّر يدخل من المعاينة (`onPick`)، وأنّ
 * التصميم لوحةٌ فيه لا شاشةٌ ثانية، وأنّ الموقعَ لا يُلفّ ببطاقاتٍ متداخلة
 * تعيده صفحةَ لوحةِ تحكّم. والشكلُ يتغيّر، والبنيةُ إن انفرطت عاد كلُّ شيء
 * كما كان بلا أن ينكسر اختبار.
 */
describe('محرّر الموقع مساحةُ عمل', () => {
    const editor = readFileSync('resources/js/Pages/Admin/Website/Editor.tsx', 'utf8');

    it('يدخل التحريرُ من المعاينة', () => {
        expect(editor).toContain('onPick={pick}');
    });

    it('التصميمُ لوحةٌ في المحرّر لا شاشةٌ ثانية', () => {
        expect(editor).toContain('<DesignPanel');
        /* والشاشةُ المنفصلة حُذفت — ومسارُها يقود إلى هنا */
        expect(() => readFileSync('resources/js/Pages/Admin/Website/Design.tsx', 'utf8')).toThrow();
    });

    /*
     * ولا بطاقاتٌ داخل بطاقات.
     *
     * `Card` هي لبنةُ لوحة التحكّم — عنوانٌ وحدٌّ وظلّ لكلّ كتلة. وأداةُ
     * تصميمٍ مبنيّةٌ منها تُقرأ صفحةَ إعدادات: حدودٌ تقسم المساحة التي يُفترض
     * أن يملأها الموقع.
     */
    it('لا بطاقاتِ لوحةِ تحكّم في مساحة العمل', () => {
        expect(editor).not.toContain('<Card');
    });
});
