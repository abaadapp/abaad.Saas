import type { CSSProperties } from 'react';
import type { SiteDocument, Tokens } from './types';

/**
 * رموز التصميم متغيّراتِ CSS — فلا لون مكتوبًا بيده في قسم.
 *
 * لونٌ مكتوبٌ في قسمٍ يبقى كما هو حين يبدّل التاجر ألوانه، فيظهر أزرقُ
 * القالب القديم في زاويةٍ من موقعٍ صار فحميًّا. والقوالب الستّة رسمُها واحد
 * وتتبدّل قيمُها — ولولا ذلك لصار إصلاحُ عطبٍ في بطاقة المنتج ستّةَ إصلاحات.
 */

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
};

/** الخطوط — عربيّةٌ كلّها، فالنظام عربيّ أوّلًا */
export const FONT_STACK: Record<string, string> = {
    system: 'system-ui, -apple-system, "Segoe UI", sans-serif',
    cairo: '"Cairo", system-ui, sans-serif',
    tajawal: '"Tajawal", system-ui, sans-serif',
    almarai: '"Almarai", system-ui, sans-serif',
    'ibm-plex-arabic': '"IBM Plex Sans Arabic", system-ui, sans-serif',
    rubik: '"Rubik", system-ui, sans-serif',
};

/** اسم العائلة عند غوغل — و`system` لا يُطلب أصلًا */
const GOOGLE_FAMILY: Record<string, string> = {
    cairo: 'Cairo:wght@400;600;800',
    tajawal: 'Tajawal:wght@400;700;800',
    almarai: 'Almarai:wght@400;700;800',
    'ibm-plex-arabic': 'IBM+Plex+Sans+Arabic:wght@400;600;700',
    rubik: 'Rubik:wght@400;600;800',
};

/** ما يُطلب من غوغل لهذا الخط — أو null إن كان خط النظام */
export function fontHref(font: string | undefined): string | null {
    const family = GOOGLE_FAMILY[font ?? 'system'];

    return family ? `https://fonts.googleapis.com/css2?family=${family}&display=swap` : null;
}

export function fontStack(font: string | undefined): string {
    return FONT_STACK[font ?? 'system'] ?? FONT_STACK.system;
}

export function tokensOf(doc: Pick<SiteDocument, 'tokens'>): Tokens {
    return { ...FALLBACK_TOKENS, ...(doc.tokens ?? {}) };
}

/**
 * متغيّرات الموقع كلُّها من رموزه.
 *
 * وشكلُ الزرّ ثلاثة رموز لا ثلاثة مكوّنات: الرسم واحد والقيم تتبدّل.
 */
export function cssVars(doc: Pick<SiteDocument, 'tokens' | 'theme'>): CSSProperties {
    const t = tokensOf(doc);
    const button = doc.theme?.button ?? t.button ?? 'solid';

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
        '--w-font': fontStack(doc.theme?.font ?? t.font),
    } as CSSProperties;
}
