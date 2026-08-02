#!/usr/bin/env bash
# دفع تلقائي إلى GitHub بعد كل رد — يُستدعى من hook نوع Stop.
# لا يفشل أبدًا بشكل يوقف الجلسة: أي خطأ يُبلَّغ عنه كرسالة فقط.
set -uo pipefail

cd "$(git rev-parse --show-toplevel 2>/dev/null)" || exit 0

msg() { printf '{"systemMessage":%s,"suppressOutput":true}\n' "$(printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g; s/^/"/; s/$/"/')"; }

[ -z "$(git status --porcelain)" ] && exit 0

branch=$(git rev-parse --abbrev-ref HEAD)
[ "$branch" = "HEAD" ] && { msg "دفع تلقائي: متوقف — HEAD منفصل"; exit 0; }

# بوابة الجودة: لا يُدفع كود يكسر الاختبارات.
# نتخطاها فقط إذا لم يتغيّر أي ملف يؤثر على السلوك الخلفي.
if git status --porcelain | grep -qE '\.(php|json)$|^.. (app|routes|config|database|tests|lang)/'; then
  if ! test_out=$(php artisan test 2>&1); then
    failed=$(printf '%s' "$test_out" | grep -oE '"failed":[0-9]+' | head -1 | cut -d: -f2)
    culprit=$(printf '%s' "$test_out" | grep -oE '"test":"[^"]+"' | head -1 | cut -d'"' -f4 | sed 's/.*\\\\//')
    msg "دفع تلقائي: متوقف — فشل ${failed:-?} اختبار (${culprit:-غير معروف}). شغّل: php artisan test"
    exit 0
  fi
fi

files=$(git status --porcelain | wc -l | tr -d ' ')
first=$(git status --porcelain | head -1 | awk '{print $NF}')
subject="تحديث تلقائي: $first"
[ "$files" -gt 1 ] && subject="$subject (+$((files - 1)) ملف)"

git add -A || { msg "دفع تلقائي: فشل git add"; exit 0; }

if ! out=$(git commit -m "$subject" -m "Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>" 2>&1); then
  msg "دفع تلقائي: فشل الكوميت — $(printf '%s' "$out" | tail -1)"
  exit 0
fi

if ! out=$(git push origin "$branch" 2>&1); then
  msg "دفع تلقائي: الكوميت تم لكن الدفع فشل — $(printf '%s' "$out" | tail -1)"
  exit 0
fi

msg "دُفع إلى origin/$branch — $subject"
