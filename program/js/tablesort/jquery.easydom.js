// Version: 0.2
// 
// Changelog:
// 0.1 Initial version
// 0.2 Added DOM / javascript attribute name translation
// 

/*

jQuery easy DOM creation (cl-who style - http://www.weitz.de/cl-who/)

I surveyed several dom creation packages listed at
http://docs.jquery.com/Plugins. I find most of them usable but less
than ideal in terms of intuitiveness.

I'm a lisper, so I'm certainly biased. Here's how one generates a html
table with one row and three columns using lisp's macro system:

(:table :cell-padding 0 :cell-spacing 1
	(:tr
		(:td :class "tdclass" "hello")
		(:td :class "tdclass" "hello")
		(:td :class "tdclass" "hello")))

Note that it is very easy to read. And below is a trival translation
using javascript's array literal syntax:
			
["table", 'cell-padding', 0, 'cell-spacing', 1,
	["tr",
		["td", 'class', 'tdclass', "hello"]
		["td", 'class', 'tdclass', "hello"]
		["td", 'class', 'tdclass', "hello"]]]

Tag name starts the array, followed by optional arbitrary number of
attribute/value pairs, and followed by optional arbitrary number of
child nodes.

Here's how the system determine what follows the tag name is
an attribute/value pair or a child node.

An attribute/value pair must start with a string element and followed
by either a string, number or boolean element. Otherwise, it is
considered a child node.

$.create(["p", 'align', 'bottom', 'id', 'my-paragraph'])
           tag  name     value     name  value
=> 

<p align="bottom" id="my-paragraph"></p>


$.create(["p", "Lorem ipsum dolor sit amet, consectetuer adipiscing elit."])
           tag  child-node
=> 

<p>Lorem ipsum dolor sit amet, consectetuer adipiscing elit.</p>

Note that you can either supply array literal as child node (which
then gets created on the fly as DOM node) or pass an object that is
already a dom node.

The $.create function returns a DOM object. So you can use jQuery's
append, prepend methods to insert it into the DOM tree.

var td1 = $.create(["td"]); 

$.create(
["table", 'cell-padding', 0, 'cell-spacing', 1,
	["tr",
		td1,
		["td", 'class', 'tdclass', "hello"]]]);

=>

<table cell-padding="0" cell-spacing="1">
	<tr>
		<td></td>
		<td class="tdclass">hello</td>
	</tr>	
</table>

Finally, notice the subtle way that I write the array literal. I use
single quote for attribute/value pairs and double quote for tag names
and string contents. This simple practice makes the code very
readable.

Comments and suggestions are welcomed.

hack@mac.e4ward.com

*/

(function($) {
  // The following are scraped from 
  // 
  // http://www.w3.org/TR/REC-DOM-Level-1/idl-definitions.html
  // http://www.w3.org/TR/DOM-Level-2-HTML/idl-definitions.html
  // http://www.w3.org/TR/2004/REC-DOM-Level-3-Core-20040407/idl-definitions.html
  // 
  // We do the translation so users of this library can create the DOM
  // on the fly just like writing plain html
  var xlation = {
    "acceptcharset":"acceptCharset",
    "accesskey":"accessKey",
    "alink":"aLink",
    "baseuri":"baseURI",
    "bgcolor":"bgColor",
    "byteoffset":"byteOffset",
    "cellindex":"cellIndex",
    "cellpadding":"cellPadding",
    "cellspacing":"cellSpacing",
    "childnodes":"childNodes",
    "choff":"chOff",
    "classname":"className",
    "codebase":"codeBase",
    "codetype":"codeType",
    "colspan":"colSpan",
    "columnnumber":"columnNumber",
    "contentdocument":"contentDocument",
    "datetime":"dateTime",
    "defaultchecked":"defaultChecked",
    "defaultselected":"defaultSelected",
    "defaultvalue":"defaultValue",
    "document":"Document",
    "documentelement":"documentElement",
    "documenttype":"DocumentType",
    "documenturi":"documentURI",
    "domconfig":"domConfig",
    "domimplementation":"DOMImplementation",
    "domlocator":"DOMLocator",
    "domobject":"DOMObject",
    "domstring":"DOMString",
    "domtimestamp":"DOMTimeStamp",
    "domuserdata":"DOMUserData",
    "element":"Element",
    "firstchild":"firstChild",
    "frameborder":"frameBorder",
    "htmlelement":"HTMLElement",
    "htmlfor":"htmlFor",
    "htmlformelement":"HTMLFormElement",
    "htmltablecaptionelement":"HTMLTableCaptionElement",
    "htmltablesectionelement":"HTMLTableSectionElement",
    "httpequiv":"httpEquiv",
    "inputencoding":"inputEncoding",
    "internalsubset":"internalSubset",
    "iselementcontentwhitespace":"isElementContentWhitespace",
    "isid":"isId",
    "ismap":"isMap",
    "lastchild":"lastChild",
    "linenumber":"lineNumber",
    "localname":"localName",
    "longdesc":"longDesc",
    "lowsrc":"lowSrc",
    "marginheight":"marginHeight",
    "marginwidth":"marginWidth",
    "maxlength":"maxLength",
    "namednodemap":"NamedNodeMap",
    "namespaceuri":"namespaceURI",
    "nextsibling":"nextSibling",
    "node":"Node",
    "nodelist":"NodeList",
    "nodename":"nodeName",
    "nodetype":"nodeType",
    "nodevalue":"nodeValue",
    "nohref":"noHref",
    "noresize":"noResize",
    "noshade":"noShade",
    "notationname":"notationName",
    "nowrap":"noWrap",
    "offset":"Offset",
    "ownerdocument":"ownerDocument",
    "ownerelement":"ownerElement",
    "parameternames":"parameterNames",
    "parentnode":"parentNode",
    "previoussibling":"previousSibling",
    "publicid":"publicId",
    "readonly":"readOnly",
    "relateddata":"relatedData",
    "relatedexception":"relatedException",
    "relatednode":"relatedNode",
    "rowindex":"rowIndex",
    "rowspan":"rowSpan",
    "schematypeinfo":"schemaTypeInfo",
    "sectionrowindex":"sectionRowIndex",
    "selectedindex":"selectedIndex",
    "stricterrorchecking":"strictErrorChecking",
    "systemid":"systemId",
    "tabindex":"tabIndex",
    "tagname":"tagName",
    "tbodies":"tBodies",
    "textcontent":"textContent",
    "tfoot":"tFoot",
    "thead":"tHead",
    "typeinfo":"TypeInfo",
    "typename":"typeName",
    "typenamespace":"typeNamespace",
    "url":"URL",
    "usemap":"useMap",
    "userdatahandler":"UserDataHandler",
    "valign":"vAlign",
    "valuetype":"valueType",
    "vlink":"vLink",
    "wholetext":"wholeText",
    "xmlencoding":"xmlEncoding",
    "xmlstandalone":"xmlStandalone",
    "xmlversion":"xmlVersion",
    // Certain html attribute's name are changed in the DOM because
    // they have conflicts with javascript keyword. These are the two
    // that I'm aware of.
    "class":"className",
    "for":"htmlFor"
  };

  function isTrivalType(obj) {
    switch(typeof(obj)) {
    case "string":
    case "number":
    case "boolean":
      return true;
    default:
      return false;
    }
  }

  $.create = function(/* array */ a) {
    var element = document.createElement(a[0]);

    // collect all attributes
    var i, attributes = {};
    for (i = 1; i < a.length; i += 2) {
      if ((typeof(a[i]) == "string")
          && (i+1 < a.length) 
          && (isTrivalType(a[i+1]))) {
        var name = xlation[a[i].toLowerCase()] || a[i];
        attributes[name] = a[i+1];
      } else break;
    }
    // apply attributes to element
    $(element).attr(attributes);

    // collect / create child nodes
    var children = [];
    for (; i < a.length; i++) {
      if (isTrivalType(a[i])) {
        children.push(document.createTextNode("" + a[i]));
      } else if (a[i]) { // make sure not null or undefined
        if (a[i].constructor == Array)
          children.push($.create(a[i]));
        else // this better be an valid html element object
          children.push(a[i]);
      }
    }
    $(element).append(children);
    return element;
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
