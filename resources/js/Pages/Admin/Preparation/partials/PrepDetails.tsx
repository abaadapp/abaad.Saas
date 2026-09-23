import { useEffect, useState } from 'react';
import { Clock, Gift, History, MapPin, Paperclip, Phone, Printer, StickyNote, Store, Truck, User } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { number as fmt } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { checklistKeys, TASK_LABELS, type PrepOrder } from './types';

/**
 * تفاصيلُ التجهيز — كلُّ ما كان على البطاقة، ومعه ما لم يكن.
 *
 * ═══ ولا حقلَ سقط ═══
 *
 * الأصنافُ وصورُها ومقاساتُها وكمّياتُها، الإضافات، المكوّنات، حقولُ الطلب
 * المخصَّص كما حُفظت يوم البيع، ملاحظاتُ البنود، ملاحظاتُ التوصيل والداخليّة،
 * المناسبة، بطاقةُ الإهداء، اسمُ المُهدي وإخفاؤه عن المستلِم، المستلِمُ
 * وهاتفُه وعنوانُه والفرعُ والموعد. كلُّها كانت على البطاقة وصارت هنا.
 *
 * والفرعُ زيادة: كان يُرسَل من الخادم ولا يُرسَم في موضع — متجرٌ بثلاثة فروع
 * تُعرض لوحتُه كلُّها معًا، ومن يجهّز لا يعرف إلى أيّ فرعٍ يُسلّم.
 *
 * ═══ والنافذة تُدار باللوحة ═══
 *
 * `Dialog` المبنيّ على Radix: حبسُ البؤرة، والخروجُ بـEscape، وإعادةُ البؤرة
 * إلى الزرّ الذي فتحها — بلا سطرٍ يُكتب هنا. ومربّعاتُ التحقّق `input` حقيقيّة
 * لا `div` يُضغط: المسافةُ تقلبها والقارئُ الصوتيّ يقول حالَها.
 */
interface Timeline {
    text: string;
    by: string | null;
    at: string;
}

interface Props {
    order: PrepOrder | null;
    /** فُتحت لطلبٍ لم يعد على اللوحة — نقله زميلٌ أو أُغلق */
    gone: boolean;
    busy?: boolean;
    onClose: () => void;
    onMove: (status: string) => void;
    onToggle: (key: string, checked: boolean) => void;
    deliveryNoteUrl: (number: string) => string;
    timelineUrl: (number: string) => string;
}

export default function PrepDetails({
    order: o,
    gone,
    busy,
    onClose,
    onMove,
    onToggle,
    deliveryNoteUrl,
    timelineUrl,
}: Props) {
    const t = useTranslate();
    const [events, setEvents] = useState<Timeline[] | null>(null);
    const [failed, setFailed] = useState(false);
    const number = o?.number ?? null;

    /*
     * الخطُّ الزمنيّ يُجلب عند الفتح لا مع اللوحة.
     *
     * مئتا بطاقةٍ تُعرض، وواحدةٌ تُفتح. فجلبُه للكلّ يعني مئتي استعلامٍ في كلّ
     * استطلاع لأجل سطورٍ لا تُقرأ.
     */
    useEffect(() => {
        if (!number) return;
        let alive = true;
        setEvents(null);
        setFailed(false);

        fetch(timelineUrl(number), { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error('http'))))
            .then((d: { events?: Timeline[] }) => {
                if (alive) setEvents(Array.isArray(d.events) ? d.events : []);
            })
            .catch(() => {
                if (alive) setFailed(true);
            });

        return () => {
            alive = false;
        };
    }, [number, timelineUrl]);

    if (!o) return null;

    const keys = checklistKeys(o);
    const done = keys.filter((k) => o.checks?.[k]).length;

    const box = (key: string, label: string, hint?: string) => {
        const mark = o.checks?.[key];

        return (
            <label
                key={key}
                className="flex cursor-pointer items-start gap-2.5 rounded-[10px] p-2 transition-colors hover:bg-[#fafafa]"
            >
                <input
                    type="checkbox"
                    checked={!! mark}
                    disabled={gone}
                    data-testid={`prep-check-${key}`}
                    onChange={(e) => onToggle(key, e.target.checked)}
                    className="mt-0.5 size-4 shrink-0 accent-[#047857]"
                />
                <span className="min-w-0 flex-1 text-sm">
                    <span className={cn(mark && 'text-[#6b7280] line-through')}>{label}</span>
                    {hint && <span className="block text-[12px] text-[#9ca3af]">{hint}</span>}
                    {/* من وضعها ومتى — يُسأل عنهما حين يُختلف، فيُقالان في موضعهما */}
                    {mark && (
                        <span className="block text-[11px] text-[#9ca3af]">
                            {mark.by ?? t('غير معروف')} · <span dir="ltr">{mark.at}</span>
                        </span>
                    )}
                </span>
            </label>
        );
    };

    return (
        <Dialog open onOpenChange={(open) => ! open && onClose()}>
            <DialogContent className="max-w-2xl p-0" data-testid="prep-details">
                <DialogHeader className="border-b border-[var(--ui-border,#e8e8e8)] p-5">
                    <DialogTitle className="flex flex-wrap items-center gap-2">
                        <span>{o.customer ?? t('بلا اسم')}</span>
                        <Badge status={o.status}>{t(o.status)}</Badge>
                        <span className="text-[12px] font-normal text-[#9ca3af]">{o.number}</span>
                    </DialogTitle>
                </DialogHeader>

                <div className="flex flex-col gap-5 p-5">
                    {gone && (
                        <p className="rounded-[10px] bg-[#fffbeb] p-3 text-[12px] text-[#92400e]">
                            {t('هذا الطلب لم يعد على اللوحة — نُقل أو أُغلق من شاشةٍ أخرى.')}
                        </p>
                    )}

                    {/* ═══ الموعد والوجهة ═══ */}
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-[12px] text-[#6b7280]">
                        <span className="flex items-center gap-1.5">
                            <Clock className="size-3.5" />
                            <span dir="ltr">{o.scheduled_for ?? '—'}</span>
                        </span>
                        {o.fulfillment && (
                            <span className="flex items-center gap-1.5">
                                {o.fulfillment === 'delivery' ? <Truck className="size-3.5" /> : <Store className="size-3.5" />}
                                {t(o.fulfillment === 'delivery' ? 'توصيل' : 'استلام من المحل')}
                            </span>
                        )}
                        {o.branch && (
                            <span className="flex items-center gap-1.5">
                                <Store className="size-3.5" />
                                {o.branch}
                            </span>
                        )}
                        {o.recipient && (
                            <span className="flex items-center gap-1.5">
                                <User className="size-3.5" />
                                {o.recipient}
                            </span>
                        )}
                        {o.recipient_phone && (
                            <span className="flex items-center gap-1.5" dir="ltr">
                                <Phone className="size-3.5" />
                                {o.recipient_phone}
                            </span>
                        )}
                        {o.occasion && <span>{t(o.occasion)}</span>}
                    </div>

                    {o.address && (
                        <p className="flex items-start gap-1.5 text-[12px] text-[#6b7280]">
                            <MapPin className="mt-0.5 size-3.5 shrink-0" />
                            {o.address}
                        </p>
                    )}

                    {/* ═══ قائمةُ التحقّق — وهي البنودُ نفسُها لا قائمةٌ ثانية ═══ */}
                    <section>
                        <h4 className="mb-2 flex items-center justify-between text-sm font-bold text-[#111]">
                            <span>{t('قائمة التجهيز')}</span>
                            <span className="text-[12px] font-normal tabular-nums text-[#6b7280]">
                                {done}/{keys.length}
                            </span>
                        </h4>
                        <div className="flex flex-col divide-y divide-[var(--ui-border,#e8e8e8)]">
                            {o.items.map((i) => (
                                <div key={i.id} className="py-1">
                                    <div className="flex items-start gap-2.5">
                                        {i.image ? (
                                            <img src={i.image} alt="" className="mt-2 size-9 shrink-0 rounded-[8px] object-cover" />
                                        ) : (
                                            <span className="mt-2 size-9 shrink-0 rounded-[8px] bg-gray-100" />
                                        )}
                                        <div className="min-w-0 flex-1">
                                            {box(`item:${i.id}`, `${i.name} ×${i.qty}`, i.note ?? undefined)}
                                            <div className="ms-6">
                                                {(i.addons ?? []).map((a) => (
                                                    <div key={a.id} className="text-[#7c3aed]">
                                                        {box(`addon:${a.id}`, `+ ${a.name}${a.qty > 1 ? ` ×${a.qty}` : ''}`)}
                                                    </div>
                                                ))}
                                            </div>
                                            {/*
                                              * والمكوّناتُ تُعرض ولا تُؤشَّر.
                                              *
                                              * هي موادُّ البند الواحد — ستُّ لفّاتِ ورقٍ وثلاثُ ورداتٍ
                                              * لباقةٍ واحدة. ومربّعٌ لكلٍّ منها يجعل قائمةَ التحقّق
                                              * أربعين سطرًا لطلبٍ فيه بندان، فلا تُقرأ أصلًا.
                                              */}
                                            {(i.components ?? []).length > 0 && (
                                                <ul className="ms-8 mt-1 text-[12px] text-[#047857]">
                                                    {(i.components ?? []).map((c, k) => (
                                                        <li key={k}>
                                                            {c.name} ×{fmt(c.qty, Number.isInteger(c.qty) ? 0 : 3)}
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                            {(i.custom?.fields ?? []).map((f, k) => (
                                                <p
                                                    key={k}
                                                    className={cn(
                                                        'ms-8 text-[12px]',
                                                        f.internal ? 'font-medium text-[#b45309]' : 'text-[#6b7280]',
                                                    )}
                                                >
                                                    {f.label}: {f.values.join(' + ')}
                                                </p>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            ))}
                            {Object.keys(TASK_LABELS).map((k) => (
                                <div key={k} className="py-1">{box(k, t(TASK_LABELS[k]))}</div>
                            ))}
                        </div>
                    </section>

                    {(o.card_message || o.card_file) && (
                        <div className="rounded-[10px] bg-[#fdf2f8] p-3 text-[12px] text-[#831843]">
                            <p className="mb-1 flex items-center gap-1.5 font-medium">
                                <Gift className="size-3.5" />
                                {t('بطاقة الإهداء')}
                            </p>
                            {/*
                                والنصُّ يُعرض مرتَّبًا كما رتّبه من دفع ثمنه.

                                `whitespace-pre-wrap` لأنّ أسطرَه جزءٌ ممّا كتب:
                                بيتُ شعرٍ في سطرين يُقرأ سطرًا واحدًا بلا هذا.
                            */}
                            {o.card_message && (
                                <p
                                    className="whitespace-pre-wrap leading-relaxed"
                                    style={{ textAlign: (o.card_align as 'right' | 'center' | 'left') || 'right' }}
                                >
                                    {o.card_message}
                                </p>
                            )}
                            {o.card_file && (
                                <a
                                    href={o.card_file}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="mt-1.5 inline-flex items-center gap-1.5 font-medium text-[#9d174d] underline"
                                >
                                    <Paperclip className="size-3.5" />
                                    {o.card_file_name || t('ملفٌّ مرفق')}
                                </a>
                            )}
                            {o.sender && (
                                <p className="mt-1 text-[#9d174d]">
                                    {t('من')}: {o.sender}
                                    {o.hide_sender && ` · ${t('مخفيّ عن المستلِم')}`}
                                </p>
                            )}
                        </div>
                    )}

                    {(o.delivery_notes || o.internal_notes) && (
                        <div className="rounded-[10px] bg-gray-50 p-3 text-[12px] text-[#6b7280]">
                            <p className="mb-1 flex items-center gap-1.5 font-medium text-[#4b4b4b]">
                                <StickyNote className="size-3.5" />
                                {t('ملاحظات')}
                            </p>
                            {o.delivery_notes && <p>{o.delivery_notes}</p>}
                            {o.internal_notes && <p className="mt-1">{o.internal_notes}</p>}
                        </div>
                    )}

                    {/* ═══ خطُّ الحال — من سجلّ النشاط، وما لم يُسجَّل لا يُخترع ═══ */}
                    <section>
                        <h4 className="mb-2 flex items-center gap-1.5 text-sm font-bold text-[#111]">
                            <History className="size-4" />
                            {t('سجل الحركة')}
                        </h4>
                        {failed ? (
                            <p className="text-[12px] text-[#b91c1c]">{t('تعذّر جلب سجل الحركة.')}</p>
                        ) : events === null ? (
                            <p className="text-[12px] text-[#9ca3af]">{t('جارٍ التحميل…')}</p>
                        ) : events.length === 0 ? (
                            <p className="text-[12px] text-[#9ca3af]">
                                {t('لا حركات مسجّلة لهذا الطلب.')}
                            </p>
                        ) : (
                            <ol data-testid="prep-timeline" className="flex flex-col gap-2 text-[12px]">
                                {events.map((e, k) => (
                                    <li key={k} className="flex flex-wrap items-baseline gap-x-2 text-[#6b7280]">
                                        <span dir="ltr" className="tabular-nums text-[#9ca3af]">{e.at}</span>
                                        <span className="text-[#111]">{e.text}</span>
                                        {e.by && <span className="text-[#9ca3af]">— {e.by}</span>}
                                    </li>
                                ))}
                            </ol>
                        )}
                    </section>
                </div>

                <div className="flex flex-wrap gap-2 border-t border-[var(--ui-border,#e8e8e8)] p-5">
                    <Button variant="outline" size="sm" asChild>
                        <a href={deliveryNoteUrl(o.number)} target="_blank" rel="noreferrer">
                            <Printer className="size-3.5" />
                            {t('سند تسليم')}
                        </a>
                    </Button>
                    {o.next.map((s) => (
                        <Button key={s} variant="outline" size="sm" disabled={busy || gone} onClick={() => onMove(s)}>
                            {t(s)}
                        </Button>
                    ))}
                </div>
            </DialogContent>
        </Dialog>
    );
}
