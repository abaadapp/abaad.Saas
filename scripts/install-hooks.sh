#!/bin/sh
# يُثبّت خطافات git من scripts/git-hooks إلى .git/hooks
# الاستخدام: sh scripts/install-hooks.sh
DIR="$(cd "$(dirname "$0")/.." && pwd)"
cp "$DIR/scripts/git-hooks/pre-commit" "$DIR/.git/hooks/pre-commit"
chmod +x "$DIR/.git/hooks/pre-commit"
echo "✔ تم تثبيت خطاف pre-commit (فحص الترجمة الإنجليزية قبل كل إيداع)."
