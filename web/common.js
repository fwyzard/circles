// Current configuration
var config = loadConfigFromURL();

// Input data to parse and visualise
var current = {
  colours: null,
  compiled: null,
  data: null,
  dataset: null,
  groups: null,
  metric: null, // description of the current mteric
  processing: false, // the new configuration is being processed
  show_labels: true,
  show_animations: true,
  title: null, // column title associated to the current metric
  unit: null // unit associated to the current metric
};

// Circles data view
var circles = null;

var unassigned = [];

var tooltip = document.getElementById("tooltip");

function fixed(value) {
  return Number.parseFloat(value).toFixed(1);
}

// return an object with the GET data from the current URL
//
// for example, if the URL is
//  http://myurl&data=value
// returns
//  { data: "value" }
function parseGetData() {
  var data = {};
  window.location.search.slice(1).split("&").forEach(function (value) {
    [key, val] = value.split("=").map(decodeURIComponent);
    data[key] = (
      (val == undefined)? null: val
    );
  });
  return data;
}

// load JSON data from the given URL, and sets the target's dataObject to it
function loadJsonInto(target, attribute, url, then) {
  console.log("Loading " + url);
  var xhttp = new XMLHttpRequest();
  xhttp.overrideMimeType("application/json");
  xhttp.open("GET", url);
  xhttp.onreadystatechange = function () {
    if (xhttp.readyState == 4) {
      target[attribute] = JSON.parse(xhttp.responseText);
      then();
    }
  };
  xhttp.send(null);
}

function groupColorDecorator(options, properties, variables) {
  // customize only the top level groups' colours
  if (properties.level > 0){
    return;
  }

  // use the group color defined in the dataset
  if ("color" in properties.group) {
    variables.groupColor = properties.group.color;
    variables.labelColor = "auto";
  }
}

function circlesVisibilityDecorator(group) {
  // hide the "other" groups
  return (group.label != "other");
}

function foamtreeVisibilityDecorator(properties, variables) {
  // hide the "other" groups
  if (properties.group.label == "other") {
    variables.groupLabelDrawn = false;
    variables.groupPolygonDrawn = false;
  }
}

// Load the configuration from the URL
function loadConfigFromURL() {
  var local_config = {
    colours: null,
    data_name: null,
    dataset: null,
    filter: null,
    groups: null,
    local: false,
    resource: null,
    show_labels: null,
    show_animations: null
  };
  var url = new URL(window.location.href);
  for (key in local_config) {
    local_config[key] = url.searchParams.get(key);
  }
  if (local_config["data_name"] == null) {
    local_config["data_name"] = data_name;
  }
  if (local_config.show_labels === null) {
    local_config.show_labels = true;
  }
  if (local_config.show_animations === null) {
    local_config.show_animations = true;
  }
  if (local_config.data_name === null) {
    local_config.data_name = "data";
  }
  local_config.threshold = 0.0;
  return local_config;
}

// Write the configuration as URL parameters
function convertConfigToURL(config) {
  var params = []
  for (key in config) {
    if (config[key] != null)
      params.push(encodeURIComponent(key) + "=" + encodeURIComponent(config[key]));
  }
  return "?" + params.join("&");
}

// Current configuration
var config = loadConfigFromURL();
if (config.colours == null)
  config.colours = "default";
if (config.groups == null)
  config.groups = "hlt";
if (config.show_labels == null)
  config.show_labels = true;
config.threshold = 0.;

// Input data to parse and visualise
var current = {
  dataset: null,
  colours: null,
  groups: null,
  show_labels: true,
  compiled: null,
  metric: null,   // description of the current mteric
  title: null,   // column title associated to the current metric
  unit: null,   // unit associated to the current metric
  data: null,
  processing: false,  // the new configuration is being processed
};

// Circles data view
var circles = null;


function escape(text) {
  return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}


function embed() {
  // We respin until the visualization container has non-zero area (there are race
  // conditions on Chrome which permit that) and the visualization class is loaded.
  var container = document.getElementById("visualization");
  if (container.clientWidth <= 0 || container.clientHeight <= 0 || !window["CarrotSearchCircles"]) {
    window.setTimeout(embed, 250);
    return;
  }

  // Create an empty CarrotSearchCircles without any data
  circles = new CarrotSearchCircles({
    id: "visualization",
    captureMouseEvents: false,
    pixelRatio: Math.min(2, window.devicePixelRatio || 1),
    visibleGroupCount: 0,
    showZeroWeightGroups: false,
    titleBar: "inscribed",
    titleBarTextColor: "#444",
    dataObject: null
  });

  installResizeHandlerFor(circles, 300);
  updateAnimations();

  circles.set("groupColorDecorator", groupColorDecorator);
  circles.set("isGroupVisible", circlesVisibilityDecorator);
  //circles.set("groupSelectionColor", "#babdb633");
  circles.set("groupSelectionColor", "#cc000022");
  circles.set("groupSelectionOutlineColor", "#cc0000aa");
  circles.set("groupSelectionOutlineWidth", 4);
  circles.set("groupSelectionOutlineStyle", "none"); // unused

  circles.set("titleBarLabelDecorator", function (attrs) {
    var table = $('#properties').DataTable();
    table.clear();
    $(".property_value span.dt-column-title").text(current.title);
    $("#selected_label").text();
    $("#selected_value").text();
    $("#selected_percent").text();
    $("#selected").hide();

    var total = circles.get("dataObject").weight;

    if (attrs.hoverGroup) {
      var group = attrs.hoverGroup;
      var value = fixed(group.weight) + current.unit;
      var percent = fixed(group.weight / total * 100.) + " %";
      table.row.add([escape(group.label), value, percent]);
      attrs.label = value;
    } else if (attrs.selectedGroups.length > 0) {
      var sum = 0.;
      for (var i = 0; i < attrs.selectedGroups.length; i++) {
        var group = attrs.selectedGroups[i];
        var value = fixed(group.weight) + current.unit;
        var percent = fixed(group.weight / total * 100.) + " %";
        sum += group.weight;
        table.row.add([escape(group.label), value, percent]);
        attrs.label = value;
      }
      if (attrs.selectedGroups.length > 1) {
        var label = "selected";
        var value = fixed(sum) + current.unit;
        var percent = fixed(sum / total * 100.) + " %";
        $('#selected_label').text(label);
        $('#selected_value').text(value);
        $('#selected_percent').text(percent);
        $('#selected').show();
        attrs.label = value;
      }
    } else {
      // Show all top level groups
      var groups = circles.get("dataObject").groups;
      for (var i = 0; i < groups.length; i++) {
        var group = groups[i];
        var value = fixed(group.weight) + current.unit;
        var percent = fixed(group.weight / total * 100.) + " %";
        table.row.add([escape(group.label), value, percent]);
      }
      var label = "total";
      var value = fixed(total) + current.unit;
      var percent = fixed(100.) + " %";
      $('#selected_label').text(label);
      $('#selected_value').text(value);
      $('#selected_percent').text(percent);
      $('#selected').show();
      attrs.label = value;
    }

    table.draw();
  });
}

// Load the available datasets
function loadAvailableDatasets() {
  var menu = document.getElementById("dataset_menu");
  while (menu.options.length > 1) {
    menu.remove(1);
  }
  menu.selectedIndex = 0;
  for (var i = 0; i < datasets.length; i += 1) {
    if ((config.filter !== null) && (!datasets[i].includes(config.filter))) {
      continue;
    }
    var entry = document.createElement("option");
    entry.text = datasets[i];
    entry.value = datasets[i];
    menu.options.add(entry);
    if (datasets[i] == config.dataset) {
      menu.selectedIndex = menu.options.length - 1;
    }
  }
  console.log(menu.selectedIndex);
  // if a dataset is selected, load it and the associated resources
  if (menu.selectedIndex !== 0) {
    updateDataset();
  }
}

// Update the selected dataset
function updateDataset() {
  // Update the configuration with the selected dataset
  var menu = document.getElementById("dataset_menu");
  var index = menu.selectedIndex;
  config.dataset = menu.options[index].value;
  config.local = false;

  // Load the selected dataset, and the associated resource metrics
  loadJsonInto(current, "dataset", config.data_name + "/" + config.dataset + ".json", loadAvailableMetrics);
}

// Upload a JSON file
function uploadDataset(files) {
  // Reset the dataset selection in the drop-down menu
  document.getElementById("dataset_menu").selectedIndex = 0;
  config.dataset = null;
  config.local = true;
  var file = files[0];
  file.text().then(function (content) {
    current.dataset = JSON.parse(content);
    loadAvailableMetrics();
  });
}

function downloadDataset() {
  var data = JSON.stringify(current.dataset, null, 2);
  var blob = new Blob([data], { type: "application/json" });
  var url = URL.createObjectURL(blob);
  var a = document.createElement("a");
  a.href = url;
  a.download = config.dataset + '.json';
  a.click();
}

function loadAvailableMetrics() {
  var menu = document.getElementById("metric_menu");

  // Clear the current resources
  while (menu.length) {
    menu.remove(0);
  }
  var resources = current.dataset.resources;

  for (var i = 0; i < resources.length; i++) {
    var entry = document.createElement("option");
    var keys = Object.keys(resources[i]);
    // if you find description title name and unit use them
    if (keys.includes("description") && keys.includes("title") && keys.includes("name") && keys.includes("unit")) {
      entry.text = resources[i].description;
      entry.value = resources[i].name;
      entry.dataset.title = resources[i].title;
      entry.dataset.unit = resources[i].unit;
      menu.add(entry);
      if (key == config.resource) {
        menu.selectedIndex = i;
      }
    } else {
      for (key in resources[i]) {
        var entry = document.createElement("option");
        entry.text = resources[i][key];
        entry.value = key;
        menu.add(entry);
        if (key == config.resource) {
          menu.selectedIndex = i;
        }
      }
    }
  }

  updateMetrics();
}


// Update the configuration with the selected metric
function updateMetrics() {
  var menu = document.getElementById("metric_menu");
  var index = menu.selectedIndex;
  config.resource = menu.options[index].value;
  current.metric = menu.options[index].text;
  if (menu.options[index].dataset.unit === undefined || menu.options[index].dataset.title === undefined) {
    if (config.resource.startsWith("hs23_")) {
      current.unit = " HS23/Hz";
      current.title = "Capacity";
    } else if (config.resource.startsWith("time_")) {
      current.unit = " ms";
      current.title = "Time";
    } else if (config.resource.startsWith("mem_")) {
      current.unit = " kB";
      current.title = "Memory";
    } else {
      current.unit = "";
      current.title = "";
    }
  } else {
    current.unit = " " + menu.options[index].dataset.unit;
    current.title = menu.options[index].dataset.title;
  }
  updatePage();
}

// Update the configuration with the selected grouping
function updateGroups() {
  var menu = document.getElementById("groups_menu");
  var index = menu.selectedIndex;
  config.groups = menu.options[index].value;

  // load the module groups, then update the page
  loadJsonInto(current, "groups", "groups/" + config.groups + ".json", compileGroups);
}

// Update the configuration with the selected colour scheme
function updateColours() {
  var menu = document.getElementById("colours_menu");
  var index = menu.selectedIndex;
  config.colours = menu.options[index].value;

  // load the colour scheme, then update the page
  loadJsonInto(current, "colours", "colours/" + config.colours + ".json", updatePage);
}

// Update the configuration with the visibility of the leaf labels
function updateShowLabels() {
  var checkbox = document.getElementById("show_labels_checkbox");
  var value = checkbox.checked;
  config.show_labels = value;
  current.show_labels = value;

  // update the page
  updatePage();
}

function updateShowAnimations() {
  var checkbox = document.getElementById("show_animations_checkbox");
  var value = checkbox.checked;
  config.show_animations = value;

  // update the animations
  updateAnimations();
  updateURL();
}


// Update the title, URL, history and visualisation based on the current
// configuration
function updateURL() {
  var title = (
    (config.local ? "local file" : config.dataset) + " - " + current.metric
  );
  window.history.pushState(config, title, convertConfigToURL(config));
  document.title = "CMSSW resource utilisation: " + title;
}

function updatePage() {
  updateURL();

  // Handle navigation of the History
  window.onpopstate = function (event) {
    config = event.state;
    updateDataset();
  }

  updateDataView();
}

// Update the animations based on the current configuration
function updateAnimations() {
  if (!config.show_animations) {
    circles.set("expandTime", 0);
    circles.set("zoomTime", 0);
    circles.set("rolloutTime", 0);
    circles.set("pullbackTime", 0);
    circles.set("updateTime", 0);
  } else {
    circles.set("expandTime", 1);
    circles.set("zoomTime", 1);
    circles.set("rolloutTime", 1);
    circles.set("pullbackTime", 1);
    circles.set("updateTime", 1);
  }
}

// Load the available groupings
function loadAvailableGroups() {
  var menu = document.getElementById("groups_menu");
  for (var i = 0; i < groups.length; i++) {
    var entry = document.createElement("option");
    entry.text = groups[i];
    entry.value = groups[i];
    menu.options.add(entry);
    if (groups[i] == config.groups) {
      menu.selectedIndex = i;
    }
  }
  updateGroups();
}


// Load the available colour scheme
function loadAvailableColours() {
  var menu = document.getElementById("colours_menu");
  for (var i = 0; i < colours.length; i++) {
    var entry = document.createElement("option");
    entry.text = colours[i];
    entry.value = colours[i];
    menu.options.add(entry);
    if (colours[i] == config.colours) {
      menu.selectedIndex = i;
    }
  }
  updateColours();
}


// Compile a pattern
//  - an empty pattern "" compiles to a null object, and matches anything
//  - a literal pattern "module" compiles to a string object
//  - a glob pattern "module*" compiles to a regex object /^module.*$/
function compilePattern(pattern) {
  // empty string, return a null object
  if (pattern == "")
    return null;

  // glob pattern, convert ot a regular expression
  if (pattern.includes("?") || pattern.includes("*")) {
    // convert glob to regex patterns
    pattern = "^" + pattern.replace(/\?/g, ".").replace(/\*/g, ".*") + "$";
    return new RegExp(pattern);
  }

  // literal string, leave unchanged
  return pattern;
}

// Match a pattern
//   - a null pattern matches any string
//   - a literal string pattern matches a full string
//   - a regex pattern matches accrding to the regex
function matchPattern(pattern, text) {
  if (pattern == null)
    return true;

  if (pattern instanceof RegExp)
    return pattern.test(text);

  return (pattern == text);
}

// Compile the group definition as a pair of patterns for the module's type and label
function compileGroups() {
  current.compiled = null;
  var compiled = [];

  for (key in current.groups) {
    // convert a glob pattern into a regular expression
    var t, l;
    if (key.includes("|")) {
      [t, l] = key.split("|")
      t = compilePattern(t);
      l = compilePattern(l);
    } else {
      // leave the type null
      t = null
      l = compilePattern(key);
    }
    compiled.push([t, l, current.groups[key]]);
  }

  // Insert the group "other"
  if (current.groups["other"] == undefined) {
    compiled.push([new RegExp(".*"), new RegExp("^other$"), "other"]);
  }

  current.compiled = compiled;
  updatePage();
}

unassigned = [];

function findGroup(module) {
  var assigned = false;
  var group = "Unassigned";
  for ([t, l, g] of current.compiled) {
    if (matchPattern(t, module.type) && matchPattern(l, module.label)) {
      assigned = true;
      group = g;
      break;
    }
  }
  if (!assigned) {
    unassigned.push({ type: module.type, label: module.label });
  }

  return group.split("|");
}

// group is an array of nested group labels,
// e.g. [ "GPU", "Pixels", "PixelTrackProducer" ]
function getGroup(group) {
  // data should always have a "groups" property of array type
  var data = current.data;
  for (label of group) {
    // check if data.groups has an element wih the given label
    var found = false;
    for (element of data.groups) {
      if (element.label == label) {
        found = true;
        data = element;
        break;
      }
    }
    if (!found) {
      return null;
    }
  }

  return data;
}

// group is an array of nested group labels,
// e.g. [ "GPU", "Pixels", "PixelTrackProducer" ]
function makeOrUpdateGroup(group, module) {
  // data should always have a "groups" property of array type
  var data = current.data;
  data.elements = 0
  for (label of group) {
    // add the module's resource to the group's
    data.weight += module[config.resource];
    // make sure that data.groups has an element wih the given label
    var found = false;
    for (element of data.groups) {
      if (element.label == label) {
        element.id = label;
        found = true;
        data = element;
        break;
      }
    }
    if (!found) {
      var len = data.groups.push({ "label": label, "weight": 0., "groups": [] })
      data = data.groups[len - 1];
    }
  }
  // add the module and its resource to the group
  var label = "";
  if (current.show_labels || module.label == "other")
    label = module.label
  var entry = { "label": label, "weight": module[config.resource], "events": module.events };
  if ("ratio" in module)
    entry.ratio = module.ratio;
  data.groups.push(entry);
  data.weight += module[config.resource];
}

function normalise(data, events) {
  data.weight /= events;
  if ("events" in data) {
    data.events /= events;
  }
  if ("groups" in data) {
    for (group of data.groups) {
      normalise(group, events);
    }
  }
}

function updateDataView() {
  // Do not draw if the configuration is incomplete
  if ((config.local == false && config.dataset == null) || !config.resource) {
    return;
  }

  // Do not draw if the configuration has been updated
  if (current.processing) {
    return;
  }

  // Respin until all configurations are available
  if (current.dataset == null || current.colours == null || current.groups == null || current.compiled == null) {
    window.setTimeout(updateDataView, 250);
    return;
  }

  current.processing = true;

  current.data = {
    "label": current.dataset.total.label,
    "expected": current.dataset.total[config.resource],
    "ratio": current.dataset.total.ratio,
    "weight": 0.,
    "groups": []
  }

  for (module of current.dataset.modules) {
    var group = findGroup(module);
    group.push(module.type);
    makeOrUpdateGroup(group, module);
  }
  if (unassigned.length) {
    console.log("Unassigned modules:");
    console.table(unassigned);
  }
  normalise(current.data, current.dataset.total.events);

  for (key in current.colours) {
    group = getGroup(key.split("|"));
    if (group != null) {
      group.color = current.colours[key];
    }
  }

  if (circles != null) {
    circles.set("onRolloutComplete", function () {
      current.processing = false;
    });

    circles.set("dataObject", current.data);

  }
}


async function getImage() {
  var canvas1 = document
    .getElementById("visualization")
    .getElementsByTagName("canvas")[0];
  var canvas2 = document
    .getElementById("visualization")
    .getElementsByTagName("canvas")[1];
  var logo = document
    .getElementById("logo")
    .getElementsByTagName("img")[0];

  var canvas = document.createElement("canvas");
  var ctx = canvas.getContext("2d");
  canvas.width = canvas1.width;
  canvas.height = canvas1.height;
  ctx.drawImage(canvas1, 0, 0);
  ctx.drawImage(canvas2, 0, 0);
  ctx.drawImage(logo, 32, 32, 72, 72); // Adjust the position and size as needed

  const image = await new Promise((res) => canvas.toBlob(res));
  if (window.showSaveFilePicker) {
    const handle = await self.showSaveFilePicker({
      suggestedName: 'piechart.png',
      types: [{
        description: 'PNG file',
        accept: {
          'image/png': ['.png'],
        },
      }],
    });
    const writable = await handle.createWritable();
    await writable.write(image);
    writable.close();
  }
  else {
    const saveImg = document.createElement("a");
    saveImg.href = URL.createObjectURL(image);
    saveImg.download = "piechart.png";
    saveImg.click();
    setTimeout(() => URL.revokeObjectURL(saveImg.href), 60000);
  }
}

$(document).ready(function () {
  console.log("Loading configuration: " + JSON.stringify(config));
  // load the colour scheme
  loadJsonInto(current, "colours", "colours/" + config.colours + ".json", function () { });

  // load the available datasets, and the resources actually available from the dataset
  loadAvailableDatasets();

  // load the available groups and colours
  loadAvailableGroups();
  loadAvailableColours();

  embed();
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
      //"info": true,
      "paging": false,
      "searching": false
    });
  }


  var tooltip = document.getElementById("tooltip");

  circles.set("onGroupHover", function (hover) {
    if (hover.group) {
      tooltip.innerHTML = escape(hover.group.label) + "<br>" + hover.group.weight.toFixed(1) + " " + current.unit;
      if ("events" in hover.group) {
        tooltip.innerHTML += "<br>" + (hover.group.events * 100.).toFixed(1) + "% events";
      }
      if ("ratio" in hover.group) {
        tooltip.innerHTML += "<br>" + (hover.group.ratio * 100.).toFixed(1) + "% ratio";
      }
      tooltip.style.visibility = "visible";
    } else {
      tooltip.innerHTML = null;
      tooltip.style.visibility = "hidden";
    }
  });

});

async function colorZoomed(){
  var table = $("#properties").DataTable();
  table.$("tr.selectedGroupRow").removeClass("selectedGroupRow");
  var zoomed = circles.get("zoom");
  for (var group of zoomed.groups) {
    var groupName = group.label;
    var tableDiv = document.querySelector(".dataTable");
    var rows = tableDiv.querySelectorAll("tbody tr");
    for (var tr of rows) {
      var td = tr.querySelector("td.property_label");
      if (td.innerText == groupName) {
        tr.classList.add("selectedGroupRow");
      }
    }
  }
}

document.onmousemove = function (event) {
  tooltip.style.top = (
    event.pageY + tooltip.clientHeight + 16 < document.body.clientHeight
      ? event.pageY + 16 + "px"
      : event.pageY - tooltip.clientHeight - 5 + "px"
  );
  tooltip.style.left = (
    event.pageX + tooltip.clientWidth < document.body.clientWidth
      ? event.pageX + "px"
      : document.body.clientWidth - tooltip.clientWidth + 5 + "px"
  );
};

$('#properties').on('click', 'tbody tr', function (e) {
  var table = $("#properties").DataTable();
  var groupName = table.row(this).data()[0];
  var zoomed = circles.get("zoom")
  for (var group of zoomed.groups) {
    if (group.label === groupName) {
      circles.set("zoom", { groups: [groupName], zoomed: false });
      return;
    }
  }
  if (!e.ctrlKey) {
    circles.set("zoom", { all: true, zoomed: false });
  }
  circles.set("zoom", { groups: [groupName], zoomed: true });
  colorZoomed();
});

// Whenever the table changes, update the zoom
$('#properties').on('draw.dt', function () {
  colorZoomed();
});

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
