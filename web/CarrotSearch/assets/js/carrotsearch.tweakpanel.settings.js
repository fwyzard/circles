/*
 * Carrot Search Visualization settings UI
 *
 * Copyright 2002-2010, Carrot Search s.c.
 * 
 * This file is licensed under Apache License 2.0
 * http://www.apache.org/licenses/LICENSE-2.0
 */
/** @suppress {missingProperties} */
(function($) {
  $.fn.settings = function(params) {
    var defaults = {
      metadata: { },
      values: { },
      defaults: { },
      onChange: function() { },
      ignoredInExportToJs: { }
    };

    var options = $.extend({}, defaults, params);
    var fontSizeUnit = options.values.fontSizeUnit || "";

    var metadata = options.metadata;
    var values = $.extend({}, options.values, { fontSizeUnit: fontSizeUnit });
    var ignoredInExportToJs = $.extend({}, options.ignoredInExportToJs, { fontSizeUnit: true });
    var $container = this;
    var onChange = options.onChange;

    // Remove % signs from font size properties, we need numbers there
    $.each(options.fontSizeProperties, function(property) {
      if (/%/.test(values[property])) {
        values[property] = values[property].replace(/%/g, "");
      }
    });

    // Generate settings components
    for (var groupId in metadata) {
      var groupMetadata = metadata[groupId];
      var $group = $("<fieldset></fieldset>");
      $("<legend></legend>").text(groupMetadata.label).appendTo($group);
      if (groupMetadata["subtitle"]) {
        $(groupMetadata["subtitle"]).appendTo($group);
      }
      var $settings = $("<ul></ul>").appendTo($group);
      for (var settingId in groupMetadata.settings) {
        var settingMetadata = groupMetadata.settings[settingId];
        
        // Option label
        var $setting = $("<li />");
        var $label = $(settingMetadata.type == "enum" ? "<div class='label' />" : "<label />").appendTo($setting);
        var $labelText = $("<span />").text(settingMetadata.label).appendTo($label);
        
        // Link to the api docs.
        var helpSettingId = settingId;
        if (settingMetadata.type == "range") {
          helpSettingId = settingMetadata.lower;
        }
        if (!$.browser.msie || $.browser.version >= 8) {
          $("<a target='_attrhelp' href='" + options.apiDocsUrl + "#" + helpSettingId + "'><em class='help'>&nbsp;</em></a>")
            .data("helpid", helpSettingId)
            .appendTo($labelText);
        }

        // Option controls
        switch (settingMetadata.type) {
          case "color":
            addColorEditor(settingId, values[settingId], settingMetadata, $setting);
            break;

          case "enum":
            addEnumEditor(settingId, values[settingId], settingMetadata, $setting);
            break;

          case "number":
            addNumberEditor(settingId, values[settingId], settingMetadata, $setting);
            break;

          case "range":
            addRangeEditor(settingId, values, settingMetadata, $setting);
            break;

          case "boolean":
            addBooleanEditor(settingId, values[settingId], settingMetadata, $setting);
            break;
            
          case "string":
            addStringEditor(settingId, values[settingId], settingMetadata, $setting);
            break;

          case "font":
            var $editor = addStringEditor(settingId, values[settingId], settingMetadata, $setting);
            $editor.fontSelector(settingMetadata.families);
            break;

          case "links":
            addLinksEditor(settingId, values[settingId], settingMetadata, $setting);
            break;
        }
        $settings.append($setting);
        if (settingMetadata.note) {
          $settings.append($("<div class='label'>" + settingMetadata.note + "</div>"));
        }
      }

      $container.append($group);
    }
    
    // Generate export window
    var $group = $("<fieldset class='folded'></fieldset>").append("<legend>Options as JavaScript</legend>");
    $group.append("<div>Current values of all options, copy and paste into your code.</div>");
    var $omitDefaults = $("<label><input type='checkbox' checked='checked' /> Show only values different from defaults</label>)");
    $group.append($omitDefaults);
    var $export = $group.append("<div><pre></pre></div>").find("div:has(pre)").bind("change", function() {
      var omitDefaults = $omitDefaults.find("input").is(":checked");
      var js = "{";
      var optionsAdded = 0;

      var vals = $.extend({}, values);
      transformFontSizeUnits(vals, vals);
      for (var v in vals) {
        if (ignoredInExportToJs[v]) {
          continue;
        }
        
        var o = vals[v];
        if (typeof o == "function" || (omitDefaults && options.defaults[v] == o)) {
          continue;
        } 
        
        js += "\n  " + v + ": " + format(o) + ",";
        optionsAdded++;
      }
      
      if (optionsAdded > 0) {
        js = js.substring(0, js.length - 1);
      }
      js += "\n}\n";
      $(this).html($("<pre />").addClass("prettyprint").text(js));
      prettyPrint();
      
      function format(o) {
        switch(Object.prototype.toString.call(o)) {
          case "[object Array]":
            var s = "[ ";
            if (o.length > 0) {
              s += format(o[0]);
            }
            for (var i = 1; i < o.length; i++) {
              s += ", ";
              s += format(o[i]);
            }
            return s + " ]";
            break;
          
          case "[object String]":
            return "\"" + o + "\"";
            
          default:
            return o !== null ? o.toString() : "null";
        }
      }
    }).trigger("change");
    $omitDefaults.find("input").click(function() {
      $export.trigger("change");
    });
    $container.append($group);


    var triggerChange = (function() {
      var last = 0, timeout;
      return function(property, value, immediate) {
        window.clearTimeout(timeout);
        if (!immediate || !last || last < 100) {
          setTimeout(trigger, 0);
        } else {
          timeout = window.setTimeout(trigger, last);
        }


        function trigger() {
          var start = Date.now();
          var toSet;
          if (typeof property === "object" && value === undefined) {
            toSet = property;
          } else {
            toSet = { };
            toSet[property] = value;
          }
          transformFontSizeUnits(values, toSet);
          $.extend(values, toSet);
          $export.trigger("change");
          onChange.call($container, toSet);
          last = (Date.now() - start + last) / 2;
        }
      };
    })();

    return this;
    
    //
    // Private functions
    //

    function addEnumEditor(id, value, metadata, $container) 
    {
      var $simpleEditors = $([]);
      var $customEditors = $([]);
      
      for (var enumValue in metadata.values) 
      {
        var enumMetadata = metadata.values[enumValue];
        var simpleEnum = typeof enumMetadata == 'string';
        
        var $editor = $("<input />").attr("name", id).attr("type", "radio").attr("value", enumValue);
        $container.find("div").append($editor.wrap("<label class='radio' />")
            .parent().append(simpleEnum ? enumMetadata : enumMetadata.label));
        
        if (simpleEnum)
        {
          $editor.attr("checked", value == enumValue);
          if (metadata.isOptionEnabled && !metadata.isOptionEnabled(enumValue)) {
            $editor.attr("disabled", "disabled");
          }
          
          $simpleEditors = $simpleEditors.add($editor);
        }
        else
        {
          var isChecked = enumMetadata.isChecked(value);
          $editor.attr("checked", isChecked);
          if (metadata.isOptionEnabled && !metadata.isOptionEnabled(enumValue)) {
            $editor.attr("disabled", "disabled");
          }

          $customEditors = $customEditors.add($editor);
          
          var $customInput = $("<input />").attr("id", enumValue).attr("type", "text").toggle(isChecked);
          if (isChecked) {
            $customInput.val(enumMetadata.transformInput(value));
          } else if (enumMetadata.initialValue) {
            $customInput.val(enumMetadata.initialValue);
          }
          $customInput.change(function() {
            triggerChange(id, enumMetadata.transformOutput(this.value), false);
          });
          
          $container.find("div").append($customInput);
          $editor.data("custom", $customInput);
        }
      }
      
      $simpleEditors.change(function() {
        $customEditors.each(function (i, e) {
          $(e).data("custom").hide();
        });
        triggerChange(this.name, this.value, false);
      });
      
      $customEditors.change(function() {
        var custom = $(this).data("custom").show();
        custom.focus().select();
        if (custom.val().length > 0) {
          custom.trigger('change');
        }
      });
    }
    
    function addBooleanEditor(id, value, metadata, $container)
    {
      var $editor = $("<input />").attr("id", id).attr("name", id).attr("type", "checkbox");
      $editor.attr("checked", value);
      $editor.change(function() {
        triggerChange(this.name, this.checked, false);
      });
      $container.find("label").append($editor);
    }

    function addStringEditor(id, value, metadata, $container)
    {
      var $editor = $("<input />").attr("id", id).attr("name", id).attr("type", "text");
      $editor.attr("value", value);
      $editor.change(function() {
        triggerChange(this.name, this.value, false);
      });
      $container.find("label").append($editor);
      return $editor;
    }
    
    function addLinksEditor(id, value, metadata, $container) 
    {
      var $editor = $("<span />").attr("id", id);
      $.each(metadata.links, function(key, value) {
        var $a = $("<a href='#'>" + key + "</a>").click(value).appendTo($editor);
        $editor.append("<span> </span>")
      });
      $container.find("label").append($editor);
    }
    
    function addRangeEditor(id, values, metadata, $container) 
    {
      var $lower = $("<input />").attr("id", metadata.lower).attr("name", metadata.lower).attr("type", "text").attr("class", "number rangeLower");
      var $upper = $("<input />").attr("id", metadata.upper).attr("name", metadata.upper).attr("type", "text").attr("class", "number rangeUpper");
      var lowerValue = values[metadata.lower];
      var upperValue = values[metadata.upper];
      if (metadata.valueSuffix) {
        lowerValue = lowerValue.substring(0, lowerValue.length - metadata.valueSuffix.length);
        upperValue = upperValue.substring(0, upperValue.length - metadata.valueSuffix.length);
      }
      $lower.val(lowerValue);
      $upper.val(upperValue);
      $lower.bind("change", function() {
        $(this).data("slider").slider("values", 0, this.value);
      });
      $upper.bind("change", function() {
        $(this).data("slider").slider("values", 1, this.value);
      });

      $container.find("label").append($lower);

      var $slider = $("<span class='slider'></span>").slider({
        min: metadata.min,
        max: metadata.max,
        range: true,
        step: metadata.step || (metadata.max - metadata.min) / 20,
        values: [ parseInt(lowerValue, 10), parseInt(upperValue, 10) ],
        slide: function(event, ui) {
          $lower.val(toFixed(ui.values[0], 2));
          $upper.val(toFixed(ui.values[1], 2));
          if (metadata.immediate) {
            trigger(ui, true);
          }
        },
        change: function(event, ui) {
          trigger(ui, false);
        }
      });
      $slider.insertAfter($lower);
      $container.find("label").after($upper);
      
      $lower.add($upper).data("slider", $slider);

      function trigger(ui, immediate) {
        var changes = { };

        // Resolve suffix value.
        changes[metadata.lower] = withSuffix(ui.values[0], metadata.valueSuffix);
        changes[metadata.upper] = withSuffix(ui.values[1], metadata.valueSuffix);
        triggerChange(changes, undefined, immediate);
      }
    }
    
    function addNumberEditor(id, value, metadata, $container) 
    {
      var $editor = $("<input />").attr("id", id).attr("name", id).attr("type", "text").attr("class", "number rangeLower");
      var internalValue = metadata.valueSuffix ? value.substring(0, value.length - metadata.valueSuffix.length) : value;
      $editor.attr("value", internalValue);
      $editor.change(function() { 
        $(this).data("slider").slider("value", this.value);
      });
      $container.find("label").append($editor);

      var $slider = $("<span class='slider'></span>").slider({
        min: metadata.min,
        max: metadata.max,
        step: metadata.step || (metadata.max - metadata.min) / 20,
        value: internalValue,
        slide: function(event, ui) {
          $editor.val(toFixed(ui.value, 2));
          if (metadata.immediate) {
            triggerChange(id, withSuffix(ui.value, metadata.valueSuffix), true);
          }
        },
        change: function(event, ui) {
          triggerChange(id, withSuffix(ui.value, metadata.valueSuffix), false);
        }
      });
      $slider.insertAfter($editor);
      $editor.data("slider", $slider);
    }

    function addColorEditor(id, value, metadata, $container) 
    {
      // Private utilities
      function setInputValue(input, hex, hsb) {
        if (input.is(".hsb")) {
          input.val(hsbToString(hsb));
        } else if (input.is(".css-rgba")) {
          input.val(toCssRgba(hexToRgba(hex), hsb.a));
        } else if (input.is(".css-hsla")) {
          input.val(toCssHsla(hsb));
        } else {
          var alpha = Math.round(hsb.a * 2.55).toString(16);
          if (alpha.length == 1) {
            alpha = "0" + alpha;
          }
          input.val(alpha + hex);
        }
        return input;
      }
      
      function stringToHsb(s) {
        var values = s.split(",");
        if (!values || values.length != 4) {
          return undefined;
        }
        return { a: parseFloat(values[0]) * 100, 
                 h: parseFloat(values[1]) * 360, 
                 s: parseFloat(values[2]) * 100, 
                 b: parseFloat(values[3]) * 100 };
      }

      function hexToRgba(s) {
        if (s.length != 8 && s.length != 6) {
          return undefined;
        }
        if (s.length == 6) {
          s = "00" + s;
        }
        return { a: parseInt(s.substring(0, 2), 16) / 2.55,
                 r: parseInt(s.substring(2, 4), 16),
                 g: parseInt(s.substring(4, 6), 16), 
                 b: parseInt(s.substring(6, 8), 16) };
      }

      function hsbToString(hsb) {
        return toFixed(hsb.a / 100.0, 2) + ", " +
               toFixed(hsb.h / 360.0, 2) + ", " + 
               toFixed(hsb.s / 100.0, 2) + ", " + 
               toFixed(hsb.b / 100.0, 2);
      }

      function toCssRgba(rgb, a) {
        return "rgba(" + rgb.r + ", " + rgb.g + ", " + rgb.b + ", " + toFixed(a / 100, 2) + ")";
      }

      function toCssHsla(hsb) {
        // Convert HSB to HSL
        hsb.s /= 100;
        hsb.b /= 100;
        var ll = (2 - hsb.s) * hsb.b;
        var ss = hsb.s * hsb.b;
        var div = (ll <= 1) ? (ll) : 2 - (ll);
        if (div != 0) {
          ss /= div;
        }
        ll /= 2;
        hsb.s *= 100;
        hsb.b *= 100;

        return "hsla(" + hsb.h + ", " + (ss * 100.0).toFixed(0) + "%, " + (ll * 100.0).toFixed(0) + "%, " + toFixed(hsb.a / 100, 2) + ")";
      }

      function inputToHsbOrRgb($editor, value) {
        // $.is() doesn't seeem to work on IE9 here
        var cssClass = $editor.attr("class");
        if (/hsb/.test(cssClass)) {
          return stringToHsb(value);
        } else if (/css-rgba/.test(cssClass)) {
          var vals = /rgba\(\s*([^,]+),\s*([^,]+),\s*([^,]+),\s*([^,]+)\s*\)/.exec(value);
          if (!vals || vals.length != 5) {
            return undefined;
          }
          return { r: parseFloat(vals[1]), g: parseFloat(vals[2]), b: parseFloat(vals[3]), a: parseFloat(vals[4]) * 100 };
        } else if (/css-hsla/.test(cssClass)) {
          var vals = /hsla\(\s*([^,]+),\s*([^,%]+)%,\s*([^,%]+)%,\s*([^,]+)\s*\)/.exec(value);
          if (!vals || vals.length != 5) {
            return undefined;
          }

          // Convert HSL to HSB
          var ss = parseFloat(vals[2]) / 100;
          var ll = parseFloat(vals[3]) / 100;
          ll *= 2;
          ss *= (ll <= 1) ? ll : 2 - ll;
          var res = { h: parseFloat(vals[1]), s: 100 * (2 * ss) / (ll + ss), b: 100 * (ll + ss) / 2, a: parseFloat(vals[4]) * 100 };
          return res;
        } else {
          return hexToRgba(value);
        }
      }


      // Build color editor
      var $editor = $("<input />").attr("id", id).attr("name", id).attr("value", value).attr("type", "text").attr("class", "color");
      if (metadata.model == "hsb") {
        $editor.addClass("hsb");
      }
      if (metadata.model == "css-rgba") {
        $editor.addClass("css-rgba");
      }
      if (metadata.model == "css-hsla") {
        $editor.addClass("css-hsla");
      }

      // Attach color picker
      
      $editor.ColorPicker({
        onChange: function(hsb, hex, rgb) {
          var picker = $(this).data("colorpicker");
          setInputValue($(picker.el), hex, hsb).trigger("colorchange", [ picker ]);
          if (metadata.immediate) {
            triggerChange(picker.el.name, picker.el.value, true);
          }
        },
        onSubmit: function(hsb, hex, rgb, el) {
          setInputValue($(el), hex, hsb).ColorPickerHide().trigger("colorchange");
          triggerChange(el.name, el.value, false);
        },
        onCancel: function(hsb, hex, rgb, el) {
          setInputValue($(el), hex, hsb).ColorPickerHide().trigger("colorchange");
        }
      }).bind("colorchange", function(e) {
        var picker = $("#" + $editor.data("colorpickerId")).data("colorpicker");
        var rgb = picker.colorRgb;
        
        // For IE, emulate RGBA with RGB+opacity. This is the same only if the element
        // is empty and shows only the background, which is the case here.
        $(this).next().children("span").eq(0)
          .css("background-color", "rgb(" + rgb.r + ", " + rgb.g + ", " + rgb.b + ")")
          .css('opacity', rgb.a / 100.0);
      }).keyup(function() {
        var $this = $(this);
        var color = inputToHsbOrRgb($editor, $this.val());
        if (color) {
          $this.ColorPickerSetColor(color, true).trigger("colorchange");
        }
      });
      var currentColor = inputToHsbOrRgb($editor, value);
      if (currentColor) {
        $editor.ColorPickerSetColor(currentColor);
      }

      // Append to the container
      $container.find("label").append($editor);
      $editor.after(" <span><span></span></span>").trigger("colorchange");
    }

    // A wrapper adding the synthetic fontSizeUnit property used only in the panel
    function transformFontSizeUnits(current, toSet) {
      if (!options.fontSizeProperties) {
        return;
      }
      if (typeof toSet.fontSizeUnit != 'undefined') {
        values.fontSizeUnit = toSet.fontSizeUnit;
        delete toSet.fontSizeUnit;
        $.each(options.fontSizeProperties, function(property) {
          var v = current[property];
          if (typeof v != 'undefined') {
            toSet[property] = v;
          }
        });
      }
      $.each(options.fontSizeProperties, function(property) {
        var v = toSet[property];
        if (typeof v != 'undefined') {
          if (typeof v == 'string') {
            v = v.replace(/[^\d]/, "");
          }
          toSet[property] = v + values.fontSizeUnit;
        }
      });
    }

    function toFixed(value, precision) {
      var power = Math.pow(10, precision || 0);
      return Math.round(value * power) / power;
    }

    function withSuffix(value, suffix) {
      return suffix ? value + suffix : value;
    }
  };

  /**
   * Font selector plugin
   * turns an ordinary input field into a list of web-safe fonts
   * Usage: $('select').fontSelector();
   *
   * Author     : James Carmichael
   * Website    : www.siteclick.co.uk
   * License    : MIT
   */
  $.fn.fontSelector = function (additionalFonts) {
    var fonts;
    
    fonts = [
      'Arial, Helvetica, sans-serif',
      'Courier New, Courier New, Courier, monospace',
      'Georgia, serif',
      'Impact, Charcoal, sans-serif',
      'Tahoma, Geneva, sans-serif',
      'Times New Roman, Times, serif',
      'Trebuchet MS, Helvetica, sans-serif',
      'Verdana, Geneva, sans-serif'];
    
    if (additionalFonts) {
      for (var i = 0; i < additionalFonts.length; i++) {
        fonts.push(additionalFonts[i]);
      }
      fonts.sort();
    }

    return this.each(function () {
      // Get input field
      var sel = this;

      // Add a ul to hold fonts
      var ul = $('<ul class="fontselector"></ul>');
      $('body').prepend(ul);
      $(ul).hide();

      $.each(fonts, function (i, item) {
        $(ul).append('<li><a href="#" class="font_' + i + '" style="font-family: ' + item + '">' + item.split(',')[0] + '</a></li>');
      });

      // Prevent real select from working
      $(sel).click(function (ev) {
        ev.preventDefault();
        ev.stopPropagation();

        // Show font list
        $(ul).show();

        // Position font list
        $(ul).css({ top: $(sel).offset().top + $(sel).height() + 4,
          left: $(sel).offset().left});

        // Blur field
        $(this).blur();
        return false;
      });

      $(ul).find('a').click(function () {
        var font = fonts[$(this).attr('class').split('_')[1]];
        $(ul).hide();
        $(sel).val(font).trigger("change");
        return false;
      });

    });
  }
  $("body").click(function(){
     $(".fontselector").hide();
  });
})(window.jQuery);
