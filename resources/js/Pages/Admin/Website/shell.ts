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
