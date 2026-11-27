import { useState } from 'react';
import { router } from '@inertiajs/react';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';

/** ‏‏حالُ المفتاح كما يقرؤه الصندوقُ الآن — انظر `App\Support\TillCatalogSwitch::state` */
export interface PosCatalogState {
    /** ‏ما يسري فعلًا: المنصّةُ والخادمُ معًا، وصفٌّ يُقرأ */
    on: boolean;
    /** ‏ما ضُبط من هذه الشاشة */
    platform: boolean;
    /** ‏إطفاءُ الخادم (`POS_CATALOG_ON_DEVICE`) — يغلب ما هنا */
    env: boolean;
    readable: boolean;
    changed_at: string | null;
    history: { at: string; by: string; from: boolean; to: boolean }[];
}

/**
 * ‏‏مفتاحُ كتالوج الصندوق على الجهاز — لمدير المنصّة وحده (`super-admin.pos-catalog.update`).
 *
 * يسري من سؤال الصندوق التالي، بلا نشرٍ ولا `.env` ولا إعادة تشغيل. ولا يمسّ في الأجهزة
 * سلّةً ولا بيعةً معلّقةً ولا نسخةَ الكتالوج المحفوظة: مطفأً يأخذ الصندوقُ أصنافَه من
 * الخادم كما كان قبل v6.600، ومشغَّلًا يعود إلى نسخة جهازه بعد أن يُتحقَّق منها صفًّا صفًّا.
 */
export default function PosCatalogSwitch({ state, locale }: { state: PosCatalogState; locale?: string }) {
    const t = useTranslate();
    const [confirm, dialog] = useConfirm();
    const [busy, setBusy] = useState(false);
    const when = (iso: string) =>
        new Intl.DateTimeFormat(locale === 'en' ? 'en-GB' : 'ar-OM', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Muscat' }).format(new Date(iso));
    const word = (on: boolean) => (on ? t('مشغّل') : t('مطفأ'));

    const flip = async () => {
        const next = !state.platform;
        const ok = await confirm({
            title: next ? t('تشغيل كتالوج الصندوق على الجهاز') : t('إطفاء كتالوج الصندوق على الجهاز'),
            message: next
                ? t('تعود الصناديق إلى نسخة الأصناف المحفوظة على أجهزتها، بعد أن تتحقّق منها مع الخادم صفًّا صفًّا. يسري من سؤال كلّ صندوقٍ التالي. لا تُمسّ السلال ولا المبيعات المعلّقة.')
                : t('تأخذ الصناديق أصنافها من الخادم مع كلّ فتح، كما كانت قبل v6.600. يسري من سؤال كلّ صندوقٍ التالي، خلال نصف دقيقة. لا تُمسّ السلال ولا المبيعات المعلّقة ولا النسخ المحفوظة على الأجهزة.'),
            action: next ? t('تشغيل') : t('إطفاء'),
            danger: !next,
        });
        if (!ok) return;
        router.post(route('super-admin.pos-catalog.update'), { enabled: next }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    };

    return (
        <Card className="p-6" data-testid="pos-catalog-switch">
            <div className="mb-1 flex flex-wrap items-center gap-2">
                <h3 className="text-[18px] font-bold text-[#111]">{t('كتالوج الصندوق على الجهاز')}</h3>
                <Badge variant={state.on ? 'success' : 'warning'} data-testid="pos-catalog-state">
                    {state.on ? t('يعمل الآن') : t('لا يعمل الآن')}
                </Badge>
            </div>
            <p className="mb-5 text-[13px] leading-relaxed text-[#6b7280]">
                {t('يحفظ كلُّ صندوقٍ أصنافَ متجره على جهازه ويسأل الخادم عمّا تغيّر وحده. مطفأً، يأخذ الصندوقُ أصنافَه كلَّها من الخادم مع كلّ فتح. والسعرُ والضريبة يتحقّق منهما الخادم عند الدفع في الحالين.')}
            </p>

            {!state.env && (
                <p data-testid="pos-catalog-env-off" className="mb-4 rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] text-[#92400e]">
                    {t('مطفأ من إعداد الخادم (POS_CATALOG_ON_DEVICE)، وهذا يغلب المفتاح هنا. تشغيله من الخادم لا من هذه الشاشة.')}
                </p>
            )}
            {!state.readable && (
                <p data-testid="pos-catalog-unreadable" className="mb-4 rounded-[10px] bg-[#fef2f2] px-3 py-2 text-[12px] text-[#b91c1c]">
                    {t('تعذّرت قراءة المفتاح من القاعدة، فالصناديق تأخذ أصنافها من الخادم حتى يُقرأ.')}
                </p>
            )}

            <div className="flex flex-wrap items-center justify-between gap-3 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] px-4 py-3">
                <span className="text-sm text-[#111]">
                    {t('مفتاح المنصّة')}: <strong data-testid="pos-catalog-platform">{word(state.platform)}</strong>
                    {state.changed_at && <span className="ms-2 text-[12px] text-[#9ca3af]">· {t('آخر تغيير')} {when(state.changed_at)}</span>}
                </span>
                <Button data-testid="pos-catalog-flip" variant={state.platform ? 'outline' : 'primary'} loading={busy} onClick={() => void flip()}>
                    {state.platform ? t('إطفاء') : t('تشغيل')}
                </Button>
            </div>

            {state.history.length > 0 && (
                <div className="mt-5">
                    <p className="mb-2 text-[12px] font-semibold text-[#6b7280]">{t('آخر التغييرات')}</p>
                    <ul className="space-y-1.5" data-testid="pos-catalog-history">
                        {state.history.map((h, i) => (
                            <li key={i} className="text-[12px] text-[#4b4b4b]">
                                {when(h.at)} — {h.by}: {t('كان')} {word(h.from)}، {t('صار')} {word(h.to)}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {dialog}
        </Card>
    );
}
