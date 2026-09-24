import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Check, History, RotateCcw, Upload } from 'lucide-react';

import { useConfirm } from '@/Components/ConfirmDialog';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface ThemeVersion {
    id: number;
    number: number;
    at: string | null;
    by: string | null;
    note: string | null;
    current: boolean;
}

export interface PublishState {
    /** كم مفتاحًا يفارق المنشور */
    changed: number;
    /** وأسماؤها كما يقرؤها صاحبُها */
    fields: string[];
    /** مراجعةُ المسوّدة التي على هذه الشاشة — تُرسَل مع النشر */
    revision: number;
    published_at: string | null;
    versions: ThemeVersion[];
}

/**
 * شريطُ النشر: أفي متجرك ما لم يره زبونُك بعد؟
 *
 * ═══ ولمَ شريطٌ لا زرّ ═══
 *
 * النشرُ ليس فعلًا يُضغط متى شاء صاحبُه — هو جوابُ سؤالٍ يسأله كلَّ مرّةٍ
 * يفتح فيها محرّره: «ما الذي كتبتُه ولم يصل بعد؟». فيُقال له العددُ
 * والأسماء، لا العددُ وحدَه: «٣ تغييرات» تُقلق ولا تُفيد، و«العنوان
 * والنبذة والتذييل» تُراجَع في لحظة.
 *
 * ═══ ولا يُقال «نُشر بنجاح» إلّا بعد أن يقع ═══
 *
 * الردُّ من الخادم هو الذي يقول — لا الضغطة. فمن ضغط مرّتين يُقال له
 * «الموقع محدّث» لا «نُشر» مرّتين، ومن نشر مسوّدةً بدّلها زميلُه يُردّ
 * ويُقال له لماذا (انظر `ThemePublisher::publish` و`StaleDraft`).
 *
 * ═══ والاستعادةُ إلى المحرّر لا إلى الموقع ═══
 *
 * وهي قاعدةُ البانِي نفسُها (`Site.tsx`): يراها صاحبُها ويراجعها ثمّ
 * ينشرها بيده. ولو كُتبت على الحيّ رأسًا لَتبدّل موقعٌ يعمل بضغطةٍ في
 * شاشة تاريخ — فتُسأل قبلها سؤالٌ صريح.
 */
export default function PublishBar({ state }: { state: PublishState }) {
    const t = useTranslate();
    const [ask, confirmDialog] = useConfirm();
    const [history, setHistory] = useState(false);
    const [busy, setBusy] = useState(false);

    const dirty = state.changed > 0;

    const publish = async () => {
        if (! await ask({
            message: 'ننشر تغييراتك الآن؟ سيراها زبائنك فورًا.',
            action: 'نشر',
        })) return;

        setBusy(true);
        router.post(
            route('admin.website.store.publish'),
            // والمراجعةُ تُرسَل: من نشر مسوّدةً بدّلها زميلُه يُردّ ويُقال له
            { revision: state.revision },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    return (
        <>
            <div
                data-testid="publish-bar"
                className="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-[14px] border border-[var(--ui-border,#e8e8e8)] bg-white px-4 py-3"
            >
                <div className="min-w-0">
                    {dirty ? (
                        <>
                            <p className="flex items-center gap-2 text-[13px] font-semibold text-[#b45309]">
                                <Badge variant="warning" data-testid="unpublished">
                                    {t('تغييرات غير منشورة')}
                                </Badge>
                                <span className="font-normal text-[#6b7280]">{state.changed}</span>
                            </p>
                            {state.fields.length > 0 && (
                                <p className="mt-1 text-[12px] leading-relaxed text-[#9ca3af]">
                                    {state.fields.join(' · ')}
                                </p>
                            )}
                        </>
                    ) : (
                        <p className="flex items-center gap-2 text-[13px] font-semibold text-[#111]" data-testid="up-to-date">
                            <Check className="size-4 text-[#16a34a]" />
                            {t('الموقع محدّث')}
                            {state.published_at && (
                                <span className="font-normal text-[#9ca3af]">
                                    {t('آخر نشر')} {state.published_at}
                                </span>
                            )}
                        </p>
                    )}
                </div>

                <div className="flex items-center gap-2">
                    {state.versions.length > 0 && (
                        <Button type="button" variant="ghost" size="sm" onClick={() => setHistory(true)} data-testid="open-history">
                            <History />
                            {t('النسخ السابقة')}
                        </Button>
                    )}
                    <Button type="button" onClick={publish} loading={busy} disabled={! dirty} data-testid="publish">
                        <Upload />
                        {t('نشر التغييرات')}
                    </Button>
                </div>
            </div>

            <Dialog open={history} onOpenChange={setHistory}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('النسخ السابقة')}</DialogTitle>
                    </DialogHeader>

                    <div className="space-y-2 px-5 pb-5" data-testid="history">
                        <p className="text-[13px] text-[#6b7280]">
                            {t('الاستعادة تُرجع النسخة إلى محرّرك — تعاينها ثمّ تنشرها إن رضيت.')}
                        </p>
                        {/*
                            وما لا تُعيده الاستعادةُ يُقال هنا لا في وثيقةٍ:
                            من استعاد تصميمًا قديمًا قد يظنّ أنّه استعاد معه
                            رسمَ توصيلٍ قديمًا — وذلك مالٌ يُحصَّل من زبونه.
                        */}
                        <p className="text-[12px] leading-relaxed text-[#9ca3af]">
                            {t('ولا تُعيد أسعارًا ولا مخزونًا ولا إعدادات دفعٍ وتوصيل — تلك حال متجرك الآن.')}
                        </p>

                        {state.versions.map((v) => (
                            <div
                                key={v.id}
                                className={cn(
                                    'flex flex-wrap items-center justify-between gap-3 rounded-[12px] border px-4 py-3',
                                    v.current ? 'border-[#111] bg-[#fafafa]' : 'border-[var(--ui-border,#e8e8e8)]',
                                )}
                            >
                                <div className="min-w-0">
                                    <p className="flex items-center gap-2 text-[13px] font-semibold text-[#111]">
                                        {t('نشرة')} {v.number}
                                        {v.current && <Badge variant="success">{t('المنشورة الآن')}</Badge>}
                                    </p>
                                    <p className="mt-0.5 text-[12px] text-[#9ca3af]">
                                        {v.at} {v.by && `· ${v.by}`}
                                        {v.note && ` · ${v.note}`}
                                    </p>
                                </div>

                                {! v.current && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        data-testid={`restore-${v.number}`}
                                        onClick={async () => {
                                            if (! await ask({
                                                message: 'نُرجع هذه النشرة إلى محرّرك؟ لن يتغيّر موقعك حتى تنشرها.',
                                                action: 'استعادة',
                                            })) return;

                                            router.post(
                                                route('admin.website.store.restore', v.id),
                                                {},
                                                { preserveScroll: true, onSuccess: () => setHistory(false) },
                                            );
                                        }}
                                    >
                                        <RotateCcw />
                                        {t('استعادة')}
                                    </Button>
                                )}
                            </div>
                        ))}
                    </div>
                </DialogContent>
            </Dialog>

            {confirmDialog}
        </>
    );
}
