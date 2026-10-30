import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Building2, Layers, Pencil, Plus, Shapes } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import SectorIcon from '@/Components/SectorIcon';
import SmartLink from '@/Components/SmartLink';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import SectorDialog, { type SectorCard } from './partials/SectorDialog';
import type { PageProps } from '@/types';

interface Props {
    sectors: SectorCard[];
    icons: string[];
}

/**
 * القطاعات — بطاقةٌ لكلّ قطاع، تفتح باقاته وشركاته ونظامه.
 *
 * ولا تحلّ محلّ «الشركات» و«الباقات» و«الاشتراكات»: هي مدخلٌ يجمعها بحسب القطاع.
 */
export default function SectorsIndex() {
    const { sectors, icons } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [editing, setEditing] = useState<SectorCard | 'new' | null>(null);

    return (
        <PlatformLayout title="القطاعات">
            <PageHeader
                title="القطاعات"
                subtitle={t('كل قطاع يجمع باقاته وشركاته واشتراكاتها، ونوع النشاط يبقى داخله كما هو')}
                actions={
                    <Button onClick={() => setEditing('new')} data-testid="sector-new">
                        <Plus />
                        {t('قطاع جديد')}
                    </Button>
                }
            />

            {sectors.length === 0 ? (
                <Card className="px-5 py-16 text-center">
                    <Shapes className="mx-auto size-8 text-[#d1d5db]" />
                    <p className="mt-3 font-medium text-[#111]">{t('لا توجد قطاعات بعد')}</p>
                </Card>
            ) : (
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {sectors.map((s) => (
                        <Card key={s.id} className={cn('flex flex-col p-5', !s.active && 'opacity-70')} data-testid={`sector-card-${s.code}`}>
                            <SmartLink
                                routeName="super-admin.sectors.show"
                                href={route('super-admin.sectors.show', s.id)}
                                className="flex items-center gap-3 rounded-[12px] outline-none focus-visible:ring-2 focus-visible:ring-[#111]"
                            >
                                <span className="flex size-12 shrink-0 items-center justify-center rounded-[12px] bg-[#f2f2f0] text-[#111]">
                                    <SectorIcon icon={s.icon} className="size-6" />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[16px] font-bold text-[#111]">{t(s.name)}</span>
                                    {s.name_en && (
                                        <span dir="ltr" className="block truncate text-[12px] text-[#9ca3af]">
                                            {s.name_en}
                                        </span>
                                    )}
                                </span>
                                <span
                                    className={cn(
                                        'shrink-0 rounded-full px-2.5 py-0.5 text-[12px] font-medium',
                                        s.active ? 'bg-[#ecfdf5] text-[#047857]' : 'bg-[#f3f4f6] text-[#6b7280]',
                                    )}
                                    data-testid="sector-status"
                                >
                                    {t(s.active ? 'نشط' : 'غير نشط')}
                                </span>
                            </SmartLink>

                            <dl className="mt-4 grid grid-cols-2 gap-2 text-[13px]">
                                <div className="flex items-center gap-2 rounded-[10px] bg-[#fafafa] px-3 py-2">
                                    <Layers className="size-4 text-[#9ca3af]" />
                                    <dt className="text-[#6b7280]">{t('الباقات')}</dt>
                                    <dd className="ms-auto font-semibold tabular-nums text-[#111]">{number(s.plans_count)}</dd>
                                </div>
                                <div className="flex items-center gap-2 rounded-[10px] bg-[#fafafa] px-3 py-2">
                                    <Building2 className="size-4 text-[#9ca3af]" />
                                    <dt className="text-[#6b7280]">{t('الشركات')}</dt>
                                    <dd className="ms-auto font-semibold tabular-nums text-[#111]">{number(s.businesses_count)}</dd>
                                </div>
                            </dl>

                            <div className="mt-4 flex items-center justify-between gap-2 text-[12px] text-[#9ca3af]">
                                <span>
                                    {t(s.system)} · <span dir="ltr">{s.code}</span>
                                </span>
                                <Button variant="ghost" size="sm" onClick={() => setEditing(s)} data-testid={`sector-edit-${s.code}`}>
                                    <Pencil />
                                    {t('تعديل')}
                                </Button>
                            </div>
                        </Card>
                    ))}
                </div>
            )}

            {editing && <SectorDialog sector={editing === 'new' ? null : editing} icons={icons} onClose={() => setEditing(null)} />}
        </PlatformLayout>
    );
}
