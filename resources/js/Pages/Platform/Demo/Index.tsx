import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Copy, FlaskConical, LogIn, Plus, RefreshCw, ShieldCheck, Trash2 } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import Field, { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Count {
    label: string;
    value: number;
}

interface Store {
    id: number;
    name: string;
    status: string;
    registered: string | null;
    counts: Count[];
    login: { owner: string | null; cashier: string | null };
}

interface SizeDetail {
    products: number;
    customers: number;
    orders: number;
    months: number;
}

interface Props {
    stores: Store[];
    sizes: string[];
    sizeDetail: Record<string, SizeDetail>;
    credentials: { password: string };
    realCount: number;
}

export default function DemoIndex() {
    const { stores, sizes, sizeDetail, credentials, realCount } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const [creating, setCreating] = useState(false);
    const [removing, setRemoving] = useState<Store | null>(null);
    const [copied, setCopied] = useState<string | null>(null);

    const create = useForm({ name: 'متجر أبعاد التجريبي', size: 'متوسط' });
    const reseed = useForm({ size: 'متوسط' });
    const remove = useForm({});

    const submitCreate = (e: React.FormEvent) => {
        e.preventDefault();
        create.post(route('super-admin.demo.store'), { onSuccess: () => setCreating(false) });
    };

    const copy = (value: string) => {
        navigator.clipboard?.writeText(value);
        setCopied(value);
        window.setTimeout(() => setCopied(null), 1500);
    };

    const detail = (size: string) => {
        const d = sizeDetail[size];
        if (!d) return size;

        return `${size} — ${number(d.products)} ${t('منتج')} · ${number(d.customers)} ${t('عميل')} · ${number(d.orders)} ${t('فاتورة')}`;
    };

    return (
        <PlatformLayout title="الديمو">
            <PageHeader
                title="الديمو"
                subtitle={t('متاجر تجريبيّة ممتلئة لعرض النظام — معزولة عن حسابات التجّار')}
                actions={
                    <Button onClick={() => setCreating(true)}>
                        <Plus />
                        {t('متجر تجريبيّ جديد')}
                    </Button>
                }
            />

            {/*
                قاعدة العزل مكتوبةً لا مفهومةً ضمنًا.

                من يفتح هذا القسم يملك حذف متجرٍ بضغطة، فيجب أن يقرأ أين يقع
                ما يفعله قبل أن يفعله — لا أن يستنتجه من اسم الشاشة.
            */}
            <Card className="mb-6 flex items-start gap-3 border-[#d1fae5] bg-[#f0fdf4] p-4">
                <ShieldCheck className="mt-0.5 size-5 shrink-0 text-[#047857]" />
                <div className="min-w-0 text-[13px] leading-relaxed text-[#065f46]">
                    <p className="font-semibold">{t('البيانات الوهميّة لا تدخل حساب تاجر')}</p>
                    <p className="mt-1">
                        {t('البذر يرفض أي متجر غير موسوم تجريبيًّا، وإحصاءات المنصّة وتقاريرها تستثني هذه المتاجر. عدد متاجر التجّار الآن:')}{' '}
                        <span className="font-semibold tabular-nums">{number(realCount)}</span>
                        {' — '}
                        {t('ولا يمسّها شيءٌ ممّا في هذه الشاشة.')}
                    </p>
                </div>
            </Card>

            {stores.length === 0 ? (
                <Card className="p-12 text-center">
                    <FlaskConical className="mx-auto size-10 text-[#d1d5db]" />
                    <h3 className="mt-4 font-bold text-[#111]">{t('لا متجر تجريبيّ بعد')}</h3>
                    <p className="mx-auto mt-1 max-w-md text-sm text-[#6b7280]">
                        {t('أنشئ متجرًا ممتلئًا بالبيانات لعرض النظام كما يبدو بعد سنةٍ من العمل — لا كما يبدو في يومه الأوّل.')}
                    </p>
                    <Button className="mt-5" onClick={() => setCreating(true)}>
                        <Plus />
                        {t('متجر تجريبيّ جديد')}
                    </Button>
                </Card>
            ) : (
                <div className="space-y-4">
                    {stores.map((store) => (
                        <Card key={store.id} className="overflow-hidden">
                            <div className="flex flex-wrap items-center gap-3 border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                                <FlaskConical className="size-5 shrink-0 text-[#6b7280]" />
                                <div className="min-w-0 flex-1">
                                    <h3 className="truncate font-bold text-[#111]">{store.name}</h3>
                                    <p className="text-[12px] text-[#9ca3af]">
                                        {t('تجريبيّ')} · #{store.id}
                                        {store.registered && (
                                            <>
                                                {' · '}
                                                <span dir="ltr">{store.registered}</span>
                                            </>
                                        )}
                                    </p>
                                </div>
                                <Badge status={store.status} />

                                {/*
                                    الدخول أوّل ما يُضغط في هذه الشاشة — فهو
                                    سببُ وجودها. والبديل كان نسخ البريد وكلمة
                                    المرور ثمّ الخروج والدخول من جديد: خطواتٌ
                                    تُفعل أمام العميل في العرض.
                                */}
                                <Button
                                    variant="primary"
                                    size="sm"
                                    onClick={() =>
                                        router.post(route('super-admin.businesses.impersonate', store.id))
                                    }
                                >
                                    <LogIn />
                                    {t('دخول')}
                                </Button>

                                <Select
                                    value={reseed.data.size}
                                    aria-label={t('حجم البيانات')}
                                    className="w-40"
                                    onChange={(e) => reseed.setData('size', e.target.value)}
                                    options={sizes.map((s) => ({ label: s, value: s }))}
                                />
                                <Button
                                    variant="outline"
                                    size="sm"
                                    loading={reseed.processing}
                                    onClick={() =>
                                        reseed.post(route('super-admin.demo.reseed', store.id), { preserveScroll: true })
                                    }
                                >
                                    <RefreshCw />
                                    {t('إعادة بناء البيانات')}
                                </Button>
                                <Button variant="danger" size="sm" onClick={() => setRemoving(store)}>
                                    <Trash2 />
                                    {t('حذف')}
                                </Button>
                            </div>

                            <div className="grid grid-cols-2 gap-px bg-[var(--ui-border,#e8e8e8)] sm:grid-cols-4 lg:grid-cols-8">
                                {store.counts.map((c) => (
                                    <div key={c.label} className="bg-white px-4 py-3">
                                        <p className="text-[11px] text-[#9ca3af]">{t(c.label)}</p>
                                        <p className="mt-0.5 text-[17px] font-bold tabular-nums text-[#111]">
                                            {number(c.value)}
                                        </p>
                                    </div>
                                ))}
                            </div>

                            {/* الدخول داخل بطاقة المتجر: لكلٍّ بريدُه، وقائمةٌ
                                واحدة في الأسفل كانت ستفتح متجرًا غير المقصود.
                                ويُنسخ ولا يُكتب — ما يُكتب باليد يُخطأ أمام الناس */}
                            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] px-5 py-3 text-[13px]">
                                {[
                                    ['المالك', store.login.owner],
                                    ['الكاشير', store.login.cashier],
                                    ['كلمة المرور', credentials.password],
                                ]
                                    .filter(([, value]) => !! value)
                                    .map(([label, value]) => (
                                        <span key={label} className="flex min-w-0 items-center gap-1.5">
                                            <span className="text-[#9ca3af]">{t(label as string)}</span>
                                            <span dir="ltr" className="truncate font-mono text-[#111]">
                                                {value}
                                            </span>
                                            <Button
                                                variant="ghost"
                                                size="icon-sm"
                                                aria-label={t('نسخ')}
                                                onClick={() => copy(value as string)}
                                            >
                                                <Copy />
                                            </Button>
                                        </span>
                                    ))}
                                {copied && <span className="text-[12px] text-[#047857]">{t('نُسخ.')}</span>}
                            </div>
                        </Card>
                    ))}
                </div>
            )}

            {/* إنشاء */}
            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('متجر تجريبيّ جديد')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitCreate} className="space-y-4 px-5 pb-5">
                        <Field label="اسم المتجر" required error={create.errors.name}>
                            <Input
                                value={create.data.name}
                                onChange={(e) => create.setData('name', e.target.value)}
                                autoFocus
                            />
                        </Field>
                        <Field
                            label="حجم البيانات"
                            hint="الأكبر يستغرق وقتًا أطول في البناء"
                            error={create.errors.size}
                        >
                            <Select
                                value={create.data.size}
                                onChange={(e) => create.setData('size', e.target.value)}
                                options={sizes.map((s) => ({ label: detail(s), value: s }))}
                            />
                        </Field>
                        <div className="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="ghost" onClick={() => setCreating(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={create.processing}>
                                {t('إنشاء وملء البيانات')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* حذف */}
            <Dialog open={!! removing} onOpenChange={(open) => !open && setRemoving(null)}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('حذف المتجر التجريبيّ')}</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4 px-5 pb-5">
                        <div className="flex items-start gap-3 rounded-[12px] bg-[#fef2f2] p-4">
                            <AlertTriangle className="mt-0.5 size-5 shrink-0 text-[#b91c1c]" />
                            <p className="text-[13px] leading-relaxed text-[#7f1d1d]">
                                {t('يُحذف «:name» وكلّ صفٍّ يخصّه — المنتجات والفواتير والقيود والموظفون. ولا يُسترجع.', {
                                    name: removing?.name ?? '',
                                })}
                            </p>
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="ghost" onClick={() => setRemoving(null)}>
                                {t('إلغاء')}
                            </Button>
                            <Button
                                variant="danger"
                                loading={remove.processing}
                                onClick={() =>
                                    removing &&
                                    remove.delete(route('super-admin.demo.destroy', removing.id), {
                                        onSuccess: () => setRemoving(null),
                                    })
                                }
                            >
                                <Trash2 />
                                {t('حذف نهائيًّا')}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </PlatformLayout>
    );
}
