import { useMemo, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import {
    AlertTriangle,
    Award,
    BadgeCheck,
    ChevronDown,
    CreditCard,
    Lightbulb,
    Minus,
    PauseCircle,
    Plus,
    PlusCircle,
    Save,
    ScanBarcode,
    ScanLine,
    Search,
    ShoppingCart,
    TicketPercent,
    Trash2,
    TrendingUp,
    User,
    UserPlus,
    X,
} from 'lucide-react';
import { toast } from 'sonner';
import PosLayout from '@/Layouts/PosLayout';
import NewCustomerDialog from '@/Pages/Pos/partials/NewCustomerDialog';
import PaymentDialog from '@/Pages/Pos/partials/PaymentDialog';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { decimalsFor, money as fmtMoney } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { usePosCart, type LoyaltySettings, type PosCustomer, type ResumeCart } from '@/hooks/usePosCart';
import type { PageProps } from '@/types';
import type { Addon, Coupon, Product } from '@/types/models';

interface Props {
    products: Product[];
    categories: { value: string; label: string }[];
    customers: (PosCustomer & { avatar?: string | null })[];
    addons: Addon[];
    coupons: Coupon[];
    resumeCart: ResumeCart | null;
    settings: LoyaltySettings & { loyaltyEnabled?: boolean };
}

export default function PosIndex() {
    const { products, categories, customers, addons, coupons, resumeCart, settings, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;

    const [cat, setCat] = useState('الكل');
    const [q, setQ] = useState('');
    const [payOpen, setPayOpen] = useState(false);
    const [newCustomerOpen, setNewCustomerOpen] = useState(false);
    const [customerMenuOpen, setCustomerMenuOpen] = useState(false);
    const barcodeRef = useRef<HTMLInputElement>(null);

    const cart = usePosCart({
        products: products.map((p) => ({
            id: p.id, label: p.label, price: p.price, image: p.image,
            sku: p.sku, barcode: p.barcode, stock: p.qty,
        })),
        customers,
        coupons,
        loyalty: settings,
        resume: resumeCart,
        currency,
        onToast: (msg, type) => {
            const fn = type === 'success' ? toast.success : type === 'danger' ? toast.error : type === 'warning' ? toast.warning : toast;
            fn(msg);
        },
    });

    /** القيمة أصلًا بالعملة الأساسية → تُحوَّل للعرض */
    const money = (v: number) => fmtMoney(v, currency);
    /** القيمة أصلًا بعملة العرض → تُنسَّق بلا تحويل */
    const fmt = (v: number) =>
        `${v.toLocaleString('en-US', {
            minimumFractionDigits: decimalsFor(currency.code),
            maximumFractionDigits: decimalsFor(currency.code),
        })} ${currency.symbol || currency.code}`;

    const activeAddons = useMemo(() => addons.filter((a) => a.active), [addons]);

    const visibleProducts = useMemo(() => {
        const needle = q.trim();
        return products.filter((p) => {
            const inCat = cat === 'الكل' || cat === p.cat;
            const inSearch = !needle || `${p.name} ${p.label}`.includes(needle);
            return inCat && inSearch;
        });
    }, [products, cat, q]);

    const loyaltyOn = settings.loyaltyEnabled !== false;

    return (
        <PosLayout title={t('نقطة البيع')} fill>
            {/* مؤشّر صمود الانقطاع */}
            <AnimatePresence>
                {(!cart.online || cart.pendingCount > 0) && (
                    <motion.div
                        initial={{ opacity: 0, y: -12 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0, y: -12 }}
                        className={cn(
                            'fixed start-1/2 top-3 z-50 flex -translate-x-1/2 items-center gap-2 rounded-full px-4 py-2 text-sm font-bold shadow-lg rtl:translate-x-1/2',
                            cart.online
                                ? 'border border-[#fde68a] bg-[#fffbeb] text-[#b45309]'
                                : 'bg-[#dc2626] text-white',
                        )}
                    >
                        {!cart.online
                            ? t('لا اتصال — البيع مستمر ويُحفَظ محليًا')
                            : `${t('جارٍ مزامنة الطلبات المعلّقة')} (${cart.pendingCount})`}
                    </motion.div>
                )}
            </AnimatePresence>

            <div className="flex h-full flex-col gap-4 overflow-hidden p-4 lg:flex-row">
                {/* ===================== المنتجات ===================== */}
                <section className="flex min-h-0 flex-1 flex-col lg:w-2/3">
                    {/* البحث والباركود */}
                    <div className="mb-4 flex shrink-0 flex-col gap-3 sm:flex-row">
                        <div className="relative flex-1">
                            <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                            <Input
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder={t('ابحث عن منتج بالاسم أو الرمز...')}
                                className="ps-9"
                            />
                        </div>
                        <div className="flex gap-2">
                            <div className="relative w-44">
                                <ScanBarcode className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                                <Input
                                    ref={barcodeRef}
                                    value={cart.barcode}
                                    onChange={(e) => cart.setBarcode(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            e.preventDefault();
                                            cart.scanBarcode(cart.barcode);
                                        }
                                    }}
                                    placeholder={t('امسح الباركود')}
                                    className="ps-9"
                                />
                            </div>
                            {/* تركيز الحقل ليبدأ الماسح الإدخال — الماسح يعمل كلوحة مفاتيح ثم Enter */}
                            <Button
                                className="shrink-0"
                                onClick={() => {
                                    barcodeRef.current?.focus();
                                    toast.info(t('جاهز للمسح — وجّه الماسح نحو الباركود'));
                                }}
                            >
                                <ScanLine />
                                {t('مسح')}
                            </Button>
                        </div>
                    </div>

                    {/* تبويبات الأقسام */}
                    <div className="mb-4 flex shrink-0 items-center gap-2 overflow-x-auto pb-1">
                        {categories.map((c) => (
                            <button
                                key={c.value}
                                type="button"
                                onClick={() => setCat(c.value)}
                                className={cn(
                                    'whitespace-nowrap rounded-full px-4 py-2 text-sm font-medium transition-colors',
                                    cat === c.value
                                        ? 'bg-gray-900 text-white shadow-sm'
                                        : 'border border-gray-100 bg-white text-gray-600 hover:bg-gray-50',
                                )}
                            >
                                {c.label}
                            </button>
                        ))}
                    </div>

                    {/* الإضافات — تُضاف كبنود بلا مخزون */}
                    {activeAddons.length > 0 && (
                        <div className="mb-4 flex shrink-0 items-center gap-2 overflow-x-auto pb-1">
                            <span className="inline-flex shrink-0 items-center gap-1 whitespace-nowrap text-xs font-bold text-[#7c3aed]">
                                <PlusCircle className="size-4" /> {t('الإضافات')}:
                            </span>
                            {activeAddons.map((a) => {
                                const emoji = /[^\x00-\x7F]/.test(a.icon) ? a.icon : '🎁';
                                return (
                                    <button
                                        key={a.id}
                                        type="button"
                                        onClick={() =>
                                            cart.add({ key: `a${a.id}`, id: null, addon_id: a.id, name: a.label, price: a.price, icon: emoji, image: null })
                                        }
                                        className="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:border-[#c4b5fd] hover:bg-[#f5f3ff]"
                                    >
                                        <span className="text-base leading-none">{emoji}</span>
                                        <span>{a.label}</span>
                                        <span className="text-xs font-bold text-[#7c3aed]">{money(a.price)}</span>
                                    </button>
                                );
                            })}
                        </div>
                    )}

                    {/* شبكة المنتجات */}
                    <div className="-mx-1 flex-1 overflow-y-auto px-1">
                        {visibleProducts.length === 0 ? (
                            <p className="py-16 text-center text-sm text-gray-400">
                                {q.trim() || cat !== 'الكل' ? t('لا نتائج مطابقة للبحث أو التصفية') : t('لا توجد منتجات بعد')}
                            </p>
                        ) : (
                            <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
                                {visibleProducts.map((p) => (
                                    <button
                                        key={p.id}
                                        type="button"
                                        onClick={() =>
                                            cart.add({ key: `p${p.id}`, id: p.id, name: p.label, price: p.price, image: p.image, stock: p.qty })
                                        }
                                        className="group select-none overflow-hidden rounded-2xl border border-gray-100 bg-white text-start shadow-sm transition-all hover:border-gray-300 hover:shadow-md"
                                    >
                                        <div className="relative aspect-square overflow-hidden bg-gray-50">
                                            {p.image ? (
                                                <img
                                                    src={p.image}
                                                    alt={p.label}
                                                    loading="lazy"
                                                    className="size-full object-cover transition-transform duration-300 group-hover:scale-105"
                                                />
                                            ) : (
                                                <span className="flex size-full items-center justify-center text-3xl">📦</span>
                                            )}
                                            <span className="absolute end-2 top-2">
                                                <Badge status={p.stock_status} />
                                            </span>
                                        </div>
                                        <div className="p-3">
                                            <h3 className="truncate text-sm font-semibold text-gray-800">{p.label}</h3>
                                            <p className="mt-0.5 text-xs text-gray-400">
                                                {t('المتوفر:')} {p.qty}
                                            </p>
                                            <div className="mt-2 flex items-center justify-between gap-1">
                                                <p className="text-sm font-bold text-gray-900">{money(p.price)}</p>
                                                <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-900 transition-colors group-hover:bg-gray-900 group-hover:text-white">
                                                    <Plus className="size-4" />
                                                </span>
                                            </div>
                                        </div>
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                </section>

                {/* ===================== السلة ===================== */}
                <aside className="flex min-h-0 w-full flex-col rounded-2xl border border-gray-100 bg-white shadow-sm lg:w-1/3">
                    {/* الرأس + العميل */}
                    <div className="shrink-0 border-b border-gray-100 px-4 py-3">
                        <div className="flex items-center justify-between">
                            <h2 className="font-bold text-gray-800">{t('طلب')}</h2>
                            <span className="text-xs text-gray-400">
                                {cart.count} {t('عنصر')}
                            </span>
                        </div>

                        <div className="mt-3 flex items-center gap-2">
                            <div className="relative flex-1">
                                <button
                                    type="button"
                                    onClick={() => setCustomerMenuOpen((v) => !v)}
                                    className="flex w-full items-center gap-2 rounded-full bg-gray-50 px-3 py-2 text-sm text-gray-700 transition-colors hover:bg-gray-100"
                                >
                                    <User className="size-4 text-gray-900" />
                                    <span className="truncate">{cart.customer}</span>
                                    <ChevronDown className="ms-auto size-4 text-gray-400" />
                                </button>

                                {customerMenuOpen && (
                                    <>
                                        <div className="fixed inset-0 z-10" onClick={() => setCustomerMenuOpen(false)} />
                                        <div className="absolute z-20 mt-1 w-72 overflow-hidden rounded-[12px] border border-gray-200 bg-white shadow-lg">
                                            <div className="relative p-2">
                                                <Search className="pointer-events-none absolute start-4 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                                                <Input
                                                    value={cart.customerSearch}
                                                    onChange={(e) => cart.setCustomerSearch(e.target.value)}
                                                    placeholder={t('ابحث بالاسم أو رقم الهاتف...')}
                                                    className="ps-9"
                                                    autoComplete="off"
                                                />
                                            </div>
                                            <div className="max-h-60 overflow-y-auto">
                                                <button
                                                    type="button"
                                                    onClick={() => { cart.selectCustomer('عميل نقدي'); setCustomerMenuOpen(false); }}
                                                    className="block w-full px-4 py-2 text-start text-sm hover:bg-gray-50"
                                                >
                                                    {t('عميل نقدي')}
                                                </button>
                                                {cart.filteredCustomers.map((c) => (
                                                    <button
                                                        key={c.id}
                                                        type="button"
                                                        onClick={() => { cart.selectCustomer(c.name); setCustomerMenuOpen(false); }}
                                                        className="flex w-full items-center justify-between gap-2 px-4 py-2 text-start text-sm hover:bg-gray-50"
                                                    >
                                                        <span className="truncate">{c.label}</span>
                                                        {c.phone && (
                                                            <span dir="ltr" className="shrink-0 font-mono text-xs text-gray-400">
                                                                {c.phone}
                                                            </span>
                                                        )}
                                                    </button>
                                                ))}
                                                {cart.customerSearch.trim() && cart.filteredCustomers.length === 0 && (
                                                    <p className="px-4 py-3 text-center text-xs text-gray-400">{t('لا نتائج')}</p>
                                                )}
                                            </div>
                                            <div className="border-t border-gray-100">
                                                <button
                                                    type="button"
                                                    onClick={() => { setCustomerMenuOpen(false); setNewCustomerOpen(true); }}
                                                    className="flex w-full items-center gap-2 px-4 py-2 text-start text-sm text-[#7c3aed] hover:bg-[#f5f3ff]"
                                                >
                                                    <UserPlus className="size-4" /> {t('عميل جديد')}
                                                </button>
                                            </div>
                                        </div>
                                    </>
                                )}
                            </div>

                            <button
                                type="button"
                                onClick={() => setNewCustomerOpen(true)}
                                title={t('عميل جديد')}
                                className="flex size-10 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-700 transition-colors hover:bg-gray-200"
                            >
                                <UserPlus className="size-5" />
                            </button>
                        </div>
                    </div>

                    {/* البنود */}
                    <div className="min-h-0 flex-1 overflow-y-auto px-3 py-3">
                        {cart.items.length === 0 ? (
                            <div className="flex h-full flex-col items-center justify-center py-10 text-center">
                                <div className="mb-3 flex size-16 items-center justify-center rounded-2xl bg-gray-100 text-gray-400">
                                    <ShoppingCart className="size-8" />
                                </div>
                                <p className="font-semibold text-gray-600">{t('السلة فارغة')}</p>
                                <p className="mt-1 text-sm text-gray-400">{t('اختر منتجات لإضافتها إلى الطلب')}</p>
                            </div>
                        ) : (
                            <div className="space-y-2">
                                <AnimatePresence initial={false}>
                                    {cart.items.map((item) => (
                                        <motion.div
                                            key={item.key}
                                            initial={{ opacity: 0, height: 0 }}
                                            animate={{ opacity: 1, height: 'auto' }}
                                            exit={{ opacity: 0, height: 0 }}
                                            transition={{ duration: 0.18 }}
                                            className="rounded-xl bg-gray-50 p-2.5"
                                        >
                                            <div className="flex items-center gap-2.5">
                                                {item.image ? (
                                                    <img src={item.image} alt={item.name} className="size-12 shrink-0 rounded-lg object-cover" />
                                                ) : (
                                                    <span className="flex size-12 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-2xl">
                                                        {item.icon || '🎁'}
                                                    </span>
                                                )}
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm font-semibold text-gray-800">{item.name}</p>
                                                    <p className="text-xs font-medium text-gray-900">{money(item.price)}</p>
                                                    {cart.overStock(item) && (
                                                        <p className="mt-0.5 text-[11px] font-bold text-[#dc2626]">
                                                            {item.stock! <= 0
                                                                ? `⚠︎ ${t('نفد المخزون')}`
                                                                : `⚠︎ ${t('يتجاوز المتوفر')} (${item.stock})`}
                                                        </p>
                                                    )}
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={() => cart.remove(item.key)}
                                                    className="flex size-7 shrink-0 items-center justify-center rounded-full text-[#ef4444] transition-colors hover:bg-[#fef2f2]"
                                                >
                                                    <Trash2 className="size-4" />
                                                </button>
                                            </div>

                                            <div className="mt-2 flex items-center justify-between gap-2">
                                                <div className="flex items-center gap-1 rounded-lg border border-gray-200 bg-white">
                                                    <button type="button" onClick={() => cart.dec(item.key)} className="flex size-7 items-center justify-center text-gray-500 hover:text-gray-900">
                                                        <Minus className="size-4" />
                                                    </button>
                                                    <span className="w-7 text-center text-sm font-bold text-gray-800">{item.qty}</span>
                                                    <button type="button" onClick={() => cart.inc(item.key)} className="flex size-7 items-center justify-center text-gray-500 hover:text-gray-900">
                                                        <Plus className="size-4" />
                                                    </button>
                                                </div>
                                                <p className="text-sm font-bold text-gray-800">{money(item.price * item.qty)}</p>
                                            </div>

                                            <Input
                                                value={item.note}
                                                onChange={(e) => cart.setNote(item.key, e.target.value)}
                                                placeholder={t('ملاحظة...')}
                                                className="mt-2 h-8 text-xs"
                                            />
                                        </motion.div>
                                    ))}
                                </AnimatePresence>
                            </div>
                        )}
                    </div>

                    {/* الملخص */}
                    <div className="shrink-0 space-y-2.5 border-t border-gray-100 p-3">
                        <div className="flex items-center justify-between text-sm text-gray-600">
                            <span>{t('المجموع الفرعي')}</span>
                            <span className="font-medium text-gray-800">{money(cart.subtotal)}</span>
                        </div>

                        {/* الكوبون */}
                        <div>
                            {!cart.coupon ? (
                                <>
                                    <div className="flex items-center gap-2">
                                        <div className="relative flex-1">
                                            <TicketPercent className="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                                            <Input
                                                value={cart.couponCode}
                                                onChange={(e) => cart.setCouponCode(e.target.value)}
                                                onKeyDown={(e) => {
                                                    if (e.key === 'Enter') { e.preventDefault(); void cart.applyCoupon(); }
                                                }}
                                                placeholder={t('كود الخصم')}
                                                autoComplete="off"
                                                className="h-9 ps-8 uppercase"
                                            />
                                        </div>
                                        <Button
                                            size="sm"
                                            disabled={!cart.couponCode.trim() || cart.couponLoading}
                                            onClick={() => void cart.applyCoupon()}
                                        >
                                            {cart.couponLoading ? '…' : t('تطبيق')}
                                        </Button>
                                    </div>

                                    {coupons.length > 0 && (
                                        <div className="mt-2 flex flex-wrap items-center gap-1.5">
                                            <span className="text-[11px] text-gray-400">{t('المتاح')}:</span>
                                            {coupons.map((c) => (
                                                <button
                                                    key={c.id}
                                                    type="button"
                                                    onClick={() => { cart.setCouponCode(c.code); void cart.applyCoupon(c.code); }}
                                                    title={c.min_order > 0 ? `${t('الحد الأدنى للطلب')} ${money(c.min_order)}` : t('بلا حد أدنى')}
                                                    className="inline-flex items-center gap-1 rounded-full border border-dashed border-[#c4b5fd] bg-[#f5f3ff]/50 px-2.5 py-1 text-[11px] font-medium text-[#6d28d9] transition hover:bg-[#ede9fe]"
                                                >
                                                    <TicketPercent className="size-3" />
                                                    <span className="font-mono uppercase">{c.code}</span>
                                                    <span className="text-[#8b5cf6]">{c.display}</span>
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                </>
                            ) : (
                                <div className="flex items-center justify-between rounded-lg border border-[#059669]/20 bg-[#ecfdf5] px-3 py-2">
                                    <span className="flex items-center gap-1.5 text-sm font-medium text-[#047857]">
                                        <BadgeCheck className="size-4" />
                                        <span>{cart.coupon.code}</span>
                                        <span className="text-xs text-[#059669]">(- {money(cart.couponDiscount)})</span>
                                    </span>
                                    <button type="button" onClick={cart.removeCoupon} className="text-gray-400 hover:text-[#ef4444]">
                                        <X className="size-4" />
                                    </button>
                                </div>
                            )}
                            {cart.couponError && <p className="mt-1 text-xs text-[#ef4444]">{cart.couponError}</p>}
                        </div>

                        {/* نقاط الولاء */}
                        {loyaltyOn && cart.selectedPoints > 0 && (
                            <div className="rounded-xl border border-[#fbcfe8] bg-[#fdf2f8]/60 p-2.5">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="flex min-w-0 items-center gap-1.5 text-xs font-medium text-[#be185d]">
                                        <Award className="size-4 shrink-0" />
                                        <span className="truncate">{t('نقاط العميل:')} {cart.selectedPoints}</span>
                                        <span className="shrink-0 text-[#ec4899]">({money(cart.selectedPoints / 100)})</span>
                                    </span>
                                    {cart.canRedeem && (
                                        <button
                                            type="button"
                                            onClick={() => cart.setRedeemActive(!cart.redeemActive)}
                                            className={cn(
                                                'shrink-0 rounded-full px-3 py-1 text-[11px] font-semibold transition',
                                                cart.redeemActive
                                                    ? 'bg-[#db2777] text-white'
                                                    : 'border border-[#f9a8d4] bg-white text-[#be185d]',
                                            )}
                                        >
                                            {cart.redeemActive ? t('إلغاء') : t('استخدم النقاط')}
                                        </button>
                                    )}
                                </div>

                                {cart.canRedeem ? (
                                    <p className="mt-1.5 flex items-center gap-1 text-[11px] text-[#db2777]">
                                        <Lightbulb className="size-3.5 shrink-0" />
                                        {cart.redeemActive
                                            ? t('سيُخصم تلقائيًا عند الدفع — اضغط «إلغاء» للتراجع')
                                            : t('اضغط «استخدم النقاط» لخصمها من الفاتورة')}
                                    </p>
                                ) : (
                                    <p className="mt-1.5 flex items-center gap-1 text-[11px] text-[#db2777]">
                                        <TrendingUp className="size-3.5 shrink-0" />
                                        {t('تتراكم النقاط — يبدأ الاستبدال من')} {cart.redeemMin} {t('نقطة (باقٍ')} {cart.pointsToThreshold})
                                    </p>
                                )}

                                {cart.redeemActive && cart.redeemPointsUsed > 0 && (
                                    <p className="mt-1 text-[11px] font-semibold text-[#be185d]">
                                        − {money(cart.redeemDiscount)} ({cart.redeemPointsUsed} {t('نقطة')})
                                    </p>
                                )}
                                {cart.redeemActive && cart.selectedPoints / 100 > cart.redeemCap && (
                                    <p className="mt-1 text-[10px] text-[#ec4899]">
                                        {t('الحد الأقصى لهذه الفاتورة')} {cart.redeemMaxPct}% ({money(cart.redeemCap)})
                                    </p>
                                )}
                            </div>
                        )}

                        {cart.redeemDiscount > 0 && (
                            <div className="flex items-center justify-between text-sm text-[#be185d]">
                                <span>{t('خصم نقاط الولاء')}</span>
                                <span className="font-medium">- {money(cart.redeemDiscount)}</span>
                            </div>
                        )}

                        <div className="flex items-center justify-between text-sm text-gray-600">
                            <span>{t('الضريبة (5%)')}</span>
                            <span className="font-medium text-gray-800">{money(cart.taxAmount)}</span>
                        </div>

                        <div className="flex items-center justify-between border-t border-dashed border-gray-200 pt-2">
                            <span className="font-bold text-gray-800">{t('الإجمالي')}</span>
                            <span className="text-xl font-extrabold text-gray-900">{money(cart.total)}</span>
                        </div>

                        {loyaltyOn && cart.pointsToEarn > 0 && (
                            <p className="flex items-center justify-center gap-1.5 rounded-xl bg-[#fdf2f8] px-3 py-1.5 text-xs font-semibold text-[#be185d]">
                                <Award className="size-4 shrink-0" />
                                {t('سيكسب العميل')} {cart.pointsToEarn} {t('نقطة من هذا الشراء')}
                            </p>
                        )}

                        {cart.hasStockWarning && (
                            <p className="flex items-center gap-2 rounded-xl bg-[#fef2f2] px-3 py-2 text-xs font-bold text-[#b91c1c]">
                                <AlertTriangle className="size-4 shrink-0" />
                                {t('بعض الأصناف تتجاوز المخزون المتوفر — تأكّد قبل الدفع')}
                            </p>
                        )}

                        {/* الإجراءات */}
                        <div className="grid grid-cols-3 gap-2 pt-1">
                            <button
                                type="button"
                                disabled={cart.items.length === 0}
                                onClick={() => void cart.holdOrder('hold')}
                                className="flex flex-col items-center gap-1 rounded-full bg-[#fffbeb] py-2 text-xs font-medium text-[#d97706] transition-colors hover:bg-[#fef3c7] disabled:opacity-40"
                            >
                                <PauseCircle className="size-5" /> {t('تعليق')}
                            </button>
                            <button
                                type="button"
                                disabled={cart.items.length === 0}
                                onClick={cart.clear}
                                className="flex flex-col items-center gap-1 rounded-full bg-[#fef2f2] py-2 text-xs font-medium text-[#dc2626] transition-colors hover:bg-[#fee2e2] disabled:opacity-40"
                            >
                                <Trash2 className="size-5" /> {t('إلغاء')}
                            </button>
                            <button
                                type="button"
                                disabled={cart.items.length === 0}
                                onClick={() => void cart.holdOrder('save')}
                                className="flex flex-col items-center gap-1 rounded-full bg-gray-100 py-2 text-xs font-medium text-gray-600 transition-colors hover:bg-gray-200 disabled:opacity-40"
                            >
                                <Save className="size-5" /> {t('حفظ')}
                            </button>
                        </div>

                        <button
                            type="button"
                            onClick={() =>
                                cart.items.length ? setPayOpen(true) : toast.error(t('السلة فارغة'))
                            }
                            className="flex w-full items-center justify-center gap-2 rounded-full bg-gray-900 py-3.5 text-base font-bold text-white shadow-sm transition-colors hover:bg-gray-800"
                        >
                            <CreditCard className="size-5" />
                            {t('الدفع')}
                            <span>{money(cart.total)}</span>
                        </button>
                    </div>
                </aside>
            </div>

            <PaymentDialog
                open={payOpen}
                onOpenChange={setPayOpen}
                total={cart.total}
                displayTotal={cart.displayTotal}
                customer={cart.customer}
                money={money}
                fmt={fmt}
                onCheckout={cart.checkoutSale}
                onNewOrder={() => { cart.clear(); toast.success(t('طلب جديد جاهز')); }}
            />

            <NewCustomerDialog
                open={newCustomerOpen}
                onOpenChange={setNewCustomerOpen}
                onSubmit={cart.addCustomer}
            />
        </PosLayout>
    );
}
