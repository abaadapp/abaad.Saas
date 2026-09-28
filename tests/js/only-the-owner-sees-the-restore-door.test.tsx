import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import BackupStatusPanel, { type BackupStatus } from '@/Pages/Admin/Settings/panels/BackupStatusPanel';

/**
 * بابُ الاستعادة لصاحب النشاط وحده — والشاشةُ تقول ما في النسخة بصدق.
 *
 * ═══ الحال التي أنشأت هذا الملفّ ═══
 *
 * كان زرُّ «استعادة آخر نسخة» يُعرض لكلّ من فتح تبويب النسخ، والخادمُ صار
 * يردّ من ليس صاحبَ النشاط. فزرٌّ يُعرض لمن سيُردّ يُضغط فتُصفع به صفحة ٤٠٣.
 *
 * وكانت الشاشةُ تقول «نسخة كاملة» والصورُ والمرفقاتُ ليست فيها، وتَعِد بنسخة
 * أمانٍ «لحال متجرك» ولا زرَّ يعيد إليها.
 *
 * ═══ وما يُحرَس هنا ═══
 *
 * أنّ الزرّ يُخفى حين يقول الخادمُ `can_restore: false` — وما سواه يبقى.
 * وأنّ الصفحةَ الأمّ تحرس بابَ الملفّ المرفوع بالشرط نفسه. وأنّ النصَّ لا
 * يَعِد بما ليس فيه. والخادمُ هو ما يمنع؛ هذا ما يُخفي فقط.
 */

const status = (over: Partial<BackupStatus> = {}): BackupStatus => ({
    frequency: 'daily',
    frequencies: ['daily', 'weekly', 'monthly', 'manual'],
    offsite_enabled: true,
    latest: {
        name: 'abadpos-backup-5-2026-09-28-020000.json.gz',
        created_at: '2026-09-28 02:00',
        bytes: 2048,
        offsite: true,
        compressed: true,
    },
    ...over,
});

const page = () =>
    readFileSync(path.resolve(__dirname, '../../resources/js/Pages/Admin/Settings/Index.tsx'), 'utf8');

describe('بابُ الاستعادة', () => {
    it('يُخفى عمّن يردّه الخادم — والإنشاءُ والتحميلُ باقيان', () => {
        render(<BackupStatusPanel data={status({ can_restore: false })} />);

        expect(screen.queryByRole('button', { name: /استعادة آخر نسخة/ })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: /إنشاء نسخة الآن/ })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /تحميل آخر نسخة/ })).toBeInTheDocument();
    });

    it('ويُعرض لصاحب النشاط', () => {
        render(<BackupStatusPanel data={status({ can_restore: true })} />);

        expect(screen.getByRole('button', { name: /استعادة آخر نسخة/ })).toBeInTheDocument();
    });

    it('والملفُّ المرفوعُ في الصفحة الأمّ خلف الشرط نفسِه', () => {
        const src = page();
        const guard = src.indexOf('backups?.can_restore !== false');
        const section = src.indexOf('title="استعادة من ملف خارجي"');

        expect(guard, 'بابُ الملفّ المرفوع بلا حارس').toBeGreaterThan(-1);
        // والشرطُ يسبق القسمَ مباشرةً — لا شرطٌ في موضعٍ آخر يُحسب له
        expect(section).toBeGreaterThan(guard);
        expect(src.slice(guard, section)).not.toContain('</SettingsSection>');
    });
});

describe('ما تقوله الشاشةُ عن النسخة', () => {
    it('لا «نسخة كاملة» — والصورُ والمرفقاتُ ليست فيها', () => {
        render(<BackupStatusPanel data={status()} />);

        expect(screen.queryByText(/نسخة كاملة/)).not.toBeInTheDocument();
        expect(screen.getByText(/لا تشمل الصور والمرفقات/)).toBeInTheDocument();
    });

    it('ونسخةُ الأمان «داخلية» — لا وعدَ بزرٍّ يعيد إليها', async () => {
        render(<BackupStatusPanel data={status()} />);

        await userEvent.click(screen.getByRole('button', { name: /استعادة آخر نسخة/ }));

        expect(screen.getByText(/سيتم إنشاء نسخة أمان داخلية قبل الاستعادة/)).toBeInTheDocument();
        expect(screen.queryByText(/لحال متجرك الآن/)).not.toBeInTheDocument();
    });

    it('وبابُ الملفّ المرفوع يقولها كذلك', () => {
        const src = page();

        expect(src).toContain('وسيتم إنشاء نسخة أمان داخلية قبل الاستعادة.');
        expect(src).not.toContain('وتُؤخذ قبلها نسخة أمان لحاله الآن');
    });
});
