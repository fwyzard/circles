/*
 * Carrot Search Visualization frame rate reporter
 *
 * Copyright 2002-2010, Carrot Search s.c.
 * 
 * This file is licensed under Apache License 2.0
 * http://www.apache.org/licenses/LICENSE-2.0
 */
/** @suppress {missingProperties} */
(function($) {
  $.fn.framerate = function(params) {
    var defaults = {
      legend: "Frame rate"
    };
    
    var options = $.extend({}, defaults, params);
    var $container = this;
    if (options.update) {
      update();
      return;
    }

    // Fieldset with all controls
    var $fieldset = $("<fieldset id='framerate' />");
    $fieldset.append($("<legend />").text(options.legend));

    $fieldset.append("<ul>\
      <li><label><span>Last redraw</span></label><span id='lastRedrawTime'>n/a</span>, <span id='lastRedrawFps'>n/a</span></li>\
      <li><label><span>Last rollout</span></label><span id='lastRolloutScreenFps'>n/a</span>, <span id='lastRolloutMaxFps'>n/a</span></li>\
    </ul>");
    $container.append($fieldset);
    update();
    return this;

    function update() {
      var times = options.source.get("times");
      if (!times.finalDrawTime) {
        return;
      }

      var $fieldset = $container.find("#framerate");
      var $redrawTime = $fieldset.find("#lastRedrawTime");
      var $redrawFps = $fieldset.find("#lastRedrawFps");
      var $rolloutMaxFps = $fieldset.find("#lastRolloutMaxFps");
      var $rolloutScreenFps = $fieldset.find("#lastRolloutScreenFps");

      $rolloutMaxFps.text((1000.0 * times.rolloutFramesDrawn / times.rolloutComputationTime).toFixed(2) + " FPS (max)");
      $rolloutScreenFps.text((1000.0 * times.rolloutFramesDrawn / times.rolloutTotalTime).toFixed(2) + " FPS");
      $redrawTime.text(times.finalDrawTime + " ms");
      $redrawFps.text((1000.0 / times.finalDrawTime).toFixed(2) + " FPS (max)");
    }
  };
})(window.jQuery);
