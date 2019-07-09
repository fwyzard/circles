
function fixed(value) {
  return Number.parseFloat(value).toFixed(1);
}

// return an object with the GET data from the current URL
//
// for example, if the URL is
//  http://myurl&data=value
// returns
//  { data: "value" }
function parseGetData() {
  var data = {};
  window.location.search.substr(1).split("&").forEach(function(value, index, array) {
    var [key, val] = value.split("=").map(decodeURIComponent);
    data[key] = (val == undefined) ? null : val;
  });
  return data;
}

// load JSON data from the given URL, and sets the target's dataObject to it
function loadJsonInto(target, attribute, url) {
  var xhttp = new XMLHttpRequest();
  xhttp.overrideMimeType("application/json");
  xhttp.open('GET', url);
  xhttp.onreadystatechange = function () {
    if (xhttp.readyState == 4) {
      target.set(attribute, JSON.parse(xhttp.responseText));
    }
  };
  xhttp.send(null);
}

function groupColorDecorator(options, properties, variables) {
  // customize only the top level groups' colours
  if (properties.level > 0)
    return;

  // use the group color defined in the dataset
  if ("color" in properties.group) {
    variables.groupColor = properties.group.color;
    variables.labelColor = "auto";
  }
}

function circlesVisibilityDecorator(group) {
  // hide the "other" groups
  return (group.label != "other");
}

function foamtreeVisibilityDecorator(options, properties, variables) {
  // hide the "other" groups
  if (properties.group.label == "other") {
    variables.groupLabelDrawn = false;
    variables.groupPolygonDrawn = false;
  }
}
