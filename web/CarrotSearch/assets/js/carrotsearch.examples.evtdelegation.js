/**
 * Install event passthrough mechanism to simulate CSS pointer-events: none for IE.
 */
function eventPassthru($overlay) {
  if (!$.browser.msie) return;

  $overlay.on("mousemove mousedown mouseup", function(evt) {
    $overlay.hide();
    var target = document.elementFromPoint(evt.pageX, evt.pageY);
    $overlay.show();
    if (target) {
      var newEvent = document.createEvent("MouseEvents");
      newEvent.initMouseEvent(
        evt.type,
        evt.bubbles,
        evt.cancelable,
        evt.view,
        evt.detail,
        evt.screenX, evt.screenY,
        evt.clientX, evt.clientY,
        evt.ctrlKey, evt.altKey, evt.shiftKey, evt.metaKey,
        evt.button, evt.relatedTarget);
      target.dispatchEvent(newEvent);
      evt.stopImmediatePropagation();
    }
  });

  // Special case for IE10 and hammer.js:
  // - jquery doesn't support MSPointerDown so we work with the original event.
  // - hammer.js subscribes MSPointerUp/MSPointerUp to document.* but MSPointerDown to canvas (?)
  // - event initialization and dispatch is different for pointer events.
  if (navigator.msPointerEnabled) {
    $overlay.on("MSPointerDown", function(evt) {
      evt = evt.originalEvent;
      $overlay.hide();
      var target = document.elementFromPoint(evt.pageX, evt.pageY);
      $overlay.show();
      if (target) {
        var newEvent = document.createEvent("MSPointerEvent");
        newEvent.initPointerEvent(
          evt.type,
          evt.bubbles,
          evt.cancelable,
          evt.view,
          evt.detail,
          evt.screenX, evt.screenY,
          evt.clientX, evt.clientY,
          evt.ctrlKey, evt.altKey, evt.shiftKey, evt.metaKey,
          evt.button, evt.relatedTarget,
          evt.offsetX, evt.offsetY,
          evt.width, evt.height,
          evt.pressure,
          evt.rotation,
          evt.tiltX, evt.tiltY,
          evt.pointerId,
          evt.pointerType,
          evt.hwTimestamp,
          evt.isPrimary
        );
        target.dispatchEvent(newEvent);
        evt.stopImmediatePropagation();
      }
    });
  }
}