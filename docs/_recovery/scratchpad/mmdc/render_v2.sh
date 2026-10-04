#!/bin/zsh
# Render docs/06_architecture/diagrams/*.mmd to SVG with brand theme (Onest, TETA colors).
# Usage: render_v2.sh [include-regex] [exclude-regex]
set -u
DIAG=/Users/dmitry/Projects/teta_new/docs/06_architecture/diagrams
HERE=/private/tmp/claude-501/-Users-dmitry-Projects-teta-new/e111f1b1-4f83-4542-aed1-80798dae1d9a/scratchpad/mmdc
INCLUDE=${1:-.}
EXCLUDE=${2:-^$}
LOGS=$HERE/logs_v2
mkdir -p "$LOGS"
RESULTS=$HERE/results_v2_$(date +%H%M%S).txt
: > "$RESULTS"

MMDC=$(ls -t ~/.npm/_npx/*/node_modules/.bin/mmdc 2>/dev/null | head -1)
if [[ -z "$MMDC" ]]; then
  MMDC="npx -y @mermaid-js/mermaid-cli@11"
fi
echo "mmdc: $MMDC"

render_one() {
  local f=$1
  local name=${f:t:r}
  if perl -e 'alarm shift; exec @ARGV' 240 ${=MMDC} -i "$f" -o "$DIAG/$name.svg" -b white -c "$HERE/mermaid_brand.json" >"$LOGS/$name.log" 2>&1 && [[ -s "$DIAG/$name.svg" ]]; then
    echo "OK $name" >>"$RESULTS"
  else
    echo "FAIL $name" >>"$RESULTS"
  fi
}

typeset -i running=0
for f in "$DIAG"/*.mmd; do
  name=${f:t:r}
  if [[ ! "$name" =~ $INCLUDE ]] || [[ "$name" =~ $EXCLUDE ]]; then
    continue
  fi
  render_one "$f" &
  running+=1
  if (( running >= 4 )); then
    wait
    running=0
  fi
done
wait
echo "--- summary ($RESULTS)"
grep -c '^OK' "$RESULTS"
grep '^FAIL' "$RESULTS" || echo "no failures"
