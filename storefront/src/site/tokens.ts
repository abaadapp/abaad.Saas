import type { CSSProperties } from 'react';
import { LAYOUT_DEFAULTS, RATIO, WIDTH_PX, layoutOf } from './layout';
import type { Tokens } from './types';

/**
 * رموز التصميم متغيّراتِ CSS — فلا لون مكتوبًا بيده في قسم.
 *
 * لونٌ مكتوبٌ في قسمٍ يبقى كما هو حين يبدّل التاجر ألوانه، فيظهر أزرقُ
 * القالب القديم في زاويةٍ من موقعٍ صار فحميًّا. والقوالبُ رسمُها واحد
 * وتتبدّل قيمُها — ولولا ذلك لصار إصلاحُ عطبٍ في بطاقة المنتج ستّةَ إصلاحات.
 *
 * ومعها رموزُ التخطيط (`layout.ts`): عرضُ المحتوى ونسبةُ صورة المنتج وخطُّ
 * العناوين تخرج متغيّراتٍ كما تخرج الألوان، وما بقي منها يخرج سماتٍ على
 * جذر الموقع (`data-density` و`data-scale`…) لأنّ استعلامات العرض تقرؤها
 * ولا تُكتب في نمطٍ مضمَّن.
 */

/**
 * ما يكفي لاشتقاق تصميمٍ كامل — مستندٌ أو جزءٌ منه.
 *
 * فصفحةُ الصيانة تحمل رموزًا بلا مستند، والغلافُ يُرسم قبل أن يُعرف أثمّة
 * موقعٌ أصلًا. وكلاهما يحتاج الألوان والخطّ، ولا يملك `SiteDocument` كاملًا.
 */
export type DesignSource = { tokens?: Tokens; theme?: Record<string, string> };

/** ما يبدأ به مستندٌ بلا رموز — لا ينهار الرسم لأنّ حقلًا غاب */
export const FALLBACK_TOKENS: Tokens = {
    primary: '#111111',
    background: '#ffffff',
    text: '#111111',
    font: 'system',
    radius: 'medium',
    button: 'solid',
    on_primary: '#ffffff',
    surface: '#f7f7f7',
    border: '#e6e6e6',
    muted: '#6b6b6b',
    radius_px: 12,
    ...LAYOUT_DEFAULTS,
};

/** الخطوط — عربيّةٌ كلّها، فالنظام عربيّ أوّلًا */
export const FONT_STACK: Record<string, string> = {
    system: 'system-ui, -apple-system, "Segoe UI", sans-serif',
    cairo: '"Cairo", system-ui, sans-serif',
    tajawal: '"Tajawal", system-ui, sans-serif',
    almarai: '"Almarai", system-ui, sans-serif',
    'ibm-plex-arabic': '"IBM Plex Sans Arabic", system-ui, sans-serif',
    rubik: '"Rubik", system-ui, sans-serif',
    /*
     * و«أميري» وحده بذيلٍ مرقوم.
     *
     * قالبٌ تحريريٌّ بلا خطٍّ يخالف الباقي ليس تحريريًّا: العنوانُ الكبير
     * بخطٍّ هندسيٍّ يبقى لوحةَ تحكّمٍ مكبَّرة. وأميري خطٌّ نسخيٌّ مفتوح يصلح
     * للعناوين الكبيرة ولا يصلح لنصٍّ طويل — فهو خطُّ عناوين لا خطُّ نصّ.
     */
    amiri: '"Amiri", "Times New Roman", serif',
};

/** اسم العائلة عند غوغل — و`system` لا يُطلب أصلًا */
const GOOGLE_FAMILY: Record<string, string> = {
    cairo: 'Cairo:wght@400;600;800',
    tajawal: 'Tajawal:wght@400;700;800',
    almarai: 'Almarai:wght@400;700;800',
    'ibm-plex-arabic': 'IBM+Plex+Sans+Arabic:wght@400;600;700',
    rubik: 'Rubik:wght@400;600;800',
    amiri: 'Amiri:wght@400;700',
};

/** ما يُطلب من غوغل لهذا الخط — أو null إن كان خط النظام */
export function fontHref(font: string | undefined): string | null {
    const family = GOOGLE_FAMILY[font ?? 'system'];

    return family ? `https://fonts.googleapis.com/css2?family=${family}&display=swap` : null;
}

/**
 * خطوطُ هذا الموقع كلُّها — نصُّه وعناوينه.
 *
 * وقالبٌ يكتب عناوينه بخطٍّ غير خطّ نصّه يحتاج ملفّين لا واحدًا. والمكرّر
 * يسقط: من كان خطُّ عناوينه خطَّ نصّه لا يُطلب له الملفّ مرّتين.
 */
export function fontHrefs(doc: DesignSource): string[] {
    const t = tokensOf(doc);
    const heading = layoutOf({ tokens: t }).heading_font;
    const list = [fontHref(doc.theme?.font ?? t.font), heading ? fontHref(heading) : null];

    return [...new Set(list.filter((href): href is string => href !== null))];
}

export function fontStack(font: string | undefined): string {
    return FONT_STACK[font ?? 'system'] ?? FONT_STACK.system;
}

export function tokensOf(doc: DesignSource): Tokens {
    return { ...FALLBACK_TOKENS, ...(doc.tokens ?? {}) };
}

/**
 * متغيّرات الموقع كلُّها من رموزه.
 *
 * وشكلُ الزرّ ثلاثة رموز لا ثلاثة مكوّنات: الرسم واحد والقيم تتبدّل.
 */
export function cssVars(doc: DesignSource): CSSProperties {
    const t = tokensOf(doc);
    const button = doc.theme?.button ?? t.button ?? 'solid';
    const layout = layoutOf({ tokens: t });
    const body = fontStack(doc.theme?.font ?? t.font);

    return {
        '--w-primary': t.primary,
        '--w-bg': t.background,
        '--w-text': t.text,
        '--w-on-primary': t.on_primary,
        '--w-surface': t.surface,
        '--w-border': t.border,
        '--w-muted': t.muted,
        '--w-radius': `${Math.min(t.radius_px, 28)}px`,
        '--w-btn-bg': button === 'solid' ? t.primary : button === 'soft' ? t.surface : 'transparent',
        '--w-btn-fg': button === 'solid' ? t.on_primary : t.primary,
        '--w-btn-border': button === 'outline' ? t.primary : 'transparent',
        '--w-font': body,
        '--w-heading-font': layout.heading_font ? fontStack(layout.heading_font) : body,
        '--w-content': WIDTH_PX[layout.width],
        '--w-ratio': RATIO[layout.ratio],
        /*
         * وحدُّ البطاقة رمزٌ لا شرطٌ في كلّ بطاقة.
         *
         * «بلا حدٍّ» في القالب التحريريّ، و«ظلٌّ خفيف» في العرضيّ، و«حدٌّ
         * رفيع» في التجاريّ — قيمتان لمتغيّرين، لا فرعان في كلّ موضعٍ يرسم
         * بطاقة. والشفّاف لا الغياب: حدٌّ يظهر ويختفي يُزيح ما حوله بكسلًا.
         */
        '--w-card-border': layout.surface_style === 'bordered' ? 'var(--w-border)' : 'transparent',
        '--w-card-shadow': layout.surface_style === 'raised' ? '0 10px 30px rgba(0,0,0,.08)' : 'none',
    } as CSSProperties;
}

/**
 * سماتُ جذر الموقع — ما تقرؤه الأنماط لا ما يقرؤه الرسم.
 *
 * الكثافةُ والسلّمُ يتبدّلان مع الشاشة، واستعلامُ العرض لا يُكتب في نمطٍ
 * مضمَّن. فيخرجان سمتين يلتقطهما `site.css` في كلّ مقاس.
 */
export function layoutAttrs(doc: DesignSource): Record<string, string> {
    const layout = layoutOf(doc);

    return {
        'data-density': layout.density,
        'data-scale': layout.scale,
        'data-card': layout.card,
        'data-surface': layout.surface_style,
    };
}
