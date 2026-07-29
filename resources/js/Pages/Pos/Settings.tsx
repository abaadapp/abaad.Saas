import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Save, Settings as SettingsIcon } from 'lucide-react';
import PosLayout from '@/Layouts/PosLayout';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

const LOCALES: { code: 'ar' | 'en'; label: string; hint: string }[] = [
    { code: 'ar', label: 'العربية', hint: 'من اليمين إلى اليسار (RTL)' },
    { code: 'en', label: 'English', hint: 'من اليسار إلى اليمين (LTR)' },
];

interface Props {
    settings: Record<string, string>;
    branchName: string;
}

export default function PosSettings() {
    const { branchName, locale } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [picked, setPicked] = useState<'ar' | 'en'>(locale === 'en' ? 'en' : 'ar');
    const [busy, setBusy] = useState(false);

    const save = () => {
        setBusy(true);
        // اتجاه المستند يُحسم في قالب الجذر، فنُعيد التحميل بعد الحفظ
        router.post(
            route('pos.language.update'),
            { locale: picked },
            { onSuccess: () => window.location.reload(), onFinish: () => setBusy(false) },
        );
    };

    return (
        <PosLayout title={t('الإعدادات')}>
            <div className="mx-auto max-w-2xl space-y-6 p-4">
                <div className="flex items-center gap-3">
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-gray-900 text-white">
                        <SettingsIcon className="size-5" />
                    </span>
                    <div>
                        <h1 className="text-lg font-bold leading-tight text-gray-900">{t('الإعدادات')}</h1>
                        <p className="text-sm text-gray-400">
                            {t('إعدادات نقطة البيع')} · {branchName}
                        </p>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('لغة النظام')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            {LOCALES.map((l) => (
                                <label
                                    key={l.code}
                                    className={cn(
                                        'flex cursor-pointer items-center justify-between rounded-xl border px-4 py-3.5 transition',
                                        picked === l.code
                                            ? 'border-gray-900 bg-gray-50'
                                            : 'border-gray-200 hover:bg-gray-50',
                                    )}
                                >
                                    <span className="flex items-center gap-3">
                                        <span className="flex size-10 items-center justify-center rounded-full bg-gray-100 text-xs font-bold text-gray-700">
                                            {l.code.toUpperCase()}
                                        </span>
                                        <span>
                                            <span className="block text-sm font-medium text-gray-800">{l.label}</span>
                                            <span className="block text-xs text-gray-400">{t(l.hint)}</span>
                                        </span>
                                    </span>
                                    <input
                                        type="radio"
                                        name="locale"
                                        value={l.code}
                                        checked={picked === l.code}
                                        onChange={() => setPicked(l.code)}
                                        className="size-5 border-gray-300 text-gray-900"
                                    />
                                </label>
                            ))}
                        </div>

                        <div className="mt-6 flex justify-end">
                            <Button onClick={save} disabled={busy}>
                                <Save />
                                {busy ? '…' : t('حفظ اللغة')}
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </PosLayout>
    );
}
