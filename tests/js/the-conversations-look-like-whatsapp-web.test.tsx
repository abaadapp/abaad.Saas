import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { ConversationListItem } from '@/Components/conversations/Shell';
import { MessageBubble } from '@/Components/conversations/Thread';

/**
 * شاشتا المحادثات بشكل واتساب ويب — وما يحمله الشكلُ من معنى.
 *
 * ═══ ما يُحرس ═══
 *
 * - حالُ التسليم علامةٌ داخل الفقاعة: ✓ خرجت، ✓✓ وصلت، ✓✓ زرقاء قُرئت،
 *   وتنبيهٌ لما لم يخرج — وكلمتُها لقارئ الشاشة دائمًا، فلا يُحمَّل الشكلُ
 *   معنًى وحدَه.
 * - والوارد لا علامةَ تسليمٍ عليه، والملاحظةُ الداخليّة كذلك.
 * - والذيلُ لأوّل فقاعةٍ في سلسلة جهتها وحدَها.
 * - والوقتُ داخل الفقاعة.
 * - وما لم يُقرأ في القائمة شارةٌ خضراء، ووقتُه أخضر.
 */
const bubble = (over: Partial<Parameters<typeof MessageBubble>[0]> = {}) =>
    render(<MessageBubble side="out" body="أهلًا" time="10:42" {...over} />);

describe('الفقاعة', () => {
    it('قُرئت: علامتان زرقاوان — وكلمتُها لقارئ الشاشة', () => {
        bubble({ status: 'read', statusLabel: 'قُرئت' });

        const mark = screen.getByRole('img', { name: 'قُرئت' });
        expect(mark.className).toContain('--cv-tick-read');
    });

    it('خرجت ولم تصل: علامةٌ رماديّة — ووصلت: علامتان رماديّتان', () => {
        const { unmount } = bubble({ status: 'sent', statusLabel: 'أُرسلت' });
        expect(screen.getByRole('img', { name: 'أُرسلت' }).className).toContain('--cv-tick,');
        unmount();

        bubble({ status: 'delivered', statusLabel: 'وصلت' });
        expect(screen.getByRole('img', { name: 'وصلت' }).className).not.toContain('--cv-tick-read');
    });

    it('ولم تخرج: تنبيهٌ أحمر بكلمته', () => {
        bubble({ status: 'failed', statusLabel: 'لم تُرسل' });

        expect(screen.getByRole('img', { name: 'لم تُرسل' }).className).toContain('--cv-fail');
    });

    it('والواردُ لا علامةَ تسليمٍ عليه — ولا الملاحظةُ الداخليّة', () => {
        const { unmount } = bubble({ side: 'in', status: 'read', statusLabel: 'قُرئت' });
        expect(screen.queryByRole('img', { name: 'قُرئت' })).toBeNull();
        unmount();

        bubble({ tone: 'internal', internalLabel: 'ملاحظة داخلية', status: 'sent', statusLabel: 'أُرسلت' });
        expect(screen.queryByRole('img', { name: 'أُرسلت' })).toBeNull();
    });

    it('والوقتُ داخل الفقاعة لا فوقها', () => {
        bubble();

        const body = screen.getByText('أهلًا');
        expect(body.parentElement).toHaveTextContent('10:42');
    });

    it('والذيلُ لأوّل فقاعةٍ في سلسلتها وحدَها', () => {
        const { container, unmount } = bubble({ first: true });
        expect(container.querySelector('[aria-hidden="true"][class*="clip-path"]')).not.toBeNull();
        unmount();

        const next = bubble({ first: false });
        expect(next.container.querySelector('[aria-hidden="true"][class*="clip-path"]')).toBeNull();
    });
});

describe('صفُّ القائمة', () => {
    it('ما لم يُقرأ شارةٌ خضراءُ بعدده، ووقتُه أخضر', () => {
        render(
            <ul>
                <ConversationListItem title="محل الورد" preview="متى يصل؟" time="10:40" unread={2} avatar={null} active={false} onClick={() => {}} />
            </ul>,
        );

        expect(screen.getByLabelText('2').className).toContain('--cv-unread,#25d366');
        expect(screen.getByText('10:40').className).toContain('--cv-unread');
    });
});
