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
[[ "$pr_number" =~ ^[0-9]+$ && "$head_sha" =~ ^[0-9a-f]{40}$ ]] || fail "طلبٌ أو رأسٌ غيرُ مفهوم: #${pr_number} ${head_sha}"

# ─── ٢) تشغيلاتُ CI الناجحة لرأس هذا الطلب ───
#
# بملفّ الـworkflow لا باسمه (`ci.yml`)، وبحدث `pull_request` وحده، والأحدثُ أوّلًا.
#
# والنجاحُ يُقرأ من التشغيل نفسِه (`status` و`conclusion` أدناه)، لا من مُرشِّح
# `status=success` في السؤال: ذاك المرشِّحُ يسأل فهرسَ بحثٍ عند GitHub قد يتأخّر
# ساعات. وقع فعلًا (#233، 2026-11-10): تشغيلٌ اكتمل ناجحًا في 11:55Z بقي في الفهرس
# «in_progress» حتى 15:20Z — فرُفض نشرُ دمجٍ مختبَرٍ كاملًا. ورفعُه لا يُرخي شيئًا:
# ما لم يكتمل ناجحًا، أو كان لرأسٍ آخر أو حدثٍ آخر أو ملفٍّ آخر، يُطرح هنا.
runs=$(gh api -X GET "repos/${GH_REPO}/actions/workflows/ci.yml/runs" \
    -f event=pull_request -f head_sha="$head_sha" -f per_page=30 \
    --jq ".workflow_runs | sort_by(.created_at) | reverse | .[]
          | select(.status == \"completed\" and .conclusion == \"success\"
                   and .head_sha == \"${head_sha}\" and .event == \"pull_request\"
                   and .path == \".github/workflows/ci.yml\")
          | \"\(.id):\(.run_attempt)\"") \
    || fail "تعذّر سؤال GitHub عن تشغيلات CI للطلب #${pr_number}"

[ -n "$runs" ] || fail "لا تشغيلَ CI ناجحًا (pull_request) لرأس الطلب #${pr_number} ${head_sha}"

# ─── ووظائفُه كلُّها نجحت، والمطلوبةُ منها كلُّها حاضرة ───
#
# نجاحُ التشغيل وحده لا يكفي: يُسأل عن وظائف محاولته الأخيرة واحدةً واحدة.
# كلُّ وظيفةٍ فيها مكتملةٌ ناجحة — لا فاشلةٌ ولا متخطّاةٌ ولا ملغاةٌ ولا جارية —
# وكلُّ وظيفةٍ في القائمة أدناه موجودةٌ فيها بعينها. فتشغيلٌ نقصت منه وظيفةُ
# اختبار (حُذفت، أو تخطّاها شرط) لا يُثبت شيئًا. والقائمةُ تطابق ما تعرّفه
# `ci.yml` — يحرسها `TheGateReadsSuccessFromTheRunNotFromGitHubsIndexTest`.
REQUIRED_JOBS=(
    "الاختبارات (SQLite)"
    "الاختبارات (PostgreSQL 0/3)"
    "الاختبارات (PostgreSQL 1/3)"
    "الاختبارات (PostgreSQL 2/3)"
    "الواجهة (أنواع + اختبارات + بناء)"
    "إثبات الشجرة المختبَرة"
)

jobs_passed() {
    local run_id="$1" jobs bad name missing=()
    jobs=$(gh api -X GET "repos/${GH_REPO}/actions/runs/${run_id}/jobs" -f filter=latest -f per_page=100 \
        --jq '.jobs[] | "\(.status)|\(.conclusion)|\(.name)"') \
        || { echo "تعذّر سؤال GitHub عن وظائفه"; return 1; }
    [ -n "$jobs" ] || { echo "لا وظائفَ له"; return 1; }
    bad=$(grep -v '^completed|success|' <<<"$jobs" || true)
    [ -z "$bad" ] || { echo "وظائفُ لم تكتمل ناجحة: $(cut -d'|' -f1-3 <<<"$bad" | paste -sd ';' -)"; return 1; }
    for name in "${REQUIRED_JOBS[@]}"; do
        grep -qxF "completed|success|${name}" <<<"$jobs" || missing+=("$name")
    done
    [ "${#missing[@]}" -eq 0 ] || { echo "وظائفُ مطلوبة غائبة: ${missing[*]}"; return 1; }
    echo "${#REQUIRED_JOBS[@]} وظائفَ مطلوبة ناجحة"
}

# ─── وثائقُ تخطيطٍ فقط بين المختبَر وmain؟ ───
#
# إن دُفع إلى main أثناء ساعة الاختبار (أو بعدها وقبل الدمج) ملفُّ تخطيط، فشجرةُ
# main ليست الشجرةَ المختبَرة — والكودُ فيهما واحد. فتُقبل حين يكون **كلُّ** فرقٍ
# بينهما في القائمة الضيّقة (`docs-only-change.sh`)، وإلّا يبقى الرفض كما كان.
#
# والشجرةُ المختبَرة لا تُؤخذ من الإثبات على عِلّاتها: تُبنى من جديد من أساسها
# ورأسِ الطلب (`git merge-tree`) ويُشترط أن تطابق ما في الإثبات. فما لا يُعاد
# بناؤه بعينه لا يُقارن، ويُرفض.
docs_only_drift() {
    local proof="$1" base head tree
    base=$(jq -r '.tested_base_sha // empty' "$proof")
    head=$(jq -r '.head_sha // empty' "$proof")
    [ -n "$base" ] && [ -n "$head" ] || { echo "  الإثبات بلا أساسٍ أو رأس"; return 1; }

    git cat-file -e "${head}^{commit}" 2>/dev/null \
        || git fetch --no-tags --quiet origin "+refs/pull/${pr_number}/head:refs/remotes/origin/pr-${pr_number}" \
        || true
    git cat-file -e "${base}^{commit}" 2>/dev/null && git cat-file -e "${head}^{commit}" 2>/dev/null \
        || { echo "  تعذّر الوصول إلى ${base} أو ${head}"; return 1; }

    tree=$(git merge-tree --write-tree "$base" "$head" 2>/dev/null | head -n1) \
        || { echo "  تعذّر إعادة بناء الشجرة المختبَرة"; return 1; }
    [ "$tree" = "$(jq -r '.tested_tree_sha' "$proof")" ] \
        || { echo "  الشجرةُ المعاد بناؤها ${tree} ليست شجرةَ الإثبات"; return 1; }

    echo "main ≠ الشجرة المختبَرة — ما الذي اختلف؟"
    bash "$(dirname "$0")/docs-only-change.sh" "$tree" "$main_tree"
}

# ─── ٣) الإثبات: ملفّ pr-proof.json من التشغيل، وشجرتُه شجرةُ main ───
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
reasons=()

for entry in $runs; do
    run_id="${entry%%:*}"
    attempt="${entry##*:}"
    [[ "$run_id" =~ ^[0-9]+$ && "$attempt" =~ ^[0-9]+$ ]] || { reasons+=("تشغيلٌ غيرُ مفهوم: ${entry}"); continue; }

    if ! jobs_note=$(jobs_passed "$run_id"); then
        reasons+=("التشغيل ${run_id}: ${jobs_note}"); continue
    fi

    # إثباتُ هذه المحاولة بعينها — التي سُئل عن وظائفها — لا محاولةٍ قبلها
    art=$(gh api "repos/${GH_REPO}/actions/runs/${run_id}/artifacts" \
        --jq "[.artifacts[] | select(.expired == false and .name == \"pr-proof-${pr_number}-${run_id}-${attempt}\")] | first // empty") \
        || art=""

    if [ -z "$art" ]; then
        reasons+=("التشغيل ${run_id}: لا إثباتَ مرفوعًا لمحاولته ${attempt} (أقدمُ من وظيفة pr-proof أو انتهت صلاحيته)")
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
    p_attempt=$(jq -r '.workflow_run_attempt' "$proof")
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
    if [ "$p_attempt" != "$attempt" ]; then
        reasons+=("التشغيل ${run_id}: الإثبات لمحاولةٍ أخرى (${p_attempt} لا ${attempt})"); continue
    fi
    note=""
    if [ "$p_tree" != "$main_tree" ]; then
        if ! docs_only_drift "$proof"; then
            reasons+=("التشغيل ${run_id}: الشجرة المختبَرة ${p_tree} ≠ شجرة main ${main_tree} — تحرّك main بعد الاختبار؟"); continue
        fi
        note="main changed during CI, but only approved planning/documentation files changed. Existing test result remains valid."
        echo "$note"
    fi

    echo "✓ شجرة main ${main_tree} هي ما اجتاز CI — PR #${pr_number}، التشغيل ${run_id} (المحاولة ${attempt}؛ ${jobs_note})"
    {
        echo "### ✅ Main Gate"
        echo
        echo "| | |"
        echo "|---|---|"
        echo "| PR | #${pr_number} |"
        echo "| head | \`${head_sha}\` |"
        echo "| CI run | ${run_id} (attempt ${attempt}) |"
        echo "| jobs | ${jobs_note} |"
        echo "| tree | \`${main_tree}\` |"
        [ -z "$note" ] || { echo; echo "ℹ️ ${note} (tested tree \`${p_tree}\`)"; }
    } >> "$SUMMARY"
    exit 0
done

printf '  - %s\n' "${reasons[@]}"
fail "لا إثباتَ مطابقًا للطلب #${pr_number}: ${reasons[*]}"
