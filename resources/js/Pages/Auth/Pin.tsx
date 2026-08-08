import { Head, usePage } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import Logo from '@/Components/Logo';
import PinPad from '@/Components/PinPad';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

/**
 * دخول الموظف برمز — الشاشة المستقلّة.
 *
 * تُفتح من قفل نقطة البيع ومن الخروج التلقائي بالخمول، فتبقى قائمةً بذاتها
 * إلى جانب تبويب الرمز في شاشة الدخول. واللوحة نفسها في الاثنتين (PinPad).
 */
export default function Pin() {
    const { deviceBusiness, deviceBranch, deviceName } = usePage<
        PageProps<{
            deviceBusiness: string | null;
            deviceBranch: string | null;
            deviceName: string | null;
        }>
    >().props;
    const t = useTranslate();

    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-[#f7f8f9] px-4 py-10">
            <Head title={t('دخول الموظف')} />

            <div className="w-full max-w-[360px]">
                <div className="mb-8 flex flex-col items-center gap-3">
                    <Logo className="h-14 w-auto text-[#111]" />
                    {/*
                        اسم المتجر الذي يقرأ هذا الجهاز رموزه.
                        جهازٌ رُبط بالمتجر الخطأ يوم التركيب يبقى صامتًا حتى
                        يقف موظفٌ أمام شاشةٍ ترفض رمزه الصحيح ولا يفهم لماذا.
                    */}
                    {deviceBusiness && (
                        <p className="text-[15px] font-semibold text-[#111]">{deviceBusiness}</p>
                    )}
                    {/*
                        الفرع والصندوق: «الخوير • كاشير 01».
                        الموظف يعرف بنظرةٍ أنه على الجهاز الصحيح — ورمزه
                        مقيَّد بهذا الفرع، فرفضُه بلا سببٍ ظاهر أسوأ من رفضه.
                    */}
                    {deviceBranch && (
                        <p className="text-[13px] text-[#6b7280]">
                            {deviceBranch}
                            {deviceName ? ` • ${deviceName}` : ''}
                        </p>
                    )}
                    <p className="text-[13px] text-[#6b7280]">{t('أدخل رمز الدخول المكوّن من 4 أرقام')}</p>
                </div>

                <Card className="p-7">
                    <PinPad from="pin" />
                </Card>

                <div className="mt-6 text-center">
                    <Button variant="ghost" size="sm" asChild>
                        <a href={route('login')}>
                            <Mail />
                            {t('الدخول بالبريد وكلمة المرور')}
                        </a>
                    </Button>
                </div>
            </div>
        </div>
    );
}
