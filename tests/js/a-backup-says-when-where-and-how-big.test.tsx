import { router } from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import BackupStatusPanel, {
    type BackupStatus,
    formatBytes,
    offsiteLabel,
} from '@/Pages/Admin/Settings/panels/BackupStatusPanel';

/**
 * شاشةُ النسخ على الخادم — تقول متى كانت آخرُ نسخة، وكم حجمُها، وأين هي.
 *
 * ═══ وما يُحرَس ═══
 *
 * أنّ «إنشاء نسخة الآن» يُعرض دائمًا — ولو «يدويًّا فقط»، وهو عندها الطريقُ
 * الوحيد إلى نسخة. وأنّ التحميلَ والاستعادةَ لا يُعرضان حين لا نسخة. وأنّ
 * الاستعادةَ تُسأل قبل أن تُرسَل، ويُرسَل تأكيدُها. وأنّ «غير مفعّل» و«لا»
 * و«غير معروف» ثلاثةُ أجوبةٍ لا جوابٌ واحد.
 *
 * والعزلُ والصلاحيّةُ ليسا هنا: البابُ لا يأخذ اسمَ ملفّ، ولهما حرّاسٌ في PHP.
 */
const status = (over: Partial<BackupStatus> = {}): BackupStatus => ({
    frequency: 'daily',
    frequencies: ['daily', 'weekly', 'monthly', 'manual'],
    offsite_enabled: true,
    latest: {
        name: 'abadpos-backup-5-2026-09-28-020000.json.gz',
        created_at: '2026-09-28 02:00',
        bytes: 3 * 1024 * 1024 + 300 * 1024,
        offsite: true,
        compressed: true,
    },
    ...over,
});

beforeEach(() => {
    vi.mocked(router.post).mockClear();
});

describe('آخرُ نسخةٍ ناجحة', () => {
    it('تقول تاريخها وحجمها وأنّها نُسخت خارج الخادم', () => {
        render(<BackupStatusPanel data={status()} />);

        expect(screen.getByText('2026-09-28 02:00')).toBeInTheDocument();
        expect(screen.getByText('3.3 MB')).toBeInTheDocument();
        expect(screen.getByText('نعم — نُسخت إلى التخزين الخارجي')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /تحميل آخر نسخة/ })).toHaveAttribute(
            'href',
            '/admin.backup.latest.download',
        );
    });

    it('وبلا نسخةٍ لا بابَ تحميلٍ ولا استعادة — و«الآن» باقٍ', () => {
        render(<BackupStatusPanel data={status({ latest: null })} />);

        expect(screen.getByText('لا توجد نسخة محفوظة على الخادم بعد.')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /تحميل آخر نسخة/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /استعادة آخر نسخة/ })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: /إنشاء نسخة الآن/ })).toBeInTheDocument();
    });

    it('و«يدويًّا فقط» يقول إنّ لا نسخ تلقائيًّا — والزرُّ يعمل', async () => {
        render(<BackupStatusPanel data={status({ frequency: 'manual', latest: null })} />);

        expect(screen.getByText(/لن تُحفظ نسخة تلقائيًّا/)).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: /إنشاء نسخة الآن/ }));

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(vi.mocked(router.post).mock.calls[0][0]).toBe('/admin.backup.create');
    });
});

describe('استعادةُ آخر نسخة', () => {
    it('تُسأل أوّلًا، ولا يُرسَل شيءٌ لمن تراجع', async () => {
        render(<BackupStatusPanel data={status()} />);

        await userEvent.click(screen.getByRole('button', { name: /استعادة آخر نسخة/ }));

        expect(screen.getByText(/سيتم إنشاء نسخة أمان داخلية/)).toBeInTheDocument();
        expect(router.post).not.toHaveBeenCalled();

        await userEvent.click(screen.getByRole('button', { name: /إلغاء/ }));

        expect(router.post).not.toHaveBeenCalled();
    });

    it('ومن أكّد يُرسَل تأكيدُه — فالخادمُ يشترطه', async () => {
        render(<BackupStatusPanel data={status()} />);

        await userEvent.click(screen.getByRole('button', { name: /استعادة آخر نسخة/ }));
        await userEvent.click(screen.getByRole('button', { name: /^استعادة$/ }));

        expect(router.post).toHaveBeenCalledTimes(1);
        const [url, payload] = vi.mocked(router.post).mock.calls[0];
        expect(url).toBe('/admin.backup.latest.restore');
        expect(payload).toEqual({ confirm: true });
    });
});

describe('واجهةٌ واحدة لا واجهتان', () => {
    it('زرٌّ أساسيٌّ واحد: «إنشاء نسخة الآن» — والتحميلُ والاستعادةُ ثانويّان', () => {
        render(<BackupStatusPanel data={status()} />);

        const create = screen.getByRole('button', { name: /إنشاء نسخة الآن/ });
        const download = screen.getByRole('link', { name: /تحميل آخر نسخة/ });
        const restore = screen.getByRole('button', { name: /استعادة آخر نسخة/ });

        expect(create.className).toContain('bg-[#111]');
        for (const secondary of [download, restore]) {
            expect(secondary.className).toContain('border');
            expect(secondary.className).not.toContain('bg-[#111]');
            expect(secondary.className).not.toContain('bg-[#dc2626]');
        }

        // والتكرارُ في القسم نفسِه
        expect(screen.getByRole('combobox', { name: 'تكرار النسخ التلقائي' })).toBeInTheDocument();
    });

    it('ولا يُعرض «تنزيل النسخة الآن» القديم بجانبها', () => {
        render(<BackupStatusPanel data={status()} />);

        expect(screen.queryByText('تنزيل النسخة الآن')).not.toBeInTheDocument();
        expect(document.querySelector('a[href="/admin.backup.download"]')).toBeNull();

        /*
         * والصفحةُ الأمّ لا تُرسم هنا — تحتاج عشراتِ الخصائص لتُركَّب. فيُسأل
         * مصدرُها: البابُ القديمُ لا يُستدعى منها. ومسارُه باقٍ على الخادم.
         */
        const page = readFileSync(
            path.resolve(__dirname, '../../resources/js/Pages/Admin/Settings/Index.tsx'),
            'utf8',
        );
        expect(page).not.toContain("route('admin.backup.download')");
        expect(page).toContain('<BackupStatusPanel');
    });

    it('وما يردّه الخادمُ خطأً يُقرأ تحت الأزرار', async () => {
        vi.mocked(router.post).mockImplementationOnce((_url, _data, options) => {
            options?.onError?.({ backup: 'النسخة بعد فكّ ضغطها أكبر من 50 ميجابايت — لا تُستعاد من هنا.' });
            options?.onFinish?.({} as never);
        });

        render(<BackupStatusPanel data={status()} />);
        await userEvent.click(screen.getByRole('button', { name: /استعادة آخر نسخة/ }));
        await userEvent.click(screen.getByRole('button', { name: /^استعادة$/ }));

        expect(await screen.findByRole('alert')).toHaveTextContent(/أكبر من 50 ميجابايت/);
    });
});

describe('ما يُقرأ', () => {
    it('الحجمُ بوحدةٍ تُقرأ، ولا «0» لنسخةٍ صغيرة', () => {
        expect(formatBytes(200)).toBe('1 KB');
        expect(formatBytes(3 * 1024)).toBe('3 KB');
        expect(formatBytes(5 * 1024 * 1024)).toBe('5.0 MB');
    });

    it('«غير مفعّل» غيرُ «لا» غيرُ «غير معروف»', () => {
        expect(offsiteLabel({ offsite_enabled: false }, false)).toBe('التخزين الخارجي غير مفعّل');
        expect(offsiteLabel({ offsite_enabled: true }, false)).toBe('لا — بقيت على الخادم وحده');
        expect(offsiteLabel({ offsite_enabled: true }, null)).toBe('غير معروف');
        expect(offsiteLabel({ offsite_enabled: false }, true)).toBe('نعم — نُسخت إلى التخزين الخارجي');
    });
});
