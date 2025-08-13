#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import argparse
import json
import re
from pathlib import Path
from typing import List, Dict, Tuple, Optional

import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt
from matplotlib.colors import to_rgb, to_hex
from matplotlib.patches import Patch


# ------------------------
# Mapping / augmentation
# ------------------------
def augment_json(input_data, group_data, debug):
    """
    Get the input json via input_data and augment it by adding a new key to
    each element, named 'expanded', that will combine the information coming
    from the input json and from the grouping json. All modules that cannot be
    found in the original group_data will be assigned to the macro package
    "Unassigned". The separator between the different fields is '|'.
    """

    groups = []
    for raw_pattern, group in group_data.items():
        # tolerant: accept non-string keys, and split on the FIRST pipe only
        pattern = str(raw_pattern)
        ctype, sep, label = pattern.partition("|")
        if sep == "":
            ctype = ""
            label = pattern
        ctype = ctype.strip()
        label = label.strip()
        ctype = (
            re.compile(ctype.replace("?", ".").replace("*", ".*") + "$")
            if ctype
            else None
        )
        label = (
            re.compile(label.replace("?", ".").replace("*", ".*") + "$")
            if label
            else None
        )
        groups.append([ctype, label, str(group)])

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


def load_grouping(path: Path) -> Dict:
    with path.open("r") as f:
        return json.load(f)


def load_colors(path: Optional[Path]) -> Dict[str, str]:
    if not path:
        return {}
    with path.open("r") as f:
        raw = json.load(f)
    return {str(k): str(v) for k, v in raw.items()}


def get_total_events(data: Dict) -> float:
    """Return the total number of events from the file-level 'total' block (fallback 1)."""
    try:
        tot = data.get("total", {})
        ev = float(tot.get("events", 0))
        return ev if ev > 0 else 1.0
    except Exception:
        return 1.0


# ------------------------
# Metric & keys
# ------------------------
def numeric_metric(
    m: Dict, metric: str, per_event: bool, total_events: float
) -> Optional[float]:
    """Return module metric, optionally normalized by the FILE total events (not per-module)."""
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
# Aggregation & alignment
# ------------------------
def aggregate(
    mods: List[Dict], metric: str, per_event: bool, level: str, total_events: float
) -> Dict[str, float]:
    agg: Dict[str, float] = {}
    for m in mods:
        v = numeric_metric(m, metric, per_event, total_events)
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
# Colors
# ------------------------
def _clamp01(x: float) -> float:
    return 0.0 if x < 0 else 1.0 if x > 1 else x


def adjust_lightness(hex_color: str, factor: float) -> str:
    """factor >1 -> lighter, <1 -> darker."""
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


def cat_to_package(
    cats: List[str],
    level: str,
    mods_a: List[Dict],
    mods_b: List[Dict],
    metric: str,
    per_event: bool,
    total_events_a: float,
    total_events_b: float,
) -> Dict[str, str]:
    """Determine a dominant package for each category (for type/label levels)."""
    mapping: Dict[str, Dict[str, float]] = {c: {} for c in cats}
    for m in mods_a:
        v = numeric_metric(m, metric, per_event, total_events_a)
        if v is None:
            continue
        k = key_for_level(m, level)
        if k not in mapping:
            continue
        pkg = package_from_expanded(m)
        mapping[k][pkg] = mapping[k].get(pkg, 0.0) + v
    for m in mods_b:
        v = numeric_metric(m, metric, per_event, total_events_b)
        if v is None:
            continue
        k = key_for_level(m, level)
        if k not in mapping:
            continue
        pkg = package_from_expanded(m)
        mapping[k][pkg] = mapping[k].get(pkg, 0.0) + v

    out: Dict[str, str] = {}
    for k, d in mapping.items():
        out[k] = max(d.items(), key=lambda x: x[1])[0] if d else "Unassigned"
    return out


def color_for_category(
    cat: str, level: str, pkg_of_cat: str, colors: Dict[str, str]
) -> str:
    base = pick_base_color(pkg_of_cat, colors)
    if level == "package":
        return base
    h = abs(hash(cat)) % 997
    factor = 0.85 + (h / 997.0) * 0.40  # ~0.85..1.25
    return adjust_lightness(base, factor)


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
    colors_A: List[str],
    colors_B: List[str],
    edge_colors: List[str],
    metric_label: str,
    title: Optional[str],
    subtitle: Optional[str],
    name_a: str,
    name_b: str,
    rotate: int,
    truncate: Optional[int],
    fontsize: int,
    style: str,
    level: str,
    package_top: str,
    outline_width: float,
    stack_key: str,
    save: Optional[Path],
    show: bool,
):
    if not cats:
        print("No categories to plot after filtering.")
        return

    x = list(range(len(cats)))
    fig = plt.figure(figsize=(max(10, len(cats) * 0.45), 7))
    gs = fig.add_gridspec(2, 1, height_ratios=[2, 1.2], hspace=0.28)

    # ---- Top panel ----
    ax1 = fig.add_subplot(gs[0, 0])

    if level == "package" and package_top == "stacked":
        # Two bars (A & B), each stacked by package composition.
        x2 = [0, 1]
        ax1.set_xticks(x2)
        ax1.set_xticklabels(
            [name_a, name_b], rotation=0, ha="center", fontsize=fontsize
        )

        bottomA = 0.0
        bottomB = 0.0

        # Stacking order: draw smaller layers first and bigger last so the biggest |Δ| is on TOP.
        def _key_for(i):
            if stack_key == "A":
                return A[i]
            if stack_key == "B":
                return B[i]
            if stack_key == "max":
                return max(A[i], B[i])
            if stack_key == "sum":
                return A[i] + B[i]
            # default: abs diff
            return abs(D[i])

        stack_order = sorted(range(len(cats)), key=_key_for)  # ascending

        for i in stack_order:
            segA = A[i]
            segB = B[i]
            seg_fill = colors_B[i]  # package color

            # A & B segments: both filled with same package color; thin edge for separation
            ax1.bar(
                0,
                segA,
                bottom=bottomA,
                width=0.6,
                color=seg_fill,
                edgecolor="black",
                linewidth=0.4,
            )
            ax1.bar(
                1,
                segB,
                bottom=bottomB,
                width=0.6,
                color=seg_fill,
                edgecolor="black",
                linewidth=0.4,
            )

            bottomA += segA
            bottomB += segB

        # Increase Y-axis limit to give space for legend
        max_height = max(bottomA, bottomB)
        ax1.set_ylim(0, max_height * 1.15)  # 15% extra space

        ax1.set_ylabel(metric_label)
        ax1.grid(axis="y", linestyle=":", alpha=0.5)

        # Legend: package colors
        pkg_handles = [
            Patch(facecolor=colors_B[i], edgecolor="black", label=cats[i])
            for i in range(len(cats))
        ]
        ax1.legend(handles=pkg_handles, loc="center left", bbox_to_anchor=(1, 0.5))

    else:
        # GROUPED per-category bars
        width = 0.42
        if style == "outline":
            ax1.bar(
                [i - width / 2 for i in x],
                A,
                width=width,
                facecolor="white",
                edgecolor=edge_colors,
                linewidth=outline_width,
                label=f"A: {name_a}",
            )
            ax1.bar(
                [i + width / 2 for i in x],
                B,
                width=width,
                color=colors_B,
                edgecolor="none",
                label=f"B: {name_b}",
            )
        else:
            ax1.bar(
                [i - width / 2 for i in x],
                A,
                width=width,
                color=colors_A,
                hatch="///",
                edgecolor="black",
                label=f"A: {name_a}",
            )
            ax1.bar(
                [i + width / 2 for i in x],
                B,
                width=width,
                color=colors_B,
                hatch="\\\\\\\\",
                edgecolor="black",
                label=f"B: {name_b}",
            )

        ax1.set_ylabel(metric_label)
        ax1.set_xticks(x)
        ax1.set_xticklabels(
            maybe_truncate(cats, truncate),
            rotation=rotate,
            ha="right",
            fontsize=fontsize,
        )
        ax1.grid(axis="y", linestyle=":", alpha=0.5)

        # Series-style legend via proxies
        if style == "outline":
            handle_A = Patch(
                facecolor="white",
                edgecolor="black",
                linewidth=outline_width,
                label=f"A: {name_a}",
            )
            handle_B = Patch(facecolor="0.6", edgecolor="none", label=f"B: {name_b}")
        else:
            handle_A = Patch(
                facecolor="white", hatch="///", edgecolor="black", label=f"A: {name_a}"
            )
            handle_B = Patch(
                facecolor="white",
                hatch="\\\\\\\\",
                edgecolor="black",
                label=f"B: {name_b}",
            )
        ax1.legend(handles=[handle_A, handle_B], loc="best")

    # ---- Bottom panel: differences per category ----
    ax2 = fig.add_subplot(gs[1, 0])
    ax2.bar(x, D, color=colors_B, edgecolor="black", linewidth=0.6)
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
        description="Compare two timing JSONs using a grouping JSON (augment_json) and plot bar charts with package colors."
    )
    p.add_argument("json_a", type=Path, help="First timing JSON")
    p.add_argument("json_b", type=Path, help="Second timing JSON")

    # Mapping & colors
    p.add_argument(
        "--map",
        type=Path,
        required=True,
        help="Grouping JSON (TypeGlob|LabelGlob -> Package)",
    )
    p.add_argument(
        "--colors",
        type=Path,
        default=None,
        help="Colors JSON mapping Package -> HEX (e.g. '#1f77b4')",
    )
    p.add_argument(
        "--debug-map",
        action="store_true",
        help="Print failures from augment_json for unmapped modules.",
    )

    # Metrics / x-axis
    p.add_argument(
        "-m", "--metric", default="time_real", help="Metric to use (default: time_real)"
    )
    p.add_argument(
        "--per-event",
        action="store_true",
        help="Divide metric by the FILE's total events (not per-module)",
    )
    p.add_argument(
        "--level",
        choices=["package", "type", "label", "expanded"],
        default="label",
        help="X-axis categories (default: label)",
    )

    # Filters (after augmentation)
    p.add_argument(
        "--package", default=None, help="Keep only modules in this exact package"
    )
    p.add_argument(
        "--package-regex",
        default=None,
        help="Keep modules whose package matches this regex",
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
        help="Sort categories by this key (default: B)",
    )
    p.add_argument(
        "--top", type=int, default=None, help="Show only top N categories after sorting"
    )
    p.add_argument(
        "--truncate",
        type=int,
        default=48,
        help="Truncate tick labels to N chars (0 disables)",
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
        help="Font size for x-axis tick labels (default 9)",
    )

    # Style & package-level top panel mode
    p.add_argument(
        "--style",
        choices=["outline", "hatch"],
        default="outline",
        help="Distinguish A vs B in grouped views: outline (A white+border, B solid) or hatch (different hatches)",
    )
    p.add_argument(
        "--outline-width",
        type=float,
        default=0.8,
        help="Line width for outline style (default: 0.8)",
    )
    p.add_argument(
        "--package-top",
        choices=["stacked", "grouped"],
        default="stacked",
        help="When level=package: top shows two stacked bars by package composition; else grouped per-package bars",
    )
    p.add_argument(
        "--stack-sort-by",
        choices=["diff", "A", "B", "max", "sum"],
        default="diff",
        help="For stacked composition, order packages & stack layers by this key (default: diff=|B-A|)",
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

    # Load base files and colors
    data_a = load_full_json(args.json_a)
    data_b = load_full_json(args.json_b)
    group_data = load_grouping(args.map)
    color_map = load_colors(args.colors)

    # Total events (file-level) for correct per-event normalization
    total_events_a = get_total_events(data_a)
    total_events_b = get_total_events(data_b)

    # Augment
    data_a = augment_json(data_a, group_data, args.debug_map)
    data_b = augment_json(data_b, group_data, args.debug_map)
    mods_a = data_a["modules"]
    mods_b = data_b["modules"]

    # Filters
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

    # Aggregate (note: normalization uses file total events)
    agg_a = aggregate(mods_a, args.metric, args.per_event, args.level, total_events_a)
    agg_b = aggregate(mods_b, args.metric, args.per_event, args.level, total_events_b)
    cats, Avals, Bvals, Dvals = align_for_bars(agg_a, agg_b)

    # Sort + top: in stacked composition, force order by abs diff so bottom plot starts with largest |Δ|
    if args.level == "package" and args.package_top == "stacked":
        order = sort_indices(
            cats, Avals, Bvals, Dvals, args.stack_sort_by
        )  # default 'diff'
    else:
        order = sort_indices(cats, Avals, Bvals, Dvals, args.sort_by)

    cats, Avals, Bvals, Dvals = apply_top(cats, Avals, Bvals, Dvals, order, args.top)

    # Colors per category
    if args.level == "package":
        pkg_for_cat = {c: c for c in cats}
    else:
        pkg_for_cat = cat_to_package(
            cats,
            args.level,
            mods_a,
            mods_b,
            args.metric,
            args.per_event,
            total_events_a,
            total_events_b,
        )

    colors_A, colors_B, edge_colors = [], [], []
    for c in cats:
        base_pkg = pkg_for_cat.get(c, "others")
        base_hex = pick_base_color(base_pkg, color_map)
        varied_hex = color_for_category(c, args.level, base_pkg, color_map)
        colors_B.append(varied_hex)
        colors_A.append(varied_hex)
        edge_colors.append(base_hex)  # outline edge uses exact package color

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
        colors_A=colors_A,
        colors_B=colors_B,
        edge_colors=edge_colors,
        metric_label=metric_label,
        title=args.title,
        subtitle=subtitle,
        name_a=args.json_a.name,
        name_b=args.json_b.name,
        rotate=args.rotate,
        truncate=None if args.truncate == 0 else args.truncate,
        fontsize=args.label_fontsize,
        style=args.style,
        level=args.level,
        package_top=args.package_top,
        outline_width=args.outline_width,
        stack_key=args.stack_sort_by,
        save=args.save,
        show=not args.no_show,
    )


if __name__ == "__main__":
    main()
