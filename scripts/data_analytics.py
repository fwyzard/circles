import argparse
import json
import re
from collections import defaultdict
from rich.console import Console
from pprint import pprint

METRICS = ['mem_alloc', 'mem_free',
           'time_real', 'time_thread',
           'time_real_abs', 'time_thread_abs',
           'hs23_time_real', 'hs23_time_thread',
           'hs23_time_real_abs', 'hs23_time_thread_abs'
           ]

def parse_arguments():
    parser = argparse.ArgumentParser(description="Process and aggregate JSON data based on input parameters.")

    parser.add_argument('--input-files', required=True, help='Path to the input JSON files to be read, comma separated.')
    parser.add_argument('--group-file', required=True, help='Path to the JSON file responsible for grouping.')
    parser.add_argument('--level', type=int, default=1, help='Level at which data aggregation should stop.')
    parser.add_argument('--sort', choices=['a', 'd'], default='d', help="Sort order: 'a' for ascending, 'd' for descending.")
    parser.add_argument('--metric', choices=METRICS, default='time_real', help="Quantity to aggregate. Valid values are 'mem_alloc', 'mem_free', 'time_real', 'time_thread', 'time_real_abs', 'time_thread_abs'")
    parser.add_argument('--limit', type=int, default=999, help='Maximum number of items to be printed to the terminal.')
    parser.add_argument('--debug', action="store_true", help='Enable debugging printouts.')
    parser.add_argument('--markdown', action="store_true", help='Format the output as a Markdown table.')
    parser.add_argument('--latex', action="store_true", help='Format the output as a latex table.')
    parser.add_argument('--filter', type=str, default='.*', help='Regular expression that is used to filter the output results.')
    parser.add_argument('--cutoff', type=float, default=-1., help='Cutoff to be applied to the relative fraction of each selected components to be printed on the terminal.')
    parser.add_argument('--latexcutoff', type=float, default=-1., help='Cutoff to be applied to the relative fraction of each selected components to be printed on aggregate latex tables.')
    parser.add_argument('--dropfirst', type=int, default=0, help='Drop the first specified elements from the full path of the modules.')
    parser.add_argument('--alert', type=float, default=5, help='Alert threshold, in percetage, to be used to highlight differences between the two input files.')
    parser.add_argument('--aggregate', action="store_true", default=False, help='Aggregate the filtered data in a hierarchical representation. This options only works when --latex is enabled.')

    return parser.parse_args()

def load_json(file_path):
    """Helper function to load JSON data from a file."""
    with open(file_path, 'r') as file:
        return json.load(file)

def augment_json(input_data, group_data, debug):
    """
    Get the input json via input_data and augment it by adding a new key to
    each element, named 'expanded', that will combine the information coming
    from the input json and from the grouping json. All modules that cannot be
    found in the original group_data will be assigned to the macro package
    "Unassigned". The separator between the different fields is '|'.
    """

    groups = []
    for pattern, group in group_data.items():
        if '|' in pattern:
            ctype, label = pattern.split('|')
        else:
            ctype = ''
            label = pattern
        ctype = re.compile(ctype.replace('?', '.').replace('*', '.*') + '$') if ctype else None
        label = re.compile(label.replace('?', '.').replace('*', '.*') + '$') if label else None
        groups.append([ctype, label, group])

    for module in input_data['modules']:
        found = False
        for g in groups:
            if (g[0] is None or g[0].match(module['type'])) and (g[1] is None or g[1].match(module['label'])):
                module['expanded'] = "|".join([g[2], module['type'], module['label']])
                found = True
                break;
        if not found:
            if debug:
                print("Failed to parse {}".format(module))
            module['expanded'] =  "|".join(["Unassigned", module['type'], module['label']])

    return input_data

def aggregate_data(input_data, metric, level, filter, dropfirst=0):
    """
    Aggregate the data in the original json according to the command line arguments supplied.
    """

    try:
        re_filter = re.compile(filter)
    except Exception as _:
        print("Failed to compile the supplied Regular expression {}".format(filter))
        re_filter = re.compile(".*")

    result = {}
    for module in input_data['modules']:
        if re_filter.match(module['expanded']):
            key = '|'.join(module['expanded'].split('|')[dropfirst:level])
            if not key in result.keys():
                result[key] = 0.
            result[key] += module[metric]/input_data['total']['events']

    return result

def flatten_dict(data):
    """
    Flattens nested dictionaries into a list of tuples (key_path, values).
    """
    def flatten(current_data, parent_key=''):
        if isinstance(current_data, dict):
            items = []
            for k, v in current_data.items():
                new_key = f"{parent_key}.{k}" if parent_key else str(k)
                items.extend(flatten(v, new_key))
            return items
        else:
            return [(parent_key, current_data)]

    return flatten(data)

def update_dict(d, keys, value, cutoff=0):
    """
    Function to recursively update a nested dictionary with the parsed values.
    keys is a list of strings that represents the sequence of nested keys to be
    added to the dictionary d. The input dictionary d is updated in place.
    Value could be either a single value or a tuple with 2 elements: the time
    of the module and its global fraction.
    If a cutfoff greater than 0 is provided, the count counter is not
    incremented, to be coherent with the possible multirow output of LaTeX
    tables.
    """
    current = d
    for key in keys[:-1]:
        if key in current.keys():
            current[key]["count"] = current[key]["count"] + 1
            current = current[key]
        else:
            current = current.setdefault(key, {"count": 1})
    if isinstance(value, int):
        current[keys[-1]] = current.get(keys[-1], 0) + value
    if isinstance(value, list):
        current[keys[-1]] = current.get(keys[-1], [0, 0])
        current[keys[-1]][0] += value[0]
        current[keys[-1]][1] += value[1]
        current = d
        if cutoff > 0 and value[1] < cutoff:
            for key in keys[:-1]:
                if key in current.keys():
                    current[key]["count"] = current[key]["count"] - 1
                    current = current[key]

def compute_sum(d):
    """
    Function to compute the sum of all values at each level, including children.
    This function assumed the input dictionary d is a nested dictionary whose
    value is a list of variable elements.
    """
    total_sum = []
    if isinstance(d, list):
        return d
    for _, value in d.items():
        if isinstance(value, dict):
            # Recursively compute the sum for nested dictionaries
            sub_sum = compute_sum(value)
            for v in range(len(sub_sum)):
                if len(total_sum) <= v:
                    total_sum.append(0)
                total_sum[v] += sub_sum[v]
        elif isinstance(value, list):
            for i, v in enumerate(value):
                if len(total_sum) <= i:
                    total_sum.append(0)
                total_sum[i] += v
    return total_sum

def print_latex_table(data, used_keys, metric, level, cutoff, debug=False):
    """
    Function to print a latex table with the aggregated data. The table will
    group modules using \\multirow. The numbers and fractions computed are
    cumulative and have no cutoff applied. The number of rows printed is
    limited by the command line option latexcutoff.
    """
    def recurse_print_table(data, used_keys, prepend = "", level=0, cutoff=-1):
        if debug:
            print(f"LEVEL {level}\tUSED_KEYS: {used_keys}\tPREPEND: {prepend}\tDATA: {data}")
        if isinstance(data, dict):
            for i, key in enumerate([k for k in data.keys() if k != "count"]):
                total_sum = compute_sum(data[key])
                if isinstance(data[key], dict):
                    to_be_prepended = prepend + "\\multirow[t]{{{}}}{{*}}{{{} [{:.1f}\\%] }} & ".format(data[key]["count"], key, total_sum[1])
                    recurse_print_table(data[key], used_keys, to_be_prepended, level+1, cutoff)
                else:
                    if debug:
                        print(f"{level} {i} {prepend} {key} & {data[key]} \\\\")
                    if data[key][1] < cutoff:
                        continue
                    row = f"{prepend} {key} & {data[key][0]:.1f} & {data[key][1]:.1f}\\% \\\\"
                    if i == 0:
                        cols = row.split('&')
                        if debug:
                            print(f"{row}")
                        for c in range(level):
                            real_key = f"{cols[c]}_{c}"
                            if real_key in used_keys:
                                cols[c] = " "
                            else:
                                update_dict(used_keys, [real_key], c)
                        print("&".join(cols))
                    else:
                        cols = row.split('&')
                        for c in range(level):
                            real_key = f"{cols[c]}_{c}"
                            if real_key in used_keys:
                                cols[c] = " "
                        print("&".join(cols))

        return

    used_keys = defaultdict(lambda: defaultdict(dict))
    metric_label = ""
    if metric.startswith("time_"):
        metric_label = "Time"
    elif metric.startswith("mem_"):
        metric_label = "Memory"
    elif metric.startswith("hs23_"):
        metric_label = "\\unit{\\HS/\\hertz}"
    cols = ""
    header = ""
    for _ in range(level+2):
        cols += "l"
    print(f"\\begin{{table}}[!htbp]")
    print("\\resizebox{\\textwidth}{!}{%")
    print(f"\\begin{{tabular}}{{{cols}}}")
    print(r"\toprule")
    for _ in range(level):
        header += "\\textbf{Module} & "
    header += f"{metric_label} & \\textbf{{Fraction}} \\\\"
    print(f"{header}")
    print(r"\midrule")
    recurse_print_table(data, used_keys, "", 0, cutoff)

    print(r"\bottomrule")
    print(r"\end{tabular}")
    print("} % close resizebox")
    print(f"\\end{{table}}")

def print_infos(args):
    """
    Dump at terminal the configuration used to run the job
    """

    vargs = vars(args)
    for key in vargs.keys():
        print(key, vargs[key])
    print()

def main():
    args = parse_arguments()

    # Load input data and group data
    # Split the comma-separated list of file names into a list
    group_data = load_json(args.group_file)
    input_data = []
    augmented_data = []
    aggregated_data = []
    flat_data = []
    limited_data = []
    file_list = args.input_files.split(',')
    if len(file_list) > 2:
        print("Only two input files are supported at the moment.")
        return
    for file in file_list:
        input_data.append(load_json(file))

        augmented_data.append(augment_json(input_data[-1], group_data, args.debug))

        # Aggregate the data based on the provided level and group_by_keys
        aggregated_data.append(aggregate_data(augmented_data[-1], args.metric, args.level, args.filter, args.dropfirst))

        # Flatten the aggregated data
        flat_data.append(flatten_dict(aggregated_data[-1]))

        # Sort the data based on the second element of the tuple
        flat_data[-1].sort(key=lambda x: x[1], reverse=(args.sort == 'd'))

        # Limit the output
        limited_data.append(flat_data[-1][:args.limit])

    print_infos(args)

    if len(input_data) == 1:
        hierarchical_data = None
        if args.aggregate:
            # Create a nested dictionary to hold the aggregated data
            hierarchical_data = defaultdict(lambda: defaultdict(dict))
        for i in range(len(input_data)):
            everything_else = 100
            print(f"\n {i} " + file_list[i])
            for key, value in limited_data[i]:
                norm_value = value *input_data[i]['total']['events'] / input_data[i]['total'][args.metric] * 100.
                if args.cutoff != -1 and norm_value < args.cutoff:
                    break
                everything_else -= norm_value
                if args.markdown:
                    markdown_key = key.replace('|',' - ')
                    print(f"| {markdown_key} | {value:.2f} | {norm_value:.2f}% |")
                elif args.latex:
                    if args.aggregate:
                        # Update the nested dictionary with the parsed value
                        # Since this implies an aggregation of the data, this
                        # operation happens in 2 steps. In this first pass we
                        # collect all inputs. In a later iteration, we will
                        # print the final aggregated data.
                        if args.debug:
                            pprint(f"Adding key: {key} with value {value} and norm_value {norm_value}")
                        update_dict(hierarchical_data, key.split('|'), [value, norm_value], args.latexcutoff)
                        if args.debug:
                            pprint(hierarchical_data)
                    else:
                        latex_key = key.replace('|',' - ')
                        print(f"{latex_key} & {value:.1f} & {norm_value:.1f}\% \\tabularnewline")
                else:
                    print(f"{key}: {value:.1f} {norm_value:.1f}%")
            print(f"Everything else: {everything_else:.1f}%")
            if args.aggregate:
                if args.debug:
                    print(f"LIMITED_DATA: {limited_data}")
                print_latex_table(hierarchical_data, dict(), args.metric, args.level, args.latexcutoff)

    if len(input_data) != 2:
        return
    # Print common keys first.
    # Loop on the first file and print exclusive keys.
    # Loop on the second file and print exclusive keys.
    #
    # Find common keys
    # Create a console that forces terminal
    console = Console(force_terminal=True)
    print("\nCOMPARISONS\n")
    for i,f in enumerate(file_list):
        console.print(f"[bold red]{i}[/] [bold yellow]{f}[/]")
    common_keys = dict(limited_data[0]).keys() & dict(limited_data[1]).keys()
    sorted_common_keys = sorted(common_keys, key=lambda k: dict(flat_data[0])[k], reverse=(args.sort == 'd'))
    for key in sorted_common_keys:
        value = dict(limited_data[0])[key]
        norm_value = value * input_data[0]['total']['events'] / input_data[0]['total'][args.metric] * 100.
        value2 = dict(limited_data[1])[key]
        norm_value2 = value2 * input_data[1]['total']['events'] / input_data[1]['total'][args.metric] * 100.
        if args.cutoff != -1 and (norm_value < args.cutoff or norm_value2 < args.cutoff):
            break
        if args.markdown:
            markdown_key = key.replace('|',' - ')
            if args.dropfirst > 0:
                markdown_key = ' - '.join(markdown_key.split(' - ')[args.dropfirst:])
            print(f"| {markdown_key} | {value:.2f} | {norm_value:.2f}% | {value2:.2f} | {norm_value2:.2f}% |")
        elif args.latex:
            latex_key = key.replace('|',' - ')
            if args.dropfirst > 0:
                latex_key = ' - '.join(latex_key.split(' - ')[args.dropfirst:])
            print(f"{latex_key} & {value:.2f} & {norm_value:.2f}\% & {value2:.2f} & {norm_value2:.2f}\% \\tabularnewline")
        else:
            alert = False
            if norm_value2 != 0:
                alert = abs(norm_value-norm_value2)/norm_value2 > args.alert
            color = "bold red" if alert else "green"
            console.print(f"[orange]{key}[/]\t{value:.2f}\t[{color}]{norm_value:.2f}%[/]\t{value2:.2f}\t[{color}]{norm_value2:.2f}%[/]")
    for i in range(len(input_data)):
        for key, value in limited_data[i]:
            if key in common_keys:
                continue
            norm_value = value *input_data[i]['total']['events'] / input_data[i]['total'][args.metric] * 100.
            if args.cutoff != -1 and norm_value < args.cutoff:
                break
            if args.markdown:
                markdown_key = key.replace('|',' - ')
                if args.dropfirst > 0:
                    markdown_key = ' - '.join(markdown_key.split(' - ')[args.dropfirst:])
                print(f"| {i} | {markdown_key} | {value:.2f} | {norm_value:.2f}% |")
            elif args.latex:
                latex_key = key.replace('|',' - ')
                if args.dropfirst > 0:
                    latex_key = ' - '.join(latex_key.split(' - ')[args.dropfirst:])
                print(f"{i} & {latex_key} & {value:.2f} & {norm_value:.2f}\% \\tabularnewline")
            else:
                print(f"{i} {key}: {value:.2f} {norm_value:.2f}%")


if __name__ == "__main__":
    main()

