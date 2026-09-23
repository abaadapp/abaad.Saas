import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { PRODUCT_TABS } from '@/Components/SectionTabs';
import SmartLink from '@/Components/SmartLink';
import DataTable, { type Column, type Filter } from '@/Components/DataTable';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import BoutiqueDialog, { type BoutiqueFields } from './BoutiqueDialog';

export interface BoutiqueRow {
    id: number;
    name: string;
    name_en: string | null;
    phone: string | null;
    contact_person: string | null;
    rate: number;
    active: boolean;
    products_count: number;
    /** ما بِيع له في الشهر المختار — من الخادم لا من عدٍّ في الشاشة */
    gross: number;
    settled: boolean;
}

interface Props {
    boutiques: BoutiqueRow[];
    period: string;
    periods: { value: string; label: string }[];
}

/**
 * البوتيكات — من يبيع تحت سقف المحلّ، وبأيّ نسبة.
 *
 * والشاشةُ تجيب سؤالين: «من عندي» و«كم باع هذا الشهر». والثاني هو ما يُفتح
 * لأجله الشهرُ كلَّه، فلا يُخبَّأ خلف فتح كلّ صفّ.
 */
export default function BoutiquesIndex() {
    const { boutiques, period, periods, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    // العملةُ من سياق المتجر — لا يُرسلها كلُّ متحكّم من جديد
    const currency = context!.currency;

    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<BoutiqueRow | null>(null);

    const fields = (b: BoutiqueRow): BoutiqueFields => ({
        name: b.name, name_en: b.name_en, phone: b.phone, contact_person: b.contact_person,
        commission_rate: b.rate, active: b.active, notes: null,
    });

    const edit = (b: BoutiqueRow) => {
        setEditing(b);
        setOpen(true);
    };

    const columns: Column<BoutiqueRow>[] = [
        {
            key: 'name',
            header: 'البوتيك',
            cell: (b) => (
                <SmartLink
                    routeName="admin.boutiques.show"
                    href={route('admin.boutiques.show', b.id)}
                    className="font-medium text-[#111] hover:underline"
                >
                    {b.name}
                </SmartLink>
            ),
        },
        { key: 'contact_person', header: 'المسؤول', cell: (b) => b.contact_person || '—' },
        {
            key: 'phone',
            header: 'رقم التواصل',
            cell: (b) => (b.phone ? <span dir="ltr">{b.phone}</span> : '—'),
        },
        {
            key: 'rate',
            header: 'نسبة المتجر',
            align: 'end',
            cell: (b) => <span className="tabular-nums font-medium">{number(b.rate)}%</span>,
        },
        {
            key: 'products_count',
            header: 'الأصناف',
            align: 'end',
            cell: (b) => <span className="tabular-nums">{number(b.products_count)}</span>,
        },
        {
            key: 'gross',
            header: 'مبيعات الشهر',
            align: 'end',
            cell: (b) =>
                b.gross > 0 ? (
                    <span className="tabular-nums font-medium">{money(b.gross, currency)}</span>
                ) : (
                    <span className="text-[#9ca3af]">—</span>
                ),
        },
        {
            key: 'status',
            header: 'الحالة',
            cell: (b) =>
                !b.active ? (
                    <Badge variant="neutral">{t('غير نشط')}</Badge>
                ) : b.settled ? (
                    <Badge variant="success">{t('سُوّي هذا الشهر')}</Badge>
                ) : b.gross > 0 ? (
                    <Badge variant="warning">{t('بانتظار التسوية')}</Badge>
                ) : (
                    <Badge variant="neutral">{t('لا مبيعات')}</Badge>
                ),
        },
        {
            key: 'actions',
            header: 'إجراءات',
            align: 'end',
            cell: (b) => (
                <Button variant="ghost" size="sm" onClick={() => edit(b)}>
                    <Pencil />
                    {t('تعديل')}
                </Button>
            ),
        },
    ];

    const filters: Filter<BoutiqueRow>[] = [
        {
            label: 'الشهر',
            param: 'period',
            options: periods,
            initial: period,
        },
    ];

    return (
        <AdminLayout title="البوتيكات">
            <PageHeader
                title="البوتيكات"
                subtitle={t('من يبيع تحت سقف محلّك — ونسبتك ممّا يبيع')}
                actions={
                    <Button
                        onClick={() => {
                            setEditing(null);
                            setOpen(true);
                        }}
                    >
                        <Plus />
                        {t('بوتيك جديد')}
                    </Button>
                }
            />

            <SectionTabs tabs={PRODUCT_TABS} current="admin.boutiques.index" className="mb-5" />

            <Card className="overflow-hidden">
                <DataTable
                    rows={boutiques}
                    columns={columns}
                    rowKey={(b) => b.id}
                    filters={filters}
                    searchable={(b) => `${b.name} ${b.name_en ?? ''} ${b.contact_person ?? ''}`}
                    searchPlaceholder="ابحث باسم البوتيك أو مسؤوله…"
                    empty="لا بوتيكات بعد — أضف أوّل واحد"
                    toolbar={
                        <span className="ms-auto text-[13px] text-[#6b7280]">
                            {t('مبيعات الشهر')}:{' '}
                            <span className="font-semibold text-[#111]">
                                {money(
                                    boutiques.reduce((a, b) => a + b.gross, 0),
                                    currency,
                                )}
                            </span>
                        </span>
                    }
                />
            </Card>

            {/*
                والمُرشِّحُ يُرسَل إلى الخادم لا يُطبَّق في الشاشة: «مبيعات
                الشهر» رقمٌ يحسبه الخادم على الشهر كلِّه، فتبديلُه في المتصفّح
                يعرض رقمَ شهرٍ ويقول إنّه شهرٌ آخر.
            */}
            <div className="sr-only" aria-hidden>
                {period}
            </div>

            <BoutiqueDialog
                open={open}
                onOpenChange={setOpen}
                id={editing?.id ?? null}
                initial={editing ? fields(editing) : null}
            />
        </AdminLayout>
    );
}
