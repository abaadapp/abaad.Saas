import { type ReactNode, useRef } from 'react';
import { Paperclip, Send, X } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * المُحرِّر: [ مرفق ] [ نصّ ] [ إرسال ].
 *
 * عرضٌ لا بيانات: النصُّ والملفّاتُ والإرسالُ كلُّها بيد الصفحة. فالدعمُ
 * يرسل بـ`internal` ونافذةِ واتساب، والمبيعاتُ بـ`ai_model` و`ai_edited` —
 * ولا يعرف المُحرِّر أيًّا منهما. وما يُقال فوق الحقل (وضعُ الإرسال، حالُ
 * النافذة، سببُ الحجب) يمرّ في `above`.
 *
 * والإرسالُ بـ⌘/Ctrl+Enter لا بـEnter وحدها: من يكتب سطرين لا يريد أن
 * يخرج أوّلُهما قبل الثاني.
 */
export function MessageComposer({
    value,
    onChange,
    onSend,
    files,
    onAddFiles,
    onRemoveFile,
    accept,
    maxFiles,
    maxKb,
    processing,
    placeholder,
    tone = 'default',
    errors,
    above,
    trailing,
}: {
    value: string;
    onChange: (v: string) => void;
    onSend: () => void;
    files: File[];
    onAddFiles: (files: File[]) => void;
    onRemoveFile: (index: number) => void;
    accept: string;
    maxFiles: number;
    maxKb: number;
    processing: boolean;
    placeholder: string;
    tone?: 'default' | 'internal';
    errors?: { body?: string; files?: string };
    above?: ReactNode;
    trailing?: ReactNode;
}) {
    const t = useTranslate();
    const picker = useRef<HTMLInputElement>(null);
    const canSend = !processing && value.trim() !== '';

    return (
        <div className="shrink-0 border-t border-[var(--ui-border,#e8e8e8)] bg-white p-2.5">
            {above}

            {files.length > 0 && (
                <ul className="mb-2 flex flex-wrap gap-1.5">
                    {files.map((f, i) => (
                        <li
                            key={`${f.name}-${i}`}
                            className="flex max-w-full items-center gap-1.5 rounded-full border border-[var(--ui-border,#e8e8e8)] bg-[#fafaf9] ps-2.5 pe-1 py-1 text-[12px]"
                        >
                            <Paperclip className="size-3.5 shrink-0 text-[#9ca3af]" />
                            <span className="min-w-0 truncate" dir="auto">
                                {f.name}
                            </span>
                            <button
                                type="button"
                                aria-label={t('إزالة')}
                                onClick={() => onRemoveFile(i)}
                                className="rounded-full p-0.5 text-[#9ca3af] hover:bg-[#fee2e2] hover:text-[#b91c1c]"
                            >
                                <X className="size-3.5" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <div className="flex items-end gap-2">
            <div
                className={cn(
                    'flex min-w-0 flex-1 items-end gap-1 rounded-[22px] border px-2 py-1',
                    tone === 'internal'
                        ? 'border-[#f59e0b] bg-[#fffbeb]'
                        : 'border-[#e6e9f0] bg-[#f7f8fb] focus-within:border-[#2563eb] focus-within:bg-white',
                )}
            >
                <input
                    ref={picker}
                    type="file"
                    multiple
                    className="hidden"
                    accept={accept}
                    onChange={(e) => {
                        onAddFiles(Array.from(e.target.files ?? []));
                        e.target.value = '';
                    }}
                />
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    onClick={() => picker.current?.click()}
                    disabled={files.length >= maxFiles}
                    aria-label={t('إرفاق ملف')}
                    title={t('حتى :n ملفات، :kb ميغابايت للملف', { n: maxFiles, kb: Math.round(maxKb / 1024) })}
                >
                    <Paperclip />
                </Button>

                <textarea
                    rows={1}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey) && canSend) {
                            e.preventDefault();
                            onSend();
                        }
                    }}
                    placeholder={placeholder}
                    aria-label={placeholder}
                    dir="auto"
                    className="max-h-40 min-h-[38px] flex-1 resize-none self-center bg-transparent px-1.5 py-2 text-[13.5px] leading-[1.5] outline-none placeholder:text-[#9ca3af] field-sizing-content"
                />

                {trailing}
            </div>

                <Button
                    type="button"
                    size="icon"
                    onClick={onSend}
                    /* والضغطتان أمرٌ واحد: `processing` يُقفل الزرّ حتّى يعود الردّ */
                    disabled={!canSend}
                    aria-label={t('إرسال')}
                    title={t('إرسال')}
                    className={cn(
                        'size-11 shrink-0 rounded-[14px] shadow-[0_2px_8px_rgba(37,99,235,0.3)]',
                        tone === 'internal' ? 'bg-[#b45309] hover:bg-[#92400e]' : 'bg-[#2563eb] hover:bg-[#1d4ed8]',
                    )}
                >
                    <Send className="size-5 rtl:-scale-x-100" />
                </Button>
            </div>

            {errors?.body && <p className="mt-1 text-[12px] text-[#b91c1c]">{errors.body}</p>}
            {errors?.files && <p className="mt-1 text-[12px] text-[#b91c1c]">{errors.files}</p>}
        </div>
    );
}
