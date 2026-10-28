import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import EmbeddedSignup, {
    isMetaOrigin,
    launchExtras,
    readSignupEvent,
    resetSdkForTests,
    type EmbeddedConfig,
} from '@/Pages/Admin/Integrations/partials/EmbeddedSignup';
import WhatsappLinkCard, { pillFor, type LinkHealth, type WhatsappLink } from '@/Pages/Admin/Integrations/partials/WhatsappLinkCard';

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
     * ═══ والتعايشُ لا يكون افتراضًا ═══
     *
     * كان نوعُ ميزة تطبيق الأعمال يُرسل لكلّ تاجر — فيُفتح مسارُ التعايش
     * لمن يريد رقمًا مستقلًّا. والافتراضُ اليوم رقمُ Cloud API مستقلّ بلا
     * `featureType`، كما في مثال وثيقة ميتا للمسار العاديّ.
     */
    it('يفتح النافذة بإعدادِ ميتا — ورقمٌ مستقلٌّ بلا نوع ميزة التعايش', async () => {
        const login = fakeSdk('CODE-123');
        const { user } = button();

        await user.click(screen.getByRole('button'));

        await waitFor(() => expect(login).toHaveBeenCalled());

        const opts = (login as unknown as { opts: Record<string, unknown> }).opts;

        expect(opts.config_id).toBe('2177821789438050');
        expect(opts.response_type).toBe('code');
        expect(opts.override_default_response_type).toBe(true);
        expect(opts.extras).toEqual({ setup: {}, sessionInfoVersion: '3' });
    });

    /**
     * ═══ `featureType` للتعايش وحده — وبمحاذاة `setup` لا داخلَه ═══
     *
     * وثيقةُ ميتا تضعه في `extras` مباشرةً. ووضعُه داخل `setup` حقلٌ لا
     * تقرؤه النافذة — فيُفتح المسارُ العاديّ بلا أن يُعلن أحدٌ خطأً، ويخرج
     * رقمُ التاجر من تطبيق واتساب للأعمال وهو يظنّه باقيًا فيه.
     */
    it('والتعايشُ مختارًا يحمل نوعَ الميزة في موضعه', () => {
        const extras = launchExtras(CONFIG, 'coexistence');

        expect(extras).toEqual({
            setup: {},
            featureType: 'whatsapp_business_app_onboarding',
            sessionInfoVersion: '3',
        });
        // ولا نوعَ ميزةٍ مدفونٌ داخل `setup`
        expect(extras.setup).toEqual({});
    });

    /** والنسخةُ تُرسل حين تُكتب في الخادم وحده — ولا تُفترض في الشاشة */
    it('يُرسل نسخة التسجيل حين يكتبها الخادم فقط', () => {
        expect(launchExtras(CONFIG, 'cloud_api')).not.toHaveProperty('version');
        expect(launchExtras({ ...CONFIG, version: 'v5' }, 'cloud_api')).toMatchObject({ version: 'v5' });
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

    /** «متّصل» ليس «يستقبل» — والحالُ من الخادم كما هي */
    it('تقول إنّ رمز الربط من تطبيقٍ غير أبعاد وإنّ الرسائل لا تصل', () => {
        const health: LinkHealth = {
            key: 'wrong_app', label: 'تطبيق Meta غير مطابق', tone: 'danger', checked_at: '2026-10-28 14:00',
            hint: 'رمزُ هذا الربط صادرٌ من تطبيقٍ غير Abaad Messaging، فإشعاراتُ الرقم لا تصل أبعاد.',
        };
        render(<WhatsappLinkCard link={{ ...base, state: 'connected' }} health={health} config={CONFIG} mayManage />);

        const box = screen.getByTestId('link-health');
        expect(box).toHaveAttribute('data-state', 'wrong_app');
        expect(box.textContent).toContain('تطبيق Meta غير مطابق');
        expect(box.textContent).toContain('Abaad Messaging');
        expect(screen.getByTestId('link-line').textContent).toContain('رقمك مربوط');
    });

    it('ولا تقول «وصلت رسالة عميل» إلّا حين يقولها الخادم — وحالةُ التسليم ليست رسالة', () => {
        const ready: LinkHealth = { key: 'ready', label: 'جاهز نظريًا لاستقبال الرسائل', hint: '…', tone: 'info', checked_at: null };
        const first = render(<WhatsappLinkCard link={{ ...base, state: 'connected' }} health={ready} config={CONFIG} mayManage />);
        expect(screen.getByTestId('link-health').textContent).not.toContain('رسالة عميل فعلية');
        first.unmount();

        const statuses: LinkHealth = {
            key: 'status_received', label: 'تم استقبال Webhook لحالة تسليم', hint: '…', tone: 'info', checked_at: null,
            last_status_at: '2026-10-28 15:00', last_inbound_at: null,
        };
        const second = render(<WhatsappLinkCard link={{ ...base, state: 'connected' }} health={statuses} config={CONFIG} mayManage />);
        expect(screen.getByTestId('link-health')).toHaveAttribute('data-state', 'status_received');
        expect(screen.getByTestId('link-health').textContent).not.toContain('رسالة عميل فعلية');
        expect(screen.getByTestId('link-health-facts').textContent).toContain('2026-10-28 15:00');
        second.unmount();

        const proven: LinkHealth = {
            key: 'inbound_received', label: 'تم استقبال رسالة عميل فعلية', hint: '…', tone: 'success', checked_at: null,
            last_status_at: '2026-10-28 15:00', last_inbound_at: '2026-10-28 16:00',
        };
        render(<WhatsappLinkCard link={{ ...base, state: 'connected' }} health={proven} config={CONFIG} mayManage />);
        expect(screen.getByTestId('link-health').textContent).toContain('تم استقبال رسالة عميل فعلية');
        expect(screen.getByTestId('link-health-facts').textContent).toContain('2026-10-28 16:00');
    });

    /** والتعايشُ يُختار صراحةً — ومطفأٌ بسببه ما لم يُفتح على الخادم */
    it('تجعل الرقم المستقلّ افتراضًا وتُطفئ التعايش حين لا يُفتح', () => {
        card({ state: 'disconnected' });

        expect(screen.getByTestId('link-kind-cloud_api').querySelector('input')).toBeChecked();
        expect(screen.getByTestId('link-kind-coexistence').querySelector('input')).toBeDisabled();
        expect(screen.getByTestId('link-kind-coexistence').textContent).toContain('غير متاح بعد');
    });

    it('تفتح التعايش اختيارًا حين يُفتح على الخادم', async () => {
        render(<WhatsappLinkCard link={base} config={{ ...CONFIG, coexistence_open: true }} mayManage />);
        const user = userEvent.setup();

        const coexist = screen.getByTestId('link-kind-coexistence').querySelector('input')!;
        expect(coexist).toBeEnabled();
        await user.click(coexist);
        expect(coexist).toBeChecked();
    });

    it('تعرض رسالة ميتا كما قالتها', () => {
        card({ state: 'error', error_message: 'Phone number is not eligible for coexistence.' });

        expect(screen.getByTestId('meta-error').textContent).toContain('not eligible');
    });
});
