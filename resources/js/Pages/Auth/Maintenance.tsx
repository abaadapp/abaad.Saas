import { Head, router, usePage } from '@inertiajs/react';
import { RefreshCw, Wrench } from 'lucide-react';
import Logo from '@/Components/Logo';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Props {
    message: string;
}

/**
 * وقفةٌ معلنة — لا صفحة خطأ.
 *
 * وضع الصيانة كان مقبضًا في إعدادات المنصة لا يقرؤه شيء: يُشغّله المشغّل قبل
 * ترقيةٍ فيظنّ الأبواب أُغلقت، والتجّار يبيعون على قاعدةٍ تُهاجَر تحتهم.
 * والجلسة تبقى مفتوحة عمدًا: الوقفة دقائق، وإخراج الجميع يعني أن كلّ تاجر
 * يعود ليكتب كلمة سرّه.
 */
export default function Maintenance() {
    const { message } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    return (
        <div className="flex min-h-dvh flex-col items-center justify-center bg-[#f7f8f9] px-4 py-10">
            <Head title="صيانة" />

            <Logo className="mb-8 h-6 w-auto text-[#111]" />

            <Card className="w-full max-w-md p-7 text-center">
                <span className="mx-auto mb-4 flex size-12 items-center justify-center rounded-[14px] bg-[#fffbeb] text-[#b45309]">
                    <Wrench className="size-6" />
                </span>

                <h1 className="text-[20px] font-bold text-[#111]">{t('النظام تحت الصيانة')}</h1>
                <p className="mt-2 text-[14px] leading-relaxed text-[#4b4b4b]">{t(message)}</p>
                <p className="mt-3 text-[13px] leading-relaxed text-[#6b7280]">
                    {t('بياناتك ومبيعاتك محفوظة كما هي — لا شيء يضيع بالوقفة.')}
                </p>

                <Button className="mt-6 w-full rounded-full" onClick={() => router.reload()}>
                    <RefreshCw />
                    {t('حاول مرّة أخرى')}
                </Button>
            </Card>
        </div>
    );
}
