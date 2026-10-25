import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { formInputs, type DocumentForm, type FormInput, type FormPart, type FormValues } from '@/lib/platformDocuments';

const BLANK = '______';

/**
 * نموذجُ المستند للقراءة — أقسامُه بنصّها، وما كتبه الفريقُ والعميلُ في إدخالاته.
 * في صفحة المستند بعد التوقيع، وفي معاينة القالب قبل الإصدار (بلا قيم).
 */
export function DocumentFormView({ form, team = {}, answers = {} }: { form: DocumentForm; team?: FormValues; answers?: FormValues }) {
    const t = useTranslate();
    const valueOf = (p: FormInput) => (p.by === 'team' ? team[p.k] : answers[p.k]);

    return (
        <div className="space-y-4 text-[13px] leading-relaxed text-[#374151]" data-testid="document-form">
            {form.intro && <p>{form.intro}</p>}
            {form.highlights.length > 0 && (
                <div className="grid gap-2 sm:grid-cols-3">
                    {form.highlights.map((h) => (
                        <div key={h.label} className="rounded-[10px] bg-[#f6f6f5] px-3 py-2">
                            <div className="text-[11.5px] text-[#6b7280]">{h.label}</div>
                            <div className="text-[#111]">{h.text}</div>
                        </div>
                    ))}
                </div>
            )}
            {form.sections.map((s, si) => (
                <section key={si} className="space-y-2" data-testid="form-section">
                    <h3 className="text-[13.5px] font-semibold text-[#111]">
                        <span className="text-[#9ca3af]">{String(si + 1).padStart(2, '0')} — </span>
                        {s.title}
                    </h3>
                    {s.after && <div className="text-[12px] text-[#6b7280]">{t('يُعبّأ هذا القسم بعد تنفيذ الخدمة')}</div>}
                    {s.parts.map((p, pi) => (
                        <Part key={pi} part={p} value={s.after || !('by' in p) ? undefined : valueOf(p as FormInput)} />
                    ))}
                </section>
            ))}
        </div>
    );
}

function Part({ part, value }: { part: FormPart; value: FormValues[string] | undefined }) {
    const t = useTranslate();
    switch (part.kind) {
        case 'p':
            return <p>{part.text}</p>;
        case 'list':
            return (
                <ul className="list-disc space-y-0.5 ps-5">
                    {part.items.map((it, i) => (
                        <li key={i}>{it}</li>
                    ))}
                </ul>
            );
        case 'note':
            return <div className="rounded-[8px] bg-[#f6f6f5] px-3 py-2 text-[12.5px]">{part.text}</div>;
        case 'cols':
            return (
                <div className="grid gap-2 sm:grid-cols-2">
                    {part.cols.map((c, i) => (
                        <div key={i} className="rounded-[8px] border border-[#e7e8ea] px-3 py-2">
                            {c.head && <div className="mb-1 font-semibold text-[#111]">{c.head}</div>}
                            <ul className="list-disc space-y-0.5 ps-5">
                                {c.items.map((it, j) => (
                                    <li key={j}>{it}</li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
            );
        case 'amount':
            return <div className="text-[12.5px] text-[#6b7280]">{t('مبلغ المستند: :label', { label: part.label })}</div>;
        case 'field':
            return (
                <div className="flex flex-wrap justify-between gap-x-3 border-b border-dashed border-[#e7e8ea] pb-1">
                    <span className="text-[#6b7280]">{part.label}</span>
                    <span dir={part.dir === 'ltr' ? 'ltr' : undefined} className="text-[#111]" data-testid={`value-${part.k}`}>
                        {typeof value === 'string' && value !== '' ? value : BLANK}
                    </span>
                </div>
            );
        case 'checks': {
            const on = Array.isArray(value) ? (value as number[]).map(Number) : [];
            return (
                <div>
                    {part.head && <div className="font-medium text-[#111]">{part.head}</div>}
                    <ul className="space-y-0.5">
                        {part.items.map((it, i) => (
                            <li key={i} className="flex gap-2">
                                <span className="w-4 shrink-0 text-center">{on.includes(i) ? '☑' : '☐'}</span>
                                <span>{it}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            );
        }
        case 'table': {
            const cells = Array.isArray(value) ? (value as string[][]) : [];
            return (
                <div className="overflow-x-auto no-scrollbar">
                    <table className="w-full min-w-[420px] border-collapse text-[12px]">
                        <thead>
                            <tr>
                                {part.head.map((h) => (
                                    <th key={h} className="border border-[#e7e8ea] bg-[#f6f6f5] px-2 py-1 text-start font-medium">
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {part.rows.map((row, r) => (
                                <tr key={r}>
                                    <td className="border border-[#e7e8ea] px-2 py-1">{row}</td>
                                    {part.types.map((type, c) => {
                                        const cell = cells[r]?.[c] ?? '';
                                        return (
                                            <td key={c} className="border border-[#e7e8ea] px-2 py-1">
                                                {type === 'check' ? (cell === '1' ? '☑' : '☐') : cell}
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            );
        }
        case 'yesno':
            return (
                <div>
                    {part.label}: {value === 'yes' ? t('نعم') : value === 'no' ? t('لا') : BLANK}
                </div>
            );
    }
}

/**
 * حقولُ فريق أبعاد في نافذة الإصدار — اختياريّة: ما بقي فارغًا يُطبع سطرًا يُكتب عليه.
 * الحقولُ والجداولُ وحدها يعبّئها الفريقُ هنا؛ وقسمُ «بعد التنفيذ» لا يُعبّأ الآن.
 */
export function TeamInputs({ form, value, onChange }: { form: DocumentForm; value: FormValues; onChange: (next: FormValues) => void }) {
    const inputs = formInputs(form, 'team');
    const set = (k: string, v: FormValues[string]) => onChange({ ...value, [k]: v });
    let lastSection = '';

    return (
        <div className="space-y-3" data-testid="team-inputs">
            {inputs.map(({ section, part }) => {
                const head = section !== lastSection ? section : null;
                lastSection = section;
                return (
                    <div key={part.k} className="space-y-1.5">
                        {head && <div className="pt-1 text-[12.5px] font-semibold text-[#111]">{head}</div>}
                        {part.kind === 'field' && (
                            <label className="block text-[12.5px] text-[#6b7280]">
                                {part.label}
                                <Input
                                    className="mt-1"
                                    dir={part.dir === 'ltr' ? 'ltr' : undefined}
                                    type={['date', 'number', 'time'].includes(part.type) ? part.type : 'text'}
                                    value={typeof value[part.k] === 'string' ? (value[part.k] as string) : ''}
                                    onChange={(e) => set(part.k, e.target.value)}
                                    data-testid={`team-${part.k}`}
                                />
                            </label>
                        )}
                        {part.kind === 'table' && (
                            <TeamTable part={part} value={(value[part.k] as string[][] | undefined) ?? []} onChange={(v) => set(part.k, v)} />
                        )}
                    </div>
                );
            })}
        </div>
    );
}

function TeamTable({ part, value, onChange }: { part: Extract<FormInput, { kind: 'table' }>; value: string[][]; onChange: (v: string[][]) => void }) {
    const setCell = (r: number, c: number, v: string) => {
        const next = part.rows.map((_, i) => part.types.map((__, j) => value[i]?.[j] ?? ''));
        next[r][c] = v;
        onChange(next);
    };

    return (
        <div className="overflow-x-auto no-scrollbar">
            <table className="w-full min-w-[420px] border-collapse text-[12px]" data-testid={`team-${part.k}`}>
                <thead>
                    <tr>
                        {part.head.map((h) => (
                            <th key={h} className="border border-[#e7e8ea] bg-[#f6f6f5] px-2 py-1 text-start font-medium">
                                {h}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {part.rows.map((row, r) => (
                        <tr key={r}>
                            <td className="border border-[#e7e8ea] px-2 py-1">{row}</td>
                            {part.types.map((type, c) => {
                                const cell = value[r]?.[c] ?? '';
                                return (
                                    <td key={c} className="border border-[#e7e8ea] px-1 py-1">
                                        {type === 'check' ? (
                                            <input type="checkbox" checked={cell === '1'} onChange={(e) => setCell(r, c, e.target.checked ? '1' : '')} />
                                        ) : type === 'pick' ? (
                                            <select
                                                value={cell}
                                                onChange={(e) => setCell(r, c, e.target.value)}
                                                className="w-full rounded-[6px] border border-[#e7e8ea] bg-white px-1 py-1"
                                            >
                                                <option value="" />
                                                {part.picks.map((pick) => (
                                                    <option key={pick} value={pick}>
                                                        {pick}
                                                    </option>
                                                ))}
                                            </select>
                                        ) : (
                                            // `Input` لا حقلٌ خام: رقمٌ يُكتب بلوحةٍ عربيّة في خليّةٍ يُحفظ لاتينيًّا كبقيّة الحقول
                                            <Input
                                                value={cell}
                                                onChange={(e) => setCell(r, c, e.target.value)}
                                                placeholder={part.ph[c] ?? ''}
                                                className="h-8 rounded-[6px] px-2 py-1 text-[12px] touch:h-9"
                                                data-testid={`team-${part.k}-${r}-${c}`}
                                            />
                                        )}
                                    </td>
                                );
                            })}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
