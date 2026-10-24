import { act, fireEvent, render, screen } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { router as core } from '@inertiajs/core';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: SetupIndex } = await import('@/Pages/Admin/Setup/Index');

/**
 * من لم يُجب لا يجبي — والشاشتان لا تُجيبان عنه.
 *
 * ═══ ما كان ═══
 *
 * الخادمُ يقرأ غيابَ `vat_enabled` تفعيلًا، والشاشتان كذلك: التهيئةُ تعرض
 * «مسجَّلًا» لمن لم يقل شيئًا، والإعداداتُ تبدأ المفتاحَ مضاءً. والنموذجان
 * يُرسلان المفتاحَ مع كلّ حفظ — فتاجرٌ جديد يكتب اسمَ متجره ويحفظ، فتُكتب
 * الضريبةُ مفعّلةً باسمه بلا رقمٍ ضريبيّ ولا قصد.
 *
 * ═══ ما يُحرس ═══
 *
 *   - التهيئةُ تعرض ما يحسبه الخادم (`Vat::enabled`): غيرُ مسجَّل، والرقمُ
 *     معطَّل، والحفظُ يُرسل «مطفأة».
 *   - ومن فعّلها صراحةً يُرسلها مفعّلة كما كانت.
 *   - وقسمُ المالية في الإعدادات يبدأ المفتاحَ مطفأً حين لا صفّ.
 *
 * وما يفعله الخادمُ بها حارسُه `AShopThatNeverAnsweredChargesNoVatTest`.
 */

let sent: Record<string, unknown>[] = [];

beforeEach(() => {
    sent = [];
    vi.spyOn(core, 'post').mockImplementation(((_url: string, data: Record<string, unknown>) => {
        sent.push(data);
    }) as never);
});

afterEach(() => vi.restoreAllMocks());

const draw = (vatEnabled: boolean) =>
    render(
        <SetupIndex
            shop={{ name: 'ورود الخوير', phone: '', address: '', logo: '', vat_number: '', vat_enabled: vatEnabled }}
            steps={[]}
            confirmed={false}
            placeholders={['متجري']}
        />,
    );

const notRegistered = () => screen.getByRole('checkbox', { name: 'متجري غير مسجّل في ضريبة القيمة المضافة' });

const save = async () => {
    await act(async () => {
        fireEvent.click(screen.getByRole('button', { name: 'حفظ' }));
    });
};

describe('تهيئةُ المتجر والضريبة', () => {
    it('متجرٌ لم يُجب يُعرض غيرَ مسجَّل — وحفظُ اسمه لا يُفعّل الضريبة', async () => {
        draw(false);

        expect(notRegistered()).toBeChecked();
        expect(document.getElementById('vat_number')).toBeDisabled();

        await save();

        expect(sent).toHaveLength(1);
        expect(sent[0].vat_enabled).toBe(false);
        expect(sent[0].shop_name).toBe('ورود الخوير');
    });

    it('ومن فعّلها صراحةً يحفظها مفعّلةً كما كانت', async () => {
        draw(true);

        expect(notRegistered()).not.toBeChecked();

        await save();

        expect(sent[0].vat_enabled).toBe(true);
    });

    it('والتسجيلُ يُختار بيد التاجر: يُزال «غير مسجّل» فيُفتح الرقم ويُرسل مفعّلًا', async () => {
        draw(false);

        fireEvent.click(notRegistered());

        expect(document.getElementById('vat_number')).not.toBeDisabled();

        await save();

        expect(sent[0].vat_enabled).toBe(true);
    });
});

describe('قسمُ المالية في الإعدادات', () => {
    it('يبدأ مفتاحَ الضريبة مطفأً حين لا صفّ — كالخادم', () => {
        const page = readFileSync(
            path.resolve(__dirname, '../../resources/js/Pages/Admin/Settings/Index.tsx'),
            'utf8',
        );

        expect(page).toContain("vat_enabled: on('vat_enabled', '0')");
        expect(page).not.toMatch(/vat_enabled: on\('vat_enabled'\)/);
    });
});
