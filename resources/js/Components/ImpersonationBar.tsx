import { router, usePage } from '@inertiajs/react';
import { LogOut, ShieldAlert } from 'lucide-react';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

/**
 * شريطٌ لا يُخفى أثناء الدخول كتاجر.
 *
 * أخطرُ ما في انتحال الحسابات أن يُنسى: يعدّل موظّف الدعم بياناتٍ ظنًّا أنها
 * بياناته، أو يخرج تاركًا الجلسة مفتوحة على حساب عميل. فالشريط ثابتٌ في أعلى
 * كل صفحة، وفيه اسمُ من ندخل باسمه وزرُّ العودة — لا في قائمةٍ منسدلة.
 */
export default function ImpersonationBar() {
    const { auth } = usePage<PageProps>().props;
    const t = useTranslate();

    if (!auth?.impersonating) {
        return null;
    }

    return (
        <div className="sticky top-0 z-50 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-[#b91c1c] px-4 py-2 text-[13px] font-medium text-white">
            <ShieldAlert className="size-4 shrink-0" />
            <span>
                {t('تدخل الآن باسم :name', { name: auth.user.name })} — {t('كل ما تفعله يُنسب إليه')}
            </span>
            <button
                type="button"
                onClick={() => router.post(route('impersonate.stop'))}
                className="inline-flex items-center gap-1.5 rounded-[8px] bg-white/15 px-3 py-1 transition-colors hover:bg-white/25"
            >
                <LogOut className="size-3.5" />
                {t('عُد إلى لوحة المنصة')}
            </button>
        </div>
    );
}
