function init() {
  $('input#loginButton').bind("click", function(e) {
    // TODO send username and password to the server for authentication
    // TODO only cause the page to change when login is successful
    // TODO use the message tray to show a message

    // The timeouts here just simulate like how it would actually work
    var username = $('input#rcmloginuser').attr('value');
    var password = $('input#rcmloginpwd').attr('value');
    var token = {
       username: username,
       password: password,
     };
    //alert($.toJSON(token));

    $('div#message').empty().append("<div class='notice'>Logging in...</div>").show();

    setTimeout(function(){
      $('div#message').hide();
    }, 750);

    setTimeout(function(){
      $('div#login-form').hide();
      $('div#application').show();
      $('img#rcmbtn104').hide();
      $('img#rcmbtn105').attr('src', 'skins/default/images/buttons/inbox_act.png');
    }, 1000);

    if (e) { e.preventDefault(); }
    else { window.event.returnValue = false; }
  });

  $('a#rcmbtn103').bind("click", function(e) {
    $('div#login-form').show();
    $('div#application').hide();
    $('img#rcmbtn104').show();

    if (e) { e.preventDefault(); }
    else { window.event.returnValue = false; }
  });

  var getMessage = function(e) {
    $('div#message').empty().append("<div class='notice'>Checking for new messages...</div>").show();

    setTimeout(function(){
      $('div#message').hide();
    }, 750);

    setTimeout(function(){
      // add a message with an anonymous object
      addMessage({
        subject: "Re: Meeting",
        sender: "John Smith",
        date: "15.12.2006 17:26",
        size: "2 KB"
      });

      // create the message object before adding it
      var msg2 = {
        subject: "Lunch today",
        sender: "Carrie Meyers",
        date: "15.12.2006 17:28",
        size: "1 KB"
      };
      addMessage(msg2);
      $('div#message').hide();
    }, 750);

    if (e) { e.preventDefault(); }
    else { window.event.returnValue = false; }
  };
  $('a#checkMessages').bind("click", getMessage);
  $('a#rcmbtn100').bind("click", getMessage);
}

var isEven = false;
function addMessage(msg) {
    var rowType = 'odd';
    if (isEven) {
      rowType = 'even';
    }
    isEven = !isEven;
    //<tr class="message odd" id="rcmrow4447"></tr>
    var row = document.createElement("tr");
    row.className = "message " + rowType;
    row.id = "rcmrow4447";

    //<td class="icon"><img border="0" alt="" src="skins/default/images/icons/dot.png" id="msgicn_4447"/></td>
    var icon1Cell = document.createElement("td");
    icon1Cell.className = 'icon';
    var icon1Img = document.createElement("img");
    icon1Img.src = "skins/default/images/icons/dot.png";
    icon1Img.id = "msgicn_4447";
    icon1Img.border = "0";
    icon1Cell.appendChild(icon1Img);

    //<td class="subject">Re: Meeting this week
    //<img width="1000" height="5" alt="" src="./program/blank.gif"/></td>
    var subjectCell = document.createElement("td");
    subjectCell.className = 'subject';
    var txtSubject = document.createTextNode(msg.subject);
    subjectCell.appendChild(txtSubject);

    //<td class="from"><a title="jsmith@atwork.com" class="rcmContactAddress" href="#">John Smith</a></td>
    var fromCell = document.createElement("td");
    fromCell.className = 'from';
    var txtFrom = document.createTextNode(msg.sender);
    fromCell.appendChild(txtFrom);

    //<td class="date">15.12.2006 17:26</td>
    var dateCell = document.createElement("td");
    dateCell.className = 'date';
    var txtDate = document.createTextNode(msg.date);
    dateCell.appendChild(txtDate);

    //<td class="size">2 KB</td>
    var sizeCell = document.createElement("td");
    sizeCell.className = 'size';
    var txtSize = document.createTextNode(msg.size);
    sizeCell.appendChild(txtSize);

    //<td class="icon"/>
    var icon2Cell = document.createElement("td");
    icon2Cell.className = 'icon';

    row.appendChild(icon1Cell);
    row.appendChild(subjectCell);
    row.appendChild(fromCell);
    row.appendChild(dateCell);
    row.appendChild(sizeCell);
    row.appendChild(icon2Cell);

    $('table#messagelist > tbody').append(row);
}
$(document).ready(function(){init();});
