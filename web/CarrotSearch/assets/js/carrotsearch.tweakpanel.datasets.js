/*
 * Carrot Search Visualization datasets UI
 *
 * Copyright 2002-2010, Carrot Search s.c.
 * 
 * This file is licensed under Apache License 2.0
 * http://www.apache.org/licenses/LICENSE-2.0
 */
/**
 * @suppress {missingProperties|checkTypes}
 */
(function($) {
  $.fn.datasets = function(params) {
    var defaults = {
      legend: "Data set",
      datasets: { },
      advanced: true,
      onChange: function() { }
    };
    
    var options = $.extend({}, defaults, params);
    var $container = this;

    // Fieldset with all data sets
    var $fieldset = $("<fieldset />");
    $fieldset.append($("<legend />").text(options.legend));

    $container.append($fieldset);

    modelDataAvailable = function(model, info) {
      triggerChange(model, 'json');
    }

    // Add simple data sets
    var $datasets = $("<ul id='data-sets' />");
    for (var datasetGroupId in options.datasets) {
      var datasetGroup = options.datasets[datasetGroupId];
      
      var $dataset = $("<li class='simple' />");
      $dataset.append(datasetGroup.label);
      
      var $urls = $("<ul />");
      for (var datasetUrl in datasetGroup.urls) {
        var $link = $("<a />").attr("href", datasetUrl).text(datasetGroup.urls[datasetUrl]);
        $link.click(function() {
          var type = /\.jsonp$/.test(this.href) ? "json" : "url";
          if (type == "json") {
            if (options.onLoadingStart) {
              options.onLoadingStart.apply($container);
            }

            var newScript = document.createElement('script');
            newScript.src = this.href;
            newScript.type = 'text/javascript';
            newScript.async = false;
            $("body").get(0).appendChild(newScript);
          } else {
            triggerChange(this.href, type);
          }
          return false;
        });
        $urls.append($link.wrap("<li />").parent());
      }
      
      $dataset.append($urls);
      $datasets.append($dataset);
    }
    $fieldset.append($datasets);

    // Add advanced data set trigger links
    var $triggers = $("\
      <li id='data-sets-advanced'>" +
        (options.controls.liveSearch ? "<a href='#live-search-results'>Live search results &gt;</a>" : "") +
        (options.controls.xmlFromUrl ? "<a href='#from-url'>Load XML from a URL &gt;</a>" : "") +
        (options.controls.pasteXml ? "<a href='#from-xml'>Paste XML &gt;</a>" : "") +
        (options.controls.pasteJson ? "<a href='#from-json'>Paste JSON &gt;</a>" : "") +
      "</li>").appendTo($datasets);

    if (options.controls.liveSearch) {
      var $liveSearch = $("\
        <li id='live-search-results'>\
          <ul>\
            <li>\
              <label><span>Query</span> <input id='live-search-results-query' type='text' value='salsa' /></label>\
            </li>\
          <li>\
            <label>\
              <span>Fetch</span>\
              <select id='live-search-results-results'>\
                <option value='50'>50 hits</option>\
                <option value='100'>100 hits</option>\
                <option value='150' selected='selected'>150 hits</option>\
                <option value='200'>200 hits</option>\
              </select>\
            </label>\
            <label>\
              <span>Cluster</span> \
              <select id='live-search-results-algorithm'>\
                <option value='lingo3g'>by topic with Lingo3G</option>\
                <option value='url'>by URL</option>\
              </select>\
            </label>\
            <input type='button' value='Load' />\
          </li>\
        </ul>\
      </li>").hide().appendTo($datasets);

      $liveSearch.find(":button").click(function () {
        var url = "http://search.carrotsearch.com/carrot2-webapp/xml?type=CARROT2" +
          "&query=" + encodeURIComponent($liveSearch.find("#live-search-results-query").val()) +
          "&results=" + encodeURIComponent($liveSearch.find("#live-search-results-results").val()) +
          "&algorithm=" + encodeURIComponent($liveSearch.find("#live-search-results-algorithm").val());
        triggerChange(url, 'url');
      });
      $liveSearch.find(":input").not(":button").keydown(submitControlOnEnter);
    }

    if (options.controls.xmlFromUrl) {
      var $fromUrl = $("\
        <li id='from-url'>\
          <ul>\
            <li>\
              <label style='float:left'>\
                <span>URL</span>\
                <input type='text' name='url' value='http://download.carrotsearch.com/clustering.xml' />\
              </label>\
              <input type='button' value='Load' />\
            </li>\
            <li><small>The URL must return data in the \
              <a href='http://download.carrot2.org/head/manual/#section.architecture.output-xml' target='_blank'>Carrot2 clusters XML format</a>.\
              <br />You will also need to \
              <a href='http://kb2.adobe.com/cps/142/tn_14213.html' target='_blank'>set up <tt>crossdomain.xml</tt></a> on the domain that serves your XML data.\
            </small></li>\
          </ul>\
        </li>").hide().appendTo($datasets);

      $fromUrl.find(":button").click(function () {
        triggerChange($fromUrl.find("input[name='url']").val(), 'url');
      });
      $fromUrl.find(":input").not(":button").keydown(submitControlOnEnter);
    }

    if (options.controls.pasteXml) {
      var $fromXml = $("\
        <li id='from-xml'>\
          <ul>\
            <li>\
              <label>\
                <span>XML</span>\
                <textarea name='xml'>\
  <searchresult>\n\
    <query>test data</query>\n\
    <document id='0'><title>Title 0</title></document>\n\
    <document id='1'><title>Title 1</title></document>\n\
    <group id='0'>\n\
      <title><phrase>Group 0</phrase></title>\n\
      <document refid='0' />\n\
    </group>\n\
    <group id='1'>\n\
      <title><phrase>Group 1</phrase></title>\n\
      <document refid='0' />\n\
     <document refid='1' />\n\
    </group>\n\
  </searchresult>\
                </textarea>\
              </label>\
              <br />\
              <input type='button' value='Load' />\
              <small>XML must be in <a href='http://download.carrot2.org/head/manual/#section.architecture.output-xml' target='_blank'>Carrot2 clusters XML format</a>.</small>\
            </li>\
          </ul>\
        </li>").hide().appendTo($datasets);

      $fromXml.find(":button").click(function () {
        triggerChange($fromXml.find("textarea[name='xml']").val(), 'xml');
      });
    }

    if (options.controls.pasteJson) {
      var $fromJson = $('\
        <li id="from-json">\
          <ul>\
            <li>\
              <label>\
                <span>JSON</span>\
                <textarea name="json">{"groups": [\n\
    { "label": "Group 0", "weight": 3 },\n\
    { "label": "Group 1", "weight": 10 },\n\
    { "label": "Group 2", "weight": 5.5, "groups": [\n\
      { "label": "Group 2.1", "weight": 20 },\n\
      { "label": "Group 2.2", "weight": 0 }\n\
    ]}\n\
  ]}</textarea>\
              </label>\
              <br />\
              <input type="button" value="Load" />\
              <small>Use proper JSON notation, see <a href="api.html#option-dataObject"><tt>dataObject</tt> docs</a> for format</small>\
            </li>\
          </ul>\
        </li>').hide().appendTo($datasets);

      $fromJson.find(":button").click(function () {
        triggerChange(window.JSON.parse($fromJson.find("textarea[name='json']").val()), 'json');
      });
    }

    // Showing and hiding of advanced controls
    var advancedDataSetTriggers = $triggers.find("a");
    var advancedDataSetControls = $.map(advancedDataSetTriggers, function(e) {
      return getControlForTrigger(e).get(0);
    });
    advancedDataSetTriggers.click(function() { 
      var control = getControlForTrigger(this);
      $(advancedDataSetControls).not(control).slideUp();
      advancedDataSetTriggers.not(this).removeClass("active");
      control.slideToggle(function() { $(this).find(":input:eq(0)").focus().select(); });
      $(this).toggleClass("active");
      return false;
    });

    return this;
    

    // Private utility functions
    function triggerChange(dataset, type) {
      options.onChange.apply($container, [dataset, type]);
    }
    
    function getControlForTrigger(e) {
      return $("#" + e.href.substring(e.href.indexOf("#") + 1), $datasets);
    }
    
    function submitControlOnEnter(ev) {
      var pressedKey = ev.charCode || ev.keyCode || -1;
      if (pressedKey == 13) {
        $(this).parents("li[id]").find(":button").trigger("click");
      }
    }
  };
})(window.jQuery);
