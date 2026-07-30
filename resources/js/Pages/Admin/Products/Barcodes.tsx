import { useEffect, useMemo, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import JsBarcode from 'jsbarcode';
import { ArrowRight, PackageX, Printer } from 'lucide-react';
import { usePage } from '@inertiajs/react';
import { Select } from '@/Components/Field';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Product } from '@/types/models';

/**
 * صفحة ملصقات الباركود — تقف خارج قشرة الإدارة عمدًا:
 * الطباعة يجب أن تُخرج الملصقات وحدها بلا شريط جانبي ولا ترويسة.
 */
export default function Barcodes() {
    const { products, context } = usePage<PageProps<{ products: Product[] }>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const [copies, setCopies] = useState(1);
    const grid = useRef<HTMLDivElement>(null);

    const active = useMemo(() => products.filter((p) => p.active), [products]);

    const labels = useMemo(
        () =>
            active.flatMap((p) =>
                Array.from({ length: copies }, (_, i) => ({
                    key: `${p.id}-${i}`,
                    name: p.label ?? p.name,
                    price: p.price,
                    // منتج بلا باركود يحصل على رمز مشتقّ من معرّفه حتى لا يخرج ملصق فارغ
                    code: p.barcode || `SKU${String(p.id).padStart(6, '0')}`,
                })),
            ),
        [active, copies],
    );

    // الرسم بعد كل تغيير في العدد أو القائمة
    useEffect(() => {
        grid.current?.querySelectorAll<SVGElement>('svg.barcode').forEach((svg) => {
            const code = svg.dataset.code;
            if (!code) return;
            try {
                JsBarcode(svg, code, { format: 'CODE128', displayValue: true, fontSize: 12, height: 40, margin: 0 });
            } catch {
                /* رمز لا يقبله المعيار — يُترك الملصق بلا رسم بدل تعطيل الصفحة */
            }
        });
    }, [labels]);

    return (
        <div className="admin-ui min-h-screen bg-[#f2f2f0]">
            <Head title={t('طباعة الباركود')} />

            <style>{`@media print { .no-print { display: none !important } body { background: #fff !important } .label { break-inside: avoid } }`}</style>

            <div className="no-print sticky top-0 z-10 flex flex-wrap items-center justify-between gap-3 border-b border-[var(--ui-border,#e8e8e8)] bg-white px-4 py-3 lg:px-8">
                <div className="flex items-center gap-2">
                    <a
                        href={route('admin.products.index')}
                        aria-label={t('رجوع')}
                        className="flex size-9 items-center justify-center rounded-[10px] text-[#4b4b4b] hover:bg-[#f2f2f0]"
                    >
                        <ArrowRight className="size-5" />
                    </a>
                    <h1 className="font-bold text-[#111]">
                        {t('ملصقات الباركود')}{' '}
                        <span className="font-normal text-[#9ca3af]">
                            ({active.length} {t('منتج')})
                        </span>
                    </h1>
                </div>

                <div className="flex items-center gap-3">
                    <label className="flex items-center gap-2 text-sm text-[#4b4b4b]">
                        {t('عدد النسخ لكل منتج:')}
                        <Select
                            value={String(copies)}
                            onChange={(e) => setCopies(Number(e.target.value))}
                            options={[1, 2, 3, 4].map((n) => ({ label: String(n), value: n }))}
                            className="w-24"
                        />
                    </label>
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="inline-flex items-center gap-2 rounded-full bg-[#111] px-4 py-2 text-sm font-medium text-white hover:bg-[#2a2a2a]"
                    >
                        <Printer className="size-4" />
                        {t('طباعة')}
                    </button>
                </div>
            </div>

            {active.length === 0 ? (
                <div className="mx-auto mt-20 max-w-md text-center text-[#9ca3af]">
                    <PackageX className="mx-auto mb-3 size-12" />
                    <p>{t('لا توجد منتجات مفعّلة لطباعة الباركود.')}</p>
                </div>
            ) : (
                <div className="p-4 lg:p-8">
                    <div ref={grid} className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                        {labels.map((l) => (
                            <div
                                key={l.key}
                                className="label flex flex-col items-center justify-between rounded-[8px] border border-[var(--ui-border,#e8e8e8)] bg-white p-3 text-center"
                            >
                                <p className="w-full truncate text-[12px] font-bold text-[#111]">{l.name}</p>
                                <p className="my-1 text-sm font-extrabold text-[#6d28d9]">{money(l.price, currency)}</p>
                                <svg className="barcode w-full" data-code={l.code} />
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
