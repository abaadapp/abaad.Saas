import { ExternalLink, Eye, Pencil } from 'lucide-react';

import PageHeader from '@/Components/PageHeader';
import SectionTabs, { THEME_TABS } from '@/Components/SectionTabs';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';

/**
 * ما تشترك فيه شاشاتُ الواجهة الخاصّة — الترويسةُ والشريط.
 *
 * ═══ ولمَ في ملفٍّ واحد ═══
 *
 * أربعُ شاشاتٍ تعرضها. ولو كُتبت في كلٍّ منها لاختلفت: زرٌّ يُسمّى «معاينة»
 * هنا و«عرض المتجر» هناك، وشارةٌ تقول «منشور» في شاشةٍ و«يعمل» في أختها عن
 * المتجر نفسِه. وهو ما وقع في شاشات البانِي قبل أن تُجمع في `shell`.
 */
export interface ThemeSite {
    name: string;
    /** ما يُخدم على العنوان فعلًا — لا ما يقوله المفتاح */
    published: boolean;
    url: string | null;
    slug: string | null;
    host: string;
}

export interface ThemeShell {
    theme: string;
    site: ThemeSite;
}

export default function ThemeHeader({
    site,
    current,
    subtitle,
}: {
    site: ThemeSite;
    /** اسمُ مسار التبويب النشط */
    current: string;
    subtitle: string;
}) {
    const t = useTranslate();

    return (
        <>
            <PageHeader
                title={site.name}
                subtitle={subtitle}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {/*
                            ورابطُ المتجر لا يُعرض إلّا إن كان يُفتح: زرٌّ يردّ
                            «غير موجود» يجعل صاحبَه يظنّ العطبَ في النظام.
                        */}
                        {site.url && (
                            <Button variant="outline" asChild>
                                <a href={site.url} target="_blank" rel="noreferrer">
                                    <ExternalLink />
                                    {t('زيارة المتجر')}
                                </a>
                            </Button>
                        )}
                        {/*
                            والمعاينةُ متجرُه نفسُه في لسانٍ جديد — منشورًا كان
                            أو لم يُنشر، ولا يفتحها أحدٌ سواه.
                        */}
                        <Button variant="outline" asChild>
                            <a href={route('admin.store.preview')} target="_blank" rel="noreferrer">
                                <Eye />
                                {t('معاينة')}
                            </a>
                        </Button>
                        <Button asChild>
                            <a href={route('admin.website.editor')}>
                                <Pencil />
                                {t('تعديل الصفحة')}
                            </a>
                        </Button>
                    </div>
                }
            />

            <SectionTabs tabs={THEME_TABS} current={current} />
        </>
    );
}

/** شارةُ الحال — لونٌ واحدٌ لكلّ حالٍ في الشاشات الأربع */
export function ThemeState({ published }: { published: boolean }) {
    const t = useTranslate();

    return (
        <Badge variant={published ? 'success' : 'neutral'}>
            {t(published ? 'منشور' : 'غير منشور — لا يفتحه أحد')}
        </Badge>
    );
}
