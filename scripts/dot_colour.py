#! /usr/bin/env python3

import sys
import os, os.path
from pathlib import Path
import argparse
import re
import json


def darken(value):
  r = int(round(int(value[1:3], 16) * 0.8))
  g = int(round(int(value[3:5], 16) * 0.8))
  b = int(round(int(value[5:7], 16) * 0.8))
  return '#%02x%02x%02x' % (r, g, b)

def is_dark(value):
  r = int(value[1:3], 16)
  g = int(value[3:5], 16)
  b = int(value[5:7], 16)
  y = 0.299 * r + 0.587 * g + 0.114 * b
  return y < 100

args = None
groupsmap = {}
groups = []
coloursmap = {}
colours = {}

def populate_available_groups_and_colours():
  global groupsmap, coloursmap
  basepath = Path(os.path.dirname(os.path.realpath(__file__))).parent
  groupspath = basepath / 'web' / 'groups'
  groupsmap = { f.stem: str(f) for f in groupspath.glob('**/*.json') }
  colourspath = basepath / 'web' / 'colours'
  coloursmap = { f.stem: str(f) for f in colourspath.glob('**/*.json') }

def parse_cmdline_args():
  global args
  parser = argparse.ArgumentParser()
  parser.add_argument("file", type = argparse.FileType('r'), metavar = 'FILE', default = 'dependency.dot', help = "Graphviz .dot file to colorise")
  parser.add_argument("-g", "--groups", choices = groupsmap, metavar = 'GROUP', default = 'hlt', help = "Modules' groupings: ")
  parser.add_argument("-c", "--colours", choices = coloursmap, metavar = 'COLOUR', default = 'default', help = "Colour schemes: ")
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

def parse_colours():
  global colours
  coloursfile = open(coloursmap[args.colours], 'r')
  colours = json.load(coloursfile)


def main():
  populate_available_groups_and_colours()
  parse_cmdline_args()
  parse_groups()
  parse_colours()

  # modules look something like
  #   0[color=black, fillcolor=white, label=source, shape=oval, style=filled, tooltip=PoolSource];
  pattern = re.compile(r'''([0-9]+)\[(((color=["']?(?P<color>[a-zA-Z0-9_]+)["']?)|(fillcolor=["']?(?P<fillcolor>[a-zA-Z0-9_]+)["']?)|(label=["']?(?P<label>[a-zA-Z0-9_]+)["']?)|(shape=["']?(?P<shape>[a-zA-Z0-9_]+)["']?)|(style=["']?(?P<style>[a-zA-Z0-9_]+)["']?)|(tooltip=["']?(?P<tooltip>[a-zA-Z0-9_]+)["']?))( *, *)?)+\];$''')
  for line in args.file:
    match = pattern.match(line.strip())
    if match:
      foreground = 'black'
      background = match['fillcolor']
      module = match['tooltip']
      label = match['label']
      light = True if background == 'white' else False
      for g in groups:
        if (g[0] is None or g[0].match(module)) and (g[1] is None or g[1].match(label)):
          group = g[2]
          if group in colours:
            background = colours[g[2]] if light else darken(colours[g[2]])
            foreground = 'white' if is_dark(background) else 'black'
          break
      print('%d[color="%s", fillcolor="%s", fontcolor="%s", label="%s", shape="%s", style="%s", tooltip="%s"];' % (int(match[1]), match['color'], background, foreground, match['label'], match['shape'], match['style'], match['tooltip']))
    else:
      print(line.strip())


if __name__ == "__main__":
  main()
