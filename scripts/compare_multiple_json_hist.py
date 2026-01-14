#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import argparse
import json
import re
from pathlib import Path
from typing import List, Dict, Tuple, Optional
from collections import defaultdict, OrderedDict

import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
from matplotlib.colors import to_rgb, to_hex
from matplotlib.patches import Patch

import mplhep as hep
hep.style.use("CMS")

# ------------------------
# Mapping / augmentation
# ------------------------
def augment_json(input_data, group_data, debug):
    """
    Add key 'expanded' = 'Package|type|label' to each module.
    First match wins. Unmatched -> 'Unassigned|type|label'
    """
    groups = []
    for raw_pattern, group in group_data.items():
        pattern = str(raw_pattern)
        ctype, sep, label = pattern.partition("|")
        if sep == "":
            ctype = ""
            label = pattern
        ctype = ctype.strip()
        label = label.strip()
        ctype = re.compile(ctype.replace("?", ".").replace("*", ".*") + "$") if ctype else None
        label = re.compile(label.replace("?", ".").replace("*", ".*") + "$") if label else None
        groups.append([ctype, label, str(group)])

    for module in input_data.get("modules", []):
        found = False
        mtype = module.get("type", "")
        mlabel = module.get("label", "")
        for ctype_rx, label_rx, group in groups:
            if (ctype_rx is None or ctype_rx.match(mtype)) and (label_rx is None or label_rx.match(mlabel)):
                module["expanded"] = "|".join([group, mtype, mlabel])
                found = True
                break
        if not found:
            if debug:
                print(f"Failed to map module: type={mtype} label={mlabel}")
            module["expanded"] = "|".join(["Unassigned", mtype, mlabel])

    return input_data


# ------------------------
# I/O helpers
# ------------------------
def load_full_json(path: Path) -> Dict:
    with path.open("r") as f:
        data = json.load(f)
    if "modules" not in data or not isinstance(data["modules"], list):
        raise ValueError(f"{path} does not contain a top-level 'modules' list")
    return data


def load_grouping(path: Path) -> Dict:
    with path.open("r") as f:
        # preserve JSON order: first rule wins
        return json.load(f, object_pairs_hook=OrderedDict)


def load_colors(path: Optional[Path]) -> Dict[str, str]:
    if not path:
        return {}
    with path.open("r") as f:
        raw = json.load(f)
    return {str(k): str(v) for k, v in raw.items()}


def get_total_events(data: Dict) -> float:
    try:
        tot = data.get("total", {})
        ev = float(tot.get("events", 0))
        return ev if ev > 0 else 1.0
    except Exception:
        return 1.0


# ------------------------
# Metric & keys
# ------------------------
def numeric_metric(m: Dict, metric: str, per_event: bool, total_events: float) -> Optional[float]:
    if metric not in m:
        return None
    try:
        val = float(m[metric])
    except Exception:
        return None
    if per_event and total_events > 0:
        val = val / total_events
    return val


def package_from_expanded(m: Dict) -> str:
    exp = m.get("expanded", "")
    return exp.split("|", 1)[0] if "|" in exp else "Unassigned"


def key_for_level(m: Dict, level: str) -> str:
    if level == "label":
        return str(m.get("label", ""))
    if level == "type":
        return str(m.get("type", ""))
    if level == "package":
        return package_from_expanded(m)
    if level == "expanded":
        return str(m.get("expanded", "Unassigned|?|?"))
    raise ValueError("level must be one of: package, type, label, expanded")


# ------------------------
# Aggregation
# ------------------------
def aggregate(mods: List[Dict], metric: str, per_event: bool, level: str, total_events: float) -> Dict[str, float]:
    agg: Dict[str, float] = {}
    for m in mods:
        v = numeric_metric(m, metric, per_event, total_events)
        if v is None:
            continue
        k = key_for_level(m, level)
        agg[k] = agg.get(k, 0.0) + v
    return agg


def union_categories(aggs: List[Dict[str, float]]) -> List[str]:
    cats = set()
    for a in aggs:
        cats |= set(a.keys())
    return sorted(cats)


# ------------------------
# Colors
# ------------------------
def _clamp01(x: float) -> float:
    return 0.0 if x < 0 else 1.0 if x > 1 else x


def adjust_lightness(hex_color: str, factor: float) -> str:
    r, g, b = to_rgb(hex_color)
    if factor >= 1:
        r = r + (1 - r) * (factor - 1)
        g = g + (1 - g) * (factor - 1)
        b = b + (1 - b) * (factor - 1)
    else:
        r = r * factor
        g = g * factor
        b = b * factor
    return to_hex((_clamp01(r), _clamp01(g), _clamp01(b)))


def pick_base_color(package: str, cmap: Dict[str, str]) -> str:
    return cmap.get(package, cmap.get("others", "#cccccc"))


def color_for_category(cat: str, level: str, pkg_of_cat: str, colors: Dict[str, str]) -> str:
    base = pick_base_color(pkg_of_cat, colors)
    if level == "package":
        return base
    h = abs(hash(cat)) % 997
    factor = 0.85 + (h / 997.0) * 0.40  # ~0.85..1.25
    return adjust_lightness(base, factor)


def dominant_package_for_cat(
    cat: str,
    level: str,
    mods_by_file: List[List[Dict]],
    metric: str,
    per_event: bool,
    total_events_by_file: List[float],
) -> str:
    """
    For type/label/expanded categories: determine dominant package by summed contribution across ALL files.
    """
    contrib = defaultdict(float)
    for mods, tev in zip(mods_by_file, total_events_by_file):
        for m in mods:
            if key_for_level(m, level) != cat:
                continue
            v = numeric_metric(m, metric, per_event, tev)
            if v is None:
                continue
            contrib[package_from_expanded(m)] += v
    return max(contrib.items(), key=lambda x: x[1])[0] if contrib else "Unassigned"


# ------------------------
# Plotting (N files)
# ------------------------
def plot_stacked_bars(
    cats: List[str],
    values_by_file: List[List[float]],  # shape: [n_files][n_cats]
    file_labels: List[str],
    cat_colors: List[str],
    metric_label: str,
    title: Optional[str],
    subtitle: Optional[str],
    lumi_text: Optional[str],
    rotate: int,
    truncate: Optional[int],
    fontsize: int,
    baseline_idx: int,
    save: Optional[Path],
    show: bool,
):
    def maybe_truncate(names: List[str], n: Optional[int]) -> List[str]:
        if not n or n <= 0:
            return names
        out = []
        for s in names:
            out.append(s if len(s) <= n else s[: max(0, n - 1)] + "…")
        return out

    n_files = len(values_by_file)
    n_cats = len(cats)
    if n_files < 1 or n_cats < 1:
        print("Nothing to plot.")
        return

    # Figure layout
    fig = plt.figure(figsize=(max(10, n_files * 1.2), 7))
    gs = fig.add_gridspec(2, 1, height_ratios=[2, 1.], hspace=0.15)

    # ---- Top panel: stacked per file ----
    ax1 = fig.add_subplot(gs[0, 0])
    x = list(range(n_files))
    bottoms = [0.0] * n_files

    # stack order: small to large average contribution (prettier)
    avg = np.mean(np.array(values_by_file), axis=0)  # per-cat average
    stack_order = list(np.argsort(avg))  # ascending

    for j in stack_order:
        seg = [values_by_file[i][j] for i in range(n_files)]
        ax1.bar(x, seg, bottom=bottoms, width=0.7, color=cat_colors[j], edgecolor="black", linewidth=0.4,
                label=cats[j])
        bottoms = [b + s for b, s in zip(bottoms, seg)]

    ax1.set_xticks(x)
    ax1.set_xticklabels(file_labels, fontsize=fontsize)
    ax1.tick_params(axis="y", labelsize=fontsize)
    ax1.set_ylabel(metric_label, fontsize=fontsize+2)
    ax1.set_ylim(0, max(bottoms) * 1.15 if bottoms else 1.0)
    ax1.grid(axis="y", linestyle=":", alpha=0.5)

    # annotate totals
    ymax = max(bottoms) if bottoms else 0.0
    for i, tot in enumerate(bottoms):
        ax1.text(x[i], tot + 0.02 * (ymax if ymax > 0 else 1.0), f"{tot:.2f}", ha="center", va="bottom", fontsize=12, fontweight="bold")

    # legend (categories)
    # If too many categories, legend can get huge; user can restrict with --top in future if needed.
    ax1.legend(loc="upper left", bbox_to_anchor=(1, 1.05), fontsize=fontsize-2, frameon=False)
    hep.cms.text("Simulation Preliminary", ax=ax1, fontsize=fontsize+4)
    hep.cms.lumitext(f"{lumi_text}", ax=ax1, fontsize=fontsize+4)

    # ---- Bottom panel: delta vs baseline per file, split by category ----
    ax2 = fig.add_subplot(gs[1, 0], sharex=ax1)

    baseline = np.array(values_by_file[baseline_idx])         # shape: (n_cats,)
    vals = np.array(values_by_file)                           # shape: (n_files, n_cats)
    delta = vals - baseline[None, :]                          # shape: (n_files, n_cats)

    pos_bottom = np.zeros(n_files)
    neg_bottom = np.zeros(n_files)

    for j in stack_order:  # use same stacking order as ax1 for consistency
        dseg = delta[:, j]

        pos = np.clip(dseg, 0, None)
        neg = np.clip(dseg, None, 0)

        ax2.bar(x, pos, bottom=pos_bottom, width=0.7,
                color=cat_colors[j], edgecolor="black", linewidth=0.4)
        ax2.bar(x, neg, bottom=neg_bottom, width=0.7,
                color=cat_colors[j], edgecolor="black", linewidth=0.4)

        pos_bottom += pos
        neg_bottom += neg

    ax2.axhline(0, linestyle="--", linewidth=1, color='black')
    ax2.tick_params(axis="y", labelsize=fontsize)
    ax2.set_ylabel(f"Δt vs {file_labels[baseline_idx]} [ms]", fontsize=fontsize+2)
    ax2.set_xticks(x)
    ax2.set_xticklabels(file_labels, fontsize=fontsize)
    ax2.grid(axis="y", linestyle=":", alpha=0.5)

    if title:
        fig.suptitle(title)
    # if subtitle:
    #     fig.text(0.5, 0.01, subtitle, ha="center")

    if save:
        save.parent.mkdir(parents=True, exist_ok=True)
        fig.savefig(save, dpi=150, bbox_inches="tight")
        print(f"Saved figure to: {save}")
    if show and not save:
        plt.show()


# ------------------------
# CLI
# ------------------------
def main():
    p = argparse.ArgumentParser(
        description="Compare N timing JSONs using a grouping JSON and plot stacked bars by category."
    )
    p.add_argument("json_files", nargs="+", type=Path, help="Timing JSON files (2 or more)")

    # Mapping & colors
    p.add_argument("--map", type=Path, required=True, help="Grouping JSON (TypeGlob|LabelGlob -> Package)")
    p.add_argument("--colors", type=Path, default=None, help="Colors JSON mapping Package -> HEX")
    p.add_argument("--debug-map", action="store_true", help="Print failures from augment_json for unmapped modules.")

    # Metrics / x-axis
    p.add_argument("-m", "--metric", default="time_real", help="Metric to use (default: time_real)")
    p.add_argument("--per-event", action="store_true", help="Divide metric by the FILE's total events")
    p.add_argument("--level", choices=["package", "type", "label", "expanded"], default="package",
                   help="Stack categories at this level (default: package)")

    # Filters
    p.add_argument("--package", default=None, help="Keep only modules in this exact package")
    p.add_argument("--package-regex", default=None, help="Keep modules whose package matches this regex")
    p.add_argument("--require-map", action="store_true", help="Drop modules with package 'Unassigned'")

    # Labels / appearance
    p.add_argument("--labels", nargs="*", default=None,
                   help="Custom labels for each input file (same count as json_files)")
    p.add_argument("--rotate", type=int, default=0, help="Rotate x tick labels (degrees, default 0)")
    p.add_argument("--label-fontsize", type=int, default=10, help="Font size for x-axis labels")
    p.add_argument("--lumi-text", default="", help="Right-side label (e.g. CMSSW..., sample)")

    # Baseline for delta
    p.add_argument("--baseline", type=int, default=0, help="Index of baseline file for Δ (default 0)")

    # Output
    p.add_argument("--title", default=None)
    p.add_argument("--save", type=Path, default="out.png", help="Save figure (default: out.png)")
    p.add_argument("--no-show", action="store_true")

    args = p.parse_args()

    print(args.labels)
    print(args.json_files)

    if args.labels and len(args.labels) != len(args.json_files):
        raise SystemExit("ERROR: --labels must match number of input JSON files.")

    file_labels = args.labels if args.labels else [f.name for f in args.json_files]
    if not (0 <= args.baseline < len(args.json_files)):
        raise SystemExit("ERROR: --baseline must be a valid index into json_files.")

    # Load mapping + colors
    group_data = load_grouping(args.map)
    color_map = load_colors(args.colors)

    # Load, augment, filter, aggregate each file
    aggs = []
    mods_by_file = []
    total_events_by_file = []

    for jf in args.json_files:
        data = load_full_json(jf)
        tev = get_total_events(data)
        total_events_by_file.append(tev)

        data = augment_json(data, group_data, args.debug_map)
        mods = data["modules"]

        # Filters
        if args.require_map:
            mods = [m for m in mods if package_from_expanded(m) != "Unassigned"]
        if args.package:
            mods = [m for m in mods if package_from_expanded(m) == args.package]
        if args.package_regex:
            rx = re.compile(args.package_regex)
            mods = [m for m in mods if rx.search(package_from_expanded(m))]

        mods_by_file.append(mods)
        aggs.append(aggregate(mods, args.metric, args.per_event, args.level, tev))

    cats = union_categories(aggs)

    # Build values matrix [n_files][n_cats]
    values_by_file = []
    for agg in aggs:
        values_by_file.append([agg.get(c, 0.0) for c in cats])

    # Category -> dominant package for colors (unless level=package)
    pkg_for_cat = {}
    if args.level == "package":
        for c in cats:
            pkg_for_cat[c] = c
    else:
        for c in cats:
            pkg_for_cat[c] = dominant_package_for_cat(
                c, args.level, mods_by_file, args.metric, args.per_event, total_events_by_file
            )

    # Colors per category
    cat_colors = []
    for c in cats:
        pkg = pkg_for_cat.get(c, "others")
        cat_colors.append(color_for_category(c, args.level, pkg, color_map))

    metric_label = "Time per event [ms]" if args.per_event else "Time [ms]"
    subtitle_bits = [f"level={args.level}"]
    if args.package:
        subtitle_bits.append(f"package == {args.package!r}")
    if args.package_regex:
        subtitle_bits.append(f"package ~ /{args.package_regex}/")
    if args.require_map:
        subtitle_bits.append("require_map")
    subtitle = "; ".join(subtitle_bits)

    plot_stacked_bars(
        cats=cats,
        values_by_file=values_by_file,
        file_labels=file_labels,
        cat_colors=cat_colors,
        metric_label=metric_label,
        title=args.title,
        subtitle=subtitle,
        lumi_text=args.lumi_text,
        rotate=args.rotate,
        truncate=None,
        fontsize=args.label_fontsize,
        baseline_idx=args.baseline,
        save=args.save,
        show=not args.no_show,
    )


if __name__ == "__main__":
    import numpy as np  # keep local to avoid changing your import style too much
    main()
