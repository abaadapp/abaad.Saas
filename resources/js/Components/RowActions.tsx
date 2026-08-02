import { type ReactNode, useState } from 'react';
import { router } from '@inertiajs/react';
import { Eye, MoreVertical, Pencil, Trash2 } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';

export interface RowAction {
    label: string;
    icon?: ReactNode;
    href?: string;
    routeName?: string;
    onSelect?: () => void;
    danger?: boolean;
}

interface Props {
    show?: { href: string; routeName: string };
    edit?: { href: string; routeName: string };
    /**
     * الحذف يمرّ بتأكيد قبل الإرسال.
     *
     * `label` لأن الإجراء ليس حذفًا دائمًا: تعطيل شركة في لوحة المنصة يغيّر
     * حالتها ولا يمحو سجلّها. كانت القائمة تقول «حذف» بينما تقول صفحة الشركة
     * نفسها «تعطيل» عن العملية عينها — فيتردّد المشغّل أو يُبلّغ بغير الواقع.
     */
    destroy?: { url: string; message?: string; label?: string };
    extra?: RowAction[];
}

/**
 * قائمة إجراءات الصف — بديل <x-dropdown> داخل الجداول والبطاقات.
 *
 * الحذف يفتح نافذة تأكيد لا confirm() المتصفح: الأخيرة تتجاهل الاتجاه
 * واللغة، وكانت في Blade تعرض نصًا عربيًا في حوار إنجليزي.
 */
export default function RowActions({ show, edit, destroy, extra = [] }: Props) {
    const t = useTranslate();
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);

    const remove = () => {
        if (!destroy) return;
        setBusy(true);
        router.delete(destroy.url, {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setConfirming(false);
            },
        });
    };

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon-sm" aria-label={t('إجراءات')}>
                        <MoreVertical />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-44">
                    {show && (
                        <DropdownMenuItem asChild>
                            <SmartLink routeName={show.routeName} href={show.href}>
                                <Eye />
                                {t('عرض')}
                            </SmartLink>
                        </DropdownMenuItem>
                    )}
                    {edit && (
                        <DropdownMenuItem asChild>
                            <SmartLink routeName={edit.routeName} href={edit.href}>
                                <Pencil />
                                {t('تعديل')}
                            </SmartLink>
                        </DropdownMenuItem>
                    )}
                    {extra.map((action, i) =>
                        action.href ? (
                            <DropdownMenuItem key={i} asChild>
                                <SmartLink routeName={action.routeName ?? ''} href={action.href}>
                                    {action.icon}
                                    {t(action.label)}
                                </SmartLink>
                            </DropdownMenuItem>
                        ) : (
                            <DropdownMenuItem
                                key={i}
                                onSelect={action.onSelect}
                                className={action.danger ? 'text-[#b91c1c]' : undefined}
                            >
                                {action.icon}
                                {t(action.label)}
                            </DropdownMenuItem>
                        ),
                    )}
                    {destroy && (
                        <>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                onSelect={(e) => {
                                    e.preventDefault();
                                    setConfirming(true);
                                }}
                                className="text-[#b91c1c]"
                            >
                                <Trash2 />
                                {t(destroy.label ?? 'حذف')}
                            </DropdownMenuItem>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{t(destroy?.label ? 'تأكيد الإجراء' : 'تأكيد الحذف')}</DialogTitle>
                    </DialogHeader>
                    <div className="px-5 pb-5">
                        <p className="text-sm text-[#4b4b4b]">
                            {t(destroy?.message ?? 'سيُحذف هذا السجل نهائيًا. هل تريد المتابعة؟')}
                        </p>
                        <div className="mt-5 flex justify-end gap-2">
                            <Button variant="outline" onClick={() => setConfirming(false)} disabled={busy}>
                                {t('إلغاء')}
                            </Button>
                            <Button variant="danger" onClick={remove} disabled={busy}>
                                {busy ? '…' : t(destroy?.label ?? 'حذف')}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
