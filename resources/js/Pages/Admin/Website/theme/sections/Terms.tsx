import { ScrollText } from 'lucide-react';
import Field from '@/Components/Field';
import Toggle from '@/Components/Toggle';
import { SettingsGroup, SettingsSection } from '@/Components/Settings';
import { Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { ThemeForm } from './form';
import { TERMS_MAX } from './schedule';

/** الشروطُ القائمة — آخرُ نسخها (`SettingsController::termsSeed`) */
export interface TermsSeed {
    version: number | null;
    updated: string | null;
    ar: string;
    en: string;
}

/**
 * «شروط وأحكام الطلبات الإلكترونية» — نصُّ صاحب المتجر بلغتيه، وموافقةٌ تُشترط قبل الطلب.
 *
 * ═══ ولا نصَّ مقترحًا ═══
 *
 * الشروطُ التزامٌ قانونيٌّ بينه وبين زبونه، فلا يكتبها النظامُ عنه ولا يعرض
 * مثالًا يُنسخ. والخانتان فارغتان حتّى يكتب.
 *
 * والمفتاحُ لا يُرفع إلّا والنصّان مكتوبان (الخادمُ يردّ ذلك). وكلُّ تعديلٍ في
 * النصّ نسخةٌ جديدةٌ برقمها، والقديمةُ تبقى كما وافق عليها زبائنُه (`Store\StoreTerms`).
 */
export default function Terms({ form, terms }: { form: ThemeForm; terms: TermsSeed }) {
    const t = useTranslate();
    const changed = form.data.store_terms.trim() !== terms.ar.trim() || form.data.store_terms_en.trim() !== terms.en.trim();

    return (
        <section id="terms" className="scroll-mt-24">
            <SettingsSection
                icon={ScrollText}
                title="شروط وأحكام الطلبات الإلكترونية"
                description="نصّك أنت، بلغتين — يقرؤه زبونك ويوافق عليه قبل أن يطلب أو يدفع."
                divided
            >
                <SettingsGroup>
                    <Toggle
                        on={form.data.store_terms_on}
                        onChange={(v) => form.setData('store_terms_on', v)}
                        label="اشترط الموافقة على الشروط قبل الطلب"
                        hint="للدفع الإلكتروني والتحويل البنكي والدفع عند الاستلام. لا يُفعَّل إلا والنصّان العربي والإنجليزي مكتوبان"
                    />

                    <p className="mt-3 text-[12px] leading-relaxed text-[#6b7280]" data-testid="terms-version">
                        {terms.version
                            ? t('الإصدار :v — آخر تحديث :when', { v: terms.version, when: terms.updated ?? '' })
                            : t('لم تُكتب شروط بعد.')}
                        {changed && <span className="text-[#b45309]"> {t('— عدّلت النص: يُحفظ إصدارًا جديدًا ويُطلب من الزبائن الموافقة عليه.')}</span>}
                    </p>
                    <p className="mt-1 text-[12px] leading-relaxed text-[#6b7280]">
                        {t('كل تعديل في النص يُحفظ إصدارًا جديدًا، وتبقى الإصدارات السابقة محفوظة كما وافق عليها زبائنك — ويُحفظ مع كل طلب الإصدار الذي وافق عليه ووقته ولغته.')}
                    </p>

                    <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <Field label="الشروط والأحكام — العربية" error={form.errors.store_terms}>
                            <Textarea
                                rows={10}
                                maxLength={TERMS_MAX}
                                value={form.data.store_terms}
                                onChange={(e) => form.setData('store_terms', e.target.value)}
                                aria-label={t('الشروط والأحكام — العربية')}
                            />
                        </Field>
                        <Field label="Terms & Conditions — English" error={form.errors.store_terms_en}>
                            <Textarea
                                rows={10}
                                dir="ltr"
                                maxLength={TERMS_MAX}
                                value={form.data.store_terms_en}
                                onChange={(e) => form.setData('store_terms_en', e.target.value)}
                                aria-label="Terms & Conditions — English"
                            />
                        </Field>
                    </div>

                    {/* المعاينةُ كما تظهر في صفحة الإتمام — النصُّ وخانةُ الموافقة بلغتها */}
                    <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2" data-testid="terms-preview">
                        <TermsPreview
                            dir="rtl"
                            title={t('معاينة — العربية')}
                            heading="الشروط والأحكام"
                            text={form.data.store_terms}
                            agree="قرأت الشروط والأحكام وأوافق عليها."
                            empty={t('اكتب الشروط بالعربية لتظهر هنا.')}
                        />
                        <TermsPreview
                            dir="ltr"
                            title="Preview — English"
                            heading="Terms & Conditions"
                            text={form.data.store_terms_en}
                            agree="I have read and agree to the Terms & Conditions."
                            empty="Write the terms in English to see them here."
                        />
                    </div>
                </SettingsGroup>
            </SettingsSection>
        </section>
    );
}

function TermsPreview({
    dir,
    title,
    heading,
    text,
    agree,
    empty,
}: {
    dir: 'rtl' | 'ltr';
    title: string;
    heading: string;
    text: string;
    agree: string;
    empty: string;
}) {
    return (
        <div className="rounded-[12px] border border-[#ececec] bg-white p-3" dir={dir}>
            <div className="mb-1.5 text-[11px] font-medium text-[#6b7280]">{title}</div>
            <div className="mb-2 text-[13px] font-semibold text-[#111]">{heading}</div>
            <div className="max-h-40 overflow-auto whitespace-pre-line rounded-[8px] bg-[#fafafa] p-2 text-[12px] leading-relaxed text-[#374151]">
                {text.trim() !== '' ? text : <span className="text-[#9ca3af]">{empty}</span>}
            </div>
            <label className="mt-2 flex items-start gap-2 text-[12px] text-[#111]">
                <input type="checkbox" disabled className="mt-0.5 size-4" />
                {agree}
            </label>
        </div>
    );
}
