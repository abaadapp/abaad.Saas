import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { Currency } from '@/types';

/* ------------------------------- الأنواع ------------------------------- */

export interface CartItem {
    /** مفتاح فريد للبند: 'p'+معرّف المنتج أو 'a'+معرّف الإضافة */
    key: string;
    id: number | null;
    /** معرّف الإضافة حين لا يكون البند منتجًا — الخادم يُسعّر به بدل الوثوق بالسعر المُرسَل */
    addon_id?: number | null;
    name: string;
    price: number;
    qty: number;
    note: string;
    image?: string | null;
    icon?: string | null;
    /** لقطة المخزون وقت الإضافة — تُستخدم للتحذير من التجاوز */
    stock?: number | null;
}

export interface PosCustomer {
    id: number;
    name: string;
    label: string;
    phone: string;
    points: number;
}

export interface PosProduct {
    id: number;
    label: string;
    price: number;
    image: string | null;
    sku: string;
    barcode: string;
    stock: number;
}

export interface AppliedCoupon {
    code: string;
    type: string;
    value: number;
}

export interface LoyaltySettings {
    redeemMaxPct: number;
    earnRate: number;
    redeemMin: number;
}

export interface ResumeCart {
    id: number | null;
    customer: string | null;
    items: Omit<CartItem, 'key' | 'note'>[];
}

interface OutboxEntry {
    uuid: string;
    payload: Record<string, unknown>;
    at: number;
}

export interface CheckoutResult {
    synced: boolean;
    invoice: string | null;
    points: number;
    /** رفضه الخادم (مخزون غير كافٍ أو صنف غير معروف) — لا يُعاد إلى الطابور */
    rejected?: boolean;
}

const OUTBOX_KEY = 'abadpos:pos:outbox';
const TAX_RATE = 5;
/** 100 نقطة = وحدة واحدة من العملة الأساسية */
const POINTS_PER_UNIT = 100;
const CASH_CUSTOMER = 'عميل نقدي';

function csrf(): string {
    return document.querySelector<HTMLMetaElement>('meta[name=csrf-token]')?.content ?? '';
}

function uuid(): string {
    try {
        if (crypto?.randomUUID) return crypto.randomUUID();
    } catch {
        /* بيئة غير آمنة — نلجأ للبديل */
    }
    return `cuid-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function readOutbox(): OutboxEntry[] {
    try {
        return JSON.parse(localStorage.getItem(OUTBOX_KEY) || '[]');
    } catch {
        return [];
    }
}

/* ------------------------------- الـhook ------------------------------- */

interface Options {
    products: PosProduct[];
    customers: PosCustomer[];
    coupons: unknown[];
    loyalty: LoyaltySettings;
    resume: ResumeCart | null;
    currency: Currency;
    onToast: (msg: string, type?: 'success' | 'warning' | 'danger' | 'info') => void;
}

/**
 * منطق سلة نقطة البيع — منقول عن Alpine.data('posCart') في app.js
 * بنفس المعادلات والسلوك: الخصم، الضريبة، سقف نقاط الولاء، وطابور
 * الانقطاع (outbox) الذي يكتب البيع محليًا أولًا ثم يرفعه.
 */
export function usePosCart({ products, customers: initialCustomers, loyalty, resume, currency, onToast }: Options) {
    const [items, setItems] = useState<CartItem[]>(() =>
        (resume?.items ?? []).map((i, idx) => ({
            ...i,
            key: (i as CartItem).key ?? `r${idx}`,
            note: (i as CartItem).note ?? '',
            qty: i.qty ?? 1,
        })),
    );
    const [resumeId] = useState<number | null>(resume?.id ?? null);
    const [customer, setCustomer] = useState<string>(
        resume?.customer || new URLSearchParams(location.search).get('customer') || CASH_CUSTOMER,
    );
    const [customers, setCustomers] = useState<PosCustomer[]>(initialCustomers);
    const [customerSearch, setCustomerSearch] = useState('');

    const [barcode, setBarcode] = useState('');

    const [couponCode, setCouponCode] = useState('');
    const [coupon, setCoupon] = useState<AppliedCoupon | null>(null);
    const [couponError, setCouponError] = useState('');
    const [couponLoading, setCouponLoading] = useState(false);

    const [redeemActive, setRedeemActive] = useState(false);

    const [online, setOnline] = useState(() => navigator.onLine);
    const [pending, setPending] = useState<OutboxEntry[]>(readOutbox);
    const flushing = useRef(false);

    const redeemMaxPct = Math.min(100, Math.max(0, Number(loyalty.redeemMaxPct) || 0));
    const earnRate = Math.max(0, Number(loyalty.earnRate) || 0);
    const redeemMin = Math.max(0, Number(loyalty.redeemMin) || 0);

    /* ----------------------------- الحسابات ----------------------------- */

    const count = useMemo(() => items.reduce((s, i) => s + i.qty, 0), [items]);
    const subtotal = useMemo(() => items.reduce((s, i) => s + i.price * i.qty, 0), [items]);

    const couponDiscount = useMemo(() => {
        if (!coupon) return 0;
        const d =
            coupon.type === 'نسبة' ? (subtotal * Number(coupon.value)) / 100 : Number(coupon.value);
        return Math.min(d, subtotal);
    }, [coupon, subtotal]);

    const selectedCustomer = useMemo(
        () => customers.find((c) => c.name === customer) ?? null,
        [customers, customer],
    );
    const selectedPoints = selectedCustomer?.points ?? 0;
    const canRedeem = selectedPoints > 0 && selectedPoints >= redeemMin;
    const pointsToThreshold = Math.max(0, redeemMin - selectedPoints);

    // سقف الاستبدال: نسبة من المجموع الفرعي، ولا يتجاوز المتبقّي بعد الكوبون
    const redeemCap = useMemo(() => {
        const byPct = (subtotal * redeemMaxPct) / 100;
        const afterCoupon = Math.max(0, subtotal - couponDiscount);
        return Math.min(byPct, afterCoupon);
    }, [subtotal, redeemMaxPct, couponDiscount]);

    const redeemDiscount = useMemo(() => {
        if (!redeemActive || !canRedeem) return 0;
        return Math.min(selectedPoints / POINTS_PER_UNIT, redeemCap);
    }, [redeemActive, canRedeem, selectedPoints, redeemCap]);

    const redeemPointsUsed = Math.round(redeemDiscount * POINTS_PER_UNIT);

    const discountAmount = Math.min(couponDiscount + redeemDiscount, subtotal);
    const taxAmount = ((subtotal - discountAmount) * TAX_RATE) / 100;
    const total = subtotal - discountAmount + taxAmount;
    const displayTotal = total * (currency.rate ?? 1);

    const pointsToEarn = useMemo(() => {
        if (earnRate <= 0 || !selectedCustomer) return 0;
        return Math.floor(total * earnRate);
    }, [earnRate, selectedCustomer, total]);

    const overStock = useCallback(
        (it: CartItem) => it != null && it.stock != null && it.qty > it.stock,
        [],
    );
    const hasStockWarning = useMemo(() => items.some(overStock), [items, overStock]);

    const filteredCustomers = useMemo(() => {
        const q = customerSearch.trim().toLowerCase();
        const list = q
            ? customers.filter(
                  (c) =>
                      (c.label || '').toLowerCase().includes(q) ||
                      (c.name || '').toLowerCase().includes(q) ||
                      (c.phone || '').toLowerCase().includes(q),
              )
            : customers;
        return list.slice(0, 50);
    }, [customers, customerSearch]);

    /* --------------------------- تعديل السلة --------------------------- */

    const warnStock = useCallback(
        (list: CartItem[], key: string): boolean => {
            const it = list.find((i) => i.key === key);
            if (!it || !overStock(it)) return false;
            onToast(
                (it.stock! <= 0 ? 'صنف نفد مخزونه: ' : `الكمية تتجاوز المتوفر (${it.stock}): `) + it.name,
                'warning',
            );
            return true;
        },
        [overStock, onToast],
    );

    const add = useCallback(
        (product: Omit<CartItem, 'qty' | 'note'>) => {
            const key = product.key ?? `p${product.id}`;
            let next: CartItem[] = [];
            setItems((prev) => {
                const existing = prev.find((i) => i.key === key);
                next = existing
                    ? prev.map((i) => (i.key === key ? { ...i, qty: i.qty + 1 } : i))
                    : [...prev, { ...product, key, qty: 1, note: '' }];
                return next;
            });
            // التحذير يُقيَّم على الحالة الجديدة لا القديمة
            queueMicrotask(() => {
                if (!warnStock(next, key)) onToast('تمت الإضافة إلى السلة', 'success');
            });
        },
        [warnStock, onToast],
    );

    const inc = useCallback(
        (key: string) => {
            let next: CartItem[] = [];
            setItems((prev) => {
                next = prev.map((i) => (i.key === key ? { ...i, qty: i.qty + 1 } : i));
                return next;
            });
            queueMicrotask(() => warnStock(next, key));
        },
        [warnStock],
    );

    const dec = useCallback((key: string) => {
        setItems((prev) => prev.map((i) => (i.key === key && i.qty > 1 ? { ...i, qty: i.qty - 1 } : i)));
    }, []);

    const remove = useCallback((key: string) => {
        setItems((prev) => prev.filter((i) => i.key !== key));
    }, []);

    const setNote = useCallback((key: string, note: string) => {
        setItems((prev) => prev.map((i) => (i.key === key ? { ...i, note } : i)));
    }, []);

    const removeCoupon = useCallback(() => {
        setCoupon(null);
        setCouponCode('');
        setCouponError('');
    }, []);

    const clear = useCallback(() => {
        setItems([]);
        removeCoupon();
        setRedeemActive(false);
    }, [removeCoupon]);

    /* ------------------------------ الباركود ------------------------------ */

    const scanBarcode = useCallback(
        (code: string) => {
            const c = (code || '').trim();
            if (!c) return;
            const p = products.find(
                (x) =>
                    (x.barcode && String(x.barcode) === c) ||
                    (x.sku && String(x.sku).toLowerCase() === c.toLowerCase()),
            );
            if (!p) {
                onToast(`لا يوجد منتج بهذا الباركود: ${c}`, 'warning');
                setBarcode('');
                return;
            }
            add({ key: `p${p.id}`, id: p.id, name: p.label, price: p.price, image: p.image, stock: p.stock });
            setBarcode('');
        },
        [products, add, onToast],
    );

    /* ------------------------------ الكوبون ------------------------------ */

    const applyCoupon = useCallback(
        async (codeArg?: string) => {
            const code = (codeArg ?? couponCode ?? '').trim();
            if (!code) return;
            setCouponLoading(true);
            setCouponError('');
            try {
                const res = await fetch('/pos/coupon', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf(),
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({ code, subtotal }),
                });
                const data = await res.json();
                if (!res.ok || !data.ok) {
                    setCoupon(null);
                    setCouponError(data.error || 'تعذّر تطبيق الكوبون');
                    return;
                }
                setCoupon({ code: data.code, type: data.type, value: data.value });
                setCouponCode(data.code);
                onToast(data.message || 'تم تطبيق الكوبون', 'success');
            } catch {
                setCouponError('تعذّر الاتصال. حاول مرة أخرى.');
            } finally {
                setCouponLoading(false);
            }
        },
        [couponCode, subtotal, onToast],
    );

    /* ------------------------------ العملاء ------------------------------ */

    const selectCustomer = useCallback((name: string) => {
        setCustomer(name);
        setCustomerSearch('');
    }, []);

    /** يضيف عميلًا ثم يحدّده للطلب الجاري بلا إعادة تحميل تُفقد السلة */
    const addCustomer = useCallback(
        async (form: HTMLFormElement): Promise<boolean> => {
            const fd = new FormData(form);
            const name = (fd.get('name') || '').toString().trim();
            if (!name) {
                onToast('أدخل اسم العميل', 'warning');
                return false;
            }
            try {
                const res = await fetch(form.action, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
                    body: fd,
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.ok) {
                    onToast(data.message || 'تعذّر إضافة العميل', 'danger');
                    return false;
                }
                const c = data.customer;
                setCustomers((prev) => [
                    { id: c.id, name: c.name, label: c.label || c.name, phone: c.phone || '', points: 0 },
                    ...prev,
                ]);
                setCustomer(c.name);
                setCustomerSearch('');
                form.reset();
                onToast('تم إضافة العميل وتحديده للطلب', 'success');
                return true;
            } catch {
                onToast('تعذّر الاتصال. حاول مرة أخرى.', 'danger');
                return false;
            }
        },
        [onToast],
    );

    /* ------------------ صمود الانقطاع: طابور محلي (outbox) ------------------ */

    const savePending = useCallback((list: OutboxEntry[]) => {
        localStorage.setItem(OUTBOX_KEY, JSON.stringify(list));
        setPending(list);
    }, []);

    const sendOne = useCallback(
        async (payload: Record<string, unknown>): Promise<{ ok: boolean; drop?: boolean; invoice?: string; points?: number; error?: string }> => {
            try {
                const res = await fetch('/pos/checkout', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf(),
                        Accept: 'application/json',
                    },
                    body: JSON.stringify(payload),
                });
                if (res.ok) {
                    const d = await res.json();
                    return { ok: true, invoice: d.invoice, points: d.points_earned || 0 };
                }
                // 419 جلسة منتهية و422 بيانات مرفوضة — إعادة المحاولة بلا فائدة.
                // نستخرج السبب: الخادم يرفض هنا نقص المخزون وصنفًا غير معروف،
                // وإسقاط البيع صامتًا يترك الكاشير بلا تفسير.
                if (res.status === 422 || res.status === 419) {
                    let error = '';
                    try {
                        const d = await res.json();
                        error = Object.values(d?.errors ?? {}).flat().join(' · ') || d?.message || '';
                    } catch {
                        /* رد بلا JSON — نكتفي برسالة عامة */
                    }
                    return { ok: false, drop: true, error: error || 'تعذّر إتمام البيع' };
                }
                return { ok: false };
            } catch {
                return { ok: false }; // خطأ شبكة → يبقى في الطابور
            }
        },
        [],
    );

    const flushOutbox = useCallback(async () => {
        if (flushing.current || !navigator.onLine) return;
        const queue = readOutbox();
        if (!queue.length) return;

        flushing.current = true;
        try {
            let remaining = [...queue];
            for (const entry of queue) {
                const res = await sendOne(entry.payload);
                if (res.ok || res.drop) {
                    remaining = remaining.filter((x) => x.uuid !== entry.uuid);
                    savePending(remaining);
                    if (res.ok) {
                        onToast('تمت مزامنة طلب مُعلّق ✓', 'success');
                    } else if (res.error) {
                        // رُفض بعد عودة الاتصال (نفد المخزون مثلًا) — يجب أن يُعلَم لا أن يختفي
                        onToast('طلب مُعلّق رُفض: ' + res.error, 'danger');
                    }
                } else {
                    break; // الشبكة ما زالت منقطعة — نعيد لاحقًا
                }
            }
        } finally {
            flushing.current = false;
        }
    }, [sendOne, savePending, onToast]);

    useEffect(() => {
        const goOnline = () => {
            setOnline(true);
            void flushOutbox();
        };
        const goOffline = () => setOnline(false);
        window.addEventListener('online', goOnline);
        window.addEventListener('offline', goOffline);
        // نبض: يلتقط عودة الاتصال حتى لو لم يُطلَق حدث online
        const beat = setInterval(() => {
            setOnline(navigator.onLine);
            void flushOutbox();
        }, 8000);
        void flushOutbox();

        return () => {
            window.removeEventListener('online', goOnline);
            window.removeEventListener('offline', goOffline);
            clearInterval(beat);
        };
    }, [flushOutbox]);

    /** يحفظ البيع محليًا أولًا ثم يحاول رفعه — لا ينتظر الشبكة لإتمامه */
    const checkoutSale = useCallback(
        async (method: string): Promise<CheckoutResult> => {
            const id = uuid();
            const payload = {
                client_uuid: id,
                items: items.map((i) => ({
                    id: i.id ?? null,
                    addon_id: i.addon_id ?? null,
                    name: i.name,
                    price: i.price,
                    qty: i.qty,
                    note: i.note ?? '',
                })),
                customer,
                payment_method: method,
                // الخصم والضريبة والإجمالي تُحتسب خادميًا من أسعار القاعدة؛
                // ما يلي معروض للمستخدم فقط ولا يُقيَّد كما هو
                delivery_fee: 0,
                resume_id: resumeId,
                coupon_code: coupon?.code ?? null,
                redeem_points: redeemPointsUsed,
            };

            const queue = [...readOutbox(), { uuid: id, payload, at: Date.now() }];
            savePending(queue);

            const res = await sendOne(payload);
            if (res.ok || res.drop) {
                savePending(queue.filter((p) => p.uuid !== id));
            }
            if (res.drop && res.error) {
                onToast(res.error, 'danger');
            }
            setOnline(navigator.onLine);

            return { synced: !!res.ok, invoice: res.invoice ?? null, points: res.points ?? 0, rejected: !!res.drop };
        },
        [items, customer, resumeId, coupon, redeemPointsUsed, savePending, sendOne, onToast],
    );

    /** تعليق الطلب أو حفظه — نفس نقطة النهاية باختلاف kind */
    const holdOrder = useCallback(
        async (kind: 'hold' | 'save') => {
            if (!items.length) return;
            await fetch('/pos/hold', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    items: items.map((i) => ({
                        id: i.id ?? null,
                        addon_id: i.addon_id ?? null,
                        name: i.name,
                        qty: i.qty,
                        note: i.note ?? '',
                    })),
                    customer,
                    kind,
                }),
            });
            clear();
            onToast(kind === 'hold' ? 'تم تعليق الطلب' : 'تم حفظ الطلب', kind === 'hold' ? 'warning' : 'success');
        },
        [items, customer, total, clear, onToast],
    );

    return {
        // الحالة
        items, customer, customers, customerSearch, barcode,
        couponCode, coupon, couponError, couponLoading,
        redeemActive, online, pendingCount: pending.length,
        // المحسوبات
        count, subtotal, couponDiscount, discountAmount, taxAmount, total, displayTotal,
        selectedCustomer, selectedPoints, canRedeem, pointsToThreshold,
        redeemCap, redeemDiscount, redeemPointsUsed, redeemMaxPct, redeemMin,
        pointsToEarn, hasStockWarning, filteredCustomers,
        // الأفعال
        add, inc, dec, remove, setNote, clear,
        setBarcode, scanBarcode,
        setCouponCode, applyCoupon, removeCoupon,
        setRedeemActive, selectCustomer, setCustomerSearch, addCustomer,
        checkoutSale, holdOrder, overStock,
    };
}
