
/*
 * Utility functions and a data model that showcases some
 * of the visualization features.
 */

/** Invoke a group's callback handler if any. */
function clickOn(id, model) {
  var match;
  if (!model) model = circles.get("dataObject");
  forAllGroups(model, function(group) {
    if (group.id === id) {
      match = group;
    }
  });
  if (match.onClick) match.onClick.call(match);
}

/**
 * Rotate options to the handler on each call.
 */
function rotateOptions(options, fn) {
  return function() {
    fn.call(this, $.isPlainObject(options[0]) ? $.extend({}, options[0]) : options[0]);
    options.push(options.shift());
  };
}

/**
 * Adjust selection to just a given group (disable siblings).
 */
function siblingSelection(circles, group) {
  var gid = group.id;
  var gids = $(group.parent.groups).map(function(i, ob) {
    return ob.id;
  }).toArray();
  circles.set("selection", {selected: false, groups: gids});
  circles.set("selection", gid);
}

/**
 * Toggles the zoom of the current group.
 */
function toggleZoom() {
  circles.set("zoom", {zoomed: !(this.zoomed || false), groups: [this.id]});
}

/**
 * Reload the same data model. We need to reset to null first otherwise a
 * no-change in value would be ignored.
 */
function reloadModel() {
  var model = circles.get("dataObject");
  circles.set("dataObject", null);
  circles.set("dataObject", model);
}

/**
 * Parts of our feature model.
 * @type {Object}
 */
window.featureModel = {};

/**
 * Showcase transitions.
 */
featureModel.transitions = {
  label: "Transitions",
  onClick: toggleZoom,
  groups: [
    { id: "explode",
      label: "Explode",
      onClick: function() {
        siblingSelection(circles, this);
        circles.set({
          rolloutAnimation: "implode",
          pullbackAnimation: "explode",
          rolloutTime: 2,
          pullbackTime: 1
        });
        reloadModel();
      }
    },
    { label: "Tumble",
      onClick: function() {
        siblingSelection(circles, this);
        circles.set({
          rolloutAnimation: "tumbler",
          pullbackAnimation: "tumbler",
          rolloutTime: 2,
          pullbackTime: 1
        });
        reloadModel();
      }
    },
    { label: "Roll",
      onClick: function() {
        siblingSelection(circles, this);
        circles.set({
          rolloutAnimation: "rollout",
          pullbackAnimation: "rollin",
          rolloutTime: 2,
          pullbackTime: 1
        });
        reloadModel();
      }
    },
    { label: "Fade",
      onClick: function() {
        siblingSelection(circles, this);
        var parallelism = circles.get("modelChangeAnimations");
        circles.set({
          rolloutAnimation: "fadein",
          pullbackAnimation: "fadeout",
          rolloutTime: 1,
          pullbackTime: 1,
          modelChangeAnimations: "sequential"
        });
        reloadModel();
        circles.set({modelChangeAnimations: parallelism});
      }
    }
  ]
};

/**
 * Miscellaneous features.
 */
featureModel.misc = {
  id: "misc-parent",
  label: "Miscellaneous",
  onClick: toggleZoom,
  groups: [
    { label: "Expanders",
      onClick: rotateOptions([2, 0], function(current) {
        circles.set({
          visibleGroupCount: current,
          expanderAngle: "5"
        })
      })
    },
    { id: "misc-zoom",
      label: "Zoom",
      onClick: rotateOptions([true, false], function(current) {
        circles.set("zoom", { groups: ["misc-zoom", "misc-parent"], zoomed: current });
      })
    },
    { id: "position",
      label: "Shape",
      onClick: rotateOptions([
        {
          angleStart: 0,
          angleWidth: 360,
          centerx: "50%",
          centery: function(spec) {
            return spec.height - Math.min(spec.width, spec.height) / 2;
          },
          diameter: "99%",
          attributionPositionX: "1%",
          attributionPositionY: "99%"
        },
        {
          angleStart: 180,
          angleWidth: 180,
          centerx: "50%",
          centery: "100%",
          diameter: function(spec) {
            return Math.min(spec.width, spec.height * 2);
          },
          attributionPositionX: "99%",
          attributionPositionY: "1%"
        },
        {
          angleStart: 180,
          angleWidth: -180,
          centerx: "50%",
          centery: "0%",
          diameter: function(spec) {
            return Math.min(spec.width, spec.height * 2);
          },
          attributionPositionX: "1%",
          attributionPositionY: "99%"
        },
        {
          angleStart: 180,
          angleWidth: 90,
          centerx: "100%",
          centery: "100%",
          diameter: function(spec) {
            return 2 * Math.min(spec.width, spec.height);
          },
          attributionPositionX: "99%",
          attributionPositionY: "97%"
        }
      ], function(current) {
        circles.set(current);
      })
    }
  ]
};

/**
 * Font variations and styles.
 */
featureModel.fonts = {
  label: "Fonts",
  onClick: toggleZoom,
  groups: [
    { label: "Size",
      onClick: rotateOptions([
        {
          label: "small",
          groupMinFontSize: "15",
          groupMaxFontSize: "15"
        },
        {
          label: "medium",
          groupMinFontSize: "5",
          groupMaxFontSize: "25"
        },
        {
          label: "large",
          groupMinFontSize: "15",
          groupMaxFontSize: "50"
        }
      ], function(current) {
        this.label = "[" + current.label + "]";
        circles.set(current);
      })
    },
    { label: "Family",
      onClick: rotateOptions([
        {
          familyName: "Courier",
          groupFontFamily: "Courier New, Courier New, Courier, monospace"
        },
        {
          familyName: "Georgia",
          groupFontFamily: "Georgia, serif"
        },
        {
          familyName: "Yanone-Kaffeesatz",
          groupFontFamily: "Yanone Kaffeesatz"
        },
        {
          familyName: "Open-Sans",
          groupFontFamily: "Open Sans Condensed"
        }
      ], function(current) {
        this.label = "[" + current.familyName + "]";
        circles.set(current);
      })
    }
  ]
};

/**
 * Color model features.
 */
featureModel.colors = {
  label: "Colors",
  onClick: toggleZoom,
  groups: [
    { id: "rainbow",
      label: "Rainbow",
      onClick: function(e) {
        siblingSelection(circles, this);
        circles.set({
          groupOutlineWidth: 1,
          groupColorDecorator: null});
      }
    },
    { id: "candy",
      label: "Candy store",
      onClick: function(e) {
        siblingSelection(circles, this);
        circles.set({
          groupOutlineWidth: 1,
          groupColorDecorator: paletteColorDecorator([
            [  4.68,  90.59, 83.33],
            [207.50,  46.15, 79.61],
            [108.95,  48.72, 84.71],
            [285.60,  31.65, 84.51],
            [ 34.77,  97.78, 82.35],
            [ 60.00, 100.00, 90.00],
            [ 40.50,  43.48, 81.96],
            [329.14,  89.74, 92.35]])
        });
      }
    },
    { id: "zebra",
      label: "Zebra",
      onClick: function(e) {
        siblingSelection(circles, this);
        circles.set({
          groupOutlineWidth: 3,
          groupOutlineColor: "#000",
          groupColorDecorator: function(options, params, variables) {
            var even = ((params.index & 1) === 0);
            var c1 = "rgba(80,80,80,.8)";
            var c2 = "rgba(255,255,255,.8)";
            variables.labelColor = (even ? c1 : c2);
            variables.groupColor = (even ? c2 : c1);
          }});
      }
    }
  ]
};

