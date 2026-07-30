import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

interface Props {
    on: boolean;
    onChange: (value: boolean) => void;
    label: string;
    hint?: string;
}

/**
 * مفتاح تبديل — مشترك بين إعدادات المتجر وإعدادات المنصة.
 *
 * يعتمد الخصائص المنطقية (start) فينعكس مع اتجاه المستند تلقائيًا:
 * المقبض يقف يمينًا في العربية ويسارًا في الإنجليزية بلا كود إضافي.
 */
export default function Toggle({ on, onChange, label, hint }: Props) {
    const t = useTranslate();

    return (
        <div className="flex items-center justify-between gap-3 py-2">
            <div>
                <p className="text-sm font-medium text-[#111]">{t(label)}</p>
                {hint && <p className="mt-0.5 text-[12px] text-[#9ca3af]">{t(hint)}</p>}
            </div>
            <button
                type="button"
                role="switch"
                aria-checked={on}
                aria-label={t(label)}
                onClick={() => onChange(!on)}
                className={cn(
                    'relative h-6 w-12 shrink-0 rounded-full transition-colors',
                    on ? 'bg-[#111]' : 'bg-[#d1d5db]',
                )}
            >
                <span
                    className={cn(
                        'absolute top-0.5 size-5 rounded-full bg-white shadow transition-[inset-inline-start]',
                        on ? 'start-[26px]' : 'start-0.5',
                    )}
                />
            </button>
        </div>
    );
}
