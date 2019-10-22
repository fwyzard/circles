#! /usr/bin/env python

import sys
import os
import re
import argparse


def to_float(s):
  s = s.strip()
  return float(s) if s else 0.


# load the groups from a CSV file
def load_groups(groupfile):
  groups = {}
  for line in groupfile:
    line = line.strip()
    if not line:
      continue

    module, group = line.split(',')
    module = module.strip()
    group  = group.strip()
    groups[module] = group

  return groups


# load the colours from a CSV file
def load_colours(colourfile):
  colours = {}
  for line in colourfile:
    line = line.strip()
    if not line:
      continue

    group, colour = line.split(',')
    group = group.strip()
    colour  = colour.strip()
    colours[group] = colour

  return colours


# extract the C++ module type from the CMSSW configuration
def load_module_types(filename):

  # implicit module types
  types = {
    'TriggerResults' : 'TriggerResults'
  }

  with open(filename, 'r') as cfgfile:
    r = re.compile(r'process.([A-Za-z0-9_]+) *= *cms\.(untracked\.)?((Path|EndPath) *\(|(Source|EDAnalyzer|EDProducer|EDFilter|OutputModule) *\( *["' + "'" + ']([A-Za-z0-9_]+)["' + "'" + ']).*')
    for line in cfgfile:
      # strip the trailing newline and spaces, and extract the object type name and type
      m = r.match(line.strip())
      if m:
        types[m.groups()[0]] = m.groups()[3] or m.groups()[5]

  return types


# extract the measurements from the FastReport in the CMSSW log
def load_data(data, logfile):
  reading = False
  header  = False

  for line in logfile:
    # strip the trailing newline and spaces
    line = line.strip()

    # look for the FastTimerService's Job Summary
    if line == 'FastReport ---------------------------- Job Summary ----------------------------':
      reading = True
      continue

    # skip lines until the report
    if not reading:
      continue
    
    # skip lines after the report
    if line == '':
      break

    # the input should look like
    """
FastReport   CPU time avg.      when run  Real time avg.      when run     Alloc. avg.      when run   Dealloc. avg.      when run  Modules
FastReport         4.2 ms         4.2 ms         4.2 ms         4.2 ms       +3065 kB       +3065 kB       -1532 kB       -1532 kB  source
FastReport       431.4 ms                      433.4 ms                     +98376 kB                     -80402 kB                 process TIME
FastReport         0.0 ms         0.0 ms         0.0 ms         0.0 ms          +0 kB          +0 kB          +0 kB          +0 kB    hltTriggerType
FastReport         0.6 ms         0.6 ms         0.7 ms         0.7 ms        +208 kB        +208 kB        -169 kB        -169 kB    hltGtStage2Digis
FastReport         2.5 ms         2.5 ms         2.5 ms         2.5 ms        +539 kB        +539 kB        -407 kB        -407 kB    hltGtStage2ObjectMap
FastReport         0.0 ms         0.0 ms         0.0 ms         0.0 ms          +5 kB          +5 kB          -2 kB          -2 kB    hltScalersRawToDigi
...
FastReport         0.0 ms         0.0 ms         0.0 ms         0.0 ms          +0 kB          +0 kB          +0 kB          +0 kB    PhysicsHLTPhysics2Output
FastReport         0.0 ms         0.0 ms         0.0 ms         0.0 ms          +0 kB          +0 kB          +0 kB          +0 kB    ParkingBPH4Output
FastReport         0.0 ms         0.0 ms         0.0 ms         0.0 ms          +0 kB          +0 kB          +0 kB          +0 kB    ScoutingCaloMuonOutput
FastReport         0.0 ms         0.0 ms         0.0 ms         0.0 ms          +0 kB          +0 kB          +0 kB          +0 kB    ParkingZeroBiasOutput
FastReport       431.4 ms                      433.4 ms                     +98376 kB                     -80402 kB                 total
    """

    # skip the header
    if not header:
      header = True
      continue

    assert(line[0:10] == "FastReport")
    cpu_avg  = to_float(line[10:22])     # ms, cpu time
    cpu_run  = to_float(line[25:37])     # ms, cpu time
    real_avg = to_float(line[40:52])     # ms, real time
    real_run = to_float(line[55:67])     # ms, real time
    mem_avg  = to_float(line[70:82])     # kB, allocated
    mem_run  = to_float(line[85:97])     # kB, allocated
    #        = to_float(line[100:112])   # kB, allocated
    #        = to_float(line[115:127])   # kB, allocated
    module   = line[130:].strip()     # module label

    if module in data:
      (prev_cpu_avg, prev_cpu_run, prev_real_avg, prev_real_run, prev_mem_avg, prev_mem_run) = data[module]
      cpu_avg  += prev_cpu_avg
      cpu_run  += prev_cpu_run
      real_avg += prev_real_avg
      real_run += prev_real_run
      mem_avg  += prev_mem_avg
      mem_run  += prev_mem_run

    data[module] = (cpu_avg, cpu_run, real_avg, real_run, mem_avg, mem_run)


# extract the process name
def extract_process_name(data):
  name = None
  to_be_removed = []
  for module in data:
    if module.startswith('process '):
      to_be_removed.append(module)
      if name is None:
        name = module.replace('process ', '')
      else:
        name = 'Total'

  # remove the processes from the data set
  for module in to_be_removed:
    del data[module]

  # if no process name was found, use a default value
  if name is None:
    name = 'Total'

  return name


# compare the reported total with the sum across all modules
def finalise_data(data, types, groups):
  if 'total' in data:
    other_cpu, _, other_real, _, other_mem, _ = data['total']
    del data['total']
    for module in data.values():
      other_cpu  -= module[0]
      other_real -= module[2]
      other_mem  -= module[4]
    data['other'] = (other_cpu, 0., other_real, 0., other_mem, 0.)
    types['other'] = 'other'
    groups['other'] = 'other'


def make_id(labels):
  return '.'.join(re.sub(r'[^A-Za-z0-9]*', '', label).lower() for label in labels)


def compute_average(node, n):
  total = 0.
  for (label, value) in node.items():
    # skip special labels
    if label.startswith('@'):
      continue
    if type(value) is dict:
      total += compute_average(value, n)
    else:
      node[label] /= n
      total += value / n
  node['@total'] = total
  return total


def indent(out, level):
  out.write('  ' * level)


# do not include modules and groups with a value less than or equal to the threshold
def skip_node(label, node, threshold):
  # skip special nodes
  if label.startswith('@'):
    return True

  # skip nodes below threshold
  value = node['@total'] if type(node) is dict else node
  if value <= args.threshold:
    return True

  # otherwise, keep the node
  return False


def export_node(out, label, node, colours, threshold, level = 0):
  value = node['@total'] if type(node) is dict else node
  indent(out, level)
  out.write('{ "label": "%s", ' % label)
  if label in colours:
    out.write('"color": "%s", ' % colours[label])
  if type(node) is dict:
    out.write('"weight": %0.6g, "groups": [\n' % value)
    first = True
    for next_label, next_node in node.items():
      if skip_node(next_label, next_node, threshold):
        continue
      if not first:
        out.write(',\n')
      first = False
      export_node(out, next_label, next_node, colours, threshold, level + 1)
    out.write('\n')
    indent(out, level)
    out.write(']}')
  else:
    out.write('"weight": %0.6g }' % value)

  if level == 0:
    out.write('\n')
  return True


# group the modules by C++ type and group
def build_module_hierarchy(data, types, groups):
  hierarchy = {}

  for label in data:

    if label in groups:
      group = groups[label]
    else:
      sys.stderr.write('Warning: module %s does not belog to any groups\n' % label)
      group = 'other'

    if label in types:
      cxxtype = types[label]
    else:
      sys.stderr.write('Warning: module %s does not have any C++ type\n' % label)
      cxxtype = 'other'

    packages = group.split('|')
    if not packages[0] in hierarchy:
      hierarchy[packages[0]] = {}
    level = hierarchy[packages[0]]
    if len(packages) > 1:
      if not packages[1] in hierarchy[packages[0]]:
        hierarchy[packages[0]][packages[1]] = {}
      level = hierarchy[packages[0]][packages[1]]
    if not cxxtype in level:
      level[cxxtype] = {}
    level[cxxtype][label] = data[label][2]    # 2 is the real time average usage

  return hierarchy


if __name__ == "__main__":
  parser = argparse.ArgumentParser(description = 'Parse the FastReport in one or more CMSSW log files, and write a JSON representation to the standard output or a file.')

  parser.add_argument('--groups', '-g', dest = 'groups', action = 'store', type = argparse.FileType('r'), default = 'groups.csv',
                      help = 'a CSV file describing the grouing of modules (default is \'groups.csv\').')

  parser.add_argument('--colours', '-C', dest = 'colours', action = 'store', type = argparse.FileType('r'), default = 'colours.csv',
                      help = 'a CSV file describing the colours used to draw each group (default is \'colours.csv\'.')

  parser.add_argument('--config', '-c', dest = 'config', action = 'store',
                      help = 'required: the CMSSW configuration file corresponding to the log file(s) being processed.')

  parser.add_argument('--output', '-o', dest = 'json', action = 'store', nargs = '?', type = argparse.FileType('w'), default = sys.stdout,
                      help = 'write the output to the JSON file instead of standard output (default).')

  parser.add_argument('--threshold', '-t', dest = 'threshold', action = 'store', type = float, default = 0.001,
                      help = 'ignore modules using less than THRESHOLD ms (default is 1 us).')

  parser.add_argument('files', metavar = 'FILE', nargs = '+', type = argparse.FileType('r'),
                      help = 'the cmsRun log file(s) to be processed.')

  args = parser.parse_args()

  types = load_module_types(args.config)
  groups = load_groups(args.groups)
  colours = load_colours(args.colours)

  data = {}
  for arg in args.files:
    load_data(data, arg)

  # this also removes the 'process ...' entries form the data
  title = extract_process_name(data)

  # this removes the 'total' entry form the data, and creates an 'other' entry to account for the overhead
  finalise_data(data, types, groups)

  # build the module hierarchy into groups and types
  hierarchy = build_module_hierarchy(data, types, groups)

  # compute the average over multiple jobs, and fill the total for the groups and types 
  compute_average(hierarchy, len(args.files))

  # export the module hierarchy to JSON format
  export_node(args.json, title, hierarchy, colours, args.threshold)
