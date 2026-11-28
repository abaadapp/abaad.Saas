import { Link } from '@inertiajs/react';
import { House } from 'lucide-react';
import { useState } from 'react';
import { useTranslate } from '@/lib/i18n';
import { isInstalledApp, isPhone } from '@/lib/installed-app';

/**
 * «الرئيسية» في التطبيق المثبَّت على الهاتف — الطريقُ إلى `/app`.
 *
 * من الشاشة البسيطة تُفتح الطلباتُ والعملاءُ والمبيعاتُ وروابطُ الدفع
 * و«النظام كاملًا» — صفحاتُ اللوحة بقائمتها. والتطبيقُ المثبَّت لا شريطَ
 * متصفّحٍ فيه، وiPhone لا يرجع بالسحب، فلا طريقَ إلى الشاشة البسيطة إلّا هذا.
 *
 * ولا يُرسم في المتصفّح العاديّ، ولا على الآيباد والحاسوب: رئيسيّتُهما
 * اللوحةُ نفسُها وقائمتُها ظاهرة. و`/app` تعرف صاحبَها: من لا يرى اللوحة
 * تحوّله إلى صفحته، فالزرُّ صادقٌ لكلّ من يضغطه.
 */
export default function InstalledAppHome() {
    const t = useTranslate();
    // الجهازُ ونمطُ العرض لا يتبدّلان والصفحةُ مفتوحة — يُسألان مرّةً لا في كلّ رسم
    const [shown] = useState(() => isInstalledApp() && isPhone());

    if (!shown) {
        return null;
    }

    return (
        <Link
            href={route('admin.dashboard.app')}
            aria-label={t('الرئيسية')}
            data-installed-app-home
            className="inline-flex size-9 shrink-0 items-center justify-center rounded-md text-[#374151] hover:bg-[#f5f5f4] lg:hidden"
        >
            <House className="size-5" aria-hidden />
        </Link>
    );
}
