import { useState, type ReactNode } from 'react';
import { Link2, Plus, X } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { csrfHeaders } from '@/lib/csrf';
import { useTranslate } from '@/lib/i18n';
import { CHANNEL_LABEL, addonChannels, addonScope, appliesTo, type AddonOption } from './addons';

interface Props {
    addons: AddonOption[];
    /** المنتجُ المحفوظ — أو `null` في شاشة الإنشاء: لا معرّفَ يُربط به بعد */
    productId: number | null;
    /** قائمةُ المنتج القديمة — انظر `appliesTo` */
    legacy: number[] | null;
    currencyLabel: string;
    /** فتحُ النافذة على إضافةٍ تُعدَّل، أو `null` لجديدةٍ مخصّصةٍ لهذا المنتج */
    onOpen: (addon: AddonOption | null) => void;
    onChanged: (addon: AddonOption) => void;
    /** ما يُرسم تحت القسم — طريقةُ عرض الإضافات في الصندوق */
    children?: ReactNode;
}

/**
 * إضافاتُ هذا المنتج — ما يُعرض معه، في مجموعتين.
 *
 * كان الحقلُ «إضافات مع كلّ المنتجات» يعرض إضافاتِ المتجر كلَّها، والمخصّصةُ
 * لغير هذا المنتج بينها — فيقرأ التاجرُ «شوكولاتة · منتجات محددة» ولا يعرف
 * أتُعرض مع هذه الباقة أم لا. فصار القسمُ يقول ما ينطبق **هنا** بقاعدة
 * البيع نفسِها (`appliesTo` ↔ `ProductAddons::passes`):
 *
 *   - **العامة**: مع كلّ المنتجات تلقائيًّا — تُدار من صفحة الإضافات.
 *   - **المخصصة**: رُبطت بهذا المنتج، أو صُنعت له وحده. تُربط هنا وتُفكّ
 *     دون أن تُحذف: تبقى لمنتجاتها الأخرى.
 *
 * وكلُّ إضافةٍ مرّةً واحدة: مجموعتان من قائمةٍ واحدة لا تتداخلان. ولكلّ
 * واحدةٍ سعرُها ومصدرُها وقناتُها وحالتُها — بلا تكلفةٍ ولا مستحقّ: ذاك في
 * صفحة الإضافات ونافذتها.
 */
export default function ProductAddonsPanel({ addons, productId, legacy, currencyLabel, onOpen, onChanged, children }: Props) {
    const t = useTranslate();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [pick, setPick] = useState('');

    const here = addons.filter((a) => appliesTo(a, productId, legacy));
    const general = here.filter((a) => addonScope(a) === 'all');
    const specific = here.filter((a) => addonScope(a) !== 'all');
    /* ما يُربط من المكتبة: المخصّصةُ التي لم تُربط بهذا المنتج بعد */
    const linkable = productId === null ? [] : addons.filter((a) => addonScope(a) === 'selected' && !appliesTo(a, productId, legacy));

    const call = async (method: 'PUT' | 'DELETE', addon: number) => {
        if (productId === null) return;
        setBusy(true);
        setError(null);
        try {
            const res = await fetch(
                route(method === 'PUT' ? 'admin.products.addons.link' : 'admin.products.addons.unlink', [productId, addon]),
                { method, headers: { Accept: 'application/json', ...csrfHeaders() } },
            );
            const payload = await res.json();
            if (!res.ok) {
                setError(payload?.message ?? t('تعذّر حفظ الإضافة'));

                return;
            }
            onChanged(payload.addon);
            setPick('');
        } catch {
            setError(t('تعذّر الاتصال بالخادم'));
        } finally {
            setBusy(false);
        }
    };

    const chip = (a: AddonOption, unlink: boolean) => {
        const channels = addonChannels(a);

        return (
            <span key={a.value} className="inline-flex items-center rounded-[8px] bg-[#f3f3f1] text-[12px] text-[#4b4b4b]" data-testid="product-addon">
                {/* الشريحةُ نفسُها بابُ التعديل — وزرُّ الفكّ بجانبها لا داخلها */}
                <button
                    type="button"
                    disabled={a.value < 0}
                    onClick={() => onOpen(a)}
                    className="rounded-[8px] px-2 py-1 text-start enabled:hover:bg-[#e8e8e6]"
                >
                    {a.label}
                    <span className="ms-1 tabular-nums opacity-60">
                        {a.price} {currencyLabel}
                    </span>
                    {a.source === 'boutique' && (
                        <span className="ms-1 opacity-60">· {t('بوتيك: :name', { name: a.boutique_name ?? '' })}</span>
                    )}
                    {a.source === 'stock' && <span className="ms-1 opacity-60">· {t('تخصم')} {a.inventory_quantity ?? 1}</span>}
                    <span className="ms-1 opacity-60">· {t(CHANNEL_LABEL[channels])}</span>
                    {addonScope(a) === 'product' && <span className="ms-1 opacity-60">· {t('خاصة بهذا المنتج')}</span>}
                    {/* والموقوفةُ تُقال: وإلّا بدت كالمعروضة */}
                    {!a.active && <span className="ms-1 font-medium text-[#b45309]">· {t('موقوفة')}</span>}
                </button>
                {unlink && (
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => void call('DELETE', a.value)}
                        aria-label={t('فك ربط «:name» بهذا المنتج', { name: a.label })}
                        title={t('فك الربط — تبقى الإضافة لمنتجاتها الأخرى')}
                        className="me-1 rounded p-0.5 opacity-60 hover:bg-[#e8e8e6] hover:opacity-100"
                        data-testid="product-addon-unlink"
                    >
                        <X className="size-3" />
                    </button>
                )}
            </span>
        );
    };

    const group = (title: string, hint: string, list: AddonOption[], testId: string, empty: string) => (
        <div data-testid={testId}>
            <p className="text-[13px] font-medium text-[#111]">{t(title)}</p>
            <p className="mb-1.5 text-[12px] text-[#9ca3af]">{t(hint)}</p>
            <div className="flex min-h-[42px] flex-wrap items-center gap-1.5 rounded-[10px] border border-[#e8e8e8] px-2 py-1.5">
                {list.length === 0
                    ? <span className="px-1 text-[13px] text-[#9ca3af]">{t(empty)}</span>
                    : list.map((a) => chip(a, addonScope(a) === 'selected'))}
            </div>
        </div>
    );

    return (
        <div className="space-y-4" data-testid="product-addons">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="font-bold text-[#111]">{t('إضافات هذا المنتج')}</h3>
                <SmartLink routeName="admin.addons.index" href={route('admin.addons.index')} className="text-[12px] text-[#4b4b4b] underline-offset-2 hover:underline">
                    {t('كل الإضافات')}
                </SmartLink>
            </div>

            {group('الإضافات العامة', 'تظهر مع كل المنتجات تلقائيًا — يُغيَّر نطاقها من صفحة الإضافات.', general, 'product-addons-general', 'لا إضافات عامة')}
            {group('الإضافات المخصصة', 'مرتبطة بهذا المنتج تحديدًا — فكّ الربط لا يحذف الإضافة.', specific, 'product-addons-specific', 'لا إضافات مخصصة')}

            <div className="flex flex-wrap items-center gap-2">
                {productId !== null && linkable.length > 0 && (
                    <span className="flex min-w-[14rem] flex-1 items-center gap-2" data-testid="product-addons-link">
                        <span className="flex-1">
                            <Select
                                aria-label={t('ربط إضافة موجودة')}
                                value={pick}
                                onChange={(e) => setPick(e.target.value)}
                                options={linkable.map((a) => ({ label: `${a.label} — ${a.price} ${currencyLabel}`, value: a.value }))}
                                placeholder={t('ربط إضافة موجودة…')}
                            />
                        </span>
                        <Button type="button" variant="outline" size="sm" disabled={!pick || busy} onClick={() => void call('PUT', Number(pick))}>
                            <Link2 className="size-4" />
                            {t('ربط')}
                        </Button>
                    </span>
                )}
                <Button type="button" variant="outline" size="sm" onClick={() => onOpen(null)}>
                    <Plus className="size-4" />
                    {t('إضافة جديدة')}
                </Button>
            </div>
            {productId === null && (
                <p className="text-[12px] text-[#9ca3af]">{t('احفظ المنتج أولًا لتربط به إضافات مخصصة.')}</p>
            )}
            {error && <p className="text-[12px] text-[#b91c1c]" role="alert">{error}</p>}
            {children}
        </div>
    );
}
