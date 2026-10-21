import { CalendarClock } from 'lucide-react';
import Field from '@/Components/Field';
import Toggle from '@/Components/Toggle';
import { SettingsGroup, SettingsSection } from '@/Components/Settings';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { ThemeForm } from './form';
import { MAX_WINDOWS, MESSAGE_MAX, fillMessage, readOrderSchedule, writeOrderSchedule, type OrderSchedule } from './schedule';
import WeekWindows, { ClosureList } from './WeekWindows';

/** حالُ الاستقبال الآن كما حُفظ — من `SettingsController::orderDesk` */
export interface OrderDesk {
    open: boolean;
    why: 'manual' | 'schedule' | null;
    now: string;
    next: string | null;
    preview: { date_ar: string; time_ar: string; date_en: string; time_en: string };
}

/**
 * «استقبال الطلبات» — متى يقبل متجرُه طلبًا جديدًا من موقعه، وما يُقال لزبونه حين لا يقبل.
 *
 * ═══ ثلاثةُ مقابض، والأوّلُ أعلاها ═══
 *
 *   - **اقبل الطلبات** — الإغلاقُ اليدويّ: مطفأٌ يعني مغلقًا حتّى يفتحه هو، ولو
 *     قال الجدولُ «مفتوح».
 *   - **الجدولة** — أيّامُ الأسبوع وساعاتُها، والمدّةُ، وأيّامُ الإغلاق.
 *   - **رسالةُ الإغلاق** — يكتبها بلغتيه، ولكلّ لغةٍ صفحتُها.
 *
 * والحكمُ في الخادم بساعة المتجر (`Store\OrderWindow`)، وهذه الشاشةُ تكتب
 * وتعاين ولا تحسب. والموقعُ يبقى يُتصفَّح وهو مغلق.
 */
export default function Orders({ form, desk }: { form: ThemeForm; desk: OrderDesk }) {
    const t = useTranslate();
    const schedule = readOrderSchedule(form.data.store_order_schedule);
    const setSchedule = (s: OrderSchedule) => form.setData('store_order_schedule', writeOrderSchedule(s));

    const ar = { date: desk.preview.date_ar, time: desk.preview.time_ar };
    const en = { date: desk.preview.date_en, time: desk.preview.time_en };

    return (
        <section id="orders" className="scroll-mt-24">
            <SettingsSection
                icon={CalendarClock}
                title="استقبال الطلبات"
                description="متى يقبل متجرك طلبات جديدة من الموقع، وما يقرؤه زبونك حين لا يقبل."
                divided
            >
                <SettingsGroup title="الحالة الآن">
                    <div
                        className={`rounded-[10px] px-3 py-2 text-[13px] leading-relaxed ${desk.open ? 'bg-[#f0fdf4] text-[#166534]' : 'bg-[#fef2f2] text-[#991b1b]'}`}
                        data-testid="order-desk-status"
                    >
                        <strong className="font-semibold">{desk.open ? t('يستقبل الطلبات الآن') : t('لا يستقبل الطلبات الآن')}</strong>
                        {desk.why === 'manual' && <span> — {t('أُغلق يدويًا حتى تفتحه بنفسك')}</span>}
                        {desk.why === 'schedule' && (
                            <span> — {desk.next ? t('حسب الجدول، ويُفتح :when', { when: desk.next }) : t('حسب الجدول، ولا موعد فتحٍ قادم فيه')}</span>
                        )}
                        <div className="mt-0.5 text-[11px] opacity-80">{t('بتوقيت المتجر :now — كما حُفظ آخر مرة', { now: desk.now })}</div>
                    </div>
                </SettingsGroup>

                <SettingsGroup title="التحكم في الاستقبال">
                    <div className="space-y-4">
                        <Toggle
                            on={form.data.store_allow_orders}
                            onChange={(v) => form.setData('store_allow_orders', v)}
                            label="اقبل الطلبات من المتجر"
                            hint="أطفئه فيُغلق الاستقبال حتى تفتحه بنفسك، ولو كان الجدول مفتوحًا. يبقى متجرك يُتصفَّح والأسعار ظاهرة"
                        />
                        <Toggle
                            on={form.data.store_order_schedule_on}
                            onChange={(v) => form.setData('store_order_schedule_on', v)}
                            label="جدولة الاستقبال تلقائيًا"
                            hint="يفتح المتجر ويغلق وحده حسب الأيام والساعات أدناه، بتوقيت مسقط لا بساعة جهاز الزبون"
                        />
                    </div>

                    {form.data.store_order_schedule_on && (
                        <div className="mt-5 space-y-5" data-testid="order-schedule">
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <Field label="أول يوم للاستقبال" hint="اختياري — قبله لا تُستقبل طلبات">
                                    <Input
                                        type="date"
                                        dir="ltr"
                                        value={schedule.starts ?? ''}
                                        onChange={(e) => setSchedule({ ...schedule, starts: e.target.value || null })}
                                        aria-label={t('أول يوم للاستقبال')}
                                    />
                                </Field>
                                <Field label="آخر يوم للاستقبال" hint="اختياري — بعده لا تُستقبل طلبات">
                                    <Input
                                        type="date"
                                        dir="ltr"
                                        value={schedule.ends ?? ''}
                                        min={schedule.starts ?? undefined}
                                        onChange={(e) => setSchedule({ ...schedule, ends: e.target.value || null })}
                                        aria-label={t('آخر يوم للاستقبال')}
                                    />
                                </Field>
                            </div>

                            <Field
                                label="أيام الأسبوع وساعاتها"
                                hint="يوم بلا فترة مغلق. والفترة التي تنتهي قبل بدايتها تمتد بعد منتصف الليل — مثل 8:00 م إلى 2:00 ص. و00:00 إلى 00:00 يوم كامل"
                                error={form.errors.store_order_schedule}
                            >
                                <WeekWindows
                                    week={schedule.week}
                                    onChange={(week) => setSchedule({ ...schedule, week })}
                                    make={() => ({ from: '09:00', to: '21:00' })}
                                    max={MAX_WINDOWS}
                                    overnight
                                    testid="order-week"
                                />
                            </Field>

                            <Field label="أيام الإغلاق والإجازات" hint="لا تُستقبل فيها طلبات ولو كانت أيام عمل في الجدول">
                                <ClosureList
                                    closed={schedule.closed}
                                    onChange={(closed) => setSchedule({ ...schedule, closed })}
                                    testid="order-closures"
                                />
                            </Field>
                        </div>
                    )}
                </SettingsGroup>

                {/*
                    ═══ رسالةُ الإغلاق — يكتبها هو، بلغتيه ═══

                    لا ترجمةَ آليّة ولا وقوعَ لغةٍ على الأخرى: الصفحةُ العربيّة تقرأ
                    العربيّة والإنجليزيّةُ الإنجليزيّة. والمتغيّران يُملآن بموعد الفتح
                    حين يُعرف، والسطرُ الذي يذكرهما يُسقط حين لا يُعرف (إغلاقٌ يدويّ).
                */}
                <SettingsGroup title="رسالة إغلاق الاستقبال">
                    <Toggle
                        on={form.data.store_closed_message_on}
                        onChange={(v) => form.setData('store_closed_message_on', v)}
                        label="اكتب رسالة الإغلاق بنفسك"
                        hint="وإلا ظهرت رسالة النظام: «المتجر لا يستقبل طلبات من الموقع الآن — تواصل معنا.»"
                    />

                    {form.data.store_closed_message_on && (
                        <div className="mt-4 space-y-4" data-testid="closed-message">
                            <p className="rounded-[10px] bg-[#f8fafc] px-3 py-2 text-[12px] leading-relaxed text-[#475569]">
                                {t('يمكنك كتابة {next_open_date} و{next_open_time} فيُكتب مكانهما موعد إعادة الفتح. وإن لم يُعرف موعد — كإغلاقٍ يدوي — يُحذف السطر الذي يذكرهما، فاكتبهما في سطرٍ مستقل.')}
                            </p>
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <Field label="رسالة إغلاق استقبال الطلبات — العربية" error={form.errors.store_closed_message}>
                                    <Textarea
                                        rows={4}
                                        maxLength={MESSAGE_MAX}
                                        value={form.data.store_closed_message}
                                        onChange={(e) => form.setData('store_closed_message', e.target.value)}
                                        aria-label={t('رسالة إغلاق استقبال الطلبات — العربية')}
                                    />
                                </Field>
                                <Field label="Order Closure Message — English" error={form.errors.store_closed_message_en}>
                                    <Textarea
                                        rows={4}
                                        dir="ltr"
                                        maxLength={MESSAGE_MAX}
                                        value={form.data.store_closed_message_en}
                                        onChange={(e) => form.setData('store_closed_message_en', e.target.value)}
                                        aria-label="Order Closure Message — English"
                                    />
                                </Field>
                            </div>

                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2" data-testid="closed-message-preview">
                                <Preview
                                    title={t('معاينة — العربية')}
                                    dir="rtl"
                                    scheduled={fillMessage(form.data.store_closed_message, ar)}
                                    manual={fillMessage(form.data.store_closed_message, null)}
                                    fallback="المتجر لا يستقبل طلبات من الموقع الآن — تواصل معنا."
                                    manualTitle={t('حين يُغلق يدويًا')}
                                />
                                <Preview
                                    title="Preview — English"
                                    dir="ltr"
                                    scheduled={fillMessage(form.data.store_closed_message_en, en)}
                                    manual={fillMessage(form.data.store_closed_message_en, null)}
                                    fallback="The store is not taking online orders right now — contact us."
                                    manualTitle="When closed by hand"
                                />
                            </div>
                        </div>
                    )}
                </SettingsGroup>
            </SettingsSection>
        </section>
    );
}

/** ما يقرؤه الزبون — حين يُعرف موعدُ الفتح، وحين يُغلق يدويًّا */
function Preview({
    title,
    dir,
    scheduled,
    manual,
    fallback,
    manualTitle,
}: {
    title: string;
    dir: 'rtl' | 'ltr';
    scheduled: string | null;
    manual: string | null;
    fallback: string;
    manualTitle: string;
}) {
    return (
        <div className="rounded-[12px] border border-[#ececec] bg-white p-3" dir={dir}>
            <div className="mb-1.5 text-[11px] font-medium text-[#6b7280]">{title}</div>
            <p className="whitespace-pre-line text-[13px] leading-relaxed text-[#111]">{scheduled ?? fallback}</p>
            {manual !== scheduled && (
                <>
                    <div className="mb-1 mt-3 text-[11px] font-medium text-[#6b7280]">{manualTitle}</div>
                    <p className="whitespace-pre-line text-[13px] leading-relaxed text-[#111]">{manual ?? fallback}</p>
                </>
            )}
        </div>
    );
}
