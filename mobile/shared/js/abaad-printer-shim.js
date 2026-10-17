/*
 * window.AbaadPrinter — الواجهةُ الواحدة التي يراها نظامُ أبعاد على Android وiOS.
 *
 * يحقنها التطبيقُ الأصليّ عند بدء المستند، بعد سطرٍ يضع `window.__abaadPrinterConfig`:
 *
 *   { platform: 'android' | 'ios',
 *     trustedOrigins: ['https://…'],          // من إعداد البناء، لا من الصفحة
 *     post:   function (json) { … },          // يُرسل طلبًا نصًّا إلى الطرف الأصليّ
 *     listen: function (receive) { … } }      // يُسلّم ردودَ الطرف الأصليّ إلى `receive`
 *
 * ═══ وما لا تفعله ═══
 *
 * لا تحمل بايتاتِ طابعة ولا تفتح مقبسًا: تُرسل طلبًا مُهيكلًا — رقمَ فاتورة
 * ورابطَ إيصالها وإعدادَ الطابعة — والطرفُ الأصليّ يتحقّق منه كلِّه من جديد.
 * فالتحقّقُ هنا راحةٌ لا حراسة: الحارسُ في Kotlin وSwift.
 *
 * والعقدُ كاملًا في `mobile/PROTOCOL.md`.
 */
(function () {
    'use strict';

    var cfg = window.__abaadPrinterConfig;
    // وأُخذ الإعدادُ — فلا تستطيع الصفحةُ تبديلَ `post` بعدها
    try {
        delete window.__abaadPrinterConfig;
    } catch (e) {
        window.__abaadPrinterConfig = undefined;
    }

    if (!cfg || typeof cfg.post !== 'function' || typeof cfg.listen !== 'function' || window.AbaadPrinter) {
        return;
    }

    var VERSION = 1;
    /*
     * أطولُ صمتٍ من الطرف الأصليّ قبل BRIDGE_TIMEOUT — لا أطولُ مدّةٍ للطلب.
     *
     * إيصالٌ بألف صنفٍ على طابعةٍ بطيئة يُطبع دقائق. والطرفُ الأصليّ يقول كلَّ
     * مرحلة، ويعيد الجاريةَ نبضةً كلَّ ثانيةٍ ما دام يتقدّم (PROTOCOL §3)، فكلُّ
     * رسالةٍ تبدأ المهلةَ من جديد: لا يكفّ الانتظارُ عن إيصالٍ ما زال يخرج.
     */
    var TIMEOUTS = { printReceipt: 120000, testPrinter: 60000, openReceipt: 120000 };
    // ما قد يكون بلغ الطابعةَ قبل انقطاع الجواب — فلا يُعاد تلقائيًّا
    var PRINTS = { printReceipt: true, testPrinter: true };

    var origins = [];
    for (var i = 0; i < (cfg.trustedOrigins || []).length; i++) {
        origins.push(String(cfg.trustedOrigins[i]));
    }
    var post = cfg.post;
    var pending = {};
    var seq = 0;

    function trusted() {
        try {
            return window.top === window.self && origins.indexOf(window.location.origin) !== -1;
        } catch (e) {
            return false;
        }
    }

    function failure(code, extra) {
        var out = { ok: false, code: code, message: '' };
        for (var k in extra || {}) {
            if (Object.prototype.hasOwnProperty.call(extra, k)) out[k] = extra[k];
        }
        return out;
    }

    function normalize(result) {
        if (!result || typeof result !== 'object') return failure('INTERNAL');
        if (result.ok === true) return result;

        return failure(typeof result.code === 'string' && result.code ? result.code : 'INTERNAL', {
            message: typeof result.message === 'string' ? result.message : '',
            connected: result.connected === true,
            mayHavePrinted: result.mayHavePrinted === true,
            effectivePort: typeof result.effectivePort === 'number' ? result.effectivePort : undefined,
            jobId: typeof result.jobId === 'string' ? result.jobId : undefined,
        });
    }

    function receive(raw) {
        var msg;
        try {
            msg = typeof raw === 'string' ? JSON.parse(raw) : raw;
        } catch (e) {
            return;
        }
        if (!msg || typeof msg.id !== 'string' || !Object.prototype.hasOwnProperty.call(pending, msg.id)) return;

        var job = pending[msg.id];
        if (msg.type === 'progress') {
            clearTimeout(job.timer);
            job.timer = arm(msg.id, job.method, job.resolve);
            if (job.onProgress) {
                try {
                    job.onProgress(String(msg.stage));
                } catch (e) {
                    /* ما يرسمه المستدعي لا يُسقط الطباعة */
                }
            }

            return;
        }
        if (msg.type === 'result') {
            delete pending[msg.id];
            clearTimeout(job.timer);
            job.resolve(normalize(msg.result));
        }
    }

    function arm(id, method, resolve) {
        return setTimeout(function () {
            delete pending[id];
            resolve(failure('BRIDGE_TIMEOUT', { mayHavePrinted: !!PRINTS[method] }));
        }, TIMEOUTS[method]);
    }

    function call(method, payload, onProgress) {
        return new Promise(function (resolve) {
            if (!trusted()) {
                resolve(failure('UNTRUSTED_ORIGIN'));

                return;
            }

            seq += 1;
            var id = 'j' + Date.now().toString(36) + '-' + seq;

            pending[id] = {
                method: method,
                resolve: resolve,
                timer: arm(id, method, resolve),
                onProgress: typeof onProgress === 'function' ? onProgress : null,
            };

            try {
                post(JSON.stringify({ v: VERSION, id: id, method: method, payload: payload }));
            } catch (e) {
                clearTimeout(pending[id].timer);
                delete pending[id];
                resolve(failure('BRIDGE_UNAVAILABLE'));
            }
        });
    }

    var api = {
        version: VERSION,
        platform: String(cfg.platform),
        isAvailable: function () {
            return trusted();
        },
        printReceipt: function (job, onProgress) {
            return call('printReceipt', job, onProgress);
        },
        testPrinter: function (job, onProgress) {
            return call('testPrinter', job, onProgress);
        },
        openReceipt: function (job) {
            return call('openReceipt', job, null);
        },
    };

    Object.freeze(api);
    Object.defineProperty(window, 'AbaadPrinter', { value: api, writable: false, configurable: false, enumerable: false });
    cfg.listen(receive);
})();
