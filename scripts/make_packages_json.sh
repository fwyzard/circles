#! /bin/bash

# Usage:
#   ./make_packages_json.sh [-u web/groups/packages.json]
#
# Options:
#   -u FILE     Start from the content of an existing JSON group file
#
# Find all framework modules declared in the plugins, src, and interface subdirectories
# of all packages under $CMSSW_RELEASE_BASE/src, and print a JSON file suitable for the
# groups of a circles piechart.
# Optionally start from an existing JSON file, and print the resulting group file.

MACROS="DEFINE_FWK_MODULE DEFINE_FWK_INPUT_SOURCE DEFINE_FWK_VECTOR_INPUT_SOURCE DEFINE_FWK_EVENTSETUP_MODULE DEFINE_FWK_EVENTSETUP_SOURCE"
REGEX="\($(echo $MACROS | sed -e's/ \+/\\|/g')\)"

ALPAKA_MACROS="DEFINE_FWK_ALPAKA_MODULE DEFINE_FWK_EVENTSETUP_ALPAKA_MODULE"
ALPAKA_REGEX="\($(echo $ALPAKA_MACROS | sed -e's/ \+/\\|/g')\)"

UPDATE=
if [ "$1" == "-u" ] && [ -f "$2" ]; then
  UPDATE=$(realpath "$2")
  shift 2
fi

echo '{'
cd $CMSSW_RELEASE_BASE/src
{
  grep -r "^ *$REGEX" */*/plugins */*/src */*/interface | sed -e"s#\(\w\+\)/\(\w\+\)/.*: *$REGEX *( *\(.*::\)\?\([a-zA-Z0-9_<>:]\+\) *).*#  \"\5|\": \"\1|\2\",#"
  grep -r "^ *$ALPAKA_REGEX" */*/plugins/alpaka */*/src/alpaka */*/interface/alpaka | sed -e"s#\(\w\+\)/\(\w\+\)/.*: *$ALPAKA_REGEX *( *\(.*::\)\?\([a-zA-Z0-9_<>:]\+\) *).*#  \"\5@alpaka|\": \"\1|\2\",#"
  echo '  "PathStatusInserter|": "FWCore|Framework",'
  echo '  "EndPathStatusInserter|": "FWCore|Framework",'
  echo '  "TriggerResultInserter|": "FWCore|Framework",'
  [ "$UPDATE" ] && cat "$UPDATE" | grep -v [{}] | grep -v -w 'idle\|other'
} | sort -t'"' -k2,2 -s -u
echo '  "idle|idle": "idle",'
echo '  "other|other": "other|other"'
echo '}'
