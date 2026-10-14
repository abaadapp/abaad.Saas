import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';

/**
 * توقُّعُ النشاط في رابط التصدير — اسمُ المعامل كما يقرؤه الخادم.
 *
 * الملفُّ يُبنى لنشاط الجلسة لا لهذا الرقم: الخادمُ لا يختار به شيئًا، وإنّما
 * يقارنه بنشاط الجلسة الآن. فإن تبدّلت الجلسةُ في تبويبٍ آخر منذ رُسمت هذه
 * الصفحة، وقف التصدير ولم يُخرج ملفَّ النشاط الآخر تحت شاشةٍ تقول غيرَه.
 * انظر `App\Support\TenantExportGuard` — والاسمان واحدٌ يحرسه اختبار.
 */
export const EXPECTED_BUSINESS_PARAM = 'expected_business_id';

/**
 * الرابطُ نفسُه يحمل نشاطَ هذه الصفحة.
 *
 * ويُستبدل ما كان فيه، ولا يُضاف بلا نشاط: لوحةُ المنصّة لا نشاطَ لها، وتصديرُها
 * ليس لنشاط.
 */
export function expectBusiness(url: string, businessId: number | null | undefined): string {
    if (!businessId) return url;

    const [base, own = ''] = url.split('?');
    const params = new URLSearchParams(own);
    params.set(EXPECTED_BUSINESS_PARAM, String(businessId));

    return `${base}?${params.toString()}`;
}

/** نشاطُ الصفحة من خصائصها — `auth.user.businessId` الذي أرسله الخادمُ معها */
export function businessIdOf(props: unknown): number | null {
    const id = (props as { auth?: { user?: { businessId?: number | null } | null } | null } | null)?.auth?.user?.businessId;

    return typeof id === 'number' && id > 0 ? id : null;
}

/**
 * رابطُ ملفٍّ لهذه الصفحة — يحمل نشاطَها.
 *
 * مصدرٌ واحد: `auth.user.businessId` في خصائص الصفحة المرسومة الآن — لا
 * تخزينٌ في المتصفّح ولا رقمٌ يُمرَّر بيد. فالتبويبُ القديم يحمل نشاطَه هو.
 */
export function useTenantLink(): (url: string) => string {
    const businessId = businessIdOf(usePage().props);

    return useCallback((url: string) => expectBusiness(url, businessId), [businessId]);
}

/**
 * يُلحق برابط التصدير ما تنظر إليه الشاشة الآن.
 *
 * زرّ «تصدير» يقف بجانب المُرشِّحات، فمن ضغطه ينتظر ما أمامه. وكان الرابط
 * يخرج عاريًا: يُرشِّح التاجر مصروفات سبتمبر ويصدّر، فيفتح ملفًّا فيه ثلاث
 * سنوات — ولا يعرف أنّه غير ما طلب إلا إن عدّ الصفوف. ويُرشِّح الفواتير
 * الملغاة فلا يجد ملغاةً واحدة.
 *
 * والصفحة ورقمُها لا يُنقلان: الملفّ ليس مرقَّمًا، وحملُ `page=3` إليه يُوهم
 * أنّ له صفحات.
 *
 * وما في الرابط أولى بما فيه: مسارٌ كُتب له `range` صراحةً يعرف ما يريد.
 */
export function withFilters(url: string): string {
    if (typeof window === 'undefined') return url;

    const here = new URLSearchParams(window.location.search);
    here.delete('page');
    here.delete('per_page');
    // وتوقُّعُ النشاط لا يُورَث من عنوان الصفحة — يحمله الرابطُ من خصائصها وحدها
    here.delete(EXPECTED_BUSINESS_PARAM);
    if (![...here].length) return url;

    const [base, own = ''] = url.split('?');
    const merged = new URLSearchParams(own);
    here.forEach((value, key) => {
        if (!merged.has(key)) merged.append(key, value);
    });

    return `${base}?${merged.toString()}`;
}

/**
 * يُنزّل ملفًّا دون أن يغادر الصفحة — ويقول متى بدأ أو فشل.
 *
 * رابطُ `<a href>` كان يُنزّل ولا يقول شيئًا: الزرُّ يبقى كما هو، فيُضغط
 * ثانيةً وثالثةً والملفُّ الكبير ما زال يُبنى — فتصل ثلاثُ نسخ. والخطأ
 * (صلاحيةٌ أو مهلة) كان يستبدل الصفحةَ كلَّها بصفحة الخطأ.
 *
 * فيُطلب الملفُّ في إطارٍ خفيّ برمزٍ (`download_token`) يعيده الخادم كعكةً
 * حين يبدأ التنزيل (`Workbook::signal`). والإطارُ لا يُحمَّل صفحةً إلا إن
 * ردّ الخادمُ بصفحة — أي بخطأ.
 */
export function downloadFile(url: string, timeoutMs = 120_000): Promise<'started' | 'failed' | 'timeout' | 'stale'> {
    const token = Math.random().toString(36).slice(2, 12) + Date.now().toString(36);
    const [base, own = ''] = url.split('?');
    const params = new URLSearchParams(own);
    params.set('download_token', token);
    const src = `${base}?${params.toString()}`;

    return new Promise((resolve) => {
        const frame = document.createElement('iframe');
        frame.style.display = 'none';
        let settled = false;

        const finish = (result: 'started' | 'failed' | 'timeout' | 'stale') => {
            if (settled) return;
            settled = true;
            clearInterval(poll);
            clearTimeout(timer);
            document.cookie = 'download_token=; Max-Age=0; path=/';
            // الإطارُ يبقى قليلًا: إزالتُه فورًا قد تقطع تنزيلًا بدأ للتوّ
            setTimeout(() => frame.remove(), 60_000);
            resolve(result);
        };

        const poll = setInterval(() => {
            if (document.cookie.split('; ').includes(`download_token=${token}`)) finish('started');
        }, 300);
        const timer = setTimeout(() => finish('timeout'), timeoutMs);

        // ملفٌّ لا يُحمَّل في الإطار — فإن حُمِّل شيءٌ فهو صفحةُ خطأ
        frame.addEventListener('load', () => {
            if (document.cookie.split('; ').includes(`download_token=${token}`)) {
                finish('started');

                return;
            }
            /*
             * وصفحةُ «تغيّرت جلسة النشاط» تُقرأ باسمها — لا «تعذّر التصدير».
             *
             * الإطارُ من الأصل نفسِه فيُقرأ ما فيه. ومن ضغط «تصدير» في تبويبٍ
             * قديم يحتاج أن يعرف أنّ التحديث هو الحلّ، لا أن يعيد المحاولة.
             */
            let stale = false;
            try {
                stale = !!frame.contentDocument?.querySelector('[data-stale-export]');
            } catch {
                stale = false;
            }
            finish(stale ? 'stale' : 'failed');
        });

        frame.src = src;
        document.body.appendChild(frame);
    });
}
