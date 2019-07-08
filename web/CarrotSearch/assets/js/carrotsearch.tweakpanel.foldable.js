/*
 * Carrot Search Circles
 *
 * Copyright 2002-2010, Carrot Search s.c.
 * 
 * This file is licensed under Apache License 2.0
 * http://www.apache.org/licenses/LICENSE-2.0
 */
/** @suppress {missingProperties} */
(function($) {
  $.fn.foldable = function(params) {
    this.each(function() {
      var $this = $(this);
      var $parent = $this.parent();
      
      if (!$parent.is(".folded") && !$parent.is(".unfolded")) {
        $parent.addClass("unfolded");
      }

      $this.click(function() {
        if ($("body").is(".standalone")) {
          return;
        }
        var $this = $(this);
        if ($parent.is(".folded")) {
          $parent.removeClass("folded").addClass("unfolded");
        } else {
          $parent.removeClass("unfolded").addClass("folded");
        }
      });

      if ($this.is("h1")) {
        $this.parent().find("h2").click(function() {
          var $parent = $(this).parent();
          if ($parent.is(".folded")) {
            $parent.removeClass("folded").addClass("unfolded");
            var self = this;
            setTimeout(function() {
              window.location.hash = "#" + $(self).attr("id");
            }, 0);
          }
        });
      }
    });
  };
})(window.jQuery);
