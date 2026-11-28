import { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { MessageCircle } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type InboxLevel, OPTIONS } from './InboxAccessField';

/** ما يرسله الخادم — أو لا شيء لمن لا يمنح (`EmployeeController::inboxRoster`) */
export interface InboxRoster {
    staff: { id: number; name: string; job_title: string | null; active: boolean; level: InboxLevel }[];
}

/**
 * محادثاتُ واتساب لموظّفي المتجر — من شاشةٍ واحدة لا من ملفّ كلِّ موظّف.
 *
 * لكلّ موظّفٍ مستوياتُه الثلاثة كما في ملفّه (`InboxAccessField`)، ويُحدَّد
 * عدّةُ موظّفين ويُعطَون مستوًى واحدًا بضغطة. ولا يخرج إلّا ما تبدّل: حفظٌ
 * من شاشةٍ قديمة لا يعيد صلاحيةً غيّرها غيرُه بعدها. والسحبُ يسري فورًا —
 * ومن كانت المحادثاتُ مفتوحةً أمامه تُغلق في سؤالها التالي.
 *
 * وصاحبُ النشاط ليس فيها، ولا من يمنح نفسُه: الأوّلُ يملكها بدوره، ولا
 * يُعدِّل أحدٌ صلاحيته (`EmployeeController::inboxAccess`).
 */
export default function InboxAccessRoster({ roster }: { roster: InboxRoster }) {
    const t = useTranslate();
    const [levels, setLevels] = useState<Record<number, InboxLevel>>(() => Object.fromEntries(roster.staff.map((s) => [s.id, s.level])));
    const [picked, setPicked] = useState<number[]>([]);
    const [saving, setSaving] = useState(false);

    const changed = useMemo(() => roster.staff.filter((s) => levels[s.id] !== s.level), [roster.staff, levels]);
    const allPicked = roster.staff.length > 0 && picked.length === roster.staff.length;

    const save = () => {
        if (changed.length === 0 || saving) return;

        router.post(
            route('admin.employees.inboxAccess'),
            { levels: Object.fromEntries(changed.map((s) => [s.id, levels[s.id]])) },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => setPicked([]),
            },
        );
    };

    return (
        <Card className="mt-6 p-5" data-testid="inbox-roster">
            <div className="flex items-start gap-3">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-[#e7fce3] text-[#008069]">
                    <MessageCircle className="size-4" />
                </span>
                <div className="min-w-0 flex-1">
                    <h2 className="text-[15px] font-bold text-[#111]">{t('محادثات واتساب لموظفيك')}</h2>
                    <p className="mt-0.5 text-[12.5px] leading-relaxed text-[#6b7280]">
                        {t('صندوق واحد مشترك: كل من تمنحه يرى المحادثات نفسها، وكل رد يخرج من رقم متجرك باسم كاتبه. أنت تملكها دائمًا، ولا تُمنح مع الوظيفة.')}
                    </p>
                </div>
            </div>

            {roster.staff.length === 0 ? (
                <p className="mt-4 text-[13px] text-[#6b7280]" data-testid="inbox-roster-empty">
                    {t('لا موظفين بعد — اختر صلاحية المحادثات لكل موظف جديد عند إضافته.')}
                </p>
            ) : (
                <>
                    <div className="mt-4 flex flex-wrap items-center gap-2 border-b border-[#f0f0f0] pb-3 text-[12.5px]">
                        <label className="inline-flex items-center gap-2 text-[#374151]">
                            <input
                                type="checkbox"
                                checked={allPicked}
                                onChange={() => setPicked(allPicked ? [] : roster.staff.map((s) => s.id))}
                                data-testid="inbox-roster-all"
                            />
                            {t('تحديد الكل')}
                        </label>
                        <span className="ms-auto text-[#6b7280]">{t('اجعل المحدَّدين:')}</span>
                        {OPTIONS.map((o) => (
                            <Button
                                key={o.key}
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={picked.length === 0}
                                onClick={() => setLevels((l) => ({ ...l, ...Object.fromEntries(picked.map((id) => [id, o.key])) }))}
                                data-testid={`inbox-roster-bulk-${o.key}`}
                            >
                                {t(o.title)}
                            </Button>
                        ))}
                    </div>

                    <ul className="divide-y divide-[#f0f0f0]">
                        {roster.staff.map((s) => (
                            <li key={s.id} className="flex flex-wrap items-center gap-3 py-2.5" data-testid="inbox-roster-row">
                                <input
                                    type="checkbox"
                                    checked={picked.includes(s.id)}
                                    onChange={() => setPicked((p) => (p.includes(s.id) ? p.filter((x) => x !== s.id) : [...p, s.id]))}
                                    aria-label={s.name}
                                />
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[14px] font-medium text-[#111]">{s.name}</span>
                                    <span className="block text-[12px] text-[#6b7280]">
                                        {s.job_title ?? '—'}
                                        {!s.active && ` · ${t('موقوف')}`}
                                    </span>
                                </span>
                                <span className="inline-flex overflow-hidden rounded-[8px] border border-[#e5e7eb]" role="radiogroup" aria-label={t('محادثات واتساب')}>
                                    {OPTIONS.map((o) => {
                                        const on = levels[s.id] === o.key;

                                        return (
                                            <button
                                                key={o.key}
                                                type="button"
                                                role="radio"
                                                aria-checked={on}
                                                title={t(o.hint)}
                                                onClick={() => setLevels((l) => ({ ...l, [s.id]: o.key }))}
                                                data-testid={`inbox-roster-${s.id}-${o.key}`}
                                                className={cn(
                                                    'px-2.5 py-1 text-[12px] transition-colors',
                                                    on ? 'bg-[#111] text-white' : 'bg-white text-[#374151] hover:bg-[#f9fafb]',
                                                )}
                                            >
                                                {t(o.title)}
                                            </button>
                                        );
                                    })}
                                </span>
                            </li>
                        ))}
                    </ul>

                    <div className="mt-3 flex items-center justify-end gap-3">
                        {changed.length > 0 && (
                            <span className="text-[12px] text-[#6b7280]" data-testid="inbox-roster-changed">
                                {t('تغييرات لم تُحفظ: :n', { n: changed.length })}
                            </span>
                        )}
                        <Button type="button" onClick={save} loading={saving} disabled={changed.length === 0} data-testid="inbox-roster-save">
                            {t('حفظ صلاحيات المحادثات')}
                        </Button>
                    </div>
                </>
            )}
        </Card>
    );
}
