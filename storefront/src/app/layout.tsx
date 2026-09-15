import type { ReactNode } from 'react';
import { fetchSite } from '@/lib/api';
import { currentHost } from '@/lib/request';
import { fontHrefs, tokensOf } from '@/site/tokens';
import type { Tokens } from '@/site/types';

/**
 * الغلاف — لغةُ الصفحة واتّجاهُها وخطُّها ولونُ أرضيّتها.
 *
 * وثلاثتُها تختلف من متجرٍ لآخر، فيُقرأ المستند هنا أيضًا. والقراءة ليست
 * طلبًا ثانيًا: `fetchSite` محفوظةٌ في `cache` فتُخدَم الصفحةُ والوسومُ
 * والغلافُ من طلبٍ واحد.
 *
 * والخطّ يُطلب هنا لا في كلّ قسم: `preconnect` قبل الطلب يوفّر مصافحةً
 * كاملة، و`display=swap` يعرض النصّ بخطّ النظام حتى يصل الخطّ — فلا يبقى
 * الزائر أمام صفحةٍ بلا كلمات.
 *
 * وخطّان لا خطٌّ واحد: القالب التحريريّ يكتب عناوينه بخطٍّ غير خطّ نصّه
 * (`heading_font`)، وطلبُ الأوّل وحده كان يجعل العناوين تعود إلى خطّ النصّ —
 * فيفقد القالبُ أظهرَ ما يميّزه. و`fontHrefs` تردّ ما يلزم بلا تكرار.
 */
export default async function RootLayout({ children }: { children: ReactNode }) {
    const host = await currentHost();
    const result = await fetchSite(host);

    const doc = result.status === 'ok' ? result.doc : null;
    const tokens: Tokens =
        result.status === 'maintenance' && result.info.tokens
            ? tokensOf({ tokens: result.info.tokens })
            : tokensOf({ tokens: doc?.tokens as Tokens });

    const fonts = fontHrefs({ tokens, theme: doc?.theme });
    const dir = doc?.dir ?? (result.status === 'maintenance' ? result.info.dir : null) ?? 'rtl';
    const locale = doc?.locale ?? (result.status === 'maintenance' ? result.info.locale : null) ?? 'ar';

    return (
        <html lang={locale} dir={dir}>
            <head>
                <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
                <meta name="theme-color" content={tokens.background} />
                {fonts.length > 0 && (
                    <>
                        <link rel="preconnect" href="https://fonts.googleapis.com" />
                        <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="" />
                        {fonts.map((href) => (
                            <link key={href} rel="stylesheet" href={href} />
                        ))}
                    </>
                )}
            </head>
            <body style={{ margin: 0, background: tokens.background, color: tokens.text }}>{children}</body>
        </html>
    );
}
