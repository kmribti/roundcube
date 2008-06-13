/*
 +-----------------------------------------------------------------------+
 | RoundCube Webmail Client Script                                       |
 |                                                                       |
 | This file is part of the RoundCube Webmail client                     |
 | Copyright (C) 2005-2008, RoundCube Dev, - Switzerland                 |
 | Licensed under the GNU GPL                                            |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Authors: Thomas Bruederli <roundcube@gmail.com>                       |
 |          Charles McNulty <charles@charlesmcnulty.com>                 |
 +-----------------------------------------------------------------------+
 | Requires: common.js, list.js                                          |
 +-----------------------------------------------------------------------+

  $Id: app.js 1520 2008-06-12 14:39:27Z till $
*/

/**
 * Class for sending HTTP requests
 * @constructor
 */
function rcube_http_request()
  {
  this.url = '';
  this.busy = false;
  this.xmlhttp = null;


  // reset object properties
  this.reset = function()
    {
    // set unassigned event handlers
    this.onloading = function(){ };
    this.onloaded = function(){ };
    this.oninteractive = function(){ };
    this.oncomplete = function(){ };
    this.onabort = function(){ };
    this.onerror = function(){ };
    
    this.url = '';
    this.busy = false;
    this.xmlhttp = null;
    }


  // create HTMLHTTP object
  this.build = function()
    {
    if (window.XMLHttpRequest)
      this.xmlhttp = new XMLHttpRequest();
    else if (window.ActiveXObject)
      {
      try { this.xmlhttp = new ActiveXObject("Microsoft.XMLHTTP"); }
      catch(e) { this.xmlhttp = null; }
      }
    else
      {
      
      }
    }

  // send GET request
  this.GET = function(url)
    {
    this.build();

    if (!this.xmlhttp)
      {
      this.onerror(this);
      return false;
      }

    var _ref = this;
    this.url = url;
    this.busy = true;

    this.xmlhttp.onreadystatechange = function(){ _ref.xmlhttp_onreadystatechange(); };
    this.xmlhttp.open('GET', url);
    this.xmlhttp.setRequestHeader('X-RoundCube-Referer', bw.get_cookie('roundcube_sessid'));
    this.xmlhttp.send(null);
    };


  this.POST = function(url, body, contentType)
    {
    // default value for contentType if not provided
    if (typeof(contentType) == 'undefined')
      contentType = 'application/x-www-form-urlencoded';

    this.build();
    
    if (!this.xmlhttp)
    {
       this.onerror(this);
       return false;
    }
    
    var req_body = body;
    if (typeof(body) == 'object')
    {
      req_body = '';
      for (var p in body)
        req_body += (req_body ? '&' : '') + p+'='+urlencode(body[p]);
    }

    var ref = this;
    this.url = url;
    this.busy = true;
    
    this.xmlhttp.onreadystatechange = function() { ref.xmlhttp_onreadystatechange(); };
    this.xmlhttp.open('POST', url, true);
    this.xmlhttp.setRequestHeader('Content-Type', contentType);
    this.xmlhttp.setRequestHeader('X-RoundCube-Referer', bw.get_cookie('roundcube_sessid'));
    this.xmlhttp.send(req_body);
    };


  // handle onreadystatechange event
  this.xmlhttp_onreadystatechange = function()
    {
    if(this.xmlhttp.readyState == 1)
      this.onloading(this);

    else if(this.xmlhttp.readyState == 2)
      this.onloaded(this);

    else if(this.xmlhttp.readyState == 3)
      this.oninteractive(this);

    else if(this.xmlhttp.readyState == 4)
      {
      try {
        if (this.xmlhttp.status == 0)
          this.onabort(this);
        else if(this.xmlhttp.status == 200)
          this.oncomplete(this);
        else
          this.onerror(this);

        this.busy = false;
        }
      catch(err)
        {
        this.onerror(this);
        this.busy = false;
        }
      }
    }

  // getter method for HTTP headers
  this.get_header = function(name)
    {
    return this.xmlhttp.getResponseHeader(name);
    };

  this.get_text = function()
    {
    return this.xmlhttp.responseText;
    };

  this.get_xml = function()
    {
    return this.xmlhttp.responseXML;
    };

  this.reset();
  
  }  // end class rcube_http_request
