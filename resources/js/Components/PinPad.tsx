import { useCallback, useEffect, useRef, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Delete, TriangleAlert } from 'lucide-react';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

const KEYS = [1, 2, 3, 4, 5, 6, 7, 8, 9] as const;
const LENGTH = 4;

interface Props {
    /**
     * من أي شاشةٍ يُرسل الرمز.
     *
     * الخادم يقرؤها ليختار لغة رسالة الرفض: شاشة الرمز المستقلّة إنجليزية
     * دائمًا، وتبويب الدخول يتبع لغة الصفحة — انظر LoginController::pinAttempt.
     */
    from: 'pin' | 'login';
}

/**
 * لوحة أرقام دخول الموظف — أربعة أرقام تُرسَل وحدها عند اكتمالها.
 *
 * مشتركة بين شاشة الرمز المستقلّة (قفل نقطة البيع) وتبويب الرمز في شاشة
 * الدخول: نسختان من منطقٍ فيه إرسالٌ تلقائي ومنعُ تكرارٍ ومستمع لوحة مفاتيح
 * تفترقان بعد شهر، فتُصلح رجّةَ الخطأ في واحدةٍ وتبقى الأخرى صامتة.
 */
export default function PinPad({ from }: Props) {
    const { errors } = usePage<PageProps>().props;
    const t = useTranslate();
    const [pin, setPin] = useState('');
    const [shake, setShake] = useState(false);
    const form = useForm({ pin: '' });
    // النموذج يُرسل من داخل مستمع لوحة المفاتيح، فيقرأ الحالة عبر مرجع لا لقطة
    const busy = useRef(false);

    const send = useCallback(
        (value: string) => {
            busy.current = true;
            form.transform(() => ({ pin: value, from }));
            form.post(route('pin.attempt'), {
                onFinish: () => {
                    busy.current = false;
                    setPin('');
                },
            });
        },
        [form, from],
    );

    const push = useCallback((n: number) => {
        if (busy.current) return;
        // دالة التحديث تبقى نقية: React ينفّذها مرّتين في وضع التطوير، فإرسال
        // الطلب من داخلها كان يسجّل الدخول مرّتين ويترك الشاشة على حالها.
        setPin((prev) => (prev.length >= LENGTH ? prev : prev + String(n)));
    }, []);

    // الإرسال أثرٌ جانبي، فمكانه بعد اكتمال الرقم الرابع لا داخل التحديث
    useEffect(() => {
        if (pin.length === LENGTH && !busy.current) send(pin);
    }, [pin, send]);

    const backspace = useCallback(() => {
        if (!busy.current) setPin((p) => p.slice(0, -1));
    }, []);

    // لوحة المفاتيح الفعلية — الكاشير قد يستخدم لوحة أرقام لا شاشة لمس
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key >= '0' && e.key <= '9') push(Number(e.key));
            else if (e.key === 'Backspace') backspace();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [push, backspace]);

    // رجّة قصيرة عند رفض الرمز — إشارة بصرية تسبق قراءة النص
    useEffect(() => {
        if (!errors.pin) return;
        setShake(true);
        const id = setTimeout(() => setShake(false), 400);
        return () => clearTimeout(id);
    }, [errors.pin]);

    /* الضغط يُعبَّر عنه بلون أغمق لا بـ active:scale — التصغير يُزيح المفتاح
       تحت الإصبع فيبدو النقر كأنه أخطأ هدفه، ويكسر ثبات أبعاد لوحة المفاتيح. */
    const key =
        'flex h-16 items-center justify-center rounded-[14px] bg-[#f7f7f5] text-2xl font-bold text-[#111] ' +
        'transition-colors hover:bg-[#efefec] active:bg-[#e4e4e0] focus-visible:outline-none ' +
        'focus-visible:ring-2 focus-visible:ring-[var(--ring)]';

    return (
        <>
            <style>{`@keyframes abaad-shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-8px)}75%{transform:translateX(8px)}}`}</style>

            {errors.pin && (
                <div
                    role="alert"
                    className="mb-5 flex items-start gap-2 rounded-[10px] border border-[#fecaca] bg-[#fef2f2] p-3 text-[13px] text-[#b91c1c]"
                >
                    <TriangleAlert className="mt-px size-4 shrink-0" />
                    <span>{errors.pin}</span>
                </div>
            )}

            <div
                className="mb-7 flex items-center justify-center gap-4"
                style={shake ? { animation: 'abaad-shake 0.4s' } : undefined}
            >
                {Array.from({ length: LENGTH }, (_, i) => (
                    <span
                        key={i}
                        className={cn(
                            'size-3.5 rounded-full border-2 transition-colors',
                            pin.length > i ? 'border-[#111] bg-[#111]' : 'border-[#d1d5db]',
                        )}
                    />
                ))}
            </div>

            <div className="grid grid-cols-3 gap-3">
                {KEYS.map((n) => (
                    <button key={n} type="button" onClick={() => push(n)} className={key}>
                        {n}
                    </button>
                ))}
                <button
                    type="button"
                    onClick={() => setPin('')}
                    className={cn(key, 'text-sm font-medium text-[#6b7280]')}
                >
                    {t('مسح')}
                </button>
                <button type="button" onClick={() => push(0)} className={key}>
                    0
                </button>
                <button
                    type="button"
                    onClick={backspace}
                    aria-label={t('حذف')}
                    className={cn(key, 'text-[#6b7280]')}
                >
                    <Delete className="size-6" />
                </button>
            </div>

            {form.processing && (
                <p className="mt-6 text-center text-[13px] text-[#9ca3af]">{t('جارٍ الدخول…')}</p>
            )}
        </>
    );
}
