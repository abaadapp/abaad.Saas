import { useEffect, useState } from 'react';
import { Check, Film, ImageOff, RefreshCw } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';

/** ما يُحفظ من المنشور — معرّفُه عند Meta وأوّلُ سطره */
export interface AutomationPost {
    id: string;
    caption: string;
}

/** ما يعرضه المنتقي — `InstagramMessenger::posts` */
interface PickerPost extends AutomationPost {
    image: string | null;
    reel: boolean;
    link: string | null;
    at: string | null;
}

/**
 * ‏‏على أيّ منشوراتٍ تعمل الأتمتة — كلُّها، أو ما يختاره التاجر من صور حسابه.
 *
 * والمنشوراتُ تُقرأ من إنستقرام حين يختار «منشورات أختارها» لا مع الصفحة. وما اختير سابقًا وليس في
 * آخر المنشورات يبقى محفوظًا ويُعرض باسمه.
 */
export default function InstagramPosts({ value, onChange, max }: {
    value: AutomationPost[];
    onChange: (next: AutomationPost[]) => void;
    max: number;
}) {
    const t = useTranslate();
    const [choosing, setChoosing] = useState(value.length > 0);
    const [posts, setPosts] = useState<PickerPost[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [round, setRound] = useState(0);

    useEffect(() => {
        if (! choosing) {
            return;
        }

        let live = true;
        setPosts(null);
        setError(null);

        fetch(route('admin.integrations.instagram.automations.posts'), { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((d: { ok?: boolean; posts?: PickerPost[]; error?: string | null }) => {
                if (! live) {
                    return;
                }

                setPosts(d.posts ?? []);
                setError(d.ok ? null : (d.error ?? t('لم نقرأ منشورات إنستقرام — حاول بعد قليل.')));
            })
            .catch(() => {
                if (live) {
                    setPosts([]);
                    setError(t('لم نقرأ منشورات إنستقرام — حاول بعد قليل.'));
                }
            });

        return () => {
            live = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [choosing, round]);

    const toggle = (p: AutomationPost) => {
        if (value.some((v) => v.id === p.id)) {
            onChange(value.filter((v) => v.id !== p.id));
        } else if (value.length < max) {
            onChange([...value, { id: p.id, caption: p.caption }]);
        }
    };

    const elsewhere = posts === null ? [] : value.filter((v) => ! posts.some((p) => p.id === v.id));

    return (
        <div className="space-y-3" data-testid="ig-posts">
            <div className="flex flex-wrap gap-2" role="radiogroup" aria-label={t('على أي منشورات؟')}>
                <button type="button" role="radio" aria-checked={! choosing} onClick={() => { setChoosing(false); onChange([]); }} data-testid="ig-posts-all"
                    className={`rounded-full px-3 py-1.5 text-[12px] font-medium ${! choosing ? 'bg-[#111] text-white' : 'bg-[#f3f4f6] text-[#4b4b4b]'}`}>
                    {t('كل المنشورات والريلز')}
                </button>
                <button type="button" role="radio" aria-checked={choosing} onClick={() => setChoosing(true)} data-testid="ig-posts-some"
                    className={`rounded-full px-3 py-1.5 text-[12px] font-medium ${choosing ? 'bg-[#111] text-white' : 'bg-[#f3f4f6] text-[#4b4b4b]'}`}>
                    {t('منشورات أختارها')}
                </button>
            </div>

            {choosing && (
                <>
                    <p className="text-[12px] text-[#6b7280] tabular-nums" data-testid="ig-posts-count">{t('اخترت :n من :max', { n: value.length, max })}</p>
                    {posts === null && <p className="text-[13px] text-[#9ca3af]">{t('نقرأ منشوراتك…')}</p>}
                    {error && (
                        <div className="flex flex-wrap items-center gap-2 rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] text-[#b45309]" data-testid="ig-posts-error">
                            <span>{error}</span>
                            <Button type="button" variant="outline" size="sm" onClick={() => setRound(round + 1)}>
                                <RefreshCw />
                                {t('أعد المحاولة')}
                            </Button>
                        </div>
                    )}
                    {posts !== null && posts.length === 0 && ! error && <p className="text-[13px] text-[#6b7280]">{t('لا منشورات في حسابك بعد.')}</p>}

                    <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
                        {(posts ?? []).map((p) => {
                            const on = value.some((v) => v.id === p.id);

                            return (
                                <button key={p.id} type="button" aria-pressed={on} onClick={() => toggle(p)} disabled={! on && value.length >= max} data-testid={`ig-post-${p.id}`}
                                    aria-label={p.caption || t('منشور بلا نص')}
                                    className={`relative aspect-square overflow-hidden rounded-[10px] border-2 bg-[#f3f4f6] disabled:opacity-40 ${on ? 'border-[#111]' : 'border-transparent'}`}>
                                    {p.image
                                        ? <img src={p.image} alt="" loading="lazy" referrerPolicy="no-referrer" className="size-full object-cover" />
                                        : <span className="flex size-full items-center justify-center text-[#9ca3af]"><ImageOff className="size-5" /></span>}
                                    {p.reel && <Film className="absolute end-1.5 top-1.5 size-4 text-white drop-shadow" />}
                                    {on && <span className="absolute start-1.5 top-1.5 flex size-5 items-center justify-center rounded-full bg-[#111] text-white"><Check className="size-3" /></span>}
                                </button>
                            );
                        })}
                    </div>

                    {elsewhere.length > 0 && (
                        <ul className="space-y-1" data-testid="ig-posts-elsewhere">
                            {elsewhere.map((p) => (
                                <li key={p.id} className="flex items-center justify-between gap-2 rounded-[10px] bg-[#f9fafb] px-3 py-2 text-[12px] text-[#4b4b4b]">
                                    <span className="truncate">{p.caption || t('منشور أقدم')}</span>
                                    <button type="button" onClick={() => toggle(p)} className="text-[#b91c1c]">{t('إزالة')}</button>
                                </li>
                            ))}
                        </ul>
                    )}
                </>
            )}
        </div>
    );
}
