import { useForm } from '@inertiajs/react';
import { MessageCircleReply, Save } from 'lucide-react';
import Field from '@/Components/Field';
import StatusPill from '@/Components/StatusPill';
import { PageActions, SettingsSection } from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import { Input, Textarea } from '@/Components/ui/input';
import Toggle from '@/Components/Toggle';
import { useTranslate } from '@/lib/i18n';

export interface AutoReplySettings {
    enabled: boolean;
    ar: string;
    en: string;
    branch_id: number | null;
    cooldown_hours: number;
}

interface Props {
    settings: AutoReplySettings;
    branches: { id: number; name: string }[];
    /** ولا يُرسل ردٌّ من رقمٍ لا يعمل — فالمقبض لا يُعرض حيًّا قبل الربط */
    linked: boolean;
    mayManage: boolean;
}

/** أطولُ نصٍّ يقبله الخادم — `WhatsAppAutoReply::MAX_TEXT` */
export const MAX_TEXT = 900;

/**
 * الردُّ التلقائيّ على أوّل رسالة — مُطفأٌ حتى يُشعله صاحبُه.
 *
 * ═══ ولمَ الإطفاءُ هو الافتراض ═══
 *
 * هذا أوّلُ ما يكتب إلى زبونٍ بلا أن يفعل أحدٌ شيئًا — وكلُّ ما سواه يتبع
 * فعلًا: بيعةً تمّت أو حالةَ طلبٍ تبدّلت. فلا يُورَث بترقية، ولا يُشعل
 * لأنّ حقلًا مُلئ: المفتاحُ يُضغط بيدٍ تعرف ما تكتب.
 *
 * ═══ ولغةُ الردّ ═══
 *
 * تُقرأ من صفّ الزبون إن كان معروفًا (هي التي يسألها الصندوق قبل البيع)،
 * والعربيّةُ لمن لا نعرفه. ولا تُخمَّن من حروف رسالته: «hi» يكتبها من
 * يقرأ العربيّة.
 */
export default function WhatsappAutoReply({ settings, branches, linked, mayManage }: Props) {
    const t = useTranslate();

    const form = useForm({
        enabled: settings.enabled,
        ar: settings.ar,
        en: settings.en,
        branch_id: settings.branch_id ? String(settings.branch_id) : '',
        cooldown_hours: String(settings.cooldown_hours),
    });

    const live = form.data.enabled && linked;

    return (
        <SettingsSection
            title="الرد التلقائي على أول رسالة"
            description="يُرسَل ردٌّ واحد لمن يكتب إلى رقم متجرك — مرةً في كل فترة، لا على كل رسالة."
            icon={MessageCircleReply}
            status={
                live
                    ? <StatusPill state="ready" label="يعمل" />
                    : <StatusPill state="off" label="متوقف" />
            }
        >
            <div className="space-y-4">
                <div data-testid="autoreply-switch">
                    <Toggle
                        label="تشغيل الرد التلقائي"
                        hint="لا يُرسل شيء قبل تشغيله — وهو متوقف حتى تُشغّله بنفسك."
                        on={form.data.enabled}
                        onChange={(v) => mayManage && form.setData('enabled', v)}
                    />
                </div>

                {/*
                    ومقبضٌ مُشعَلٌ على رقمٍ غير مربوط لا يُرسل شيئًا — فيُقال.

                    وإخفاءُ القسم كان أسوأ: من كتب نصَّه ثمّ ربط رقمه لا يجد
                    ما كتب ولا يعرف أنّه حُفظ.
                */}
                {form.data.enabled && ! linked && (
                    <p className="rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] leading-relaxed text-[#b45309]">
                        {t('اربط رقم متجرك أولًا — لا يصل ردٌّ من رقم أبعاد المشترك.')}
                    </p>
                )}

                <Field label="نص الرد بالعربية" error={form.errors.ar} hint={`${form.data.ar.length}/${MAX_TEXT}`}>
                    <Textarea
                        dir="rtl"
                        maxLength={MAX_TEXT}
                        value={form.data.ar}
                        disabled={! mayManage}
                        onChange={(e) => form.setData('ar', e.target.value)}
                        placeholder={t('مثال: أهلًا بك في متجرنا 🌷 وصلتنا رسالتك وسيردّ عليك فريقنا قريبًا.')}
                        className="min-h-20"
                    />
                </Field>

                <Field label="نص الرد بالإنجليزية" error={form.errors.en} hint={`${form.data.en.length}/${MAX_TEXT}`}>
                    <Textarea
                        dir="ltr"
                        maxLength={MAX_TEXT}
                        value={form.data.en}
                        disabled={! mayManage}
                        onChange={(e) => form.setData('en', e.target.value)}
                        placeholder="Thanks for reaching out — our team will reply shortly."
                        className="min-h-20"
                    />
                </Field>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    {/* والفرعُ يُعرض حين يكون للمتجر أكثرُ من فرع: سؤالٌ جوابُه واحدٌ ليس سؤالًا */}
                    {branches.length > 1 && (
                        <Field label="الفرع الذي يُذكر في الرد" error={form.errors.branch_id}>
                            <select
                                value={form.data.branch_id}
                                disabled={! mayManage}
                                onChange={(e) => form.setData('branch_id', e.target.value)}
                                className="h-10 w-full rounded-[10px] border border-[#e5e7eb] bg-white px-3 text-[13px] text-[#111]"
                                data-testid="autoreply-branch"
                            >
                                <option value="">{t('بلا فرع')}</option>
                                {branches.map((b) => (
                                    <option key={b.id} value={String(b.id)}>{b.name}</option>
                                ))}
                            </select>
                        </Field>
                    )}

                    <Field
                        label="لا يتكرر الرد لنفس الرقم خلال"
                        hint={t('بالساعات — يمنع ردًّا على كل سطر يكتبه الزبون')}
                        error={form.errors.cooldown_hours}
                    >
                        <Input
                            type="number"
                            min="1"
                            max="168"
                            dir="ltr"
                            disabled={! mayManage}
                            value={form.data.cooldown_hours}
                            onChange={(e) => form.setData('cooldown_hours', e.target.value)}
                        />
                    </Field>
                </div>

                {/*
                    ونافذةُ ميتا تُقال هنا لا تُترك ليكتشفها التاجر من فشلٍ صامت.

                    الردُّ يقع على رسالةٍ وصلت الآن — أي داخل الأربعِ والعشرين
                    ساعةً دائمًا، فالنصُّ الحرّ مسموح. ولو خرجنا عن هذا يومًا
                    لوجب قالبٌ معتمَد.
                */}
                <p className="text-[12px] leading-relaxed text-[#9ca3af]">
                    {t('يُرسَل نصًّا حرًّا لأنه ردٌّ داخل نافذة الـ24 ساعة التي تسمح بها ميتا — ولا يُرسَل للزبون ابتداءً.')}
                </p>

                {mayManage && (
                    <PageActions>
                        <Button
                            type="button"
                            loading={form.processing}
                            onClick={() => {
                                /*
                                 * والفرعُ الفارغ يُرسَل `null` لا `''`.
                                 *
                                 * قاعدةُ الخادم `nullable|integer`، والنصُّ
                                 * الفارغ ليس عددًا — فتُردّ الحفظةُ كلُّها
                                 * بـ٤٢٢ على تاجرٍ لم يختر فرعًا، وهو الأكثر.
                                 */
                                form.transform((d) => ({
                                    ...d,
                                    branch_id: d.branch_id === '' ? null : Number(d.branch_id),
                                    cooldown_hours: Number(d.cooldown_hours) || 12,
                                }));

                                form.post(route('admin.integrations.whatsapp.autoReply'), { preserveScroll: true });
                            }}
                            data-testid="autoreply-save"
                        >
                            <Save />
                            {t('حفظ')}
                        </Button>
                    </PageActions>
                )}
            </div>
        </SettingsSection>
    );
}
