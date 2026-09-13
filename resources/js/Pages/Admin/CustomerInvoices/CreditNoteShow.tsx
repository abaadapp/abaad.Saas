import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { FileText } from 'lucide-react';

import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import DocumentMeta from '@/Components/DocumentMeta';
import DocumentPanel, { DocumentAside } from '@/Components/DocumentPanel';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Props {
    note: {
        id: number;
        number: string;
        amount: number;
        tax_amount: number;
        net: number;
        reason: string | null;
        issued_at: string | null;
    };
    invoice: { id: number; number: string; customer: string | null; total: number } | null;
    paper: { html: string; size: string; url: string };
}

/**
 * إشعارٌ دائن واحد — وورقتُه إلى جانبه.
 *
 * ═══ والبابُ الذي لم يكن ═══
 *
 * كان الإشعارُ سطرًا في قائمةٍ داخل الفاتورة: رقمٌ ومبلغٌ وسبب، لا يُفتح ولا
 * يُطبع ولا عنوانَ له يُرسَل. وهو مستندٌ ماليٌّ يُنقص ذمّةً — يُطلب في
 * تدقيق، وتُرفَق صورتُه بمطالبةٍ تُخصَم منها. فمن سُئل عنه كان جوابُه لقطةَ
 * شاشة.
 *
 * ═══ والمبلغُ يُقرأ مفصَّلًا ═══
 *
 * `amount` شاملٌ للضريبة و`tax_amount` نصيبُها منه — انظر
 * `CustomerInvoices::creditNote`. ومبلغٌ واحدٌ معروضٌ بلا تفصيل يجعل من
 * يراجعه يطرح بيده ليعرف كم رُدّ من الضريبة.
 */
export default function CreditNoteShow() {
    const { note, invoice, paper, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const [previewing, setPreviewing] = useState(false);

    return (
        <AdminLayout title={note.number}>
            <BackLink
                routeName="admin.customerInvoices.index"
                href={route('admin.customerInvoices.index')}
                label="كل الفواتير"
            />

            <PageHeader
                title={note.number}
                subtitle={`${t('إشعار دائن')}${invoice?.customer ? ` — ${invoice.customer}` : ''}`}
                actions={
                    <Button variant="outline" className="xl:hidden" onClick={() => setPreviewing(true)}>
                        <FileText />
                        {t('معاينة الورقة')}
                    </Button>
                }
            />

            <div className="grid grid-cols-1 gap-6 xl:grid-cols-5">
                <div className="min-w-0 space-y-4 xl:col-span-3">
                    <DocumentMeta
                        cells={[
                            { label: 'تاريخ الإشعار', value: note.issued_at || '—', ltr: true },
                            {
                                label: 'الفاتورة الأصلية',
                                value: invoice ? (
                                    <SmartLink
                                        routeName="admin.customerInvoices.show"
                                        href={route('admin.customerInvoices.show', invoice.id)}
                                        className="font-mono text-[#6d28d9] hover:underline"
                                    >
                                        {invoice.number}
                                    </SmartLink>
                                ) : (
                                    '—'
                                ),
                            },
                            { label: 'الجهة', value: invoice?.customer || '—' },
                            note.reason !== null && { label: 'السبب', value: note.reason, wide: true },
                        ]}
                    />

                    <Card className="p-4 sm:p-5">
                        <h2 className="mb-3 font-bold text-[#111]">{t('المبلغ')}</h2>

                        <dl className="ms-auto max-w-xs text-[13px]">
                            <div className="flex items-center justify-between gap-3 py-1">
                                <dt className="text-[#6b7280]">{t('المجموع الفرعي')}</dt>
                                <dd className="tabular-nums text-[#111]">{m(note.net)}</dd>
                            </div>
                            {note.tax_amount > 0 && (
                                <div className="flex items-center justify-between gap-3 py-1">
                                    <dt className="text-[#6b7280]">{t('ضريبة القيمة المضافة')}</dt>
                                    <dd className="tabular-nums text-[#111]">{m(note.tax_amount)}</dd>
                                </div>
                            )}
                            <div className="mt-2 flex items-center justify-between gap-3 border-t border-[var(--ui-border,#e8e8e8)] pt-3">
                                <dt className="font-bold text-[#111]">{t('إجمالي الإشعار')}</dt>
                                <dd className="font-bold tabular-nums text-[#111]">{m(note.amount)}</dd>
                            </div>
                        </dl>

                        {/*
                            ولا يُعاد كتابةُ الفاتورة: الإشعارُ يُنقص الذمّة
                            وتبقى الورقةُ التي في يد الجهة كما صدرت.
                        */}
                        <p className="mt-4 text-[12px] leading-relaxed text-[#9ca3af]">
                            {t('يُنقص هذا المبلغُ ما على الجهة — ولا يُعيد كتابة الفاتورة الأصلية.')}
                        </p>
                    </Card>
                </div>

                <DocumentAside className="xl:col-span-2">
                    <DocumentPanel
                        html={paper.html}
                        size={paper.size}
                        url={paper.url}
                        filename={`${note.number}.pdf`}
                        label={`${t('إشعار دائن')} ${note.number}`}
                        open={previewing}
                        onOpenChange={setPreviewing}
                    />
                </DocumentAside>
            </div>
        </AdminLayout>
    );
}
