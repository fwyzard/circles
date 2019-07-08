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
  $.fn.eventlog = function(params) {
    var defaults = {
      legend: "Event log",
      source: null,
      events: [],
      maxLines: 100
    };
    
    var options = $.extend({}, defaults, params);
    if (!options.source) {
      return this;
    }

    var originalHandlers = { };
    $(window).unload(function () {
      options.source.set(originalHandlers);
    });

    var $container = this;

    // Fieldset with all data sets
    var $fieldset = $("<fieldset />");
    $fieldset.append($("<legend />").text(options.legend));

    var $log = $("<div class='log'></div>");
    $fieldset.append($log);
    if (options.optional) {
      $.each(options.optional, function (key, val) {
        $fieldset.append("<label style='margin-right: 1em; float: left'><input type='checkbox' id='" + key + "Cb' />&nbsp;Show&nbsp;" + key + "</label>");
      });
    }
    $container.append($fieldset);
    
    var callbacks = $.extend({ }, options.piggyback);
    for (var e in options.events) {
      var original = options.source.get(options.events[e]);
      originalHandlers[options.events[e]] = original;
      callbacks[options.events[e]] = wrap(options.events[e], log, original);
    }
    
    options.source.set(callbacks);

    return this;
    
    function log() {
      var method = arguments[0];
      if (options.piggybackEvents && options.piggybackEvents[method]) {
        options.piggybackEvents[method]();
      }

      if (options.optional && options.optional[method] && !$("#" + method + "Cb").is(":checked")) {
        return;
      }

      var $line = $("<div />");
      $line.addClass(method);

      append("method", method + "(");
      if (arguments.length > 1) {
        format(arguments[1]);
      }
      for (var i = 2; i < arguments.length; i++) {
        append(null, ", ");
        format(arguments[i]);
      }
      append("method", ")");
      
      $log.append($line);
      var lineCount = $log.children().size();
      if (lineCount > options.maxLines) {
        $log.children().slice(0, lineCount - options.maxLines).remove();
      }
      
      $log.get(0).scrollTop = $log.get(0).scrollHeight;
      
      function append(type, text) {
        var $span = $("<span />");
        if (typeof text == "undefined") {
          text = type;
          type = null;
        }
        if (type) {
          $span.addClass(type);
        }
        $span.text(text);
        $line.append($span);
      }
      
      function format(o) {
        switch(Object.prototype.toString.call(o)) {
          case "[object Array]":
            var maxElements = 5;
            append("array", "[ ");
            var len = Math.min(o.length, maxElements);
            for (var i = 0; i < len; i++) {
              format(o[i]);
              if (i < o.length - 1) {
                append(null, ", ");
              }
            }
            if (o.length > len) {
              append("ellipsis", "and " + (o.length - len) + " more");
            }
            append("array", " ]");
            break;
          
          case "[object String]":
            append("string", "\"" + ellipsis(o) + "\"");
            break;
            
          case "[object Boolean]":
            append("boolean", o.toString());
            break;
            
          case "[object Number]":
            append("number", o.toString());
            break;

          case "[object Null]":
            append("object", "null");
            break;

          case "[object Undefined]":
            append("object", "undefined");
            break;

          default:
            append(null, "{ ");
            var count = 0;
            $.each(o, function() { count++; });
            $.each(o, function(key, value) {
              append(null, key + ": ");
              format(o[key]);
              if (--count > 0) {
                append(null, ", ");
              }
            });
            append(null, " }");
            break;
        }
      }
      
      function ellipsis(s) {
        if (s.length > 80) {
          return s.substring(0, 80) + "...";
        } else {
          return s;
        }
      }
    }

    function wrap(name, f, prev) {
      return function eventLogCallbackWrapper() {
        var a = [name];
        for (var i = 0; i < arguments.length; i++) {
          a.push(arguments[i]);
        }

        f.apply(this, a);
      };
    }
  };
  
})(window.jQuery);
