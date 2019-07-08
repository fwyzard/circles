
/**
 * Iterate over all groups in a model.
 */
function forAllGroups(group, fn) {
  fn(group);
  if (group.groups) {
    for (var i = 0; i < group.groups.length; i++) {
      forAllGroups(group.groups[i], fn);
    }
  }
  return group;
}

/**
 * Assign unique ID to each group in a model and create pointers to
 * parent groups.
 */
function assignIds(model) {
  var id = 0;
  return forAllGroups(model, function(group) {
    if (group.id === undefined) group.id = id++;
    if (group.groups) {
      for (var i = 0; i < group.groups.length; i++) {
        group.groups[i].parent = group;
      }
    }
  });
}