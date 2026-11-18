/**
 * جدولُ المسارات لكلّ واجهةٍ ما تنادي به — ويُضمّ إليه ما تحتاجه صفحةٌ من غيرها.
 *
 * الصفحةُ الأولى تحمل مجموعتَها وحدها (`config/ziggy.php`، `RouteGroups`):
 * الصندوقُ مساراتِه، والدخولُ مساراتِه. والانتقالُ بلا تحميلٍ إلى واجهةٍ أخرى —
 * من الصندوق إلى اللوحة، أو من الدخول إلى اللوحة — لا يعيد كتابة الجدول، فتُجلب
 * مجموعةُ الصفحة الجديدة وتُضمّ قبل أن تُرسم (`ensureRoutesFor` في `resolve`)،
 * كما يضمّ Ziggy نفسُه مجموعةً ثانية (`Object.assign(Ziggy.routes, …)`).
 *
 * والمرآةُ في الخادم `RouteGroups::FOLDERS` و`COVERS` — والحارسُ يقابل بينهما.
 */

export type RouteGroup = 'guest' | 'pos' | 'merchant' | 'all';

export const FOLDERS: Record<string, RouteGroup> = {
    Auth: 'guest',
    Pos: 'pos',
    Admin: 'merchant',
    Profile: 'merchant',
    Platform: 'all',
};

export const COVERS: Record<RouteGroup, RouteGroup[]> = {
    all: ['all', 'merchant', 'pos', 'guest'],
    merchant: ['merchant', 'pos'],
    pos: ['pos'],
    guest: ['guest'],
};

/** مجموعةُ صفحةٍ بمكوّنها — `Pos/Index` ← `pos`، وما لا يُعرف مجلّدُه يأخذ الكلّ */
export function groupOf(component: string): RouteGroup {
    return FOLDERS[component.split('/')[0]] ?? 'all';
}

type ZiggyConfig = { routes: Record<string, unknown> };

declare const Ziggy: ZiggyConfig | undefined;

function ziggy(): ZiggyConfig | undefined {
    if (typeof Ziggy !== 'undefined') return Ziggy;
    return (globalThis as { Ziggy?: ZiggyConfig }).Ziggy;
}

/** ما حُمّل — مجموعةُ الصفحة الأولى كما قالها الخادم، وبلا علامةٍ: الكلّ (كما كان) */
const loaded = new Set<RouteGroup>();

export function initialGroup(doc: Document = document): RouteGroup {
    const said = doc.querySelector('meta[name="route-group"]')?.getAttribute('content');
    return said && said in COVERS ? (said as RouteGroup) : 'all';
}

export function startRouteGroups(group: RouteGroup = initialGroup()): void {
    loaded.clear();
    loaded.add(group);
}

export function covered(need: RouteGroup): boolean {
    for (const g of loaded) if (COVERS[g].includes(need)) return true;
    return false;
}

/**
 * يضمّ مجموعةَ الصفحة قبل أن تُرسم — ولا يفعل شيئًا إن كانت محمّلة.
 *
 * ويرمي حين لا جواب: يتولّى `resolve` الانتقالَ بتحميلٍ كامل بدل صفحةٍ تنادي
 * `route()` باسمٍ غائب.
 */
export async function ensureRoutesFor(component: string, fetcher: typeof fetch = fetch): Promise<void> {
    const need = groupOf(component);
    if (covered(need)) return;

    const res = await fetcher(`/route-groups/${need}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
    if (!res.ok) throw new Error(`route group ${need}: ${res.status}`);

    const data = (await res.json()) as { group: string; routes: Record<string, unknown> };
    const config = ziggy();
    if (!config || data.group !== need || typeof data.routes !== 'object') throw new Error(`route group ${need}: bad answer`);

    Object.assign(config.routes, data.routes);
    loaded.add(need);
}
