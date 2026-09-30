import { beforeEach, describe, expect, it, vi } from 'vitest';
import { dropBootPayload, followIdentity, identityOf } from '@/lib/identity-history';

/**
 * سجلُّ المتصفّح يُمسح حين يتبدّل الجالس — ولا يُمسح في كلّ صفحة.
 *
 * قِيس في متصفّحٍ حقيقيّ: مديرُ المنصّة دخل متجر A ثمّ B، فأعاد «رجوع» صفحةَ
 * A كاملةً من `history.state` بلا طلبٍ إلى الخادم. والمسحُ يُبطل مفتاحَ
 * التشفير فيعجز «رجوع» عن فكّها ويسأل الخادم. وهذا المراقبُ يمسحه من أيّ
 * بابٍ تبدّلت الهويّة — ولو ضاعت علامةُ الخادم مع جلسةٍ فُرّغت.
 */

type Handler = (event: { detail: { page: { props: Record<string, unknown> } } }) => void;

const as = (id: number | null, businessId: number | null = 1, impersonating = false) =>
    id === null ? { auth: null } : { auth: { user: { id, businessId }, impersonating } };

function harness() {
    let handler: Handler = () => {};
    const router = { clearHistory: vi.fn(), on: vi.fn((_e: 'success', cb: Handler) => (handler = cb)) };
    const visit = (props: Record<string, unknown>) => handler({ detail: { page: { props } } });

    return { router, visit };
}

beforeEach(() => sessionStorage.clear());

describe('المسحُ يتبع الجالس', () => {
    it('لا يمسح عند أوّل صفحةٍ في التبويب', () => {
        const { router } = harness();
        followIdentity(as(1, 10), router);

        expect(router.clearHistory).not.toHaveBeenCalled();
    });

    it('ولا في التنقّل والجالسُ هو هو', () => {
        const { router, visit } = harness();
        followIdentity(as(1, 10), router);
        visit(as(1, 10));
        visit(as(1, 10));

        expect(router.clearHistory).not.toHaveBeenCalled();
    });

    it('ويمسح حين يدخل مديرُ المنصّة متجرًا آخر', () => {
        const { router, visit } = harness();
        followIdentity(as(7, 10, true), router);
        visit(as(9, null));
        expect(router.clearHistory).toHaveBeenCalledTimes(1);

        visit(as(8, 20, true));
        expect(router.clearHistory).toHaveBeenCalledTimes(2);
    });

    it('ويمسح حين يخرج المستخدمُ فيبقى ضيفًا أمام شاشة الدخول', () => {
        const { router, visit } = harness();
        followIdentity(as(1, 10), router);
        visit(as(null));

        expect(router.clearHistory).toHaveBeenCalledTimes(1);
    });

    it('ويتذكّر عبر إعادة تحميل الصفحة في التبويب نفسِه', () => {
        followIdentity(as(1, 10), harness().router);

        // مستندٌ جديد في التبويب نفسِه — والجالسُ تغيّر
        const { router } = harness();
        followIdentity(as(2, 20), router);

        expect(router.clearHistory).toHaveBeenCalledTimes(1);
    });

    it('وتعطّلُ التخزين يمسح احتياطًا ولا يُسقط الصفحة', () => {
        const broken = { getItem: () => { throw new Error('denied'); }, setItem: () => { throw new Error('denied'); } } as unknown as Storage;
        const { router } = harness();

        expect(() => followIdentity(as(1, 10), router, broken)).not.toThrow();
        expect(router.clearHistory).toHaveBeenCalled();
    });

    it('والهويّةُ متجرٌ ومستخدمٌ وانتحال', () => {
        expect(identityOf(as(1, 10))).not.toBe(identityOf(as(1, 20)));
        expect(identityOf(as(1, 10, false))).not.toBe(identityOf(as(1, 10, true)));
        expect(identityOf(as(null))).toBe('guest');
    });
});

describe('وخصائصُ الصفحة الأولى تُرفع من المستند بعد قراءتها', () => {
    it('لا يبقى وسمُ `data-page` في المصدر — وما سواه باقٍ', () => {
        document.body.innerHTML =
            '<script data-page="app" type="application/json">{"props":{"jobTitles":["TENANT-A-ONLY"]}}</script>' +
            '<div id="app"></div><script type="application/json" id="other">{}</script>';

        dropBootPayload();

        expect(document.body.innerHTML).not.toContain('TENANT-A-ONLY');
        expect(document.getElementById('app')).not.toBeNull();
        expect(document.getElementById('other')).not.toBeNull();
    });
});
