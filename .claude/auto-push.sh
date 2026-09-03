#!/usr/bin/env bash
# دفعٌ تلقائيّ إلى GitHub بعد كل ردّ — يُستدعى من hook نوع Stop.
#
# ولا يلتزم شيئًا: يدفع ما التُزم فقط.
#
# النسخةُ الأولى كانت تفعل: `git add -A` ثمّ كوميت برسالةٍ آليّة
# («تحديث تلقائي: app/Support/Pdf.php (+43 ملف)»). وهي تخالف اصطلاح
# المستودع من وجهين: الرسالةُ فيه جملةٌ عربية تصف ما كان معطوبًا وما صار،
# والوسمُ `v<النسخة>` يُرفَق بكلّ التزام. وسكربتٌ لا يقرأ الكود لا يكتب
# جملةً كتلك ولا يعرف أيَّ رقمٍ يستحقّ.
#
# فصار عملُه خطوةً واحدة: ما التُزم عمدًا يُدفع بلا أن يُسأل عنه أحد.
# والوسومُ معه بـ`--follow-tags`.
#
# ولا يفشل بشكلٍ يوقف الجلسة: أيّ خطأ يُبلَّغ عنه رسالةً فقط.
set -uo pipefail

msg() { printf '{"systemMessage":%s,"suppressOutput":true}\n' "$(printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g; s/^/"/; s/$/"/')"; }

root=$(git rev-parse --show-toplevel 2>/dev/null) || exit 0
cd "$root" || exit 0

pushed=()
failed=()

# كلُّ شجرات العمل لا الشجرةَ الرئيسية وحدها: العملُ يجري في شجرةٍ منفصلة،
# وhook يقرأ مجلّد الجلسة وحده لا يرى الفرع الذي كُتب فيه شيء.
while IFS= read -r dir; do
    [ -d "$dir" ] || continue

    branch=$(git -C "$dir" rev-parse --abbrev-ref HEAD 2>/dev/null) || continue

    # رأسٌ منفصل، أو الفرع الرئيس: لا يُدفع تلقائيًّا.
    # وmain خاصّةً — أخطرُ ما يُدفع بلا أن ينظر إليه أحد.
    case "$branch" in
        HEAD|main|master) continue ;;
    esac

    # ومنبعٌ مضبوط شرطٌ لا زينة: أوّلُ نشرٍ لفرعٍ قرارٌ يُتَّخذ باليد، وما
    # بعده يبقى متزامنًا وحده. وبلا هذا الشرط يَنشر السكربتُ فروعَ جلساتٍ
    # أخرى تعمل في شجرات العمل نفسها ولم تطلب النشر بعد.
    upstream=$(git -C "$dir" rev-parse --abbrev-ref --symbolic-full-name '@{upstream}' 2>/dev/null) || continue

    # ومنبعُه هو نفسُه لا فرعٌ آخر.
    #
    # شجرةُ عملٍ فُتحت بـ`git worktree add` ترث منبعَ ما فُتحت منه: فرعٌ
    # اسمه `feat/store-picks` منبعُه `origin/main`. فلو اكتفينا بوجود
    # المنبع لَدفعنا التزاماتِ جلسةٍ أخرى إلى فرعٍ بعيدٍ جديد باسمها — نشرًا
    # لم يطلبه أحد. والمنبعُ المسمّى باسم الفرع أثرُ نشرٍ سابقٍ متعمَّد.
    [ "$upstream" = "origin/$branch" ] || continue

    ahead=$(git -C "$dir" rev-list --count "$upstream..HEAD" 2>/dev/null) || continue
    [ "${ahead:-0}" -gt 0 ] || continue

    # ‏--follow-tags: الوسومُ المشروحة التي تصل إليها الالتزامات المدفوعة.
    # وهي اصطلاح المستودع — وسمٌ لكلّ تعديل — فدفعُ الالتزام بلا وسمه يترك
    # نصفَ الاصطلاح على القرص.
    if out=$(git -C "$dir" push --follow-tags origin "$branch" 2>&1); then
        pushed+=("$branch (+$ahead)")
    else
        failed+=("$branch: $(printf '%s' "$out" | tail -1)")
    fi
done < <(git worktree list --porcelain | awk '/^worktree /{print substr($0, 10)}')

if [ ${#failed[@]} -gt 0 ]; then
    msg "دفع تلقائي: فشل — ${failed[*]}"
    exit 0
fi

[ ${#pushed[@]} -eq 0 ] && exit 0

msg "دُفع تلقائيًّا: ${pushed[*]}"
