import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import Tabs from '@/Components/Tabs';
import { Table, TableBody, TableCell, TableRow } from '@/Components/ui/table';

/**
 * ما يُمرَّر أفقيًّا يُمرَّر — ولا يُرسم تحته شريطُ النظام الرماديّ.
 *
 * ═══ العطب ═══
 *
 * التبويباتُ والجداولُ العريضة وأشرطةُ نقطة البيع تُمرَّر أفقيًّا
 * (`overflow-x-auto`)، فيرسم Safari على iPad وmacOS تحتها شريطًا رماديًّا
 * يقطع الواجهة. و`.no-scrollbar` في `app.css` تخفيه وتُبقي التمرير — لكنّها
 * لم تكن على عنصرٍ واحد.
 *
 * ═══ ما يُحرس ═══
 *
 *   - كلُّ `overflow-x-auto` في الواجهة معه `no-scrollbar` — يُضاف الغدَ
 *     جدولٌ أو شريطٌ بلاه فيسقط هذا.
 *   - والتمريرُ نفسُه باقٍ: لا `overflow-hidden` بدلَه، ولا يُخفى شريطٌ
 *     عموديّ (`overflow-y-auto` لقائمةٍ طويلة يبقى شريطُه).
 *   - والمكوّناتُ المشتركة تحمله: التبويبات وجدولُ الواجهة — منها ينتشر.
 */

const SOURCES = import.meta.glob('/resources/js/**/*.{tsx,ts}', { query: '?raw', import: 'default', eager: true }) as Record<string, string>;
// والـCSS يُقرأ من القرص: Vitest يُفرغ استيرادَه (`css: false`)
const APP_CSS = readFileSync(resolve(process.cwd(), 'resources/css/app.css'), 'utf8');
const STORE = readFileSync(resolve(process.cwd(), 'resources/views/store/show.blade.php'), 'utf8');

/** كلُّ صنفٍ نصّيٍّ فيه تمريرٌ أفقيّ — `[الملف، النصّ]` */
const scrollers = () =>
    Object.entries(SOURCES).flatMap(([file, src]) =>
        [...src.matchAll(/(['"`])([^'"`\n]*\boverflow-x-(?:auto|scroll)\b[^'"`\n]*)\1/g)].map((m) => [file, m[2]] as const),
    );

describe('التمريرُ الأفقيّ بلا شريطٍ ظاهر', () => {
    it('`.no-scrollbar` في app.css تُخفي الشريط ولا تمنع التمرير', () => {
        expect(APP_CSS).toMatch(/\.no-scrollbar::-webkit-scrollbar\s*\{\s*display:\s*none;?\s*\}/);
        expect(APP_CSS).toMatch(/\.no-scrollbar\s*\{[^}]*scrollbar-width:\s*none/);
        // ولا خاصّيّةَ `overflow` فيها (`-ms-overflow-style` اسمُ الشريط لا التمرير)
        expect(APP_CSS).not.toMatch(/\.no-scrollbar\s*\{[^}]*(?<![-\w])overflow(?:-[xy])?\s*:/);
    });

    it('كلُّ حاويةٍ تُمرَّر أفقيًّا في الواجهة معها `no-scrollbar`', () => {
        const found = scrollers();
        // المكوّناتُ المشتركة والصفحاتُ المنفردة معًا — ثلاثون موضعًا يوم كُتب
        expect(found.length).toBeGreaterThanOrEqual(30);

        const bare = found.filter(([, cls]) => !/\bno-scrollbar\b/.test(cls)).map(([file, cls]) => `${file}: ${cls}`);
        expect(bare).toEqual([]);
    });

    it('والإخفاءُ لا يمنع التمرير ولا يمسّ الشريط العموديّ', () => {
        for (const [file, cls] of scrollers()) {
            expect(cls, file).not.toMatch(/\boverflow-(?:x-)?hidden\b/);
            expect(cls, file).not.toMatch(/\boverflow-y-auto\b/);
        }
        // ولا `no-scrollbar` على قائمةٍ تُمرَّر عموديًّا
        for (const [file, src] of Object.entries(SOURCES)) {
            expect(src, file).not.toMatch(/['"`][^'"`\n]*\bno-scrollbar\b[^'"`\n]*\boverflow-y-auto\b/);
            expect(src, file).not.toMatch(/['"`][^'"`\n]*\boverflow-y-auto\b[^'"`\n]*\bno-scrollbar\b/);
        }
    });

    it('والمتجرُ القديم (`store/show`) لا يُحمِّل app.css — فقاعدتاها مكتوبتان معه', () => {
        expect(STORE).toMatch(/nav\.cats\s*\{[^}]*overflow-x:\s*auto[^}]*scrollbar-width:\s*none/);
        expect(STORE).toMatch(/nav\.cats::-webkit-scrollbar\s*\{\s*display:\s*none;?\s*\}/);
    });
});

describe('المكوّناتُ المشتركة', () => {
    it('شريطُ التبويبات يُمرَّر بلا شريط — وأوّلُ تبويبٍ وآخرُه فيه', () => {
        const tabs = Array.from({ length: 12 }, (_, i) => ({ key: `t${i}`, label: `تبويب ${i + 1}` }));
        render(<Tabs tabs={tabs} current="t0" onChange={() => {}} />);

        const list = screen.getByRole('tablist');
        expect(list).toHaveClass('overflow-x-auto', 'no-scrollbar');
        expect(list).not.toHaveClass('overflow-hidden');
        expect(screen.getByRole('tab', { name: 'تبويب 1' })).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: 'تبويب 12' })).toBeInTheDocument();
    });

    it('الجدولُ العريض يُمرَّر داخل غلافه بلا شريط', () => {
        const { container } = render(
            <Table>
                <TableBody>
                    <TableRow>
                        <TableCell>أ</TableCell>
                    </TableRow>
                </TableBody>
            </Table>,
        );

        const wrapper = container.querySelector('table')!.parentElement!;
        expect(wrapper).toHaveClass('overflow-x-auto', 'no-scrollbar', 'w-full');
    });
});
