/* MAD4B Operational Remediation: progressive enhancement only. */
(function () {
  "use strict";
  function init() {
    var root = document.getElementById("mad4b-remediation");
    if (!root) return;
    var tabs = Array.prototype.slice.call(root.querySelectorAll(".mad4b-remediation-tab"));
    var panes = Array.prototype.slice.call(root.querySelectorAll(".mad4b-remediation-panel"));
    if (!tabs.length || !panes.length) return;
    function activate(name, updateHistory, setFocus) {
      var chosen = tabs.find(function (tab) { return tab.dataset.tab === name; });
      if (!chosen) return;
      tabs.forEach(function (tab) {
        var active = tab === chosen;
        tab.setAttribute("aria-selected", active ? "true" : "false");
        tab.tabIndex = active ? 0 : -1;
      });
      panes.forEach(function (pane) {
        pane.classList.toggle("is-active", pane.dataset.tabPanel === name);
      });
      if (setFocus) chosen.focus();
      if (updateHistory && window.history && window.history.replaceState) {
        window.history.replaceState(null, "", "#mad4b-pane-" + encodeURIComponent(name));
      }
    }
    function tabForHash() {
      var raw = "";
      try { raw = decodeURIComponent(window.location.hash.slice(1)); } catch (e) { return ""; }
      if (!raw) return "";
      var target = document.getElementById(raw);
      var parent = target && target.closest(".mad4b-remediation-panel");
      return parent ? parent.dataset.tabPanel : "";
    }
    tabs.forEach(function (tab, index) {
      tab.addEventListener("click", function () { activate(tab.dataset.tab, true, false); });
      tab.addEventListener("keydown", function (e) {
        var i = index;
        if (e.key === "ArrowRight") i = (index + 1) % tabs.length;
        else if (e.key === "ArrowLeft") i = (index - 1 + tabs.length) % tabs.length;
        else if (e.key === "Home") i = 0;
        else if (e.key === "End") i = tabs.length - 1;
        else return;
        e.preventDefault();
        activate(tabs[i].dataset.tab, true, true);
      });
    });
    activate(tabForHash() || "overview", false, false);
    root.classList.add("is-enhanced");
    window.addEventListener("hashchange", function () {
      var match = tabForHash();
      if (match) activate(match, false, false);
    });
    var search = document.getElementById("mad4b-blocker-search");
    var owner = document.getElementById("mad4b-blocker-owner");
    var results = document.getElementById("mad4b-blocker-results");
    var table = document.getElementById("mad4b-blocker-table");
    var empty = document.getElementById("mad4b-no-blockers");
    if (!search || !owner || !table) return;
    var rows = Array.prototype.slice.call(table.querySelectorAll("tbody tr[data-owner]"));
    function filterRows() {
      var query = search.value.trim().toLocaleLowerCase();
      var selectedOwner = owner.value;
      var count = 0;
      rows.forEach(function (row) {
        var matches = (!query || row.textContent.toLocaleLowerCase().indexOf(query) !== -1)
          && (!selectedOwner || row.dataset.owner === selectedOwner);
        row.hidden = !matches;
        if (matches) count++;
      });
      if (results) results.textContent = count + " of " + rows.length + " gates";
      if (empty) empty.hidden = count > 0 || rows.length === 0;
    }
    search.addEventListener("input", filterRows);
    owner.addEventListener("change", filterRows);
    filterRows();
    var routeSearch = document.getElementById("mad4b-route-search");
    var routeBox = document.getElementById("mad4b-route-results");
    var emptyRoutes = document.getElementById("mad4b-no-routes");
    var routes = Array.prototype.slice.call(root.querySelectorAll("#mad4b-pane-routes table.widefat tbody tr"));
    if (routeSearch) {
      function filterRoutes() {
        var query = routeSearch.value.trim().toLocaleLowerCase();
        var n = 0;
        routes.forEach(function (row) {
          var visible = !query || row.textContent.toLocaleLowerCase().indexOf(query) >= 0;
          row.hidden = !visible;
          if (visible) n++;
        });
        if (routeBox) routeBox.textContent = n + " of " + routes.length + " routes";
        if (emptyRoutes) emptyRoutes.hidden = n > 0 || routes.length === 0;
      }
      routeSearch.addEventListener("input", filterRoutes);
      filterRoutes();
    }
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
}());

