import '@testing-library/react';

/**
 * تهيئة الاختبارات.
 *
 * `matchMedia` غير موجودة في jsdom، وبعض المكوّنات تسألها عن تفضيل الحركة.
 * وغيابُها يرمي، فتسقط اختباراتٌ لا علاقة لها بالحركة.
 */
if (typeof window !== 'undefined' && !window.matchMedia) {
    window.matchMedia = ((query: string) => ({
        matches: false,
        media: query,
        onchange: null,
        addListener: () => {},
        removeListener: () => {},
        addEventListener: () => {},
        removeEventListener: () => {},
        dispatchEvent: () => false,
    })) as unknown as typeof window.matchMedia;
}
