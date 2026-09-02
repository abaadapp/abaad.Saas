/** أشكال البيانات القادمة من App\Support\Demo — مأخوذة من مخرجاتها الفعلية */

export interface ProductVariantOption {
    id: number;
    name: string;
    label: string;
    price: number;
    sku: string | null;
}

export interface Product {
    id: number;
    name: string;
    name_en: string | null;
    label: string;
    cat: string;
    price: number;
    cost: number;
    qty: number;
    sku: string;
    barcode: string;
    image: string | null;
    stock_status: string;
    active: boolean;
    alert: number;
    tax: number;
    discount: number;
    /** مقاساتُه الفعّالة — الفارغة تعني منتجًا بسيطًا يُباع بسعره */
    variants?: ProductVariantOption[];
    /** معرّفات الإضافات المسموحة — و`null` يعني «كلّها» (سلوك ما قبل الربط) */
    addon_ids?: number[] | null;
    /** ذو الوصفة رصيدُه مكوّناتُه لا عمودُه */
    has_recipe?: boolean;
}

export interface Category {
    id: number;
    name: string;
    name_en: string | null;
    products: number;
    icon: string;
    color: string;
}

export interface Customer {
    id: number;
    name: string;
    name_en: string | null;
    label: string;
    phone: string;
    email: string | null;
    tax_number: string | null;
    orders: number;
    total_spent: number;
    last_order: string;
    /** رقم آخر فاتورةٍ مباعة — null لعميلٍ لم يشترِ بعد */
    last_invoice?: string | null;
    last_invoice_total?: number | null;
    points: number;
    avatar: string | null;
}

export interface Employee {
    id: number;
    name: string;
    avatar: string | null;
    role: string;
    branch: string;
    phone: string;
    email: string;
    sales: number;
    status: string;
    joined: string;
    achieved: number;
}

export interface Supplier {
    id: number;
    name: string;
    /** الاسم اللاتينيّ إن وُجد — و`label` هو ما يُعرض بلغة الواجهة */
    name_en?: string | null;
    label?: string;
    phone: string;
    email: string;
    contact: string;
    notes: string | null;
    orders_count: number;
}

export interface InventoryItem {
    id: number;
    name: string;
    sku: string;
    qty: number;
    min: number;
    status: string;
    cost: number;
    value: number;
    updated: string;
    branches: { name: string; quantity: number }[];
}

/**
 * ما ترسله Demo::activeCoupons إلى شاشة البيع — ثلاثة حقول لا أكثر.
 *
 * كانت الشاشة تستعمل نوع Coupon الكامل، فيمرّ `key={c.id}` من TypeScript
 * بينما `id` غير مُرسَل أصلًا: المفتاح undefined لكل كوبون. النوع الذي
 * يَعِد بأكثر مما يصل يُعمي الفحص بدل أن يحرسه.
 */
export interface PosCoupon {
    code: string;
    min_order: number;
    display: string;
}

export interface Coupon {
    id: number;
    code: string;
    type: string;
    value: number;
    min_order: number;
    max_uses: number | null;
    used_count: number;
    expires: string;
    expired: boolean;
    active: boolean;
    display: string;
}

export interface Addon {
    id: number;
    name: string;
    name_en: string | null;
    label: string;
    price: number;
    icon: string;
    active: boolean;
    /** البضاعة التي تنقص حين تُباع — فارغةٌ لإضافةٍ خدميّة لا رصيد لها */
    inventory_product_id?: number | null;
}

export interface Transaction {
    /** المفتاح الأساسي — للهوية في React لا للعرض */
    key: number;
    /** المرجع المعروض (رقم فاتورة مثلًا) وقد يتكرّر */
    id: string;
    date: string;
    description: string;
    method: string;
    type: string;
    amount: number;
    employee: string;
}

export interface Receipt {
    number: string;
    customer: string;
    phone: string | null;
    payment: string;
    time: string;
    employee: string;
    /*
     * اختيارية لأن الخادم ينزعها عمّن لا يملك صلاحية `finance` — انظر
     * App\Support\ReceiptVisibility. الاختيارية هنا مقصودة: تُجبر كل شاشة
     * تعرض مبلغًا على التعامل مع غيابه بدل أن تطبع undefined.
     */
    total?: number;
    subtotal?: number;
    discount?: number;
    tax?: number;
    delivery_fee?: number;
    lines?: {
        name: string;
        qty: number;
        price: number;
        total: number;
        /** الإضافات المختارة على هذا البند بلقطتها وقت البيع */
        addons?: { name: string; qty: number; total: number }[];
    }[];
}

export interface PurchaseOrder {
    id: number;
    number: string;
    branch: string | null;
    supplier: string;
    status: string;
    total: number;
    items_count: number;
    /** بنود الأمر — تُرسَل للمفتوح وحده، فالمستلَم لا يُفتح في نافذة الاستلام */
    items: PurchaseOrderLine[];
    receipt: string | null;
    receipt_name: string | null;
    ordered: string;
    received: string | null;
}

export interface PurchaseOrderLine {
    id: number;
    name: string;
    quantity: number;
    received: number;
    remaining: number;
    cost: number;
}

export interface Movement {
    id: number;
    product: string;
    sku: string;
    type: string;
    qty: string;
    branch: string | null;
    /** مسار التحويل («مسقط ← صلالة») — للحركات من نوع «تحويل بين الفروع» */
    note: string | null;
    employee: string;
    date: string;
}

export interface Branch {
    id: number;
    name: string;
    phone: string | null;
    address: string | null;
    /** ما يتعلّق بالفرع — يُعرض في تأكيد الحذف ليعرف الضاغط ما يُخفيه */
    orders?: number;
    devices?: number;
}

export interface Order {
    /** رقم الفاتورة لا معرّف الصف — وهو ما تستقبله مسارات الطلب */
    id: string;
    customer: string;
    employee: string;
    branch: string | null;
    items_count: number;
    total: number;
    payment: string;
    status: string;
    date: string;
}

export interface Expense {
    type: string;
    description: string;
    amount: number;
    date: string;
    employee: string | null;
    method: string | null;
}

export interface HeldOrder {
    order_id: number;
    id: string;
    customer: string;
    employee: string;
    total: number;
    items_count?: number;
    time?: string;
}
