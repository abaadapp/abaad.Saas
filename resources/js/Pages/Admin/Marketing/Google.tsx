import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Check, Copy, ExternalLink, MapPin, QrCode, Star } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface Link {
    place_id: string | null;
    source: string;
    on_receipt: boolean;
    review_url: string | null;
    place_url: string | null;
}

interface Props {
    settings: Record<string, string>;
    link: Link;
    internal: number;
}

/** رابطٌ يُنسخ بضغطة — العنوان طويلٌ ولا يُكتب بيد */
function CopyRow({ label, url, hint }: { label: string; url: string; hint?: string }) {
    const t = useTranslate();
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
            setTimeout(() => setCopied(false), 1600);
        } catch {
            // متصفّحٌ يمنع الحافظة: الرابط ظاهرٌ ويُحدَّد باليد
            setCopied(false);
        }
    };

    return (
        <div className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] p-3">
            <div className="mb-1.5 flex items-center justify-between gap-2">
                <span className="text-[13px] font-medium text-[#111]">{t(label)}</span>
                <div className="flex items-center gap-1.5">
                    <Button type="button" size="sm" variant="outline" onClick={copy}>
                        {copied ? <Check className="text-[#047857]" /> : <Copy />}
                        {t(copied ? 'نُسخ' : 'نسخ')}
                    </Button>
                    <Button asChild size="sm" variant="outline">
                        <a href={url} target="_blank" rel="noreferrer">
                            <ExternalLink />
                            {t('فتح')}
                        </a>
                    </Button>
                </div>
            </div>
            {/* الرابط لاتينيّ في صفحةٍ عربية: بلا dir ينقلب أوّلُه إلى آخره */}
            <p dir="ltr" className="break-all text-start text-[12px] text-[#6b7280]">{url}</p>
            {hint && <p className="mt-1.5 text-[12px] text-[#9ca3af]">{t(hint)}</p>}
        </div>
    );
}

/**
 * ربط خرائط Google.
 *
 * وكان زرًّا في شاشة التقييمات يفتح `business.google.com` في تبويبٍ خارجيّ:
 * اسمُه «ربط تقييمات Google Maps» ولا يربط شيئًا — يُخرج التاجر من لوحته
 * ويتركه هناك، ولا يعود بمعرّفٍ ولا يُحفظ شيء.
 *
 * وما تفعله هذه الصفحة بالضبط: تحفظ معرّف المكان، فيصير للمحلّ رابطُ «اكتب
 * تقييمًا» يفتح ملفَّه بعينه — يُنسخ ليُرسل، أو يُطبع رمزًا على الإيصال
 * يمسحه الزبون وهو واقفٌ عند المنضدة.
 */
export default function MarketingGoogle() {
    const { settings, link, internal } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm({
        google_maps_url: settings.google_maps_url ?? '',
        google_review_on_receipt: (settings.google_review_on_receipt ?? '0') === '1',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.marketing.google.save'), { preserveScroll: true });
    };

    const linked = !! link.place_id;

    return (
        <AdminLayout title="ربط خرائط Google">
            <PageHeader
                title="ربط خرائط Google"
                subtitle={t('اربط محلّك بملفّه على الخرائط ليصير لطلب التقييم رابطٌ يفتح محلّك بعينه')}
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <form onSubmit={submit} className="lg:col-span-2">
                    <Card className="p-6">
                        <div className="mb-4 flex items-start gap-3">
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-[#f3f4f6] text-[#111]">
                                <MapPin className="size-[18px]" />
                            </span>
                            <div>
                                <h3 className="font-bold text-[#111]">{t('معرّف المكان')}</h3>
                                <p className="mt-0.5 text-[13px] text-[#6b7280]">
                                    {t('الصق «Place ID» الخاصّ بمحلّك، أو رابطًا يحمله.')}
                                </p>
                            </div>
                        </div>

                        <Input
                            dir="ltr"
                            value={form.data.google_maps_url}
                            onChange={(e) => form.setData('google_maps_url', e.target.value)}
                            placeholder="ChIJ… أو https://…place_id:ChIJ…"
                            aria-label={t('معرّف المكان')}
                        />
                        {form.errors.google_maps_url && (
                            <p className="mt-2 text-[12px] text-[#b91c1c]">{form.errors.google_maps_url}</p>
                        )}

                        {/*
                            من أين يأتي المعرّف يُقال هنا لا يُترك للبحث: رابطُ
                            الخرائط العاديّ لا يحمله، وهو أوّل ما يلصقه التاجر.
                        */}
                        <p className="mt-2 text-[12px] text-[#9ca3af]">
                            {t('رابط الخرائط العاديّ لا يحمل المعرّف. خُذه من أداة Google:')}{' '}
                            <a
                                href="https://developers.google.com/maps/documentation/places/web-service/place-id"
                                target="_blank"
                                rel="noreferrer"
                                className="text-[#111] underline"
                            >
                                Place ID Finder
                            </a>
                        </p>

                        <label className="mt-5 flex cursor-pointer items-start gap-2.5">
                            <input
                                type="checkbox"
                                checked={form.data.google_review_on_receipt}
                                onChange={(e) => form.setData('google_review_on_receipt', e.target.checked)}
                                className="mt-0.5 size-4 rounded border-[#d1d5db] accent-[#111]"
                            />
                            <span>
                                <span className="flex items-center gap-1.5 text-sm font-medium text-[#111]">
                                    <QrCode className="size-4" />
                                    {t('اطبع رمز التقييم على الإيصال')}
                                </span>
                                <span className="mt-0.5 block text-[12px] text-[#6b7280]">
                                    {t('يمسحه الزبون وهو عند المنضدة — وهي اللحظة الوحيدة التي يكتب فيها أحدٌ تقييمًا.')}
                                </span>
                            </span>
                        </label>

                        <div className="mt-6 flex items-center gap-3">
                            <Button type="submit" loading={form.processing}>
                                {t('حفظ')}
                            </Button>
                            {linked && (
                                <span className="flex items-center gap-1.5 text-[13px] text-[#047857]">
                                    <Check className="size-4" />
                                    {t('مربوط')}
                                </span>
                            )}
                        </div>
                    </Card>
                </form>

                <div className="space-y-4">
                    {linked ? (
                        <>
                            <CopyRow
                                label="رابط طلب التقييم"
                                url={link.review_url!}
                                hint="أرسله للعميل أو اطبعه — يفتح نافذة «اكتب تقييمًا» على محلّك."
                            />
                            <CopyRow
                                label="ملفّك على الخرائط"
                                url={link.place_url!}
                                hint="افتحه وتأكّد أنّه محلّك — معرّفٌ خاطئ يرسل زبائنك إلى محلٍّ آخر."
                            />
                        </>
                    ) : (
                        <Card className="p-5 text-center">
                            <Star className="mx-auto size-6 text-[#9ca3af]" />
                            <p className="mt-2 text-[13px] font-medium text-[#111]">{t('لم يُربط بعد')}</p>
                            <p className="mt-1 text-[12px] text-[#9ca3af]">
                                {t('احفظ معرّف المكان ليظهر رابط طلب التقييم هنا.')}
                            </p>
                        </Card>
                    )}

                    {/*
                        الفرق بين التقييمين يُقال صراحةً: تقييمات النظام تُكتب
                        داخله وتُنشر بإذنه، وتقييمات Google تُكتب هناك وتبقى
                        هناك — لا تُسحب إلى هذه الشاشة.
                    */}
                    <Card className={cn('p-4 text-[12px] leading-relaxed text-[#6b7280]')}>
                        <p className="mb-1 text-[13px] font-medium text-[#111]">{t('ما يفعله هذا الربط وما لا يفعله')}</p>
                        <p>
                            {t('يصنع رابطًا يفتح تقييم محلّك على Google. ولا يسحب نصوص التقييمات إلى النظام — ذاك يحتاج موافقة Google على النشاط نفسه، لا مفتاحًا يُكتب هنا.')}
                        </p>
                        <p className="mt-2">
                            {t('وتقييمات النظام — :n — تبقى مستقلّةً عنها.', { n: internal })}
                        </p>
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}
