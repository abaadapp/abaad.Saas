import { useCallback, useEffect, useRef, useState } from 'react';

/** ما يعرفه الخادمُ عن التطبيق وإعداد التسجيل — ولا سرَّ فيه */
export interface EmbeddedConfig {
    configured: boolean;
    app_id: string;
    config_id: string;
    graph_version: string;
    /** `whatsapp_business_app_onboarding` — يُقرأ من الخادم ولا يُكتب هنا */
    feature_type: string;
    session_info_version: string;
}

/** ما تلتقطه الشاشةُ من نافذة ميتا — اقتراحٌ يُرسَل ليُفحص في الخادم */
export interface SignupAssets {
    waba_id?: string | null;
    phone_number_id?: string | null;
    business_id?: string | null;
}

/**
 * أمِن ميتا هذه الرسالة؟ — فحصٌ صارمٌ لا يقبل ما يُشبه.
 *
 * ═══ ولمَ لا يكفي `endsWith('facebook.com')` ═══
 *
 * هي الصيغةُ التي تكتبها وثيقةُ ميتا نفسُها، وهي ناقصة: النطاق
 * `evil-facebook.com` ينتهي بها، و`facebook.com.attacker.net` لا ينتهي بها
 * لكنّ `http://facebook.com` ينتهي بها وهو غيرُ مشفَّر — ورسالةٌ من صفحةٍ
 * على HTTP تستطيع أن تدّعي أنّها ميتا.
 *
 * فيُقرأ الأصلُ عنوانًا كاملًا (`new URL`) ويُشترط ثلاثة معًا:
 *   ١ · البروتوكول `https:` وحده.
 *   ٢ · المضيف إمّا `facebook.com` بعينه أو نطاقٌ تحته (`.facebook.com`)
 *       — ولا يُقبل `xfacebook.com` لأنّ النقطة شرط.
 *   ٣ · لا منفذَ غيرَ الافتراضيّ.
 *
 * وما لم يُطابق الثلاثةَ لا يُقرأ منه حرف.
 */
export function isMetaOrigin(origin: string): boolean {
    let url: URL;

    try {
        url = new URL(origin);
    } catch {
        return false;
    }

    if (url.protocol !== 'https:' || url.port !== '') return false;

    return url.hostname === 'facebook.com' || url.hostname.endsWith('.facebook.com');
}

/** ما تقوله نافذةُ ميتا: انتهت بنجاح، أو أُلغيت، أو أخطأت */
export type SignupOutcome =
    | { type: 'FINISH'; assets: SignupAssets }
    | { type: 'CANCEL'; step: string | null }
    | { type: 'ERROR'; message: string | null };

/**
 * قراءةُ حمولة `WA_EMBEDDED_SIGNUP` — و`null` لكلّ ما سواها.
 *
 * ═══ وثلاثةُ أسماءٍ للنجاح لا اسمٌ واحد ═══
 *
 * المسارُ العاديّ يُعيد `FINISH`، ومسارُ **تطبيق** واتساب للأعمال يُعيد
 * `FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING` — وهو المسارُ المقصود هنا.
 * ولمّا كانت ميتا تُبدّل هذه الأسماء بين النسخ، يُقبل كلُّ ما يبدأ
 * بـ`FINISH` ويُعامَل معاملةً واحدة: إرسالُ الكود إلى الخادم ليقرّر.
 *
 * والحمولةُ نصٌّ أحيانًا وكائنٌ أحيانًا — فتُقرأ الحالتان.
 */
export function readSignupEvent(raw: unknown): SignupOutcome | null {
    let payload: Record<string, unknown>;

    if (typeof raw === 'string') {
        try {
            payload = JSON.parse(raw) as Record<string, unknown>;
        } catch {
            return null;
        }
    } else if (raw && typeof raw === 'object') {
        payload = raw as Record<string, unknown>;
    } else {
        return null;
    }

    if (payload.type !== 'WA_EMBEDDED_SIGNUP') return null;

    const event = String(payload.event ?? '');
    const data = (payload.data ?? {}) as Record<string, unknown>;

    if (event.startsWith('FINISH')) {
        return {
            type: 'FINISH',
            assets: {
                waba_id: data.waba_id ? String(data.waba_id) : null,
                phone_number_id: data.phone_number_id ? String(data.phone_number_id) : null,
                business_id: data.business_id ? String(data.business_id) : null,
            },
        };
    }

    if (event === 'CANCEL') {
        return { type: 'CANCEL', step: data.current_step ? String(data.current_step) : null };
    }

    if (event === 'ERROR') {
        return { type: 'ERROR', message: data.error_message ? String(data.error_message) : null };
    }

    return null;
}

interface FacebookSdk {
    init(options: Record<string, unknown>): void;
    login(callback: (response: { authResponse?: { code?: string } | null }) => void, options: Record<string, unknown>): void;
}

declare global {
    interface Window {
        FB?: FacebookSdk;
        fbAsyncInit?: () => void;
    }
}

/**
 * تحميلُ عدّة ميتا مرّةً واحدة — ولا مرّتين مهما ضُغط الزرّ.
 *
 * الوعدُ يُحفظ في متغيّرٍ خارج المكوّن: مكوّنٌ يُركَّب ثانيةً (تنقّلُ Inertia
 * بين الشاشات) لا يُحمّل النصَّ من جديد، ولا يُهيَّأ `FB.init` مرّتين —
 * والتهيئةُ الثانية تُسقط مستمعي الأولى بلا خطأٍ يُقرأ.
 */
let sdk: Promise<FacebookSdk> | null = null;

function loadSdk(appId: string, version: string): Promise<FacebookSdk> {
    if (sdk) return sdk;

    sdk = new Promise<FacebookSdk>((resolve, reject) => {
        if (typeof document === 'undefined') {
            reject(new Error('no document'));

            return;
        }

        window.fbAsyncInit = () => {
            window.FB?.init({ appId, autoLogAppEvents: true, xfbml: false, version });
            if (window.FB) resolve(window.FB);
            else reject(new Error('sdk missing'));
        };

        const script = document.createElement('script');
        script.src = 'https://connect.facebook.net/en_US/sdk.js';
        script.async = true;
        script.defer = true;
        script.crossOrigin = 'anonymous';
        script.onerror = () => {
            /* وفشلُ التحميل يُنسى، فالضغطةُ التالية تُعيد المحاولة */
            sdk = null;
            reject(new Error('sdk failed'));
        };
        document.body.appendChild(script);
    });

    return sdk;
}

/* للاختبار وحدَه: شجرةُ الاختبارات تُهدَم بين حالةٍ وأخرى، والوعدُ خارجها */
export function resetSdkForTests(): void {
    sdk = null;
}

interface Props {
    config: EmbeddedConfig;
    /** يُنادى بالكود وبما التقطته الشاشة — والخادمُ وحده يقرّر النجاح */
    onCode: (code: string, assets: SignupAssets) => void;
    onCancel?: (step: string | null) => void;
    onError?: (message: string | null) => void;
    /** يُرسم بالحال: مُعطَّلًا أثناء الفتح وأثناء انتظار الخادم */
    children: (state: { open: () => void; busy: boolean }) => React.ReactNode;
}

/**
 * زرُّ «ربط WhatsApp Business» — يفتح نافذة ميتا ويُصغي إليها.
 *
 * ═══ وما لا يفعله ═══
 *
 * لا يُعلن نجاحًا. حدثُ `FINISH` يعني أنّ التاجر أتمّ خطوات ميتا — لا أنّ
 * لدينا رمزًا يعمل على رقمٍ نملك إدارته. فكلُّ ما يفعله هذا المكوّن أن
 * يُسلّم الكودَ إلى `onCode`؛ والنجاحُ ما يردّ به الخادم بعد أن يُبدّل الكود
 * ويفحص الصلاحيات ويُطابق الرقمَ بحساب الأعمال.
 *
 * ولا يمسّ `localStorage` ولا `sessionStorage`: الكودُ عمرُه ثلاثون ثانية
 * وليس ممّا يُخزَّن، والرمزُ لا يمرّ من هنا أصلًا.
 */
export default function EmbeddedSignup({ config, onCode, onCancel, onError, children }: Props) {
    const [busy, setBusy] = useState(false);

    /*
     * آخرُ ما التقطه المستمع — ويُقرأ لحظةَ يردّ `FB.login`.
     *
     * الحدثان مستقلّان: `postMessage` يحمل المعرّفات، و`FB.login` يحمل
     * الكود، ولا يضمن أحدٌ ترتيبَهما. ومرجعٌ لا حال: تغييرُ الحال يُعيد
     * التركيب، والردُّ القادم يقرأ نسخةً قديمة.
     */
    const assets = useRef<SignupAssets>({});

    useEffect(() => {
        const listen = (event: MessageEvent) => {
            if (!isMetaOrigin(event.origin)) return;

            const outcome = readSignupEvent(event.data);

            if (!outcome) return;

            if (outcome.type === 'FINISH') {
                assets.current = outcome.assets;

                return;
            }

            /*
             * والإلغاءُ والخطأُ يُنهيان الانتظار.
             *
             * `FB.login` قد لا تردّ أصلًا حين تُغلق النافذةُ بالصليب — فلو
             * تُرك الزرُّ مُعطَّلًا على حدث الإلغاء وحدَه لَبقي معطَّلًا حتّى
             * تُعاد الصفحة، ولا شيء يقول للتاجر لماذا.
             */
            setBusy(false);
            assets.current = {};

            if (outcome.type === 'CANCEL') onCancel?.(outcome.step);
            else onError?.(outcome.message);
        };

        window.addEventListener('message', listen);

        return () => window.removeEventListener('message', listen);
    }, [onCancel, onError]);

    const open = useCallback(() => {
        if (busy || !config.configured) return;

        setBusy(true);
        assets.current = {};

        loadSdk(config.app_id, config.graph_version)
            .then((fb) => {
                fb.login(
                    (response) => {
                        const code = response?.authResponse?.code;

                        if (!code) {
                            /* لا كود: أُلغيت النافذة أو رُفض الإذن — والحدثُ أعلاه قال السبب إن وصل */
                            setBusy(false);

                            return;
                        }

                        /*
                         * والكودُ يُسلَّم فورًا — عمرُه ثلاثون ثانيةً عند ميتا.
                         *
                         * ولا يُخزَّن في `localStorage` ولا يُؤجَّل إلى ضغطةٍ
                         * ثانية: كودٌ محفوظٌ هو كودٌ مسروقٌ حين يُسرق الجهاز،
                         * وكودٌ مؤجَّلٌ هو كودٌ ميّت.
                         */
                        onCode(code, assets.current);
                        setBusy(false);
                    },
                    {
                        config_id: config.config_id,
                        response_type: 'code',
                        override_default_response_type: true,
                        /*
                         * ═══ `featureType` بمحاذاة `setup` لا داخلَه ═══
                         *
                         * وثيقةُ ميتا تضعه في `extras` مباشرةً مع
                         * `sessionInfoVersion`. ووضعُه داخل `setup` يجعله
                         * حقلًا لا تقرؤه النافذةُ — فيُفتح المسارُ العاديّ
                         * بلا أن يُعلن أحدٌ خطأً، ويُنزع رقمُ التاجر من
                         * تطبيق واتساب للأعمال وهو يظنّه باقيًا فيه.
                         */
                        extras: {
                            setup: {},
                            featureType: config.feature_type,
                            sessionInfoVersion: config.session_info_version,
                        },
                    },
                );
            })
            .catch(() => {
                setBusy(false);
                onError?.(null);
            });
    }, [busy, config, onCode, onError]);

    return <>{children({ open, busy })}</>;
}
