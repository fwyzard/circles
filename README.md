![banner](web/images/banner.png)

# Resources utilisation plot

Visualise the resources used by different modules in a CMSSW job as a
hierarchical chart.

This tool is composed of two parts:
   1. running a CMSSW job and producing a [`FastTimerService`](https://twiki.cern.ch/twiki/bin/viewauth/CMS/FastTimerService)
      output in JSON format;
   2. uploading the data to a web server for interactive visualisation and
      exploration.

## Running a CMSSW job

The instructions for running a CMSSW job with the `FastTimerService` are
available on its [twiki page](https://twiki.cern.ch/twiki/bin/viewauth/CMS/FastTimerService).

To produce a `resources.json` file suitable for uploading and visualisation,
use `CMSSW_11_1_0_pre5` or later with the options
```python
process.FastTimerService.writeJSONSummary = cms.untracked.bool(True)
process.FastTimerService.jsonFileName = cms.untracked.string('resources.json')
```

The `resources.json` file should look like
```json
{
  "modules": [
    {
      "events": 100,
      "label": "source",
      "mem_alloc": 302244,
      "mem_free": 151125,
      "time_real": 120.584236,
      "time_thread": 120.307255,
      "type": "FedRawDataInputSource"
    },
    ...
  ],
  "resources": [
    {
      "time_real": "real time"
    },
    {
      "time_thread": "cpu time"
    },
    {
      "mem_alloc": "allocated memory"
    },
    {
      "mem_free": "deallocated memory"
    }
  ],
  "total": {
    "events": 100,
    "label": "HLTGRun",
    "mem_alloc": 27584018,
    "mem_free": 27066780,
    "time_real": 59625.846558,
    "time_thread": 59310.740262,
    "type": "Job"
  }
}
```

The file is structured in three sections:

  - `resources` lists the available metrics and their brief descriptions;
  - `modules` is an array with the type, label and metrics for each module in
    the process;
  - `total` lists the information and metrics for the whole job.


## Setting up the interactive page on a web server

After cloning the repository, the directory structure should look like this:
```
scripts/
    convert.py
    merge.py
web/
    Buttons-1.5.6/
    CarrotSearch/
    DataTables-1.10.18/
    FixedColumns-3.2.5/
    FixedHeader-3.1.4/
    RowGroup-1.1.0/
    Scroller-2.0.0/
    cgi-bin/
        colourslist.py
        datalist.py
        groupslist.py
    colours/
        default.json
    data/
    groups/
        hlt_cpu.json
        hlt_gpu.json
        reco_PhaseII.json
    jQuery-3.3.1/
    common.js
    datatables.css
    datatables.js
    datatables.min.css
    datatables.min.js
    piechart.html
README.md
```

Just copy the `web/` drectory to a web server, and enable support for cgi-bin
scripts for the `cgi-bin` directory.


## Visualising the data

To make a measurement available on the web server, copy the JSON files produced
by the `FastTimerService` to the `data/` subdirectory, and refresh the web page.
The files will automatically appear in the "Datasets" drop-down box.

To look at a measurement without adding it permanently to the web server, use
the "upload a file" button: this will upload and visualise the dataset, without
adding it permanently to the web server.

The metric, grouping and colour style can be changed directly on the web page.


# Working with JSON files

While the JSON files produces by the `FastTimerService` can be visualised
directly in the web interface, under the `scripts/` directory there some python
scripts to perfrm common operations on them: merging multiple files, converting
from the old format, *etc*.

## Merging multiple JSON files

It is possible to merge multiple JSON files, for exmple to "harvest" the results
of multiple jobs running in paralle, using the `merge.py` script.

```bash
./scripts/merge.py input1.json input2.json ... > output.json
```

To be mergeable, the files must list the same metrics (*i.e.* the `resources`
section of the JSON).


## Converting old JSON files

The format of the JSON files used by the web interface has changed with respect
to what was produced by `make_circles.py`.

While the old files contained less information, it is possible to use the script
`convert.py` (found in the `scripts/` subdirectory) to convert them to the
latest format and visualise them on the web interface, and *e.g.* change the
grouping and colour scheme on fly:
```bash
./scripts/convert.py old.json > new.json
```
