import type { ComponentProps, ReactNode } from 'react';
import { Link } from '@inertiajs/react';

interface SmartLinkProps extends Omit<ComponentProps<'a'>, 'href'> {
    /** اسم المسار — يُمرَّر للتوثيق ولتسهيل البحث عن روابط صفحة بعينها */
    routeName: string;
    href: string;
    children: ReactNode;
}

/**
 * رابط تنقّل داخل اللوحة — تنقّل Inertia دائمًا.
 *
 * كان يقرّر بقائمة بيضاء اسمها INERTIA_ROUTES تُسرد فيها الصفحات المحوَّلة،
 * وما ليس فيها يهبط إلى <a> بتنقّل كامل. كان ذلك صحيحًا يوم بقيت صفحات Blade،
 * لكنه صار فخًّا: لوحة المنصة حين تحوّلت إلى React لم يُضَف أيٌّ من مساراتها
 * (super-admin.*) إلى القائمة، فظلّ كل ضغط فيها يعيد تحميل الصفحة كاملة —
 * ٥٧ طلبًا و~٧٠٠ مللي ثانية بدل طلب واحد و~٣٠. والأسوأ أن السقوط صامت: لا خطأ
 * ولا تحذير، فقط بطء يبدو وكأنه من الخادم.
 *
 * كل صفحات النظام اليوم Inertia، فالقائمة حُذفت. ولو أُضيفت وجهة غير Inertia
 * لاحقًا (تنزيل مثلًا) فلا تمرّرها من هنا — استعمل <a> صراحةً.
 *
 * جلبٌ مسبق عند المرور بالمؤشّر: حين يمرّ المؤشّر على الرابط (قبل النقر بلحظة)
 * تُجلب الصفحة في الخلفية، فيصير النقر فتحًا فوريًّا بلا انتظار الخادم. كل
 * روابط التنقّل تمرّ من هنا، فالإضافة في مكان واحد تسري على النظام كلّه.
 * cacheFor قصير (نصف دقيقة) لأنه نظام بيع تتغيّر بياناته: يكفي لالتقاط النقرة
 * التي تلي المرور، ولا يُبقي بياناتٍ قديمة لو تأخّر النقر. القيمة قابلة
 * للتجاوز لكل رابط عبر تمرير prefetch/cacheFor صراحةً.
 */
export default function SmartLink({ routeName: _routeName, href, children, ...props }: SmartLinkProps) {
    // توقيع onClick في <Link> أعمّ منه في <a>؛ نوسّعه هنا بدل تضييق نوع الوسيط
    const linkProps = props as ComponentProps<typeof Link>;

    return (
        <Link href={href} prefetch="hover" cacheFor="30s" {...linkProps}>
            {children}
        </Link>
    );
}
