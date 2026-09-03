import { Link, router, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { UserPlus, UserRound, Users } from 'lucide-react';
import PosLayout from '@/Layouts/PosLayout';
import { Card } from '@/Components/ui/card';
import { Avatar, AvatarFallback, AvatarImage } from '@/Components/ui/avatar';
import { Button } from '@/Components/ui/button';
import { initials } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Employee {
    id: number;
    name: string;
    role: string;
    avatar: string | null;
}

/**
 * من يقف على الصندوق الآن؟
 *
 * ولم تعد تُفرض قبل البيع: الداخل بحسابه هو الواقف على الصندوق افتراضًا،
 * وهذه الشاشة تُطلب من الترويسة حين يتناوب الموظفون على جهازٍ واحد. ولأنّها
 * صارت اختيارًا، فيها بابُ خروج — «أكمل باسمي» — وإلّا لصارت حاجزًا لمن
 * فتحها بالخطأ.
 */
export default function ChooseCashier() {
    const { employees, currentId, auth } = usePage<PageProps<{ employees: Employee[]; currentId: number | null }>>().props;
    const t = useTranslate();

    const pick = (id: number) => router.post(route('pos.cashier.select'), { employee_id: id });

    return (
        <PosLayout title={t('من على الصندوق؟')}>
            <div className="mx-auto max-w-3xl p-4 pt-10">
                <div className="mb-8 text-center">
                    <div className="mx-auto mb-3 flex size-14 items-center justify-center rounded-2xl bg-gray-100 text-gray-400">
                        <Users className="size-7" />
                    </div>
                    <h1 className="text-[22px] font-bold text-[#111]">{t('من على الصندوق؟')}</h1>
                    <p className="mt-1 text-sm text-gray-500">
                        {t('تُسجَّل المبيعات باسم الموظف الذي تختاره.')}
                    </p>
                    {/*
                        ولا أحد يُحبَس هنا: من فتح الشاشة بالخطأ — أو كان هو
                        نفسه من يبيع — يمضي باسمه بضغطة واحدة.
                    */}
                    <Button variant="outline" size="sm" className="mt-4 gap-1.5" asChild>
                        <Link href={route('pos.index')}>
                            <UserRound className="size-4 text-gray-400" />
                            {t('أكمل باسمي')}
                            {auth?.user?.name ? <span className="text-gray-400"> · {auth.user.name}</span> : null}
                        </Link>
                    </Button>
                </div>

                {employees.length === 0 ? (
                    <Card className="flex flex-col items-center gap-3 py-16 text-center">
                        <div className="flex size-16 items-center justify-center rounded-2xl bg-gray-100 text-gray-400">
                            <UserPlus className="size-8" />
                        </div>
                        <p className="font-semibold text-gray-600">{t('لا يوجد موظفون بعد')}</p>
                        <p className="max-w-sm text-sm text-gray-500">
                            {t('أضف موظفًا أولًا حتى تُنسب المبيعات إليه بدل أن تُنسب إلى حسابك.')}
                        </p>
                        <Button asChild className="mt-1">
                            <Link href={route('admin.employees.index')}>{t('إضافة موظف')}</Link>
                        </Button>
                    </Card>
                ) : (
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        {employees.map((e, i) => (
                            <motion.button
                                key={e.id}
                                type="button"
                                onClick={() => pick(e.id)}
                                initial={{ opacity: 0, y: 10 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ duration: 0.22, delay: Math.min(i * 0.04, 0.3) }}
                                className={
                                    'flex flex-col items-center gap-2 rounded-xl border bg-white p-5 text-center transition-colors hover:border-gray-300 hover:bg-gray-50 ' +
                                    (e.id === currentId ? 'border-gray-900' : 'border-gray-200')
                                }
                            >
                                <Avatar className="size-14">
                                    {e.avatar && <AvatarImage src={e.avatar} alt="" />}
                                    <AvatarFallback>{initials(e.name)}</AvatarFallback>
                                </Avatar>
                                <span className="text-[15px] font-semibold leading-tight text-[#111]">{e.name}</span>
                                <span className="text-[12px] leading-tight text-gray-400">{e.role}</span>
                            </motion.button>
                        ))}
                    </div>
                )}
            </div>
        </PosLayout>
    );
}
