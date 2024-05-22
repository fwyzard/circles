    <?php
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
      function rglob($basedir, $pattern, $flags = 0) {
          $files = glob($basedir.'/'.$pattern, $flags);
          foreach (glob($basedir.'/*', GLOB_ONLYDIR) as $dir) {
              $files = array_merge($files, rglob($dir, $pattern, $flags));
          }
          return $files;
      }
      $data = "data";
      if ($argc > 1) {$data = $argv[1];}
      print("var data_name = \"$data\";\n");
      print("var datasets = [ " . join(", ", array_map("preformat", rglob($data,"*.json"))) . " ];\n");
      print("var groups = [ " . join(", ", array_map("preformat", glob("groups/*.json"))) . " ];\n");
      print("var colours = [ " . join(", ", array_map("preformat", glob("colours/*.json"))) . " ];\n");
    ?>
