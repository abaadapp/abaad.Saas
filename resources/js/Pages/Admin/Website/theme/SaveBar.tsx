import { Save } from 'lucide-react';

import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';

/**
 * شريطُ الحفظ — يظهر متى تغيّر شيء، ويلتصق بأسفل الشاشة.
 *
 * ═══ ولمَ واحدٌ في الشاشة لا زرٌّ لكلّ بطاقة ═══
 *
 * كان في شاشة المتجر **زرّان** متجاوران في الشكل: واحدٌ للمتجر وآخرُ
 * لبوّابة الدفع، ولا يحفظ أحدُهما ما يحفظه الآخر. فمن عدّل رسمَ التوصيل
 * ثمّ ضغط الزرَّ الأسفل أضاع ما كتب ولا شيءَ يقول له ذلك.
 *
 * فصار للشاشة زرٌّ واحدٌ يلتصق بأسفلها مهما كثرت بطاقاتُها. وODAY تضع زرًّا
 * لكلّ بطاقة، ولم نتبعها هنا: بطاقاتُ «المتجر والطلبات» خمسٌ وإحداهنّ
 * بوّابةُ دفعٍ لها بابُها — وخمسةُ أزرارٍ متشابهةٍ تُعيد العطبَ نفسَه.
 * وتبقى البوّابةُ بزرّها وحدَها، واسمُه يقول ما يحفظ: «حفظ بوّابة الدفع»
 * لا «حفظ»، فلا يلتبس بالزرّ اللاصق.
 *
 * ═══ ولا يظهر إلّا حين يكون له معنى ═══
 *
 * زرُّ حفظٍ دائمُ الظهور على صفحةٍ لم يتغيّر فيها شيء يُدرَّب صاحبُه على
 * تجاهله. وظهورُه عند أوّل تغيير هو ما يقول «عندك ما لم يُحفظ» — وهي
 * الجملةُ التي تمنع الخروجَ من الصفحة بما كُتب فيها.
 *
 * وسرّا البوّابة لا يُرسلان مع كلّ حفظِ رسمِ توصيل — وهذا سببٌ ثانٍ لبقاء
 * زرّها وحدَه.
 */
export default function SaveBar({
    dirty,
    processing,
    onSave,
    onReset,
}: {
    dirty: boolean;
    processing: boolean;
    onSave: () => void;
    onReset: () => void;
}) {
    const t = useTranslate();

    if (! dirty) return null;

    return (
        <div
            data-testid="save-bar"
            className="sticky bottom-4 z-20 mt-6 flex flex-wrap items-center justify-between gap-3 rounded-[14px] border border-[var(--ui-border,#e8e8e8)] bg-white/95 px-4 py-3 shadow-[0_8px_24px_rgba(0,0,0,0.08)] backdrop-blur"
        >
            <p className="text-[13px] text-[#6b7280]">{t('عندك تغييراتٌ لم تُحفظ بعد.')}</p>

            <div className="flex items-center gap-2">
                <Button type="button" variant="ghost" onClick={onReset} disabled={processing}>
                    {t('تراجع')}
                </Button>
                <Button type="button" onClick={onSave} loading={processing}>
                    <Save />
                    {t('حفظ التغييرات')}
                </Button>
            </div>
        </div>
    );
}
