import { useForm } from '@inertiajs/react';
import { useRef } from 'react';
import { ArrowLeft, FileText, Send } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import SmartLink from '@/Components/SmartLink';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { FilePicker } from './Index';

interface Message {
    id: number;
    scope: 'business' | 'platform' | 'system';
    body: string | null;
    event: string | null;
    eventText: string | null;
    sender: string;
    at: string | null;
    files: { id: number; name: string; size: number; isImage: boolean; url: string }[];
}

interface Props {
    conversation: {
        id: number;
        reference: string;
        subject: string;
        category: string;
        status: string;
        statusLabel: string;
        channelLabel: string;
        openedAt: string | null;
    };
    messages: Message[];
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

const kb = (bytes: number) => `${Math.max(1, Math.round(bytes / 1024))} KB`;

export default function HelpShow({ conversation, messages, maxFiles, maxKb, extensions }: Props) {
    const t = useTranslate();
    const picker = useRef<HTMLInputElement>(null);

    const form = useForm<{ body: string; files: File[] }>({ body: '', files: [] });

    return (
        <AdminLayout title={conversation.subject}>
            <SmartLink
                routeName="admin.help.index"
                href={route('admin.help.index')}
                className="mb-3 inline-flex items-center gap-1.5 text-[13px] text-[#6b7280] hover:text-[#111]"
            >
                <ArrowLeft className="size-4 rtl:rotate-180" />
                {t('المساعدة والدعم')}
            </SmartLink>

            <div className="rounded-[16px] border border-[var(--ui-border,#e8e8e8)] bg-white">
                <header className="flex flex-wrap items-center gap-3 border-b border-[var(--ui-border,#e8e8e8)] p-4">
                    <div className="me-auto min-w-0">
                        <h1 className="truncate text-[16px] font-bold text-[#111]">{conversation.subject}</h1>
                        <p className="mt-0.5 text-[12px] text-[#71717a]">
                            {conversation.category}
                            <span className="mx-1.5 text-[#d4d4d8]">·</span>
                            <span dir="ltr">{conversation.reference}</span>
                        </p>
                    </div>

                    <span
                        className={cn(
                            'rounded-full px-2.5 py-1 text-[12px] font-medium',
                            statusTone(conversation.status),
                        )}
                    >
                        {conversation.statusLabel}
                    </span>
                </header>

                <div className="space-y-3 p-4">
                    {messages.length === 0 && (
                        <p className="py-10 text-center text-[13px] text-[#9ca3af]">{t('لا توجد رسائل بعد')}</p>
                    )}

                    {messages.map((m) =>
                        m.event ? (
                            <p key={m.id} className="text-center text-[11px] text-[#9ca3af]">
                                {m.eventText}
                            </p>
                        ) : (
                            <div
                                key={m.id}
                                className={cn('flex', m.scope === 'business' ? 'justify-end' : 'justify-start')}
                            >
                                <div
                                    className={cn(
                                        'max-w-[80%] rounded-[14px] px-3.5 py-2.5',
                                        m.scope === 'business' ? 'bg-[#f5f3ff]' : 'bg-[#f7f7f5]',
                                    )}
                                >
                                    {m.body && (
                                        <p className="whitespace-pre-wrap break-words text-[13px] leading-relaxed text-[#111]">
                                            {m.body}
                                        </p>
                                    )}

                                    {m.files.map((f) => (
                                        <a
                                            key={f.id}
                                            href={f.url}
                                            className="mt-2 flex items-center gap-2 rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white p-2 text-[12px] hover:bg-[#fafafa]"
                                        >
                                            <FileText className="size-4 shrink-0 text-[#6d28d9]" />
                                            <span className="min-w-0 flex-1 truncate">{f.name}</span>
                                            <span className="shrink-0 text-[11px] text-[#9ca3af]">{kb(f.size)}</span>
                                        </a>
                                    ))}

                                    <p className="mt-1 text-[10px] text-[#9ca3af]">
                                        {m.sender}
                                        {m.at && (
                                            <>
                                                <span className="mx-1">·</span>
                                                <span dir="ltr">{new Date(m.at).toLocaleString()}</span>
                                            </>
                                        )}
                                    </p>
                                </div>
                            </div>
                        ),
                    )}
                </div>

                {/*
                    وبابُ الردّ يبقى مفتوحًا ولو حُلّت المحادثة.
                    من يجد بابَه مغلقًا يفتح محادثةً ثانيةً بالسؤال نفسه،
                    فيتشتّت الخيطُ ويعيد الدعمُ قراءةَ القصّة من أوّلها.
                */}
                <div className="space-y-3 border-t border-[var(--ui-border,#e8e8e8)] p-4">
                    <textarea
                        rows={3}
                        value={form.data.body}
                        maxLength={5000}
                        onChange={(e) => form.setData('body', e.target.value)}
                        placeholder={t('اكتب ردّك هنا...')}
                        className="w-full resize-none rounded-[10px] border border-[var(--ui-border,#e8e8e8)] p-3 text-[13px] outline-none placeholder:text-[#9ca3af]"
                    />
                    {form.errors.body && <p className="text-[12px] text-[#b91c1c]">{form.errors.body}</p>}

                    <FilePicker
                        form={form}
                        picker={picker}
                        maxFiles={maxFiles}
                        maxKb={maxKb}
                        extensions={extensions}
                    />

                    <Button
                        type="button"
                        loading={form.processing}
                        disabled={form.processing || form.data.body.trim() === ''}
                        onClick={() =>
                            form.post(route('admin.help.reply', conversation.id), {
                                preserveScroll: true,
                                forceFormData: true,
                                onSuccess: () => form.reset(),
                            })
                        }
                    >
                        <Send />
                        {t('إرسال')}
                    </Button>
                </div>
            </div>
        </AdminLayout>
    );
}
