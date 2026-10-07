import { usePage } from '@inertiajs/react';
import { AlertTriangle, CheckSquare, Clock, Gift, ListChecks, Package, Phone, Store, StickyNote, Truck, Wallet } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import { minutesLeft, spanOf, urgencyOf } from './schedule';
import { checkProgress, type PrepOrder } from './types';

/**
 * بطاقةُ الطاولة — مختصرةٌ تُقرأ من بعيد.
 *
 * ═══ لمَ اختُصرت ═══
 *
 * كانت تحمل كلَّ شيء: الأصناف ومكوّناتِها وخياراتِ الطلب المخصَّص ونصَّ
 * البطاقة والملاحظات. فبطاقةُ طلبٍ فيه خمسةُ أصناف تطول شاشةً كاملة، وثلاثُ
 * بطاقاتٍ تملأ اللوحة — ومن يقف عند الطاولة يسأل أوّلًا «ما التالي؟» لا «ما
 * في هذا الطلب؟». فصار الجوابُ الأوّل على البطاقة، والثاني خلف زرّ.
 *
 * ولم يسقط حقلٌ واحد: كلُّ ما كان هنا انتقل إلى `PrepDetails` — انظرها.
 *
 * ═══ والإلحاح لا يُقال بلونٍ وحده ═══
 *
 * ثُمنُ الرجال لا يميّز الأحمر من الأخضر، وشاشةُ الطاولة تُقرأ تحت ضوءٍ
 * ساطع. فالمتأخّرُ له أيقونةٌ وكلمةٌ وحدٌّ جانبيّ — ثلاثُ إشاراتٍ لا لون.
 */
interface Props {
    order: PrepOrder;
    /** عمرُ الحمولة بالميلّي ثانية — منه تُحسب البقيّة بلا تفسير تاريخ */
    ageMs: number;
    /** وصل بعد أن فُتحت الشاشة — يُوسَم حتى يُقرّ من يجهّز أنّه رآه */
    fresh?: boolean;
    /** نقلٌ جارٍ على هذا الطلب — تُعطَّل أزرارُه حتى يصل الجواب */
    busy?: boolean;
    onOpen: () => void;
    onMove: (status: string) => void;
}

/** أسماءُ الأصناف على البطاقة — وما زاد خلف «تفاصيل التجهيز» */
const ITEMS_ON_CARD = 4;

export default function PrepCard({ order: o, ageMs, fresh, busy, onOpen, onMove }: Props) {
    const t = useTranslate();
    const currency = usePage<PageProps>().props.context?.currency;
    const m = (v: number) => (currency ? money(v, currency) : String(v));
    const left = minutesLeft(o.scheduled, ageMs);
    const urgency = urgencyOf(left);
    const span = spanOf(left);
    const progress = checkProgress(o);
    const items = o.items.reduce((sum, i) => sum + i.qty, 0);

    /*
     * أبرزُ ملاحظةٍ واحدة — وبقيّتُها خلف الزرّ.
     *
     * والداخليّةُ أوّلًا: هي ما يكتبه صاحبُ المحلّ لمن يجهّز («زبونٌ مهمّ،
     * غلّفها في العلبة الفاخرة»). ثمّ ملاحظةُ أوّل بندٍ تحملها — وهي أوجبُ ما
     * يُقرأ عند الطاولة: الاسمُ يقول ماذا، والملاحظةُ تقول كيف.
     *
     * وملاحظةُ الطلب العامّة بعد الداخليّة وقبل ملاحظة البند: هي ما كُتب
     * على الطلب كلِّه، فتسبق ما كُتب على بندٍ واحد منه.
     */
    const itemNote = o.items.find((i) => i.note && !i.card_line)?.note ?? null;
    const note = o.internal_notes || o.order_notes || itemNote || o.delivery_notes || null;
    // واسمُ الملاحظة معها — لا يُخمَّن مصدرُها على البطاقة
    const noteKind = o.internal_notes
        ? 'ملاحظات داخلية'
        : o.order_notes
          ? 'ملاحظات الطلب'
          : itemNote
            ? 'ملاحظة المنتج'
            : 'تعليمات التوصيل';
    const notes = [o.internal_notes, o.order_notes, o.delivery_notes, ...o.items.map((i) => i.note)].filter(Boolean);

    return (
        <Card
            data-testid="prep-card"
            data-number={o.number}
            data-urgency={urgency}
            className={cn(
                'flex flex-col gap-3 p-4',
                // الحدُّ الجانبيّ إشارةٌ ثالثةٌ بعد الأيقونة والكلمة — لا وحدَها
                urgency === 'late' && 'border-s-4 border-s-[#b91c1c]',
                urgency === 'soon' && 'border-s-4 border-s-[#b45309]',
                fresh && 'ring-2 ring-[#7c3aed]/40',
            )}
        >
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="truncate font-bold text-[#111]">{o.customer ?? t('بلا اسم')}</p>
                    <p className="mt-0.5 text-[12px] text-[#9ca3af]">{o.number}</p>
                    {/* هاتفُ صاحب الطلب — يُتّصل به من البطاقة بلا فتح شيء */}
                    {o.customer_phone && (
                        <a
                            href={`tel:${o.customer_phone}`}
                            data-testid="prep-customer-phone"
                            className="mt-0.5 flex items-center gap-1 text-[12px] text-[#4b4b4b]"
                            dir="ltr"
                        >
                            <Phone className="size-3" />
                            {o.customer_phone}
                        </a>
                    )}
                </div>
                <div className="flex shrink-0 flex-col items-end gap-1.5">
                    <Badge status={o.status}>{t(o.status)}</Badge>
                    {fresh && (
                        <span className="text-[11px] font-medium text-[#7c3aed]">{t('جديد')}</span>
                    )}
                </div>
            </div>

            {/*
              * الموعدُ سطرٌ واحد: متى، وكم بقي أو كم تأخّر.
              *
              * والكامل بجانبه لا بدلَه — «خلال ٤٥ د» وحدها لا تكفي من يرتّب
              * عملَ يومه، و«٢٠٢٦-٠٩-٢٣ ١٤:٠٠» وحدها لا تقول إن كان قريبًا.
              */}
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px]">
                <span className="flex items-center gap-1.5 text-[#6b7280]">
                    <Clock className="size-3.5 shrink-0" />
                    <span>
                        {o.scheduled?.day ? t({ today: 'اليوم', tomorrow: 'غدًا', yesterday: 'أمس' }[o.scheduled.day]) : o.scheduled?.date}
                        {o.scheduled && ' · '}
                        <span dir="ltr">{o.scheduled?.time ?? '—'}</span>
                    </span>
                </span>
                {span && urgency !== 'later' && (
                    <span
                        data-testid="prep-span"
                        className={cn(
                            'flex items-center gap-1 font-medium',
                            urgency === 'late' ? 'text-[#b91c1c]' : 'text-[#b45309]',
                        )}
                    >
                        <AlertTriangle className="size-3.5 shrink-0" />
                        {span.key === 'late'
                            ? t('متأخّر :h س :m د', { h: span.hours, m: span.minutes })
                            : t('خلال :h س :m د', { h: span.hours, m: span.minutes })}
                    </span>
                )}
            </div>

            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-[#6b7280]">
                {o.fulfillment && (
                    <span className="flex items-center gap-1">
                        {o.fulfillment === 'delivery' ? <Truck className="size-3.5" /> : <Store className="size-3.5" />}
                        {t(o.fulfillment === 'delivery' ? 'توصيل' : 'استلام من المحل')}
                    </span>
                )}
                <span className="flex items-center gap-1">
                    <Package className="size-3.5" />
                    {t(':n صنفًا', { n: items })}
                </span>
                {progress.total > 0 && (
                    <span
                        data-testid="prep-progress"
                        className={cn(
                            'flex items-center gap-1 tabular-nums',
                            progress.done === progress.total && 'font-medium text-[#047857]',
                        )}
                    >
                        <ListChecks className="size-3.5" />
                        {progress.done}/{progress.total}
                    </span>
                )}
                {/*
                  * وإشارةُ الكرت تُقال لمرفوعٍ كما تُقال لمكتوب.
                  *
                  * صار الكرتُ يُكتب أو يُرفع — واحدًا من اثنين لا كليهما. فمن
                  * رفع صورةً بخطّ يده لا `card_message` له أصلًا، وكانت
                  * الإشارةُ تُقاس به وحده: فتمرّ البطاقةُ على اللوحة بلا ما
                  * يقول إنّ معها كرتًا يُكتب، ولا يُفتح ما خلف الزرّ إلّا صدفة.
                  *
                  * وباقةٌ تخرج بلا كرتِها ليست سطرًا ناقصًا في شاشة.
                  */}
                {(o.card_message || o.card_file) && (
                    <span data-testid="prep-gift" className="flex items-center gap-1 text-[#9d174d]">
                        <Gift className="size-3.5" />
                        {t('بطاقة')}
                    </span>
                )}
            </div>

            {/*
              * أسماءُ الأصناف — من يقف عند الطاولة يعرف ما يجمع بلا فتح التفاصيل.
              * وأربعةٌ تكفي البطاقة، وما زاد يُقال عددُه.
              */}
            {o.items.length > 0 && (
                <ul data-testid="prep-items" className="flex flex-col gap-0.5 text-[13px] text-[#111]">
                    {o.items.slice(0, ITEMS_ON_CARD).map((i) => (
                        <li key={i.id} className="truncate">
                            {i.name} <span className="text-[#6b7280]">×{i.qty}</span>
                        </li>
                    ))}
                    {o.items.length > ITEMS_ON_CARD && (
                        <li className="text-[12px] text-[#6b7280]">
                            {t('+ :n أصناف أخرى', { n: o.items.length - ITEMS_ON_CARD })}
                        </li>
                    )}
                </ul>
            )}

            {/* ما يُحصَّل عند التسليم: الإجماليُّ وحالُ الدفع ووسيلتُه */}
            {o.total !== undefined && (
                <div data-testid="prep-money" className="flex flex-wrap items-center gap-2 text-[12px]">
                    <span className="flex items-center gap-1 font-bold tabular-nums text-[#111]">
                        <Wallet className="size-3.5" />
                        {m(o.total)}
                    </span>
                    {o.payment_status && <Badge status={o.payment_status}>{t(o.payment_status)}</Badge>}
                    {o.payment_method && <span className="text-[#6b7280]">{t(o.payment_method)}</span>}
                </div>
            )}

            {note && (
                <p className="flex items-start gap-1.5 rounded-[10px] bg-[#fffbeb] p-2.5 text-[12px] text-[#92400e]">
                    <StickyNote className="mt-0.5 size-3.5 shrink-0" />
                    <span className="line-clamp-2">
                        <span className="font-medium" data-testid="prep-card-note-kind">{t(noteKind)}:</span>{' '}
                        <span dir="auto">{note}</span>
                    </span>
                    {notes.length > 1 && (
                        <span className="shrink-0 font-medium">+{notes.length - 1}</span>
                    )}
                </p>
            )}

            <div className="flex flex-wrap gap-2 border-t border-[var(--ui-border,#e8e8e8)] pt-3">
                <Button variant="outline" size="sm" onClick={onOpen} data-testid="prep-open">
                    <CheckSquare className="size-3.5" />
                    {t('تفاصيل التجهيز')}
                </Button>
                {o.next.map((s) => (
                    <Button
                        key={s}
                        variant="outline"
                        size="sm"
                        disabled={busy}
                        onClick={() => onMove(s)}
                    >
                        {t(s)}
                    </Button>
                ))}
            </div>
        </Card>
    );
}
