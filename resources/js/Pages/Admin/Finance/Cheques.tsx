import { useState } from "react";
import { router, useForm, usePage } from "@inertiajs/react";
import { Undo2 } from "lucide-react";
import AdminLayout from "@/Layouts/AdminLayout";
import PageHeader from "@/Components/PageHeader";
import SectionTabs, { FINANCE_TABS } from "@/Components/SectionTabs";
import StatCard from "@/Components/StatCard";
import { Badge } from "@/Components/ui/badge";
import { Button } from "@/Components/ui/button";
import { Card } from "@/Components/ui/card";
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from "@/Components/ui/dialog";
import { Input } from "@/Components/ui/input";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/Components/ui/table";
import { money } from "@/lib/format";
import { useTranslate } from "@/lib/i18n";
import type { PageProps } from "@/types";

interface Cheque {
    id: number;
    number: string;
    customer: string;
    amount: number;
    reference: string | null;
    received_at: string | null;
    due_at: string | null;
    /** محسوبٌ في الخادم — ساعةُ الجهاز تُضبط بيد صاحبها */
    overdue: boolean;
    settled_at: string | null;
    note: string | null;
    status: string;
}

interface Props {
    status: string;
    statuses: string[];
    summary: {
        pending_count: number;
        pending_total: number;
        overdue_count: number;
        overdue_total: number;
        soon_count: number;
        bounced_count: number;
    };
    soonDays: number;
    cheques: Cheque[];
    bankAccounts: { value: string; label: string }[];
    context?: { currency: Parameters<typeof money>[1] };
}

/**
 * الشيكات — ورقةٌ في اليد ليست مالًا في البنك.
 *
 * وكان الشيكُ يُسجَّل فيدخل البنكَ في الدفتر لحظتَه، وقد يكون بتاريخٍ بعد
 * شهرين وقد يرتدّ. فهنا يُقرأ عمرُه: ما ينتظر، وما تأخّر عن استحقاقه، وما
 * رجع ولماذا.
 */
export default function Cheques() {
    const {
        status,
        statuses,
        summary,
        soonDays,
        cheques,
        bankAccounts,
        context,
    } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const [clearing, setClearing] = useState<Cheque | null>(null);
    const [bouncing, setBouncing] = useState<Cheque | null>(null);

    const clearForm = useForm({
        cleared_on: new Date().toISOString().slice(0, 10),
        bank_account_id: bankAccounts[0]?.value ?? "",
    });
    const bounceForm = useForm({ reason: "" });

    const go = (next: string) =>
        router.get(
            route("admin.finance.cheques"),
            { status: next },
            { preserveScroll: true },
        );

    return (
        <AdminLayout title="الشيكات">
            <PageHeader
                title="الشيكات"
                subtitle={t(
                    "شيكٌ في يدك ليس مالًا في بنكك — يُسجَّل صرفُه أو ارتدادُه هنا فيتحرّك الدفتر",
                )}
            />
            <SectionTabs tabs={FINANCE_TABS} current="admin.finance.cheques" />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard
                    stat={{
                        label: t("تحت التحصيل"),
                        value: m(summary.pending_total),
                        icon: "hourglass",
                        color: "info",
                    }}
                    index={0}
                />
                {/*
                    والمتأخّرُ يُفصل عن المنتظر ولا يُجمعان.

                    رقمٌ واحدٌ يبتلع شيكًا يستحقّ بعد شهرين وشيكًا مضى موعدُه
                    يجعل التاجر لا يعرف أيّهما يلاحق.
                */}
                <StatCard
                    stat={{
                        label: t("تأخّر استحقاقه"),
                        value: m(summary.overdue_total),
                        icon: "alert-triangle",
                        color: "danger",
                    }}
                    index={1}
                />
                <StatCard
                    stat={{
                        label: t("يستحقّ خلال :n أيام", { n: soonDays }),
                        value: String(summary.soon_count),
                        icon: "banknote",
                        color: "warning",
                    }}
                    index={2}
                />
            </div>

            <div className="mb-4 flex flex-wrap gap-2">
                {statuses.map((s) => (
                    <Button
                        key={s}
                        size="sm"
                        variant={s === status ? "primary" : "outline"}
                        onClick={() => go(s)}
                    >
                        {t(s)}
                        {s === "مرتجع" && summary.bounced_count > 0
                            ? ` (${summary.bounced_count})`
                            : ""}
                    </Button>
                ))}
            </div>

            <Card className="overflow-x-auto p-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t("الرقم")}</TableHead>
                            <TableHead>{t("العميل")}</TableHead>
                            <TableHead>{t("المبلغ")}</TableHead>
                            <TableHead>{t("رقم الشيك")}</TableHead>
                            <TableHead>{t("تاريخ القبض")}</TableHead>
                            <TableHead>{t("الاستحقاق")}</TableHead>
                            {status === "تحت التحصيل" && (
                                <TableHead>{t("إجراء")}</TableHead>
                            )}
                            {status !== "تحت التحصيل" && (
                                <TableHead>{t("التسوية")}</TableHead>
                            )}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {cheques.length === 0 && (
                            <TableRow>
                                <TableCell
                                    colSpan={7}
                                    className="py-10 text-center text-[13px] text-[#9ca3af]"
                                >
                                    {t("لا شيكات في هذه الحال.")}
                                </TableCell>
                            </TableRow>
                        )}
                        {cheques.map((c) => (
                            <TableRow key={c.id}>
                                <TableCell
                                    dir="ltr"
                                    className="text-start font-medium"
                                >
                                    {c.number}
                                </TableCell>
                                <TableCell>{c.customer}</TableCell>
                                <TableCell className="font-bold">
                                    {m(c.amount)}
                                </TableCell>
                                <TableCell
                                    dir="ltr"
                                    className="text-start text-[12px] text-[#6b7280]"
                                >
                                    {c.reference ?? "—"}
                                </TableCell>
                                <TableCell
                                    dir="ltr"
                                    className="text-start text-[12px]"
                                >
                                    {c.received_at ?? "—"}
                                </TableCell>
                                <TableCell
                                    dir="ltr"
                                    className="text-start text-[12px]"
                                >
                                    {c.due_at ?? (
                                        <span className="text-[#9ca3af]">
                                            {t("بلا موعد")}
                                        </span>
                                    )}
                                    {c.overdue && (
                                        <Badge
                                            variant="danger"
                                            className="ms-2"
                                        >
                                            {t("متأخر")}
                                        </Badge>
                                    )}
                                </TableCell>

                                {c.status === "تحت التحصيل" ? (
                                    <TableCell>
                                        <div className="flex gap-2">
                                            <Button
                                                size="sm"
                                                onClick={() => setClearing(c)}
                                            >
                                                {t("صُرِف")}
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="danger"
                                                onClick={() => setBouncing(c)}
                                            >
                                                <Undo2 />
                                                {t("ارتدّ")}
                                            </Button>
                                        </div>
                                    </TableCell>
                                ) : (
                                    <TableCell className="text-[12px]">
                                        <span dir="ltr">
                                            {c.settled_at ?? "—"}
                                        </span>
                                        {c.note && (
                                            <p className="mt-0.5 text-[#b91c1c]">
                                                {c.note}
                                            </p>
                                        )}
                                    </TableCell>
                                )}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Card>

            {/* ═══════════ صُرِف ═══════════ */}
            <Dialog
                open={clearing !== null}
                onOpenChange={(o) => !o && setClearing(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t("تسجيل صرف الشيك")}</DialogTitle>
                    </DialogHeader>

                    {/* والحشوُ على الجسم: `DialogContent` لا يحمله، فما بلا حشوٍ يلتصق بالحافّة */}
                    <div className="px-5 pb-5">
                        <p className="text-[13px] text-[#6b7280]">
                            {t(
                                "ينتقل المبلغ من «شيكات تحت التحصيل» إلى حسابك البنكي في الدفتر.",
                            )}
                        </p>

                        <label className="mt-4 block text-[13px]">
                            {t("تاريخ الصرف")}
                            <Input
                                type="date"
                                dir="ltr"
                                value={clearForm.data.cleared_on}
                                onChange={(e) =>
                                    clearForm.setData(
                                        "cleared_on",
                                        e.target.value,
                                    )
                                }
                            />
                        </label>
                        {clearForm.errors.cleared_on && (
                            <p className="mt-1 text-[12px] text-[#b91c1c]">
                                {clearForm.errors.cleared_on}
                            </p>
                        )}

                        {/*
                        والحسابُ يُسأل هنا مرّةً أخرى: التاجر قد يودع الشيك في
                        حسابٍ غير الذي كتبه يوم استلمه.
                    */}
                        {bankAccounts.length > 0 && (
                            <label className="mt-4 block text-[13px]">
                                {t("أودع في")}
                                <select
                                    className="mt-1 block w-full rounded-[8px] border border-[var(--ui-border,#e8e8e8)] p-2"
                                    value={clearForm.data.bank_account_id}
                                    onChange={(e) =>
                                        clearForm.setData(
                                            "bank_account_id",
                                            e.target.value,
                                        )
                                    }
                                >
                                    {bankAccounts.map((a) => (
                                        <option key={a.value} value={a.value}>
                                            {a.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}

                        <div className="mt-5 flex justify-end gap-2">
                            <Button
                                variant="outline"
                                onClick={() => setClearing(null)}
                            >
                                {t("إلغاء")}
                            </Button>
                            <Button
                                loading={clearForm.processing}
                                onClick={() =>
                                    clearForm.post(
                                        route(
                                            "admin.finance.cheques.clear",
                                            clearing!.id,
                                        ),
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => setClearing(null),
                                        },
                                    )
                                }
                            >
                                {t("تأكيد الصرف")}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>

            {/* ═══════════ ارتدّ ═══════════ */}
            <Dialog
                open={bouncing !== null}
                onOpenChange={(o) => !o && setBouncing(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t("تسجيل ارتداد الشيك")}</DialogTitle>
                    </DialogHeader>

                    {/*
                        وما سيحدث يُقال قبل الضغط لا بعده: عودةُ الذمّة تعني أنّ
                        الفاتورة تصير غيرَ مسدَّدة من جديد، وهو أثرٌ يُفاجئ من
                        ظنّ الزرّ مجرّد وسمٍ على صفّ.
                    */}
                    <div className="px-5 pb-5">
                        <p className="text-[13px] text-[#6b7280]">
                            {t(
                                "يُعكس قيد القبض، وتعود الذمّة على العميل، وتصير فاتورته غير مسدّدة.",
                            )}
                        </p>

                        <label className="mt-4 block text-[13px]">
                            {t("سبب الارتداد")}
                            <Input
                                value={bounceForm.data.reason}
                                onChange={(e) =>
                                    bounceForm.setData("reason", e.target.value)
                                }
                                placeholder={t("عدم كفاية الرصيد")}
                            />
                        </label>
                        {bounceForm.errors.reason && (
                            <p className="mt-1 text-[12px] text-[#b91c1c]">
                                {bounceForm.errors.reason}
                            </p>
                        )}

                        <div className="mt-5 flex justify-end gap-2">
                            <Button
                                variant="outline"
                                onClick={() => setBouncing(null)}
                            >
                                {t("إلغاء")}
                            </Button>
                            <Button
                                variant="danger"
                                loading={bounceForm.processing}
                                onClick={() =>
                                    bounceForm.post(
                                        route(
                                            "admin.finance.cheques.bounce",
                                            bouncing!.id,
                                        ),
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => {
                                                setBouncing(null);
                                                bounceForm.reset("reason");
                                            },
                                        },
                                    )
                                }
                            >
                                {t("تأكيد الارتداد")}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
