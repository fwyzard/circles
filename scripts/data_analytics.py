import argparse
import json
import re
from rich.console import Console

def parse_arguments():
    parser = argparse.ArgumentParser(description="Process and aggregate JSON data based on input parameters.")

    parser.add_argument('--input-files', required=True, help='Path to the input JSON files to be read, comma separated.')
    parser.add_argument('--group-file', required=True, help='Path to the JSON file responsible for grouping.')
    parser.add_argument('--level', type=int, default=1, help='Level at which data aggregation should stop.')
    parser.add_argument('--sort', choices=['a', 'd'], default='d', help="Sort order: 'a' for ascending, 'd' for descending.")
    parser.add_argument('--metric', choices=['mem_alloc', 'mem_free', 'time_real', 'time_thread', 'time_real_abs', 'time_thread_abs'], default='time_real', help="Quantity to aggregate. Valid values are 'mem_alloc', 'mem_free', 'time_real', 'time_thread', 'time_real_abs', 'time_thread_abs'")
    parser.add_argument('--limit', type=int, default=999, help='Maximum number of items to be printed to the terminal.')
    parser.add_argument('--debug', action="store_true", help='Enable debugging printouts.')
    parser.add_argument('--markdown', action="store_true", help='Format the output as a Markdown table.')
    parser.add_argument('--latex', action="store_true", help='Format the output as a latex table.')
    parser.add_argument('--filter', type=str, default='.*', help='Regular expression that is used to filter the output results.')
    parser.add_argument('--cutoff', type=float, default=-1., help='Cutoff to be applied to the relative fraction of each selected components to be printed on the terminal.')
    parser.add_argument('--dropfirst', type=int, default=-1., help='Drop the first specified elements from the full path of the modules.')
    parser.add_argument('--alert', type=float, default=5, help='Alert threshold, in percetage, to be used to highlight differences between the two input files.')

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
    "Unassigned".
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

def aggregate_data(input_data, metric, level, filter):
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
            key = '|'.join(module['expanded'].split('|')[:level])
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
        aggregated_data.append(aggregate_data(augmented_data[-1], args.metric, args.level, args.filter))

        # Flatten the aggregated data
        flat_data.append(flatten_dict(aggregated_data[-1]))

        # Sort the data based on the second element of the tuple
        flat_data[-1].sort(key=lambda x: x[1], reverse=(args.sort == 'd'))

        # Limit the output
        limited_data.append(flat_data[-1][:args.limit])

    print_infos(args)

    if len(input_data) == 1:
        # Print the results separatly for the two input files
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
                    if args.dropfirst > 0:
                        markdown_key = ' - '.join(markdown_key.split(' - ')[args.dropfirst:])
                    print(f"| {markdown_key} | {value:.2f} | {norm_value:.2f}% |")
                elif args.latex:
                    latex_key = key.replace('|',' - ')
                    if args.dropfirst > 0:
                        latex_key = ' - '.join(latex_key.split(' - ')[args.dropfirst:])
                    print(f"{latex_key} & {value:.2f} & {norm_value:.2f}\% \\tabularnewline")
                else:
                    print(f"{key}: {value:.2f} {norm_value:.2f}%")
            print(f"Everything else: {everything_else:.2f}%")

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

