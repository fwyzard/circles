"""
find_branch.py

This script processes the JSON file containing the list of modules (each with a 'type' field),
extracts branch names from those types, and generates a JSON file mapping wildcard branch patterns
to branch names.

Usage:
    python3 find_branch.py <input_file> [-o <output_file>]

Arguments:
    input_file: Path to the input JSON file (should contain a 'modules' list with 'type' fields).
    -o, --output_file: Path to the output JSON file (default: by_branch_name.json).

The script is useful for grouping modules by branch name for visualization in circles.
"""

import argparse
import json
from data_analytics import load_json

def parse_arguments():
    parser = argparse.ArgumentParser(description='Create groups json by branchname')
    parser.add_argument('input_file', type=str, help='Input file')
    parser.add_argument('-o', '--output_file', type=str, default='by_branch_name.json', help='Output file')
    return parser.parse_args()

def get_branches(input_data):
    branches = {}
    for module in input_data['modules']:
        name = module['type']
        parts = name.split('_')
        if len(parts) < 2:
            branch_name = parts[0]
            branch_regex = branch_name + "*|"
            if branch_regex not in branches:
                branches[branch_regex] = "other"
            continue
        branch_name = parts[1]
        branch_regex = "*" + branch_name + "*|"
        if branch_regex not in branches:
            branches[branch_regex] = branch_name
    return branches

def main():
    args = parse_arguments()
    input_data = load_json(args.input_file)
    
    branches = get_branches(input_data)
    with open(args.output_file, 'w') as f:
        json.dump(branches, f, indent=2)
    
    


if __name__ == "__main__":
    main()