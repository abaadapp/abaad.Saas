#!/usr/bin/env bash
#
# Main Gate — يثبت أنّ شجرة main هي الشجرة التي اجتازت CI الطلب، ولا يُعيد الاختبارات.
#
# ═══ لمَ سكربتٌ لا خطوةٌ في YAML ═══
#
# يُفحص محلّيًّا: `bash -n` لصياغته، ويُشغَّل على المستودع الحقيقيّ بقراءاتٍ
# فقط (`gh api` بلا كتابة) — فيُرى رفضُه لدمجٍ قديمٍ بلا إثبات قبل أن يُعتمد عليه.
#
# ═══ الشرطُ الحاسم: الشجرة لا البصمة ═══
#
# الدمجُ بإيداعٍ أو بضغطٍ (squash) أو بإعادة تأسيس يُنتج بصمةً جديدة على main لم
# تُختبر بعينها. لكنّ **محتوى الملفّات** — شجرتها — هو ما اختُبر: CI الطلب يختبر
# `refs/pull/N/merge`، أي main ساعتَها مدموجًا فيه الطلب. فإن كانت شجرةُ main
# الآن هي تلك الشجرة بعينها، فما سيُنشر هو ما نجح حرفًا حرفًا. وإن تحرّك main
# بين الاختبار والدمج (طلبٌ آخر دُمج قبله) اختلفت الشجرة — فالمزيجُ الجديد لم
# يُختبر، ولا يُنشر تلقائيًّا.
#
# المدخلات (متغيّرات بيئة): GH_TOKEN، GH_REPO (owner/repo)، MAIN_SHA.
# الخروج: 0 حين يثبت التطابق، وغيرُه برسالةٍ صريحة — ولا نشرَ صامتًا.

set -euo pipefail

: "${GH_REPO:?GH_REPO غير مضبوط}"
: "${MAIN_SHA:?MAIN_SHA غير مضبوط}"

REFUSED="Main commit has no matching successful PR CI proof; automatic deployment refused."
SUMMARY="${GITHUB_STEP_SUMMARY:-/dev/null}"

fail() {
    echo "::error title=Main Gate::${REFUSED} — $1"
    {
        echo "### ⛔ Main Gate"
        echo
        echo "**${REFUSED}**"
        echo
        echo "السبب: $1"
        echo
        echo "للنشر: حدّث فرعَ الطلب من main (فيُعاد CI على المزيج الجديد) ثمّ ادمجه، أو شغّل «Deploy Main» يدويًّا عن قصد."
    } >> "$SUMMARY"
    exit 1
}

main_tree=$(git rev-parse --verify "${MAIN_SHA}^{tree}") || fail "تعذّر قراءة شجرة ${MAIN_SHA}"
echo "main ${MAIN_SHA} → tree ${main_tree}"

# ─── ١) الطلبُ الذي أدخل هذا الإيداع إلى main ───
#
# مدموجٌ، وأساسُه main، و**إيداعُ دمجه هو هذا الإيداع بعينه**: دفعةٌ مباشرة تحمل
# إيداعًا قديمًا من فرع طلبٍ لا تمرّ بأنّ الطلبَ موجود. والربطُ عند GitHub قد
# يتأخّر ثوانيَ بعد الدفع، فيُعاد السؤال قبل الحكم.
pr_json=""
for attempt in 1 2 3 4 5 6; do
    pr_json=$(gh api "repos/${GH_REPO}/commits/${MAIN_SHA}/pulls" \
        --jq "[.[] | select(.merged_at != null and .base.ref == \"main\" and .merge_commit_sha == \"${MAIN_SHA}\")] | first // empty" \
        2>/dev/null) || pr_json=""
    [ -n "$pr_json" ] && break
    echo "محاولة ${attempt}: لا طلبَ مدموجًا مربوطًا بعد — انتظار"
    sleep 10
done

[ -n "$pr_json" ] || fail "لا طلبَ مدموجًا في main إيداعُ دمجه ${MAIN_SHA} (دفعةٌ مباشرة؟)"

pr_number=$(jq -r '.number' <<<"$pr_json")
head_sha=$(jq -r '.head.sha' <<<"$pr_json")
echo "PR #${pr_number} — head ${head_sha}"

# ─── ٢) تشغيلاتُ CI الناجحة لرأس هذا الطلب ───
#
# بملفّ الـworkflow لا باسمه (`ci.yml`)، وبحدث `pull_request` وحده، والأحدثُ أوّلًا.
runs=$(gh api -X GET "repos/${GH_REPO}/actions/workflows/ci.yml/runs" \
    -f event=pull_request -f head_sha="$head_sha" -f status=success -f per_page=30 \
    --jq '.workflow_runs | sort_by(.created_at) | reverse | .[] | select(.conclusion == "success") | .id') \
    || fail "تعذّر سؤال GitHub عن تشغيلات CI للطلب #${pr_number}"

[ -n "$runs" ] || fail "لا تشغيلَ CI ناجحًا (pull_request) لرأس الطلب #${pr_number} ${head_sha}"

# ─── ٣) الإثبات: ملفّ pr-proof.json من التشغيل، وشجرتُه شجرةُ main ───
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
reasons=()

for run_id in $runs; do
    art=$(gh api "repos/${GH_REPO}/actions/runs/${run_id}/artifacts" \
        --jq "[.artifacts[] | select(.expired == false and (.name | startswith(\"pr-proof-${pr_number}-${run_id}-\")))] | first // empty") \
        || art=""

    if [ -z "$art" ]; then
        reasons+=("التشغيل ${run_id}: لا إثباتَ مرفوعًا (أقدمُ من وظيفة pr-proof أو انتهت صلاحيته)")
        continue
    fi

    dir="${work}/${run_id}"
    mkdir -p "$dir"
    if ! gh api "repos/${GH_REPO}/actions/artifacts/$(jq -r '.id' <<<"$art")/zip" > "${dir}/proof.zip" \
        || ! unzip -q -o "${dir}/proof.zip" -d "$dir" \
        || [ ! -f "${dir}/pr-proof.json" ]; then
        reasons+=("التشغيل ${run_id}: تعذّر تنزيل الإثبات أو قراءته")
        continue
    fi

    proof="${dir}/pr-proof.json"
    p_pr=$(jq -r '.pr_number' "$proof")
    p_head=$(jq -r '.head_sha' "$proof")
    p_run=$(jq -r '.workflow_run_id' "$proof")
    p_tree=$(jq -r '.tested_tree_sha' "$proof")

    if [ "$p_pr" != "$pr_number" ]; then
        reasons+=("التشغيل ${run_id}: الإثبات لطلبٍ آخر (#${p_pr})"); continue
    fi
    if [ "$p_head" != "$head_sha" ]; then
        reasons+=("التشغيل ${run_id}: رأسُ الإثبات ${p_head} لا ${head_sha}"); continue
    fi
    if [ "$p_run" != "$run_id" ]; then
        reasons+=("التشغيل ${run_id}: الإثبات يذكر تشغيلًا آخر (${p_run})"); continue
    fi
    if [ "$p_tree" != "$main_tree" ]; then
        reasons+=("التشغيل ${run_id}: الشجرة المختبَرة ${p_tree} ≠ شجرة main ${main_tree} — تحرّك main بعد الاختبار؟"); continue
    fi

    echo "✓ شجرة main ${main_tree} هي ما اجتاز CI — PR #${pr_number}، التشغيل ${run_id}"
    {
        echo "### ✅ Main Gate"
        echo
        echo "| | |"
        echo "|---|---|"
        echo "| PR | #${pr_number} |"
        echo "| head | \`${head_sha}\` |"
        echo "| CI run | ${run_id} |"
        echo "| tree | \`${main_tree}\` |"
    } >> "$SUMMARY"
    exit 0
done

printf '  - %s\n' "${reasons[@]}"
fail "لا إثباتَ مطابقًا للطلب #${pr_number}: ${reasons[*]}"
