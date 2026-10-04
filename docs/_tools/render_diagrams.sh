#!/bin/zsh
# Рендер исходников docs/06_architecture/diagrams/*.mmd в SVG в фирменном оформлении ТЕТА
# (шрифт Onest, фиолетовый #4D427A и графитовый #4A4A4A). Нужен Node.js: mermaid-cli ставится через npx.
# Использование: docs/_tools/render_diagrams.sh [регулярное выражение имён, по умолчанию все]
set -u
ROOT=${0:A:h:h}
DIAG=$ROOT/06_architecture/diagrams
CONFIG=$ROOT/_tools/mermaid_brand.json
INCLUDE=${1:-.}
MMDC=(npx -y @mermaid-js/mermaid-cli@11)
typeset -i ok=0 fail=0
for f in "$DIAG"/*.mmd; do
  name=${f:t:r}
  [[ "$name" =~ $INCLUDE ]] || continue
  if "${MMDC[@]}" -i "$f" -o "$DIAG/$name.svg" -b white -c "$CONFIG" >/dev/null 2>&1; then
    ok+=1
  else
    fail+=1
    echo "Ошибка рендера: $name"
  fi
done
echo "Готово: $ok, ошибок: $fail"
