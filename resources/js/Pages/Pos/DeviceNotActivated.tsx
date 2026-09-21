import { Head, usePage } from '@inertiajs/react';
import { LogOut, MonitorSmartphone } from 'lucide-react';
import Logo from '@/Components/Logo';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { logout } from '@/lib/logout';
import type { PageProps } from '@/types';

interface Props {
    businessName: string;
}

/**
 * جهازٌ لم يُفعَّل — لموظّفٍ لا يملك تفعيلَه.
 *
 * كان يقف على 403 عارية وقد كتب بريدَه وكلمتَه صحيحَين. هنا يُقال له ما
 * ينقص (تفعيلُ هذا المتصفّح مرّةً واحدة) ومن يفعله (من يملك الإعدادات)،
 * وبابٌ للخروج ليدخل المديرُ ويفعّل.
 */
export default function DeviceNotActivated() {
    const { businessName, csrf } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    return (
        <div className="flex min-h-dvh flex-col items-center justify-center bg-[#f7f8f9] px-4 py-10">
            <Head title={t('الجهاز غير مفعَّل')} />

            <div className="w-full max-w-[420px]">
                <div className="mb-8 flex flex-col items-center gap-3">
                    <Logo className="h-14 w-auto text-[#111]" />
                    <p className="text-[15px] font-semibold text-[#111]">{businessName}</p>
                </div>

                <Card className="p-7">
                    <div className="mb-4 flex items-center gap-2">
                        <span className="flex size-9 items-center justify-center rounded-[12px] bg-[#fffbeb] text-[#d97706]">
                            <MonitorSmartphone className="size-5" />
                        </span>
                        <h3 className="font-bold text-[#111]">{t('هذا الجهاز لم يُربط بفرع بعد')}</h3>
                    </div>

                    <p className="text-[13px] leading-6 text-[#4b4b4b]">
                        {t('حسابك صحيح، لكنّ نقطة البيع تُفعَّل على كلّ متصفّح مرّةً واحدة ليُعرف فرعُها. يفعّلها من يملك صلاحية الإعدادات — صاحب المتجر أو المدير — بالدخول من هذا المتصفّح نفسه واختيار الفرع.')}
                    </p>

                    <Button
                        variant="outline"
                        className="mt-5 w-full"
                        onClick={() => logout(route('logout'), csrf)}
                    >
                        <LogOut />
                        {t('خروج — ليدخل المدير ويفعّل')}
                    </Button>
                </Card>
            </div>
        </div>
    );
}
