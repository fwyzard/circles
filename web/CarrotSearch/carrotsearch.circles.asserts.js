
/**
 * Debugging assertions for Carrot Search Circles.
 */

(function() {
  /**
   * Attribute constraints.
   */
  var attributeDefinitions = function() {
    var args = {}

    // Special.
    args["dataObject"] = {
      type: "object",
      asserts: Or(IsObject(), IsNull(), IsUndefined())
    };

    args["imageData"] = {
      type: "string",
      asserts: ReadOnly()
    };

    args["times"] = {
      type: "object",
      asserts: ReadOnly()
    };

    args["layout"] = {
      type: "object",
      asserts: ReadOnly()
    };

    ([
      "selection",
      "open",
      "zoom"
    ]).forEach(function(key) {
        args[key] = {
          type: "mixed"
        }
      });

    // Unconstrained strings
    ([
      "attributionLogo",
      "attributionUrl",
      "groupFontFamily",
      "titleBarFontFamily"
    ]).forEach(function(key) {
        args[key] = {
          type: "string",
          asserts: Or(IsString(), IsNull(), IsUndefined())
        }
      });

    // Element identifier
    ([
      "id"
    ]).forEach(function(key) {
        args[key] = {
          type: "string",
          asserts: And(IsString(), IdentifiesExistingElement())
        };
      });

    // DOM element
    ([
      "element"
    ]).forEach(function(key) {
        args[key] = {
          type: "object",
          asserts: IsElement()
        };
      });

    // Numbers or percentages.
    ([
      "attributionPositionX",
      "attributionPositionY",
      "groupMinFontSize",
      "groupMaxFontSize",
      "titleBarMinFontSize",
      "titleBarMaxFontSize"
    ]).forEach(function(key) {
        args[key] = {
          type: "mixed",
          asserts: And(NotEmpty(), Or(IsNumeric(), Matches(/^[0-9]+%$/)))
        }
      });

    // Numbers, percentages or functions
    ([
      "centerx",
      "centery",
      "diameter"
    ]).forEach(function(key) {
        args[key] = {
          type: "mixed",
          asserts: And(NotEmpty(), Or(IsNumeric(), Matches(/^[0-9]+%$/), IsFunction()))
        }
      });

    // Booleans
    ([
      "supported",
      "logging",
      "textureMappingMesh",
      "showZeroWeightGroups",
      "groupHoverHierarchy",
      "captureMouseEvents"
    ]).forEach(function(key) {
        args[key] = {
          type: "boolean",
          asserts: IsBoolean()
        }
      });

    // enums
    args["rolloutAnimation"] = {
      type: "string",
      asserts: And(NotEmpty(), OneOf([
        "implode",
        "rollout",
        "tumbler",
        "fadein",
        "random"
      ]))
    };

    args["pullbackAnimation"] = {
      type: "string",
      asserts: And(NotEmpty(), OneOf([
        "explode",
        "rollin",
        "tumbler",
        "fadeout",
        "random"
      ]))
    };

    args["modelChangeAnimations"] = {
      type: "string",
      asserts: And(NotEmpty(), OneOf([
        "auto",
        "sequential",
        "parallel"
      ]))
    };

    args["titleBar"] = {
      type: "string",
      asserts: And(NotEmpty(), OneOf([
        "none",
        "inscribed",
        "topbottom",
        "top",
        "bottom"
      ]))
    };

    // CSS colors
    ([
      "backgroundColor",
      "groupOutlineColor",
      "rainbowStartColor",
      "rainbowEndColor",
      "labelDarkColor",
      "labelLightColor",
      "expanderOutlineColor",
      "expanderColor",
      "groupSelectionColor",
      "groupSelectionOutlineColor",
      "groupHoverColor",
      "groupHoverOutlineColor",
      "titleBarBackgroundColor",
      "titleBarTextColor",
      "zoomDecorationFillColor",
      "zoomDecorationStrokeColor"
    ]).forEach(function(key) {
        args[key] = {
          type: "string",
          asserts: And(NotEmpty(), CssColor())
        }
      });

    // Functions
    ([
      "groupColorDecorator",
      "groupLabelDecorator",
      "isGroupVisible",
      "ringShape",
      "attributionSize",
      "titleBarLabelDecorator"
    ]).forEach(function(key) {
        args[key] = {
          type: "function",
          asserts: IsFunction()
        }
      });

    // Callbacks
    ([
      "onModelChanged",
      "onRolloutStart",
      "onRolloutComplete",
      "onRedraw",
      "onLayout",
      "onGroupHover",
      "onGroupZoom",
      "onGroupOpenOrClose",
      "onGroupSelectionChanging",
      "onGroupSelectionChanged",
      "onGroupClick",
      "onGroupDoubleClick",
      "onBeforeZoom",
      "onBeforeSelection"
    ]).forEach(function(key) {
        args[key] = {
          type: "function | Array",
          asserts: Or(IsFunction(), IsArrayOfFunctions())
        }
      });

    // Numbers.
    ([
      "visibleGroupCount",
      "deferLabelRedraws",
      "labelRedrawFadeInTime",
      "textureOverlapFudge",
      "expanderOutlineWidth",
      "minAngularPadding",
      "minRadialPadding",
      "groupHoverOutlineWidth",
      "groupSelectionOutlineWidth",
      "groupOutlineWidth",
      "expandTime",
      "zoomTime",
      "pullbackTime",
      "rolloutTime",
      "updateTime",
      "attributionFadeOutTime",
      "ratioAspectSwap",
      "titleBarTextPaddingTopBottom",
      "titleBarTextPaddingLeftRight"
    ]).forEach(function(key) {
        args[key] = {
          type: "number",
          asserts: And(NotEmpty(), IsNumeric("[0,∞)"))
        }
      });

    ([
      "radialTextureStep",
      "angularTextureStep",
      "pixelRatio",
      "ringScaling"
    ]).forEach(function(key) {
        args[key] = {
          type: "number",
          asserts: And(NotEmpty(), IsNumeric("(0,∞)"))
        }
      });

    ([
      "minExpanderAngle",
      "angleStart",
      "expanderAngle"
    ]).forEach(function(key) {
        args[key] = {
          type: "number",
          asserts: And(NotEmpty(), IsNumeric("[0,360)"))
        }
      });

    ([
      "zoomedFraction",
      "labelColorThreshold",
      "noTexturingCurvature",
      "ratioAngularPadding",
      "ratioRadialPadding"
    ]).forEach(function(key) {
        args[key] = {
          type: "number",
          asserts: And(NotEmpty(), IsNumeric("[0,1]"))
        }
      });

    // unconstrained floats (undefined values not allowed)
    ([
      "angleWidth",
      "groupLinePadding",
      "groupLinePaddingRatio"
    ]).forEach(function(key) {
        args[key] = {
          type: "number",
          asserts: And(NotEmpty(), IsNumeric())
        }
      });

    // floats (undefined values allowed).
    args["attributionStayOnTime"] = {
      type: "number",
      asserts: Or(IsUndefined(), IsNumeric("[0,∞)"))
    };

    args["angleEnd"] = {
      type: "number",
      asserts: Or(IsUndefined(), IsNumeric("[0,360]"))
    };

    args["attributionFadeInTime"] = {
      type: "number",
      asserts: Or(IsUndefined(), IsNumeric("[0,5]"))
    };

    // API changes, 2.1.0
    ([
      "captureMouseEvents",
      "angleWidth",
      "isGroupVisible",
      "ringShape",
      "attributionSize",
      "ratioAspectSwap",
      "attributionFadeInTime"
    ]).forEach(function(key) {
        args[key].since = "2.1.0";
      });

    ([
      "angleEnd"
    ]).forEach(function(key) {
        args[key].deprecated = "2.1.0";
      });

    // API changes, 2.2.0
    ([
      "layout",
      "titleBar",
      "titleBarFontFamily",
      "titleBarMinFontSize",
      "titleBarMaxFontSize",
      "titleBarBackgroundColor",
      "titleBarTextColor",
      "titleBarTextPaddingLeftRight",
      "titleBarTextPaddingTopBottom",
      "titleBarLabelDecorator"
    ]).forEach(function(key) {
        args[key].since = "2.2.0";
      });

    // API changes, 2.3.0
    ([
      "zoomDecorationStrokeColor",
      "zoomDecorationFillColor"
    ]).forEach(function(key) {
        args[key].since = "2.3.0";
      });

    // API changes, 2.3.1
    ([
      "updateTime"
    ]).forEach(function(key) {
          args[key].since = "2.3.1";
        });

    // API changes, 2.3.7
    ([
      "groupLinePadding"
    ]).forEach(function(key) {
        args[key].deprecated = "2.3.7";
      });

    ([
      "groupLinePaddingRatio"
    ]).forEach(function(key) {
          args[key].since = "2.3.7";
        });

    return args;
  };



  /**
   * Constraint definitions.
   */

  function valueOf(v) {
    if (typeof v == "function") {
      return "[function]";
    } else {
      return "'" + v + "'";
    }
  }


  var NotEmpty = (function() {
    function NotEmpty() {
      if (this === window) return new NotEmpty();
    }

    NotEmpty.prototype.validate = function(value) {
      if ((typeof value == "undefined") || value == null || ("" + value) === "") {
        throw valueOf(value) + " is empty";
      }
    };

    NotEmpty.prototype.toString = function() {
      return "value is not empty"
    };

    return NotEmpty;
  })();


  var IsNull = (function() {
    function IsNull() {
      if (this === window) return new IsNull();
    }

    IsNull.prototype.validate = function(value) {
      if (value !== null) {
        throw valueOf(value) + " is not null";
      }
    };

    IsNull.prototype.toString = function() {
      return "value is null"
    };

    return IsNull;
  })();


  var IsUndefined = (function() {
    function IsUndefined() {
      if (this === window) return new IsUndefined();
    }

    IsUndefined.prototype.validate = function(value) {
      if (typeof value !== "undefined") {
        throw valueOf(value) + " is not undefined";
      }
    };

    IsUndefined.prototype.toString = function() {
      return "value is undefined"
    };

    return IsUndefined;
  })();


  var IsObject = (function() {
    function IsObject() {
      if (this === window) return new IsObject();
    }

    IsObject.prototype.validate = function(value) {
      if (value !== Object(value)) {
        throw valueOf(value) + " is not an object";
      }
    };

    IsObject.prototype.toString = function() {
      return "value is an object"
    };

    return IsObject;
  })();


  var ReadOnly = (function() {
    function ReadOnly() {
      if (this === window) return new ReadOnly();
    }

    ReadOnly.prototype.validate = function(value) {
      throw "attribute is read-only";
    };

    ReadOnly.prototype.toString = function() {
      return "attribute is read-only";
    };

    return ReadOnly;
  })();


  var IsNumeric = (function() {
    function IsNumeric(range) {
      if (this === window) return new IsNumeric(range);

      function inclusiveBracket(v) {
        switch (v) {
          case '[':
          case ']':
            return true;
          case '(':
          case ')':
            return false;
          default:
            throw "Unrecognized bracket op: " + v;
        }
      }

      function parseRange(v) {
        if (v == "∞")  { return Number.POSITIVE_INFINITY; }
        if (v == "-∞") { return Number.NEGATIVE_INFINITY; }
        if (isNaN(parseFloat(v))) throw "Not a number in range: " + v;
        return parseFloat(v);
      }

      // Simplistic range parsing.
      if (range) {
        // "(x,y)" => ["(", "0", ",", "∞", ")"]
        var comps = range.replace(/[\[\]\(\),]/g, " $& ").trim().split(/\s+/);
        this.left = parseRange(comps[1]);
        this.leftInclusive = inclusiveBracket(comps[0]);
        this.right = parseRange(comps[3]);
        this.rightInclusive = inclusiveBracket(comps[4]);
        this.range = range.replace("∞", "infinity");
      }
    }

    IsNumeric.prototype.validate = function(value) {
      if (isNaN(parseFloat(value))) {
        throw valueOf(value) + " is not a number";
      }

      if (!isFinite(value)) {
        throw valueOf(value) + " is not a finite number";
      }

      if (this.range) {
        if ((value < this.left  || (value == this.left  && !this.leftInclusive)) ||
            (value > this.right || (value == this.right && !this.rightInclusive))) {
          throw valueOf(value) + " is not within " + this.range;
        }
      }
    };

    IsNumeric.prototype.toString = function() {
      if (this.range) {
        return "value is a number in range " + this.range;
      } else {
        return "value is a number";
      }
    };

    return IsNumeric;
  })();


  var IsString = (function() {
    function IsString() {
      if (this === window) return new IsString();
    }

    IsString.prototype.validate = function(value) {
      var toString = Object.prototype.toString;
      if (value != null && toString.call(value) != "[object String]") {
        throw valueOf(value) + " is not a string";
      }
    };

    IsString.prototype.toString = function() {
      return "value is a string"
    };

    return IsString;
  })();


  var IsBoolean = (function() {
    function IsBoolean() {
      if (this === window) return new IsBoolean();
    }

    IsBoolean.prototype.validate = function(value) {
      if (value != null && typeof value !== "undefined") {
        if (value !== true && value !== false) {
          throw valueOf(value) + " is not a boolean";
        }
      }
    };

    IsBoolean.prototype.toString = function() {
      return "value is a boolean"
    };

    return IsBoolean;
  })();

  var IsFunction = (function() {
    function IsFunction() {
      if (this === window) return new IsFunction();
    }

    IsFunction.prototype.validate = function(value) {
      if (value != null && value != undefined) {
        if (typeof value !== "function") {
          throw valueOf(value) + " [" + (typeof value) + "] is not a function";
        }
      }
    };

    IsFunction.prototype.toString = function() {
      return "value is a function"
    };

    return IsFunction;
  })();

  var IsArrayOfFunctions = (function() {
    function IsArrayOfFunctions() {
      if (this === window) return new IsArrayOfFunctions();
    }

    IsArrayOfFunctions.prototype.validate = function(value) {
      if (value != null && value != undefined) {
        var arrayOfFunctions = Array.isArray(value);
        if (arrayOfFunctions) {
          value.forEach(function(key) {
            if (typeof key !== "function") {
              arrayOfFunctions = false;
            }
          });
        }
        if (!arrayOfFunctions) {
          throw valueOf(value) + " [" + (typeof value) + "] is not an array of functions";
        }
      }
    };

    IsArrayOfFunctions.prototype.toString = function() {
      return "value is an array of functions"
    };

    return IsArrayOfFunctions;
  })();

  var Matches = (function() {
    function Matches(regexp) {
      if (this === window) { return new Matches(regexp); }
      this.regexp = regexp;
    }

    Matches.prototype.validate = function(value) {
      if (!this.regexp.test(value)) throw valueOf(value) + " does not match " + this.regexp;
    };

    Matches.prototype.toString = function() {
      return "value matches " + this.regexp
    };

    return Matches;
  })();

  var IdentifiesExistingElement = (function() {
    function IdentifiesExistingElement() {
      if (this === window) return new IdentifiesExistingElement();
    }

    IdentifiesExistingElement.prototype.validate = function(value) {
      var element = document.getElementById(value);
      if (!element) {
        throw valueOf(value) + " is not an identifier of an existing DOM element";
      }
    };

    IdentifiesExistingElement.prototype.toString = function() {
      return "value is an identifier of an existing DOM element";
    };

    return IdentifiesExistingElement;
  })();


  var IsElement = (function() {
    function IsElement() {
      if (this === window) return new IsElement();
    }

    IsElement.prototype.validate = function(value) {
      if (!(value instanceof HTMLElement)) {
        throw valueOf(value) + " is not a DOM element";
      }
    };

    IsElement.prototype.toString = function() {
      return "value is a DOM element";
    };

    return IsElement;
  })();

  var CssColor = (function() {
    function CssColor() {
      if (this === window) { return new CssColor(); }
    }

    var predefinedColorsRegexp = new RegExp("^(AliceBlue|AntiqueWhite|Aqua|Aquamarine|Azure|Beige|Bisque|Black|BlanchedAlmond|Blue|BlueViolet|Brown|BurlyWood|CadetBlue|Chartreuse|Chocolate|Coral|CornflowerBlue|Cornsilk|Crimson|Cyan|DarkBlue|DarkCyan|DarkGoldenrod|DarkGray|DarkGreen|DarkKhaki|DarkMagenta|DarkOliveGreen|DarkOrange|DarkOrchid|DarkRed|DarkSalmon|DarkSeaGreen|DarkSlateBlue|DarkSlateGray|DarkTurquoise|DarkViolet|DeepPink|DeepSkyBlue|DimGray|DodgerBlue|FireBrick|FloralWhite|ForestGreen|Fuchsia|Gainsboro|GhostWhite|Gold|Goldenrod|Gray|Green|GreenYellow|Honeydew|HotPink|IndianRed|Indigo|Ivory|Khaki|Lavender|LavenderBlush|LawnGreen|LemonChiffon|LightBlue|LightCoral|LightCyan|LightGoldenrodYellow|LightGreen|LightGrey|LightPink|LightSalmon|LightSeaGreen|LightSkyBlue|LightSlateGray|LightSteelBlue|LightYellow|Lime|LimeGreen|Linen|Magenta|Maroon|MediumAquamarine|MediumBlue|MediumOrchid|MediumPurple|MediumSeaGreen|MediumSlateBlue|MediumSpringGreen|MediumTurquoise|MediumVioletRed|MidnightBlue|MintCream|MistyRose|Moccasin|NavajoWhite|Navy|OldLace|Olive|OliveDrab|Orange|OrangeRed|Orchid|PaleGoldenrod|PaleGreen|PaleTurquoise|PaleVioletRed|PapayaWhip|PeachPuff|Peru|Pink|Plum|PowderBlue|Purple|Red|RosyBrown|RoyalBlue|SaddleBrown|Salmon|SandyBrown|SeaGreen|Seashell|Sienna|Silver|SkyBlue|SlateBlue|SlateGray|Snow|SpringGreen|SteelBlue|Tan|Teal|Thistle|Tomato|Turquoise|Violet|Wheat|White|WhiteSmoke|Yellow|YellowGreen)$", "i");

    CssColor.prototype.validate = function(value) {
      if (/^rgba\(\s*([^,\s]+)\s*,\s*([^,\s]+)\s*,\s*([^,\s]+)\s*,\s*([^,\s]+)\s*\)$/.test(value)) {
        return;
      }
      if (/^rgb\(\s*([^,\s]+)\s*,\s*([^,\s]+)\s*,\s*([^,\s]+)\s*\)$/.test(value)) {
        return;
      }
      if (/^hsla\(\s*([^,\s]+)\s*,\s*([^,%\s]+)%\s*,\s*([^,\s%]+)%\s*,\s*([^,\s]+)\s*\)$/.test(value)) {
        return;
      }
      if (/^hsl\(\s*([^,\s]+)\s*,\s*([^,\s%]+)%\s*,\s*([^,\s%]+)%\s*\)$/.test(value)) {
        return;
      }
      if (/^#([0-9a-fA-F]{2})([0-9a-fA-F]{2})([0-9a-fA-F]{2})$/.test(value)) {
        return;
      }
      if (/^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/.test(value)) {
        return;
      }
      if (predefinedColorsRegexp.test(value)) {
        return;
      }

      throw valueOf(value) + " is not a CSS color specification";
    };

    CssColor.prototype.toString = function() {
      return "value is a CSS color";
    };

    return CssColor;
  })();


  var OneOf = (function() {
    function OneOf(values) {
      if (this === window) { return new OneOf(values); }
      this.values = values;
    }

    OneOf.prototype.validate = function(value) {
      if (this.values.indexOf(value) < 0) {
        throw valueOf(value) + " not one of [" + this.values.join(", ") + "]";
      }
    };

    OneOf.prototype.toString = function() {
      return "value one of [" + this.values.join(", ") + "]";
    };

    return OneOf;
  })();


  var Or = (function() {
    function Or() {
      if (this === window) { return Or.apply(new Or(), arguments); }
      this.clauses = Array.prototype.slice.call(arguments);
      return this;
    }

    Or.prototype.validate = function(value) {
      var vetoes = [];
      for (var i = 0; i < this.clauses.length; i++) {
        try {
          this.clauses[i].validate(value);
        } catch (e) {
          vetoes.push(e);
        }
      }

      if (vetoes.length == this.clauses.length) {
        throw vetoes.map(function(e) {return "(" + e + ")";}).join(" and ");
      }
    };

    Or.prototype.toString = function() {
      return this.clauses.map(function(e) {return "(" + e + ")";}).join(" or ");
    };

    return Or;
  })();


  var And = (function() {
    function And() {
      if (this === window) { return And.apply(new And(), arguments); }
      this.clauses = Array.prototype.slice.call(arguments);
      return this;
    }

    And.prototype.validate = function(value) {
      var vetoes = [];
      for (var i = 0; i < this.clauses.length; i++) {
        try {
          this.clauses[i].validate(value);
        } catch (e) {
          vetoes.push(e);
          break;  // fastpath, no need to evaluate further.
        }
      }

      if (vetoes.length != 0) {
        throw vetoes.map(function(e) {return "(" + e + ")";}).join(" and ");
      }
    };

    And.prototype.toString = function() {
      return this.clauses.map(function(e) {return "(" + e + ")";}).join(" and ");
    };

    return And;
  })();

  // Install attributes or defer until Circles is loaded.
  var args = attributeDefinitions();
  if (window["CarrotSearchCircles"]) {
    window["CarrotSearchCircles"]["attributes"] = args;
  } else {
    window["CarrotSearchCircles.attributes"] = args;
  }
})();

/*
 * Build information
 * -----------------
 * 
 * Build type    : Carrot Search Circles HTML5 (demo variant)
 * Build version : 2.3.7
 * Build number  : CIRCLES-SOFTWARE-DIST-72
 * Build time    : Dec 11, 2018
 * Built by      : bamboo
 * Build revision: master/cb11e9a8
 */