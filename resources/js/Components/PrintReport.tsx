import { Printer } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';

/**
 * زرّ طباعة التقرير.
 *
 * وكان في النافذة المشتركة قبل أن يصير لكلّ تقريرٍ صفحته، فذهب معها: من
 * كان يطبع «وسائل الدفع» لمحاسبه فقد ذلك بلا أن يُقال له. فعاد هنا.
 *
 * والقالبُ يعتمد على `.printable-report` في app.css: قاعدةُ الطباعة العامة
 * تُخفي الصفحة كلّها إلا الإيصال الحراري، فبلا الكشف تخرج ورقةٌ بيضاء —
 * زرٌّ يقول شيئًا ولا يفعله.
 */
export default function PrintReport() {
    const t = useTranslate();

    return (
        // الزرّ لا يُطبع نفسه: أداةٌ على الشاشة لا سطرٌ في التقرير
        <Button variant="outline" className="no-print" onClick={() => window.print()}>
            <Printer />
            {t('طباعة')}
        </Button>
    );
}
