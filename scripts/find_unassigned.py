#! /usr/bin/env python3

import sys
import os, os.path
from pathlib import Path
import argparse
import re
import json


args = None
groupsmap = {}
groups = []

def populate_choices():
  global groupsmap
  basepath = Path(os.path.dirname(os.path.realpath(__file__))).parent
  groupspath = basepath / 'web' / 'groups'
  groupsmap = { f.stem: str(f) for f in groupspath.glob('**/*.json') }

def parse_cmdline_args():
  global args
  parser = argparse.ArgumentParser()
  parser.add_argument("file", type = argparse.FileType('r'), metavar = 'FILE', default = 'dependency.dot', help = "Graphviz .dot file to colorise")
  parser.add_argument("-g", "--groups", choices = groupsmap, metavar = 'GROUP', default = 'hlt', help = "Modules' groupings: ")
  args = parser.parse_args()

def parse_groups():
  global groups
  f = open(groupsmap[args.groups], 'r')
  d = json.load(f)
  for pattern, group in d.items():
    if '|' in pattern:
      ctype, label = pattern.split('|')
    else:
      ctype = ''
      label = pattern
    ctype = re.compile(ctype.replace('?', '.').replace('*', '.*') + '$') if ctype else None
    label = re.compile(label.replace('?', '.').replace('*', '.*') + '$') if label else None
    groups.append([ctype, label, group])

def main():
  populate_choices()
  parse_cmdline_args()
  parse_groups()

  data = json.loads(args.file.read())
  for module in data['modules']:
    for g in groups:
      if (g[0] is None or g[0].match(module['type'])) and (g[1] is None or g[1].match(module['label'])):
        break;
    else:
      print('{type}|{label}'.format(**module))


if __name__ == "__main__":
  main()
