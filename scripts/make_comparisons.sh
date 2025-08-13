#!/usr/bin/env bash
set -euo pipefail

# Resolve the directory of this script
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Path to the Python script relative to this wrapper
TOOL="$SCRIPT_DIR/compare_json_hist.py"

# --------- CONFIG YOU CAN TWEAK ---------
# Path to your Python comparer (the augmented, bar-chart version)
#TOOL="compare_json_hist.py"
PYTHON_BIN="${PYTHON_BIN:-python3}"

# Default chart options
METRIC="${METRIC:-time_real}"
PER_EVENT="${PER_EVENT:-1}"        # 1=per-event, 0=raw
TOP_PACKAGES="${TOP_PACKAGES:-15}" # top N packages for the overall package plot
TOP_LABELS="${TOP_LABELS:-15}"     # top N labels per package
ROTATE="${ROTATE:-60}"
LABEL_FONTSIZE="${LABEL_FONTSIZE:-6}"

usage() {
  cat <<EOF
Usage: $0 <runA.json> <runB.json> <mapping.json> <out_dir> [packages.txt]

- <runA.json>, <runB.json> : timing JSONs
- <mapping.json>           : grouping JSON (for augment_json)
- <out_dir>                : where to save images
- [packages.txt]           : optional file, one package name per line

Environment overrides (optional):
  PYTHON_BIN          (default: python3)
  TOOL                (default: compare_json_bar_augmented.py)
  METRIC              (default: time_real)
  PER_EVENT           (default: 1)  # set 0 to disable per-event normalization
  TOP_PACKAGES        (default: 50)
  TOP_LABELS          (default: 80)
  ROTATE              (default: 60)
  LABEL_FONTSIZE      (default: 8)

Examples:
  $0 runA.json runB.json hlt.json out
  $0 runA.json runB.json hlt.json out my_packages.txt
EOF
  exit 1
}

[[ $# -lt 4 ]] && usage

JSON_A="$1"
JSON_B="$2"
MAP="$3"
OUTDIR="$4"
PKGFILE="${5:-}"

mkdir -p "$OUTDIR"

# Basenames for nice filenames
baseA="$(basename "$JSON_A")"
baseA="${baseA%.*}"
baseB="$(basename "$JSON_B")"
baseB="${baseB%.*}"

# Helper: slugify package names for filenames
slugify() {
  # lower, replace non-alnum with underscores, collapse repeats, trim edges
  printf '%s' "$1" |
    tr '[:upper:]' '[:lower:]' |
    sed -E 's/[^a-z0-9]+/_/g; s/^_+//; s/_+$//'
}

# Build common flags
common_flags=(
  "--map" "$MAP"
  "-m" "$METRIC"
  "--rotate" "$ROTATE"
  "--label-fontsize" "$LABEL_FONTSIZE"
)
# per-event toggle
if [[ "$PER_EVENT" == "1" ]]; then
  common_flags+=("--per-event")
fi

# 1) Overall comparison at PACKAGE level
overall_png="${OUTDIR}/compare_packages_${baseA}_vs_${baseB}.png"
echo "[1/2] Making overall package-level comparison -> $overall_png"
"$PYTHON_BIN" "$TOOL" "$JSON_A" "$JSON_B" \
  "${common_flags[@]}" \
  --level package --sort-by diff --top "$TOP_PACKAGES" \
  --title "Timing by package ($([[ "$PER_EVENT" == "1" ]] && echo 'per-event' || echo 'raw')): ${baseA} vs ${baseB}" \
  --colors $SCRIPT_DIR/../web/colours/default.json --style outline \
  --save "$overall_png" --no-show

# 2) Per-package comparisons at LABEL level
declare -a PACKAGES
if [[ -n "$PKGFILE" ]]; then
  # Read packages from file (non-empty lines)
  mapfile -t PACKAGES < <(grep -v '^[[:space:]]*$' "$PKGFILE")
else
  # Read the packages from the JSON groups
  mapfile -t PACKAGES < <(grep '".*": *".*"' "$MAP" | cut -d'"' -f4 | cut -d '|' -f1 | sort -u | grep -v '^$')
fi

echo "[2/2] Making per-label comparisons for ${#PACKAGES[@]} packages…"
for pkg in "${PACKAGES[@]}"; do
  slug="$(slugify "$pkg")"
  out_png="${OUTDIR}/${slug}_${baseA}_vs_${baseB}.png"
  echo "  - $pkg -> $out_png"
  "$PYTHON_BIN" "$TOOL" "$JSON_A" "$JSON_B" \
    "${common_flags[@]}" \
    --level label --package "$pkg" --sort-by diff --top "$TOP_LABELS" \
    --title "Timing by label (${pkg}; $([[ "$PER_EVENT" == "1" ]] && echo 'per-event' || echo 'raw')): ${baseA} vs ${baseB}" \
    --colors $SCRIPT_DIR/../web/colours/default.json --style outline \
    --label-fontsize 6 --rotate 20 --truncate 25 \
    --save "$out_png" --no-show
done

echo "Done. Images saved under: $OUTDIR"
