<!DOCTYPE html>
<html lang="en">
  <head>
    <title>CMSSW resource utilisation</title>

    <meta charset="utf-8" />

    <!-- CarrotSearch Circles -->
    <link rel="stylesheet" href="CarrotSearch/assets/css/reset.css" />
    <link rel="stylesheet" href="CarrotSearch/assets/css/layout.css" />

    <script src="CarrotSearch/carrotsearch.circles.js"></script>
    <!--script src="CarrotSearch/carrotsearch.circles.asserts.js"></script-->
    <script src="CarrotSearch/assets/js/carrotsearch.examples.onresizehook.js"></script>
    <script src="CarrotSearch/assets/js/carrotsearch.examples.viewport.js"></script>

    <!-- jQuery, DataTables, and plugins-->
    <link rel="stylesheet" type="text/css" href="DataTables/datatables.min.css"/>
    <script type="text/javascript" src="DataTables/datatables.min.js"></script>
    <script type="text/javascript" src="DataTables/plug-ins/1.12.1/sorting/absolute.js"></script>

    <!-- load the available datasets, groups and colour schemes -->
    <script type="text/javascript">
    <?php
      $data_name=basename($_SERVER['SCRIPT_NAME'], ".php");
      $dataset_cache = "${data_name}_dataset.js";
      if ($data_name == "piechart"){
        $data_name = "data";
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

      // from https://stackoverflow.com/a/17161106/2050986
      // does not support flag GLOB_BRACE
      function rglob($pattern, $flags = 0) {
          $files = glob($pattern, $flags);
          foreach (glob(dirname($pattern).'/*', GLOB_ONLYDIR) as $dir) {
              $files = array_merge($files, rglob($dir.'/'.basename($pattern), $flags));
          }
          return $files;
      }
      if (file_exists($dataset_cache)){
        print(file_get_contents($dataset_cache));
      }
      else {
        print("var data_name = $data_name;\n");
        print("var datasets = [ " . join(", ", array_map("preformat", rglob($data_name."/*.json"))) . " ];\n");
        print("var groups = [ " . join(", ", array_map("preformat", glob("groups/*.json"))) . " ];\n");
        print("var colours = [ " . join(", ", array_map("preformat", glob("colours/*.json"))) . " ];\n");
      }
    ?>
    </script>

    <!-- local code -->
    <style type="text/css">
      html, body {
        height: 100%;
        margin: 0;
      }

      .property_label {
        width:      60%;
        text-align: left;
      }

      .property_value {
        width:      20%;
        text-align: right;
      }

      #selected {
        font-style: italic;
      }
    </style>

    <script src="common.js"></script>

  </head>

  <body>
    <div id="visualization" style="width: 60%;"></div>

    <div id="sidebar" style="width: 40%; overflow: auto;">
      <form>
        <div style="display:inline-block;">
          <b>Dataset</b>
          <select id="dataset_menu" name="dataset" onchange="updateDataset()">
            <option disabled selected value="">Select the data to visualise</option>
          </select>
        </div>
        <div style="display:inline-block;">
          or <b>upload a file</b>
          <input type="file" accept=".json" id="dataset_upload" oninput="uploadDataset(this.files)"/>
        </div>
        <div></div>
        <div style="display:inline-block;">
          <b>Metric</b>
          <select id="metric_menu" name="resource" onchange="updateMetrics()">
            <option disabled selected value="">Select some data to choose a metric</option>
          </select>
        </div>
        <div style="display:inline-block;">
          <b>Groups</b>
          <select id="groups_menu" name="groups" onchange="updateGroups()"></select>
        </div>
        <div style="display:inline-block;">
          <b>Colour style</b>
          <select id="colours_menu" name="colours" onchange="updateColours()"></select>
        </div>
        <div style="display:inline-block;">
          <input type="checkbox" checked id="show_labels_checkbox" name="show_labels" onchange="updateShowLabels()"></input>
          <b>Show labels</b>
        </div>
      </form>
      <hr/>

      <table id="properties" style="width:100%">
      <thead>
        <tr>
          <th class="property_label">Element</th>
          <th id="resource_title" class="property_value">Resource</th>
          <th class="property_value">Fraction</th>
        </tr>
      </thead>
      <tbody>
      </tbody>
      <tfoot>
        <tr id="selected">
          <td id="selected_label" class="property_label"></td>
          <td id="selected_value" class="property_value"></td>
          <td id="selected_percent" class="property_value"></td>
        </tr>
      </tfoot>
      </table>
    </div>

    <script>
      // Load the configuration from the URL
      function loadConfigFromURL() {
        var config = {
          local:       false,
          dataset:     null,
          resource:    null,
          colours:     null,
          groups:      null,
          filter:      null,
          show_labels: null
        };
        var url = new URL(window.location.href);
        for (key in config) {
          config[key] = url.searchParams.get(key);
        }
        return config;
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
        dataset:     null,
        colours:     null,
        groups:      null,
        show_labels: true,
        compiled:    null,
        metric:      null,   // description of the current mteric
        title:       null,   // column title associated to the current metric
        unit:        null,   // unit associated to the current metric
        data:        null,
        processing:  false,  // the new configuration is being processed
      };

      // Circles data view
      var circles = null;

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

        circles.set("groupColorDecorator", groupColorDecorator);
        circles.set("isGroupVisible", circlesVisibilityDecorator);
        //circles.set("groupSelectionColor", "#babdb633");
        circles.set("groupSelectionColor", "#cc000022");
        circles.set("groupSelectionOutlineColor", "#cc0000aa");
        circles.set("groupSelectionOutlineWidth", 4);
        circles.set("groupSelectionOutlineStyle", "none"); // unused


        circles.set("titleBarLabelDecorator", function(attrs) {
          var table = $('#properties').DataTable();
          table.clear();
          $('#resource_title').text(current.title);
          $('#selected_label').text();
          $('#selected_value').text();
          $('#selected_percent').text();
          $('#selected').hide();

          var total = circles.get("dataObject").weight;

          if (attrs.hoverGroup) {
            var group = attrs.hoverGroup;
            var value = fixed(group.weight) + current.unit;
            var percent = fixed(group.weight / total * 100.) + " %";
            table.row.add( [ group.label, value, percent ] );
            attrs.label = value;
          } else if (attrs.selectedGroups.length > 0) {
            var sum = 0.;
            for (var i = 0; i < attrs.selectedGroups.length; i++) {
              var group = attrs.selectedGroups[i];
              var value = fixed(group.weight) + current.unit;
              var percent = fixed(group.weight / total * 100.) + " %";
              sum += group.weight;
              table.row.add( [ group.label, value, percent ] );
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
              table.row.add( [ group.label, value, percent ] );
            }
            var label   = "total";
            var value   = fixed(total) + current.unit;
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
        for (var i = 0; i < datasets.length; i++) {
          if ((config.filter != null) && (! datasets[i].includes(config.filter))){
            continue;
          }
          var entry = document.createElement("option");
          entry.text = datasets[i];
          entry.value = datasets[i];
          menu.options.add(entry);
          if (datasets[i] == config.dataset) {
            menu.selectedIndex = menu.options.length-1;
          }
        }

        // if a dataset is selected, load it and the associated resources
        if (menu.selectedIndex != 0) {
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
        loadJsonInto(current, "dataset", data_name + "/" + config.dataset + ".json", loadAvailableMetrics);
      }

      // Upload a JSON file
      function uploadDataset(files) {
        // Reset the dataset selection in the drop-down menu
        document.getElementById("dataset_menu").selectedIndex = 0;
        config.dataset = null;
        config.local = true;
        var file = files[0];
        file.text().then(function(content) {
          current.dataset = JSON.parse(content);
          loadAvailableMetrics();
        });
      }


      function loadAvailableMetrics() {
        var menu = document.getElementById("metric_menu");

        // Clear the current resources
        while (menu.length) {
          menu.remove(0);
        }

        // Extract the available resources from the input, and select the first one by default
        var resources = current.dataset.resources;
        for (var i = 0; i < resources.length; i++) {
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

        updateMetrics();
      }


      // Update the configuration with the selected metric
      function updateMetrics() {
        var menu = document.getElementById("metric_menu");
        var index = menu.selectedIndex;
        config.resource = menu.options[index].value;
        current.metric = menu.options[index].text
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

      // Update the title, URL, history and visualisation based on the current configuration
      function updatePage() {
        var title = (config.local ? "local file" : config.dataset) + " - " + current.metric;
        window.history.pushState(config, title, convertConfigToURL(config));
        document.title = "CMSSW resource utilisation: " + title;

        // Handle navigation of the History
        window.onpopstate = function(event) {
          config = event.state;
          updateDataset();
        }

        updateDataView();
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
            [t,l] = key.split("|")
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
        if (! assigned) {
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
          if (! found) {
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
        for (label of group) {
          // add the module's resource to the group's
          data.weight += module[config.resource];
          // make sure that data.groups has an element wih the given label
          var found = false;
          for (element of data.groups) {
            if (element.label == label) {
              found = true;
              data = element;
              break;
            }
          }
          if (! found) {
            var len = data.groups.push({ "label": label, "weight": 0., "groups": [] })
            data = data.groups[len-1];
          }
        }

        // add the module and its resource to the group
        if (current.show_labels || module.label == "other")
          data.groups.push({ "label": module.label, "weight": module[config.resource], "events": module.events })
        else
          data.groups.push({ "label": "", "weight": module[config.resource], "events": module.events })
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
        if ((config.local == false && config.dataset == null) || ! config.resource) {
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
          "weight": 0.,
          "groups": []
        }

        var threshold = config.threshold * current.dataset.total.events;
        for (module of current.dataset.modules) {
          if (module[config.resource] <= threshold)
            continue;
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
          circles.set("onRolloutComplete", function() {
            current.processing = false;
          });

          circles.set("dataObject", current.data);
        }
      }

      // load the colour scheme
      loadJsonInto(current, "colours", "colours/" + config.colours + ".json", function(){});

      // load the available datasets, and the resources actually available from the dataset
      loadAvailableDatasets();

      // load the available groups and colours
      loadAvailableGroups();
      loadAvailableColours();

      embed();
    </script>

    <script>
      $(document).ready(function() {
        var sortable_label = $.fn.dataTable.absoluteOrder(
          { value: "other", position: "bottom" }
        );

        $('#properties').DataTable( {
          "columns": [
            { "className": "property_label", "type": sortable_label },
            { "className": "property_value" },
            { "className": "property_value" }
          ],
          //"info": true,
          "paging": false,
          "searching": false
        } );
      });
    </script>

    <!-- show this tool's logo in the upper left corner -->
    <div id="logo" style="position: absolute; top: 32px; left: 32px; z-index: 999;">
      <a href="https://github.com/fwyzard/circles">
        <img src="images/pie.png" width="72" height="72"/>
      </a>
    </div>

    <!-- show a tootip when the mouse is hovering over one of the slices -->
    <style>
      .tooltip {
          position: absolute;
          top: 400px;
          left: 200px;
          visibility: hidden;
          background: #646464;
          border-radius:4px;
          padding: 6px 12px;
          font-family: arial;
          font-size: 16px;
          text-shadow: 0px 1px 1px #000;
          color: #ffffff;
      }
    </style>

    <div id="tooltip" class="tooltip">Tooltip</div>

    <script>
      var tooltip = document.getElementById("tooltip");

      circles.set("onGroupHover", function(hover) {
        if (hover.group) {
          tooltip.innerHTML = hover.group.label + "<br>" + hover.group.weight.toFixed(1) + " ms";
          if ("events" in hover.group) {
            tooltip.innerHTML += "<br>" + (hover.group.events * 100.).toFixed(1) + "% events";
          }
          tooltip.style.visibility = "visible";
        } else {
          tooltip.innerHTML = null;
          tooltip.style.visibility = "hidden";
        }
      });

      document.onmousemove = function(event) {
        tooltip.style.top = (event.pageY + 16) + "px";
        tooltip.style.left = event.pageX + "px";
      }
    </script>

  </body>
</html>
