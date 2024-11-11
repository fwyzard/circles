import argparse
import json
import re
from rich.console import Console
from data_analytics import augment_json, load_json, print_infos, METRICS

ACTIONS = ['fullrun', 'remove_modules', 'remove_metric', 'scale', 'fullscale']

def parse_arguments():
    parser = argparse.ArgumentParser(
        description=
        f"""Alter JSON data based on input parameters.

        The script allows the user to perform the following actions: {ACTIONS}.
        Only one action can be performed at a time.

        The actions are:

        - fullrun: Augment the real time and CPU time of the input JSON by mimicking a 100% execution of each module. This action ignores filter, metric, and scale options.
                   This action adds 2 new metrics to the input JSON: time_real_abs and time_thread_abs.

        - remove_modules: Remove modules from the input JSON based on a regular expression defined by the filter option.

        - remove_metric: Remove a metric (specified via the metric option) from the input JSON based on a regular expression defined by the filter option.

        - scale: Scale a metric (specified via the metric option) from the input JSON based on a regular expression defined by the filter option. The scale factor is passed by via the scale option.

        - fullscale: Scale a metric (specified via the metric option) from the input JSON for all modules. This action ignores the filter option. The scale factor is passed by via the scale option. If the option add_metric is set to a non-empty string, that string will be added to the metric name in the output JSON and will contain the scaled version of the to-be-scaled metric. Otherwise the to-be-scaled metric will be overwritten.

        """,
        formatter_class=argparse.RawTextHelpFormatter
    )

    parser.add_argument('--input-file', required=True, help='Path to the input JSON files to be read, comma separated.')
    parser.add_argument('--group-file', required=True, help='Path to the JSON file responsible for grouping.')
    parser.add_argument('--metric', choices=METRICS, default='time_real',
                        help="""Quantity to modify. Valid values are 'mem_alloc', 'mem_free',
                                'time_real', 'time_thread', 'time_real_abs', 'time_thread_abs'.
                                Default is 'time_real'.""")
    parser.add_argument('--add-metric', type=str, default='', help='Add a new metric to the output JSON. Default is empty. It can only be used with the fullscale action.')
    parser.add_argument('--debug', action="store_true", help='Enable debugging printouts.')
    parser.add_argument('--filter', type=str, default='.*',
                        help="""Regular expression that is used to filter the output results.
                        Default is '.*'.""")
    parser.add_argument('--action', choices=ACTIONS, default='scale', help='Action to perform. Default is scale.')
    parser.add_argument('--scale', type=float, default=1., help='Scale factor to apply to the specified metric to all filtered modules. Default is 1.')
    parser.add_argument('--inplace', action="store_true", default=False, help='Overwrite the original input-file.')

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

    # Remove the metric from the resources and total if its total is close enough to 0
    for m in augmented_data['resources']:
        if metric in m and augmented_data['total'][metric] < 0.001:
            augmented_data['resources'].remove(m)
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

def fullscale_json(input_data, group_data, metric, scale, add_metric,debug):
    augmented_data = augment_json(input_data, group_data, debug)

    total_changed = {key: 0 for key in METRICS}
    for m in augmented_data['modules'][:]:  # Use a slice to create a copy:
        if metric in m:
            original_dict = {key: m[key] for key in METRICS if key in m}
            actual_metric = add_metric if add_metric != "" else metric
            m[actual_metric] = m[metric]
            m[add_metric] *= scale
            if not add_metric in augmented_data['total']:
                augmented_data['total'][add_metric] = 0
            augmented_data['total'][add_metric] += m[actual_metric]
            total_changed[metric] = m[metric]
        del m['expanded']

    if debug:
        print(f"Total changed: {total_changed}")

    # If the user added a new metric, add it to the resources
    if add_metric != "":
        augmented_data['resources'].append({add_metric: add_metric})

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
    elif args.action == 'fullscale':
        output_json = fullscale_json(input_data, group_data, args.metric, args.scale, args.add_metric, args.debug)

    output_file = args.input_file
    if not args.inplace:
        output_file = args.input_file.replace('.json', '_scaled.json')
    with open(output_file, 'w') as file:
        json.dump(output_json, file, indent=4)

if __name__ == "__main__":
    main()

