# Resources utilisation plot

Visualise the resources used by different modules in a CMSSW job as a hierarchical chart.

This tool is composed of three parts:
   1. running a CMSSW job and producing a [`FastTimerService`](https://twiki.cern.ch/twiki/bin/viewauth/CMS/FastTimerService) output
   2. running the `make_circles.py` script to produce a JSON representation of the data
   3. uploading the data to a web server for interactive visualisation and exploration

## Running a CMSSW job

The instructions for running a CMSSW job with the `FastTimerService` are available on its [twiki page](https://twiki.cern.ch/twiki/bin/viewauth/CMS/FastTimerService).

The output should contain a section like
```
FastReport ---------------------------- Job Summary ----------------------------
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

```


## Converting the output into JSON format

The `make_circles.py` requires two input files: the output of the CMSSW job, and the corresponding fully-expanded python configuration file (use `edmConfigDump` on configs created with `cmsDriver.py`), which is used to associate
the C++ type to each module.

The output is structured in a hierarchy defined by
  - the module group;
  - the module C++ type;
  - the individual module.

The module groups are defined in a .csv file that associates each individual module to a group.
For example:
```
...
hltTowerMakerForECALMF, Jets/MET
hltTowerMakerForHCAL, Jets/MET
hltTrackIter0RefsForJets4Iter1TauReg, Tracking
hltTrackVertexArbitrator, Tracking
hltTracksIter, Tracking
hltTriEG20CaloIdLV2ClusterShapeUnseededFilter, E/Gamma
hltTriEG20CaloIdLV2R9IdVLR9IdUnseededFilter, E/Gamma
hltTrimmedPixelVertices, Vertices
hltTripleMu0L3PreFiltered0, Muons
hltTripleMu0NoL1MassL3PreFiltered0, Muons
...
```

A group can also define additional hierarchies, using the `|` as a separattor.
For example:
```
...
source, FWCore|ParameterSet
BeamHaloSummary, RecoMET|METProducers
CSCHaloData, RecoMET|METProducers
CosmicMuonSeed, RecoMuon|MuonSeedGenerator
EcalHaloData, RecoMET|METProducers
filteredLayerClustersMIP, RecoHGCal|TICL
...
```

ther options allow assigning specific colours to groups, or filtering the input data to skip modules below a given threshold.
See the output of `./make_circles.py -h` for more information.


## Setting up the interactive page on a web server

After cloning the repository, the directory structure should look like this:
```
web/
    Buttons-1.5.6/
    CarrotSearch/
    DataTables-1.10.18/
    FixedColumns-3.2.5/
    FixedHeader-3.1.4/
    RowGroup-1.1.0/
    Scroller-2.0.0/
    cgi-bin/
    data/
    jQuery-3.3.1/
    common.js
    datatables.css
    datatables.js
    datatables.min.css
    datatables.min.js
    piechart.html
README.md
colours.csv
groups_cpu.csv
groups_gpu.csv
groups_recoPhaseII.csv
make_circles.py
```

Just copy the `web/` drectory to a web server, and enable support for cgi-bin scripts for the `cgi-bin` directory.


## Uploading the data to a web server

Copy the JSON files produced by `make_circles.py` to the `data/` subdirectory, and refresh the web page.
The files will automatically appear in the "Datasets" drop-down box.
Select one to visualise its content in the interactive plot.
(Alternatively, append `?dataset=x` to the URL, where `x` is the name of the dataset.)
