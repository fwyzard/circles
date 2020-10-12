#! /usr/bin/python

import sys, os, os.path, glob, fnmatch

print "Content-Type: text/javascript;charset=utf-8\n"

# from https://stackoverflow.com/a/2186673/2050986
def find_files(directory, pattern):
    for root, dirs, files in os.walk(directory):
        for basename in files:
            if fnmatch.fnmatch(basename, pattern):
                filename = os.path.join(root, basename)
                yield filename


# list all JSON files
files = list(find_files('../data', '*.json'))
# sort by modification time
files.sort(key = os.path.getmtime)
# remove the path and extension
names = [ os.path.splitext(os.path.relpath(f,'../data'))[0] for f in files ]
# convert to string, using double quotes
value = str(names).replace("'", '"')
# print the result
print 'var datasets = %s;' % value
