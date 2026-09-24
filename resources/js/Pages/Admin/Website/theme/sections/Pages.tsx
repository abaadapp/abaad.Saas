import { Link } from '@inertiajs/react';
import { ExternalLink, FileText, Home, Info, Phone, ShoppingBag } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import StoreImageField from '@/Components/StoreImageField';
import Toggle from '@/Components/Toggle';
import { SettingsGroup, SettingsSection } from '@/Components/Settings';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import type { ThemeSite } from '../Shell';
import { NO_PAGES, type ThemeForm } from './form';

export interface PageRow {
    key: string;
    path: string;
    /** الرئيسيةُ والمتجرُ لا يُطفآن — والمتجرُ قد لا يظهر مع ذلك */
    fixed: boolean;
    on: boolean;
    /** «مُشغَّلةٌ ولا تظهر» ولمَ — أو null */
    blocked: string | null;
}

/** ما تقوله الشاشةُ عن كلّ صفحة — وأسماؤها هنا بالعربية لا بلغة الزائر */
const SAID: Record<string, { label: string; hint: string; icon: LucideIcon; edit: string | null }> = {
    home: {
        label: 'الرئيسية',
        hint: 'واجهةُ متجرك وأقسامُها — أوّلُ ما يفتحه زبونك',
        icon: Home,
        edit: 'admin.website.editor',
    },
    shop: {
        label: 'المتجر',
        hint: 'بضاعتُك كلُّها بفئاتها وبحثِها — تُبنى من أصنافك المعروضة',
        icon: ShoppingBag,
        edit: 'admin.website.index',
    },
    about: {
        label: 'من نحن',
        hint: 'نبذتُك وصورتُها — وهي النبذةُ نفسُها التي تظهر في قسم «عنّا»',
        icon: FileText,
        edit: 'admin.website.editor',
    },
    contact: {
        label: 'تواصل معنا',
        hint: 'هاتفُك وواتسابك وبريدك وعنوانك وساعاتُ عملك',
        icon: Phone,
        edit: 'admin.settings.index',
    },
};

/**
 * «الصفحات» — ما يُفتح منها، وأين يُكتب ما فيها.
 *
 * والصفحاتُ الأربعُ نفسُها التي لكلّ متجرٍ في أبعاد (انظر `Blueprints::PAGES`)،
 * ولا زرَّ «صفحة جديدة»: صفحاتُ هذه الواجهة قوالبُ مكتوبة لا صفوفٌ في جدول.
 *
 * ═══ والمفاتيحُ تُحفظ مع الصفحة لا وحدَها ═══
 *
 * كانت تُرسَل عند كلّ قلبة لأنّ زرَّ الحفظ كان في آخر شاشةٍ طويلة فيُنسى.
 * وشريطُ الحفظ اللاصق يظهر عند أوّل تغيير ولا يُنسى — فالقلبةُ تنتظره كما
 * ينتظره كلُّ حقلٍ في الصفحة، ولا يُكتب في القاعدة ما لم يُقصد حفظُه.
 */
export default function Pages({
    form,
    site,
    rows,
    optional,
}: {
    form: ThemeForm;
    site: ThemeSite;
    rows: PageRow[];
    /** الصفحاتُ التي تُطفأ وتُشعل — الرئيسيةُ والمتجرُ ليسا منها */
    optional: string[];
}) {
    const t = useTranslate();

    /** ما أذِن به الآن — من النموذج لا من الخادم، فالقلبةُ تُرى قبل أن تُحفظ */
    const picked = form.data.store_pages === NO_PAGES
        ? []
        : form.data.store_pages.split(',').filter(Boolean);

    const on = (key: string) => picked.includes(key);

    const toggle = (key: string, next: boolean) => {
        const kept = optional.filter((p) => (p === key ? next : on(p)));

        /*
         * ولا يُرسَل فراغٌ حين يُطفئ الاثنتين: الفراغُ يعني «كلُّها» فينقلب
         * الإطفاءُ إشعالًا — انظر `StoreNav::allowed`.
         */
        form.setData('store_pages', kept.length ? kept.join(',') : NO_PAGES);
    };

    return (
        <section id="pages" className="scroll-mt-24">
            <SettingsSection
                title="الصفحات"
                description="صفحاتُ متجرك الأربع — ما يُفتح منها، وأين يُكتب ما فيها."
                divided
            >
                <SettingsGroup>
                    <div className="grid gap-3">
                        {rows.map((row) => {
                            const said = SAID[row.key];
                            const Icon = said.icon;
                            const live = site.url
                                ? site.url.replace(/\/$/, '') + (row.path === '/' ? '' : row.path)
                                : null;
                            const shown = row.fixed || on(row.key);

                            return (
                                <div
                                    key={row.key}
                                    data-testid={`page-${row.key}`}
                                    className="flex flex-wrap items-start justify-between gap-4 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-4"
                                >
                                    <div className="flex min-w-0 gap-3">
                                        <Icon className="mt-0.5 size-5 shrink-0 text-[#6b7280]" />
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <h4 className="font-bold text-[#111]">{t(said.label)}</h4>
                                                <span dir="ltr" className="font-mono text-[12px] text-[#9ca3af]">
                                                    {row.path}
                                                </span>
                                                {row.fixed && <Badge variant="neutral">{t('ثابتة')}</Badge>}
                                            </div>

                                            <p className="mt-1 text-[13px] text-[#6b7280]">{t(said.hint)}</p>

                                            {/* و«مُشغَّلةٌ ولا تظهر» تُقال بسببها — فلا يظنّ صاحبُها العطبَ في النظام */}
                                            {row.blocked && (
                                                <p
                                                    data-testid={`blocked-${row.key}`}
                                                    className="mt-2 inline-flex items-start gap-2 rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] leading-relaxed text-[#b45309]"
                                                >
                                                    <Info className="mt-px size-4 shrink-0" />
                                                    {t(row.blocked)}
                                                </p>
                                            )}
                                        </div>
                                    </div>

                                    <div className="flex shrink-0 flex-wrap items-center gap-2">
                                        {/* والرابطُ لا يُعرض إلّا إن كان يُفتح — لا على صفحةٍ مطفأةٍ ولا على متجرٍ لم يُنشر */}
                                        {live && shown && ! row.blocked && (
                                            <Button variant="outline" size="sm" asChild>
                                                <a href={live} target="_blank" rel="noreferrer">
                                                    <ExternalLink />
                                                    {t('افتحها')}
                                                </a>
                                            </Button>
                                        )}

                                        {said.edit && (
                                            <Button variant="outline" size="sm" asChild>
                                                <Link href={route(said.edit)}>{t('تحرير')}</Link>
                                            </Button>
                                        )}

                                        {! row.fixed && (
                                            <div data-testid={`toggle-${row.key}`}>
                                                <Toggle on={on(row.key)} onChange={(v) => toggle(row.key, v)} label="تُعرض" />
                                            </div>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </SettingsGroup>

                <SettingsGroup title="صورة «من نحن»">
                    <StoreImageField
                        label="صورة الصفحة"
                        hint="تظهر إلى جانب نبذتك — وشعارُك مكانها إن تركتها فارغة"
                        value={form.data.store_about_image}
                        onChange={(v) => form.setData('store_about_image', v)}
                    />
                </SettingsGroup>
            </SettingsSection>
        </section>
    );
}
