import { Link, usePage } from '@inertiajs/react';
import {
    CreditCard,
    ExternalLink,
    MapPin,
    MessageCircle,
    Puzzle,
    type LucideIcon,
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

/** بطاقةُ أداةٍ كما يبنيها App\Support\Integrations::cards */
interface App {
    key: string;
    name: string;
    site: string;
    line: string;
    category: string;
    tint: string;
    /** بابُ الأداة — و`null` لأداةٍ في الدليل ولمّا تُبنَ */
    route: string | null;
    built: boolean;
    licensed: boolean;
    status: { state: string; label: string };
}

interface Props {
    apps: App[];
}

/**
 * أيقونةُ الأداة — مرسومةٌ هنا لا مُرسَلةٌ من الخادم.
 *
 * والخادم يرسل المفتاح واللون: اسمُ أيقونةٍ يعبر الشبكة يصير عقدًا بين
 * PHP ومكتبة رسمٍ في المتصفّح — تُبدَّل المكتبة فتنكسر أسماءٌ في ملفّ
 * PHP لا يعرف أحدٌ لماذا هي فيه.
 */
const ICONS: Record<string, LucideIcon> = {
    google: MapPin,
    whatsapp: MessageCircle,
    amwalpay: CreditCard,
};

/** حالُ الأداة → لونُ شارتها. وما لا يُعرف رماديّ لا أخضر */
const TONE: Record<string, 'success' | 'warning' | 'neutral' | 'outline'> = {
    ready: 'success',
    partial: 'warning',
    off: 'neutral',
    unbuilt: 'outline',
};

function AppCard({ app }: { app: App }) {
    const t = useTranslate();
    const Icon = ICONS[app.key] ?? Puzzle;

    return (
        <Card className="flex flex-col p-5">
            <div className="flex items-start gap-3">
                <span
                    className="flex size-12 shrink-0 items-center justify-center rounded-[14px]"
                    style={{ background: app.tint + '14', color: app.tint }}
                >
                    <Icon className="size-6" />
                </span>

                <div className="min-w-0">
                    <h3 className="truncate font-bold text-[#111]">{app.name}</h3>
                    {/* العنوان لاتينيّ في صفحةٍ عربية: بلا dir ينقلب أوّلُه إلى آخره */}
                    <p
                        className="mt-0.5 flex items-center gap-1 text-[12px] text-[#9ca3af]"
                        dir="ltr"
                    >
                        {app.site}
                        <ExternalLink className="size-3" />
                    </p>
                </div>
            </div>

            <p className="mt-4 grow text-[13px] leading-relaxed text-[#6b7280]">{app.line}</p>

            <div className="mt-5 flex items-center justify-between gap-3 border-t border-[var(--ui-border,#e8e8e8)] pt-4">
                {/*
                 * زرٌّ يفتح، أو لا زرّ.
                 *
                 * وما لم يُبنَ لا يُرسم له زرٌّ مطفأ يُضغط فلا يقع شيء: التاجر
                 * يجرّبه مرّتين ثمّ يظنّ العطب في متصفّحه. فيُقال بالحرف إنّه
                 * لم يُهيّأ بعد، ويُترك موضعُ الزرّ فارغًا.
                 */}
                {app.built && app.route ? (
                    <Button asChild variant="outline" size="sm">
                        <Link href={route(app.route)}>{t('عرض التكامل')}</Link>
                    </Button>
                ) : (
                    <span className="text-[12px] text-[#9ca3af]">{t('يهيّئه أبعاد قريبًا')}</span>
                )}

                {/*
                 * والباقةُ تسبق الحال: أداةٌ خارج الباقة لا يُقال عنها «غير
                 * مربوطة» — فيذهب صاحبها يربطها ويُردّ.
                 */}
                {app.licensed ? (
                    <Badge variant={TONE[app.status.state] ?? 'neutral'}>{app.status.label}</Badge>
                ) : (
                    <Badge variant="outline">{t('خارج باقتك')}</Badge>
                )}
            </div>
        </Card>
    );
}

/**
 * لوحةُ التطبيقات التكاملية — بمَ رُبط هذا المتجر، وبمَ يستطيع أن يُربط.
 *
 * وتُعرض الأدوات كلُّها لا المربوطةُ وحدها: لوحةٌ لا تعرض إلا ما رُبط تكون
 * فارغةً عند كلّ متجرٍ جديد، فلا تقول لصاحبها ما الذي يستطيع ربطه — وهو
 * السؤال الذي فُتحت من أجله.
 */
export default function Integrations() {
    const { apps } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    return (
        <AdminLayout title="التطبيقات التكاملية">
            <PageHeader
                title="التطبيقات التكاملية"
                subtitle={t('اربط متجرك بالأدوات التي يعمل بها — والربطُ هنا، وما تفعله الأداةُ في قسمها')}
            />

            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3">
                {apps.map((app) => (
                    <AppCard key={app.key} app={app} />
                ))}
            </div>
        </AdminLayout>
    );
}
