import type { CSSProperties, ReactNode } from 'react';
import { Block } from './blocks';
import { expired, hasContent } from './content';
import { Footer } from './Footer';
import { Header } from './Header';
import { TextProvider } from './i18n';
import './site.css';
import { cssVars } from './tokens';
import type { DocPage, DocSection, Mode, SiteDocument } from './types';

/**
 * الموقع كلّه — ترويسةً وأقسامًا وتذييلًا.
 *
 * وهذا الملفّ هو الحدّ بين المشترك والخاصّ: ما فوقه (`app/`) يخصّ الخادم —
 * حلُّ النطاق والوسوم والتخزين — وما تحته يخصّ الرسم وحده. فالمعاينة داخل
 * أبعاد تستدعي هذا بالضبط، ولا تعرف شيئًا عن Next ولا عن الطلبات.
 */

/**
 * أهو قسمٌ يستحقّ أن يُرسم؟
 *
 * والجواب يختلف بالوضع: المعاينة ترسم كلّ ما أضافه التاجر — المخفيَّ باهتًا
 * والفارغَ مع بيان ما ينقصه — والموقع يرسم ما فيه شيءٌ لزبون.
 */
export function shows(section: DocSection, mode: Mode, doc: SiteDocument): boolean {
    if (section.visible === false) {
        return mode === 'edit';
    }

    if (mode === 'edit') {
        return true;
    }

    return !expired(section) && hasContent(section, doc);
}

/** الصفحة المطلوبة بمفتاحها أو بمسارها — أو الرئيسية */
export function findPage(doc: SiteDocument, keyOrSlug?: string): DocPage | undefined {
    const home = doc.pages.find((p) => p.is_home) ?? doc.pages[0];

    if (!keyOrSlug) return home;

    return doc.pages.find((p) => p.key === keyOrSlug) ?? doc.pages.find((p) => p.slug === keyOrSlug) ?? home;
}

export function globalSlot(doc: SiteDocument, slot: 'header' | 'footer'): DocSection | undefined {
    return doc.globals?.find((g) => g.slot === slot);
}

export interface SiteProps {
    doc: SiteDocument;
    mode: Mode;
    /** الصفحة المعروضة — بمفتاحها أو بمسارها. وبلا شيء: الرئيسية */
    page?: string;
    /** ما يُلفّ به كلُّ قسم في المعاينة — للتحديد والإبراز */
    wrap?: (node: ReactNode, section: DocSection, index: number) => ReactNode;
    /** نصٌّ يُعرض حين لا أقسام — في المعاينة وحدها */
    emptyText?: string;
    /** مترجم لوحة أبعاد — لنصوص المحرّر وحدها. انظر `i18n.tsx` */
    t?: (text: string) => string;
    style?: CSSProperties;
    className?: string;
    /**
     * محتوًى يُرسم داخل الموقع بعد أقسامه.
     *
     * وهذا هو موضع التوسّع: صفحةُ منتجٍ أو سلّةٌ أو نتيجةُ بحثٍ تُرسم يومًا
     * ما هنا — داخل ترويسة المتجر وتذييله وبرموزه — بلا أن يُعاد بناء شيء.
     * وأوّلُ مستعمليه اليوم صفحةُ «غير موجودة»: تُعرض داخل الموقع لا خارجه.
     */
    children?: ReactNode;
}

export function Site({ doc, mode, page, wrap, emptyText, style, className, children, t }: SiteProps) {
    const current = findPage(doc, page);
    const header = globalSlot(doc, 'header');
    const footer = globalSlot(doc, 'footer');
    const sections = current?.sections ?? [];
    const drawn = sections.filter((s) => shows(s, mode, doc));

    return (
        <TextProvider t={t}>
            <div
                dir={doc.dir ?? 'rtl'}
                lang={doc.locale ?? 'ar'}
                className={['w-site', className].filter(Boolean).join(' ')}
                style={{ ...cssVars(doc), minHeight: mode === 'edit' ? 420 : undefined, ...style }}
            >
                {header && shows(header, mode, doc) && <Header section={header} doc={doc} mode={mode} />}

                <main>
                    {drawn.length === 0 && !children && mode === 'edit' && (
                        <p style={{ padding: '64px 24px', textAlign: 'center', color: 'var(--w-muted)', fontSize: 14 }}>
                            {(t ?? ((x: string) => x))(emptyText ?? 'لا أقسام في هذه الصفحة بعد')}
                        </p>
                    )}

                    {sections.map((section, i) => {
                        if (!shows(section, mode, doc)) return null;

                        const node = <Block section={section} doc={doc} mode={mode} first={i === 0} />;

                        return <div key={i}>{wrap ? wrap(node, section, i) : node}</div>;
                    })}

                    {children}
                </main>

                {footer && shows(footer, mode, doc) && <Footer section={footer} doc={doc} mode={mode} />}
            </div>
        </TextProvider>
    );
}
