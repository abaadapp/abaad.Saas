import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import ComboBox from '@/Components/ComboBox';

/**
 * المنتقي الواحد — يخدم وحدةَ الشراء وتصنيفَ الأصل.
 *
 * وخطأُ هذا الحقل لا تراه اختباراتُ الخادم: الخادمُ يقبل أيَّ نصّ، والعطبُ
 * كان في الشاشة — قائمةٌ يرسمها نظامُ التشغيل لا سهمَ يقول إنّها هناك، فلا
 * يفتحها التاجرُ ولا يعرف أنّه يستطيع كتابة ما ليس فيها.
 */

const UNITS = ['حبة', 'صندوق', 'كيلو'];

function draw(value = 'حبة') {
    const on = { onPick: vi.fn(), onAdd: vi.fn(), onDelete: vi.fn() };
    render(<ComboBox value={value} options={UNITS} label="وحدة الشراء" placeholder="اختر الوحدة" {...on} />);

    return { ...on, user: userEvent.setup() };
}

/** بلا `onDelete` — كما تستعملها شاشةُ الأصول */
function drawPlain() {
    const on = { onPick: vi.fn(), onAdd: vi.fn() };
    render(<ComboBox value="حبة" options={UNITS} label="التصنيف" placeholder="اختر التصنيف" {...on} />);

    return { ...on, user: userEvent.setup() };
}

const open = async (user: ReturnType<typeof userEvent.setup>) =>
    user.click(screen.getByRole('button', { name: 'وحدة الشراء' }));

describe('القائمة تُرسم في الصفحة', () => {
    it('والحقلُ يقول وحدتَه قبل أن يُفتح', () => {
        draw('صندوق');

        expect(screen.getByRole('button', { name: 'وحدة الشراء' })).toHaveTextContent('صندوق');
    });

    it('وحقلٌ لم يُختر له شيءٌ يقول ما يُنتظر منه', () => {
        draw('');

        expect(screen.getByRole('button', { name: 'وحدة الشراء' })).toHaveTextContent('اختر الوحدة');
    });

    it('ولا تُعرض قبل الضغط', () => {
        draw();

        expect(screen.queryByRole('button', { name: 'كيلو' })).not.toBeInTheDocument();
    });

    it('والضغطُ يعرض الوحدات كلَّها', async () => {
        const { user } = draw();
        await open(user);

        // ‏بالاسم تمامًا: «احذف «حبة»» زرٌّ آخرُ في الصفّ نفسه
        for (const u of UNITS) {
            expect(screen.getByRole('button', { name: u })).toBeInTheDocument();
        }
    });

    it('واختيارُ وحدةٍ يقولها ويُغلق', async () => {
        const { onPick, user } = draw();
        await open(user);
        await user.click(screen.getByRole('button', { name: 'صندوق' }));

        expect(onPick).toHaveBeenCalledWith('صندوق');
        expect(screen.queryByRole('button', { name: 'كيلو' })).not.toBeInTheDocument();
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

        expect(screen.getByRole('button', { name: 'صندوق' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'كيلو' })).not.toBeInTheDocument();
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

describe('وما يُضاف يُرفع', () => {
    it('ولكلّ خيارٍ زرُّ رفعٍ من القائمة', async () => {
        const { onDelete, user } = draw();
        await open(user);
        await user.click(screen.getByRole('button', { name: 'احذف «كيلو»' }));

        expect(onDelete).toHaveBeenCalledWith('كيلو');
    });

    it('والرفعُ لا يختار ما رُفع', async () => {
        const { onPick, user } = draw();
        await open(user);
        await user.click(screen.getByRole('button', { name: 'احذف «كيلو»' }));

        expect(onPick).not.toHaveBeenCalled();
    });

    it('ولا يُرسم زرُّ رفعٍ لمن لا يملك رفعًا', async () => {
        const { user } = drawPlain();
        await user.click(screen.getByRole('button', { name: 'التصنيف' }));

        expect(screen.queryByRole('button', { name: /احذف/ })).not.toBeInTheDocument();
        // ‏والقائمةُ نفسُها تُعرض كما هي
        expect(screen.getByRole('button', { name: 'كيلو' })).toBeInTheDocument();
    });
});
