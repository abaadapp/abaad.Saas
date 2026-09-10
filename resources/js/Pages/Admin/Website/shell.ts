/**
 * ما تشترك فيه شاشات الموقع — الحالُ ولونُها واسمُها.
 *
 * ويُقرأ من ملفٍّ واحد لأنّ الحال تُعرض في ستّ شاشات: لو كُتب اللون في كلٍّ
 * منها لصار «مسوّدة» رماديًّا في شاشةٍ وأصفرَ في أختها عن الشيء نفسه.
 */

export interface SiteShell {
    site: {
        id: number;
        name: string;
        goal: string;
        goal_label: string;
        template: string;
        /** draft · published · changed · maintenance */
        state: string;
        sells: boolean;
        maintenance: boolean;
        published_at: string | null;
        saved_at: string | null;
        /** هل في المسوّدة ما لم يُنشر؟ */
        changes: boolean;
        url: string | null;
        tokens: Record<string, string | number>;
    };
}

export const STATE_LABEL: Record<string, string> = {
    draft: 'مسوّدة — لم يُنشر بعد',
    published: 'منشور',
    changed: 'منشور · فيه تغييرات لم تُنشر',
    maintenance: 'وضع الصيانة',
};

export const STATE_TONE: Record<string, 'neutral' | 'success' | 'warning' | 'info' | 'danger'> = {
    draft: 'neutral',
    published: 'success',
    changed: 'warning',
    maintenance: 'danger',
};

/**
 * حالُ عناوين الموقع كما يرسلها الخادم — انظر `App\Support\Website\Domains`.
 *
 * وعنوانان لا واحد: عنوانُ أبعاد يعمل من اليوم الأوّل، ونطاقُ التاجر إن
 * ربطه. والأصلُ بينهما ما يُخدَم فعلًا لا ما يُملَك.
 */
export interface DomainRecord {
    type: string;
    name: string;
    value: string;
}

export interface DomainState {
    platform: { host: string; url: string } | null;
    custom: {
        id: number;
        host: string;
        url: string;
        status: string;
        label: string;
        reason: string | null;
        checked_at: string | null;
        records: DomainRecord[];
    } | null;
    primary: string | null;
}

/** العنوانُ الذي يُعرض في سطرٍ واحد — نطاقُ التاجر إن ربطه، وإلّا عنوانُ أبعاد */
export function domainHost(domain: DomainState): string | null {
    return domain.custom?.host ?? domain.platform?.host ?? null;
}
