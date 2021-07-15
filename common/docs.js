/*
 * Copyright (C) 2021 omegazero.org
 *
 * This Source Code Form is subject to the terms of the Mozilla Public License, v. 2.0.
 * If a copy of the MPL was not distributed with this file, You can obtain one at https://mozilla.org/MPL/2.0/.
 *
 * Covered Software is provided under this License on an "as is" basis, without warranty of any kind,
 * either expressed, implied, or statutory, including, without limitation, warranties that the Covered Software
 * is free of defects, merchantable, fit for a particular purpose or non-infringing.
 * The entire risk as to the quality and performance of the Covered Software is with You.
 */

(function(){


	function init(){
		// var for backward compatibility
		var versionSelector = document.getElementById("versionSelector");
		if(versionSelector){
			versionSelector.onchange = function(){
				var name = this.children[this.selectedIndex].value; // value is URL-encoded already by PHP code
				if(name == "current"){
					var cur = window.location.href;
					var queryIndex = cur.indexOf("?");
					if(queryIndex > 0)
						cur = cur.substring(0, queryIndex);
					window.location.href = cur;
				}else
					window.location.href = "?tag=" + name;
			}
		}

		window.addEventListener("popstate", windowStatePop);

		var entries = document.getElementsByClassName("sidebar-regularentry");
		for(var el of entries){
			el.addEventListener("click", entryClick);
		}

		var collapsible = document.getElementsByClassName("sidebar-collapsible");
		for(var el of collapsible){
			el.addEventListener("click", collapsibleClick);
		}

		docsRoot = document.querySelector("meta[name='docsRoot']").content;
		addLinkClickListeners(document.getElementById("main"));

		hideSidebarToggle = document.getElementById("hideSidebar");
		document.getElementById("mainModal").addEventListener("click", function(){
			hideSidebarToggle.checked = "1";
		});
	}

	function addLinkClickListeners(wrap){
		var els = wrap.getElementsByTagName("a");
		for(var el of els){
			el.addEventListener("click", entryClick);
		}
	}


	var docsRoot;

	var hideSidebarToggle;

	function entryClick(event){
		var target = event.target;
		if(target.origin != window.location.origin || !target.pathname.startsWith(docsRoot))
			return;

		event.preventDefault();
		hideSidebarToggle.checked = "1";

		switchContent(target.href, target);

		window.history.pushState({entryElementId: target.id}, document.title, target.href);
	}

	var initialSelected; // the first state (when navigating to the page) is null, so need to save it below

	function windowStatePop(event){
		switchContent(document.location.href, document.getElementById(event.state ? event.state.entryElementId : initialSelected));
	}

	function switchContent(href, entryElement){
		var mainContentEl = document.getElementById("mainContent");

		var error = function(str){ // no ecma6 arrow functions for backward compatibility
			mainContentEl.innerHTML = '<span style="color:red;">Error while loading content: ' + str + '</span>';
		};

		var progressEl = document.getElementById("loadingBar");
		progressEl.style.opacity = "1";
		progressEl.style.width = "1%";

		var selectedElements = document.getElementsByClassName("sidebar-entry-selected");
		for(var el of selectedElements){
			initialSelected = el.id;
			el.classList.remove("sidebar-entry-selected");
		}

		var xhr = new XMLHttpRequest();
		xhr.addEventListener("load", function(event){
			if(xhr.status == 200){
				mainContentEl.innerHTML = xhr.responseText;
				addLinkClickListeners(mainContentEl);
			}else{
				error("Non-200 status code: " + xhr.status);
			}
		});
		xhr.addEventListener("error", function(event){
			error("XHR request failed");
		});
		xhr.addEventListener("loadstart", function(){
			progressEl.style.width = "20%";
		});
		xhr.addEventListener("loadend", function(){
			setTimeout(function(){
				progressEl.style.opacity = "0";
				progressEl.style.width = "0%";
			}, 300);
		});
		xhr.addEventListener("progress", function(event){
			progressEl.style.width = Math.floor((event.loaded / event.total) * 80 + 20) + "%";
		});
		xhr.open("GET", href + (href.indexOf("?") >= 0 ? "&" : "?") + "contentOnly=1");
		xhr.send();

		mainContentEl.innerHTML = "";

		if(entryElement)
			entryElement.classList.add("sidebar-entry-selected");
		else
			console.warn("entryElement not available");
	}


	function collapsibleClick(event){
		var el = event.target;
		var contentEl = document.getElementById(el.id + "_content");
		if(!contentEl){
			console.warn("Cannot toggle collapsible '" + el.id + "' because content element was not found");
			return;
		}
		if(el.classList.contains("sidebar-collapsible-coll")){
			el.classList.remove("sidebar-collapsible-coll");
			contentEl.style.display = "block";
		}else{
			el.classList.add("sidebar-collapsible-coll");
			contentEl.style.display = "none";
		}
	}


	window.addEventListener("load", init);

})();

