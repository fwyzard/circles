
var CarrotSearchDemoHelper = (/** @suppress {missingProperties} */ function() {
  var showLocalTipTimeout;

  function cookie(name, value, options) {
    if (typeof value != 'undefined') {
      options = options || {};
      if (value === null) {
        value = '';
      }
      if (typeof options.expires == "undefined") {
        options.expires = 365;
      }
      var expires = '';
      if (options.expires && (typeof options.expires == 'number' || options.expires.toUTCString)) {
        var date;
        if (typeof options.expires == 'number') {
          date = new Date();
          date.setTime(date.getTime() + (options.expires * 24 * 60 * 60 * 1000));
        } else {
          date = options.expires;
        }
        expires = '; expires=' + date.toUTCString();
      }
      var path = options.path ? '; path=' + (options.path) : '';
      var domain = options.domain ? '; domain=' + (options.domain) : '';
      var secure = options.secure ? '; secure' : '';
      document.cookie = [name, '=', encodeURIComponent(value), expires, path, domain, secure].join('');
    } else {
      var cookieValue = null;
      if (document.cookie && document.cookie != '') {
        var cookies = document.cookie.split(';');
        for (var i = 0; i < cookies.length; i++) {
          var cookie = (cookies[i] || "").replace( /^\s+|\s+$/g, "" );
          if (cookie.substring(0, name.length + 1) == (name + '=')) {
            cookieValue = decodeURIComponent(cookie.substring(name.length + 1));
            break;
          }
        }
      }
      return cookieValue;
    }
  }

  function tip(id, show) {
    document.getElementById(id).style.display = show ? "block" : "none";
  }

  function writeDiv(id, content) {
    document.write('<div id="' + id + '">');
    document.write(content);
    document.write('</div>');
  }

  function tipOnLoad(success) {
    if (!success) {
      document.getElementById("alt").style.display = "block";
      window.clearTimeout(showLocalTipTimeout);
    }
  }

  function tipOnInitialized() {
    window.clearTimeout(showLocalTipTimeout);
  }

  // IE is crappy enough to always keep the noscript element itself, only the content is removed if JS is enabled.
  var ns = document.getElementsByTagName("noscript");
  for (var i = 0; i < ns.length; i++) {
    if (typeof ns[i].removeNode != 'undefined') {
      ns[i].removeNode(true);
    }
  }

  var _gaq = _gaq || [];
  _gaq.push(['_setAccount', 'UA-317750-2']);
  _gaq.push(['_trackPageview']);

  (function() {
    var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;
    ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
    (document.getElementsByTagName('head')[0] || document.getElementsByTagName('body')[0]).appendChild(ga);
  })();

  // Export public members
  return {
    cookie: cookie,
    tip: tip,
    writeDiv: writeDiv,
    tipOnLoad: tipOnLoad,
    tipOnInitialized: tipOnInitialized,
    loading: (function() {
      var timeout;
      return function(show) {
        if (show) {
          timeout = window.setTimeout(function() {
            showhide(true);
          }, 400);
        } else {
          window.clearTimeout(timeout);
          showhide(false);
        }

        function showhide(s) {
          var overlay = document.getElementById("overlay");
          var load = document.getElementById("loading");

          setClasses(s ? "" : "transparent");

          // Support for transitionend is not universal, so let's hack with timeouts
          if (!s) {
            setTimeout(function() {
              setClasses("hidden transparent");
            }, 1000);
          }

          function setClasses(cssClass) {
            overlay.setAttribute("class", cssClass);
            load.setAttribute("class", cssClass);
          }
        }
      }
    })()
  };
})();

CarrotSearchDemoHelper = /** @suppress {missingProperties} */ function(helper) {
  if (!window.supported) {
    return;
  }

  var openShownCookieName = "carrotsearch.circles.openShown";
  var closeShownCookieName = "carrotsearch.circles.closeShown";
  var openShown = (helper.cookie(openShownCookieName) || "false") == "true";
  var closeShown = ((helper.cookie(closeShownCookieName) || "false") == "true") && openShown; // prevent from illegal state
  var android = navigator.userAgent.match(/Android/i);
  var mobile = navigator.userAgent.match(/iPhone|iPad|Android/i);

  function tipOnHover(group) {
    if (openShown) { return; }
    helper.tip("open-group-tip", true);
  }

  function tipOnGroupOpenOrClose(info) {
    if (info.open && !closeShown) {
      helper.tip("open-group-tip", false);
      helper.tip("close-group-tip", true);
      openShown = true;
      helper.cookie(openShownCookieName, true);
    } else {
      helper.tip("close-group-tip", false);
      closeShown = true;
      helper.cookie(closeShownCookieName, true);
    }
  }

  function loadScript(src, onLoaded) {
    var tag = document.createElement('script');
    tag.type = 'text/javascript';
    tag.async = true;
    tag.src = src;
    tag.onload = function() {
      onLoaded();
    };
    (document.getElementsByTagName('head')[0] || document.getElementsByTagName('body')[0]).appendChild(tag);
  }

  function replaceBodyCssClass(from, to) {
    var e = document.getElementsByTagName("body")[0];
    var current = e.getAttribute("class");
    var re = new RegExp(from);
    e.setAttribute("class", current.replace(re, to));
  }

  function orientation() {
    var ori = (window.orientation == 0 || window.orientation == 180) ? "portrait" : "landscape";
    replaceBodyCssClass("(landscape|portrait|$)", " " + ori);
    if (!android) {
      window.circles.resize();
    }
  }

  helper.writeDiv("open-group-tip", '<b>Tip</b>: to browse subgroups, ' +
    (mobile ? 'tap-and-hold, double-tap or pinch-out the group' : 'double click, Shift+click or click-and-hold the group'));
  helper.writeDiv("close-group-tip", '<b>Tip</b>: return to the parent group, ' +
    (mobile ? 'pinch-in the group' : 'Shift+Ctrl+click the group or press Esc'));

  if (window.circles) {
    window.circles.set({
      onGroupHover: tipOnHover,
      onGroupOpenOrClose: tipOnGroupOpenOrClose
    });
  }

  if ("onorientationchange" in window) {
    window.addEventListener("orientationchange", orientation, false);
  } else {
    window.addEventListener("resize", (function() {
      var timeout;
      return function() {
        window.clearTimeout(timeout);
        timeout = window.setTimeout(function() {
          if (window.circles) {
            window.circles.resize();
          }
        }, 330);
      };
    })(), false);
  }

  return helper;
}(CarrotSearchDemoHelper);
