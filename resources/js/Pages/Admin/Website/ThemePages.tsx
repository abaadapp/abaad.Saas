import { Link, router, useForm, usePage } from '@inertiajs/react';
import { ExternalLink, FileText, Home, Info, Phone, Save, ShoppingBag } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import AdminLayout from '@/Layouts/AdminLayout';
import StoreImageField from '@/Components/StoreImageField';
import Toggle from '@/Components/Toggle';
import { PageActions, SettingsGroup, SettingsSection } from '@/Components/Settings';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import ThemeHeader, { type ThemeShell } from './theme/Shell';

/** ما يُرسَل حين يُطفئ الاثنتين — انظر `StoreNav::NONE` */
const NONE = 'none';

interface PageRow {
    key: string;
    path: string;
    /** الرئيسيةُ والمتجرُ لا يُطفآن — والمتجرُ قد لا يظهر مع ذلك */
    fixed: boolean;
    on: boolean;
    /** «مُشغَّلةٌ ولا تظهر» ولمَ — أو null */
    blocked: string | null;
}

interface Props extends ThemeShell {
    rows: PageRow[];
    optional: string[];
    aboutImage: string;
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
 * «الموقع الإلكتروني ‹ الصفحات» لصاحب الواجهة الخاصّة.
 *
 * ═══ والصفحاتُ الأربعُ نفسُها ═══
 *
 * «الرئيسية» و«المتجر» و«من نحن» و«تواصل معنا» — هي صفحاتُ كلّ متجرٍ في
 * أبعاد (انظر `Blueprints::PAGES`). وكانت واجهتُه صفحةً ورفًّا وسلّة بلا
 * قائمةٍ أصلًا: من أراد أن يعرف من يبيعه لا يجد بابًا.
 *
 * ═══ ولا زرَّ «صفحة جديدة» ═══
 *
 * وهو الفرقُ الوحيد عن شاشة جاره: صفحاتُ هذه الواجهة قوالبُ مكتوبة لا
 * صفوفٌ في جدول، و«صفحة جديدة» تعني صفحةً بلا قالبٍ يرسمها. وزرٌّ يُضغط
 * فلا يقع شيء أسوأُ من زرٍّ لا يُرسم.
 */
export default function ThemePages() {
    const { site, rows, optional, aboutImage } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    /*
     * ونموذجُ الصورة وحدَه — والمفاتيحُ تُقلَّب بحفظٍ مستقلّ.
     *
     * فمقبضٌ يُقلَّب ثمّ يُنتظر زرُّ حفظٍ في آخر الشاشة يُنسى مرفوعًا، ويظنّ
     * صاحبُه أنّه أطفأ صفحةً وهي مفتوحةٌ على زبائنه.
     */
    const form = useForm({ store_about_image: aboutImage });

    /** ما أذِن به — والفراغُ «كلُّها» كما يقرؤه `StoreNav::allowed` */
    const on = (key: string) => rows.find((r) => r.key === key)?.on ?? false;

    const toggle = (key: string, next: boolean) => {
        const picked = optional.filter((p) => (p === key ? next : on(p)));

        /*
         * ولا يُرسَل فراغٌ حين يُطفئ الاثنتين: الفراغُ يعني «كلُّها» فينقلب
         * الإطفاءُ إشعالًا. ورمزٌ لا يصحّ منها يُرشَّح في `allowed` ويؤول
         * إلى قائمةٍ فارغة — وهو ما يُقصَد هنا بالضبط.
         */
        router.post(
            route('admin.marketing.store.save'),
            { store_pages: picked.length ? picked.join(',') : NONE },
            { preserveScroll: true },
        );
    };

    const save = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.marketing.store.save'), { preserveScroll: true });
    };

    return (
        <AdminLayout title="الموقع الإلكتروني">
            <ThemeHeader
                site={site}
                current="admin.website.pages"
                subtitle={t('صفحاتُ متجرك — ما يُفتح منها، وأين يُكتب ما فيها')}
            />

            <div className="mb-6 grid gap-3">
                {rows.map((row) => {
                    const said = SAID[row.key];
                    const Icon = said.icon;
                    const live = site.url ? site.url.replace(/\/$/, '') + (row.path === '/' ? '' : row.path) : null;

                    return (
                        <Card key={row.key} className="p-5" data-testid={`page-${row.key}`}>
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <div className="flex min-w-0 gap-3">
                                    <Icon className="mt-0.5 size-5 shrink-0 text-[#6b7280]" />
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h3 className="font-bold text-[#111]">{t(said.label)}</h3>
                                            <span dir="ltr" className="font-mono text-[12px] text-[#9ca3af]">
                                                {row.path}
                                            </span>
                                            {row.fixed && <Badge variant="neutral">{t('ثابتة')}</Badge>}
                                        </div>

                                        <p className="mt-1 text-[13px] text-[#6b7280]">{t(said.hint)}</p>

                                        {/*
                                            و«مُشغَّلةٌ ولا تظهر» تُقال بسببها —
                                            فلا يظنّ صاحبُها العطبَ في النظام.
                                        */}
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
                                    {live && row.on && !row.blocked && (
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

                                    {!row.fixed && (
                                        <div data-testid={`toggle-${row.key}`}>
                                            <Toggle
                                                on={row.on}
                                                onChange={(v) => toggle(row.key, v)}
                                                label="تُعرض"
                                            />
                                        </div>
                                    )}
                                </div>
                            </div>
                        </Card>
                    );
                })}
            </div>

            <form onSubmit={save}>
                <SettingsSection
                    title="صورة «من نحن»"
                    description="تظهر إلى جانب نبذتك في صفحة «من نحن» — واتركها فارغة فيظهر شعارك مكانها."
                    divided
                >
                    <SettingsGroup>
                        <StoreImageField
                            label="صورة الصفحة"
                            hint="تظهر إلى جانب نبذتك — وشعارُك مكانها إن تركتها فارغة"
                            value={form.data.store_about_image}
                            onChange={(v) => form.setData('store_about_image', v)}
                        />
                    </SettingsGroup>

                    <SettingsGroup>
                        <PageActions>
                            <Button type="submit" loading={form.processing}>
                                <Save />
                                {t('حفظ')}
                            </Button>
                        </PageActions>
                    </SettingsGroup>
                </SettingsSection>
            </form>
        </AdminLayout>
    );
}
