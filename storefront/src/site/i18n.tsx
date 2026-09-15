'use client';

import { createContext, useContext, type ReactNode } from 'react';

/**
 * نصوصُ المحرّر وحدها تُترجَم — لا نصوص الموقع.
 *
 * ما يراه زائرُ المتجر عربيٌّ لأنّ المستند يقول `locale: ar`: «اطلب عبر
 * واتساب» لا تتبدّل بلغة لوحة التاجر. أمّا ما يُقال للتاجر وهو يحرّر —
 * «لا صور بعد» — فيتبع لغة لوحته، وقد تكون إنجليزية.
 *
 * وهو الفرق بين نصٍّ في المنتج ونصٍّ عن المنتج. ولولا هذا التمييز لصار
 * تبديلُ التاجر لغةَ لوحته يبدّل لغة موقعه في المعاينة — وهو ليس كذلك.
 */

type Translate = (text: string) => string;

const TextContext = createContext<Translate>((text) => text);

export function TextProvider({ t, children }: { t?: Translate; children: ReactNode }) {
    return <TextContext.Provider value={t ?? ((text) => text)}>{children}</TextContext.Provider>;
}

export function useText(): Translate {
    return useContext(TextContext);
}
