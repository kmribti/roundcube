(function($) {
  $.fn.stripAlternateRow = function(alternateColumnClass) {
    // it turns out that jquery is smart enough to not select <tr>
    // from nested table
    $("tr:odd",this).addClass(alternateColumnClass);
    // the reason why we need to remove class is that table can be
    // sorted by client side script
    $("tr:even",this).removeClass(alternateColumnClass);
  };
})(jQuery);
