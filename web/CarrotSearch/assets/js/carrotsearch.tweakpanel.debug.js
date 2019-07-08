/*
 * Carrot Search Visualization event log UI
 *
 * Copyright 2002-2010, Carrot Search s.c.
 * 
 * This file is licensed under Apache License 2.0
 * http://www.apache.org/licenses/LICENSE-2.0
 */
/** @suppress {missingProperties} */
(function($) {
  $.fn.debug = function(params) {
    var defaults = {
      legend: "Debug"
    };
    
    var options = $.extend({}, defaults, params);
    var $container = this;

    // Fieldset with all controls
    var $fieldset = $("<fieldset class='folded' />");
    $fieldset.append($("<legend />").text(options.legend));

    $fieldset.append("<ul>\
        <li><a href='#hide'>Hide visualization container</a></li>\
        <li><a href='#show'>Show visualization container</a></li>\
      </ul>");
    $fieldset.find("a[href = '#hide']").click(function() {
      $(window.parent.document.getElementById("visualization")).hide();
      return false;
    });
    $fieldset.find("a[href = '#show']").click(function() {
      $(window.parent.document.getElementById("visualization")).show();
      return false;
    });
    $container.append($fieldset);
    
    return this;
  };
})(window.jQuery);
