import { useState, type ReactNode } from 'react';
import { ArrowDown, ArrowUp, ChevronDown, ChevronLeft, Plus, Trash2 } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import {
    PART_LABELS,
    isInput,
    newPart,
    type DocumentForm,
    type FormInput,
    type FormPart,
    type FormSection,
} from '@/lib/platformDocuments';

/**
 * محرّرُ نموذج الاتفاقية — كلُّ ما فيها يُكتب هنا: المقدّمةُ والأبرز، والأقسامُ
 * بأجزائها (فقراتٌ وقوائمُ وأعمدةٌ وحقولٌ وخياراتٌ وأسئلةٌ وجداول)، والإقرار.
 *
 * يكتب `DocumentForm` كما يقرؤه الخادم (`DocumentForms::normalize`)، فلا JSON
 * يُكتب باليد. والرموزُ (`{fee}`، `{provider}`) نصٌّ يبقى في موضعه ويُملأ ساعةَ
 * الإصدار. وما يقوم عليه التوقيع (مفتاحٌ فريدٌ لكلّ إدخال، أعمدةُ الجدول) يفحصه
 * الخادمُ قبل الحفظ ويقول ما ينقص.
 */
export function DocumentFormEditor({ value, onChange }: { value: DocumentForm; onChange: (next: DocumentForm) => void }) {
    const t = useTranslate();
    const [open, setOpen] = useState<number | null>(null);
    const set = (patch: Partial<DocumentForm>) => onChange({ ...value, ...patch });
    const setSection = (i: number, next: FormSection) => set({ sections: value.sections.map((s, j) => (j === i ? next : s)) });

    return (
        <div className="space-y-4" data-testid="form-editor">
            <p className="rounded-[10px] bg-[#f6f6f5] px-3 py-2 text-[12px] text-[#4b5563]" data-testid="token-hint">
                {t('رموز تُملأ عند الإصدار: {fee} الرسوم بثلاث خانات · {fee_short} الرسوم مختصرة · {provider} بيانات مقدّم الخدمة')}
            </p>

            <Block title={t('المقدمة والأبرز')}>
                <Textarea
                    value={value.intro ?? ''}
                    onChange={(e) => set({ intro: e.target.value === '' ? null : e.target.value })}
                    rows={2}
                    placeholder={t('مقدمة الاتفاقية (اختيارية)')}
                    aria-label={t('المقدمة')}
                    data-testid="form-intro"
                />
                {value.highlights.map((h, i) => (
                    <div key={i} className="flex items-center gap-2">
                        <Input
                            value={h.label}
                            onChange={(e) => set({ highlights: value.highlights.map((x, j) => (j === i ? { ...x, label: e.target.value } : x)) })}
                            placeholder={t('العنوان')}
                            className="w-40 shrink-0"
                            aria-label={t('عنوان السطر البارز')}
                        />
                        <Input
                            value={h.text}
                            onChange={(e) => set({ highlights: value.highlights.map((x, j) => (j === i ? { ...x, text: e.target.value } : x)) })}
                            placeholder={t('النص')}
                            aria-label={t('نص السطر البارز')}
                        />
                        <IconButton label={t('حذف')} onClick={() => set({ highlights: value.highlights.filter((_, j) => j !== i) })}>
                            <Trash2 />
                        </IconButton>
                    </div>
                ))}
                <Button type="button" variant="outline" size="sm" onClick={() => set({ highlights: [...value.highlights, { label: '', text: '' }] })}>
                    <Plus />
                    {t('سطر بارز')}
                </Button>
            </Block>

            <div className="space-y-2" data-testid="form-sections">
                {value.sections.map((section, i) => (
                    <SectionEditor
                        key={i}
                        index={i}
                        count={value.sections.length}
                        form={value}
                        section={section}
                        open={open === i}
                        onToggle={() => setOpen(open === i ? null : i)}
                        onChange={(next) => setSection(i, next)}
                        onMove={(by) => {
                            const next = [...value.sections];
                            const [row] = next.splice(i, 1);
                            next.splice(i + by, 0, row);
                            set({ sections: next });
                            setOpen(i + by);
                        }}
                        onRemove={() => {
                            set({ sections: value.sections.filter((_, j) => j !== i) });
                            setOpen(null);
                        }}
                    />
                ))}
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-testid="add-section"
                    onClick={() => {
                        set({ sections: [...value.sections, { title: '', after: false, parts: [{ kind: 'p', text: '' }] }] });
                        setOpen(value.sections.length);
                    }}
                >
                    <Plus />
                    {t('قسم جديد')}
                </Button>
            </div>

            <Block title={t('الإقرار والتذييل')}>
                <Textarea
                    value={value.consent}
                    onChange={(e) => set({ consent: e.target.value })}
                    rows={3}
                    placeholder={t('نص الإقرار الذي يوقّع عليه العميل')}
                    aria-label={t('نص الإقرار')}
                    data-testid="form-consent"
                />
                <Input value={value.agree} onChange={(e) => set({ agree: e.target.value })} placeholder={t('نص خانة الموافقة')} aria-label={t('نص خانة الموافقة')} />
                <Input
                    value={value.footer ?? ''}
                    onChange={(e) => set({ footer: e.target.value === '' ? null : e.target.value })}
                    placeholder={t('تذييل النموذج (اختياري)')}
                    aria-label={t('التذييل')}
                />
            </Block>
        </div>
    );
}

function SectionEditor({
    index,
    count,
    form,
    section,
    open,
    onToggle,
    onChange,
    onMove,
    onRemove,
}: {
    index: number;
    count: number;
    form: DocumentForm;
    section: FormSection;
    open: boolean;
    onToggle: () => void;
    onChange: (next: FormSection) => void;
    onMove: (by: number) => void;
    onRemove: () => void;
}) {
    const t = useTranslate();
    const [adding, setAdding] = useState<FormPart['kind']>('p');
    const setPart = (i: number, next: FormPart) => onChange({ ...section, parts: section.parts.map((p, j) => (j === i ? next : p)) });
    const movePart = (i: number, by: number) => {
        const next = [...section.parts];
        const [row] = next.splice(i, 1);
        next.splice(i + by, 0, row);
        onChange({ ...section, parts: next });
    };

    return (
        <div className="rounded-[12px] border border-[#e7e8ea]" data-testid={`section-${index}`}>
            <div className="flex items-center gap-2 px-3 py-2">
                <button type="button" onClick={onToggle} className="text-[#6b7280]" aria-expanded={open} aria-label={open ? t('طي القسم') : t('فتح القسم')}>
                    {open ? <ChevronDown className="size-4" /> : <ChevronLeft className="size-4" />}
                </button>
                <span className="w-6 shrink-0 text-[12px] text-[#9ca3af]">{String(index + 1).padStart(2, '0')}</span>
                <Input
                    value={section.title}
                    onChange={(e) => onChange({ ...section, title: e.target.value })}
                    placeholder={t('عنوان القسم')}
                    aria-label={t('عنوان القسم')}
                    data-testid={`section-title-${index}`}
                />
                <span className="hidden shrink-0 text-[11.5px] text-[#9ca3af] sm:inline">{t(':n جزء', { n: String(section.parts.length) })}</span>
                <IconButton label={t('أعلى')} disabled={index === 0} onClick={() => onMove(-1)}>
                    <ArrowUp />
                </IconButton>
                <IconButton label={t('أسفل')} disabled={index === count - 1} onClick={() => onMove(1)}>
                    <ArrowDown />
                </IconButton>
                <IconButton label={t('حذف القسم')} disabled={count === 1} onClick={onRemove}>
                    <Trash2 />
                </IconButton>
            </div>
            {open && (
                <div className="space-y-3 border-t border-[#e7e8ea] p-3">
                    <Check checked={section.after} onChange={(after) => onChange({ ...section, after })}>
                        {t('يُعبّأ بعد تنفيذ الخدمة (محضر استلام): يُطبع فارغًا ولا يُكتب فيه عند الإصدار ولا التوقيع')}
                    </Check>
                    {section.parts.map((part, i) => (
                        <div key={i} className="space-y-2 rounded-[10px] bg-[#fafafa] p-3" data-testid={`part-${index}-${i}`}>
                            <div className="flex items-center gap-2">
                                <Badge variant={isInput(part) ? 'info' : 'neutral'}>{t(PART_LABELS[part.kind])}</Badge>
                                {isInput(part) && (
                                    <Choice
                                        value={part.by}
                                        onChange={(by) => setPart(i, { ...part, by: by as FormInput['by'] })}
                                        options={[
                                            ['client', t('يعبّئه العميل')],
                                            ['team', t('يعبّئه فريق أبعاد')],
                                        ]}
                                        label={t('من يعبّئه')}
                                    />
                                )}
                                <span className="flex-1" />
                                <IconButton label={t('أعلى')} disabled={i === 0} onClick={() => movePart(i, -1)}>
                                    <ArrowUp />
                                </IconButton>
                                <IconButton label={t('أسفل')} disabled={i === section.parts.length - 1} onClick={() => movePart(i, 1)}>
                                    <ArrowDown />
                                </IconButton>
                                <IconButton label={t('حذف الجزء')} onClick={() => onChange({ ...section, parts: section.parts.filter((_, j) => j !== i) })}>
                                    <Trash2 />
                                </IconButton>
                            </div>
                            <PartEditor part={part} onChange={(next) => setPart(i, next)} />
                        </div>
                    ))}
                    <div className="flex items-center gap-2">
                        <Choice
                            value={adding}
                            onChange={(k) => setAdding(k as FormPart['kind'])}
                            options={(Object.keys(PART_LABELS) as FormPart['kind'][]).map((k) => [k, t(PART_LABELS[k])])}
                            label={t('نوع الجزء')}
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            data-testid={`add-part-${index}`}
                            onClick={() => onChange({ ...section, parts: [...section.parts, newPart(adding, form)] })}
                        >
                            <Plus />
                            {t('إضافة جزء')}
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}

/** محرّرُ الجزء بنوعه — حقولُه كما يقرؤها الخادم */
function PartEditor({ part, onChange }: { part: FormPart; onChange: (next: FormPart) => void }) {
    const t = useTranslate();
    switch (part.kind) {
        case 'p':
        case 'note':
            return <Textarea value={part.text} onChange={(e) => onChange({ ...part, text: e.target.value })} rows={3} aria-label={t('النص')} />;
        case 'list':
            return <Lines value={part.items} onChange={(items) => onChange({ ...part, items })} label={t('البنود — بند في كل سطر')} />;
        case 'amount':
            return <Input value={part.label} onChange={(e) => onChange({ ...part, label: e.target.value })} placeholder={t('بيان المبلغ')} aria-label={t('بيان المبلغ')} />;
        case 'cols':
            return (
                <div className="space-y-2">
                    {part.cols.map((c, i) => (
                        <div key={i} className="flex items-start gap-2">
                            <div className="flex-1 space-y-1.5">
                                <Input
                                    value={c.head}
                                    onChange={(e) => onChange({ ...part, cols: part.cols.map((x, j) => (j === i ? { ...x, head: e.target.value } : x)) })}
                                    placeholder={t('عنوان العمود')}
                                    aria-label={t('عنوان العمود')}
                                />
                                <Lines
                                    value={c.items}
                                    onChange={(items) => onChange({ ...part, cols: part.cols.map((x, j) => (j === i ? { ...x, items } : x)) })}
                                    label={t('بنود العمود — بند في كل سطر')}
                                />
                            </div>
                            <IconButton label={t('حذف العمود')} disabled={part.cols.length === 1} onClick={() => onChange({ ...part, cols: part.cols.filter((_, j) => j !== i) })}>
                                <Trash2 />
                            </IconButton>
                        </div>
                    ))}
                    <Button type="button" variant="outline" size="sm" disabled={part.cols.length >= 6} onClick={() => onChange({ ...part, cols: [...part.cols, { head: '', items: [''] }] })}>
                        <Plus />
                        {t('عمود')}
                    </Button>
                </div>
            );
        case 'field':
            return (
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <Input value={part.label} onChange={(e) => onChange({ ...part, label: e.target.value })} placeholder={t('اسم الحقل')} aria-label={t('اسم الحقل')} />
                    <Input value={part.ph} onChange={(e) => onChange({ ...part, ph: e.target.value })} placeholder={t('تلميح داخل الحقل (اختياري)')} aria-label={t('التلميح')} />
                    <Choice
                        value={part.type}
                        onChange={(type) => onChange({ ...part, type })}
                        options={[
                            ['text', t('نص قصير')],
                            ['textarea', t('نص طويل')],
                            ['number', t('رقم')],
                            ['date', t('تاريخ')],
                            ['time', t('وقت')],
                            ['tel', t('هاتف')],
                            ['email', t('بريد إلكتروني')],
                        ]}
                        label={t('نوع الحقل')}
                    />
                    <Choice
                        value={part.dir}
                        onChange={(dir) => onChange({ ...part, dir })}
                        options={[
                            ['rtl', t('من اليمين')],
                            ['ltr', t('من اليسار')],
                        ]}
                        label={t('اتجاه الكتابة')}
                    />
                    <Check checked={part.req} onChange={(req) => onChange({ ...part, req })}>
                        {t('إلزامي — لا يُرسل التوقيع بدونه')}
                    </Check>
                    <KeyInput value={part.k} onChange={(k) => onChange({ ...part, k })} />
                </div>
            );
        case 'checks':
            return (
                <div className="space-y-2">
                    <Input value={part.head} onChange={(e) => onChange({ ...part, head: e.target.value })} placeholder={t('السؤال أو العنوان')} aria-label={t('العنوان')} />
                    <Lines value={part.items} onChange={(items) => onChange({ ...part, items })} label={t('الخيارات — خيار في كل سطر')} />
                    <KeyInput value={part.k} onChange={(k) => onChange({ ...part, k })} />
                </div>
            );
        case 'yesno':
            return (
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <Input value={part.label} onChange={(e) => onChange({ ...part, label: e.target.value })} placeholder={t('السؤال')} aria-label={t('السؤال')} />
                    <KeyInput value={part.k} onChange={(k) => onChange({ ...part, k })} />
                </div>
            );
        case 'table':
            return <TableEditor part={part} onChange={onChange} />;
    }
}

/**
 * الجدول: صفوفٌ بأسمائها، وأعمدةٌ لكلٍّ منها عنوانٌ ونوعٌ وتلميح بموضعه — العنوانُ
 * الأوّل لعمود أسماء الصفوف. والاختيارُ من قائمةٍ واحدةٍ للجدول (`picks`).
 */
function TableEditor({ part, onChange }: { part: Extract<FormPart, { kind: 'table' }>; onChange: (next: FormPart) => void }) {
    const t = useTranslate();
    const setCol = (i: number, patch: { head?: string; type?: string; ph?: string }) =>
        onChange({
            ...part,
            head: part.head.map((h, j) => (j === i + 1 && patch.head !== undefined ? patch.head : h)),
            types: part.types.map((x, j) => (j === i && patch.type !== undefined ? patch.type : x)),
            ph: part.ph.map((x, j) => (j === i && patch.ph !== undefined ? patch.ph : x)),
        });
    const removeCol = (i: number) =>
        onChange({
            ...part,
            head: part.head.filter((_, j) => j !== i + 1),
            types: part.types.filter((_, j) => j !== i),
            ph: part.ph.filter((_, j) => j !== i),
        });

    return (
        <div className="space-y-2" data-testid="table-editor">
            <Input
                value={part.head[0] ?? ''}
                onChange={(e) => onChange({ ...part, head: [e.target.value, ...part.head.slice(1)] })}
                placeholder={t('عنوان عمود الصفوف')}
                aria-label={t('عنوان عمود الصفوف')}
            />
            {part.types.map((type, i) => (
                <div key={i} className="flex items-center gap-2">
                    <span className="w-5 shrink-0 text-[12px] text-[#9ca3af]">{i + 1}</span>
                    <Input value={part.head[i + 1] ?? ''} onChange={(e) => setCol(i, { head: e.target.value })} placeholder={t('عنوان العمود')} aria-label={t('عنوان العمود :n', { n: String(i + 1) })} />
                    <Choice
                        value={type}
                        onChange={(v) => setCol(i, { type: v })}
                        options={[
                            ['text', t('نص')],
                            ['check', t('تأشير')],
                            ['pick', t('اختيار من قائمة')],
                        ]}
                        label={t('نوع العمود')}
                    />
                    <Input value={part.ph[i] ?? ''} onChange={(e) => setCol(i, { ph: e.target.value })} placeholder={t('تلميح')} aria-label={t('تلميح العمود')} className="w-32" />
                    <IconButton label={t('حذف العمود')} disabled={part.types.length === 1} onClick={() => removeCol(i)}>
                        <Trash2 />
                    </IconButton>
                </div>
            ))}
            <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={part.types.length >= 8}
                onClick={() => onChange({ ...part, head: [...part.head, ''], types: [...part.types, 'text'], ph: [...part.ph, ''] })}
            >
                <Plus />
                {t('عمود')}
            </Button>
            <Lines value={part.rows} onChange={(rows) => onChange({ ...part, rows })} label={t('الصفوف — صف في كل سطر')} />
            {part.types.includes('pick') && <Lines value={part.picks} onChange={(picks) => onChange({ ...part, picks })} label={t('خيارات الاختيار — خيار في كل سطر')} />}
            <KeyInput value={part.k} onChange={(k) => onChange({ ...part, k })} />
        </div>
    );
}

function Block({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div className="space-y-2 rounded-[12px] border border-[#e7e8ea] p-3">
            <div className="text-[13px] font-medium text-[#111]">{title}</div>
            {children}
        </div>
    );
}

/** أسطرٌ في مربّع — بندٌ في كلّ سطر، والفارغُ يُترك في الخادم */
function Lines({ value, onChange, label }: { value: string[]; onChange: (next: string[]) => void; label: string }) {
    return (
        <label className="block space-y-1">
            <span className="text-[11.5px] text-[#6b7280]">{label}</span>
            <Textarea value={value.join('\n')} onChange={(e) => onChange(e.target.value.split('\n'))} rows={Math.min(8, Math.max(2, value.length))} />
        </label>
    );
}

/** مفتاحُ الإدخال — به تُحفظ إجاباتُه. تغييرُه بعد إصدار مستندٍ لا يمسّ ذلك المستند */
function KeyInput({ value, onChange }: { value: string; onChange: (next: string) => void }) {
    const t = useTranslate();
    return (
        <label className="block space-y-1">
            <span className="text-[11.5px] text-[#6b7280]">{t('مفتاح الإدخال (حروف إنجليزية وأرقام)')}</span>
            <Input dir="ltr" value={value} onChange={(e) => onChange(e.target.value.trim())} aria-label={t('مفتاح الإدخال')} />
        </label>
    );
}

function Choice({ value, onChange, options, label }: { value: string; onChange: (next: string) => void; options: [string, string][]; label: string }) {
    return (
        <select
            value={value}
            onChange={(e) => onChange(e.target.value)}
            aria-label={label}
            className="h-9 rounded-[10px] border border-[#e8e8e8] bg-white px-2 text-[12.5px]"
        >
            {options.map(([v, l]) => (
                <option key={v} value={v}>
                    {l}
                </option>
            ))}
        </select>
    );
}

function Check({ checked, onChange, children }: { checked: boolean; onChange: (next: boolean) => void; children: ReactNode }) {
    return (
        <label className="flex items-center gap-2 text-[12.5px] text-[#374151]">
            <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} />
            {children}
        </label>
    );
}

function IconButton({ label, onClick, disabled, children }: { label: string; onClick: () => void; disabled?: boolean; children: ReactNode }) {
    return (
        <Button type="button" variant="ghost" size="icon-sm" disabled={disabled} onClick={onClick} aria-label={label}>
            {children}
        </Button>
    );
}
