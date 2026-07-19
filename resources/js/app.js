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

/* رسم أيقونات Lucide الموجودة في الصفحة */
window.renderIcons = () => createIcons({ icons });
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
/* إعادة الرسم بعد أي تحديث من Alpine (عناصر ديناميكية مثل السلة) */
document.addEventListener('alpine:initialized', () => window.renderIcons());
let _iconTimer = null;
const _iconObserver = new MutationObserver(() => {
    clearTimeout(_iconTimer);
    _iconTimer = setTimeout(() => window.renderIcons(), 60);
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
    Alpine.data('posCart', () => ({
        items: [],
        customer: 'عميل نقدي',
        discountPercent: 0,
        deliveryFee: 0,
        taxRate: 5,
        add(product) {
            const existing = this.items.find((i) => i.id === product.id);
            if (existing) {
                existing.qty++;
            } else {
                this.items.push({ ...product, qty: 1, note: '' });
            }
            Alpine.store('toasts').add('تمت الإضافة إلى السلة', 'success', 1500);
        },
        inc(id) {
            const it = this.items.find((i) => i.id === id);
            if (it) it.qty++;
        },
        dec(id) {
            const it = this.items.find((i) => i.id === id);
            if (it && it.qty > 1) it.qty--;
        },
        remove(id) {
            this.items = this.items.filter((i) => i.id !== id);
        },
        clear() {
            this.items = [];
            this.discountPercent = 0;
            this.deliveryFee = 0;
        },
        get count() {
            return this.items.reduce((s, i) => s + i.qty, 0);
        },
        get subtotal() {
            return this.items.reduce((s, i) => s + i.price * i.qty, 0);
        },
        get discountAmount() {
            return (this.subtotal * this.discountPercent) / 100;
        },
        get taxAmount() {
            return ((this.subtotal - this.discountAmount) * this.taxRate) / 100;
        },
        get total() {
            return this.subtotal - this.discountAmount + this.taxAmount + Number(this.deliveryFee || 0);
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
});

Alpine.start();
