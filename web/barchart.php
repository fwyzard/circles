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

  createPackagesBarChart(top);
  lastTopGroups = top.slice();
  createPackagesSingleStackChart(top);
  updatePackagesChartTitle();
  resizeChartHeights();
  populateComparisonAddSelect();

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

  // ORDERING (primary dataset groups first)
  
  function orderSingleStackLabels(labels){
    var priority = ['pixels','tracking','vertices','unassigned'];
    var low = s=>s.toLowerCase();
    var prioritized = [];
    priority.forEach(p=>{
      var idx = labels.findIndex(l=>low(l)===p);
      if (idx>=0) prioritized.push(labels[idx]);
    });
    var rest = labels.filter(l=>!priority.includes(low(l))).sort((a,b)=>a.localeCompare(b));
    return prioritized.concat(rest);
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
    if (d === currentPrimary) return;
    if (comparisonDatasets.find(c=>c.name===d)) return;
    var opt=document.createElement('option');
    opt.value=d; opt.text=d;
    sel.add(opt);
  });
  if (sel.options.length) sel.selectedIndex=0;
}

function refreshComparisonList(){
  var list = document.getElementById('comparison_list');
  if (!list) return;
  list.innerHTML = comparisonDatasets.length ? '' : '<em>None</em>';
  comparisonDatasets.forEach(function(c){
    var div=document.createElement('div');
    div.style.display='flex';
    div.style.justifyContent='space-between';
    div.style.alignItems='center';
    div.style.gap='4px';
    div.innerHTML = '<span style="white-space:nowrap; overflow:hidden; max-width:1000px;" title="'+c.name+'">'+escapeHTML(c.name)+'</span>'
      +'<button type="button" style="padding:1px 4px;" onclick="removeComparisonDataset(\''+c.name.replace(/'/g,"\\'")+'\')">x</button>';
    list.appendChild(div);
  });
  populateComparisonAddSelect();
  // Re-render chart with updated comparisons
  if (lastTopGroups) createPackagesSingleStackChart(lastTopGroups);
}

function clearComparisons() {
  comparisonDatasets = [];
  refreshComparisonList();
  isDiffView = false;
  var button = document.querySelector("button[onclick='toggleTimingDifference()']");
  if (button) button.textContent = "Switch to diff view";
  updateRelativeDiffLabelState();
  if (lastTopGroups) {
    createPackagesBarChart(lastTopGroups);
    updatePackagesChartTitle();
  }
}

function removeComparisonDataset(name) {
  comparisonDatasets = comparisonDatasets.filter(c => c.name !== name);
  refreshComparisonList();
  isDiffView = false;
  var button = document.querySelector("button[onclick='toggleTimingDifference()']");
  if (button) button.textContent = "Switch to diff view";
  updateRelativeDiffLabelState();
  if (lastTopGroups) createPackagesSingleStackChart(lastTopGroups);
}

function addComparisonDataset(){
  var sel = document.getElementById('comparison_add_select');
  if (!sel || !sel.value) return;
  var name = sel.value;
  if (comparisonDatasets.find(c=>c.name===name)) return;
  isDiffView = false;
  var button = document.querySelector("button[onclick='toggleTimingDifference()']");
  if (button) button.textContent = "Switch to diff view";
  updateRelativeDiffLabelState();
  loadComparisonDataset(name);
}

function loadComparisonDataset(name){
  if (comparisonCache[name]){
    processComparisonRaw(name, comparisonCache[name]);
    return;
  }
  fetch(config.data_name + '/' + name + '.json')
    .then(r=>r.json())
    .then(raw=>{
      comparisonCache[name]=raw;
      processComparisonRaw(name, raw);
    })
    .catch(e=>console.error("Failed to load comparison dataset "+name,e));
}

// Compute grouped weights for comparison dataset using current grouping/metric
function processComparisonRaw(name, raw){
  if (!current.compiled) return; 
  var cacheKey = name+"|"+config.resource+"|v"+groupsPatternVersion+"|lbl"+(current.show_labels?1:0);
  if (aggregatedComparisonCache[cacheKey]){
    var cached = aggregatedComparisonCache[cacheKey];
    comparisonDatasets.push({ name:name, weights:cached.weights, total:cached.total });
    refreshComparisonList();
    return;
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
  aggregatedComparisonCache[cacheKey] = { weights:weights, total:total };
  cachedComparisonTrees[cacheKey] = localRoot;
  comparisonDatasets.push({ name:name, weights:weights, total:total });
  refreshComparisonList();
}

// Clear comparisons when primary context changes
function invalidateComparisons(){
  clearComparisons();
  populateComparisonAddSelect();
}

// Hook into existing update triggers
var _origUpdateMetrics = updateMetrics;
updateMetrics = function(){ _origUpdateMetrics(); invalidateComparisons(); };

var _origUpdateGroups = updateGroups;
updateGroups = function(){ _origUpdateGroups(); invalidateComparisons(); };

var _origUpdateColours = updateColours;
updateColours = function(){ _origUpdateColours(); invalidateComparisons(); };

var _origUpdateDataset = updateDataset;
updateDataset = function(){
  // Reset table ordering to default
  tableUserInitiatedSort = false;
  var table = $('#properties').DataTable();
  table.order([]).draw();
  _origUpdateDataset();
  invalidateComparisons();
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
  isDiffView = !isDiffView;
  var button = document.querySelector("button[onclick='toggleTimingDifference()']");
  var middleGroupToggle = document.getElementById('middle_group_toggle');
  if (isDiffView) {
    button.textContent = "Switch to stacked view";
    middleGroupToggle.style.display = "block";
    populateMiddleGroupSelect();
    plotTimingDifference();
  } else {
    button.textContent = "Switch to diff view";
    middleGroupToggle.style.display = "none";
    if (lastTopGroups) {
      createPackagesBarChart(lastTopGroups);
      createPackagesSingleStackChart(lastTopGroups);
      updatePackagesChartTitle();
    }
  }
  updateRelativeDiffLabelState();
}

// Populate the middle-level group dropdown
function populateMiddleGroupSelect() {
  var select = document.getElementById('middle_group_select');
  if (!select) return;
  while (select.options.length > 1) select.remove(1); // Keep "All packages" option
  if (!lastTopGroups) return;
  lastTopGroups.forEach(group => {
    var opt = document.createElement('option');
    opt.value = group.label;
    opt.textContent = group.label;
    select.add(opt);
  });
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
  var selectedGroup = document.getElementById('middle_group_select').value;
  var selectedGroupTitle = "All Groups";
  var selectedGroupTotalValue = 0;

  if (selectedGroup) {
    // Focus on module-level data (one layer deeper) for the selected package
    var selectedGroupData = lastTopGroups.find(g => g.label === selectedGroup);
    if (!selectedGroupData || !selectedGroupData.groups) {
      alert("Selected package has no sub-groups.");
      return;
    }
    selectedGroupData.groups.forEach(subGroup => {
      if (subGroup.groups) {
        subGroup.groups.forEach(module => {
          primaryWeights[module.label] = module.weight;
        });
      } else {
        primaryWeights[subGroup.label] = subGroup.weight;
      }
    });

    // Sort modules by weight and keep only the top 15
    var sortedModules = Object.entries(primaryWeights)
      .sort((a, b) => b[1] - a[1])
    var topModules = sortedModules.slice(0, 15);
    primaryWeights = Object.fromEntries(sortedModules);

    // Update the selected group title and total value
    selectedGroupTitle = selectedGroup;
    selectedGroupTotalValue = Object.values(primaryWeights).reduce((a, b) => a + b, 0);
    primaryWeights = Object.fromEntries(topModules);
  } else {
    // Use top-level groups without filtering
    lastTopGroups.forEach(g => {
      if (g.groups) primaryWeights[g.label] = g.weight;
    });
  }

  var labels = Object.keys(primaryWeights);
  var topDatasets = [];
  var bottomDatasets = [];

  // Update the titles of the plots dynamically based on the metric
  document.getElementById('packages_chart_title').textContent = 
    selectedGroup ? `${selectedGroupTitle} - Total ${current.title}: ${selectedGroupTotalValue.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 1 })} ${current.unit}` 
                  : "All Groups";
  document.getElementById('singleStackTitle').textContent = 
    selectedGroup ? `${selectedGroupTitle} - Total ${current.title}: ${selectedGroupTotalValue.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 1 })} ${current.unit}` 
                  : "All Groups diff";

  // Color palette from https://arxiv.org/pdf/2107.02270
  var colors = [
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
  var borderColors = [
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

  // Primary dataset
  var primaryData = labels.map(label => primaryWeights[label] || 0);
  topDatasets.push({
    label: config.dataset.split('/').pop() || "Primary",
    data: primaryData,
    backgroundColor: colors[0],
    borderColor: borderColors[0],
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
      backgroundColor: colors[(index + 1) % colors.length],
      borderColor: borderColors[(index + 1) % borderColors.length],
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
      backgroundColor: colors[(index + 1) % colors.length],
      borderColor: borderColors[(index + 1) % borderColors.length],
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