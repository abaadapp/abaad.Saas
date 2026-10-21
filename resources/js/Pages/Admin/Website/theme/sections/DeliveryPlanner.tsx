import Field from '@/Components/Field';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { MAX_SLOTS, hasAny, type DeliveryPlan, type Slot } from './schedule';
import WeekWindows, { ClosureList } from './WeekWindows';

/**
 * جدولُ أيّام التسليم وفتراتِه — يومٌ يومٌ، والاستلامُ بجدول التوصيل أو بجدوله.
 *
 * يُحرَّر هنا ويُكتب في «الدفع والاستلام» (`Checkout`)، صاحبِ المفتاح: هذا
 * المكوّنُ يردّ الجدولَ كلَّه ولا يكتب في النموذج.
 *
 * والفترةُ الجديدة بلا معرّف: يعطيها الخادمُ معرّفَها حين يحفظ، ثمّ يبقى لها
 * ما بقيت مهما تبدّل اسمُها أو ساعتاها (`Store\DeliverySchedule`).
 */
const fresh = (): Slot => ({ id: '', from: '09:00', to: '12:00', ar: '', en: '' });

export default function DeliveryPlanner({
    plan,
    onChange,
    error,
    offersPickup,
    offersDelivery,
}: {
    plan: DeliveryPlan;
    onChange: (plan: DeliveryPlan) => void;
    error?: string;
    offersPickup: boolean;
    offersDelivery: boolean;
}) {
    const t = useTranslate();
    const ownPickup = plan.pickup !== null;

    return (
        <div className="mt-4 space-y-5" data-testid="delivery-plan">
            {error && <p className="rounded-[10px] bg-[#fef2f2] px-3 py-2 text-[12px] text-[#b91c1c]">{error}</p>}

            <Field
                label={offersPickup && !ownPickup ? 'أيام التوصيل والاستلام وفتراتها' : 'أيام التوصيل وفتراته'}
                hint="يوم بلا فترة لا يُعرض للزبون. واسم الفترة اختياري — وبلا اسم تُكتب بساعتيها"
            >
                <WeekWindows week={plan.delivery} onChange={(delivery) => onChange({ ...plan, delivery })} make={fresh} max={MAX_SLOTS} labels exclusive testid="delivery-week" />
            </Field>

            {offersPickup && (
                <Field label="الاستلام من المحل" hint="بجدول التوصيل نفسه، أو بأيامٍ وساعاتٍ تخصّه">
                    <div className="flex flex-wrap gap-4 pt-1 text-[13px]">
                        <label className="flex cursor-pointer items-center gap-2">
                            <input
                                type="radio"
                                name="pickup-plan"
                                checked={!ownPickup}
                                onChange={() => onChange({ ...plan, pickup: null })}
                                className="size-4 accent-[#111]"
                            />
                            {t('بجدول التوصيل نفسه')}
                        </label>
                        <label className="flex cursor-pointer items-center gap-2">
                            <input
                                type="radio"
                                name="pickup-plan"
                                checked={ownPickup}
                                onChange={() => onChange({ ...plan, pickup: plan.pickup ?? plan.delivery.map((day) => day.map((s) => ({ ...s, id: '' }))) })}
                                className="size-4 accent-[#111]"
                            />
                            {t('بجدولٍ خاص للاستلام')}
                        </label>
                    </div>
                    {ownPickup && plan.pickup && (
                        <div className="mt-3">
                            <WeekWindows week={plan.pickup} onChange={(pickup) => onChange({ ...plan, pickup })} make={fresh} max={MAX_SLOTS} labels exclusive testid="pickup-week" />
                        </div>
                    )}
                </Field>
            )}

            <Field label="أيام الإغلاق والإجازات" hint="لا توصيل ولا استلام فيها — ولو كانت أيام عمل في الجدول">
                <ClosureList closed={plan.closed} onChange={(closed) => onChange({ ...plan, closed })} testid="delivery-closures" />
            </Field>

            <Field
                label="مدة تجهيز الطلب (بالدقائق)"
                hint="لا تُعرض فترة تبدأ قبل أن يمرّ هذا الوقت على الطلب — 120 تعني ساعتين، وصفر يعني أي فترة لم تبدأ بعد"
            >
                <Input
                    type="number"
                    min={0}
                    max={10080}
                    dir="ltr"
                    className="w-40"
                    value={String(plan.lead)}
                    onChange={(e) => onChange({ ...plan, lead: Math.max(0, Math.trunc(Number(e.target.value) || 0)) })}
                    aria-label={t('مدة تجهيز الطلب (بالدقائق)')}
                />
            </Field>

            {offersDelivery && !hasAny(plan.delivery) && (
                <p className="rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] text-[#b45309]">{t('أضف فترة توصيلٍ واحدةً على الأقل — وإلا لم يجد زبونك موعدًا يختاره.')}</p>
            )}
        </div>
    );
}
