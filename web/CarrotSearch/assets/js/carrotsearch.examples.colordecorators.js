
/**
 * Implements a custom color model based on a predefined palette.
 */
function paletteColorDecorator(baseColors) {
  /** Combine an array to HSLA color model. */
  function toCss3(hsl, alpha) {
    return "hsla(" +
      hsl[0] + "," +
      hsl[1] + "%," +
      hsl[2] + "%," +
      alpha + ")";
  }

  function recurse(group, hsl) {
    if (group.groups) {
      var max = group.groups.length - 1;
      var range = 20;
      var from = Math.max(0, hsl[2] - range / 2);
      if (from + range > 90) {
        from = Math.max(0, 90 - range);
      }

      // Cut saturation by a third, spread the lightness evenly.
      for (var i = 0; i <= max; i++) {
        group.groups[i].color = [
          hsl[0],
          hsl[1] / 3,
          Math.ceil(from + range * i / max)];
        recurse(group.groups[i], group.groups[i].color);
      }
    }
  }

  return function(opts, params, vars) {
    var opacity = 1;
    if (params.level == 0) {
      var baseHsl = baseColors[params.index % baseColors.length];
      vars.groupColor = toCss3(baseHsl, opacity);
      vars.labelColor = "auto";

      // Recursively assign the color to all the children.
      // Reuse model nodes for simplicity.
      recurse(params.group, baseHsl);
    } else {
      vars.groupColor = toCss3(params.group.color, opacity);
      vars.labelColor = "auto";
    }
  };
};
