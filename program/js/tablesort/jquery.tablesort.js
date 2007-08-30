// usage : $("table").makeTableSortable(options);
//         see the default options below 
// 
// changelog
// 
// Version 0.1
// initial version
//
// Version 0.1.1
// bugfix - return jQuery object for chaining
//

(function($){
  var defaults = {
    // arguments : (/* tableObject */ obj, 
    //              /* int */         sortedColumn, 
    //              /* boolean */     ascending)
    afterSortCallback : null,
    // a number column
    addNumberColumn: false, 
    numberColumnClass: "number-column",
    // a checkbox column 
    addSelectorColumn: false,
    selectorColumnClass: "selector-column",
    // arguments : (/* boolean */        checked, 
    //              /* tableRowObject */ obj)
    selectorCallback: null,

    columnAscendingClass: "column-ascending",
    columnDescendingClass: "column-descending",
    // Whether to append an image to the <th> to show if the
    // column is sorted
    addIndicatorImages: true,
    indicatorImages: {
      nosort: "1x1.gif", 
      ascending: "asc.png", 
      descending: "desc.png"
    },
    // This is optional. Useful if not all images are of same size
    indicatorImageHeight: 15,
    indicatorImageWidth: 19
  };

  // Generate a unique id for dynamically generated elements
  (function(){
    var symbol = "jQueryId";
    var id = 0;
    $.genId = function(){return symbol + (id++);};
  })();

  // Collapse any multiple whites space.
  var whiteSpaceMultiples = new RegExp("\\s\\s+", "g");
  function normalizeString(s) {
    return $.trim(s.replace(whiteSpaceMultiples, " "));
  }

  function sortTable(tbody, col, /* settings */ s) {
    function compareValues(a, b){
      var v1 = a.cells[col].sortValue;
      var v2 = b.cells[col].sortValue;
      if (v1 == v2) return 0;
      if (v1 > v2) return 1;
      return -1;
    }
    // If this column has not been sorted before, set the initial sort
    // direction.
    if (s.reverseSort[col] == null)
      s.reverseSort[col] = true;

    // If this column was the last one sorted, reverse its sort
    // direction.
    if (col == s.lastSortedColumn)
      s.reverseSort[col] = !s.reverseSort[col];

    // Remember this column as the last one sorted.
    s.lastSortedColumn = col;

    s.rows.sort(compareValues);

    if(s.reverseSort[col])
      s.rows.reverse();
    $(tbody).empty().append(s.rows);
  }

  // after sorting, msie will reset checked checkbox
  var isCheckboxMessedUp = jQuery.browser.msie;

  function updateSelectorColumn(tbody, /* settings */ s) {
    // msie : restore checked checkbox's state
    if (isCheckboxMessedUp && s.addSelectorColumn)
      for (var id in s.selectedRows)
        if (s.selectedRows[id])
          $("#" + id)[0].checked = "checked";
    // update number column after sorting
    if (s.addNumberColumn)
      for (var i = 0; i < tbody.rows.length; i++)
        $(tbody.rows[i].cells[s.numberColumn]).html(''+(i+1));
  }

  $.fn.makeTableSortable = function(options) {
    return this.each(function() {
      /* settings */
	  var s = $.extend(
        {
          lastSortedColumn: 0,
          // Set up an array of reverse sort flags to remember the sort
          // direction for each column.
          reverseSort: [], 
          // Since tbody.rows[] is not a true array, we cannot pass it
          // directly to the array.sort() function. We create an array
          // and store all pointers to the actual table rows here.
          rows:[],
          // collect tr id that are checked
          selectedRows:{}
        }, defaults, options);
      var table = this;
      if (s.afterSortCallback)
        $(table).bind("aftersort", function() {
          var idx = s.lastSortedColumn;        
          s.afterSortCallback(this, idx, !s.reverseSort[idx]);
        });
      var thead = $("thead",this)[0];
      var tbody = $("tbody",this)[0];
      var i,j;
      for (i = 0; i < tbody.rows.length; i++) {
        for (j = 0; j < tbody.rows[i].cells.length; j++) {
          // Strip all tags from <td> and collect the text
          //var textValue = getTextValue(tbody.rows[i].cells[j]);
          var textValue = $(tbody.rows[i].cells[j]).text();
          // Can we interpret the text as number?
          var floatValue = parseFloat(textValue);
          // store the parsed value so we can use it for comparsion
          tbody.rows[i].cells[j].sortValue = isNaN(floatValue) ?
            textValue : floatValue;
        }
      }
      if (s.addSelectorColumn || s.addNumberColumn) {
        var tr = $("tr", thead);
        if (s.addNumberColumn) {
          // we need to remember this so we can update the number
          // column after sorting
          s.numberColumn = s.addSelectorColumn ? 1 : 0;
          tr.prepend("<th class='" + s.numberColumnClass + "'>&nbsp;</th>");
        }
        if (s.addSelectorColumn)
          tr.prepend("<th class='" + s.selectorColumnClass + "'>&nbsp;</th>");        

        for (i = 0; i < tbody.rows.length; i++) {      
          tr = $(tbody.rows[i]);
          if (s.addNumberColumn)
            tr.prepend("<td class='" + s.numberColumnClass + 
                       "'>" + (i+1) + "</td>");
          if (s.addSelectorColumn) {
            var td = document.createElement("td");
            td.className = s.selectorColumnClass;
            var checkbox = document.createElement("input");
            if (isCheckboxMessedUp) checkbox.id = $.genId();
            checkbox.type = 'checkbox';
            // capture tr
            (function(tableRow) {
              $(checkbox).click(function() {
                if (isCheckboxMessedUp) 
                  s.selectedRows[this.id] = this.checked;
                if (s.selectorCallback)
                  s.selectorCallback(this.checked, tableRow);
              });
            })(tr[0]);
            td.appendChild(checkbox);
            tr.prepend(td);
          }
        }
      }
      // collect pointers to table rows
      for (i = 0; i < tbody.rows.length; i++)
        s.rows.push(tbody.rows[i]);
      // add images to the table header and setup callbacks onclick for
      // sorting
      for (i = s.numberColumn ? s.numberColumn + 1 : 0;
           i < thead.rows[0].cells.length; i++) {
        // need a closure to capture the correct value of i (as column
        // index)
        (function(col) {
          // this class allow us to quickly identify all indicator
          // images
          var indicatorClass = "sort-indicator";
          var tr = thead.rows[0];
          var th = $(tr.cells[col]);
          if (s.addIndicatorImages) {
            var image = document.createElement('img');
            image.src= s.indicatorImages["nosort"];
            image.className = indicatorClass;
            if (s.indicatorImageHeight)
              image.height = s.indicatorImageHeight;
            if (s.indicatorImageWidth)
              image.width = s.indicatorImageWidth;
            th.append(image);
          }
          th.click(function(){
            sortTable(tbody, col, s);
            if (s.addNumberColumn || s.addSelectorColumn)
              updateSelectorColumn(tbody, s);

            // clear all indicators and classes
            $("th", tr)
            .removeClass(s.columnAscendingClass)
            .removeClass(s.columnDescendingClass);
            if (s.addIndicatorImages) {
              $("." + indicatorClass, tr).each(function(){
                this.src = s.indicatorImages["nosort"];
              });
            }
            // update sorted column header appearance
            var idx = s.lastSortedColumn;
            var className, img, cell = tr.cells[idx];
            if (s.reverseSort[idx]) {                
              className = s.columnDescendingClass;
              img = s.indicatorImages["descending"];
            } else {
              className = s.columnAscendingClass;
              img = s.indicatorImages["ascending"];
            }
            $(cell).addClass(className);
            if (s.addIndicatorImages)
              $("." + indicatorClass, cell)[0].src = img;
            $(table).trigger("aftersort");
          });
        })(i);
      }
    });
  };
})(jQuery);


// Copyright (c) 2007, Mac Chan. All rights reserved.           
//                                                              
// Redistribution and use of this software in source and binary 
// forms, with or without modification, are permitted provided  
// that the following conditions are met:                       
//                                                              
// * Redistributions of source code must retain the above       
//   copyright notice, this list of conditions and the          
//   following disclaimer.                                      
//                                                              
// * Redistributions in binary form must reproduce the above    
//   copyright notice, this list of conditions and the          
//   following disclaimer in the documentation and/or other     
//   materials provided with the distribution.                  
//                                                              
// THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND       
// CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES,
// INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF     
// MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE     
// DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR         
// CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, 
// SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT 
// NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; 
// LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION)     
// HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN    
// CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR 
// OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS         
// SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE. 
