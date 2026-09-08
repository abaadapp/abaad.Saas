import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { UnitPicker } from '@/Pages/Admin/Purchases/Create';

/**
 * منتقي وحدة الشراء.
 *
 * وخطأُ هذا الحقل لا تراه اختباراتُ الخادم: الخادمُ يقبل أيَّ نصّ، والعطبُ
 * كان في الشاشة — قائمةٌ يرسمها نظامُ التشغيل لا سهمَ يقول إنّها هناك، فلا
 * يفتحها التاجرُ ولا يعرف أنّه يستطيع كتابة وحدةٍ ليست فيها.
 */

const UNITS = ['حبة', 'صندوق', 'كيلو'];

function draw(value = 'حبة') {
    const on = { onPick: vi.fn(), onAdd: vi.fn() };
    render(<UnitPicker value={value} units={UNITS} {...on} />);

    return { ...on, user: userEvent.setup() };
}

const open = async (user: ReturnType<typeof userEvent.setup>) =>
    user.click(screen.getByRole('button', { name: 'وحدة الشراء' }));

describe('القائمة تُرسم في الصفحة', () => {
    it('والحقلُ يقول وحدتَه قبل أن يُفتح', () => {
        draw('صندوق');

        expect(screen.getByRole('button', { name: 'وحدة الشراء' })).toHaveTextContent('صندوق');
    });

    it('ولا تُعرض قبل الضغط', () => {
        draw();

        expect(screen.queryByRole('button', { name: 'كيلو' })).not.toBeInTheDocument();
    });

    it('والضغطُ يعرض الوحدات كلَّها', async () => {
        const { user } = draw();
        await open(user);

        for (const u of UNITS) {
            expect(screen.getByRole('button', { name: new RegExp(u) })).toBeInTheDocument();
        }
    });

    it('واختيارُ وحدةٍ يقولها ويُغلق', async () => {
        const { onPick, user } = draw();
        await open(user);
        await user.click(screen.getByRole('button', { name: /صندوق/ }));

        expect(onPick).toHaveBeenCalledWith('صندوق');
        expect(screen.queryByRole('button', { name: /كيلو/ })).not.toBeInTheDocument();
    });
});

describe('والقائمةُ لا تحدّ', () => {
    it('وحدةٌ ليست فيها تُضاف بالكتابة', async () => {
        const { onPick, onAdd, user } = draw();
        await open(user);
        await user.type(screen.getByRole('textbox'), 'شتلة');
        await user.click(screen.getByRole('button', { name: /أضف/ }));

        expect(onPick).toHaveBeenCalledWith('شتلة');
        // ‏وتبقى مع صاحبها إلى السطر التالي
        expect(onAdd).toHaveBeenCalledWith('شتلة');
    });

    it('ولا يُعرض «أضف» لما هو في القائمة أصلًا', async () => {
        const { user } = draw();
        await open(user);
        await user.type(screen.getByRole('textbox'), 'كيلو');

        expect(screen.queryByRole('button', { name: /أضف/ })).not.toBeInTheDocument();
    });

    it('والبحثُ يرشّح ما يُعرض', async () => {
        const { user } = draw();
        await open(user);
        await user.type(screen.getByRole('textbox'), 'صند');

        expect(screen.getByRole('button', { name: /صندوق/ })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /كيلو/ })).not.toBeInTheDocument();
    });

    it('و«Enter» تأخذ أوّلَ ما طابق لا ما نصفَ كُتب', async () => {
        const { onPick, user } = draw();
        await open(user);
        await user.type(screen.getByRole('textbox'), 'صند{Enter}');

        expect(onPick).toHaveBeenCalledWith('صندوق');
    });

    it('وما لم يطابق شيئًا تأخذه كما كُتب', async () => {
        const { onPick, user } = draw();
        await open(user);
        await user.type(screen.getByRole('textbox'), 'شتلة{Enter}');

        expect(onPick).toHaveBeenCalledWith('شتلة');
    });

    it('ولا تُقبل مسافاتٌ وحدَها', async () => {
        const { onPick, user } = draw();
        await open(user);
        await user.type(screen.getByRole('textbox'), '   {Enter}');

        expect(onPick).not.toHaveBeenCalled();
    });
});
