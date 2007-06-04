
var RoundCube = Class.create();
Object.extend(RoundCube.prototype, {});

RoundCube.Application = Class.create();
RoundCube.Button = Class.create();

Object.extend(RoundCube.Application.prototype, {
  initialize: function() {
    this.controlTray = new RoundCube.ControlTray();
    this.controlTray.updateTray();
    // action wrapper
    this.startAW = 'function(){try {';
    this.endAW = '}catch(e){alert("Error");}}';
  },
  processInstructions : function(instructions) {
    for (var i=0;i<instructions.length;i++) {
      var cmd = instructions[i];
      if ("addControlTrayButton" == cmd.instruction) {
        var action = eval(this.startAW + cmd.action + this.endAW);
        var button = new RoundCube.Button(cmd.name, action);
        this.controlTray.addButton(button);
      }
    }
    this.controlTray.updateTray();
  },
  getInstructions : function() {
    this.successHandler = function() {
      var __app = this;
      return function() {
        var instructions = $.parseJSON(arguments[0]);
        if (instructions != null) {
          _app.processInstructions(instructions);
        }
      }
    };

    $.ajax({
      type: "GET",
      url: "instructions.json",
      //url: "proxy.php?action=instructions",
      success: this.successHandler(),
      error: function() { alert('Error calling proxy!'); }
    });
  }
});

Object.extend(RoundCube.Button.prototype, {
  initialize: function(name, action) {
   this.name = name;
   this.action = action;
  }
});

