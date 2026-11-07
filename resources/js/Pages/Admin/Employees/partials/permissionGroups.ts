/**
 * مجموعاتُ العرض في «الصلاحيات» — ترتيبٌ للعين لا قاعدة.
 *
 * ═══ ما لا تفعله ═══
 *
 * لا تمنح ولا تمنع ولا تُرسَل إلى الخادم. المفاتيحُ مفاتيحُ `Permissions::SECTIONS`
 * و`Permissions::ACTIONS` كما هي، والحارسُ يقرؤها بلا علمٍ بهذه المجموعات.
 * فنقلُ مفتاحٍ من مجموعةٍ إلى أخرى لا يغيّر ما يفتحه أحد.
 *
 * ═══ وكلُّ مفتاحٍ مرّةً واحدة ═══
 *
 * يُعرض المفتاحُ في أوّل مجموعةٍ تذكره، ولا يُعرض ما لم يُرسله الخادم — فلا
 * تُخترع صلاحيةٌ من هنا. وما أرسله الخادم ولم تذكره مجموعةٌ يقع في «أخرى»،
 * فلا يغيب مفتاحٌ جديد عن الشاشة يوم يُضاف.
 *
 * والحارسُ `TheStaffPermissionScreenShowsEveryKeyOnceTest` يقرأ هذا الملفّ
 * ويقيسه على `Permissions` نفسِها.
 */

export interface PermissionGroupDef {
    id: string;
    title: string;
    keys: string[];
}

export const PERMISSION_GROUPS: PermissionGroupDef[] = [
    { id: 'overview', title: 'لوحة التحكم والتقارير', keys: ['dashboard', 'reports'] },
    { id: 'customers', title: 'العملاء', keys: ['customers', 'credit.override', 'customer.block_override'] },
    { id: 'stock', title: 'المنتجات والمخزون', keys: ['products', 'inventory'] },
    { id: 'sales', title: 'المبيعات ونقطة البيع', keys: ['orders', 'pos', 'order.edit', 'pos.movement'] },
    {
        id: 'invoices',
        title: 'الفواتير والتحصيل',
        keys: [
            'customerInvoice.issue',
            'customerInvoice.cancel',
            'customerCreditNote.create',
            'customerPayment.create',
            'customerPayment.cancel',
            'paymentLink.create',
        ],
    },
    {
        id: 'purchasing',
        title: 'المشتريات والموردون',
        keys: [
            'suppliers',
            'purchases',
            'receipt.view',
            'receipt.create',
            'receipt.approve',
            'receipt.reject',
            'invoice.view',
            'invoice.create',
            'invoice.approve',
            'invoice.reject',
            'invoice.override',
            'invoice.pay',
        ],
    },
    { id: 'staff', title: 'الرواتب والموظفون', keys: ['employees', 'payroll.view', 'payroll.approve', 'payroll.pay'] },
    {
        id: 'preparation',
        title: 'التجهيز والتسليم',
        keys: ['preparation', 'preparation.cancel', 'customerInvoice.preparation.edit'],
    },
    {
        id: 'marketing',
        title: 'التسويق والموقع الإلكتروني',
        keys: ['marketing', 'website', 'website.configure', 'order.websiteNotify'],
    },
    { id: 'settings', title: 'الإعدادات والتطبيقات التكاملية', keys: ['settings', 'integrations', 'business.export'] },
    {
        id: 'transfers',
        title: 'تحويلات الفروع والتزويد',
        keys: [
            'transfer.view',
            'transfer.viewAll',
            'transfer.create',
            'transfer.approve',
            'transfer.dispatch',
            'transfer.receive',
            'transfer.resolve',
            'supply.create',
        ],
    },
    { id: 'money', title: 'المصاريف والعمليات المالية', keys: ['finance', 'expenses', 'attachment.view'] },
];

/** ما أرسله الخادم ولم تذكره مجموعة */
export const OTHER_GROUP = { id: 'other', title: 'أخرى' };

export interface PermissionItem {
    key: string;
    label: string;
    hint?: string;
    /** فعلٌ يُمنح بالاسم — لا قسمٌ يُفتح. لا يُحدَّد جماعيًّا أبدًا */
    sensitive: boolean;
}

export interface PermissionGroup {
    id: string;
    title: string;
    items: PermissionItem[];
}

/**
 * يوزّع ما أرسله الخادمُ على المجموعات — كلُّ مفتاحٍ مرّةً واحدة.
 *
 * الحسّاسُ ما جاء في `actions`، لا ما يُخمَّن من شكل المفتاح: المصدرُ
 * `Permissions::ACTIONS` نفسُها.
 */
export function groupPermissions(
    sections: Record<string, string>,
    actions: Record<string, string> = {},
    hints: Record<string, string> = {},
): PermissionGroup[] {
    const item = (key: string): PermissionItem | null => {
        if (key in actions) return { key, label: actions[key], hint: hints[key], sensitive: true };
        if (key in sections) return { key, label: sections[key], sensitive: false };

        return null;
    };

    const placed = new Set<string>();
    const take = (keys: string[]) =>
        keys.flatMap((key) => {
            if (placed.has(key)) return [];
            const it = item(key);
            if (!it) return [];
            placed.add(key);

            return [it];
        });

    const groups = PERMISSION_GROUPS.map((g) => ({ id: g.id, title: g.title, items: take(g.keys) }));
    const rest = take([...Object.keys(sections), ...Object.keys(actions)]);

    return [...groups, { ...OTHER_GROUP, items: rest }].filter((g) => g.items.length > 0);
}

/**
 * نصٌّ للبحث بلا تشكيلٍ ولا همزات — «تنبيهُ» تُطابق «تنبيه»، و«إلغاء» تُطابق «الغاء».
 */
export function searchable(text: string): string {
    return text
        .toLowerCase()
        .replace(/[ً-ْٰـ]/g, '')
        .replace(/[أإآٱ]/g, 'ا')
        .replace(/ى/g, 'ي')
        .replace(/ة/g, 'ه')
        .replace(/\s+/g, ' ')
        .trim();
}
