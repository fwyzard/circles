import argparse
import json
import re
from rich.console import Console
from data_analytics import augment_json, load_json, print_infos

METRICS = ['mem_alloc', 'mem_free', 'time_real', 'time_thread', 'time_real_abs', 'time_thread_abs']
ACTIONS = ['fullrun', 'remove_modules', 'remove_metric', 'scale']

def parse_arguments():
    parser = argparse.ArgumentParser(description="Alter JSON data based on input parameters.")

    parser.add_argument('--input-file', required=True, help='Path to the input JSON files to be read, comma separated.')
    parser.add_argument('--group-file', required=True, help='Path to the JSON file responsible for grouping.')
    parser.add_argument('--metric', choices=METRICS, default='time_real',
                        help="""Quantity to modify. Valid values are 'mem_alloc', 'mem_free',
                                'time_real', 'time_thread', 'time_real_abs', 'time_thread_abs'.
                                Default is 'time_real'.""")
    parser.add_argument('--debug', action="store_true", help='Enable debugging printouts.')
    parser.add_argument('--filter', type=str, default='.*',
                        help="""Regular expression that is used to filter the output results.
                        Default is '.*'.""")
    parser.add_argument('--action', choices=ACTIONS, default='scale', help='Action to perform. Default is scale.')
    parser.add_argument('--scale', type=float, default=1., help='Scale factor to apply to the specified metric to all filtered modules. Default is 1.')
    parser.add_argument('--inplace', action="store_true", default=False, help='Overwrite the original input-file.')
    parser.add_argument('--fullrun', action="store_true", default=False, help="""Augment the real time and CPU time of the input JSON by mimicking a 100%% execution of each module. It has precedence over all other options.""")

    return parser.parse_args()

def fullrun_json(input_data, debug):
    time_real_abs = 0.
    time_thread_abs = 0.

    events = input_data['total']['events']
    input_data['resources'].append({'time_real_abs': 'real time abs'})
    input_data['resources'].append({'time_thread_abs': 'cpu time abs'})

    for k in input_data['modules']:
        if k['events'] > 0:
            if k['label'] != "other":
                k['time_real_abs'] = k['time_real']/k['events']*events
                k['time_thread_abs'] = k['time_thread']/k['events']*events
            else:
                k['time_real_abs'] = k['time_real']
                k['time_thread_abs'] = k['time_thread']
            time_real_abs += k['time_real_abs']
            time_thread_abs += k['time_thread_abs']
        else:
            k['time_real_abs'] = 0
            k['time_thread_abs'] = 0

    input_data['total']['time_real_abs'] = time_real_abs
    input_data['total']['time_thread_abs'] = time_thread_abs

    return input_data

def remove_modules_json(input_data, group_data, filter, debug):
    augmented_data = augment_json(input_data, group_data, debug)

    total_changed = {key: 0 for key in METRICS}
    for m in augmented_data['modules'][:]:  # Use a slice to create a copy:
        if re.search(filter, m['expanded']):
            original_dict = {key: m[key] for key in METRICS if key in m}
            if debug:
                print("Pruning ", m)
            augmented_data['modules'].remove(m)
            for k in original_dict:
                augmented_data['total'][k] -= original_dict[k]
                total_changed[k] -= original_dict[k]
        del m['expanded']

    if debug:
        print(f"Total changed: {total_changed}")

    return augmented_data

def remove_metric_json(input_data, group_data, filter, metric, debug):
    augmented_data = augment_json(input_data, group_data, debug)

    total_changed = {key: 0 for key in METRICS}
    for m in augmented_data['modules']:
        if re.search(filter, m['expanded']) and metric in m:
            original_dict = {key: m[key] for key in METRICS if key in m}
            if debug:
                print("Pruning ", m[metric])
            del m[metric]
            for k in original_dict:
                if k == metric:
                    augmented_data['total'][k] -= original_dict[k]
                    total_changed[k] -= original_dict[k]
        del m['expanded']

    # Remove the metric from the resources
    for m in augmented_data['resources']:
        if metric in m:
            augmented_data['resources'].remove(m)

    # Remove the metric from the total
    if metric in augmented_data['total']:
        del augmented_data['total'][metric]

    if debug:
        print(f"Total changed: {total_changed}")

    return augmented_data

def scale_json(input_data, group_data, filter, metric, scale, debug):
    augmented_data = augment_json(input_data, group_data, debug)

    total_changed = {key: 0 for key in METRICS}
    for m in augmented_data['modules'][:]:  # Use a slice to create a copy:
        if re.search(filter, m['expanded']):
            if metric in m:
                original_dict = {key: m[key] for key in METRICS if key in m}
                m[metric] *= scale
                augmented_data['total'][metric] += m[metric] - original_dict[metric]
                total_changed[metric] = m[metric] - original_dict[metric]
            del m['expanded']

    if debug:
        print(f"Total changed: {total_changed}")

    return augmented_data

def main():
    args = parse_arguments()

    # Load input data and group data
    group_data = load_json(args.group_file)
    input_data = load_json(args.input_file)
    output_json = None

    print_infos(args)

    if args.action == 'fullrun':
        output_json = fullrun_json(input_data, args.debug)
    elif args.action == 'remove_modules':
        output_json = remove_modules_json(input_data, group_data, args.filter, args.debug)
    elif args.action == 'remove_metric':
        output_json = remove_metric_json(input_data, group_data, args.filter, args.metric, args.debug)
    elif args.action == 'scale':
        output_json = scale_json(input_data, group_data, args.filter, args.metric, args.scale, args.debug)

    output_file = args.input_file
    if not args.inplace:
        output_file = args.input_file.replace('.json', '_scaled.json')
    with open(output_file, 'w') as file:
        json.dump(output_json, file, indent=4)

if __name__ == "__main__":
    main()

