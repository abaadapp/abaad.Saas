import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ChevronLeft, RefreshCw, Save } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Field, { Select } from '@/Components/Field';
import Toggle from '@/Components/Toggle';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

type FieldRow = { key: string; label: string; hint: string | null };

type Template = {
    key: string;
    label: string;
    desc: string;
    section: string;
    hasPaper: boolean;
    fields: FieldRow[];
    fonts: string[];
    papers: string[];
    values: Record<string, string | boolean>;
};

type Props = PageProps<{
    template: Template;
    templates: { key: string; label: string; desc: string; section: string }[];
}>;

/**
 * محرّرُ ورقةٍ واحدة — الورقةُ سبعون بالمئة من الشاشة وإعداداتُها ثلاثون.
 *
 * والمعاينة **صورةُ الورقة التي تُطبع** لا شبيهٌ لها مرسومٌ في الشاشة: تُطلب
 * من الخادم بالقيم التي على الشاشة الآن، فيرسمها بالقالب الذي يُخرج الـPDF.
 * وكانت الشاشة ترسم إيصالًا بيدها في JSX — فيُصلَح سطرٌ في ملفّ الرسم ولا
 * يتغيّر في المعاينة، ويضبط التاجر قالبه على شكلٍ لا يخرج من الطابعة.
 *
 * وفي إطارٍ معزول (`iframe`) لا في الصفحة: الورقة تحمل `<style>` خاصًّا بها
 * يضبط `body` والجداول، ولصقُه في الصفحة يُعيد تنسيق اللوحة كلّها.
 */
export default function TemplateEditor({ template, templates }: Props) {
    const t = useTranslate();

    const form = useForm<Record<string, string | boolean>>({ ...template.values });

    const [html, setHtml] = useState<string>('');
    const [drawing, setDrawing] = useState(true);
    /*
     * ورقةٌ واحدة تُرسم في كلّ لحظة.
     *
     * كلُّ ضغطة حرفٍ في التذييل تطلب رسمًا، وردودُ الخادم لا تصل بالترتيب
     * الذي أُرسلت به — فتحلّ صورةٌ قديمة محلَّ أحدث واحدة، ويرى التاجر
     * حرفَه الأخير وقد اختفى. والعدّاد يُسقط كلّ ردٍّ سبقه أحدثُ منه.
     */
    const ticket = useRef(0);

    const draw = useCallback(async () => {
        const mine = ++ticket.current;
        setDrawing(true);

        try {
            const res = await fetch(route('admin.settings.templates.preview', template.key), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify(form.data),
            });

            const body = await res.json();

            if (mine === ticket.current) {
                setHtml(typeof body.html === 'string' ? body.html : '');
            }
        } catch {
            /* شبكةٌ انقطعت: تبقى آخر صورةٍ رُسمت، ولا تُمحى الورقة أمام صاحبها */
        } finally {
            if (mine === ticket.current) {
                setDrawing(false);
            }
        }
    }, [form.data, template.key]);

    // تأخيرٌ قصير: الكتابة في التذييل لا ترسل طلبًا لكل حرف
    useEffect(() => {
        const id = window.setTimeout(draw, 350);

        return () => window.clearTimeout(id);
    }, [draw]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.settings.templates.update', template.key), { preserveScroll: true });
    };

    return (
        <AdminLayout title={template.label}>
            <PageHeader
                title={template.label}
                subtitle={template.desc}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <Link href={route('admin.settings.index', { section: 'templates' })}>
                                {t('كل القوالب')}
                                <ChevronLeft />
                            </Link>
                        </Button>
                        <Button onClick={submit} loading={form.processing}>
                            <Save />
                            {t('حفظ القالب')}
                        </Button>
                    </>
                }
            />

            {/*
                سبعون إلى ثلاثين — والورقة أوّلًا في الترتيب.

                وعلى الجوّال عمودٌ واحد: الورقة فوق وإعداداتُها تحتها. وعمودان
                على شاشةٍ عرضها أربعمئة بكسل يجعلان الورقة شريطًا لا يُقرأ.
            */}
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-[7fr_3fr]">
                <div className="min-w-0">
                    <div className="mb-2 flex items-center justify-between">
                        <p className="text-[12px] text-[#9ca3af]">
                            {t('المعاينة — بالقالب الذي يُطبع فعلًا')}
                        </p>
                        {drawing && <RefreshCw className="size-3.5 animate-spin text-[#d1d5db]" />}
                    </div>

                    <div className="overflow-hidden rounded-[16px] border border-[var(--ui-border,#e8e8e8)] bg-white">
                        {/*
                            sandbox بلا allow-scripts: الورقة نصٌّ يُطبع لا
                            صفحةٌ تعمل، وتنفيذُ شيءٍ منها في اللوحة لا داعيَ له.
                        */}
                        <iframe
                            title={t('معاينة الورقة')}
                            srcDoc={html}
                            sandbox=""
                            className="h-[70dvh] w-full border-0 bg-white lg:h-[calc(100dvh-14rem)]"
                        />
                    </div>
                </div>

                <form onSubmit={submit} className="min-w-0 space-y-4">
                    <Card className="p-5">
                        <h3 className="mb-4 font-bold text-[#111]">{t('إعدادات الورقة')}</h3>

                        <Field
                            label="سطر تحت اسم المتجر"
                            hint="شعار أو عبارة ترحيب — يُترك فارغًا فلا يظهر"
                            error={form.errors.header}
                        >
                            <Input
                                value={(form.data.header as string) ?? ''}
                                onChange={(e) => form.setData('header', e.target.value)}
                            />
                        </Field>

                        <div className="mt-4">
                            <Field label="نص التذييل" hint="يظهر أسفل الورقة — كل سطر كما كتبته" error={form.errors.footer}>
                                <Textarea
                                    rows={3}
                                    value={(form.data.footer as string) ?? ''}
                                    onChange={(e) => form.setData('footer', e.target.value)}
                                />
                            </Field>
                        </div>

                        <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="حجم الخط" error={form.errors.font}>
                                <Select
                                    value={(form.data.font as string) ?? 'عادي'}
                                    onChange={(e) => form.setData('font', e.target.value)}
                                    options={template.fonts.map((f) => ({ label: t(f), value: f }))}
                                />
                            </Field>

                            {template.hasPaper && (
                                <Field
                                    label="مقاس الورق"
                                    hint="الإيصال الحراري 80 أو 58 ملم، والفاتورة على A4"
                                    error={form.errors.paper}
                                >
                                    <Select
                                        value={(form.data.paper as string) ?? '80mm'}
                                        onChange={(e) => form.setData('paper', e.target.value)}
                                        options={template.papers.map((p) => ({ label: p, value: p }))}
                                    />
                                </Field>
                            )}
                        </div>
                    </Card>

                    <Card className="p-5">
                        <h3 className="mb-1 font-bold text-[#111]">{t('ما يظهر على الورقة')}</h3>
                        <p className="mb-3 text-[12px] text-[#9ca3af]">
                            {t('كل مفتاح أثرُه في المعاينة على اليمين فورًا.')}
                        </p>

                        {template.fields.map((f) => (
                            <Toggle
                                key={f.key}
                                on={Boolean(form.data[f.key])}
                                onChange={(v) => form.setData(f.key, v)}
                                label={f.label}
                                hint={f.hint ?? undefined}
                            />
                        ))}
                    </Card>

                    <Card className="p-5">
                        <h3 className="mb-3 font-bold text-[#111]">{t('قوالب أخرى')}</h3>
                        <ul className="space-y-1">
                            {templates
                                .filter((x) => x.key !== template.key)
                                .map((x) => (
                                    <li key={x.key}>
                                        <Link
                                            href={route('admin.settings.templates.edit', x.key)}
                                            className={cn(
                                                'flex items-center justify-between rounded-[10px] px-3 py-2 text-[13px] text-[#374151]',
                                                'hover:bg-[#fafafa]',
                                            )}
                                        >
                                            {x.label}
                                            <ChevronLeft className="size-4 text-[#d1d5db]" />
                                        </Link>
                                    </li>
                                ))}
                        </ul>
                    </Card>

                    <div className="flex justify-end">
                        <Button type="submit" loading={form.processing}>
                            <Save />
                            {t('حفظ القالب')}
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
