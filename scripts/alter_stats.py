import argparse
import json
import re
from rich.console import Console
from data_analytics import augment_json, load_json

METRICS = ['mem_alloc', 'mem_free', 'time_real', 'time_thread', 'time_real_abs', 'time_thread_abs']


def parse_arguments():
    parser = argparse.ArgumentParser(description="Alter JSON data based on input parameters.")

    parser.add_argument('--input-file', required=True, help='Path to the input JSON files to be read, comma separated.')
    parser.add_argument('--group-file', required=True, help='Path to the JSON file responsible for grouping.')
    parser.add_argument('--metric', choices=METRICS, default='time_real', help="Quantity to modify. Valid values are 'mem_alloc', 'mem_free', 'time_real', 'time_thread', 'time_real_abs', 'time_thread_abs'")
    parser.add_argument('--debug', action="store_true", help='Enable debugging printouts.')
    parser.add_argument('--filter', type=str, default='.*', help='Regular expression that is used to filter the output results.')
    parser.add_argument('--scale', type=float, default=1., help='Scale factor to apply to all filtered modules.')
    parser.add_argument('--remove', action="store_true", default=False, help='Delete modules that match the filter. It has precedence over --scale.')
    parser.add_argument('--inplace', action="store_true", default=False, help='Overwrite the original input-file.')

    return parser.parse_args()

def main():
    args = parse_arguments()

    # Load input data and group data
    # Split the comma-separated list of file names into a list
    group_data = load_json(args.group_file)
    input_data = load_json(args.input_file)
    augmented_data = augment_json(input_data, group_data, args.debug)

    total_changed = {key: 0 for key in METRICS}
    for m in augmented_data['modules'][:]:  # Use a slice to create a copy:
        if re.search(args.filter, m['expanded']):
            if args.metric in m:
                original_dict = {key: m[key] for key in METRICS if key in m}
                if args.remove:
                    print("Pruning ", m)
                    augmented_data['modules'].remove(m)
                    for k in original_dict:
                        augmented_data['total'][k] -= original_dict[k]
                        total_changed[k] -= original_dict[k]
                    continue
                else:
                    m[args.metric] *= args.scale
                    augmented_data['total'][args.metric] += m[args.metric] - original_dict[args.metric]
                    total_changed[args.metric] = m[args.metric] - original_dict[args.metric]
        del m['expanded']

    print(f"Total changed: {total_changed}")

    output_file = args.input_file
    if not args.inplace:
        output_file = args.input_file.replace('.json', '_scaled.json')
    with open(output_file, 'w') as file:
        json.dump(augmented_data, file, indent=4)

if __name__ == "__main__":
    main()

