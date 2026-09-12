import { type FormEvent, useState } from "react";
import { router, useForm, usePage } from "@inertiajs/react";
import {
    AlertTriangle,
    Building2,
    CheckCircle2,
    ClipboardList,
    History,
    Link2,
    MessageSquare,
    Phone,
    Plus,
    RotateCcw,
    UserX,
} from "lucide-react";
import PlatformLayout from "@/Layouts/PlatformLayout";
import PageHeader from "@/Components/PageHeader";
import SmartLink from "@/Components/SmartLink";
import Field, { Select, type SelectOption } from "@/Components/Field";
import { Button } from "@/Components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from "@/Components/ui/dialog";
import { Input } from "@/Components/ui/input";
import { useTranslate } from "@/lib/i18n";
import type { PageProps } from "@/types";

interface Lead {
    id: number;
    name: string;
    businessName: string | null;
    phone: string;
    phoneRaw: string | null;
    phoneNormalized: string;
    wilayat: string | null;
    branchesCount: number | null;
    currentSystem: string | null;
    stage: string;
    stageLabel: string;
    stageTone: string;
    status: string;
    statusLabel: string;
    source: string;
    sourceLabel: string;
    assignee: string | null;
    assigneeId: string | null;
    assignedBy: string | null;
    assignedAt: string | null;
    plan: string | null;
    planId: string | null;
    expectedValue: number | null;
    firstContactAt: string | null;
    lastContactAt: string | null;
    nextFollowUpAt: string | null;
    nextFollowUpRaw: string | null;
    followUpOverdue: boolean;
    tags: string[];
    lostReason: string | null;
    lostReasonLabel: string | null;
    lostNote: string | null;
    convertedAt: string | null;
    business: { id: number; name: string; url: string } | null;
}

interface Note {
    id: number;
    author: string;
    body: string;
    at: string | null;
}

interface Task {
    id: number;
    title: string;
    status: string;
    statusLabel: string;
    priority: string;
    priorityLabel: string;
    assignee: string | null;
    dueAt: string | null;
    overdue: boolean;
    notes: string | null;
}

interface Event {
    id: number;
    from: string | null;
    to: string;
    by: string;
    reason: string | null;
    at: string | null;
}

interface Props {
    lead: Lead;
    existingMerchant: {
        name: string;
        businessId: number | null;
        businessName: string | null;
        url: string | null;
    } | null;
    notes: Note[];
    tasks: Task[];
    timeline: Event[];
    stages: SelectOption[];
    lostReasons: SelectOption[];
    sources: SelectOption[];
    staff: SelectOption[];
    plans: SelectOption[];
    taskPriorities: SelectOption[];
    businesses: SelectOption[];
}

const TONE: Record<string, string> = {
    info: "bg-[#eff6ff] text-[#1d4ed8]",
    primary: "bg-[#f5f3ff] text-[#6d28d9]",
    warning: "bg-[#fffbeb] text-[#b45309]",
    success: "bg-[#f0fdf4] text-[#15803d]",
    danger: "bg-[#fef2f2] text-[#b91c1c]",
    gray: "bg-[#f4f4f5] text-[#52525b]",
};

/**
 * حقلُ نصٍّ متعدّدُ الأسطر.
 *
 * ولا مكوّنَ مشترك له في النظام — ومركزُ المحادثات يكتب `<textarea>` خامًا
 * بصنفه. فيُكتب هنا مثلُه لا يُضاف مكوّنٌ مشترَكٌ جديد لشاشتين: مكوّنٌ يُنشأ
 * لأجل شاشةٍ واحدة يُغيّره أوّلُ من يحتاج غيرَ ما فيه.
 */
function Textarea({
    rows,
    value,
    onChange,
    placeholder,
}: {
    rows: number;
    value: string;
    onChange: (e: { target: { value: string } }) => void;
    placeholder?: string;
}) {
    return (
        <textarea
            rows={rows}
            value={value}
            onChange={onChange}
            placeholder={placeholder}
            className="w-full resize-none rounded-[10px] border border-[#e8e8e8] bg-white p-2.5 text-[13px] outline-none placeholder:text-[#9ca3af] focus:border-[#111]"
        />
    );
}

/** سطرٌ في لوحة البيانات — والمجهولُ يُقال «غير معروف» لا يُخترع */
function Row({
    label,
    value,
}: {
    label: string;
    value: string | null | undefined;
}) {
    const t = useTranslate();

    return (
        <div className="flex items-start justify-between gap-3 py-1.5">
            <dt className="shrink-0 text-[12px] text-[#6b7280]">{t(label)}</dt>
            <dd
                className={`min-w-0 truncate text-end text-[13px] ${value ? "text-[#111]" : "text-[#9ca3af]"}`}
            >
                {value || t("غير معروف")}
            </dd>
        </div>
    );
}

export default function CrmLeadShow() {
    const {
        lead,
        existingMerchant,
        notes,
        tasks,
        timeline,
        stages,
        lostReasons,
        sources,
        staff,
        plans,
        taskPriorities,
        businesses,
    } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const [losing, setLosing] = useState(false);
    const [converting, setConverting] = useState(false);
    const [addingTask, setAddingTask] = useState(false);

    const details = useForm({
        name: lead.name ?? "",
        business_name: lead.businessName ?? "",
        wilayat: lead.wilayat ?? "",
        branches_count:
            lead.branchesCount !== null ? String(lead.branchesCount) : "",
        current_system: lead.currentSystem ?? "",
        source: lead.source,
        interested_plan_id: lead.planId ?? "",
        expected_value:
            lead.expectedValue !== null ? String(lead.expectedValue) : "",
        next_follow_up_at: lead.nextFollowUpRaw ?? "",
    });

    const noteForm = useForm({ body: "" });
    const lostForm = useForm({ lost_reason: "price", lost_note: "" });
    const convertForm = useForm({ business_id: "" });
    const taskForm = useForm({
        title: "",
        due_at: "",
        assigned_to: "",
        priority: "normal",
        notes: "",
    });

    const post = (name: string, data: Record<string, string | number | null>) =>
        router.post(route(name, lead.id), data, { preserveScroll: true });

    const saveDetails = (e: FormEvent) => {
        e.preventDefault();
        details.put(route("super-admin.crm.leads.update", lead.id), {
            preserveScroll: true,
        });
    };

    const closed = lead.stage === "won" || lead.stage === "lost";

    return (
        <PlatformLayout title={lead.name}>
            <PageHeader
                title={lead.name}
                subtitle={lead.businessName ?? lead.phone}
                actions={
                    <>
                        <SmartLink
                            routeName="super-admin.crm.leads.index"
                            href={route("super-admin.crm.leads.index")}
                        >
                            <Button variant="outline">
                                {t("كل العملاء المحتملين")}
                            </Button>
                        </SmartLink>
                        {closed ? (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    post("super-admin.crm.leads.reopen", {})
                                }
                            >
                                <RotateCcw className="size-4" />
                                {t("إعادة الفتح")}
                            </Button>
                        ) : (
                            <>
                                <Button
                                    variant="outline"
                                    onClick={() => setLosing(true)}
                                >
                                    <UserX className="size-4" />
                                    {t("تسجيل خسارة")}
                                </Button>
                                <Button onClick={() => setConverting(true)}>
                                    <Link2 className="size-4" />
                                    {t("تحويل إلى عميل")}
                                </Button>
                            </>
                        )}
                    </>
                }
            />

            {/*
                تاجرٌ عندنا أصلًا — يُقال قبل كلّ شيء.
                من يكلّمنا وهو مشتركٌ ليس عميلًا محتملًا بل صاحبُ دعم، وبيعُه
                ما اشتراه أسوأ من ألّا نردّ عليه.
            */}
            {existingMerchant && (
                <div className="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-[#bfdbfe] bg-[#eff6ff] p-4 text-[13px] text-[#1e40af]">
                    <Building2 className="size-4 shrink-0" />
                    <span>
                        {t("هذا الرقم لصاحب متجرٍ قائم: :name", {
                            name:
                                existingMerchant.businessName ??
                                existingMerchant.name,
                        })}
                    </span>
                    {existingMerchant.url && (
                        <SmartLink
                            routeName="super-admin.businesses.show"
                            href={existingMerchant.url}
                            className="font-medium underline"
                        >
                            {t("افتح ملفّ المتجر")}
                        </SmartLink>
                    )}
                </div>
            )}

            {lead.stage === "lost" && (
                <div className="mb-4 rounded-xl border border-[#fecaca] bg-[#fef2f2] p-4 text-[13px] text-[#991b1b]">
                    <span className="font-medium">{t("مفقود")}</span>
                    {lead.lostReasonLabel && (
                        <span> — {lead.lostReasonLabel}</span>
                    )}
                    {lead.lostNote && (
                        <p className="mt-1 text-[12px]">{lead.lostNote}</p>
                    )}
                </div>
            )}

            {lead.business && (
                <div className="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-[#bbf7d0] bg-[#f0fdf4] p-4 text-[13px] text-[#166534]">
                    <CheckCircle2 className="size-4 shrink-0" />
                    <span>
                        {t("تم الاشتراك — مرتبط بالمتجر :name", {
                            name: lead.business.name,
                        })}
                    </span>
                    <SmartLink
                        routeName="super-admin.businesses.show"
                        href={lead.business.url}
                        className="font-medium underline"
                    >
                        {t("افتح ملفّ المتجر")}
                    </SmartLink>
                </div>
            )}

            <div className="grid gap-4 lg:grid-cols-3">
                {/* ═══ العمود الأيمن في العربية: المرحلة والإسناد والبيانات ═══ */}
                <div className="space-y-4">
                    <section className="rounded-xl border border-[#e8e8e8] bg-white p-5">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="font-bold text-[#111]">
                                {t("مرحلة البيع")}
                            </h2>
                            <span
                                className={`rounded-full px-2 py-0.5 text-[12px] font-medium ${TONE[lead.stageTone] ?? TONE.gray}`}
                            >
                                {lead.stageLabel}
                            </span>
                        </div>

                        {/*
                            و«تم الاشتراك» ليست في القائمة: الاشتراكُ يُربط
                            بمتجرٍ حقيقيّ من زرّ التحويل. وخيارٌ هنا يكتبها
                            بلا متجرٍ يعني مشتركين لا متاجر لهم في التقرير.
                        */}
                        <Select
                            value={closed ? "" : lead.stage}
                            disabled={closed}
                            options={stages}
                            placeholder={
                                closed
                                    ? t("محسوم — أعد فتحه لتغيير المرحلة")
                                    : undefined
                            }
                            onChange={(e) =>
                                e.target.value &&
                                post("super-admin.crm.leads.stage", {
                                    stage: e.target.value,
                                })
                            }
                            aria-label={t("مرحلة البيع")}
                        />

                        <div className="mt-4">
                            <Field label="المسؤول">
                                <Select
                                    value={lead.assigneeId ?? ""}
                                    options={staff}
                                    placeholder={t("غير معيّن")}
                                    onChange={(e) =>
                                        post("super-admin.crm.leads.assign", {
                                            assigned_to: e.target.value || null,
                                        })
                                    }
                                    aria-label={t("المسؤول")}
                                />
                            </Field>
                            {lead.assignedBy && (
                                <p className="mt-1 text-[12px] text-[#6b7280]">
                                    {t("أسنده :by — :at", {
                                        by: lead.assignedBy,
                                        at: lead.assignedAt ?? "",
                                    })}
                                </p>
                            )}
                        </div>
                    </section>

                    <section className="rounded-xl border border-[#e8e8e8] bg-white p-5">
                        <h2 className="mb-2 font-bold text-[#111]">
                            {t("معلومات العميل المحتمل")}
                        </h2>
                        <dl className="divide-y divide-[#f3f4f6]">
                            <Row
                                label="رقم الجوال"
                                value={lead.phoneRaw ?? lead.phoneNormalized}
                            />
                            <Row label="اسم النشاط" value={lead.businessName} />
                            <Row label="الولاية" value={lead.wilayat} />
                            <Row
                                label="عدد الفروع"
                                value={
                                    lead.branchesCount !== null
                                        ? String(lead.branchesCount)
                                        : null
                                }
                            />
                            <Row
                                label="النظام الحالي"
                                value={lead.currentSystem}
                            />
                            <Row label="المصدر" value={lead.sourceLabel} />
                            <Row label="الباقة المهتم بها" value={lead.plan} />
                            <Row
                                label="القيمة المتوقعة"
                                value={
                                    lead.expectedValue !== null
                                        ? String(lead.expectedValue)
                                        : null
                                }
                            />
                            <Row
                                label="أول تواصل"
                                value={lead.firstContactAt}
                            />
                            <Row label="آخر تواصل" value={lead.lastContactAt} />
                            <Row
                                label="المتابعة القادمة"
                                value={lead.nextFollowUpAt}
                            />
                        </dl>
                    </section>
                </div>

                {/* ═══ الوسط: تحرير البيانات، الملاحظات ═══ */}
                <div className="space-y-4 lg:col-span-2">
                    <section className="rounded-xl border border-[#e8e8e8] bg-white p-5">
                        <h2 className="mb-3 font-bold text-[#111]">
                            {t("تعديل البيانات")}
                        </h2>

                        <form
                            onSubmit={saveDetails}
                            className="grid gap-3 sm:grid-cols-2"
                        >
                            <Field label="الاسم" error={details.errors.name}>
                                <Input
                                    value={details.data.name}
                                    onChange={(e) =>
                                        details.setData("name", e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                label="اسم النشاط"
                                error={details.errors.business_name}
                            >
                                <Input
                                    value={details.data.business_name}
                                    onChange={(e) =>
                                        details.setData(
                                            "business_name",
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="الولاية"
                                error={details.errors.wilayat}
                            >
                                <Input
                                    value={details.data.wilayat}
                                    onChange={(e) =>
                                        details.setData(
                                            "wilayat",
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="عدد الفروع"
                                error={details.errors.branches_count}
                            >
                                <Input
                                    type="number"
                                    min={0}
                                    value={details.data.branches_count}
                                    onChange={(e) =>
                                        details.setData(
                                            "branches_count",
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="النظام الحالي"
                                error={details.errors.current_system}
                            >
                                <Input
                                    value={details.data.current_system}
                                    onChange={(e) =>
                                        details.setData(
                                            "current_system",
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field label="المصدر" error={details.errors.source}>
                                <Select
                                    value={details.data.source}
                                    options={sources}
                                    onChange={(e) =>
                                        details.setData(
                                            "source",
                                            e.target.value,
                                        )
                                    }
                                    aria-label={t("المصدر")}
                                />
                            </Field>
                            <Field
                                label="الباقة المهتم بها"
                                error={details.errors.interested_plan_id}
                            >
                                <Select
                                    value={details.data.interested_plan_id}
                                    options={plans}
                                    placeholder={t("غير محدد")}
                                    onChange={(e) =>
                                        details.setData(
                                            "interested_plan_id",
                                            e.target.value,
                                        )
                                    }
                                    aria-label={t("الباقة المهتم بها")}
                                />
                            </Field>
                            <Field
                                label="القيمة المتوقعة"
                                error={details.errors.expected_value}
                            >
                                <Input
                                    type="number"
                                    step="0.001"
                                    min={0}
                                    value={details.data.expected_value}
                                    onChange={(e) =>
                                        details.setData(
                                            "expected_value",
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="المتابعة القادمة"
                                error={details.errors.next_follow_up_at}
                                className="sm:col-span-2"
                            >
                                <Input
                                    type="datetime-local"
                                    value={details.data.next_follow_up_at}
                                    onChange={(e) =>
                                        details.setData(
                                            "next_follow_up_at",
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>

                            <div className="sm:col-span-2 flex justify-end">
                                <Button
                                    type="submit"
                                    disabled={details.processing}
                                >
                                    {t("حفظ")}
                                </Button>
                            </div>
                        </form>
                    </section>

                    <section className="rounded-xl border border-[#e8e8e8] bg-white p-5">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="font-bold text-[#111]">
                                <ClipboardList className="me-1.5 inline size-4 text-[#9ca3af]" />
                                {t("المهام والمتابعات")}
                            </h2>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setAddingTask(true)}
                            >
                                <Plus className="size-4" />
                                {t("إضافة مهمة")}
                            </Button>
                        </div>

                        {tasks.length === 0 ? (
                            <p className="text-[13px] text-[#9ca3af]">
                                {t("لا مهام على هذا العميل المحتمل.")}
                            </p>
                        ) : (
                            <ul className="divide-y divide-[#f3f4f6]">
                                {tasks.map((task) => (
                                    <li
                                        key={task.id}
                                        className="flex items-start gap-3 py-2.5"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span
                                                    className={`text-[13px] font-medium ${task.status === "done" ? "text-[#9ca3af] line-through" : "text-[#111]"}`}
                                                >
                                                    {task.title}
                                                </span>
                                                {task.overdue && (
                                                    <span className="inline-flex items-center gap-1 rounded-full bg-[#fef2f2] px-2 py-0.5 text-[11px] text-[#b91c1c]">
                                                        <AlertTriangle className="size-3" />
                                                        {t("متأخرة")}
                                                    </span>
                                                )}
                                            </div>
                                            <p className="mt-0.5 text-[12px] text-[#6b7280]">
                                                {task.dueAt} ·{" "}
                                                {task.priorityLabel}
                                                {task.assignee
                                                    ? ` · ${task.assignee}`
                                                    : ""}
                                            </p>
                                            {task.notes && (
                                                <p className="mt-1 text-[12px] text-[#6b7280]">
                                                    {task.notes}
                                                </p>
                                            )}
                                        </div>

                                        {task.status === "open" && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        route(
                                                            "super-admin.crm.tasks.update",
                                                            task.id,
                                                        ),
                                                        { status: "done" },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {t("إنجاز")}
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section className="rounded-xl border border-[#e8e8e8] bg-white p-5">
                        <h2 className="mb-1 font-bold text-[#111]">
                            <MessageSquare className="me-1.5 inline size-4 text-[#9ca3af]" />
                            {t("ملاحظات")}
                        </h2>
                        {/* ولا تخرج إلى أحد — يُقال في الشاشة لا في التعليق وحده */}
                        <p className="mb-3 text-[12px] text-[#6b7280]">
                            {t(
                                "ملاحظات داخلية لفريق أبعاد — لا تصل العميل بحال.",
                            )}
                        </p>

                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                noteForm.post(
                                    route(
                                        "super-admin.crm.leads.note",
                                        lead.id,
                                    ),
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => noteForm.reset(),
                                    },
                                );
                            }}
                            className="mb-4 space-y-2"
                        >
                            <Textarea
                                rows={3}
                                value={noteForm.data.body}
                                onChange={(e) =>
                                    noteForm.setData("body", e.target.value)
                                }
                                placeholder={t("اكتب ملاحظة داخلية...")}
                            />
                            {noteForm.errors.body && (
                                <p className="text-[12px] text-[#b91c1c]">
                                    {noteForm.errors.body}
                                </p>
                            )}
                            <div className="flex justify-end">
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={
                                        noteForm.processing ||
                                        !noteForm.data.body.trim()
                                    }
                                >
                                    {t("إضافة ملاحظة")}
                                </Button>
                            </div>
                        </form>

                        {notes.length === 0 ? (
                            <p className="text-[13px] text-[#9ca3af]">
                                {t("لا ملاحظات بعد.")}
                            </p>
                        ) : (
                            <ul className="space-y-3">
                                {notes.map((n) => (
                                    <li
                                        key={n.id}
                                        className="rounded-lg bg-[#fafafa] p-3"
                                    >
                                        <p className="whitespace-pre-wrap text-[13px] text-[#111]">
                                            {n.body}
                                        </p>
                                        <p className="mt-1.5 text-[11px] text-[#9ca3af]">
                                            {n.author} · {n.at}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section className="rounded-xl border border-[#e8e8e8] bg-white p-5">
                        <h2 className="mb-3 font-bold text-[#111]">
                            <History className="me-1.5 inline size-4 text-[#9ca3af]" />
                            {t("تاريخ المراحل")}
                        </h2>

                        {timeline.length === 0 ? (
                            <p className="text-[13px] text-[#9ca3af]">
                                {t("لا انتقالات مسجّلة.")}
                            </p>
                        ) : (
                            <ul className="space-y-2.5">
                                {timeline.map((e) => (
                                    <li
                                        key={e.id}
                                        className="flex items-start gap-2 text-[13px]"
                                    >
                                        <span className="mt-1.5 size-1.5 shrink-0 rounded-full bg-[#d1d5db]" />
                                        <div className="min-w-0">
                                            <span className="text-[#111]">
                                                {e.from
                                                    ? `${e.from} ← ${e.to}`
                                                    : e.to}
                                            </span>
                                            {e.reason && (
                                                <span className="text-[#6b7280]">
                                                    {" "}
                                                    — {e.reason}
                                                </span>
                                            )}
                                            <span className="block text-[11px] text-[#9ca3af]">
                                                {e.by} · {e.at}
                                            </span>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>

            {/* ═══ تسجيل الخسارة ═══ */}
            <Dialog open={losing} onOpenChange={setLosing}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t("تسجيل خسارة")}</DialogTitle>
                    </DialogHeader>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            lostForm.post(
                                route("super-admin.crm.leads.lose", lead.id),
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setLosing(false),
                                },
                            );
                        }}
                        className="space-y-3 px-5 pb-5"
                    >
                        {/* والسببُ من قائمةٍ مغلقة: بلا ذلك لا يُجمع تقريرُ الأسباب */}
                        <Field
                            label="سبب الخسارة"
                            error={lostForm.errors.lost_reason}
                            required
                        >
                            <Select
                                value={lostForm.data.lost_reason}
                                options={lostReasons}
                                onChange={(e) =>
                                    lostForm.setData(
                                        "lost_reason",
                                        e.target.value,
                                    )
                                }
                                aria-label={t("سبب الخسارة")}
                            />
                        </Field>
                        <Field label="تفصيل" error={lostForm.errors.lost_note}>
                            <Textarea
                                rows={3}
                                value={lostForm.data.lost_note}
                                onChange={(e) =>
                                    lostForm.setData(
                                        "lost_note",
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setLosing(false)}
                            >
                                {t("إلغاء")}
                            </Button>
                            <Button
                                type="submit"
                                disabled={lostForm.processing}
                            >
                                {t("تسجيل")}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* ═══ التحويل ═══ */}
            <Dialog open={converting} onOpenChange={setConverting}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t("تحويل إلى عميل")}</DialogTitle>
                    </DialogHeader>

                    {/*
                        ولا يُنشأ متجرٌ من هنا.
                        إنشاءُ المتجر بابٌ له شاشتُه وباقتُه واشتراكُه وحسابُ
                        صاحبه؛ وبابٌ ثانٍ يفترق عنه فيُضاف متجرٌ بلا اشتراك.
                    */}
                    <div className="px-5 pb-5">
                        <p className="mb-3 text-[13px] text-[#6b7280]">
                            {t(
                                "اربطه بمتجرٍ قائم. ولإنشاء متجرٍ جديد استعمل شاشة «الشركات» ثم عُد واربطه هنا.",
                            )}
                        </p>

                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                convertForm.post(
                                    route(
                                        "super-admin.crm.leads.convert",
                                        lead.id,
                                    ),
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setConverting(false),
                                    },
                                );
                            }}
                            className="space-y-3"
                        >
                            <Field
                                label="المتجر"
                                error={convertForm.errors.business_id}
                                required
                            >
                                <Select
                                    value={convertForm.data.business_id}
                                    options={businesses}
                                    placeholder={t("اختر متجرًا")}
                                    onChange={(e) =>
                                        convertForm.setData(
                                            "business_id",
                                            e.target.value,
                                        )
                                    }
                                    aria-label={t("المتجر")}
                                />
                            </Field>
                            <div className="flex flex-wrap justify-between gap-2">
                                <SmartLink
                                    routeName="super-admin.businesses.create"
                                    href={route(
                                        "super-admin.businesses.create",
                                    )}
                                >
                                    <Button type="button" variant="outline">
                                        {t("إنشاء متجر جديد")}
                                    </Button>
                                </SmartLink>
                                <div className="flex gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setConverting(false)}
                                    >
                                        {t("إلغاء")}
                                    </Button>
                                    <Button
                                        type="submit"
                                        disabled={
                                            convertForm.processing ||
                                            !convertForm.data.business_id
                                        }
                                    >
                                        {t("ربط")}
                                    </Button>
                                </div>
                            </div>
                        </form>
                    </div>
                </DialogContent>
            </Dialog>

            {/* ═══ مهمّة جديدة ═══ */}
            <Dialog open={addingTask} onOpenChange={setAddingTask}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t("إضافة مهمة")}</DialogTitle>
                    </DialogHeader>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            taskForm.post(
                                route(
                                    "super-admin.crm.leads.tasks.store",
                                    lead.id,
                                ),
                                {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        taskForm.reset();
                                        setAddingTask(false);
                                    },
                                },
                            );
                        }}
                        className="space-y-3 px-5 pb-5"
                    >
                        <Field
                            label="عنوان المهمة"
                            error={taskForm.errors.title}
                            required
                        >
                            <Input
                                value={taskForm.data.title}
                                onChange={(e) =>
                                    taskForm.setData("title", e.target.value)
                                }
                            />
                        </Field>
                        {/* والموعدُ مطلوب: مهمّةٌ بلا موعدٍ لا تتأخّر أبدًا فلا تُنبّه أحدًا */}
                        <Field
                            label="موعد الاستحقاق"
                            error={taskForm.errors.due_at}
                            required
                        >
                            <Input
                                type="datetime-local"
                                value={taskForm.data.due_at}
                                onChange={(e) =>
                                    taskForm.setData("due_at", e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="المسؤول"
                            error={taskForm.errors.assigned_to}
                        >
                            <Select
                                value={taskForm.data.assigned_to}
                                options={staff}
                                placeholder={t("غير معيّن")}
                                onChange={(e) =>
                                    taskForm.setData(
                                        "assigned_to",
                                        e.target.value,
                                    )
                                }
                                aria-label={t("المسؤول")}
                            />
                        </Field>
                        <Field
                            label="الأولوية"
                            error={taskForm.errors.priority}
                            required
                        >
                            <Select
                                value={taskForm.data.priority}
                                options={taskPriorities}
                                onChange={(e) =>
                                    taskForm.setData("priority", e.target.value)
                                }
                                aria-label={t("الأولوية")}
                            />
                        </Field>
                        <Field label="ملاحظات" error={taskForm.errors.notes}>
                            <Textarea
                                rows={2}
                                value={taskForm.data.notes}
                                onChange={(e) =>
                                    taskForm.setData("notes", e.target.value)
                                }
                            />
                        </Field>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setAddingTask(false)}
                            >
                                {t("إلغاء")}
                            </Button>
                            <Button
                                type="submit"
                                disabled={taskForm.processing}
                            >
                                {t("إضافة")}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* رقمُ الجوال جاهزًا للاتصال — لا زرَّ واتساب قبل أن يُوصَل خطُّه */}
            <a
                href={`tel:${lead.phoneNormalized}`}
                className="mt-4 inline-flex items-center gap-1.5 text-[13px] text-[#6b7280] hover:text-[#111]"
            >
                <Phone className="size-4" />
                {lead.phoneRaw ?? lead.phoneNormalized}
            </a>
        </PlatformLayout>
    );
}
