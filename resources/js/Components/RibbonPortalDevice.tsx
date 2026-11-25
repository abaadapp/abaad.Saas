import { useId, useState, type FormEvent } from 'react';
import { PhoneCall, ShieldCheck, Store } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { useTranslate } from '@/lib/i18n';
import { askPortal, DEVICE_DIGITS, deviceDigits } from '@/lib/ribbon-portal';
import { cn } from '@/lib/utils';

/**
 * اعتمادُ جهاز العميل — يكتب الموظّفُ الرقمَ الذي على شاشة العميل ويقول كيف تحقّق منه.
 *
 * ولا يُعرض الرقمُ هنا أبدًا: فلا يُعتمد جهازٌ لم يره أحد ولم يُقرأ له. ومن بعيدٍ لا يُعتمد إلّا
 * بعد أن يتّصل الموظّفُ هو بالرقم المسجَّل — ويقرّ بذلك، ويُكتب مع اسمه.
 */
export function DeviceApproval<T>({ url, phone, onDone, className }: { url: string; phone: string; onDone?: (data: T) => void; className?: string }) {
    const t = useTranslate();
    const id = useId();
    const [digits, setDigits] = useState('');
    const [how, setHow] = useState<'shop' | 'call' | null>(null);
    const [called, setCalled] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const failed = t('تعذّر ذلك الآن. تحقّق من الاتصال وحاول من جديد.');
    const ready = digits.length === DEVICE_DIGITS && (how === 'shop' || (how === 'call' && called));

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        // نافذةُ الصندوق فيها نماذجُ غيره: هذا لا يُرسلها
        e.stopPropagation();
        if (busy || !ready) return;
        setBusy(true);
        setError(null);
        const { data, error: refused } = await askPortal<T>(url, 'POST', failed, { device: digits, how, called: how === 'call' && called });
        setBusy(false);
        setDigits('');
        if (refused || !data) return setError(refused ?? failed);
        toast.success(t('اعتُمد الجهاز، وتفتح صفحة العميل وحدها'));
        onDone?.(data);
    };
    const choose = (next: 'shop' | 'call') => {
        setHow(next);
        // إقرارٌ بالاتّصال لا ينتقل من اختيارٍ إلى اختيار
        setCalled(false);
    };

    return (
        <form onSubmit={(e) => void submit(e)} className={cn('space-y-3', className)} data-testid="portal-approve">
            <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label={t('كيف تحققت من العميل')}>
                {([['shop', Store, 'العميل أمامي'], ['call', PhoneCall, 'عن بُعد باتصال']] as const).map(([key, Icon, label]) => (
                    <button
                        key={key}
                        type="button"
                        role="radio"
                        aria-checked={how === key}
                        onClick={() => choose(key)}
                        className={cn(
                            'flex min-h-11 items-center justify-center gap-2 rounded-xl border px-2 text-[13px] font-medium transition-colors',
                            how === key ? 'border-[#111] bg-[#111] text-white' : 'border-[var(--ui-border,#e8e8e8)] bg-white text-[#374151] hover:bg-[#fafafa]',
                        )}
                    >
                        <Icon className="size-4 shrink-0" />
                        {t(label)}
                    </button>
                ))}
            </div>

            {how === 'shop' && <p className="text-[12px] text-[#6b7280]">{t('تعرف أنه صاحب الرقم، أو اتصلت برقمه المسجّل فرنّ في يده.')}</p>}
            {how === 'call' && (
                <div className="space-y-2 rounded-xl bg-[#fffbeb] p-3 text-[12px] text-[#92400e]">
                    <p>
                        {t('اتصل أنت بالرقم المسجّل')} <b dir="ltr">{phone}</b>{t('، واسأله عن اسمه ورقم جهازه. لا تعتمد من اتصل بك أو راسلك.')}
                    </p>
                    <label className="flex items-start gap-2 font-medium">
                        <input type="checkbox" className="mt-0.5 size-5 shrink-0 accent-[#111]" checked={called} onChange={(e) => setCalled(e.target.checked)} />
                        <span>{t('اتصلت بالرقم المسجّل، وتأكدت من العميل ورقم جهازه')}</span>
                    </label>
                </div>
            )}

            <div className="space-y-1.5">
                <Label htmlFor={id}>{t('رقم الجهاز الظاهر على شاشة العميل')}</Label>
                <div className="flex gap-2">
                    <Input
                        id={id}
                        dir="ltr"
                        inputMode="numeric"
                        autoComplete="off"
                        className="text-center font-semibold tracking-[0.2em]"
                        value={digits}
                        onChange={(e) => {
                            setError(null);
                            setDigits(deviceDigits(e.target.value));
                        }}
                    />
                    <Button type="submit" loading={busy} disabled={!ready}>
                        <ShieldCheck />
                        {t('اعتماد')}
                    </Button>
                </div>
            </div>
            {error && <p role="alert" className="text-[12px] text-[#b91c1c]">{error}</p>}
        </form>
    );
}
