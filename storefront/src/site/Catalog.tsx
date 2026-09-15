'use client';

import { useEffect, useMemo, useState } from 'react';
import { showPrices } from './commerce';
import { ChevronDown, Search, Sliders } from './icons';
import { useText } from './i18n';
import type { LayoutTokens } from './layout';
import { gridOf, ProductCard } from './ProductCard';
import { Grid } from './primitives';
import type { DocCategory, DocProduct, Mode, SiteDocument } from './types';

/**
 * صفحة المتجر — الكتالوج كلُّه، يُرشَّح ويُرتَّب في المتصفّح.
 *
 * الواجهةُ الرئيسية تعرض ثمانيةَ منتجاتٍ مختارة، وهذا يكفي لتقول ما تبيع
 * ولا يكفي ليشتري أحد. والمتجر الحقيقيّ صفحةٌ فيها البضاعة كلُّها ومعها ما
 * يصل بها الزائرُ إلى ما يريد: بحثٌ وأقسامٌ وترتيب.
 *
 * ── لماذا في المتصفّح ──
 *
 * لا مسارَ بحثٍ في العقد ولا صفحاتٍ مرقّمة: المستند يصل ومعه الكتالوج، فما
 * يُرشَّح يُرشَّح ممّا وصل. وهذا ليس تحايلًا — متجرُ تاجرٍ في أبعاد مئتا صنفٍ
 * لا مليون، وترشيحُ مئتين في المتصفّح أسرع من طلبٍ ثانٍ إلى الخادم وأصدقُ
 * من «تحميل المزيد» يخفي نصف البضاعة. ويوم يصير الكتالوج أكبر من ذلك يصير
 * الترشيح في الخادم، ولا يتغيّر شكلُ هذه الصفحة.
 *
 * ── ما لا يُعرض ──
 *
 * لا لونَ ولا مقاسَ ولا علامةً تجارية في المرشّحات. المنتج في أبعاد اسمٌ
 * وسعرٌ وصورةٌ وتصنيف — وليس فيه لونٌ ولا مقاس. ومرشّحٌ لا بيانات تحته يُرسم
 * صندوقًا فارغًا أو — أسوأ — يعِد الزائرَ بترشيحٍ لا يقع.
 *
 * والأقسام لا تُعرض كلُّها: التصنيفُ الذي لا منتجَ له في هذه الصفحة لا
 * يُعرض زرًّا يؤدّي إلى «لا نتائج».
 */

type Sort = 'new' | 'price-asc' | 'price-desc' | 'name';

/**
 * تسويةُ الحرف والرقم قبل المطابقة.
 *
 * زبونٌ يكتب «ورد» لا يجد «وَرْد»، ومن يكتب «باقه» لا يجد «باقة»، ومن كتب
 * «باقة ٥» بلوحةٍ عربية لا يجد «باقة 5». والبحث الذي لا يجد ما هو موجود
 * أسوأ من ألّا يكون هناك بحث: الزائر يستنتج أنّ المتجر لا يبيعه.
 *
 * والأرقام تُسوّى هنا لا بحارس أبعاد (`lib/numerals`): هذه الطبقة بلا
 * تبعيّات — لا تستورد من أبعاد ولا من غيرها — وسطرٌ واحد أهون من شرطٍ
 * يكسر ذلك.
 */
function norm(text: string): string {
    return text
        .toLowerCase()
        .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660))
        .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0))
        .replace(/[ً-ْـ]/g, '')
        .replace(/[أإآٱ]/g, 'ا')
        .replace(/ى/g, 'ي')
        .replace(/ة/g, 'ه')
        .replace(/\s+/g, ' ')
        .trim();
}

export default function Catalog({
    doc,
    mode,
    items,
    title,
    card,
    grid,
    ratio,
    columns,
}: {
    doc: SiteDocument;
    mode: Mode;
    items: DocProduct[];
    title: string;
    card: LayoutTokens['card'];
    grid: LayoutTokens['grid'];
    ratio: string;
    columns: number;
}) {
    const t = useText();
    const [q, setQ] = useState('');
    const [cat, setCat] = useState<number | null>(null);
    const [sort, setSort] = useState<Sort>('new');
    const [openFilters, setOpenFilters] = useState(false);
    const prices = showPrices(doc);

    /*
     * وما كُتب في بحث الترويسة يصل هنا في العنوان.
     *
     * الترويسة نموذجٌ يذهب إلى `/shop?q=…` — بلا JavaScript ولا حالةٍ
     * مشتركة. ويُقرأ بعد التركيب لا قبله: الخادم يرسم الصفحة كاملةً
     * (فيقرؤها الزاحف ومن لا JavaScript عنده)، ثمّ يُطبَّق ما في العنوان.
     */
    useEffect(() => {
        if (typeof window === 'undefined') return;

        const wanted = new URLSearchParams(window.location.search).get('q');

        if (wanted) setQ(wanted);
    }, []);

    /** التصنيفاتُ التي لها منتجٌ هنا — لا كلُّ ما في النظام */
    const categories = useMemo(() => {
        const present = new Set(items.map((p) => p.category_id).filter((id): id is number => id !== null));

        return (doc.data?.categories ?? []).filter((c: DocCategory) => present.has(c.id));
    }, [items, doc.data?.categories]);

    const shown = useMemo(() => {
        const needle = norm(q);
        let list = items;

        if (cat !== null) {
            list = list.filter((p) => p.category_id === cat);
        }

        if (needle !== '') {
            list = list.filter((p) => norm(`${p.name} ${p.excerpt}`).includes(needle));
        }

        if (sort === 'price-asc') list = [...list].sort((a, b) => a.final - b.final);
        if (sort === 'price-desc') list = [...list].sort((a, b) => b.final - a.final);
        if (sort === 'name') list = [...list].sort((a, b) => a.name.localeCompare(b.name, 'ar'));

        return list;
    }, [items, q, cat, sort]);

    const shape = gridOf(grid, columns);

    const sorts: { value: Sort; label: string }[] = [
        { value: 'new', label: 'الأحدث' },
        ...(prices
            ? ([
                  { value: 'price-asc', label: 'الأقلّ سعرًا' },
                  { value: 'price-desc', label: 'الأعلى سعرًا' },
              ] as const)
            : []),
        { value: 'name', label: 'الاسم' },
    ];

    const chip = (label: string, on: boolean, onClick: () => void) => (
        <button
            key={label}
            type="button"
            onClick={onClick}
            aria-pressed={on}
            style={{
                border: `1px solid ${on ? 'var(--w-primary)' : 'var(--w-border)'}`,
                background: on ? 'var(--w-primary)' : 'var(--w-bg)',
                color: on ? 'var(--w-on-primary)' : 'inherit',
                borderRadius: 999,
                padding: '0 16px',
                minHeight: 40,
                fontSize: 13.5,
                fontWeight: 700,
                fontFamily: 'inherit',
                cursor: 'pointer',
                whiteSpace: 'nowrap',
            }}
        >
            {label}
        </button>
    );

    const search = (
        <div
            style={{
                display: 'flex',
                alignItems: 'center',
                gap: 8,
                border: '1px solid var(--w-border)',
                borderRadius: 'var(--w-radius)',
                background: 'var(--w-bg)',
                paddingInline: 12,
                minHeight: 44,
                flex: 1,
                minWidth: 180,
            }}
        >
            <Search size={17} style={{ color: 'var(--w-muted)', flex: 'none' }} />
            <input
                type="search"
                value={q}
                onChange={(e) => setQ(e.target.value)}
                placeholder={t('ابحث في المنتجات')}
                aria-label={t('ابحث في المنتجات')}
                style={{
                    flex: 1,
                    minWidth: 0,
                    border: 0,
                    outline: 'none',
                    background: 'transparent',
                    color: 'inherit',
                    fontFamily: 'inherit',
                    fontSize: 14,
                    padding: '10px 0',
                }}
            />
        </div>
    );

    const sorter = (
        <label
            style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: 6,
                border: '1px solid var(--w-border)',
                borderRadius: 'var(--w-radius)',
                background: 'var(--w-bg)',
                paddingInline: 12,
                minHeight: 44,
                flex: 'none',
            }}
        >
            <span style={{ fontSize: 13, color: 'var(--w-muted)' }}>{t('الترتيب')}</span>
            <select
                value={sort}
                onChange={(e) => setSort(e.target.value as Sort)}
                aria-label={t('ترتيب المنتجات')}
                style={{
                    border: 0,
                    outline: 'none',
                    background: 'transparent',
                    color: 'inherit',
                    fontFamily: 'inherit',
                    fontSize: 13.5,
                    fontWeight: 700,
                    appearance: 'none',
                    paddingInlineEnd: 4,
                    cursor: 'pointer',
                }}
            >
                {sorts.map((s) => (
                    <option key={s.value} value={s.value}>
                        {t(s.label)}
                    </option>
                ))}
            </select>
            <ChevronDown size={15} style={{ color: 'var(--w-muted)', flex: 'none' }} />
        </label>
    );

    return (
        <section style={{ padding: 'var(--w-pad)' }}>
            <div style={{ maxWidth: 'var(--w-content)', margin: '0 auto' }}>
                {/* ترويسةُ الصفحة: اسمُها وكم فيها — والعدد يتبع ما يُعرض لا ما يوجد */}
                <div style={{ marginBottom: 20 }}>
                    <h1 style={{ fontSize: 'var(--w-h1)', fontWeight: 800, margin: 0, lineHeight: 1.25 }}>{title}</h1>
                    <p style={{ color: 'var(--w-muted)', fontSize: 14, margin: '8px 0 0' }}>
                        {shown.length === items.length
                            ? `${items.length} ${t('منتجًا')}`
                            : `${shown.length} ${t('من')} ${items.length} ${t('منتجًا')}`}
                    </p>
                </div>

                {/* الأقسام شريطٌ يُمرَّر بالإصبع على الهاتف ويلتفّ على الحاسوب */}
                {categories.length > 0 && (
                    <div className="w-rail" style={{ marginBottom: 14 }}>
                        {chip(t('الكلّ'), cat === null, () => setCat(null))}
                        {categories.map((c) => chip(c.name, cat === c.id, () => setCat(cat === c.id ? null : c.id)))}
                    </div>
                )}

                {/*
                 * والمرشّحات على الهاتف خلف زرّ — لا صفًّا يدفع البضاعة تحت
                 * الطيّة. ولا نافذةَ منزلقة: المحتوى نفسُه يُفتح مكانه، فلا
                 * حبسَ تركيزٍ ولا `fixed` تغطّي الصفحة بلا حاجة.
                 */}
                <div className="w-mob" style={{ marginBottom: 14 }}>
                    <button
                        type="button"
                        onClick={() => setOpenFilters((v) => !v)}
                        aria-expanded={openFilters}
                        style={{
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: 8,
                            minHeight: 44,
                            padding: '0 16px',
                            border: '1px solid var(--w-border)',
                            borderRadius: 'var(--w-radius)',
                            background: 'var(--w-bg)',
                            color: 'inherit',
                            fontFamily: 'inherit',
                            fontSize: 13.5,
                            fontWeight: 700,
                            cursor: 'pointer',
                        }}
                    >
                        <Sliders size={16} />
                        {t('البحث والترتيب')}
                    </button>

                    {openFilters && (
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10, marginTop: 10 }}>
                            {search}
                            {sorter}
                        </div>
                    )}
                </div>

                <div className="w-desk" style={{ gap: 10, marginBottom: 22, flexWrap: 'wrap' }}>
                    {search}
                    {sorter}
                </div>

                {shown.length === 0 ? (
                    <p
                        style={{
                            border: '1px dashed var(--w-border)',
                            borderRadius: 'var(--w-radius)',
                            padding: '36px 18px',
                            textAlign: 'center',
                            color: 'var(--w-muted)',
                            fontSize: 14,
                            margin: 0,
                        }}
                    >
                        {items.length === 0
                            ? t('لا منتجات مفعّلة بعد — أضف منتجاتك وستظهر هنا')
                            : t('لا منتج يطابق بحثك — جرّب كلمةً أخرى')}
                    </p>
                ) : (
                    <Grid
                        columns={shape.columns}
                        min={shape.min}
                        center={false}
                        className={grid === 'editorial' ? 'w-grid-editorial' : undefined}
                    >
                        {shown.map((p, i) => (
                            <ProductCard
                                key={p.id}
                                p={p}
                                doc={doc}
                                mode={mode}
                                card={card}
                                ratio={ratio}
                                eager={i < 4}
                            />
                        ))}
                    </Grid>
                )}
            </div>
        </section>
    );
}
