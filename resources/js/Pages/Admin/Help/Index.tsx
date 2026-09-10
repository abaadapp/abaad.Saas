import { router, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { LifeBuoy, MessageSquarePlus, Paperclip, Send, X } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

interface Row {
    id: number;
    reference: string;
    subject: string;
    category: string;
    status: string;
    statusLabel: string;
    lastMessageAt: string | null;
    unread: number;
}

interface Props {
    conversations: { data: Row[]; links: { url: string | null; label: string; active: boolean }[] };
    categories: { value: string; label: string }[];
    maxFiles: number;
    maxKb: number;
    extensions: string[];
}

const statusTone = (status: string) =>
    ({
        new: 'bg-[#eef2ff] text-[#4338ca]',
        open: 'bg-[#ecfdf5] text-[#047857]',
        waiting_customer: 'bg-[#fffbeb] text-[#b45309]',
        waiting_abaad: 'bg-[#f5f3ff] text-[#5b21b6]',
        resolved: 'bg-[#f0fdf4] text-[#15803d]',
        closed: 'bg-[#f4f4f5] text-[#52525b]',
    })[status] ?? 'bg-[#f4f4f5] text-[#52525b]';

export default function Help({ conversations, categories, maxFiles, maxKb, extensions }: Props) {
    const t = useTranslate();
    const [open, setOpen] = useState(conversations.data.length === 0);

    return (
        <AdminLayout title="المساعدة والدعم">
            <div className="mb-4 flex flex-wrap items-center gap-3">
                <div className="me-auto">
                    <h1 className="text-[20px] font-bold text-[#111]">{t('المساعدة والدعم')}</h1>
                    <p className="mt-0.5 text-[13px] text-[#71717a]">
                        {t('اسأل فريق أبعاد — والردّ يصلك هنا وفي جرس التنبيهات.')}
                    </p>
                </div>

                {!open && (
                    <Button onClick={() => setOpen(true)}>
                        <MessageSquarePlus />
                        {t('محادثة جديدة')}
                    </Button>
                )}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,420px)]">
                <section className="rounded-[16px] border border-[var(--ui-border,#e8e8e8)] bg-white">
                    {conversations.data.length === 0 ? (
                        <div className="px-4 py-16 text-center">
                            <LifeBuoy className="mx-auto size-10 text-[#d4d4d8]" />
                            <p className="mt-3 text-[14px] font-medium text-[#4b4b4b]">
                                {t('لا توجد محادثات حاليًا')}
                            </p>
                            <p className="mt-1 text-[12px] text-[#9ca3af]">
                                {t('ابدأ محادثة وسيصلك ردّ فريق أبعاد هنا.')}
                            </p>
                        </div>
                    ) : (
                        <ul className="divide-y divide-[var(--ui-border,#f0f0ef)]">
                            {conversations.data.map((c) => (
                                <li key={c.id}>
                                    <button
                                        type="button"
                                        onClick={() => router.get(route('admin.help.show', c.id))}
                                        className="flex w-full items-center gap-3 p-4 text-start hover:bg-[#fafafa]"
                                    >
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-[14px] font-bold text-[#111]">
                                                {c.subject}
                                            </span>
                                            <span className="mt-0.5 block text-[12px] text-[#71717a]">
                                                {c.category}
                                                <span className="mx-1.5 text-[#d4d4d8]">·</span>
                                                <span dir="ltr">{c.reference}</span>
                                            </span>
                                        </span>

                                        {c.unread > 0 && (
                                            <span className="flex size-[18px] shrink-0 items-center justify-center rounded-full bg-[#6d28d9] text-[10px] font-bold tabular-nums text-white">
                                                {c.unread}
                                            </span>
                                        )}

                                        <span
                                            className={cn(
                                                'shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium',
                                                statusTone(c.status),
                                            )}
                                        >
                                            {c.statusLabel}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}

                    {conversations.links.length > 3 && (
                        <nav className="flex flex-wrap items-center justify-center gap-1 border-t border-[var(--ui-border,#e8e8e8)] p-2">
                            {conversations.links.map((l, i) => (
                                <button
                                    key={i}
                                    type="button"
                                    disabled={!l.url}
                                    onClick={() => l.url && router.get(l.url)}
                                    className={cn(
                                        'min-w-7 rounded-[6px] px-2 py-1 text-[12px]',
                                        l.active ? 'bg-[#111] text-white' : 'text-[#4b4b4b] hover:bg-[#fafafa]',
                                        !l.url && 'cursor-not-allowed opacity-40',
                                    )}
                                    dangerouslySetInnerHTML={{ __html: l.label }}
                                />
                            ))}
                        </nav>
                    )}
                </section>

                {open && (
                    <NewConversation
                        categories={categories}
                        maxFiles={maxFiles}
                        maxKb={maxKb}
                        extensions={extensions}
                        onClose={conversations.data.length > 0 ? () => setOpen(false) : undefined}
                    />
                )}
            </div>
        </AdminLayout>
    );
}

/**
 * نموذجُ الفتح — ولا يُسأل فيه عن المتجر.
 *
 * لا حقلَ «اسم النشاط» ولا «رقم المتجر»: من يكتب يُعرَف من جلسته. وحقلٌ
 * يُملأ باليد يُخطئ صاحبُه فتصل شكواه إلى غيره — أو يُبدَّل عمدًا.
 */
function NewConversation({
    categories,
    maxFiles,
    maxKb,
    extensions,
    onClose,
}: {
    categories: { value: string; label: string }[];
    maxFiles: number;
    maxKb: number;
    extensions: string[];
    onClose?: () => void;
}) {
    const t = useTranslate();
    const picker = useRef<HTMLInputElement>(null);

    const form = useForm<{ subject: string; category: string; body: string; files: File[] }>({
        subject: '',
        category: categories[0]?.value ?? '',
        body: '',
        files: [],
    });

    return (
        <section className="h-fit rounded-[16px] border border-[var(--ui-border,#e8e8e8)] bg-white p-5">
            <div className="mb-4 flex items-center gap-2">
                <h2 className="me-auto text-[15px] font-bold text-[#111]">{t('كيف يمكننا مساعدتك؟')}</h2>
                {onClose && (
                    <button type="button" onClick={onClose} aria-label={t('إلغاء')}>
                        <X className="size-4 text-[#9ca3af] hover:text-[#111]" />
                    </button>
                )}
            </div>

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(route('admin.help.store'), { forceFormData: true });
                }}
                className="space-y-4"
            >
                <label className="block">
                    <span className="mb-1.5 block text-[13px] font-medium text-[#374151]">
                        {t('الموضوع')} <span className="text-[#b91c1c]">*</span>
                    </span>
                    <Input
                        value={form.data.subject}
                        maxLength={160}
                        onChange={(e) => form.setData('subject', e.target.value)}
                        placeholder={t('اكتب عنوانًا مختصرًا للمشكلة')}
                    />
                    {form.errors.subject && <p className="mt-1 text-[12px] text-[#b91c1c]">{form.errors.subject}</p>}
                </label>

                <label className="block">
                    <span className="mb-1.5 block text-[13px] font-medium text-[#374151]">{t('التصنيف')}</span>
                    <select
                        value={form.data.category}
                        onChange={(e) => form.setData('category', e.target.value)}
                        className="h-9 w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] px-3 text-[13px]"
                    >
                        {categories.map((c) => (
                            <option key={c.value} value={c.value}>
                                {c.label}
                            </option>
                        ))}
                    </select>
                    {form.errors.category && <p className="mt-1 text-[12px] text-[#b91c1c]">{form.errors.category}</p>}
                </label>

                <label className="block">
                    <span className="mb-1.5 block text-[13px] font-medium text-[#374151]">
                        {t('الرسالة')} <span className="text-[#b91c1c]">*</span>
                    </span>
                    <textarea
                        rows={5}
                        value={form.data.body}
                        maxLength={5000}
                        onChange={(e) => form.setData('body', e.target.value)}
                        placeholder={t('اكتب تفاصيل مشكلتك هنا...')}
                        className="w-full resize-none rounded-[10px] border border-[var(--ui-border,#e8e8e8)] p-3 text-[13px] outline-none placeholder:text-[#9ca3af]"
                    />
                    {form.errors.body && <p className="mt-1 text-[12px] text-[#b91c1c]">{form.errors.body}</p>}
                </label>

                <FilePicker form={form} picker={picker} maxFiles={maxFiles} maxKb={maxKb} extensions={extensions} />

                <Button type="submit" className="w-full" loading={form.processing} disabled={form.processing}>
                    <Send />
                    {t('إرسال')}
                </Button>
            </form>
        </section>
    );
}

/* منتقي الملفّات — ويقول حدودَه قبل أن تُتجاوز، لا بعد أن يردّ الخادم */
export function FilePicker({
    form,
    picker,
    maxFiles,
    maxKb,
    extensions,
}: {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    form: any;
    picker: React.RefObject<HTMLInputElement | null>;
    maxFiles: number;
    maxKb: number;
    extensions: string[];
}) {
    const t = useTranslate();

    return (
        <div>
            <input
                ref={picker}
                type="file"
                multiple
                className="hidden"
                accept={extensions.map((e) => `.${e}`).join(',')}
                onChange={(e) =>
                    form.setData(
                        'files',
                        [...form.data.files, ...Array.from(e.target.files ?? [])].slice(0, maxFiles),
                    )
                }
            />

            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => picker.current?.click()}
                disabled={form.data.files.length >= maxFiles}
            >
                <Paperclip />
                {t('إرفاق ملف')}
            </Button>

            <p className="mt-1 text-[11px] text-[#9ca3af]">
                {extensions.map((e) => e.toUpperCase()).join(' · ')}
                {' — '}
                {t('حتى :n ملفات، :kb ميغابايت للملف', { n: maxFiles, kb: Math.round(maxKb / 1024) })}
            </p>

            {form.data.files.length > 0 && (
                <ul className="mt-2 space-y-1">
                    {form.data.files.map((f: File, i: number) => (
                        <li
                            key={i}
                            className="flex items-center gap-2 rounded-[8px] bg-[#fafafa] p-2 text-[12px]"
                        >
                            <Paperclip className="size-3.5 shrink-0 text-[#9ca3af]" />
                            <span className="min-w-0 flex-1 truncate">{f.name}</span>
                            <button
                                type="button"
                                aria-label={t('إزالة')}
                                onClick={() =>
                                    form.setData(
                                        'files',
                                        form.data.files.filter((_: File, j: number) => j !== i),
                                    )
                                }
                            >
                                <X className="size-3.5 text-[#9ca3af] hover:text-[#b91c1c]" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {form.errors.files && <p className="mt-1 text-[12px] text-[#b91c1c]">{form.errors.files}</p>}
            {form.errors['files.0'] && (
                <p className="mt-1 text-[12px] text-[#b91c1c]">{form.errors['files.0']}</p>
            )}
        </div>
    );
}
