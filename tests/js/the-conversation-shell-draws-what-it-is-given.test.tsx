import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { MessageComposer } from '@/Components/conversations/Composer';
import { ConversationDetailsPanel } from '@/Components/conversations/Details';
import { ConversationListItem, ConversationShell } from '@/Components/conversations/Shell';
import { AttachmentCard, MessageBubble } from '@/Components/conversations/Thread';

/**
 * قشرةُ المحادثات ترسم ما تُعطى — ولا تعرف دعمًا من مبيعات.
 *
 * ما يُقاس هنا شكلُ ما يراه الموظّف ويضغطه: أيُّ عمودٍ يُعرض على الهاتف،
 * وشارةُ ما لم يُقرأ، والملاحظةُ الداخليّة بكلمتها لا بلونها وحده، ورابطُ
 * المرفق كما أُعطي، وزرُّ الإرسال الذي لا يُضغط على فراغ.
 */
describe('قشرةُ المحادثات', () => {
    it('على الهاتف يُعرض عمودٌ واحد — والقائمةُ تبقى على الشاشة المتوسّطة', () => {
        const { container } = render(
            <ConversationShell pane="thread" list={<i>list</i>} thread={<i>thread</i>} details={<i>details</i>} />,
        );
        const [list, thread, details] = Array.from(container.querySelectorAll('section'));

        expect(list.className).toContain('hidden lg:flex');
        expect(thread.className).not.toMatch(/(^|\s)hidden(\s|$)/);
        expect(details.className).toContain('hidden xl:flex');
    });

    it('وحين تُفتح التفاصيل يُخفى الخيطُ حتى الشاشة العريضة', () => {
        const { container } = render(
            <ConversationShell pane="details" list={<i />} thread={<i />} details={<i />} />,
        );
        const [, thread, details] = Array.from(container.querySelectorAll('section'));

        expect(thread.className).toContain('hidden xl:flex');
        expect(details.className).not.toMatch(/(^|\s)hidden(\s|$)/);
    });
});

describe('صفُّ القائمة', () => {
    const row = (unread: number, active = false, onClick = vi.fn()) =>
        render(
            <ul>
                <ConversationListItem
                    title="محل الورد"
                    subtitle="الفاتورة لا تُطبع"
                    preview="أضغط طباعة فلا يحدث شيء"
                    time="أمس"
                    unread={unread}
                    avatar={<b>و</b>}
                    active={active}
                    onClick={onClick}
                />
            </ul>,
        );

    it('شارةُ ما لم يُقرأ تُرسم حين يكون شيءٌ لم يُقرأ — وصفرٌ لا يُرسم', () => {
        row(3);
        expect(screen.getByLabelText('3')).toHaveTextContent('3');
    });

    it('وصفرٌ لا يُرسم', () => {
        row(0);
        expect(screen.queryByLabelText('0')).toBeNull();
    });

    it('والمفتوحةُ معلَّمةٌ للقارئ ولقارئ الشاشة، والضغطُ يفتح', () => {
        const onClick = vi.fn();
        row(0, true, onClick);
        const button = screen.getByRole('button');
        expect(button).toHaveAttribute('aria-current', 'true');
        fireEvent.click(button);
        expect(onClick).toHaveBeenCalledTimes(1);
    });
});

describe('الفقاعة والمرفق', () => {
    it('الملاحظةُ الداخليّة تُقال بكلمتها لا بلونها وحده', () => {
        render(<MessageBubble side="out" tone="internal" internalLabel="ملاحظة داخلية" body="لا نردّ قبل الغد" />);
        expect(screen.getByText('ملاحظة داخلية')).toBeInTheDocument();
    });

    it('وردٌّ عاديٌّ لا يحمل الكلمة', () => {
        render(<MessageBubble side="out" internalLabel="ملاحظة داخلية" body="وصلنا" />);
        expect(screen.queryByText('ملاحظة داخلية')).toBeNull();
    });

    it('والمرفقُ يقود إلى الرابط الذي أُعطي — لا يبني مسارًا من عنده', () => {
        render(<AttachmentCard name="receipt.pdf" size={2048} image={false} url="/support/7/files/3" />);
        expect(screen.getByRole('link')).toHaveAttribute('href', '/support/7/files/3');
    });

    it('والصورةُ تُعرض صورةً لا اسمًا يُقرأ', () => {
        render(<AttachmentCard name="shot.png" size={2048} image url="/crm/9/files/4" />);
        expect(screen.getByRole('img')).toHaveAttribute('src', '/crm/9/files/4');
    });
});

describe('المُحرِّر', () => {
    const composer = (value: string, processing = false) => {
        const onSend = vi.fn();
        const onAddFiles = vi.fn();
        const onRemoveFile = vi.fn();
        render(
            <MessageComposer
                value={value}
                onChange={() => {}}
                onSend={onSend}
                files={[new File(['x'], 'a.png')]}
                onAddFiles={onAddFiles}
                onRemoveFile={onRemoveFile}
                accept=".png"
                maxFiles={3}
                maxKb={5120}
                processing={processing}
                placeholder="اكتب رسالتك..."
            />,
        );
        return { onSend, onAddFiles, onRemoveFile };
    };

    it('لا يُرسل فراغ', () => {
        composer('   ');
        expect(screen.getByRole('button', { name: 'إرسال' })).toBeDisabled();
    });

    it('ولا يُرسل مرّتين: الزرُّ مقفلٌ ما دام الردُّ في الطريق', () => {
        composer('مرحبًا', true);
        expect(screen.getByRole('button', { name: 'إرسال' })).toBeDisabled();
    });

    it('ويُرسل بالزرّ وبـCtrl+Enter — لا بـEnter وحدها', () => {
        const { onSend } = composer('مرحبًا');
        const box = screen.getByPlaceholderText('اكتب رسالتك...');

        fireEvent.keyDown(box, { key: 'Enter' });
        expect(onSend).not.toHaveBeenCalled();

        fireEvent.keyDown(box, { key: 'Enter', ctrlKey: true });
        fireEvent.click(screen.getByRole('button', { name: 'إرسال' }));
        expect(onSend).toHaveBeenCalledTimes(2);
    });

    it('والملفُّ المرفق يُرسم ويُزال بموضعه', () => {
        const { onRemoveFile } = composer('x');
        expect(screen.getByText('a.png')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'إزالة' }));
        expect(onRemoveFile).toHaveBeenCalledWith(0);
    });
});

describe('لوحةُ التفاصيل', () => {
    it('تبويباتُها تُبدَّل بالضغط', () => {
        const onChange = vi.fn();
        render(
            <ConversationDetailsPanel
                title="التفاصيل"
                onBack={() => {}}
                tabs={{
                    items: [
                        { key: 'info', label: 'معلومات' },
                        { key: 'signals', label: 'إشارات' },
                    ],
                    value: 'info',
                    onChange,
                }}
            >
                <p>محتوى</p>
            </ConversationDetailsPanel>,
        );

        expect(screen.getByRole('tab', { name: 'معلومات' })).toHaveAttribute('aria-selected', 'true');
        fireEvent.click(screen.getByRole('tab', { name: 'إشارات' }));
        expect(onChange).toHaveBeenCalledWith('signals');
    });
});
