import type { DocSection } from './types';

/**
 * قراءةُ حقلٍ من بيانات قسم — بلا انهيارٍ لأنّ حقلًا غاب.
 *
 * بيانات القسم مصفوفةٌ حرّة (`json`)، ونسخةٌ نُشرت قبل أن يُضاف حقلٌ لا
 * تحمله. فكلّ قراءةٍ لها بديلُها، والصفحةُ تُرسم بما وجدت.
 */

export const str = (s: DocSection, key: string, fallback = ''): string => {
    const v = s.data?.[key];

    return typeof v === 'string' && v.trim() !== '' ? v : fallback;
};

export const bool = (s: DocSection, key: string, fallback = false): boolean => {
    const v = s.data?.[key];

    return typeof v === 'boolean' ? v : fallback;
};

export const num = (s: DocSection, key: string, fallback: number): number => {
    const v = s.data?.[key];
    const n = typeof v === 'number' ? v : Number(v);

    return Number.isFinite(n) && n > 0 ? n : fallback;
};

export const rows = <T,>(s: DocSection, key: string): T[] => {
    const v = s.data?.[key];

    return Array.isArray(v) ? (v as T[]) : [];
};

/** صفٌّ فيه شيءٌ يُقرأ — الصفوف الفارغة لا تُرسم بطاقاتٍ فارغة */
export const filled = <T extends Record<string, unknown>>(list: T[], keys: (keyof T)[]): T[] =>
    list.filter((row) => keys.some((k) => String(row?.[k] ?? '').trim() !== ''));
