import { usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';

/**
 * ترجمة نصوص الواجهة — نظير __() في Blade.
 *
 * المفتاح هو النص العربي نفسه، تمامًا كما في القوالب، فيبقى مصدر الترجمة
 * ملف lang/en.json واحدًا للطرفين ولا ينشأ قاموسان يفترقان.
 *
 * الاستبدال بـ :name مثل Laravel — t('مرحبًا :name', { name: 'أحمد' }).
 */
export function useTranslate() {
    const { translations } = usePage<PageProps>().props;

    return (key: string, replace?: Record<string, string | number>): string => {
        let text = translations?.[key] ?? key;

        if (replace) {
            for (const [name, value] of Object.entries(replace)) {
                text = text.replace(`:${name}`, String(value));
            }
        }

        return text;
    };
}
