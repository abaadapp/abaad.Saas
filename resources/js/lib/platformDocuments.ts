import { useEffect } from 'react';
import { router } from '@inertiajs/react';

/** صفُّ مستندٍ من مستندات المنصّة — في القائمة وفي رأس صفحته (`DocumentController::row`) */
export interface DocumentRow {
    id: number;
    url: string;
    pdf_url: string;
    number: string;
    title: string;
    service: string | null;
    client_name: string | null;
    client_biz: string | null;
    business: { id: number; name: string | null } | null;
    plan_name: string | null;
    cycle_label: string | null;
    /** سعرُ دورة الباقة أو مبلغُ الخدمة — نصًّا بثلاث خانات */
    value: string | null;
    value_unit: string | null;
    renewal: boolean;
    /** آخرُ ما فعله المدير بالرابط — «نُسخ» أو «فُتح واتساب»، لا دليلَ وصول */
    shared: string | null;
    shared_at: string | null;
    state: 'sent' | 'signed' | 'cancelled' | 'expired';
    state_label: string;
    created_at: string;
    expires_at: string;
    signed_at: string;
}

/** لونُ كلّ حالة — المنتهي أحمر: رابطٌ أُرسل ولم يُوقَّع ولم يعد يعمل */
export const STATE_VARIANT: Record<DocumentRow['state'], 'warning' | 'success' | 'neutral' | 'danger'> = {
    sent: 'warning',
    signed: 'success',
    cancelled: 'neutral',
    expired: 'danger',
};

/** كلَّ كم يسأل الخادمَ ما ينتظر التوقيع — خفيفٌ، ويهدأ حين يُخفى التبويب */
export const POLL_MS = 10_000;

/**
 * يسأل الخادمَ عن خاصّيّاتٍ بعينها كلَّ حين ما دام `active` — فيظهر توقيعٌ وقع
 * والشاشةُ مفتوحة، دون إعادة تحميلٍ ولا دخولٍ جديد، أيًّا كانت الطريقُ التي وصل
 * بها الرابطُ إلى العميل. وما يُطلب هو ما يتغيّر وحده (`only`)، والتبويبُ المخفيّ
 * لا يسأل — كما يفعل الجرس (`Topbar`).
 */
export function useLiveReload(only: string[], active = true, every = POLL_MS): void {
    const keys = only.join(',');
    useEffect(() => {
        if (!active) return;
        const id = window.setInterval(() => {
            if (document.hidden) return;
            router.reload({ only: keys.split(',') });
        }, every);
        return () => window.clearInterval(id);
    }, [keys, active, every]);
}

/**
 * نهايةُ المدّة من بدايتها — بلا فيضٍ إلى الشهر التالي، كما يحسبها الخادم
 * (`Billing::extend`): شهرٌ من ٣١ يناير ينتهي آخرَ فبراير.
 */
export function addMonths(iso: string, months: number): string {
    const [y, m, d] = iso.split('-').map(Number);
    if (!y || !m || !d) return '';
    const target = new Date(Date.UTC(y, m - 1 + months, 1));
    const last = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate();
    target.setUTCDate(Math.min(d, last));
    return target.toISOString().slice(0, 10);
}

/** إدخالٌ في نموذج مستند — يعبّئه العميل (client) أو فريقُ أبعاد (team). انظر `DocumentForms` */
export type FormInput =
    | { kind: 'field'; by: 'client' | 'team'; k: string; label: string; req: boolean; type: string; dir: string; ph: string }
    | { kind: 'checks'; by: 'client' | 'team'; k: string; head: string; items: string[] }
    | { kind: 'table'; by: 'client' | 'team'; k: string; head: string[]; types: string[]; ph: string[]; rows: string[]; picks: string[] }
    | { kind: 'yesno'; by: 'client' | 'team'; k: string; label: string };

export type FormPart =
    | { kind: 'p'; text: string }
    | { kind: 'list'; items: string[] }
    | { kind: 'note'; text: string }
    | { kind: 'cols'; cols: { head: string; items: string[] }[] }
    | { kind: 'amount'; label: string }
    | FormInput;

export interface FormSection {
    title: string;
    /** يُعبّأ بعد تنفيذ الخدمة — يُطبع فارغًا ولا يقبل إدخالًا هنا */
    after: boolean;
    parts: FormPart[];
}

/** نموذجُ اتفاقيةٍ كما صمّمها المالك — أقسامٌ وحقولٌ وقوائمُ وجداول */
export interface DocumentForm {
    intro: string | null;
    highlights: { label: string; text: string }[];
    sections: FormSection[];
    consent: string;
    agree: string;
    footer: string | null;
}

/** ما كُتب في إدخالات النموذج: نصٌّ للحقل، وأرقامُ البنود للقائمة، وخلايا للجدول */
export type FormValues = Record<string, string | number[] | string[][]>;

export function isInput(part: FormPart): part is FormInput {
    return part.kind === 'field' || part.kind === 'checks' || part.kind === 'table' || part.kind === 'yesno';
}

/** إدخالاتُ صاحبها خارج أقسام «بعد التنفيذ» — كما يقبلها الخادم */
export function formInputs(form: DocumentForm | null | undefined, by: 'client' | 'team'): { section: string; part: FormInput }[] {
    if (!form) return [];
    return form.sections.flatMap((s) =>
        s.after ? [] : s.parts.filter(isInput).filter((p) => p.by === by).map((part) => ({ section: s.title, part })),
    );
}

/** رموزُ القالب التي تُملأ ساعةَ الإصدار — انظر `DocumentForms::render` */
export const TOKENS = ['{fee}', '{fee_short}', '{provider}'] as const;

/** أيُّ الرموز في النموذج؟ — لتنبيه المحرّر إن أُزيل منها شيء */
export function formTokens(form: DocumentForm | null | undefined): string[] {
    if (!form) return [];
    const text = JSON.stringify(form);
    return TOKENS.filter((tok) => text.includes(tok));
}

/**
 * النموذجُ برموزه مملوءةً — كما يكتبه الخادم في المستند (`DocumentForms::render`):
 * الرسومُ بثلاث خانات ومختصرة، ومقدّمُ الخدمة بما كُتب منه، وسطرُ «الأبرز» الذي
 * لم يبق فيه شيءٌ يسقط. للمعاينة وحدها — المستندُ يُكتب في الخادم.
 */
export function renderTokens(form: DocumentForm, values: { fee: string | null; provider: string }): DocumentForm {
    const fee = values.fee === null || values.fee === '' ? null : Number(values.fee);
    const full = fee === null || Number.isNaN(fee) ? '—' : fee.toFixed(3);
    const short = fee === null || Number.isNaN(fee) ? '—' : String(Number(fee.toFixed(3)));
    const fill = (v: unknown): unknown => {
        if (typeof v === 'string') return v.split('{fee_short}').join(short).split('{fee}').join(full).split('{provider}').join(values.provider.trim());
        if (Array.isArray(v)) return v.map(fill);
        if (v && typeof v === 'object') return Object.fromEntries(Object.entries(v).map(([k, x]) => [k, fill(x)]));
        return v;
    };
    const out = fill(form) as DocumentForm;
    return { ...out, highlights: out.highlights.filter((h) => h.text.trim() !== '') };
}

/** أسماءُ الأجزاء كما يقرؤها المدير — `DocumentForms::LABELS` */
export const PART_LABELS: Record<FormPart['kind'], string> = {
    p: 'فقرة',
    list: 'قائمة',
    note: 'ملاحظة',
    cols: 'أعمدة',
    amount: 'مبلغ المستند',
    field: 'حقل',
    checks: 'خيارات للتأشير',
    table: 'جدول',
    yesno: 'سؤال نعم/لا',
};

/** مفتاحُ إدخالٍ جديد لا يتكرّر في النموذج — بادئةٌ ورقم */
export function nextKey(form: DocumentForm, prefix: string): string {
    const used = new Set(form.sections.flatMap((s) => s.parts.filter(isInput).map((p) => p.k)));
    let n = 1;
    while (used.has(`${prefix}${n}`)) n++;
    return `${prefix}${n}`;
}

/** جزءٌ جديدٌ فارغ من نوعه — يكتبه المدير */
export function newPart(kind: FormPart['kind'], form: DocumentForm): FormPart {
    switch (kind) {
        case 'p':
        case 'note':
            return { kind, text: '' };
        case 'list':
            return { kind, items: [''] };
        case 'cols':
            return { kind, cols: [{ head: '', items: [''] }] };
        case 'amount':
            return { kind, label: '' };
        case 'field':
            return { kind, by: 'client', k: nextKey(form, 'field'), label: '', req: false, type: 'text', dir: 'rtl', ph: '' };
        case 'checks':
            return { kind, by: 'client', k: nextKey(form, 'checks'), head: '', items: [''] };
        case 'yesno':
            return { kind, by: 'client', k: nextKey(form, 'yesno'), label: '' };
        case 'table':
            return { kind, by: 'client', k: nextKey(form, 'table'), head: ['', ''], types: ['text'], ph: [''], rows: [''], picks: [] };
    }
}

/** نموذجٌ جديد — قسمٌ بفقرة، والإقرارُ يكتبه المدير */
export function emptyForm(): DocumentForm {
    return { intro: null, highlights: [], sections: [{ title: '', after: false, parts: [{ kind: 'p', text: '' }] }], consent: '', agree: '', footer: null };
}
