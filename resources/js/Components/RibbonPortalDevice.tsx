import { useId, useState, type FormEvent } from 'react';
import { ShieldCheck } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { useTranslate } from '@/lib/i18n';
import { askPortal, DEVICE_DIGITS, deviceDigits } from '@/lib/ribbon-portal';
import { cn } from '@/lib/utils';

/**
 * اعتمادُ جهاز العميل — يكتب الموظّفُ الرقمَ الذي يراه على شاشة العميل.
 *
 * ولا يُعرض الرقمُ هنا أبدًا: فلا يُعتمد جهازٌ لا يراه أحد.
 */
export function DeviceApproval<T>({ url, onDone, className }: { url: string; onDone?: (data: T) => void; className?: string }) {
    const t = useTranslate();
    const id = useId();
    const [digits, setDigits] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const failed = t('تعذّر ذلك الآن. تحقّق من الاتصال وحاول من جديد.');

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        // نافذةُ الصندوق فيها نماذجُ غيره: هذا لا يُرسلها
        e.stopPropagation();
        if (busy || digits.length !== DEVICE_DIGITS) return;
        setBusy(true);
        setError(null);
        const { data, error: refused } = await askPortal<T>(url, 'POST', failed, { device: digits });
        setBusy(false);
        setDigits('');
        if (refused || !data) return setError(refused ?? failed);
        toast.success(t('اعتُمد الجهاز، وتفتح صفحة العميل وحدها'));
        onDone?.(data);
    };

    return (
        <form onSubmit={(e) => void submit(e)} className={cn('space-y-2', className)} data-testid="portal-approve">
            <Label htmlFor={id}>{t('رقم الجهاز الظاهر على شاشة العميل')}</Label>
            <div className="flex gap-2">
                <Input
                    id={id}
                    dir="ltr"
                    inputMode="numeric"
                    autoComplete="off"
                    className="text-center font-semibold tracking-[0.3em]"
                    value={digits}
                    onChange={(e) => {
                        setError(null);
                        setDigits(deviceDigits(e.target.value));
                    }}
                />
                <Button type="submit" loading={busy} disabled={digits.length !== DEVICE_DIGITS}>
                    <ShieldCheck />
                    {t('اعتماد')}
                </Button>
            </div>
            {error && <p role="alert" className="text-[12px] text-[#b91c1c]">{error}</p>}
            <p className="text-[12px] text-[#6b7280]">{t('اعتمد جهاز من يقف أمامك وتعرف أنه صاحب الرقم. إن لم تعرفه فاتصل برقمه المسجّل وتأكد أنه يرن في يده.')}</p>
        </form>
    );
}
