import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    ExternalLink,
    Eye,
    EyeOff,
    Image as ImageIcon,
    LayoutTemplate,
    Package,
    Pencil,
    Settings,
    Wrench,
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import { STATE_LABEL, STATE_TONE, type SiteShell } from './shell';

interface Row {
    id: number;
    name: string;
    price: number;
    quantity: number;
    image: string | null;
    published: boolean;
    in_stock: boolean;
}

interface Fact {
    key: string;
    label: string;
    ok: boolean;
    optional: boolean;
    detail: string;
}

interface Props extends SiteShell {
    readiness: Fact[];
    channel: string;
    sells: boolean;
    counts: { shown: number; hidden: number; out: number };
    products: Row[];
    may: { products: boolean; configure: boolean };
    /**
     * الواجهةُ الخاصّة — `ribbon` — إن كانت هي الموقع.
     *
     * ولا بانٍ معها ولا قوالب: زرُّ الإعدادات يقود إلى بطاقة المتجر في
     * الإعدادات (النشرُ والدفعُ والتوصيل)، والطلباتُ تصل «الطلبات» بقناة
     * الموقع. انظر `HubController::themed`.
     */
    theme?: string | null;
    orders?: { today: number };
}

/** اسمُ الواجهة كما يُعرض — والمفتاحُ كما في `Business::THEMES` */
const THEME_LABEL: Record<string, string> = { ribbon: 'RIBBON' };

/**
 * لوحةُ تشغيل الموقع — شاشةُ الموظّف اليوميّة.
 *
 * ═══ ما تجيب عنه في ثلاث ثوانٍ ═══
 *
 * أيعمل الموقع؟ وكم صنفًا يراه الزبون؟ وأيُّها نفد فاختفى؟ ثمّ: أصلحْه من
 * هنا بلا أن تغادر.
 *
 * وما لا تجيب عنه: التصميمُ والصفحاتُ والنطاقُ والسيو. تلك تُضبط مرّةً ثمّ
 * تُترك، وموضعُها «الإعدادات ‹ الموقع الإلكتروني». ومن مرّ بها كلَّ صباح
 * ليبلغ منتجًا ينقصه رقمٌ عرف لماذا فُصلتا.
 *
 * ═══ ولا قاعدةَ منتجاتٍ ثانية ═══
 *
 * الصفوفُ صفوفُ `products` نفسُها، والكتابةُ بمسارات المنتجات القائمة:
 * `admin.products.quick` للسعر والكمّية — وهي التي تقفل الصفّ وتوزّع الفارق
 * على الفرع — و`admin.marketing.store.products` للإظهار والإخفاء. فلا منطقَ
 * يُنسخ، ولا عمودَ يُكتب من بابين.
 */
export default function Hub() {
    const { site, readiness, sells, counts, products, may, channel, context, theme, orders } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    // وعملةُ المتجر من المشترَك لا من هذه الشاشة — انظر HandleInertiaRequests
    const currency = context!.currency;

    /** الصفُّ الذي يُحرَّر الآن — سعرُه وكمّيتُه قبل أن يصلا الخادم */
    const [editing, setEditing] = useState<number | null>(null);
    const [draft, setDraft] = useState<{ price: string; quantity: string }>({ price: '', quantity: '' });

    const open = (row: Row) => {
        setEditing(row.id);
        setDraft({ price: String(row.price), quantity: String(row.quantity) });
    };

    const save = (row: Row) => {
        router.patch(
            route('admin.products.quick', row.id),
            { price: Number(draft.price), quantity: Number(draft.quantity) },
            { preserveScroll: true, onSuccess: () => setEditing(null) },
        );
    };

    const publish = (row: Row) =>
        router.post(
            route('admin.marketing.store.products'),
            { ids: [row.id], published: !row.published },
            { preserveScroll: true },
        );

    /* ما ينقص فعلًا — والاختياريُّ لا يُعدّ نقصًا */
    const missing = readiness.filter((f) => !f.ok && !f.optional);

    return (
        <AdminLayout title="الموقع الإلكتروني">
            <PageHeader
                title="الموقع الإلكتروني"
                subtitle={
                    theme
                        ? `${t('واجهة')} ${THEME_LABEL[theme] ?? theme} — ${t('موقعك بتصميمك، وما يراه زبونك اليوم')}`
                        : t('حالة موقعك وما يراه زبونك اليوم')
                }
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {/* والواجهةُ الخاصّة حالان لا أربع: تُخدم أو لا — لا مسوّدةَ لها ولا تغييراتٍ تنتظر */}
                        <Badge variant={STATE_TONE[site.state]}>
                            {t(theme && site.state === 'draft' ? 'غير منشور' : STATE_LABEL[site.state])}
                        </Badge>
                        {site.url && (
                            <Button variant="outline" asChild>
                                <a href={site.url} target="_blank" rel="noopener noreferrer">
                                    <ExternalLink />
                                    {t('افتح الموقع')}
                                </a>
                            </Button>
                        )}
                        {/*
                            ومحرّرُ الصفحة بابُ صاحب الواجهة الخاصّة اليوميّ.

                            وسائرُ المتاجر تبلغ محرّرَها من «إعدادات الموقع»
                            ثمّ «الصفحات» — وهذا لا صفحاتِ له تُختار: صفحتُه
                            واحدة، فيُفتح محرّرُها من هنا مباشرةً.
                        */}
                        {may.configure && theme && (
                            <Button variant="outline" asChild>
                                <Link href={route('admin.website.editor')}>
                                    <LayoutTemplate />
                                    {t('حرّر صفحتك')}
                                </Link>
                            </Button>
                        )}
                        {/* والضبطُ لمن يملكه — انظر Permissions::WEBSITE_CONFIGURE */}
                        {may.configure && (
                            <Button variant="ghost" asChild>
                                {/* والبابُ واحدٌ للطريقين: شاشاتُ الموقع بشريط تبويباتها */}
                                <Link href={route('admin.website.site')}>
                                    <Settings />
                                    {t('إعدادات الموقع')}
                                </Link>
                            </Button>
                        )}
                    </div>
                }
            />

            {/*
                وما يمنع الموقعَ من العمل يُقال أوّلًا لا في ذيل الشاشة.
                صاحبُ متجرٍ نشر موقعه وأطفأ زرَّ الطلب لا يعرف لماذا لا تصله
                رسائل — والسببُ مكتوبٌ عندنا.
            */}
            {missing.length > 0 && (
                <Card className="mb-5 border-[#fde68a] bg-[#fffbeb] p-4">
                    <p className="flex items-center gap-2 text-[13px] font-semibold text-[#b45309]">
                        <AlertTriangle className="size-4 shrink-0" />
                        {t(theme && site.state === 'draft' ? 'موقعك لا يفتح بعد:' : 'موقعك يعمل، لكن:')}
                    </p>
                    <ul className="mt-2 space-y-1 ps-6 text-[13px] leading-7 text-[#92400e]">
                        {missing.map((f) => (
                            <li key={f.key} className="list-disc">
                                {f.detail}
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            {/*
                ═══ وأين تصل طلباتُ الموقع؟ — سؤالُ الموظّف الأوّل ═══

                ولا لوحةَ «طلبات الموقع» هنا، ولا عددٌ يُعرض. وذلك ليس نقصًا
                في الشاشة بل صدقًا عنها: لا سلّةَ في الموقع ولا مسارَ يقبل
                طلبًا (انظر `App\Support\Website\Commerce`). فالطلبُ محادثةُ
                واتساب يكتبها الزبون بيده، ويُدخلها الموظّف في نقطة البيع
                كأيّ طلبٍ يأتي على الهاتف.

                وصندوقٌ مكتوبٌ فيه «طلبات الموقع: ٠» كان سيُقرأ «لم يطلب أحد»
                — وهي كذبة: لعلّهم طلبوا كلُّهم، والرسائلُ في هاتف صاحب المحلّ.
            */}
            {sells && (
                <Card className="mb-5 p-4">
                    <p className="text-[13px] font-semibold text-[#111]">{t('طلبات الموقع')}</p>
                    <p className="mt-1 text-[13px] leading-7 text-[#6b7280]">
                        {channel === 'checkout'
                            ? t('في موقعك سلّة وإتمام طلب — ما يطلبه زبونك يصل «الطلبات» طلبًا حقيقيًّا بقناة الموقع، جديدًا وغير مدفوع.')
                            : channel === 'whatsapp'
                              ? t('تصلك على واتساب — الموقع ليس فيه سلّة ولا دفع، فما يُطلب يُسجَّل هنا كما يُسجَّل طلب الهاتف.')
                              : t('لا يستقبل موقعك طلبات: لا رقم واتساب في بيانات متجرك، فلا يظهر زرّ الطلب أصلًا.')}
                    </p>
                    {channel === 'checkout' && orders && (
                        <p className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px]">
                            <span className="tabular-nums text-[#111]">
                                {t('طلبات الموقع اليوم')}: <strong>{number(orders.today)}</strong>
                            </span>
                            <Link href={route('admin.orders.index')} className="font-medium text-[#1d4ed8] underline">
                                {t('افتح الطلبات')}
                            </Link>
                        </p>
                    )}
                </Card>
            )}

            {sells && (
                <div className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    {[
                        { key: 'shown', label: 'يراها الزبون', value: counts.shown, tone: 'ok' },
                        { key: 'out', label: 'نفدت فاختفت', value: counts.out, tone: 'warn' },
                        { key: 'hidden', label: 'أخفيتها', value: counts.hidden, tone: 'mute' },
                    ].map((box) => (
                        <Card key={box.key} className="p-4">
                            <p className="text-[12px] text-[#6b7280]">{t(box.label)}</p>
                            <p
                                className={cn(
                                    'mt-1 text-[26px] font-bold tabular-nums',
                                    box.tone === 'warn' && box.value > 0 ? 'text-[#b45309]' : 'text-[#111]',
                                )}
                            >
                                {number(box.value)}
                            </p>
                        </Card>
                    ))}
                </div>
            )}

            {sells && (
                <Card className="overflow-hidden">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3">
                        <div className="min-w-0">
                            <h2 className="text-[15px] font-semibold text-[#111]">{t('منتجات موقعك')}</h2>
                            <p className="mt-0.5 text-[12px] text-[#9ca3af]">
                                {t('هذه منتجات أبعاد نفسها — ما تغيّره هنا يتغيّر في المتجر كلّه')}
                            </p>
                        </div>
                        {may.products && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={route('admin.products.index')}>
                                    <Package />
                                    {t('إدارة المنتجات')}
                                </Link>
                            </Button>
                        )}
                    </div>

                    {products.length === 0 && (
                        <p className="px-4 py-10 text-center text-[13px] text-[#9ca3af]">
                            {t('لا منتجات بعد — أضف منتجاتك لتظهر في موقعك')}
                        </p>
                    )}

                    <ul className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                        {products.map((row) => (
                            <li key={row.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                                <span className="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa]">
                                    {row.image ? (
                                        <img src={row.image} alt="" className="size-full object-cover" />
                                    ) : (
                                        <ImageIcon className="size-4 text-[#d1d5db]" />
                                    )}
                                </span>

                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[13px] font-medium text-[#111]">
                                        {row.name}
                                    </span>
                                    <span className="mt-0.5 flex flex-wrap items-center gap-1.5">
                                        {!row.published ? (
                                            <Badge variant="neutral">{t('مخفيّ عن الموقع')}</Badge>
                                        ) : !row.in_stock ? (
                                            <Badge variant="warning">{t('نفد — لا يظهر')}</Badge>
                                        ) : (
                                            <span className="text-[12px] text-[#9ca3af]">
                                                {t('ظاهر')} · {money(row.price, currency)}
                                            </span>
                                        )}
                                        {row.published && !row.in_stock && (
                                            <span className="text-[12px] text-[#9ca3af]">
                                                {money(row.price, currency)}
                                            </span>
                                        )}
                                    </span>
                                </span>

                                {editing === row.id ? (
                                    <span className="flex flex-wrap items-center gap-2">
                                        <label className="flex items-center gap-1.5 text-[12px] text-[#6b7280]">
                                            {t('السعر')}
                                            <Input
                                                type="number"
                                                step="0.001"
                                                min="0"
                                                value={draft.price}
                                                onChange={(e) => setDraft((d) => ({ ...d, price: e.target.value }))}
                                                className="h-9 w-24"
                                            />
                                        </label>
                                        <label className="flex items-center gap-1.5 text-[12px] text-[#6b7280]">
                                            {t('المخزون')}
                                            <Input
                                                type="number"
                                                min="0"
                                                value={draft.quantity}
                                                onChange={(e) =>
                                                    setDraft((d) => ({ ...d, quantity: e.target.value }))
                                                }
                                                className="h-9 w-20"
                                            />
                                        </label>
                                        <Button size="sm" onClick={() => save(row)}>
                                            <Check />
                                            {t('حفظ')}
                                        </Button>
                                        <Button size="sm" variant="ghost" onClick={() => setEditing(null)}>
                                            {t('إلغاء')}
                                        </Button>
                                    </span>
                                ) : (
                                    <span className="flex shrink-0 items-center gap-1">
                                        <span className="me-2 hidden text-[12px] tabular-nums text-[#6b7280] sm:inline">
                                            {t('المخزون')} {number(row.quantity)}
                                        </span>
                                        {may.products && (
                                            <>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => open(row)}
                                                    aria-label={t('تعديل السعر والمخزون')}
                                                >
                                                    <Wrench />
                                                    <span className="hidden sm:inline">{t('سعر ومخزون')}</span>
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    onClick={() => publish(row)}
                                                    aria-label={t(row.published ? 'إخفاء عن الموقع' : 'إظهار في الموقع')}
                                                >
                                                    {row.published ? <Eye /> : <EyeOff />}
                                                </Button>
                                                <Button variant="ghost" size="icon-sm" asChild>
                                                    <Link
                                                        href={route('admin.products.edit', row.id)}
                                                        aria-label={t('افتح المنتج')}
                                                    >
                                                        <Pencil />
                                                    </Link>
                                                </Button>
                                            </>
                                        )}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </Card>
            )}
        </AdminLayout>
    );
}
