import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** صلاحيةُ الموظّف على «محادثات واتساب» — انظر `Permissions::INBOX_VIEW` */
export type InboxLevel = 'none' | 'view' | 'reply';

/** ما يرسله الخادم — أو لا شيء لمتجرٍ لم يُفتح له الصندوق (`EmployeeController::inboxField`) */
export interface InboxAccess {
    level: InboxLevel;
    /** صاحبُ النشاط وحده يمنحها — ولا يُعدِّل أحدٌ صلاحيته هو */
    can_grant: boolean;
}

const OPTIONS: { key: InboxLevel; title: string; hint: string }[] = [
    { key: 'none', title: 'بدون صلاحية', hint: 'لا يرى المحادثات ولا يفتحها' },
    { key: 'view', title: 'مشاهدة فقط', hint: 'يقرأ المحادثات ولا يرسل ردًّا' },
    { key: 'reply', title: 'مشاهدة ورد', hint: 'يقرأ المحادثات ويردّ على العملاء من رقم المتجر' },
];

/**
 * ثلاثةُ مستويات لا مربّعان — «يردّ ولا يقرأ» ليس حالًا تُختار.
 *
 * ومستقلّةٌ عن الأقسام والوظيفة: لا يمنحها «أدوات التسويق» ولا «مدير
 * الفرع»، ولا يمحوها اختيارُ «اتبع صلاحيات الوظيفة». ومن لا يملك منحها
 * يراها معطّلةً بسببها مكتوبًا — والخادمُ يردّ ما يُرسل سواها.
 */
export default function InboxAccessField({
    value,
    canGrant,
    onChange,
    error,
}: {
    value: InboxLevel;
    canGrant: boolean;
    onChange: (level: InboxLevel) => void;
    error?: string;
}) {
    const t = useTranslate();

    return (
        <div className="space-y-3" data-testid="inbox-access">
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-3" role="radiogroup" aria-label={t('محادثات واتساب')}>
                {OPTIONS.map((opt) => {
                    const picked = value === opt.key;

                    return (
                        <button
                            key={opt.key}
                            type="button"
                            role="radio"
                            aria-checked={picked}
                            disabled={!canGrant}
                            data-testid={`inbox-access-${opt.key}`}
                            onClick={() => onChange(opt.key)}
                            className={cn(
                                'rounded-[10px] border p-3 text-start transition-colors',
                                picked ? 'border-[#111] bg-[#f9fafb]' : 'border-[#e5e7eb] hover:bg-[#f9fafb]',
                                !canGrant && 'cursor-not-allowed opacity-60 hover:bg-transparent',
                            )}
                        >
                            <span className="flex items-center gap-2">
                                <span
                                    className={cn(
                                        'flex size-4 shrink-0 items-center justify-center rounded-full border',
                                        picked ? 'border-[#111]' : 'border-[#d1d5db]',
                                    )}
                                >
                                    {picked && <span className="size-2 rounded-full bg-[#111]" />}
                                </span>
                                <span className="text-sm font-medium text-[#111]">{t(opt.title)}</span>
                            </span>
                            <span className="mt-1 block ps-6 text-[12px] text-[#6b7280]">{t(opt.hint)}</span>
                        </button>
                    );
                })}
            </div>

            {!canGrant && (
                <p className="text-[12px] text-[#6b7280]" data-testid="inbox-access-locked">
                    {t('صلاحيات محادثات واتساب يمنحها صاحب النشاط وحده.')}
                </p>
            )}

            {error && <p className="text-[12px] text-[#b91c1c]">{error}</p>}
        </div>
    );
}
