#! /usr/bin/env python

import sys
import json

if (len(sys.argv) > 1):
  input  = json.load(open(sys.argv[1]))
else:
  input  = json.load(sys.stdin)

output = {}

# the legacy JOSN files stored only the "real time"
output["resources"] = [ { "time_real": "real time" } ]

# convert the top level object to the "total"
#   - propagate the proces name
#   - propagate the weight to the real time (in ms)
#   - set the number of event to 1, since the legacy JSON stores the average per event
output["total"] = {
  "type": "Job",
  "label": input["label"],
  "time_real": input["weight"],
  "events": 1
}

# check if the node's children are leaves, i.e. if they do not have child nodes
def is_module_type(node):
  is_type = False
  for child in node["groups"]:
    if not "groups" in child:
      is_type = True
    else:
      if is_type:
        sys.stderr("Error: descendents of node %s are a mixture of terminals and non-terminals")
        sys.exit(1)
  return is_type

# navigate the JSON to find the outermost leaves (corresponding to the module
# labels) and their parents (corresponding to the module types)
output["modules"] = []

nodes_to_be_processed = input["groups"]
while nodes_to_be_processed:
  node = nodes_to_be_processed.pop()
  if is_module_type(node):
    # this node identifies a C++ type, with individual modules as children
    output["modules"].extend([{ "type" : node["label"], "label": child["label"], "time_real": child["weight"] } for child in node["groups"]])
  else:
    # this node is a group of subgroup
    nodes_to_be_processed.extend(node["groups"])


json.dump(output, sys.stdout, indent = 2 )
sys.stdout.write('\n')
