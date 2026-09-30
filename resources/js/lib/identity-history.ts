/**
 * سجلُّ المتصفّح يُمسح حين يتبدّل الجالسُ أمام الشاشة — من أيّ بابٍ تبدّل.
 *
 * ═══ ولمَ في الواجهة وقد مسحه الخادم ═══
 *
 * الخادمُ يمسحه حين يعرف أنّ الهويّة تبدّلت: الدخولُ (مستمعُ `Login`)،
 * والخروجُ من زرّه، ودخولُ مدير المنصّة إلى متجرٍ وعودتُه (`TenantSwitch`).
 * لكنّ أبوابًا أخرى تُخرج المستخدم وتفرّغ جلستَه — متجرٌ عُطّل، جهازٌ فقد
 * فرعَه — فتضيع علامةُ المسح مع الجلسة. وبابٌ يُضاف غدًا يُنسى فيه المسح.
 *
 * فالواجهةُ تسأل سؤالًا واحدًا في كلّ صفحة: هل من يجلس الآن هو من جلس في
 * الصفحة السابقة؟ فإن لم يكن مُسح السجلّ — فيعجز «رجوع» عن فكّ ما كُتب
 * لغيره ويسأل الخادمَ من جديد. انظر `config/inertia.php`.
 */

const KEY = 'abaad.history-identity';

type Props = Record<string, unknown>;

interface HistoryRouter {
    clearHistory: () => void;
    on: (event: 'success', cb: (event: { detail: { page: { props: Props } } }) => void) => unknown;
}

/** من يجلس الآن: المستخدمُ ومتجرُه، وهل هو منتحِل — أو «ضيف» */
export function identityOf(props: Props): string {
    const auth = props.auth as { user?: { id?: number; businessId?: number | null }; impersonating?: boolean } | null | undefined;
    const user = auth?.user;

    return user ? `${user.id}:${user.businessId ?? '-'}:${auth?.impersonating ? 'as' : 'self'}` : 'guest';
}

/**
 * يقارن الهويّة بما حُفظ في هذا التبويب، ويمسح السجلّ إن اختلفت.
 *
 * والتخزينُ قد يُمنع (تصفّحٌ خاصّ، أو سياسةُ متصفّح) — فلا يسقط التطبيق:
 * يُمسح السجلّ احتياطًا لا يُترك.
 */
export function followIdentity(initial: Props, router: HistoryRouter, storage: Storage | null = safeStorage()): void {
    const check = (props: Props) => {
        const now = identityOf(props);
        let last: string | null = null;

        try {
            last = storage?.getItem(KEY) ?? null;
        } catch {
            last = null;
        }

        if (last !== null && last !== now) {
            router.clearHistory();
        }

        try {
            storage?.setItem(KEY, now);
        } catch {
            router.clearHistory();
        }
    };

    check(initial);
    router.on('success', (event) => check(event.detail.page.props));
}

function safeStorage(): Storage | null {
    try {
        return window.sessionStorage;
    } catch {
        return null;
    }
}

/**
 * وخصائصُ الصفحة الأولى لا تبقى في المستند بعد أن قُرئت.
 *
 * الخادمُ يكتبها في `<script data-page type="application/json">` ويقرؤها
 * `createInertiaApp` مرّةً عند الإقلاع — ثمّ تبقى في الـDOM ما عاش المستند.
 * فمن فتح «إضافة موظّف» في متجر A تحميلًا كاملًا ثمّ تنقّل إلى B بزياراتِ
 * Inertia، بقيت مسمّياتُ A وموظّفوه في مصدر الصفحة وهو في B — لا تُرى على
 * الشاشة، وتُقرأ من أدوات المتصفّح. قِيس في متصفّحٍ حقيقيّ.
 *
 * ولا يعود إليها أحد: Inertia تقرأ ما بعدها من الخادم ومن `history.state`.
 */
export function dropBootPayload(doc: Document = document): void {
    doc.querySelectorAll('script[data-page][type="application/json"]').forEach((el) => el.remove());
}
