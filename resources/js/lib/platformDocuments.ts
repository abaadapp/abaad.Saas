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
