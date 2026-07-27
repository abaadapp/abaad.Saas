import Alpine from 'alpinejs';
import ApexCharts from 'apexcharts';
import JsBarcode from 'jsbarcode';
import Sortable from 'sortablejs';
import { createIcons, icons } from 'lucide';

window.Alpine = Alpine;
window.ApexCharts = ApexCharts;

/* عملة العرض (تُحقن من الخادم عبر <meta name="app-currency">) */
window.CURRENCY = (() => {
    try {
        return JSON.parse(document.querySelector('meta[name=app-currency]')?.content) || null;
    } catch (e) {
        return null;
    }
})() || { rate: 1, symbol: 'ر.ع', decimals: 3 };

/* رسم أيقونات Lucide الموجودة في الصفحة.
   نفصل المراقب أثناء الرسم حتى لا يُطلق تحديثُنا نفسَه حلقةً لا نهائية،
   وحتى لا تُستبدل أيقونة يجري النقر عليها فتضيع الضغطة. */
let _iconTimer = null;
let _iconObserver = null;
window.renderIcons = () => {
    _iconObserver?.disconnect();
    createIcons({ icons });
    if (_iconObserver && document.body) {
        _iconObserver.observe(document.body, { childList: true, subtree: true });
    }
};
document.addEventListener('DOMContentLoaded', () => window.renderIcons());

/* رسم الباركود على كل عنصر <svg class="barcode" data-code="..."> */
window.renderBarcodes = () => {
    document.querySelectorAll('svg.barcode[data-code]').forEach((el) => {
        try {
            JsBarcode(el, el.dataset.code, {
                format: 'CODE128', width: 2, height: 48, fontSize: 12, margin: 6, displayValue: true,
            });
        } catch (e) {
            /* رمز غير صالح — تجاهل */
        }
    });
};
document.addEventListener('DOMContentLoaded', () => window.renderBarcodes());

/* ====== قبول كل الفواصل في حقول المبالغ ======
   يحوّل حقول المبالغ إلى نص يقبل الفاصلة (,) والنقطة (.) والأرقام العربية،
   ثم يوحّدها إلى نقطة عشرية لحظيًا في طور الالتقاط (قبل أن تقرأها Alpine). */
const MONEY_NAMES = new Set([
    'price', 'cost', 'amount', 'discount', 'tax', 'total', 'subtotal',
    'delivery_fee', 'deliveryfee', 'opening_balance', 'monthly', 'yearly',
    'monthly_price', 'yearly_price', 'free_threshold', 'fee', 'discountpercent',
    'paid', 'unit_price', 'salary', 'monthly_target', 'value', 'balance', 'min_order',
]);
const AR_DIGITS = { '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9', '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9' };
window.normalizeMoney = (v) => {
    v = String(v).replace(/[٠-٩۰-۹]/g, (d) => AR_DIGITS[d] ?? d); // أرقام عربية → إنجليزية
    v = v.replace(/٫/g, '.').replace(/[،٬\s]/g, ''); // فاصلة عشرية عربية + حذف فواصل الآلاف والمسافات
    v = v.replace(/,/g, '.'); // الفاصلة → نقطة عشرية
    v = v.replace(/[^0-9.]/g, ''); // إبقاء الأرقام والنقطة فقط
    const i = v.indexOf('.');
    if (i !== -1) v = v.slice(0, i + 1) + v.slice(i + 1).replace(/\./g, ''); // نقطة عشرية واحدة
    return v;
};
const isMoneyInput = (el) => {
    if (!el || el.tagName !== 'INPUT') return false;
    if (el.hasAttribute('data-money')) return true;
    const name = el.getAttribute('name');
    if (!name) return false;
    const key = name.toLowerCase().replace(/\]$/, '').split(/[.[]/).pop();
    return MONEY_NAMES.has(key);
};
window.upgradeMoneyInputs = (root = document) => {
    (root.querySelectorAll ? root.querySelectorAll('input') : []).forEach((el) => {
        if (el._moneyUpgraded || !isMoneyInput(el)) return;
        el._moneyUpgraded = true;
        if (el.type === 'number') el.type = 'text';
        el.setAttribute('inputmode', 'decimal');
        // طور الالتقاط: نوحّد القيمة قبل أن تقرأها مستمعات Alpine في طور الفقاعة
        el.addEventListener('input', () => {
            const s = window.normalizeMoney(el.value);
            if (s !== el.value) el.value = s;
        }, true);
    });
};
document.addEventListener('DOMContentLoaded', () => window.upgradeMoneyInputs());
/* إعادة الرسم بعد أي تحديث من Alpine (عناصر ديناميكية مثل السلة) */
document.addEventListener('alpine:initialized', () => window.renderIcons());
_iconObserver = new MutationObserver(() => {
    clearTimeout(_iconTimer);
    _iconTimer = setTimeout(() => { window.renderIcons(); window.upgradeMoneyInputs(); }, 120);
});
document.addEventListener('DOMContentLoaded', () => {
    _iconObserver.observe(document.body, { childList: true, subtree: true });
});

/**
 * تحديث بطاقات الإحصائيات لحظيًا (Polling) دون إعادة تحميل الصفحة.
 * الاستخدام: <div ... x-init="liveStats($el, '<url>')">
 * تُطابق كل بطاقة عبر [data-stat-value="<label>"] وتحدّث قيمتها فقط عند تغيّرها.
 */
window.liveStats = (root, url, intervalMs = 15000) => {
    const badge = document.querySelector('[data-stat-updated]');
    const tick = async () => {
        try {
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const { stats, updated_at } = await res.json();
            (stats || []).forEach((s) => {
                const el = root.querySelector(`[data-stat-value="${CSS.escape(s.label)}"]`);
                if (el && el.textContent.trim() !== String(s.value).trim()) {
                    el.textContent = s.value;
                    el.classList.add('text-primary-600');
                    setTimeout(() => el.classList.remove('text-primary-600'), 1200);
                }
            });
            if (badge && updated_at) badge.textContent = 'آخر تحديث: ' + updated_at;
        } catch (e) {
            /* تجاهل أخطاء الشبكة العابرة */
        }
    };
    const id = setInterval(tick, intervalMs);
    document.addEventListener('turbo:before-visit', () => clearInterval(id), { once: true });
    return id;
};

/**
 * تحديث حيّ لكميات المنتجات في شاشة نقطة البيع (Polling) — يعكس الجرد/الحركات فورًا دون إعادة تحميل.
 * الاستخدام: <div ... x-init="startPosStock('<url>')">
 * يحدّث [data-pos-qty="<id>"] (المتوفر) و[data-pos-status="<id>"] (شارة الحالة ولونها).
 */
const POS_STATUS_CLASSES = {
    'متوفر': ['bg-success-50', 'text-success-700'],
    'منخفض': ['bg-warning-50', 'text-warning-600'],
    'نفد المخزون': ['bg-danger-50', 'text-danger-600'],
};
const POS_STATUS_ALL = ['bg-success-50', 'text-success-700', 'bg-warning-50', 'text-warning-600', 'bg-danger-50', 'text-danger-600'];
window.startPosStock = (url, intervalMs = 10000) => {
    const tick = async () => {
        try {
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const { products } = await res.json();
            (products || []).forEach((p) => {
                const q = document.querySelector(`[data-pos-qty="${p.id}"]`);
                if (q && q.textContent.trim() !== String(p.qty)) {
                    q.textContent = p.qty;
                    q.classList.add('text-primary-600', 'font-bold');
                    setTimeout(() => q.classList.remove('text-primary-600', 'font-bold'), 1500);
                }
                const s = document.querySelector(`[data-pos-status="${p.id}"]`);
                if (s && s.textContent.trim() !== p.status) {
                    s.textContent = p.status;
                    s.classList.remove(...POS_STATUS_ALL);
                    s.classList.add(...(POS_STATUS_CLASSES[p.status] || []));
                }
            });
        } catch (e) {
            /* تجاهل أخطاء الشبكة العابرة */
        }
    };
    const id = setInterval(tick, intervalMs);
    document.addEventListener('turbo:before-visit', () => clearInterval(id), { once: true });
    return id;
};

/**
 * إشعارات المتصفح للطلبات الجديدة — يستطلع نقطة feed ويُظهر إشعارًا فوريًا عند ورود طلب جديد.
 * يبدأ تلقائيًا من التخطيط عبر startOrderAlerts(url). زر التفعيل يطلب الإذن.
 */
window.enableBrowserNotifications = async () => {
    if (!('Notification' in window)) {
        Alpine.store('toasts').add('متصفحك لا يدعم الإشعارات', 'warning');
        return;
    }
    const perm = await Notification.requestPermission();
    Alpine.store('toasts').add(
        perm === 'granted' ? 'تم تفعيل إشعارات المتصفح' : 'تم رفض إذن الإشعارات',
        perm === 'granted' ? 'success' : 'warning',
    );
};

window.startOrderAlerts = (url, intervalMs = 20000) => {
    let lastId = null;
    const badge = document.querySelector('[data-notif-count]');
    const tick = async () => {
        try {
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();
            if (badge && typeof data.count === 'number') {
                badge.textContent = data.count > 9 ? '9+' : data.count;
                badge.style.display = data.count ? '' : 'none';
            }
            const o = data.latest_order;
            if (o) {
                if (lastId !== null && o.id > lastId) {
                    const body = `${o.customer} — ${Number(o.total).toFixed(3)} ر.ع`;
                    Alpine.store('toasts').add(`طلب جديد ${o.number}: ${body}`, 'success', 6000);
                    if ('Notification' in window && Notification.permission === 'granted') {
                        const n = new Notification(`طلب جديد ${o.number}`, { body, tag: 'abadpos-order-' + o.id });
                        n.onclick = () => { window.focus(); window.location.href = o.url; };
                    }
                }
                lastId = o.id;
            }
        } catch (e) {
            /* تجاهل أخطاء الشبكة العابرة */
        }
    };
    tick();
    return setInterval(tick, intervalMs);
};

/**
 * مخزن التنبيهات (Toasts) — يُستخدم عبر النظام كله.
 * الاستدعاء: $store.toasts.add('تم الحفظ', 'success')
 */
document.addEventListener('alpine:init', () => {
    Alpine.store('toasts', {
        items: [],
        add(message, type = 'success', timeout = 3500) {
            const id = Date.now() + Math.random();
            this.items.push({ id, message, type });
            setTimeout(() => this.remove(id), timeout);
        },
        remove(id) {
            this.items = this.items.filter((t) => t.id !== id);
        },
    });

    /* مخزن الشريط الجانبي — الفتح والإغلاق على الجوال */
    Alpine.store('sidebar', {
        open: false,
        collapsed: false,
        toggle() {
            this.open = !this.open;
        },
        toggleCollapse() {
            this.collapsed = !this.collapsed;
        },
    });

    /* تصفية قوائم/جداول لحظية على جهة العميل.
       الاستخدام: x-data="listFilter()" على الحاوية، x-ref="list" على حاوية الصفوف،
       وكل صف يحمل data-row مع data-search (نص البحث) و data-tag (قيمة الفلتر). */
    Alpine.data('listFilter', () => ({
        q: '',
        tag: '',
        apply() {
            const box = this.$refs.list;
            if (!box) return;
            const q = this.q.trim().toLowerCase();
            const tag = this.tag;
            let shown = 0;
            box.querySelectorAll('[data-row]').forEach((row) => {
                const text = (row.getAttribute('data-search') || row.textContent || '').toLowerCase();
                const rowTag = row.getAttribute('data-tag') || '';
                const ok = (!q || text.includes(q)) && (!tag || rowTag === tag);
                row.style.display = ok ? '' : 'none';
                if (ok) shown++;
            });
            if (this.$refs.empty) this.$refs.empty.style.display = shown ? 'none' : '';
        },
    }));

    /* البحث الموحّد في الشريط العلوي */
    Alpine.data('unifiedSearch', (url) => ({
        q: '',
        results: [],
        loading: false,
        open: false,
        async run() {
            const term = this.q.trim();
            if (term.length < 2) {
                this.results = [];
                this.open = false;
                return;
            }
            this.loading = true;
            this.open = true;
            try {
                const res = await fetch(`${url}?q=${encodeURIComponent(term)}`, { headers: { Accept: 'application/json' } });
                const data = await res.json();
                this.results = data.groups || [];
            } catch (e) {
                this.results = [];
            } finally {
                this.loading = false;
                this.$nextTick(() => window.renderIcons());
            }
        },
    }));

    /* لوحة قابلة للتخصيص: إعادة ترتيب البطاقات بالسحب + إظهار/إخفاء (تُحفظ محليًا) */
    Alpine.data('dashboardGrid', (storageKey) => ({
        editing: false,
        hidden: [],
        sortable: null,
        key(kind) { return `abadpos:dash:${storageKey}:${kind}`; },
        labelOf(card) { return card.querySelector('[data-stat-value]')?.getAttribute('data-stat-value') || ''; },
        init() {
            const grid = this.$refs.grid;
            this.hidden = JSON.parse(localStorage.getItem(this.key('hidden')) || '[]');
            this.applyOrder(JSON.parse(localStorage.getItem(this.key('order')) || '[]'));
            this.injectButtons();
            this.applyHidden();
            this.sortable = Sortable.create(grid, {
                disabled: true, animation: 150, draggable: '[data-card]',
                onEnd: () => this.saveOrder(),
            });
        },
        applyOrder(order) {
            if (!order.length) return;
            const grid = this.$refs.grid;
            const map = {};
            Array.from(grid.children).forEach((c) => (map[this.labelOf(c)] = c));
            order.forEach((l) => { if (map[l]) grid.appendChild(map[l]); });
        },
        applyHidden() {
            Array.from(this.$refs.grid.children).forEach((c) => {
                c.style.display = this.hidden.includes(this.labelOf(c)) ? 'none' : '';
            });
        },
        saveOrder() {
            const order = Array.from(this.$refs.grid.children).map((c) => this.labelOf(c));
            localStorage.setItem(this.key('order'), JSON.stringify(order));
        },
        injectButtons() {
            Array.from(this.$refs.grid.children).forEach((card) => {
                card.setAttribute('data-card', this.labelOf(card));
                card.classList.add('relative');
                if (card.querySelector('.dash-hide')) return;
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'dash-hide absolute top-2 left-2 w-6 h-6 rounded-lg bg-danger-50 text-danger-600 items-center justify-center hover:bg-danger-100';
                b.innerHTML = '<i data-lucide="eye-off" class="w-3.5 h-3.5"></i>';
                b.addEventListener('click', () => this.hide(this.labelOf(card)));
                card.appendChild(b);
            });
            window.renderIcons();
        },
        hide(label) {
            if (!this.hidden.includes(label)) this.hidden.push(label);
            localStorage.setItem(this.key('hidden'), JSON.stringify(this.hidden));
            this.applyHidden();
        },
        show(label) {
            this.hidden = this.hidden.filter((l) => l !== label);
            localStorage.setItem(this.key('hidden'), JSON.stringify(this.hidden));
            this.applyHidden();
        },
        toggleEdit() {
            this.editing = !this.editing;
            if (this.sortable) this.sortable.option('disabled', !this.editing);
            this.$refs.grid.classList.toggle('dash-editing', this.editing);
        },
    }));

    /* مكوّن الرسوم البيانية عبر ApexCharts */
    Alpine.data('apexChart', (options) => ({
        chart: null,
        init() {
            this.chart = new ApexCharts(this.$el, options);
            this.chart.render();
        },
    }));

    /* سلة نقطة البيع POS */
    Alpine.data('posCart', (resume = null, initialCustomers = [], initialProducts = [], redeemMaxPct = 50, earnRate = 5, redeemMin = 100) => ({
        // سلة مستعادة من طلب معلّق/محفوظ (إن وُجدت) — نضمن مفتاح سلة فريدًا لكل بند
        items: (resume?.items ?? []).map((i, idx) => ({ ...i, key: i.key ?? ('r' + idx) })),
        resumeId: resume?.id ?? null,
        customer:
            resume?.customer ||
            new URLSearchParams(location.search).get('customer') ||
            'عميل نقدي',
        taxRate: 5,
        // ====== الباركود: مسح المنتج بالكود وإضافته للسلة ======
        barcode: '',
        products: initialProducts ?? [],
        // يُستدعى عند الضغط Enter في حقل الباركود (الماسح يُدخل الكود ثم Enter)
        scanBarcode(code) {
            code = (code || '').toString().trim();
            if (!code) return;
            const p = this.products.find((x) =>
                (x.barcode && String(x.barcode) === code) ||
                (x.sku && String(x.sku).toLowerCase() === code.toLowerCase()));
            if (!p) {
                Alpine.store('toasts').add('لا يوجد منتج بهذا الباركود: ' + code, 'warning', 2500);
                this.barcode = '';
                return;
            }
            this.add({ key: 'p' + p.id, id: p.id, name: p.label, price: p.price, image: p.image, stock: p.stock });
            this.barcode = '';
        },
        // ====== العملاء: بحث + إضافة سريعة مع تحديد تلقائي ======
        customers: (initialCustomers ?? []).map((c) => ({
            id: c.id, name: c.name, label: c.label || c.name, phone: c.phone || '', points: c.points || 0,
        })),
        customerSearch: '',
        // ====== استبدال نقاط الولاء كخصم (100 نقطة = 1 ر.ع) ======
        // سقف منطقي: لا يُغطّي الاستبدال أكثر من نسبة محدّدة من قيمة الشراء (يبقى العميل يدفع نقدًا)
        redeemActive: false,
        redeemMaxPct: Math.min(100, Math.max(0, Number(redeemMaxPct) || 0)),
        earnRate: Math.max(0, Number(earnRate) || 0),
        // الحد الأدنى لبدء الاستبدال: تحته تتراكم النقاط فقط ولا يُتاح الخصم
        redeemMin: Math.max(0, Number(redeemMin) || 0),
        // هل بلغ العميل الحد الأدنى المسموح به لبدء الاستبدال؟
        get canRedeem() {
            return this.selectedPoints > 0 && this.selectedPoints >= this.redeemMin;
        },
        // كم نقطة باقية على العميل ليبلغ حد الاستبدال (للعرض التحفيزي)
        get pointsToThreshold() {
            return Math.max(0, this.redeemMin - this.selectedPoints);
        },
        // النقاط المتوقّع اكتسابها من هذا الشراء (على الإجمالي بعد الخصم) — للعملاء المسجّلين فقط
        get pointsToEarn() {
            if (this.earnRate <= 0 || !this.selectedCustomer) return 0;
            return Math.floor(this.total * this.earnRate);
        },
        get selectedCustomer() {
            return this.customers.find((c) => c.name === this.customer) || null;
        },
        get selectedPoints() {
            return this.selectedCustomer ? (this.selectedCustomer.points || 0) : 0;
        },
        // أقصى قيمة قابلة للاستبدال في هذه الفاتورة (نسبة من المجموع الفرعي، ولا تتجاوز المتبقّي بعد الكوبون)
        get redeemCap() {
            const byPct = (this.subtotal * this.redeemMaxPct) / 100;
            const afterCoupon = Math.max(0, this.subtotal - this.couponDiscount);
            return Math.min(byPct, afterCoupon);
        },
        get redeemDiscount() {
            if (!this.redeemActive || !this.canRedeem) return 0;
            return Math.min(this.selectedPoints / 100, this.redeemCap);
        },
        get redeemPointsUsed() {
            return Math.round(this.redeemDiscount * 100);
        },
        get filteredCustomers() {
            const q = this.customerSearch.trim().toLowerCase();
            const list = q
                ? this.customers.filter((c) =>
                    (c.label || '').toLowerCase().includes(q) ||
                    (c.name || '').toLowerCase().includes(q) ||
                    (c.phone || '').toLowerCase().includes(q))
                : this.customers;
            return list.slice(0, 50);
        },
        selectCustomer(name) {
            this.customer = name;
            this.customerSearch = '';
        },
        // إضافة عميل عبر AJAX ثم تحديده تلقائيًا للطلب الجاري (دون إعادة تحميل تُفقد السلة)
        async addCustomer(form) {
            const fd = new FormData(form);
            const name = (fd.get('name') || '').toString().trim();
            if (!name) {
                Alpine.store('toasts').add('أدخل اسم العميل', 'warning', 2000);
                return;
            }
            try {
                const res = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        Accept: 'application/json',
                    },
                    body: fd,
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.ok) {
                    Alpine.store('toasts').add(data.message || 'تعذّر إضافة العميل', 'danger', 2500);
                    return;
                }
                const c = data.customer;
                this.customers.unshift({ id: c.id, name: c.name, label: c.label || c.name, phone: c.phone || '', points: 0 });
                this.customer = c.name; // تحديد تلقائي للطلب الحالي
                this.customerSearch = '';
                form.reset();
                this.$dispatch('close-modal');
                Alpine.store('toasts').add('تم إضافة العميل وتحديده للطلب', 'success', 2200);
            } catch (e) {
                Alpine.store('toasts').add('تعذّر الاتصال. حاول مرة أخرى.', 'danger', 2500);
            }
        },
        // ====== صمود الانقطاع (Outbox) ======
        // البيع يُكتب محليًا أولًا ثم يُرفع للخادم؛ عند انقطاع الشبكة تبقى الطلبات
        // في طابور localStorage وتُرفع تلقائيًا عند عودة الاتصال (بلا تكرار عبر client_uuid).
        online: navigator.onLine,
        pending: [],
        _flushing: false,
        outboxKey: 'abadpos:pos:outbox',
        // Alpine يستدعيها تلقائيًا عند تهيئة المكوّن
        init() {
            try {
                this.pending = JSON.parse(localStorage.getItem(this.outboxKey) || '[]');
            } catch (e) {
                this.pending = [];
            }
            window.addEventListener('online', () => { this.online = true; this.flushOutbox(); });
            window.addEventListener('offline', () => { this.online = false; });
            // نبض مزامنة: يلتقط عودة الاتصال حتى لو لم يُطلَق حدث online، ويعيد المحاولة دوريًا
            setInterval(() => { this.online = navigator.onLine; this.flushOutbox(); }, 8000);
            if (this.pending.length) this.flushOutbox();
        },
        get pendingCount() {
            return this.pending.length;
        },
        _savePending() {
            localStorage.setItem(this.outboxKey, JSON.stringify(this.pending));
        },
        _uuid() {
            try {
                if (crypto?.randomUUID) return crypto.randomUUID();
            } catch (e) { /* بيئة غير آمنة — نلجأ للبديل */ }
            return 'cuid-' + Date.now() + '-' + Math.random().toString(16).slice(2);
        },
        // يرفع طلبًا واحدًا للخادم. النتيجة: {ok, invoice} أو {ok:false} (نبقيه) أو {ok:false, drop:true} (نتجاهله)
        async _sendOne(payload) {
            try {
                const res = await fetch('/pos/checkout', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        Accept: 'application/json',
                    },
                    body: JSON.stringify(payload),
                });
                if (res.ok) {
                    const d = await res.json();
                    return { ok: true, invoice: d.invoice, points: d.points_earned || 0 };
                }
                // 419 = انتهاء الجلسة/رمز CSRF، 422 = بيانات مرفوضة → لا فائدة من إعادة المحاولة
                if (res.status === 422 || res.status === 419) return { ok: false, drop: true };
                return { ok: false };
            } catch (e) {
                return { ok: false }; // خطأ شبكة → نُبقيه في الطابور
            }
        },
        // يُستدعى من نافذة الدفع: يحفظ البيع محليًا ثم يحاول رفعه فورًا
        async checkoutSale(method) {
            const uuid = this._uuid();
            const payload = {
                client_uuid: uuid,
                items: this.items.map((i) => ({
                    id: i.id ?? null, name: i.name, price: i.price, qty: i.qty, note: i.note ?? '',
                })),
                customer: this.customer,
                payment_method: method,
                discount: this.discountAmount,
                tax: this.taxAmount,
                delivery_fee: 0,
                total: this.total,
                resume_id: this.resumeId,
                coupon_code: this.coupon?.code ?? null,
                redeem_points: this.redeemPointsUsed,
            };
            // اكتب محليًا أولًا — لا تنتظر الشبكة لإتمام البيع
            this.pending.push({ uuid, payload, at: Date.now() });
            this._savePending();

            const res = await this._sendOne(payload);
            if (res.ok || res.drop) {
                this.pending = this.pending.filter((p) => p.uuid !== uuid);
                this._savePending();
            }
            this.online = navigator.onLine;
            return { synced: res.ok, invoice: res.invoice ?? null, points: res.points ?? 0 };
        },
        // يفرّغ الطابور تباعًا؛ يتوقف عند أول فشل شبكة ليعيد لاحقًا (يمنع التوازي المتكرر)
        async flushOutbox() {
            if (this._flushing || !navigator.onLine || !this.pending.length) return;
            this._flushing = true;
            try {
                for (const p of [...this.pending]) {
                    const res = await this._sendOne(p.payload);
                    if (res.ok || res.drop) {
                        this.pending = this.pending.filter((x) => x.uuid !== p.uuid);
                        this._savePending();
                        if (res.ok) Alpine.store('toasts').add('تمت مزامنة طلب مُعلّق ✓', 'success', 2000);
                    } else {
                        break; // ما زالت الشبكة منقطعة — نوقف ونعيد لاحقًا
                    }
                }
            } finally {
                this._flushing = false;
            }
        },
        // الكوبون
        couponCode: '',
        coupon: null, // { code, type, value } عند التطبيق
        couponError: '',
        couponLoading: false,
        async applyCoupon() {
            const code = (this.couponCode || '').trim();
            if (!code) return;
            this.couponLoading = true;
            this.couponError = '';
            try {
                const res = await fetch('/pos/coupon', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({ code, subtotal: this.subtotal }),
                });
                const data = await res.json();
                if (!res.ok || !data.ok) {
                    this.coupon = null;
                    this.couponError = data.error || 'تعذّر تطبيق الكوبون';
                    return;
                }
                this.coupon = { code: data.code, type: data.type, value: data.value };
                this.couponCode = data.code;
                Alpine.store('toasts').add(data.message || 'تم تطبيق الكوبون', 'success', 2000);
            } catch (e) {
                this.couponError = 'تعذّر الاتصال. حاول مرة أخرى.';
            } finally {
                this.couponLoading = false;
            }
        },
        removeCoupon() {
            this.coupon = null;
            this.couponCode = '';
            this.couponError = '';
        },
        get couponDiscount() {
            if (!this.coupon) return 0;
            const d = this.coupon.type === 'نسبة'
                ? (this.subtotal * Number(this.coupon.value)) / 100
                : Number(this.coupon.value);
            return Math.min(d, this.subtotal);
        },
        add(product) {
            // مفتاح السلة: 'p'+معرّف المنتج، أو المفتاح المُمرَّر (مثل 'a'+معرّف الإضافة)
            const key = product.key ?? ('p' + product.id);
            const existing = this.items.find((i) => i.key === key);
            if (existing) {
                existing.qty++;
            } else {
                this.items.push({ ...product, key, qty: 1, note: '' });
            }
            if (!this._warnStock(key)) {
                Alpine.store('toasts').add('تمت الإضافة إلى السلة', 'success', 1500);
            }
        },
        inc(key) {
            const it = this.items.find((i) => i.key === key);
            if (it) { it.qty++; this._warnStock(key); }
        },
        // تجاوز المتوفر؟ (المخزون لقطة عند الإضافة؛ مهم في وضع الانقطاع حيث لا يتحدّث لحظيًا)
        overStock(it) {
            return it && it.stock != null && it.qty > it.stock;
        },
        get hasStockWarning() {
            return this.items.some((i) => this.overStock(i));
        },
        // ينبّه الكاشير عند تجاوز المتوفر ويُرجِع true إن حدث تحذير
        _warnStock(key) {
            const it = this.items.find((i) => i.key === key);
            if (!this.overStock(it)) return false;
            Alpine.store('toasts').add(
                (it.stock <= 0 ? 'صنف نفد مخزونه: ' : 'الكمية تتجاوز المتوفر (' + it.stock + '): ') + it.name,
                'warning', 3000,
            );
            return true;
        },
        dec(key) {
            const it = this.items.find((i) => i.key === key);
            if (it && it.qty > 1) it.qty--;
        },
        remove(key) {
            this.items = this.items.filter((i) => i.key !== key);
        },
        clear() {
            this.items = [];
            this.removeCoupon();
            this.redeemActive = false;
        },
        get count() {
            return this.items.reduce((s, i) => s + i.qty, 0);
        },
        get subtotal() {
            return this.items.reduce((s, i) => s + i.price * i.qty, 0);
        },
        // الخصم = الكوبون + نقاط الولاء المستبدلة (لا يتجاوز المجموع الفرعي)
        get discountAmount() {
            return Math.min(this.couponDiscount + this.redeemDiscount, this.subtotal);
        },
        get taxAmount() {
            return ((this.subtotal - this.discountAmount) * this.taxRate) / 100;
        },
        get total() {
            return this.subtotal - this.discountAmount + this.taxAmount;
        },
        // الإجمالي بعملة العرض (للعرض فقط — التخزين يبقى بالأساسية)
        get displayTotal() {
            return this.total * window.CURRENCY.rate;
        },
        // تنسيق قيمة بعملة العرض (القيمة أصلًا بالأساسية → تُحوّل)
        money(v) {
            return this.fmt(Number(v) * window.CURRENCY.rate);
        },
        // تنسيق قيمة مُعطاة أصلًا بعملة العرض (بدون تحويل)
        fmt(v) {
            return Number(v).toLocaleString('en-US', {
                minimumFractionDigits: window.CURRENCY.decimals,
                maximumFractionDigits: window.CURRENCY.decimals,
            }) + ' ' + window.CURRENCY.symbol;
        },
    }));
    Alpine.data('reportViewer', () => ({
        g: 'all',
        showModal: false,
        loading: false,
        error: '',
        report: {},
        async open(key) {
            this.showModal = true;
            this.loading = true;
            this.error = '';
            this.report = {};
            try {
                const res = await fetch(`/admin/reports/data/${key}`, { headers: { Accept: 'application/json' } });
                if (!res.ok) throw new Error('load failed');
                this.report = await res.json();
            } catch (e) {
                this.error = 'تعذّر تحميل التقرير. حاول مرة أخرى.';
            } finally {
                this.loading = false;
            }
        },
        close() { this.showModal = false; },
    }));
});


Alpine.start();
