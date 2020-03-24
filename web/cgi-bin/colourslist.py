#! /usr/bin/python

import sys, os, os.path, glob

print "Content-Type: text/javascript;charset=utf-8\n"

# list all JSON files
files = glob.glob('../colours/*.json')
# sort by file time
files.sort()
# remove the path and extension
names = [ os.path.splitext(os.path.basename(f))[0] for f in files ]
# convert to string, using double quotes
value = str(names).replace("'", '"')
# print the result
print 'var colours = %s;' % value
