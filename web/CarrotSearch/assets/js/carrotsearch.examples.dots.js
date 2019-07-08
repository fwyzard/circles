/**
 * The "documents-in-cluster" highlight component similar to the built-in functionality
 * in Flash version of Circles.
 *
 * Requires jquery (to simplify the code a bit). See the dots.html example and modify
 * to your use case.
 */

var DotsComponent = function(visualization, $svgElement) {
  var _vis = visualization,
    $svg = $svgElement;

  // map: document index in _ids => $svgDotElement
  var _indexToDot = undefined;

  // Current hover and selection (groups from the model).
  var _hover      = undefined,
    _selection  = undefined;

  // An array of document identifiers.
  var _ids        = undefined;

  // Dot radius and spacing.
  var _dotRadius  = 6,
    _dotSpacing = 3;

  // Internal helper functions.
  var self = this;

  function addListener(event, fn) {
    _vis.set(event, _vis.get(event).concat(fn));
  }

  function isUndefined(v) {
    return jQuery.type(v) === "undefined";
  }

  function addHighlightClasses(highlightClasses, clazz, groups) {
    if (isUndefined(groups)) {
      return;
    }

    var highlightMarks = {};
    var remaining = groups.concat([]);

    while (remaining.length > 0) {
      var g = remaining.pop();

      if (g.documents) {
        g.documents.forEach(function(o) {
          highlightMarks[o] = true;
        });
      }

      if (g.groups) {
        // we assume g.groups is smallish, so this will work.
        Array.prototype.push.apply(remaining, g.groups);
      }
    }

    for (var key in highlightMarks) {
      if (highlightClasses.hasOwnProperty(key)) {
        highlightClasses[key] = highlightClasses[key] + " " + clazz;
      } else {
        highlightClasses[key] = clazz;
      }
    }
  }

  /**
   * Recreate all the SVG dots. May be a costly call.
   */
  self.layout = function() {
    $svg.empty();

    _indexToDot = undefined;
    if (isUndefined(_ids)) {
      return;
    }

    var clientWidth  = Math.floor($svg.width());
    var clientHeight = Math.floor($svg.height());

    var dotCell = _dotRadius * 2 + _dotSpacing;
    var columns = Math.floor(clientWidth  / dotCell);
    var rows    = Math.floor(clientHeight / dotCell);

    var xo = Math.floor(dotCell / 2);
    var yo = Math.floor(dotCell / 2);

    // SVG needs to be created with proper namespace, so it is a bit more explicit
    // (can't use jquery directly without hacks).
    var svg_g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
    svg_g.setAttribute("transform", 'translate(' + xo + "," + yo + ')');

    var max;
    if (_ids.length <= rows * columns) {
      max = _ids.length;
    } else {
      max = rows * columns - 1;
    }

    if (max <= 0) {
      return;
    }

    // Create svg dots.
    var _svgDots = [];
    _indexToDot = [];
    var id = 0,
      r  = 0,
      c  = 0;
    for (; id < max; id++) {
      var svg_c = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
      svg_c.setAttribute("cx", dotCell * c);
      svg_c.setAttribute("cy", dotCell * r);
      svg_c.setAttribute("r", _dotRadius);
      svg_c.setAttribute("class", "dot");
      svg_c.setAttribute("data-index", id);
      svg_g.appendChild(svg_c);

      if (++c == columns) {
        r++; c = 0;
      }

    }

    // Append a small ellipsis marker if needed.
    if (id != _ids.length) {
      var svg_r = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
      svg_r.setAttribute("x", dotCell * c - _dotRadius / 2);
      svg_r.setAttribute("y", dotCell * r - _dotRadius / 2);
      svg_r.setAttribute("width",  _dotRadius);
      svg_r.setAttribute("height", _dotRadius);
      svg_r.setAttribute("class", "ellipsis");
      svg_g.appendChild(svg_r);
    }

    var _svg = $svg.get(0);
    _svg.appendChild(svg_g);

    // Attach listener handlers.
    var $dots = $svg.find("circle.dot");
    var eventMapping = {
      "mouseover": "documentOver",
      "mouseout" : "documentOut",
      "click"    : "documentClick"
    };
    $dots.on("mouseover mouseout click", function(e) {
      var index = $(e.target).attr("data-index");
      $svg.trigger(eventMapping[e.type], {
        id: _ids[index]
      });
    });

    // Save dot references.
    $dots.each(function(i, e) {
      var $e = $(e);
      var index = $e.attr("data-index");
      _indexToDot[index] = $e;
    });

    self.updateHighlights();
  };

  /**
   * Update highlights after selection or hover has changed.
   */
  self.updateHighlights = function() {
    // Reset class.
    var $dots = $svg.find("circle.dot");
    $dots.attr("class", "dot");

    if (isUndefined(_indexToDot) ||
      isUndefined(_ids)) {
      return;
    }

    // Collect currently highlighted document IDs.
    // map: document ID => highlight classes string
    var highlights = {};
    addHighlightClasses(highlights, "groupselection", _selection);
    addHighlightClasses(highlights, "grouphover",     _hover);

    for (var i = 0; i < _indexToDot.length; i++) {
      var $e = _indexToDot[i];
      var index = $e.attr("data-index");

      if (highlights.hasOwnProperty(_ids[index])) {
        $e.attr("class", "dot " + highlights[_ids[index]]);
      }
    }
  };

  /**
   * Refresh data structures for a new data model in the visualization.
   */
  self.refreshModel = function() {
    _selection =  _hover = undefined;

    var model = _vis.get("dataObject");
    if (model && Array.isArray(model.documents)) {
      _ids = model.documents.map(function(e) {
        return e.id;
      });
    } else {
      _ids = undefined;
    }

    self.layout();
  };

  // Install listeners.
  addListener("onLayout", self.layout);
  addListener("onModelChanged", self.refreshModel);

  addListener("onGroupSelectionChanged", function(g) {
    _selection = g.groups.length == 0 ? undefined : g.groups;
    self.updateHighlights();
  });

  addListener("onGroupHover", function(g) {
    _hover = (g.group == null ? undefined : [g.group]);
    self.updateHighlights();
  });

  // Reset the initial model.
  self.refreshModel();
};
