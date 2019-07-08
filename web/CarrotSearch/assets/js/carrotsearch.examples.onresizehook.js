
function installResizeHandlerFor(visualization, deferUpdateByMillis) {
  var fn = function() {
    var id = visualization.get("id"), element;
    if (id && (element = document.getElementById(id))) {
      visualization.resize();
    }
  };

  // Call fn at most once within a single minInterval.
  function defer(minInterval, fn) {
    var last;
    var deferId;
    return function() {
      var now = new Date().getTime();

      if (deferId) {
        window.clearTimeout(deferId);
        deferId = undefined;
      }

      if (!last || last + minInterval <= now) {
        last = now;
        fn();
      } else {
        deferId = window.setTimeout(arguments.callee, Math.min(1, minInterval / 5));
      }
    };
  }

  if (undefined === deferUpdateByMillis) {
    deferUpdateByMillis = 500;
  }

  window.addEventListener("resize", defer(deferUpdateByMillis, fn));
  window.addEventListener("orientationchange", defer(deferUpdateByMillis, fn));
}