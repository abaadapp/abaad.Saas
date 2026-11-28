import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Camera } from 'lucide-react';
import CopyButton from '@/Components/CopyButton';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
import { useTranslate } from '@/lib/i18n';

export type InstagramMode = 'off' | 'pilot' | 'all';

/** ‏‏إنستقرام عند المنصّة — `App\Support\InstagramPlatform::state`، ولا سرَّ فيه */
export interface InstagramPlatformState {
    mode: InstagramMode;
    pilot: number[];
    modes: InstagramMode[];
    pilot_max: number;
    app_id: string;
    secret_set: boolean;
    configured: boolean;
    redirect_uri: string;
    webhook_url: string;
    verify_token: string;
    deauthorize_url: string;
    deletion_url: string;
    scopes: string[];
    fields: string[];
}

export const INSTAGRAM_MODE_LABEL: Record<InstagramMode, string> = {
    off: 'مقفلة',
    pilot: 'لمتاجر التجربة',
    all: 'مفتوحة لكل التجار',
};

/**
 * ‏‏مديرُ المنصّة يكتب تطبيق إنستقرام، ويلصق العناوينَ في لوحة Meta، ثمّ يفتح الربط.
 *
 * العناوينُ من الخادم نفسِه (لا تُكتب باليد فتخطئ حرفًا)، والسرُّ لا يعود إلى الشاشة: «محفوظ» أو لا.
 */
export default function InstagramPlatformCard({ state }: { state: InstagramPlatformState }) {
    const t = useTranslate();
    const [confirm, dialog] = useConfirm();
    const errors = (usePage().props.errors ?? {}) as Record<string, string>;
    const [appId, setAppId] = useState(state.app_id);
    const [secret, setSecret] = useState('');
    const [mode, setMode] = useState<InstagramMode>(state.mode);
    const [pilot, setPilot] = useState(state.pilot.join(', '));

    const saveApp = () => router.post(route('super-admin.instagram.credentials'), { app_id: appId, app_secret: secret }, {
        preserveScroll: true,
        onSuccess: () => setSecret(''),
    });

    const saveMode = async () => {
        const ok = await confirm({
            title: 'ربط إنستقرام',
            message: mode === 'off'
                ? 'إقفاله يخفي صفحة إنستقرام عن التجار ويسكت إشعاراته — ولا يفصل حسابًا مربوطًا. متابعة؟'
                : 'يصير ربط إنستقرام :state. يربط التجار حساباتهم الحقيقية. متابعة؟',
            values: { state: t(INSTAGRAM_MODE_LABEL[mode]) },
            action: 'حفظ',
        });

        if (ok) {
            router.post(route('super-admin.instagram.mode'), { mode, pilot }, { preserveScroll: true });
        }
    };

    const rows: [string, string][] = [
        ['عنوان الرجوع بعد تسجيل الدخول (OAuth redirect URI)', state.redirect_uri],
        ['عنوان الإشعارات (Callback URL)', state.webhook_url],
        ['رمز التحقق (Verify token)', state.verify_token],
        ['عنوان إلغاء الإذن (Deauthorize callback URL)', state.deauthorize_url],
        ['عنوان حذف البيانات (Data deletion request URL)', state.deletion_url],
    ];

    return (
        <Card className="mb-6 p-6" data-testid="ig-platform">
            {dialog}
            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div className="flex items-start gap-3">
                    <span className="flex size-9 items-center justify-center rounded-[10px] bg-[#fdf2f8] text-[#e1306c]"><Camera className="size-[18px]" /></span>
                    <div>
                        <h2 className="font-bold text-[#111]">{t('ربط إنستقرام')}</h2>
                        <p className="mt-0.5 text-[13px] text-[#6b7280]">{t('تسجيل الدخول عبر Instagram بتطبيق أبعاد — يربط التاجر حسابه بضغطة.')}</p>
                    </div>
                </div>
                <Badge variant={state.mode === 'off' ? 'neutral' : 'success'} data-testid="ig-platform-state">{t(INSTAGRAM_MODE_LABEL[state.mode])}</Badge>
            </div>

            <section className="space-y-3" data-testid="ig-platform-app">
                <h3 className="text-[13px] font-bold text-[#111]">{t('1. تطبيق إنستقرام')}</h3>
                <div className="grid gap-2 sm:grid-cols-2">
                    <Input value={appId} onChange={(e) => setAppId(e.target.value)} dir="ltr" placeholder="Instagram app ID" data-testid="ig-app-id" />
                    <PasswordInput value={secret} onChange={(e) => setSecret(e.target.value)} autoComplete="off"
                        placeholder={state.secret_set ? t('السر محفوظ — اكتب سرًا جديدًا لتبديله') : 'Instagram app secret'} data-testid="ig-app-secret" />
                </div>
                {errors.app_id && <p className="text-[12px] text-[#b91c1c]">{errors.app_id}</p>}
                <p className="text-[12px] text-[#9ca3af]">{t('من لوحة Meta: تطبيق أبعاد ← Instagram ← API setup with Instagram login.')}</p>
                <div className="flex justify-end">
                    <Button type="button" variant="outline" onClick={saveApp} data-testid="ig-app-save">{t('حفظ')}</Button>
                </div>
            </section>

            <section className="mt-6 space-y-2" data-testid="ig-platform-urls">
                <h3 className="text-[13px] font-bold text-[#111]">{t('2. ألصق هذه في لوحة Meta')}</h3>
                {rows.map(([label, value]) => (
                    <div key={label} className="flex flex-wrap items-center justify-between gap-2 rounded-[10px] bg-[#f9fafb] px-3 py-2">
                        <div className="min-w-0">
                            <p className="text-[12px] text-[#6b7280]">{t(label)}</p>
                            <p className="truncate text-[12px] font-medium text-[#111]" dir="ltr">{value}</p>
                        </div>
                        <CopyButton text={value} />
                    </div>
                ))}
                <p className="text-[12px] text-[#6b7280]" data-testid="ig-platform-fields">
                    {t('واشترك في حقول الإشعارات:')} <span dir="ltr">{state.fields.join(', ')}</span>
                    {' · '}{t('والأذونات:')} <span dir="ltr">{state.scopes.join(', ')}</span>
                </p>
            </section>

            <section className="mt-6 space-y-3">
                <h3 className="text-[13px] font-bold text-[#111]">{t('3. لمن يُفتح')}</h3>
                <div className="grid gap-2 sm:grid-cols-3" role="radiogroup">
                    {state.modes.map((m) => (
                        <button key={m} type="button" role="radio" aria-checked={mode === m} onClick={() => setMode(m)} data-testid={`ig-mode-${m}`}
                            disabled={m !== 'off' && ! state.configured}
                            className={`rounded-[12px] border p-3 text-start text-[13px] font-medium disabled:opacity-50 ${mode === m ? 'border-2 border-[#111]' : 'border-[#e5e7eb]'}`}>
                            {t(INSTAGRAM_MODE_LABEL[m])}
                        </button>
                    ))}
                </div>
                {! state.configured && <p className="text-[12px] text-[#b45309]">{t('اكتب معرّف تطبيق إنستقرام وسرّه أولًا.')}</p>}
                {mode === 'pilot' && (
                    <div className="space-y-1">
                        <Input value={pilot} onChange={(e) => setPilot(e.target.value)} dir="ltr" placeholder="5, 12" data-testid="ig-pilot" />
                        <p className="text-[12px] text-[#9ca3af]">{t('أرقام المتاجر مفصولة بفاصلة — :max على الأكثر.', { max: state.pilot_max })}</p>
                    </div>
                )}
                {errors.pilot && <p className="text-[12px] text-[#b91c1c]" data-testid="ig-platform-error">{errors.pilot}</p>}
                <p className="text-[12px] text-[#9ca3af]">{t('والرد الآلي على التعليقات والرسائل يُفتح من تبويب واتساب: «أتمتة الردود بالكلمات».')}</p>
                <div className="flex justify-end">
                    <Button type="button" onClick={saveMode} data-testid="ig-mode-save">{t('حفظ')}</Button>
                </div>
            </section>
        </Card>
    );
}
