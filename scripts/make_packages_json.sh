#! /bin/bash

MACROS="DEFINE_FWK_MODULE DEFINE_FWK_INPUT_SOURCE DEFINE_FWK_VECTOR_INPUT_SOURCE DEFINE_FWK_EVENTSETUP_MODULE"
REGEX="\($(echo $MACROS | sed -e's/ \+/\\|/g')\)"

echo '{'
cd $CMSSW_RELEASE_BASE/src
{
  grep -r "^ *$REGEX" */*/plugins */*/src */*/interface | sed -e"s#\(\w\+\)/\(\w\+\)/.*: *$REGEX *( *\(.*::\)\?\([a-zA-Z0-9_<>:]\+\) *).*#  \"\5|\": \"\1|\2\",#"
  echo '  "PathStatusInserter|": "FWCore|Framework",'
  echo '  "EndPathStatusInserter|": "FWCore|Framework",'
  echo '  "TriggerResultInserter|": "FWCore|Framework",'
} | sort -u
echo '  "idle|idle": "idle",'
echo '  "other|other": "other|other"'
echo '}'
