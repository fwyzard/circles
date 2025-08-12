#!/usr/bin/env python3
import argparse
import json
import math
import re
from pathlib import Path
from typing import List, Dict, Tuple, Optional

import matplotlib.pyplot as plt


def augment_json(input_data, group_data, debug):
    """
    Augment each module by adding 'expanded' = '<Package>|<Type>|<Label>'.
    If a module doesn't match any rule, assign package 'Unassigned'.
    Wildcards: '*' and '?' in the map are supported (shell-like).
    """

    groups = []
    for raw_pattern, group in group_data.items():
        # Be tolerant of non-string keys
        pattern = str(raw_pattern)

        # Split on the FIRST '|' only: "Type|LabelPattern"
        ctype, sep, label = pattern.partition("|")
        if sep == "":  # no '|' found: treat as label-only pattern
            ctype = ""
            label = pattern

        # Trim whitespace
        ctype = ctype.strip()
        label = label.strip()

        # Convert shell wildcards to regex, anchor to end
        ctype_rx = (
            re.compile(ctype.replace("?", ".").replace("*", ".*") + "$")
            if ctype
            else None
        )
        label_rx = (
            re.compile(label.replace("?", ".").replace("*", ".*") + "$")
            if label
            else None
        )

        groups.append([ctype_rx, label_rx, str(group)])

    for module in input_data.get("modules", []):
        found = False
        mtype = module.get("type", "")
        mlabel = module.get("label", "")

        for ctype_rx, label_rx, group in groups:
            if (ctype_rx is None or ctype_rx.match(mtype)) and (
                label_rx is None or label_rx.match(mlabel)
            ):
                module["expanded"] = "|".join([group, mtype, mlabel])
                found = True
                break

        if not found:
            if debug:
                print(f"Failed to parse {module}")
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


def load_grouping(path: Optional[Path]) -> Optional[Dict]:
    if path is None:
        return None
    with path.open("r") as f:
        return json.load(f)


# ------------------------
# Metric and keys
# ------------------------
def numeric_metric(m: Dict, metric: str, per_event: bool) -> Optional[float]:
    if metric not in m:
        return None
    try:
        val = float(m[metric])
    except Exception:
        return None
    if per_event:
        ev = m.get("events", None)
        if ev and ev > 0:
            val = val / float(ev)
    return val


def package_from_expanded(m: Dict) -> str:
    exp = m.get("expanded", None)
    if not exp:
        return "Unassigned"
    return exp.split("|", 1)[0] if "|" in exp else exp


def key_for_level(m: Dict, level: str) -> str:
    if level == "label":
        return str(m.get("label", ""))
    if level == "type":
        return str(m.get("type", ""))
    if level == "package":
        return package_from_expanded(m)
    if level == "expanded":
        return str(m.get("expanded", "Unassigned|?|?"))
    raise ValueError("level must be one of: label, type, package, expanded")


# ------------------------
# Aggregation & alignment
# ------------------------
def aggregate(
    mods: List[Dict], metric: str, per_event: bool, level: str
) -> Dict[str, float]:
    agg: Dict[str, float] = {}
    for m in mods:
        v = numeric_metric(m, metric, per_event)
        if v is None:
            continue
        k = key_for_level(m, level)
        agg[k] = agg.get(k, 0.0) + v
    return agg


def align_for_bars(
    agg_a: Dict[str, float], agg_b: Dict[str, float]
) -> Tuple[List[str], List[float], List[float], List[float]]:
    cats = sorted(set(agg_a.keys()) | set(agg_b.keys()))
    A = [agg_a.get(c, 0.0) for c in cats]
    B = [agg_b.get(c, 0.0) for c in cats]
    D = [b - a for a, b in zip(A, B)]
    return cats, A, B, D


# ------------------------
# Sorting / trimming
# ------------------------
def sort_indices(
    cats: List[str], A: List[float], B: List[float], D: List[float], how: str
) -> List[int]:
    key_funcs = {
        "A": lambda i: A[i],
        "B": lambda i: B[i],
        "diff": lambda i: abs(D[i]),
        "max": lambda i: max(A[i], B[i]),
        "sum": lambda i: A[i] + B[i],
    }
    keyf = key_funcs.get(how, key_funcs["B"])
    return sorted(range(len(cats)), key=keyf, reverse=True)


def maybe_truncate(names: List[str], n: Optional[int]) -> List[str]:
    if not n or n <= 0:
        return names
    out = []
    for s in names:
        out.append(s if len(s) <= n else s[: max(0, n - 1)] + "…")
    return out


def apply_top(
    cats: List[str],
    A: List[float],
    B: List[float],
    D: List[float],
    order: List[int],
    top: Optional[int],
):
    idxs = order[:top] if (top and top > 0) else order
    return (
        [cats[i] for i in idxs],
        [A[i] for i in idxs],
        [B[i] for i in idxs],
        [D[i] for i in idxs],
    )


# ------------------------
# Plotting
# ------------------------
def bar_panels(
    cats: List[str],
    A: List[float],
    B: List[float],
    D: List[float],
    metric_label: str,
    title: Optional[str],
    subtitle: Optional[str],
    name_a: str,
    name_b: str,
    rotate: int,
    truncate: Optional[int],
    fontsize: int,
    save: Optional[Path],
    show: bool,
):
    if not cats:
        print("No categories to plot after filtering.")
        return

    x = list(range(len(cats)))
    width = 0.42

    fig = plt.figure(figsize=(max(10, len(cats) * 0.45), 7))
    gs = fig.add_gridspec(2, 1, height_ratios=[2, 1.2], hspace=0.28)

    # Top panel: grouped bars A and B
    ax1 = fig.add_subplot(gs[0, 0])
    ax1.bar([i - width / 2 for i in x], A, width=width, label=f"A: {name_a}")
    ax1.bar([i + width / 2 for i in x], B, width=width, label=f"B: {name_b}")
    ax1.set_ylabel(metric_label)
    ax1.set_xticks(x)
    ax1.set_xticklabels(
        maybe_truncate(cats, truncate), rotation=rotate, ha="right", fontsize=fontsize
    )
    ax1.legend(loc="best")
    ax1.grid(axis="y", linestyle=":", alpha=0.5)

    # Bottom panel: difference bars
    ax2 = fig.add_subplot(gs[1, 0])
    ax2.bar(x, D)
    ax2.axhline(0, linestyle="--", linewidth=1)
    ax2.set_ylabel(f"Δ(B−A) {metric_label}")
    ax2.set_xticks(x)
    ax2.set_xticklabels(
        maybe_truncate(cats, truncate), rotation=rotate, ha="right", fontsize=fontsize
    )
    ax2.grid(axis="y", linestyle=":", alpha=0.5)

    if title:
        fig.suptitle(title)
    if subtitle:
        fig.text(0.5, 0.01, subtitle, ha="center")

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
        description="Compare two timing JSONs using a grouping JSON (augment_json) and plot grouped bar charts."
    )
    p.add_argument("json_a", type=Path, help="First timing JSON")
    p.add_argument("json_b", type=Path, help="Second timing JSON")

    # Grouping / augmentation
    p.add_argument(
        "--map",
        type=Path,
        required=True,
        help="Grouping JSON (keys like 'TypeGlob|LabelGlob' -> 'PackageName'). Required for augmentation.",
    )
    p.add_argument(
        "--debug-map",
        action="store_true",
        help="Print failures from augment_json for unmapped modules.",
    )

    # Metrics and x-axis
    p.add_argument(
        "-m", "--metric", default="time_real", help="Metric to use (default: time_real)"
    )
    p.add_argument(
        "--per-event",
        action="store_true",
        help="Divide metric by each module's 'events'",
    )
    p.add_argument(
        "--level",
        choices=["package", "type", "label", "expanded"],
        default="label",
        help="What to put on the X-axis (default: label)",
    )

    # Optional filters on the augmented data
    p.add_argument(
        "--package",
        default=None,
        help="Keep only modules with this exact package (from 'expanded')",
    )
    p.add_argument(
        "--package-regex",
        default=None,
        help="Keep only modules whose package matches this regex",
    )
    p.add_argument(
        "--require-map",
        action="store_true",
        help="Drop modules with package 'Unassigned'",
    )

    # Sorting / trimming / labels
    p.add_argument(
        "--sort-by",
        choices=["A", "B", "diff", "max", "sum"],
        default="B",
        help="Sort categories by this (default: B)",
    )
    p.add_argument(
        "--top", type=int, default=None, help="Keep only top N categories after sorting"
    )
    p.add_argument(
        "--truncate",
        type=int,
        default=48,
        help="Truncate tick labels to N chars (0 disables; default 48)",
    )
    p.add_argument(
        "--rotate",
        type=int,
        default=60,
        help="Rotate x tick labels (degrees, default 60)",
    )
    p.add_argument(
        "--label-fontsize",
        type=int,
        default=9,
        help="Font size for x-axis tick labels (default: 9)",
    )

    # Output
    p.add_argument("--title", default=None)
    p.add_argument(
        "--save",
        type=Path,
        default=None,
        help="Save figure (e.g., out.png). If omitted, shows window.",
    )
    p.add_argument("--no-show", action="store_true")

    args = p.parse_args()

    # Load base files
    data_a = load_full_json(args.json_a)
    data_b = load_full_json(args.json_b)
    group_data = load_grouping(args.map)

    # Augment using user's function
    data_a = augment_json(data_a, group_data, args.debug_map)
    data_b = augment_json(data_b, group_data, args.debug_map)

    mods_a = data_a["modules"]
    mods_b = data_b["modules"]

    # Apply package-based filters (after augmentation)
    if args.require_map:
        mods_a = [m for m in mods_a if package_from_expanded(m) != "Unassigned"]
        mods_b = [m for m in mods_b if package_from_expanded(m) != "Unassigned"]

    if args.package:
        mods_a = [m for m in mods_a if package_from_expanded(m) == args.package]
        mods_b = [m for m in mods_b if package_from_expanded(m) == args.package]

    if args.package_regex:
        rx = re.compile(args.package_regex)
        mods_a = [m for m in mods_a if rx.search(package_from_expanded(m))]
        mods_b = [m for m in mods_b if rx.search(package_from_expanded(m))]

    # Aggregate by chosen level
    agg_a = aggregate(mods_a, args.metric, args.per_event, args.level)
    agg_b = aggregate(mods_b, args.metric, args.per_event, args.level)

    cats, Avals, Bvals, Dvals = align_for_bars(agg_a, agg_b)

    # Sort + take top
    order = sort_indices(cats, Avals, Bvals, Dvals, args.sort_by)
    cats, Avals, Bvals, Dvals = apply_top(cats, Avals, Bvals, Dvals, order, args.top)

    metric_label = args.metric + (" (per event)" if args.per_event else "")

    subtitle_bits = [f"level={args.level}"]
    if args.package:
        subtitle_bits.append(f"package == {args.package!r}")
    if args.package_regex:
        subtitle_bits.append(f"package ~ /{args.package_regex}/")
    if args.require_map:
        subtitle_bits.append("require_map")
    subtitle = "; ".join(subtitle_bits)

    bar_panels(
        cats,
        Avals,
        Bvals,
        Dvals,
        metric_label=metric_label,
        title=args.title,
        subtitle=subtitle,
        name_a=args.json_a.name,
        name_b=args.json_b.name,
        rotate=args.rotate,
        truncate=None if args.truncate == 0 else args.truncate,
        fontsize=args.label_fontsize,
        save=args.save,
        show=not args.no_show,
    )


if __name__ == "__main__":
    main()
