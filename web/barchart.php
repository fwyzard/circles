<!DOCTYPE html>
<!--
Bar chart view for CMSSW resource utilisation

Server-side (PHP):
  - Sanitises ?data_name= query parameter
  - Enumerates dataset, group, and colour JSON files (same mechanism as piechart)
  - Emits JS arrays: datasets, groups, colours, plus data_name

Client-side (JavaScript):
  Core data plumbing reused from common.js (dataset loading, grouping compilation,
  colour/style loading, metric/unit inference, URL state management)
  Additional bar-specific features implemented here:

  Data preparation:
    - Same grouping rules as pie view
    - Normalises all weights by event count
    - Applies the same colour mappings to groups as the pie view

  Visualisation (Chart.js + DataTables):
    1. Top bar chart: absolute resource per group
    2. Bottom stacked bar chart: compares primary dataset to any number of
       additional datasets
    3. Alternative "diff view":
         * Top: side‑by‑side bars (primary vs each comparison) for either
           all groups or, when focused, module-level breakdown
           of a selected group (limited to top 15 for readability)
         * Bottom: absolute or relative (% of primary) differences

  Ordering and interaction:
    - DataTables drives ordering; user resorting of the summary table
      reorders both charts (respecting diff/group focus constraints)
    - Middle-level group selector appears only in diff view
    - Relative difference toggle enabled only in diff view

  Utilities:
    - PNG export for each chart (white background compositing)
    - URL state mirrored via history API for deep-linking
    - Comparison dataset status also reflected in URL for sharing
-->
<html lang="en">
<head>
  <title>CMSSW resource utilisation - Bar Charts</title>

  <meta charset="utf-8"/>

  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <!-- jQuery, DataTables, and plugins-->
  <link rel="stylesheet" type="text/css" href="DataTables/datatables.min.css"/>
  <script type="text/javascript" src="DataTables/datatables.min.js"></script>
  <script type="text/javascript" src="DataTables/plug-ins/sorting/absolute.js"></script>
  <script type="text/javascript" src="DataTables/plug-ins/sorting/any-number.js"></script>
  <script type="text/javascript" src="DataTables/plug-ins/dataRender/ellipsis.js"></script>

  <script type="text/javascript">
  <?php
    $data_name="data";
    if (isset($_GET["data_name"]) && preg_match('/^[a-z0-9_-]*$/', $_GET["data_name"])) {
      $data_name = $_GET["data_name"];
    }
    $dataset_cache = "${data_name}_dataset.js";
    if ($data_name == "data"){
      $dataset_cache = "dataset.js";
    }
    function preformat($file) {
      $file = explode('/', $file);
      unset($file[0]);
      $file = implode('/', $file);
      if (dirname($file) == '.')
        return "'" . basename($file, ".json") . "'";
      else
        return "'" . dirname($file) . "/" . basename($file, ".json") . "'";
    }
    function rglob($pattern, $flags = 0) {
      $files = glob($pattern, $flags);
      foreach (glob(dirname($pattern).'/*', GLOB_ONLYDIR) as $dir) {
        $files = array_merge($files, rglob($dir.'/'.basename($pattern), $flags));
      }
      return $files;
    }
    if (file_exists($dataset_cache)){
      print(file_get_contents($dataset_cache));
    } else {
      print("var data_name = \"$data_name\";");
      print("var datasets = [ " . join(", ", array_map("preformat", rglob($data_name.'/*.json'))) . " ];\n");
      print("var groups = [ " . join(", ", array_map("preformat", glob('groups/*.json'))) . " ];\n");
      print("var colours = [ " . join(", ", array_map("preformat", glob('colours/*.json'))) . " ];\n");
    }

    // Always emit the modification time of each grouping file so the client
    // can append it as a cache-busting query parameter and re-fetch groups/*.json
    // as soon as it changes on disk.
    print("var group_versions = { " . join(", ", array_map(function($file) {
      return "\"" . basename($file, ".json") . "\": " . (@filemtime($file) ?: 0);
    }, glob('groups/*.json'))) . " };\n");
    print("var colour_versions = { " . join(", ", array_map(function($file) {
      return "\"" . basename($file, ".json") . "\": " . (@filemtime($file) ?: 0);
    }, glob('colours/*.json'))) . " };\n");
  ?>
  </script>

  <!-- Stubs to prevent common.js embed() from busy-looping on this page -->
  <script>
  if (!window.CarrotSearchCircles){
    window.CarrotSearchCircles = function(){ this._props={}; };
    CarrotSearchCircles.prototype.set = function(k,v){
      this._props[k]=v;
      if (k==='dataObject'){ this._props.dataObject=v; if (this._props.onRolloutComplete) this._props.onRolloutComplete(); }
    };
    CarrotSearchCircles.prototype.get = function(k){
      if (k==='dataObject') return this._props.dataObject;
      if (k==='zoom') return { groups:[] };
      return this._props[k];
    };
  }
  if (!window.installResizeHandlerFor){
    window.installResizeHandlerFor = function(){};
  }
  </script>

  <style>
    html, body { height:100%; margin:0; font-family: sans-serif;}
    #layout { display:flex; height:100%; }
    #visualization { width:60%; padding:8px; overflow:auto; }
    #sidebar { width:40%; padding:8px; overflow:auto; }
    .property_label { text-align:left; }
    .property_value { text-align:right; }
    #selected { font-style:italic; }
    canvas { max-width:100%; }
    .chart-block { margin-bottom:32px; }
    #visualization {
      display:flex;
      flex-direction:column;
      overflow:hidden;
    }
    .chart-block {
      flex:1 1 0;
      margin-bottom:12px;
      display:flex;
      flex-direction:column;
      min-height:0;
      position:relative;
    }
    .chart-block h3 { margin:4px 0 4px 0; flex:0 0 auto; }
    .chart-block canvas {
      flex:1 1 0;
      height:100% !important;
    }
  </style>

  <script src="common.js"></script>
</head>
<body>
<div id="layout">
  <div id="visualization">
    <div class="chart-block">
      <h3 id="packages_chart_title">Packages (group totals)</h3>
      <canvas id="packagesBarChart" height="300"></canvas>
    </div>
    <div class="chart-block" id="singleStackBlock">
      <h3 id="singleStackTitle" style="margin:4px 0;">Packages (single stacked)</h3>
      <canvas id="packagesSingleStackChart"></canvas>
    </div>
    <div style="margin-top:12px;">
      <button type="button" onclick="downloadImage('packagesBarChart','plot.png')">Download top plot</button>
      <button type="button" onclick="downloadImage('packagesSingleStackChart','plot.png')">Download bottom plot</button>
      <button type="button" id="download_primary_btn" onclick="downloadDataset()">Download primary dataset</button>
    </div>
  </div>

  <div id="sidebar">
    <form>
      <div style="display:inline-block;">
        <b>Dataset</b>
        <select id="dataset_menu" onchange="updateDataset()">
          <option disabled selected value="">Select the data to visualise</option>
        </select>
      </div>
      <div style="display:inline-block;">
        or <b>upload</b>
        <input type="file" accept=".json" id="dataset_upload" oninput="uploadDataset(this.files)"/>
      </div>
      <div style="display:inline-block;">
        <b>Metric</b>
        <select id="metric_menu" onchange="updateMetrics()">
          <option disabled selected value="">Select a dataset first</option>
        </select>
      </div>
      <div style="display:inline-block;">
        <b>Groups</b>
        <select id="groups_menu" onchange="updateGroups()"></select>
      </div>
      <div style="display:inline-block;">
        <b>Colour style</b>
        <select id="colours_menu" onchange="updateColours()"></select>
      </div>
      <div style="display:inline-block;">
        <b>View</b>
        <select id="view_menu" onchange="switchChartView()">
          <option value="bar">Bar</option>
          <option value="pie">Pie</option>
        </select>
      </div>
    </form>
    <hr/>
    <table id="properties" style="width:100%">
    <thead>
      <tr>
        <th class="property_label">Element</th>
        <th class="property_value">Resource</th>
        <th class="property_fraction">Fraction</th>
      </tr>
    </thead>
    <tbody>
    </tbody>
    <tfoot>
      <tr id="selected">
        <td id="selected_label" class="property_label"></td>
        <td id="selected_value" class="property_value"></td>
        <td id="selected_percent" class="property_fraction"></td>
      </tr>
    </tfoot>
    </table>
    <div id="comparisonControls" style="margin-top:12px; padding:8px; border:1px solid #ccc; border-radius:4px; background:#f9f9f9;">
      <div style="margin-bottom:6px; font-weight:bold;">Compare datasets</div>
      <div style="display:flex; gap:4px; flex-wrap:wrap; align-items:center; margin-bottom:6px;">
        <select id="comparison_add_select" style="flex:1; min-width:120px;"></select>
        <button type="button" onclick="addComparisonDataset()" style="padding:4px 8px;">Add</button>
        <button type="button" onclick="clearComparisons()" style="padding:4px 8px;">Clear</button>
        <button type="button" onclick="toggleTimingDifference()" style="padding:4px 8px;">Diff view</button>
        <label id="relative_diff_label" style="display:inline-block; font-size:90%; opacity:0.3;">
          <input type="checkbox" id="relative_diff_checkbox" onchange="updateRelativeDiff()" disabled />
          Relative (%)
        </label>
      </div>
      <div id="comparison_list" style="max-height:120px; overflow:auto;"></div>
      <div id="middle_group_toggle" style="display:none; margin-top:8px;">
        <label for="middle_group_select"><b>Focus on group:</b></label>
        <select id="middle_group_select" onchange="updateMiddleGroup()">
          <option value="" selected>All groups</option>
        </select>
      </div>
    </div>
  </div>
</div>

<script>

// If common.js already initialised config/current, just ensure defaults for bar view.
if (typeof config === 'undefined') {
  // Fallback (should not happen; common.js sets this)
  config = loadConfigFromURL();
}
if (config.colours == null) config.colours = "default";
if (config.groups == null) config.groups = "hlt";
if (config.show_labels == null) config.show_labels = true;
config.threshold = 0.;
// Comparison datasets to restore from the URL (?compare=a,b,c)
// Captured once here so it survives the list refreshes that happen while the
// primary dataset loads; it is applied once the primary is ready.
var pendingURLComparisons = [];
(function(){
  var c = new URL(window.location.href).searchParams.get("compare");
  if (c) pendingURLComparisons = c.split(",").map(s=>s.trim()).filter(Boolean);
})();
// Whether to restore diff view (?diff=1) from the URL; applied once a comparison loads.
var pendingURLDiff = (function(){
  var d = new URL(window.location.href).searchParams.get("diff");
  return d === "1" || d === "true";
})();
// Focused group to restore from the URL (?focus=<group>); applied once it appears in the
// focus dropdown (which needs the relevant comparison loaded). null = no focused group.
var pendingURLFocus = new URL(window.location.href).searchParams.get("focus");

// Hook compileGroups to advance pattern version for comparison cache invalidation.
var groupsPatternVersion = 0;
if (typeof compileGroups === 'function' && !window.__barCompileWrapped){
  const __origCompile = compileGroups;
  compileGroups = function(){
    __origCompile();
    groupsPatternVersion++;
  };
  window.__barCompileWrapped = true;
}

// Bar-view specific state (kept; relies on current from common.js)
var currentMetricTotal = function(){
  if (current && current.data && typeof current.data.weight === 'number') return current.data.weight;
  return null;
}

function updatePackagesChartTitle(){
  var el = document.getElementById("packages_chart_title");
  if (!el) return;
  if (!current.metric){
    el.textContent = "Groups";
    return;
  }
  var totalNow = currentMetricTotal();
  el.textContent = "Groups - " + current.metric + (totalNow!=null
    ? ": " + totalNow.toLocaleString(undefined,{minimumFractionDigits:0,maximumFractionDigits:3}) + current.unit
    : "");
}

var packagesBarChart = null;
var packagesSingleStackChart = null;
// Globals for comparison feature
var comparisonDatasets = []; // { name, weights:{groupLabel:weight}, total }
var lastTopGroups = null;
var comparisonCache = {};
// Aggregation performance helpers
var aggregatedComparisonCache = {}; // key -> { weights, total }
var groupsPatternVersion = 0;       // increments whenever groups file recompiled
var cachedComparisonTrees = {};     // cache full grouped trees per comparison dataset
// Diff-view colour palette (https://arxiv.org/pdf/2107.02270), shared between the top
// diff plot and the comparison-list colour swatches so the colours always match. Named
// with a 'diff' prefix to avoid clobbering the global `colours` (list of colour schemes).
const diffColours = [
  'rgba(63, 144, 218, 0.6)',
  'rgba(255, 169, 14, 0.6)',
  'rgba(189, 31, 1, 0.6)',
  'rgba(148, 164, 162, 0.6)',
  'rgba(131, 45, 182, 0.6)',
  'rgba(169, 107, 89, 0.6)',
  'rgba(231, 99, 0, 0.6)',
  'rgba(185, 172, 112, 0.6)',
  'rgba(113, 117, 129, 0.6)',
  'rgba(146, 218, 221, 0.6)'
];
const diffBorderColours = [
  'rgba(63, 144, 218, 1)',
  'rgba(255, 169, 14, 1)',
  'rgba(189, 31, 1, 1)',
  'rgba(148, 164, 162, 1)',
  'rgba(131, 45, 182, 1)',
  'rgba(169, 107, 89, 1)',
  'rgba(231, 99, 0, 1)',
  'rgba(185, 172, 112, 1)',
  'rgba(113, 117, 129, 1)',
  'rgba(146, 218, 221, 1)'
];
// table -> chart ordering sync
var tableOrderSyncInstalled = false;
var tableUserInitiatedSort = false;
var orderEventCount = 0; // count order events to skip initial automatic draw

/* Utility */
function escapeHTML(t){ return t.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;"); }


/* Upload */
function uploadDataset(files){
  document.getElementById("dataset_menu").selectedIndex=0;
  config.dataset=null; config.local=true;
  var file=files[0];
  file.text().then(function(content){
    current.dataset = JSON.parse(content);
    loadAvailableMetrics();
    updateDownloadButtonLabel();
  });
}

function updateDataset(){
  var menu=document.getElementById("dataset_menu");
  var idx=menu.selectedIndex;
  config.dataset = menu.options[idx].value;
  config.local=false;
  updateDownloadButtonLabel();
  loadJsonInto(current,"dataset",config.data_name + "/" + config.dataset + ".json",loadAvailableMetrics);
}

function updatePage(){
  if (!config.resource) {
    updateDownloadButtonLabel();
    return;
  }
  var title=(config.local?"local file":config.dataset)+" - "+current.metric;
  window.history.pushState(config,title,convertConfigToURL(config));
  document.title="CMSSW resource utilisation: "+title;
  window.onpopstate=function(e){
    config=e.state; updateDataset();
  }
  // Replace inline title logic with helper (will show number later once data ready)
  updatePackagesChartTitle();
  updateDownloadButtonLabel();
  // Debounced data view update
  scheduleDataViewUpdate();
}

// Debounce utility - re-draw the page once if multiple changes happen quickly
function debounce(fn, wait){
  var t=null;
  function wrapper(){
    var ctx=this, args=arguments;
    if (t) clearTimeout(t);
    t=setTimeout(function(){ fn.apply(ctx,args); }, wait);
  }
  wrapper.cancel=function(){ if(t) clearTimeout(t); t=null; };
  return wrapper;
}
var scheduleDataViewUpdate = debounce(function(){ updateDataView(); }, 40);

function updateDataView(){
  if ((config.local==false && !config.dataset) || !config.resource) return;
  if (current.processing) return;
  if (!current.dataset || !current.colours || !current.groups || !current.compiled){
    setTimeout(updateDataView,200); return;
  }
  current.processing=true;

  current.data = {
    "label": current.dataset.total.label,
    "expected": current.dataset.total[config.resource],
    "ratio": current.dataset.total.ratio,
    "weight":0.,
    "groups":[]
  };
  for (module of current.dataset.modules){
    var g=findGroup(module);
    g.push(module.type);
    makeOrUpdateGroup(g,module);
  }
  if (unassigned.length){
    console.log("Unassigned modules"); console.table(unassigned);
  }
  normalise(current.data,current.dataset.total.events);
  for (key in current.colours){
    var grp = getGroup(key.split("|"));
    if (grp) grp.color = current.colours[key];
  }
  buildTablesAndCharts();
  current.processing=false;
}

/* Tables and charts */
function buildTablesAndCharts(){
  // Prepare DataTable (top-level groups summary)
  var table = $('#properties').DataTable();
  table.clear();
  $(".property_value span.dt-column-title").text(current.title);
  var total = current.data.weight;
  // only package groups (have sub-groups)
  var top = current.data.groups.filter(g=>g.groups); 
  top.sort((a,b)=> a.label.localeCompare(b.label));
  for (var g of top){
    var value = fixed(g.weight)+current.unit;
    var percent = fixed(g.weight/total*100.)+" %";
    table.row.add([escapeHTML(g.label), value, percent]);
  }
  $('#resource_title').text(current.title);
  $('#selected_label').text("total");
  $('#selected_value').text(fixed(total)+current.unit);
  $('#selected_percent').text("100 %");
  $('#selected').show();
  table.draw();

  lastTopGroups = top.slice();
  // Recompute the selected comparison datasets for the (possibly changed) metric /
  // grouping so they survive configuration changes, then draw either the diff view or
  // the stacked view depending on the current mode.
  recomputeComparisons();
  if (isDiffView && comparisonDatasets.length){
    // plotTimingDifference refreshes the focus dropdown (preserving the selection) itself.
    plotTimingDifference();
  } else {
    createPackagesBarChart(top);
    createPackagesSingleStackChart(top);
  }
  updatePackagesChartTitle();
  resizeChartHeights();
  populateComparisonAddSelect();
  applyComparisonsFromURL();

  // One-time handlers to sync charts with user table sorting
  if (!tableOrderSyncInstalled){
    $('#properties thead').on('click', 'th', function(){
      tableUserInitiatedSort = true; // Ensure table ordering is picked up on the first click
      $('#properties').trigger('order.dt'); // Explicitly trigger the order event
    });

    $('#properties').on('order.dt', function(){
      orderEventCount++;
      if (!lastTopGroups) return;

      var allGroupsSelected = !document.getElementById('middle_group_select')
        || document.getElementById('middle_group_select').value === '';

      // In diff view only reorder when "All groups" is selected
      if (isDiffView && !allGroupsSelected) return;

      var dt = $('#properties').DataTable();
      var orderedLabels = dt.rows({order:'applied'}).data().toArray()
        .map(r => r[0])
        .map(lbl => lbl
          .replace(/&amp;/g,'&')
          .replace(/&lt;/g,'<')
          .replace(/&gt;/g,'>')
          .replace(/<[^>]+>/g,''));

      if (!orderedLabels.length) return;

      var reordered = orderedLabels.map(l => lastTopGroups.find(g=>g.label===l)).filter(Boolean);
      if (reordered.length !== lastTopGroups.length) return;

      lastTopGroups = reordered.slice();

      if (isDiffView && allGroupsSelected){
        // Rebuild diff view charts respecting new order
        plotTimingDifference();
      } else {
        createPackagesBarChart(lastTopGroups);
        createPackagesSingleStackChart(lastTopGroups);
      }
    });

    tableOrderSyncInstalled = true;
  }
}

/* Chart construction */
function createPackagesBarChart(groups){
  if (packagesBarChart) packagesBarChart.destroy();
  var ctx = document.getElementById('packagesBarChart').getContext('2d');

  var labels = groups.map(g=>g.label);
  var data = {
    labels: labels,
    datasets: [{
      label: current.metric,
      data: groups.map(g=>g.weight),
      backgroundColor: groups.map(g=>g.color),
      borderWidth: 1
    }]
  };
  var options = {
    responsive: true,
    maintainAspectRatio: false,
    scales: {
      y: {
        beginAtZero: true,
        title: { display: true, text: current.title },
        ticks: {
          callback: function(value) { return value.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 1}) + current.unit; }
        }
      },
      x: {
        title: { display: true, text: 'Groups' }
      }
    },
    plugins: {
      // disable legend for top plot
      legend: { display: false },
      tooltip: {
        callbacks: {
          label: function(context) {
            var label = context.dataset.label || '';
            if (label) {
              label += ': ';
            }
            label += context.raw.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 1});
            if (current.unit) {
              label += ' ' + current.unit;
            }
            return label;
          }
        }
      }
    }
  };

  packagesBarChart = new Chart(ctx, {
    type: 'bar',
    data: data,
    options: options
  });
}

function createPackagesSingleStackChart(groups){
  if (!document.getElementById('packagesSingleStackChart')) return;
  if (packagesSingleStackChart) packagesSingleStackChart.destroy();
  var ctx = document.getElementById('packagesSingleStackChart').getContext('2d');

  // ORDERING
  // Alphabetical baseline, with two tweaks: groups whose name starts with a lower-case
  // letter (e.g. "event setup") sort after the upper-case-initial ones, and "Unassigned"
  // is always placed last. With comparisons: keep the groups whose value changed by
  // <= 5% (relative to every comparison) at the bottom of the stack and move those that
  // changed by more than 5% on top, so the actual differences stand out. Each part keeps
  // the alphabetical ordering above.
  var CHANGE_THRESHOLD = 0.05; // 5%
  function groupChangedBeyondThreshold(label){
    var p = primaryWeights[label] || 0;
    for (var k=0;k<comparisonDatasets.length;k++){
      var c = comparisonDatasets[k].weights[label] || 0;
      var rel = (p === 0) ? (c === 0 ? 0 : Infinity) : Math.abs(c - p) / p;
      if (rel > CHANGE_THRESHOLD) return true;
    }
    return false;
  }
  // Sort upper-case-initial groups before lower-case-initial ones, alphabetical within each.
  function compareLabels(a,b){
    var la = /^[a-z]/.test(a) ? 1 : 0;
    var lb = /^[a-z]/.test(b) ? 1 : 0;
    if (la !== lb) return la - lb;
    return a.localeCompare(b);
  }
  function orderSingleStackLabels(labels){
    // "Unassigned" is pulled out and re-appended so it is always placed last.
    var hasUnassigned = labels.indexOf("Unassigned") !== -1;
    var sorted = labels.filter(l=>l!=="Unassigned").sort(compareLabels);
    var ordered;
    if (!comparisonDatasets.length){
      ordered = sorted;
    } else {
      var changed = [], unchanged = [];
      sorted.forEach(function(l){ (groupChangedBeyondThreshold(l) ? changed : unchanged).push(l); });
      ordered = unchanged.concat(changed); // unchanged at the bottom of the stack, changed on top
    }
    if (hasUnassigned) ordered.push("Unassigned"); // always last
    return ordered;
  }

  // Build union of top-level group labels across primary + comparisons
  var primaryWeights = {};
  groups.forEach(g=>{ if (g.groups) primaryWeights[g.label]=g.weight; });
  var labelSet = new Set(Object.keys(primaryWeights));
  comparisonDatasets.forEach(cd=>{
    Object.keys(cd.weights).forEach(l=>labelSet.add(l));
  });

  // Respect user table ordering (tableUserInitiatedSort) for stacked chart.
  var orderedLabels;
  if (tableUserInitiatedSort){
    // Start with current primary group order (already matches table)
    var baseOrder = groups.map(g=>g.label);
    // Append any comparison-only labels not in primary
    labelSet.forEach(l=>{
      if (!baseOrder.includes(l)) baseOrder.push(l);
    });
    orderedLabels = baseOrder;
  } else {
    orderedLabels = orderSingleStackLabels(Array.from(labelSet));
  }

  // Prepare bar labels (one per dataset bar)
  var barLabels = [ (config.local?"local":config.dataset) ].concat(comparisonDatasets.map(c=>c.name));

  // Compute totals per bar (for percentage in tooltip)
  var barTotals = [];
  // Bar 0 (primary)
  barTotals[0] = Object.values(primaryWeights).reduce((a,b)=>a+b,0);
  comparisonDatasets.forEach((c,i)=> {
    barTotals[i+1] = Object.values(c.weights).reduce((a,b)=>a+b,0);
  });

  // Update title (primary bar total)
  (function(){
    var ttlEl = document.getElementById('singleStackTitle');
    if (ttlEl){
      var unit = (current.unit || '').trim();
      var datasetLabel = config.local ? "local file" : (config.dataset || "dataset");
      var totalPrimary = currentMetricTotal();
      ttlEl.textContent = datasetLabel + " - " + current.metric + (totalPrimary!=null
        ? ": " + totalPrimary.toLocaleString(undefined,{minimumFractionDigits:0,maximumFractionDigits:3}) + (unit ? " "+unit : "")
        : "") + (comparisonDatasets.length ? " (+"+comparisonDatasets.length+" cmp)" : "");
    }
  })();

  // Build datasets: one dataset per group label with value per bar
  var datasets = orderedLabels.map(label=>{
    // pick color from primary group if available
    var gPrimary = groups.find(g=>g.label===label);
    var baseColor = gPrimary && gPrimary.color ? gPrimary.color : '#999';
    // contrast check
    var cLow = baseColor.toLowerCase();
    var needsContrast = ['#fff','#ffffff','white','rgb(255,255,255)'].includes(cLow);
    var dataArr = [];
    // primary
    dataArr.push(primaryWeights[label] || 0);
    // comparisons
    comparisonDatasets.forEach(cd=>{
      dataArr.push(cd.weights[label] || 0);
    });
    return {
      label: label,
      data: dataArr,
      backgroundColor: baseColor,
      borderColor: needsContrast ? '#000000' : baseColor,
      borderWidth: 1,
      stack: 'one'
    };
  });

  packagesSingleStackChart = new Chart(ctx,{
    type:'bar',
    data:{ labels:barLabels, datasets:datasets },
    options:{
      responsive:true,
      maintainAspectRatio:false,
      scales:{
        x:{ stacked:true },
        y:{
          stacked:true,
          beginAtZero:true,
          title:{ display:true, text: current.title },
          ticks:{ callback:v=>v.toLocaleString(undefined,{maximumFractionDigits:1})+current.unit }
        }
      },
      plugins:{
        tooltip:{
          callbacks:{
            title:function(items){
              if (!items.length) return '';
              var idx = items[0].dataIndex;
              var barTotal = barTotals[idx];
              return [
                barLabels[idx],
                'Total: ' + barTotal.toLocaleString(undefined,{minimumFractionDigits:0,maximumFractionDigits:3}) + current.unit
              ];
            },
            label:function(c){
              var val = c.raw;
              var barIdx = c.dataIndex;
              var pctBar = barTotals[barIdx]>0 ? (val/barTotals[barIdx]*100).toFixed(1)+'%' : '0%';
              return c.dataset.label+": "+val.toLocaleString(undefined,{maximumFractionDigits:2})+current.unit+" ("+pctBar+")";
            }
          }
        },
        legend:{ position:'right', labels:{ boxWidth:12 } }
      }
    }
  });
}

function downloadImage(canvasId, filename){
  var canvas = document.getElementById(canvasId);
  if (!canvas) return;
  try {
    // Create a copy with white background
    var tmp = document.createElement('canvas');
    tmp.width = canvas.width;
    tmp.height = canvas.height;
    var ctx = tmp.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0,0,tmp.width,tmp.height);
    ctx.drawImage(canvas,0,0);
    var url = tmp.toDataURL('image/png');
    var a = document.createElement('a');
    a.href = url;
    a.download = filename || (canvasId + '.png');
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
  } catch(e){
    console.error("Image download failed:", e);
  }
}

function updateDownloadButtonLabel(){
  var btn = document.getElementById('download_primary_btn');
  if (!btn) return;
  if (config.local) {
    btn.textContent = "Download local file";
  } else if (config.dataset) {
    btn.textContent = "Download " + config.dataset + ".json";
  } else {
    btn.textContent = "Download dataset";
  }
}

document.addEventListener("DOMContentLoaded", function initBarChartPage(){
  // Set view selector to bar on this page
  var viewSel = document.getElementById('view_menu');
  if (viewSel){ viewSel.value = 'bar'; }
  
  // Initialize DataTable with proper column configuration (matching piechart.php)
  var sortable_label = $.fn.dataTable.absoluteOrder(
    { value: "other", position: "bottom" }
  );

  if (!$.fn.DataTable.isDataTable("#properties")) {
    $("#properties").dataTable({
      "columns": [{
        "className": "property_label",
        "type": sortable_label,
        "render": $.fn.dataTable.render.ellipsis(36)
      },
      {
        "className": "property_value",
        "type": "any-number"
      },
      {
        "className": "property_fraction"
      }
      ],
      "paging": true,
      "searching": false
    });
  }
  
  // common.js already loads datasets/groups/colours; just resize and update buttons
  resizeChartHeights();
  window.addEventListener('resize', resizeChartHeights);
  updateRelativeDiffLabelState();
  updateDownloadButtonLabel();
});

function resizeChartHeights(){
  var vh = window.innerHeight;
  var padding = 72;
  var available = vh - padding;
  if (available < 400) available = 400;
  var blocks = document.querySelectorAll('#visualization .chart-block');
  if (blocks.length >= 2){
    var mainH = Math.round(available * 0.60);   // 60% for top chart
    var singleH = available - mainH - 8;        // remainder for stacked chart
    if (singleH < 180){                         // enforce minimum for lower chart
      singleH = 180;
      mainH = available - singleH - 8;
    }
    blocks[0].style.height = mainH + "px";
    blocks[1].style.height = singleH + "px";
  }
  if (packagesBarChart) packagesBarChart.resize();
  if (packagesSingleStackChart) packagesSingleStackChart.resize();
}

// Switch between bar and pie chart pages preserving state
function switchChartView(){
  var sel = document.getElementById('view_menu').value;
  var target = (sel === 'pie') ? 'piechart.php' : 'barchart.php';
  // Preserve current configuration
  var cfg = {
    data_name: config.data_name,
    dataset: config.dataset,
    resource: config.resource,
    colours: config.colours,
    groups: config.groups,
    show_labels: config.show_labels
  };
  var params=[];
  for (var k in cfg){
    if (cfg[k] != null && cfg[k] !== '') params.push(encodeURIComponent(k)+"="+encodeURIComponent(cfg[k]));
  }
  // Avoid redirect loops (already on desired page)
  var currentPage = window.location.pathname.split('/').pop();
  if ((sel==='bar' && currentPage==='barchart.php') || (sel==='pie' && currentPage==='piechart.php')) return;
  window.location.href = target + (params.length ? "?"+params.join("&") : "");
}

function populateComparisonAddSelect(){
  var sel = document.getElementById('comparison_add_select');
  if (!sel) return;
  var keep = sel.value;
  while (sel.options.length) sel.remove(0);
  var currentPrimary = config.dataset;
  datasets.forEach(function(d){
    var opt=document.createElement('option');
    opt.value=d;
    opt.text=d;
    // Show every dataset, but disable (gray out) the ones that cannot be added right
    // now: the primary dataset and any already-selected comparison datasets.
    if (d === currentPrimary){
      opt.text = d + " (primary)";
      opt.disabled = true;
    } else if (comparisonDatasets.find(c=>c.name===d)){
      opt.text = d + " (added)";
      opt.disabled = true;
    }
    sel.add(opt);
  });
  // Restore the previous selection if it is still selectable, otherwise fall back to
  // the first enabled option so a disabled/grayed entry is never left selected.
  sel.selectedIndex = -1;
  var fallback = -1;
  for (var i=0;i<sel.options.length;i++){
    if (sel.options[i].disabled) continue;
    if (fallback === -1) fallback = i;
    if (sel.options[i].value === keep){ sel.selectedIndex = i; break; }
  }
  if (sel.selectedIndex === -1) sel.selectedIndex = fallback;
}

// Diff-view colour for a dataset position (0 = primary, i+1 = comparison i), matching the
// colour assignment in plotTimingDifference.
function diffColourFor(position){
  return {
    bg: diffColours[position % diffColours.length],
    border: diffBorderColours[position % diffBorderColours.length]
  };
}
function colourSwatchHTML(position){
  var c = diffColourFor(position);
  return '<span style="display:inline-block; width:12px; height:12px; border-radius:2px;'
    + ' margin-right:6px; vertical-align:middle; flex:0 0 auto; background:'+c.bg+'; border:1px solid '+c.border+';"></span>';
}

// Build the comparison list, refresh the add-select and the shareable URL
// In diff view each row also shows the colour the top diff plot assigns to
// that dataset. Does NOT redraw the charts.

// Dataset reordering
// The list is treated as one ordered set [primary, ...comparisons]; the up/down arrows
// move an entry within it and whatever ends up first becomes the primary. A named primary
// can be demoted (and a comparison promoted)
// Qn uploaded local file stays fixed at the top (it has no name to re-group as a comparison).

// Names that take part in reordering, and whether the primary is among them.
function reorderableList(){
  if (config.local || !config.dataset){
    return { names: comparisonDatasets.map(function(c){ return c.name; }), hasPrimary: false };
  }
  return { names: [config.dataset].concat(comparisonDatasets.map(function(c){ return c.name; })), hasPrimary: true };
}

// Up/down arrow buttons for the row at the given index within the reorderable list.
function reorderArrowsHTML(reorderIndex, total){
  if (reorderIndex < 0 || total <= 1) return ''; // nothing to reorder (or a fixed local primary)
  var up = '<button type="button" title="Move up" style="padding:0 5px;"'
    + (reorderIndex <= 0 ? ' disabled' : '')
    + ' onclick="moveDataset('+reorderIndex+',-1)">&#9650;</button>';
  var down = '<button type="button" title="Move down" style="padding:0 5px;"'
    + (reorderIndex >= total-1 ? ' disabled' : '')
    + ' onclick="moveDataset('+reorderIndex+',1)">&#9660;</button>';
  return up + down;
}

// Remove ("x") button for a comparison row. When placeholder is true, render an invisible
// button of the same size so the arrows on the (non-removable) primary row line up with
// those on the comparison rows.
function removeButtonHTML(name, placeholder){
  if (placeholder){
    return '<button type="button" tabindex="-1" aria-hidden="true" style="padding:1px 4px; visibility:hidden;">x</button>';
  }
  return '<button type="button" title="Remove" style="padding:1px 4px;" onclick="removeComparisonDataset(\''
    + name.replace(/'/g,"\\'") + '\')">x</button>';
}

// Move the entry at reorderIndex by delta (-1 up, +1 down) and apply the new order.
function moveDataset(reorderIndex, delta){
  var rl = reorderableList();
  var names = rl.names.slice();
  var j = reorderIndex + delta;
  if (j < 0 || j >= names.length) return;
  var tmp = names[reorderIndex]; names[reorderIndex] = names[j]; names[j] = tmp;
  if (rl.hasPrimary && names[0] !== config.dataset){
    changePrimaryDataset(names[0], names.slice(1)); // a swap across the primary slot
  } else {
    reorderComparisonsTo(rl.hasPrimary ? names.slice(1) : names);
  }
}

// Reorder the comparison datasets to match the given list of names; redraws + syncs URL.
function reorderComparisonsTo(newCompNames){
  comparisonDatasets = newCompNames
    .map(function(n){ return comparisonDatasets.find(function(c){ return c.name === n; }); })
    .filter(Boolean);
  refreshComparisonList();
}

// Promote newPrimary to the primary dataset (its raw is already cached as a comparison),
// demote the current primary into the comparison set, and rebuild in the requested order.
function changePrimaryDataset(newPrimary, newCompNames){
  var oldPrimary = config.dataset;
  if (oldPrimary && current.dataset) comparisonCache[oldPrimary] = current.dataset; // keep raw for re-grouping
  config.dataset = newPrimary;
  config.local = false;
  current.dataset = comparisonCache[newPrimary] || current.dataset;
  // Reflect the new primary in the Dataset dropdown.
  var menu = document.getElementById('dataset_menu');
  if (menu){
    for (var i=0;i<menu.options.length;i++){
      if (menu.options[i].value === newPrimary){ menu.selectedIndex = i; break; }
    }
  }
  // Rebuild the comparison set in the requested order (weights for the current metric/grouping).
  comparisonDatasets = newCompNames.map(function(n){
    var raw = comparisonCache[n];
    if (!raw) return null;
    var r = computeComparisonWeights(n, raw);
    return { name:n, weights:r.weights, total:r.total };
  }).filter(Boolean);
  updateDownloadButtonLabel();
  renderComparisonList();   // list + add-select + URL (no chart redraw here)
  scheduleDataViewUpdate(); // rebuild the primary data and redraw the charts in the active view
}

function renderComparisonList(){
  var list = document.getElementById('comparison_list');
  if (!list) return;
  list.innerHTML = '';
  // Colour swatches are only meaningful in diff view, where the top plot colours each
  // dataset; the stacked view colours by group instead.
  var showSwatches = isDiffView && comparisonDatasets.length > 0;
  var rl = reorderableList();
  var total = rl.names.length;

  function rowDiv(){
    var div = document.createElement('div');
    div.style.display='flex';
    div.style.justifyContent='space-between';
    div.style.alignItems='center';
    div.style.gap='4px';
    return div;
  }

  // Always show the primary dataset first, clearly labeled and not removable, so it
  // is obvious at a glance which dataset the comparisons are being measured against.
  var primaryName = config.local ? "local file" : config.dataset;
  if (primaryName){
    var primaryIndex = rl.hasPrimary ? 0 : -1; // -1 -> not reorderable (uploaded local file)
    var pdiv = rowDiv();
    pdiv.style.opacity='0.7';
    pdiv.innerHTML = '<span style="white-space:nowrap; overflow:hidden; max-width:1000px;" title="'+escapeHTML(primaryName)+'">'
      + (showSwatches ? colourSwatchHTML(0) : '') + escapeHTML(primaryName) + ' <b>(primary)</b></span>'
      + '<span style="white-space:nowrap; flex:0 0 auto;">'
      + reorderArrowsHTML(primaryIndex, total)
      + removeButtonHTML(null, true) // invisible spacer so arrows align with the comparison rows
      + '</span>';
    list.appendChild(pdiv);
  }

  comparisonDatasets.forEach(function(c, i){
    var reorderIndex = rl.hasPrimary ? i+1 : i;
    var div = rowDiv();
    div.innerHTML = '<span style="white-space:nowrap; overflow:hidden; max-width:1000px;" title="'+c.name+'">'
      + (showSwatches ? colourSwatchHTML(i+1) : '') + escapeHTML(c.name)+'</span>'
      + '<span style="white-space:nowrap; flex:0 0 auto;">'
      + reorderArrowsHTML(reorderIndex, total)
      + removeButtonHTML(c.name, false)
      + '</span>';
    list.appendChild(div);
  });

  // Only "None" when there is genuinely nothing to show (no primary and no comparisons).
  if (!list.children.length){
    list.innerHTML = '<em>None</em>';
  }

  populateComparisonAddSelect();
  syncComparisonURL();
}

// Redraw the comparison charts in whichever view is currently active, so adding or
// removing a dataset updates the diff plot in place instead of dropping back to stacked.
function redrawComparisonView(){
  if (!lastTopGroups) return;
  if (isDiffView && comparisonDatasets.length){
    plotTimingDifference();
  } else {
    createPackagesBarChart(lastTopGroups);
    createPackagesSingleStackChart(lastTopGroups);
    updatePackagesChartTitle();
  }
}

// Leave diff view and restore the stacked-view controls (button label, focus selector,
// relative-% label).
function exitDiffView(){
  isDiffView = false;
  var button = document.querySelector("button[onclick='toggleTimingDifference()']");
  if (button) button.textContent = "Switch to diff view";
  var middleGroupToggle = document.getElementById('middle_group_toggle');
  if (middleGroupToggle) middleGroupToggle.style.display = "none";
  updateRelativeDiffLabelState();
}

// Enter diff view and set the stacked/diff toggle controls accordingly. Does not draw;
// callers redraw (e.g. via plotTimingDifference or redrawComparisonView).
function enterDiffView(){
  isDiffView = true;
  var button = document.querySelector("button[onclick='toggleTimingDifference()']");
  if (button) button.textContent = "Switch to stacked view";
  var middleGroupToggle = document.getElementById('middle_group_toggle');
  if (middleGroupToggle) middleGroupToggle.style.display = "block";
  updateRelativeDiffLabelState();
}

// Restore diff view from the URL (?diff=1) once at least one comparison has loaded
// (diff view needs something to compare against). Applied at most once.
function maybeApplyURLDiffView(){
  if (!pendingURLDiff || !comparisonDatasets.length) return;
  pendingURLDiff = false;
  if (!isDiffView) enterDiffView();
}

function refreshComparisonList(){
  renderComparisonList();
  redrawComparisonView();
}

function clearComparisons() {
  comparisonDatasets = [];
  exitDiffView(); // nothing left to compare against
  refreshComparisonList();
}

function removeComparisonDataset(name) {
  comparisonDatasets = comparisonDatasets.filter(c => c.name !== name);
  // Stay in diff view and just update it; only leave when nothing remains to compare
  // against (diff view needs at least one comparison dataset).
  if (isDiffView && !comparisonDatasets.length) exitDiffView();
  refreshComparisonList();
}

function addComparisonDataset(){
  var sel = document.getElementById('comparison_add_select');
  if (!sel || !sel.value) return;
  var name = sel.value;
  if (name === config.dataset) return;          // never compare the primary against itself
  if (comparisonDatasets.find(c=>c.name===name)) return;
  // Keep the current view (stacked or diff): once the dataset has loaded,
  // refreshComparisonList -> redrawComparisonView updates whichever one is active.
  loadComparisonDataset(name);
}

function loadComparisonDataset(name){
  if (comparisonCache[name]){
    processComparisonRaw(name, comparisonCache[name]);
    return Promise.resolve();
  }
  return fetch(config.data_name + '/' + name + '.json')
    .then(r=>r.json())
    .then(raw=>{
      comparisonCache[name]=raw;
      processComparisonRaw(name, raw);
    })
    .catch(e=>console.error("Failed to load comparison dataset "+name,e));
}

// Compute the grouped top-level weights for a comparison dataset under the current
// grouping/metric, caching the result. Returns { weights, total } 
function computeComparisonWeights(name, raw){
  var cacheKey = name+"|"+config.resource+"|v"+groupsPatternVersion+"|lbl"+(current.show_labels?1:0);
  if (aggregatedComparisonCache[cacheKey]){
    return aggregatedComparisonCache[cacheKey];
  }
  // Local aggregation to not modify current.data
  var localRoot = {
    label: raw.total.label,
    expected: raw.total[config.resource],
    ratio: raw.total.ratio,
    weight:0,
    groups:[]
  };
  var localUnassigned = [];
  // Local versions of helpers
  function localMatch(p,t){
    if (p==null) return true;
    if (p instanceof RegExp) return p.test(t);
    return p==t;
  }
  function localFindGroup(module){
    for ([t,l,gr] of current.compiled){
      if (localMatch(t,module.type) && localMatch(l,module.label)){
        return gr.split("|");
      }
    }
    localUnassigned.push({type:module.type,label:module.label});
    return ["Unassigned"];
  }
  function localMakeOrUpdate(path,module){
    var data=localRoot; data.elements=0;
    for (lbl of path){
      data.weight += module[config.resource];
      var found=false;
      for (el of data.groups){
        if (el.label==lbl){ data=el; found=true; break; }
      }
      if (!found){
        var len=data.groups.push({"label":lbl,"weight":0.,"groups":[]});
        data=data.groups[len-1];
      }
    }
    var entry={"label": (current.show_labels||module.label=="other")?module.label:"", "weight":module[config.resource],"events":module.events};
    if ("ratio" in module) entry.ratio=module.ratio;
    data.groups.push(entry);
    data.weight += module[config.resource];
  }
  for (var module of raw.modules){
    if (module[config.resource] === undefined) continue;
    var g = localFindGroup(module);
    g.push(module.type);
    localMakeOrUpdate(g,module);
  }
  (function normalise(node,events){
    node.weight /= events;
    if ("events" in node) node.events /= events;
    if (node.groups){
      for (g of node.groups) normalise(g,events);
    }
  })(localRoot, raw.total.events);

  var weights = {};
  for (var g of localRoot.groups){
    if (g.groups){
      weights[g.label]=g.weight;
    }
  }
  var total = Object.values(weights).reduce((a,b)=>a+b,0);
  var result = { weights:weights, total:total };
  aggregatedComparisonCache[cacheKey] = result;
  cachedComparisonTrees[cacheKey] = localRoot;
  return result;
}

// Add a comparison dataset entry (computing its weights) and refresh the list/charts.
function processComparisonRaw(name, raw){
  if (!current.compiled) return;
  var res = computeComparisonWeights(name, raw);
  comparisonDatasets.push({ name:name, weights:res.weights, total:res.total });
  maybeApplyURLDiffView(); // enter diff view first if the URL requested it, so the redraw is diff
  refreshComparisonList();
}

// Recompute the weights of all currently-selected comparison datasets under the
// current grouping/metric (e.g. after the user changes groups or metric). Updates
// comparisonDatasets in place without touching the list/URL/charts; the caller
// (buildTablesAndCharts) redraws afterwards.
function recomputeComparisons(){
  if (!current.compiled || !comparisonDatasets.length) return;
  comparisonDatasets = comparisonDatasets.map(function(c){
    var raw = comparisonCache[c.name];
    if (!raw) return c; // raw not cached (should not happen) -> keep previous weights
    var res = computeComparisonWeights(c.name, raw);
    return { name:c.name, weights:res.weights, total:res.total };
  });
}

// Mirror the current comparison selection and view mode (diff/stacked) into the page URL
// (without adding a history entry)
function syncComparisonURL(){
  var names = comparisonDatasets.map(c=>c.name);
  config.compare = names.length ? names.join(",") : null;
  config.diff = isDiffView ? 1 : null;
  var focusSel = document.getElementById('middle_group_select');
  config.focus = (isDiffView && focusSel && focusSel.value) ? focusSel.value : null;
  try {
    window.history.replaceState(config, document.title, convertConfigToURL(config));
  } catch(e){ /* ignore navigation errors */ }
}

// One-time restore of comparison datasets named in the URL (?compare=a,b,c), run once
// the primary dataset/metric/grouping are ready so their weights can be computed.
var comparisonsFromURLApplied = false;
function applyComparisonsFromURL(){
  if (comparisonsFromURLApplied) return;
  comparisonsFromURLApplied = true;
  // Load sequentially so the comparison datasets keep the exact order given in the URL;
  // parallel fetches can resolve out of order, which would reorder the list, the plotted
  // datasets and their assigned colours.
  var chain = Promise.resolve();
  pendingURLComparisons.forEach(function(name){
    if (name === config.dataset) return;                                          // skip the primary
    if (typeof datasets !== "undefined" && datasets.indexOf(name) === -1) return; // skip unknown datasets
    chain = chain.then(function(){
      if (comparisonDatasets.find(c=>c.name===name)) return;                      // skip duplicates
      return loadComparisonDataset(name);
    });
  });
}

// The selected comparison datasets are kept across metric / grouping / colour changes:
// their weights are recomputed for the new configuration in buildTablesAndCharts, so
// there is no need to wipe the list on those actions. Comparisons are only removed
// manually, via the per-row "x" or the "Clear" button.

var _origUpdateDataset = updateDataset;
updateDataset = function(){
  // Reset table ordering to default
  tableUserInitiatedSort = false;
  var table = $('#properties').DataTable();
  table.order([]).draw();
  _origUpdateDataset();
  // Keep existing comparisons across a primary change, but drop the new primary if it
  // is itself in the comparison list (a dataset cannot be compared against itself).
  comparisonDatasets = comparisonDatasets.filter(c => c.name !== config.dataset);
  // List only; the imminent buildTablesAndCharts redraws the charts in the active view.
  renderComparisonList();
};

var isDiffView = false;
var isRelativeDiff = false;

function updateRelativeDiffLabelState(){
  var lbl = document.getElementById('relative_diff_label');
  var cb  = document.getElementById('relative_diff_checkbox');
  if (!lbl || !cb) return;
  if (isDiffView){
    lbl.style.opacity = '1';
    lbl.style.fontWeight = '600';
    lbl.style.cursor = 'pointer';
    cb.disabled = false;
    lbl.title = 'Show absolute or relative (%) difference';
  } else {
    lbl.style.opacity = '0.2';
    lbl.style.fontWeight = 'normal';
    lbl.style.cursor = 'not-allowed';
    cb.disabled = true;
    lbl.title = 'Enable diff view to use this option';
  }
}

function toggleTimingDifference() {
  if (isDiffView) {
    exitDiffView();
    if (lastTopGroups) {
      createPackagesBarChart(lastTopGroups);
      createPackagesSingleStackChart(lastTopGroups);
      updatePackagesChartTitle();
    }
  } else {
    enterDiffView();
    plotTimingDifference();
  }
  // Refresh the list so the per-dataset colour swatches appear/disappear with diff view
  // (renderComparisonList does not touch the charts, so it won't clobber the diff plot).
  renderComparisonList();
}

// Populate the middle-level group dropdown
function populateMiddleGroupSelect() {
  var select = document.getElementById('middle_group_select');
  if (!select) return;
  var prev = select.value; // preserve the current focus across rebuilds
  while (select.options.length > 1) select.remove(1); // Keep the "All groups" option
  if (!lastTopGroups) return;

  // Primary groups first (in their current order), then groups that exist only in
  // comparison datasets (alphabetical), so comparison-only groups can be focused too.
  var seen = {};
  var labels = [];
  lastTopGroups.forEach(function(group){
    if (!seen[group.label]) { seen[group.label] = true; labels.push(group.label); }
  });
  var extra = [];
  comparisonDatasets.forEach(function(cd){
    Object.keys(cd.weights).forEach(function(l){
      if (!seen[l]) { seen[l] = true; extra.push(l); }
    });
  });
  extra.sort(function(a,b){ return a.localeCompare(b); });
  labels = labels.concat(extra);

  labels.forEach(function(label){
    var opt = document.createElement('option');
    opt.value = label;
    opt.textContent = label;
    select.add(opt);
  });

  // Restore the focus: honour a URL-requested focus as soon as the group it names is
  // available (and the user has not picked something else); otherwise keep the previous
  // selection. Fall back to "All groups" if the target no longer exists.
  var target = prev;
  if (pendingURLFocus !== null && prev === "" && labels.indexOf(pendingURLFocus) !== -1) {
    target = pendingURLFocus;
    pendingURLFocus = null; // applied once
  }
  var stillExists = Array.prototype.some.call(select.options, function(o){ return o.value === target; });
  select.value = stillExists ? target : "";
}

// Update the diff view to focus on the selected middle-level group
function updateMiddleGroup() {
  if (!isDiffView) return;
  plotTimingDifference();
}

function updateRelativeDiff() {
  var cb = document.getElementById('relative_diff_checkbox');
  isRelativeDiff = cb.checked;
  if (isDiffView) plotTimingDifference();
}

function plotTimingDifference() {
  if (comparisonDatasets.length === 0) {
    alert("Please add at least one comparison dataset to compute timing differences.");
    return;
  }

  var ctxTop = document.getElementById('packagesBarChart').getContext('2d');
  var ctxBottom = document.getElementById('packagesSingleStackChart').getContext('2d');

  if (packagesBarChart) packagesBarChart.destroy();
  if (packagesSingleStackChart) packagesSingleStackChart.destroy();

  var primaryWeights = {};
  // Keep the focus dropdown in sync with the current comparisons (preserving the current
  // selection) so comparison-only groups are focusable.
  populateMiddleGroupSelect();
  var selectedGroup = document.getElementById('middle_group_select').value;
  var selectedGroupTitle = "All Groups";
  var selectedGroupTotalValue = 0;

  if (selectedGroup) {
    // Focus on module-level data (one layer deeper) for the selected group. The group may
    // exist only in comparison datasets, in which case the primary contributes no modules.
    var primaryModuleWeights = {};
    var selectedGroupData = lastTopGroups.find(g => g.label === selectedGroup);
    if (selectedGroupData && selectedGroupData.groups) {
      selectedGroupData.groups.forEach(subGroup => {
        if (subGroup.groups) {
          subGroup.groups.forEach(module => {
            primaryModuleWeights[module.label] = module.weight;
          });
        } else {
          primaryModuleWeights[subGroup.label] = subGroup.weight;
        }
      });
    }

    // Rank modules by their largest weight across ALL configurations (primary plus every
    // comparison) and keep the top 15, so the selection reflects every dataset, including
    // modules that exist only in some of them.
    var overallModuleWeights = Object.assign({}, primaryModuleWeights);
    comparisonDatasets.forEach(function(cd){
      var cmw = getComparisonModuleWeights(cd.name, selectedGroup);
      Object.keys(cmw).forEach(function(l){
        overallModuleWeights[l] = Math.max(overallModuleWeights[l] || 0, cmw[l]);
      });
    });
    if (!Object.keys(overallModuleWeights).length) {
      alert("Selected group has no module-level breakdown.");
      return;
    }
    var topModules = Object.entries(overallModuleWeights)
      .sort((a, b) => b[1] - a[1])
      .slice(0, 15)
      .map(function(e){ return e[0]; });

    // Keep the primary's values for those modules (0 where the primary lacks one).
    primaryWeights = {};
    topModules.forEach(function(l){ primaryWeights[l] = primaryModuleWeights[l] || 0; });

    // Title shows the selected group's total in the primary dataset.
    selectedGroupTitle = selectedGroup;
    selectedGroupTotalValue = Object.values(primaryModuleWeights).reduce((a, b) => a + b, 0);
  } else {
    // Use top-level groups without filtering
    lastTopGroups.forEach(g => {
      if (g.groups) primaryWeights[g.label] = g.weight;
    });
  }

  // In the "All groups" view, show the union of the primary groups and any extra groups
  // that exist only in a comparison dataset (their primary value is 0), so groups added by
  // a comparison still appear. In the focused (module-level) view keep the primary modules.
  var labels;
  if (selectedGroup) {
    labels = Object.keys(primaryWeights);
  } else {
    var primaryKeys = Object.keys(primaryWeights);
    var seen = {};
    primaryKeys.forEach(function(l){ seen[l] = true; });
    var extraGroups = [];
    comparisonDatasets.forEach(function(cd){
      Object.keys(cd.weights).forEach(function(l){
        if (!seen[l]) { seen[l] = true; extraGroups.push(l); }
      });
    });
    extraGroups.sort(function(a,b){ return a.localeCompare(b); });
    labels = primaryKeys.concat(extraGroups);
  }
  var topDatasets = [];
  var bottomDatasets = [];

  // Update the titles of the plots dynamically based on the metric
  document.getElementById('packages_chart_title').textContent = 
    selectedGroup ? `${selectedGroupTitle} - Total ${current.title}: ${selectedGroupTotalValue.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 1 })} ${current.unit}` 
                  : "All Groups";
  document.getElementById('singleStackTitle').textContent = 
    selectedGroup ? `${selectedGroupTitle} - Total ${current.title}: ${selectedGroupTotalValue.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 1 })} ${current.unit}` 
                  : "All Groups diff";

  // Diff-view colour palette is defined once at module scope (diffColours / diffBorderColours).

  // Primary dataset
  var primaryData = labels.map(label => primaryWeights[label] || 0);
  topDatasets.push({
    label: config.dataset.split('/').pop() || "Primary",
    data: primaryData,
    backgroundColor: diffColours[0],
    borderColor: diffBorderColours[0],
    borderWidth: 1
  });

  // Comparison datasets (FIX: use module-level weights when a group is selected)
  comparisonDatasets.forEach((cd, index) => {
    var compWeights;
    if (selectedGroup) {
      compWeights = getComparisonModuleWeights(cd.name, selectedGroup);
    } else {
      compWeights = cd.weights; // top-level groups
    }
    var comparisonData = labels.map(label => compWeights[label] || 0);
    topDatasets.push({
      label: cd.name.split('/').pop(),
      data: comparisonData,
      backgroundColor: diffColours[(index + 1) % diffColours.length],
      borderColor: diffBorderColours[(index + 1) % diffBorderColours.length],
      borderWidth: 1
    });

    var differences = labels.map(label => {
      var primaryValue = primaryWeights[label] || 0;
      var comparisonValue = comparisonData[labels.indexOf(label)] || 0;
      if (isRelativeDiff) {
        if (primaryValue === 0) return 0;
        return (comparisonValue - primaryValue) / primaryValue * 100.0;
      }
      return comparisonValue - primaryValue;
    });
    bottomDatasets.push({
      label: (isRelativeDiff ? '%Diff ' : 'Diff ') + `${cd.name.split('/').pop()} - ${config.dataset.split('/').pop()}`,
      data: differences,
      backgroundColor: diffColours[(index + 1) % diffColours.length],
      borderColor: diffBorderColours[(index + 1) % diffBorderColours.length],
      borderWidth: 1
    });
  });

  // Create the top plot (side-by-side measurements)
  packagesBarChart = new Chart(ctxTop, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: topDatasets
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {
          beginAtZero: true,
          // use metric-specific title (Time / Memory)
          title: { display: true, text: current.title },
          ticks: {
            callback: function(value) {
              return value.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 1 }) + current.unit;
            }
          }
        },
        x: {
          title: { display: true, text: 'Modules' }
        }
      },
      plugins: {
        legend: { position: 'top' },
        tooltip: {
          callbacks: {
            label: function(context) {
              var label = context.dataset.label || '';
              if (label) label += ': ';
              label += context.raw.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + current.unit;
              return label;
            }
          }
        }
      }
    }
  });

  // Create the bottom plot (differences)
  packagesSingleStackChart = new Chart(ctxBottom, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: bottomDatasets
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {
          beginAtZero: true,
          // keep metric title; add (%) only for relative diff
          title: { display: true, text: isRelativeDiff ? (current.title + " (%)") : current.title },
          ticks: {
            callback: function(value) {
              if (isRelativeDiff) {
                return value.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 1 }) + '%';
              }
              return value.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 1 }) + current.unit;
            }
          }
        },
        x: {
          title: { display: true, text: 'Modules' }
        }
      },
      plugins: {
        legend: { position: 'top' },
        tooltip: {
          callbacks: {
            label: function(context) {
              var label = context.dataset.label || '';
              if (label) label += ': ';
              var val = context.raw;
              label += val.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 }) +
                       (isRelativeDiff ? ' %' : current.unit);
              return label;
            }
          }
        }
      }
    }
  });

  // Mirror the resulting view state (including the focused group) into the URL.
  syncComparisonURL();
}

// Helper to extract module-level weights for a selected top group from cached tree
function getComparisonModuleWeights(datasetName, selectedGroup){
  var cacheKey = datasetName+"|"+config.resource+"|v"+groupsPatternVersion+"|lbl"+(current.show_labels?1:0);
  var tree = cachedComparisonTrees[cacheKey];
  if (!tree) return {};
  var topNode = null;
  if (tree.groups){
    for (var g of tree.groups){
      if (g.label === selectedGroup && g.groups){
        topNode = g;
        break;
      }
    }
  }
  if (!topNode) return {};
  var weights = {};
  // Mirror primary extraction logic: one more level if present
  topNode.groups.forEach(sub=>{
    if (sub.groups){
      sub.groups.forEach(mod=>{
        weights[mod.label] = mod.weight;
      });
    } else {
      weights[sub.label] = sub.weight;
    }
  });
  return weights;
}
</script>
</body>
</html>
