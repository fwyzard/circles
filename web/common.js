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
    var key;
    var val;
    [key, val] = value.split("=").map(decodeURIComponent);
    data[key] = (
      (val === undefined) ? null : val
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
    if (xhttp.readyState === 4) {
      target[attribute] = JSON.parse(xhttp.responseText);
      then();
    }
  };
  xhttp.send(null);
}

function groupColorDecorator(options, properties, variables) {
  // customize only the top level groups' colours
  if (properties.level > 0) {
    return;
  }

  // use the group color defined in the dataset
  if (properties.group.hasOwnProperty("color")) {
    variables.groupColor = properties.group.color;
    variables.labelColor = "auto";
  }
}

function circlesVisibilityDecorator(group) {
  // hide the "other" groups
  return (group.label !== "other");
}

function foamtreeVisibilityDecorator(properties, variables) {
  // hide the "other" groups
  if (properties.group.label === "other") {
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
    show_labels: null
  };
  var url = new URL(window.location.href);
  Object.keys(local_config).forEach(function (key) {
    local_config[key] = url.searchParams.get(key);
  });
  if (local_config.colours === null) {
    local_config.colours = "default";
  }
  if (local_config.groups === null) {
    local_config.groups = "hlt";
  }
  if (local_config.show_labels === null) {
    local_config.show_labels = true;
  }
  if (local_config.data_name === null) {
    local_config.data_name = "data";
  }
  local_config.threshold = 0.0;
  return local_config;
}

// Write the configuration as URL parameters
function convertConfigToURL(config) {
  var params = [];
  Object.keys(config).forEach(function (key) {
    if (config[key] !== null) {
      params.push(
        encodeURIComponent(key) + "=" + encodeURIComponent(config[key])
      );
    }
  });
  return "?" + params.join("&");
}

function escape(text) {
  return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}


function embed() {
  // We respin until the visualization container has non-zero area
  // (there are race conditions on Chrome which permit that) and the
  //  visualization class is loaded.
  var container = document.getElementById("visualization");
  if (
    container.clientWidth <= 0 ||
    container.clientHeight <= 0 ||
    !window.CarrotSearchCircles
  ) {
    window.setTimeout(embed, 250);
    return;
  }

  // Create an empty CarrotSearchCircles without any data
  circles = new CarrotSearchCircles({
    captureMouseEvents: false,
    dataObject: null,
    id: "visualization",
    pixelRatio: Math.min(2, window.devicePixelRatio || 1),
    showZeroWeightGroups: false,
    titleBar: "inscribed",
    titleBarTextColor: "#444",
    visibleGroupCount: 0
  });

  installResizeHandlerFor(circles, 300);

  circles.set("groupColorDecorator", groupColorDecorator);
  circles.set("isGroupVisible", circlesVisibilityDecorator);
  //circles.set("groupSelectionColor", "#babdb633");
  circles.set("groupSelectionColor", "#cc000022");
  circles.set("groupSelectionOutlineColor", "#cc0000aa");
  circles.set("groupSelectionOutlineWidth", 4);
  circles.set("groupSelectionOutlineStyle", "none"); // unused


  circles.set("titleBarLabelDecorator", function (attrs) {
    var table = $("#properties").DataTable();
    var total = circles.get("dataObject").weight;
    var group;
    var groups;
    var i;
    var label;
    var percent;
    var sum;
    var value;
    table.clear();
    $("#resource_title").text(current.title);
    $("#selected_label").text();
    $("#selected_value").text();
    $("#selected_percent").text();
    $("#selected").hide();


    if (attrs.hoverGroup) {
      group = attrs.hoverGroup;
      value = fixed(group.weight) + current.unit;
      percent = fixed(group.weight / total * 100.0) + " %";
      table.row.add([escape(group.label), value, percent]);
      attrs.label = value;
    } else if (attrs.selectedGroups.length > 0) {
      sum = 0.0;
      for (i = 0; i < attrs.selectedGroups.length; i += 1) {
        group = attrs.selectedGroups[i];
        value = fixed(group.weight) + current.unit;
        percent = fixed(group.weight / total * 100.0) + " %";
        sum += group.weight;
        table.row.add([escape(group.label), value, percent]);
        attrs.label = value;
      }
      if (attrs.selectedGroups.length > 1) {
        label = "selected";
        value = fixed(sum) + current.unit;
        percent = fixed(sum / total * 100.0) + " %";
        $("#selected_label").text(label);
        $("#selected_value").text(value);
        $("#selected_percent").text(percent);
        $("#selected").show();
        attrs.label = value;
      }
    } else {
      // Show all top level groups
      groups = circles.get("dataObject").groups;
      for (i = 0; i < groups.length; i += 1) {
        group = groups[i];
        value = fixed(group.weight) + current.unit;
        percent = fixed(group.weight / total * 100.0) + " %";
        table.row.add([escape(group.label), value, percent]);
      }
      label = "total";
      value = fixed(total) + current.unit;
      percent = fixed(100.0) + " %";
      $("#selected_label").text(label);
      $("#selected_value").text(value);
      $("#selected_percent").text(percent);
      $("#selected").show();
      attrs.label = value;
    }

    table.draw();
  });
}

// Load the available datasets
function loadAvailableDatasets() {
  var menu = document.getElementById("dataset_menu");
  var i;
  while (menu.length) {
    menu.remove(0);
  }
  for (i = 0; i < datasets.length; i += 1) {
    if ((config.filter !== null) && (!datasets[i].includes(config.filter))) {
      continue;
    }
    var entry = document.createElement("option");
    entry.text = datasets[i];
    entry.value = datasets[i];
    menu.options.add(entry);
    if (datasets[i] === config.dataset) {
      menu.selectedIndex = menu.options.length - 1;
    }
  }
  // if a dataset is selected, load it and the associated resources
  if (menu.selectedIndex !== null) {
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
  loadJsonInto(
    current,
    "dataset",
    config.data_name + "/" + config.dataset + ".json",
    loadAvailableMetrics
  );
}

// Upload a JSON file
function uploadDataset(files) {
  // Reset the dataset selection in the drop-down menu
  var file = files[0];
  document.getElementById("dataset_menu").selectedIndex = 0;
  config.dataset = null;
  config.local = true;
  file.text().then(function (content) {
    current.dataset = JSON.parse(content);
    loadAvailableMetrics();
  });
}

function downloadDataset() {
  var data = JSON.stringify(current.dataset, null, 2);
  var blob = new Blob([data], {
    type: "application/json"
  });
  var url = URL.createObjectURL(blob);
  var a = document.createElement("a");
  a.href = url;
  a.download = config.dataset + ".json";
  a.click();
}

function loadAvailableMetrics() {
  var menu = document.getElementById("metric_menu");
  var i;
  var resources = current.dataset.resources;
  var keys;
  // Clear the current resources
  while (menu.length) {
    menu.remove(0);
  }

  for (i = 0; i < resources.length; i += 1) {
    var entry = document.createElement("option");
    keys = Object.keys(resources[i]);
    // if you find description title name and unit use them
    if (
      keys.includes("description") &&
      keys.includes("title") &&
      keys.includes("name") &&
      keys.includes("unit")
    ) {
      entry.text = resources[i].description;
      entry.value = resources[i].name;
      entry.dataset.title = resources[i].title;
      entry.dataset.unit = resources[i].unit;
      menu.add(entry);
      if (entry.value === config.resource) {
        menu.selectedIndex = i;
      }
    } else {
      Object.keys(resources[i]).forEach(function (key) {
        entry.text = resources[i][key];
        entry.value = key;
        menu.add(entry);
        if (key === config.resource) {
          menu.selectedIndex = i;
        }
      });
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
  current.unit = " " + menu.options[index].dataset.unit;
  current.title = menu.options[index].dataset.title;
  if (current.unit === null || current.title === null) {
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
  }
  updatePage();
}

// Update the configuration with the selected grouping
function updateGroups() {
  var menu = document.getElementById("groups_menu");
  var index = menu.selectedIndex;
  config.groups = menu.options[index].value;

  // load the module groups, then update the page
  loadJsonInto(
    current,
    "groups", "groups/" + config.groups + ".json",
    compileGroups
  );
}

// Update the configuration with the selected colour scheme
function updateColours() {
  var menu = document.getElementById("colours_menu");
  var index = menu.selectedIndex;
  config.colours = menu.options[index].value;

  // load the colour scheme, then update the page
  loadJsonInto(
    current,
    "colours",
    "colours/" + config.colours + ".json",
    updatePage
  );
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

// Update the title, URL, history and visualisation based on the current
// configuration
function updatePage() {
  var title = (
    (config.local ? "local file" : config.dataset) + " - " + current.metric
  );
  window.history.pushState(config, title, convertConfigToURL(config));
  document.title = "CMSSW resource utilisation: " + title;

  // Handle navigation of the History
  window.onpopstate = function (event) {
    config = event.state;
    updateDataset();
  };

  updateDataView();
}

// Load the available groupings
function loadAvailableGroups() {
  var menu = document.getElementById("groups_menu");
  var i;
  for (i = 0; i < groups.length; i += 1) {
    var entry = document.createElement("option");
    entry.text = groups[i];
    entry.value = groups[i];
    menu.options.add(entry);
    if (groups[i] === config.groups) {
      menu.selectedIndex = i;
    }
  }
  updateGroups();
}


// Load the available colour scheme
function loadAvailableColours() {
  var menu = document.getElementById("colours_menu");
  var i;
  for (i = 0; i < colours.length; i += 1) {
    var entry = document.createElement("option");
    entry.text = colours[i];
    entry.value = colours[i];
    menu.options.add(entry);
    if (colours[i] === config.colours) {
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
  if (pattern === "") {
    return null;
  }

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
  if (pattern === null) {
    return true;
  }

  if (pattern instanceof RegExp) {
    return pattern.test(text);
  }

  return (pattern === text);
}

// Compile the group definition as a pair of patterns for the
// module's type and label
function compileGroups() {
  var compiled = [];
  var key;
  current.compiled = null;

  Object.keys(current.groups).forEach(function (key) {
    // convert a glob pattern into a regular expression
    var t;
    var l;
    if (key.includes("|")) {
      [t, l] = key.split("|");
      t = compilePattern(t);
      l = compilePattern(l);
    } else {
      // leave the type null
      t = null;
      l = compilePattern(key);
    }
    compiled.push([t, l, current.groups[key]]);
  });

  // Insert the group "other"
  if (current.groups.other === undefined) {
    compiled.push([new RegExp(".*"), new RegExp("^other$"), "other"]);
  }

  current.compiled = compiled;
  updatePage();
}

function findGroup(module) {
  var assigned = false;
  var group = "Unassigned";
  current.compiled.forEach(function ([t, l, g]) {
    if (matchPattern(t, module.type) && matchPattern(l, module.label)) {
      assigned = true;
      group = g;
      return;
    }
  });
  if (!assigned) {
    unassigned.push({
      label: module.label,
      type: module.type
    });
  }

  return group.split("|");
}

// group is an array of nested group labels,
// e.g. [ "GPU", "Pixels", "PixelTrackProducer" ]
function getGroup(group) {
  // data should always have a "groups" property of array type
  var data = current.data;
  group.forEach(function (label) {
    // check if data.groups has an element wih the given label
    var found = false;
    data.groups.forEach(function (element) {
      if (element.label === label) {
        found = true;
        data = element;
        return;
      }
    });
    if (!found) {
      return null;
    }
  });

  return data;
}

// group is an array of nested group labels,
// e.g. [ "GPU", "Pixels", "PixelTrackProducer" ]
function makeOrUpdateGroup(group, module) {
  // data should always have a "groups" property of array type
  var data = current.data;
  var len;
  data.elements = 0;
  group.forEach(function (label) {
    var found = false;
    // add the module's resource to the group's
    data.weight += module[config.resource];
    // make sure that data.groups has an element wih the given label
    data.groups.forEach(function (element) {
      element.id = element.label;
      if (element.label === label) {
        found = true;
        data = element;
        return;
      }
    });
    if (!found) {
      len = data.groups.push({
        "groups": [],
        "label": label,
        "weight": 0.0
      });
      data = data.groups[len - 1];
    }
  });
  // add the module and its resource to the group
  if (current.show_labels || module.label === "other") {
    data.groups.push({
      "events": module.events,
      "label": module.label,
      "ratio": module.ratio,
      "weight": module[config.resource]
    });
  } else {
    data.groups.push({
      "events": module.events,
      "label": "",
      "ratio": module.ratio,
      "weight": module[config.resource]
    });

  }
  data.weight += module[config.resource];
}

function normalise(data, events) {
  data.weight = data.weight / events;
  if (data.hasOwnProperty("events")) {
    data.events = data.events / events;
  }
  if (data.hasOwnProperty("groups")) {
    data.groups.forEach(function (group) {
      normalise(group, events);
    });
  }
}

function updateDataView() {
  // Do not draw if the configuration is incomplete
  if ((config.local === false && config.dataset === null) || !config.resource) {
    return;
  }

  // Do not draw if the configuration has been updated
  if (current.processing) {
    return;
  }

  // Respin until all configurations are available
  if (
    current.dataset === null ||
    current.colours === null ||
    current.groups === null ||
    current.compiled === null
  ) {
    window.setTimeout(updateDataView, 250);
    return;
  }

  current.processing = true;

  current.data = {
    "expected": current.dataset.total[config.resource],
    "groups": [],
    "label": current.dataset.total.label,
    "ratio": current.dataset.total.ratio,
    "weight": 0.0
  };

  current.dataset.modules.forEach(function (module) {
    var group = findGroup(module);
    group.push(module.type);
    makeOrUpdateGroup(group, module);
  });
  if (unassigned.length) {
    console.log("Unassigned modules:");
    console.table(unassigned);
  }
  normalise(current.data, current.dataset.total.events);

  Object.keys(current.colours).forEach(function (key) {
    var group = getGroup(key.split("|"));
    if (group !== null) {
      group.color = current.colours[key];
    }
  });

  if (circles !== null) {
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
  var a = document.createElement("a");

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
  var sortable_label = $.fn.dataTable.absoluteOrder({
    position: "bottom",
    value: "other"
  });

  // load the colour scheme
  loadJsonInto(
    current,
    "colours",
    "colours/" + config.colours + ".json",
    () => undefined
  );

  // load the available datasets, and the resources actually
  // available from the dataset
  loadAvailableDatasets();

  // load the available groups and colours
  loadAvailableGroups();
  loadAvailableColours();

  embed();

  if (!$.fn.DataTable.isDataTable("#properties")) {
    $("#properties").dataTable({
      "columns": [{
        "className": "property_label",
        "type": sortable_label
      },
      {
        "className": "property_value",
        "type": "any-number",
        "targets": 0
      },
      {
        "className": "property_value"
      }
      ],
      //"info": true,
      "paging": false,
      "searching": false
    });
  }

  circles.set("onGroupHover", function (hover) {
    if (hover.group) {
      tooltip.innerHTML = escape(hover.group.label) +
        "<br>" + hover.group.weight.toFixed(1) +
        " " + current.unit;
      if (hover.group.hasOwnProperty("events")) {
        tooltip.innerHTML += "<br>" +
          Number(hover.group.events * 100.0).toFixed(1) +
          "% events";
      }
      if (hover.group.hasOwnProperty("ratio")) {
        tooltip.innerHTML += "<br>" +
          Number(hover.group.ratio * 100.0).toFixed(1) +
          "% ratio";
      }
      tooltip.style.visibility = "visible";
    } else {
      tooltip.innerHTML = null;
      tooltip.style.visibility = "hidden";
    }
  });


});

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

$('#properties').on('click', 'tr', function(e) {
  var table = $("#properties").DataTable();
  var groupName = table.row(this).data()[0];
  var zoomed = circles.get("zoom")
  for(var group of zoomed.groups) {
    if(group.label === groupName) {
      circles.set("zoom", { groups: [groupName], zoomed: false });
      return;
    }
  }
  if (!e.ctrlKey) {
    circles.set("zoom", { all: true, zoomed: false });
  }
  circles.set("zoom", { groups: [groupName], zoomed: true });
});
