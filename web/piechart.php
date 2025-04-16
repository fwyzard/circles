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
    <script type="text/javascript" src="DataTables/plug-ins/sorting/absolute.js"></script>
    <script type="text/javascript" src="DataTables/plug-ins/sorting/any-number.js"></script>
    <script type="text/javascript" src="DataTables/plug-ins/dataRender/ellipsis.js"></script>

    <!-- load the available datasets, groups and colour schemes -->
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
        print("var data_name = \"$data_name\";");
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
        overflow: hidden;
      }

       #visualization {
        z-index: 1;}

      .dataTable>tbody>tr{
        cursor: pointer;
      }

      .property_label {
        width:      60%;
        text-align: left;
      }

      .property_value {
        width:      20%;
        text-align: right;
      }

      .property_fraction {
        width:      20%;
        text-align: right;
      }

      .selectedGroupRow {
        background-color: #e0e0e0 !important;
      }

      #selected {
        font-style: italic;
      }
    </style>

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
        <div style="display:inline-block;">
          <input type="checkbox" checked id="show_animations_checkbox" name="show_animations" onchange="updateShowAnimations()"></input>
          <b>Show aminations</b>
        </div>
        <div style="display:inline-block;">
          <button type="button" onclick="getImage()">Download image</button>
        </div>
        <div style="display:inline-block;">
          <button type="button" onclick="downloadDataset()">Download dataset</button>
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
    </div>


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
          z-index: 9999;
      }
    </style>

    <div id="tooltip" class="tooltip">Tooltip</div>

  </body>
  <!-- Load common.js -->
  <script src="common.js"></script>
</html>
