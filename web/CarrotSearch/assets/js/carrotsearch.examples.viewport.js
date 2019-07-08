
/** Set viewport size in such a way that CSS pixels correspond to physical pixels. */
function updateViewportDimensions() {
  var width, height, portrait = (window.orientation % 180) == 0;
  var iOs = /iPad|iPhone/.test(window.navigator.userAgent);
  var chrome = /Chrome/.test(window.navigator.userAgent);
  if (iOs) {
    width = portrait ? screen.width : screen.height;
  } else  {
    var pixelRatio = window.devicePixelRatio || 1;
    width = screen.width / pixelRatio;
  }
  var meta = document.getElementById("__viewport_meta");
  if (!meta) {
    meta = document.createElement("meta");
    meta.id = "__viewport_meta";
    meta.name = "viewport";
    document.getElementsByTagName('head')[0].appendChild(meta);
  }
  meta.setAttribute("content", "width=" + width + (iOs || chrome ? ", initial-scale=1" : ""));
}

updateViewportDimensions();
window.addEventListener("orientationchange", updateViewportDimensions);
