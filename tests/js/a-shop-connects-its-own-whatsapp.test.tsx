import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import EmbeddedSignup, {
    isMetaOrigin,
    readSignupEvent,
    resetSdkForTests,
    type EmbeddedConfig,
} from '@/Pages/Admin/Integrations/partials/EmbeddedSignup';
import WhatsappLinkCard, { pillFor, type WhatsappLink } from '@/Pages/Admin/Integrations/partials/WhatsappLinkCard';

/**
 * التسجيل المدمج في المتصفّح — ما يُقبل من ميتا، وما لا يُعدّ نجاحًا.
 *
 * ═══ ولمَ يُحرَس فحصُ الأصل بأكثر من حالةٍ واحدة ═══
 *
 * وثيقةُ ميتا نفسُها تكتب `origin.endsWith('facebook.com')` — وهي ناقصة:
 * `https://evil-facebook.com` ينتهي بها، و`http://facebook.com` ينتهي بها
 * وهو غيرُ مشفَّر. ورسالةٌ تُقبل من أحدهما تحمل معرّفاتٍ نربط بها رقمًا.
 *
 * فالحالاتُ هنا تُعدّد ما يجب أن يُردّ بعينه — لا «يعمل في الحالة السعيدة».
 */

const CONFIG: EmbeddedConfig = {
    configured: true,
    app_id: '1082481610822941',
    config_id: '2177821789438050',
    graph_version: 'v26.0',
    feature_type: 'whatsapp_business_app_onboarding',
    session_info_version: '3',
};

afterEach(() => {
    resetSdkForTests();
    delete (window as { FB?: unknown }).FB;
});

describe('فحصُ أصل الرسالة', () => {
    it('يقبل ميتا وحدَها على HTTPS', () => {
        expect(isMetaOrigin('https://www.facebook.com')).toBe(true);
        expect(isMetaOrigin('https://facebook.com')).toBe(true);
        expect(isMetaOrigin('https://business.facebook.com')).toBe(true);
    });

    it('يردّ نطاقًا ينتهي بالاسم وليس منه', () => {
        // ينجح في `endsWith` ويسقط هنا — وهذا هو الفرق كلُّه
        expect(isMetaOrigin('https://evil-facebook.com')).toBe(false);
        expect(isMetaOrigin('https://notfacebook.com')).toBe(false);
    });

    it('يردّ ما ليس مشفَّرًا', () => {
        expect(isMetaOrigin('http://www.facebook.com')).toBe(false);
    });

    it('يردّ منفذًا غيرَ الافتراضيّ', () => {
        expect(isMetaOrigin('https://www.facebook.com:8443')).toBe(false);
    });

    it('يردّ ما ليس عنوانًا أصلًا', () => {
        expect(isMetaOrigin('facebook.com')).toBe(false);
        expect(isMetaOrigin('')).toBe(false);
        expect(isMetaOrigin('null')).toBe(false);
    });
});

describe('قراءةُ حدث ميتا', () => {
    const wrap = (event: string, data: Record<string, unknown> = {}) =>
        JSON.stringify({ type: 'WA_EMBEDDED_SIGNUP', event, data });

    it('تقرأ FINISH العاديّة بمعرّفاتها', () => {
        const out = readSignupEvent(wrap('FINISH', { waba_id: 'W1', phone_number_id: 'P1', business_id: 'B1' }));

        expect(out).toEqual({ type: 'FINISH', assets: { waba_id: 'W1', phone_number_id: 'P1', business_id: 'B1' } });
    });

    /**
     * ومسارُ تطبيق واتساب للأعمال اسمُ حدثه غيرُ ذاك.
     *
     * `FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING` — ولو قُرئ `FINISH` وحدَها
     * لَصمتت الشاشةُ على كلّ ربطٍ في هذا المسار بعينه: يُتمّ التاجر خطوات
     * ميتا ولا يحدث شيء.
     */
    it('تقرأ FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING كنجاح', () => {
        const out = readSignupEvent(wrap('FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING', { waba_id: 'W1' }));

        expect(out?.type).toBe('FINISH');
        expect(out).toMatchObject({ assets: { waba_id: 'W1', phone_number_id: null } });
    });

    it('تقرأ الإلغاء والخطأ', () => {
        expect(readSignupEvent(wrap('CANCEL', { current_step: 'PHONE_NUMBER' }))).toEqual({
            type: 'CANCEL', step: 'PHONE_NUMBER',
        });
        expect(readSignupEvent(wrap('ERROR', { error_message: 'no' }))).toEqual({ type: 'ERROR', message: 'no' });
    });

    it('تتجاهل ما ليس من التسجيل المدمج', () => {
        expect(readSignupEvent(JSON.stringify({ type: 'SOMETHING_ELSE', event: 'FINISH' }))).toBeNull();
        expect(readSignupEvent('not json at all')).toBeNull();
        expect(readSignupEvent(null)).toBeNull();
        expect(readSignupEvent(42)).toBeNull();
    });

    it('تقرأ الكائنَ كما تقرأ النصّ', () => {
        expect(readSignupEvent({ type: 'WA_EMBEDDED_SIGNUP', event: 'FINISH', data: { waba_id: 'W9' } })).toMatchObject({
            type: 'FINISH', assets: { waba_id: 'W9' },
        });
    });
});

describe('زرُّ الربط', () => {
    /** عدّةُ ميتا كما تبدو للشاشة — ونلتقط ما تُمرَّر به `FB.login` */
    function fakeSdk(code: string | null, message?: { origin: string; data: unknown }) {
        const login = vi.fn((cb: (r: unknown) => void, opts: unknown) => {
            (login as unknown as { opts?: unknown }).opts = opts;

            /*
             * وترتيبُ الحدثين كما يقع فعلًا: النافذةُ تُرسل معرّفاتِها
             * أثناءَ فتحها، ثمّ يردّ `FB.login` بالكود بعد إغلاقها.
             *
             * وأوّلُ كتابةٍ لهذا الاختبار أرسلت الرسالةَ **قبل** الضغط —
             * فمرّت على `assets.current = {}` التي تُصفّر عند كلّ فتحة،
             * وسقط الاختبارُ على ترتيبٍ لا يقع في متصفّح.
             */
            if (message) {
                window.dispatchEvent(new MessageEvent('message', {
                    origin: message.origin,
                    data: message.data as string,
                }));
            }

            cb(code ? { authResponse: { code } } : { authResponse: null });
        });

        (window as { FB?: unknown }).FB = { init: vi.fn(), login };

        /* ونُهيّئ الوعدَ على عدّةٍ حاضرة — لا نُحمّل نصًّا من الشبكة في الاختبار */
        Object.defineProperty(window, 'fbAsyncInit', {
            configurable: true,
            set(fn: () => void) {
                fn();
            },
            get() {
                return undefined;
            },
        });

        return login;
    }

    const button = (config = CONFIG, onCode = vi.fn()) => {
        render(
            <EmbeddedSignup config={config} onCode={onCode}>
                {({ open, busy }) => (
                    <button type="button" onClick={open} disabled={busy}>
                        اربط
                    </button>
                )}
            </EmbeddedSignup>,
        );

        return { onCode, user: userEvent.setup() };
    };

    /**
     * ═══ `featureType` بمحاذاة `setup` لا داخلَه ═══
     *
     * وثيقةُ ميتا تضعه في `extras` مباشرةً. ووضعُه داخل `setup` حقلٌ لا
     * تقرؤه النافذة — فيُفتح المسارُ العاديّ بلا أن يُعلن أحدٌ خطأً، ويخرج
     * رقمُ التاجر من تطبيق واتساب للأعمال وهو يظنّه باقيًا فيه.
     */
    it('يفتح النافذة بإعدادِ ميتا وبنوع الميزة في موضعه', async () => {
        const login = fakeSdk('CODE-123');
        const { user } = button();

        await user.click(screen.getByRole('button'));

        await waitFor(() => expect(login).toHaveBeenCalled());

        const opts = (login as unknown as { opts: Record<string, unknown> }).opts;

        expect(opts.config_id).toBe('2177821789438050');
        expect(opts.response_type).toBe('code');
        expect(opts.override_default_response_type).toBe(true);
        expect(opts.extras).toEqual({
            setup: {},
            featureType: 'whatsapp_business_app_onboarding',
            sessionInfoVersion: '3',
        });
        // ولا نوعَ ميزةٍ مدفونٌ داخل `setup`
        expect((opts.extras as { setup: Record<string, unknown> }).setup).toEqual({});
    });

    it('يسلّم الكودَ مع ما التقطه المستمعُ من ميتا', async () => {
        fakeSdk('CODE-123', {
            origin: 'https://www.facebook.com',
            data: JSON.stringify({
                type: 'WA_EMBEDDED_SIGNUP',
                event: 'FINISH',
                data: { waba_id: 'W1', phone_number_id: 'P1' },
            }),
        });
        const { onCode, user } = button();

        await user.click(screen.getByRole('button'));

        await waitFor(() => expect(onCode).toHaveBeenCalledTimes(1));
        expect(onCode).toHaveBeenCalledWith('CODE-123', { waba_id: 'W1', phone_number_id: 'P1', business_id: null });
    });

    /** ورسالةٌ من أصلٍ مزوَّر لا تُقرأ منها معرّفات */
    it('لا يقرأ معرّفاتٍ من أصلٍ ليس ميتا', async () => {
        fakeSdk('CODE-123', {
            origin: 'https://evil-facebook.com',
            data: JSON.stringify({
                type: 'WA_EMBEDDED_SIGNUP',
                event: 'FINISH',
                data: { waba_id: 'HACKED', phone_number_id: 'HACKED' },
            }),
        });
        const { onCode, user } = button();

        await user.click(screen.getByRole('button'));

        await waitFor(() => expect(onCode).toHaveBeenCalled());
        expect(onCode).toHaveBeenCalledWith('CODE-123', {});
    });

    it('لا يسلّم شيئًا حين لا يعود كود', async () => {
        fakeSdk(null);
        const { onCode, user } = button();

        await user.click(screen.getByRole('button'));

        await waitFor(() => expect(screen.getByRole('button')).not.toBeDisabled());
        expect(onCode).not.toHaveBeenCalled();
    });

    it('لا يفتح شيئًا حين ينقص إعدادُ الخادم', async () => {
        const login = fakeSdk('CODE-123');
        const { user } = button({ ...CONFIG, configured: false });

        await user.click(screen.getByRole('button'));

        expect(login).not.toHaveBeenCalled();
    });
});

describe('بطاقةُ الحال', () => {
    const base: WhatsappLink = {
        state: 'disconnected', label: 'غير متصل', waba_id: null, meta_business_id: null,
        phone_number_id: null, display_phone_number: null, coexistence: false,
        connected_at: null, connected_by: null, last_webhook_at: null,
        expires_at: null, days_left: null, alert_days: null, error_code: null, error_message: null,
    };

    const card = (link: Partial<WhatsappLink>, mayManage = true) =>
        render(<WhatsappLinkCard link={{ ...base, ...link }} config={CONFIG} mayManage={mayManage} />);

    /** ستُّ حالاتٍ لكلٍّ منها جملةٌ ولونٌ — ولا حالَ بلا سطرٍ يقرؤه التاجر */
    it('تقول لكلّ حالٍ جملتَها', () => {
        const lines: [WhatsappLink['state'], string][] = [
            ['disconnected', 'لم يُربط رقمٌ بعد'],
            ['connecting', 'لم يُظهر رقمًا بعد'],
            ['connected', 'رقمك مربوط'],
            ['reauthorization_required', 'جدّده قبل أن تقف الرسائل'],
            ['expired', 'انتهت صلاحية التفويض'],
            ['error', 'حدث خطأ في الربط'],
        ];

        for (const [state, text] of lines) {
            const { unmount } = card({ state });
            expect(screen.getByTestId('link-line').textContent).toContain(text);
            unmount();
        }
    });

    /** و«يحتاج إعادة تفويض» ليست حمراءَ: هو يعمل اليوم */
    it('تُفرّق بين ما يوشك وما وقف', () => {
        expect(pillFor('reauthorization_required')).toBe('action');
        expect(pillFor('expired')).toBe('error');
        expect(pillFor('connected')).toBe('ready');
        expect(pillFor('connecting')).toBe('progress');
        expect(pillFor('disconnected')).toBe('idle');
    });

    it('تكتب عتبةَ التنبيه كما حسبها الخادم', () => {
        card({ state: 'reauthorization_required', alert_days: 7 });

        expect(screen.getByTestId('link-line').textContent).toContain('7');
    });

    it('تقول إنّ الرقم باقٍ في تطبيق واتساب للأعمال حين يكون كذلك', () => {
        card({ state: 'connected', coexistence: true, display_phone_number: '+968 9525 9066' });

        expect(screen.getByTestId('link-facts').textContent).toContain('يعمل مع النظام على الرقم نفسه');
    });

    /** وزرُّ الربط لا يُعرض لمن لا يملكه — ولا زرُّ الفصل */
    it('تُخفي الأزرار عمّن ليس مديرًا', () => {
        card({ state: 'connected' }, false);

        expect(screen.queryByTestId('connect-whatsapp')).toBeNull();
        expect(screen.queryByTestId('disconnect-whatsapp')).toBeNull();
    });

    it('تعرض رسالة ميتا كما قالتها', () => {
        card({ state: 'error', error_message: 'Phone number is not eligible for coexistence.' });

        expect(screen.getByTestId('meta-error').textContent).toContain('not eligible');
    });
});
