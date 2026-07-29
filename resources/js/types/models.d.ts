/** أشكال البيانات القادمة من App\Support\Demo — مأخوذة من مخرجاتها الفعلية */

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
    has_pin: boolean;
    achieved: number;
}

export interface Supplier {
    id: number;
    name: string;
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
}

export interface Transaction {
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
    total: number;
    subtotal: number;
    discount: number;
    tax: number;
    delivery_fee: number;
    payment: string;
    time: string;
    employee: string;
    lines: { name: string; qty: number; price: number; total: number }[];
}

export interface PurchaseOrder {
    id: number;
    number: string;
    branch: string | null;
    supplier: string;
    status: string;
    total: number;
    items_count: number;
    receipt: string | null;
    receipt_name: string | null;
    ordered: string;
    received: string | null;
}

export interface Movement {
    product: string;
    sku: string;
    type: string;
    qty: string;
    branch: string | null;
    employee: string;
    date: string;
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
