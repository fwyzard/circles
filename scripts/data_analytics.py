import argparse
import json
from collections import defaultdict
import re

def parse_arguments():
    parser = argparse.ArgumentParser(description="Process and aggregate JSON data based on input parameters.")

    parser.add_argument('--input-file', required=True, help='Path to the input JSON file to be read.')
    parser.add_argument('--group-file', required=True, help='Path to the JSON file responsible for grouping.')
    parser.add_argument('--level', type=int, default=1, help='Level at which data aggregation should stop.')
    parser.add_argument('--sort', choices=['a', 'd'], default='d', help="Sort order: 'a' for ascending, 'd' for descending.")
    parser.add_argument('--metric', choices=['mem_alloc', 'mem_free', 'time_real', 'time_thread', 'time_real_abs', 'time_thread_abs'], default='time_real', help="Quantity to aggregate. Valid values are 'mem_alloc', 'mem_free', 'time_real', 'time_thread', 'time_real_abs', 'time_thread_abs'")
    parser.add_argument('--limit', type=int, default=999, help='Maximum number of items to be printed to the terminal.')
    parser.add_argument('--debug', action="store_true", help='Enable debugging printouts.')
    parser.add_argument('--markdown', action="store_true", help='Format the output as a Markdown table.')
    parser.add_argument('--filter', type=str, default='.*', help='Regular expression that is used to filter the output results.')

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
    except Exception as e:
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
    input_data = load_json(args.input_file)
    group_data = load_json(args.group_file)

    augmented_data = augment_json(input_data, group_data, args.debug)

    # Aggregate the data based on the provided level and group_by_keys
    aggregated_data = aggregate_data(augmented_data, args.metric, args.level, args.filter)

    # Flatten the aggregated data
    flat_data = flatten_dict(aggregated_data)

    # Sort the data based on the second element of the tuple
    flat_data.sort(key=lambda x: x[1], reverse=(args.sort == 'd'))

    # Limit the output
    limited_data = flat_data[:args.limit]

    print_infos(args)

    # Print the results
    for key, value in limited_data:
        norm_value = value *input_data['total']['events'] / input_data['total'][args.metric] * 100.
        if not args.markdown:
            print(f"{key}: {value:.2f} {norm_value:.2f}%")
        else:
            markdown_key = key.replace('|','/')
            print(f"| {markdown_key} | {value:.2f} | {norm_value:.2f}% |")


if __name__ == "__main__":
    main()

