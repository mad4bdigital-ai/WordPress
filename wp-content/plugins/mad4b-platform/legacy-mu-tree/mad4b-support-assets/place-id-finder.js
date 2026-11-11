/* MAD4B embedded Place ID Finder.
 * Uses Google's supported Places (New) client library in a site-authorized admin page.
 * A human selection is evidence only, never an operational pickup approval.
 */
(function () {
  "use strict";
  const $ = (q, root = document) => root.querySelector(q);
  const panel = $("#mad4b-place-finder");
  if (!panel) return;
  const search = $("#mad4b-place-finder-search");
  const status = $("#mad4b-place-finder-status");
  const selected = $("#mad4b-place-finder-selected");
  const suggestions = $("#mad4b-place-finder-suggestions");
  const mapNode = $("#mad4b-place-finder-map");
  let currentForm = null;
  let map = null;
  let marker = null;
  let apiReady = false;

  function updateStatus(message, isError = false) {
    status.textContent = message;
    status.style.color = isError ? "#a00" : "";
  }
  function chooseForm(button) {
    const form = button.closest("tr")?.querySelector("form[data-mad4b-support-review]");
    if (!form) return;
    currentForm = form;
    const tourLabel = button.getAttribute("data-tour-title") || "";
    const label = $("#mad4b-place-finder-target");
    const locationKind = button.getAttribute("data-location-kind") || "undetermined";
    label.textContent = tourLabel + " — " + locationKind.replace(/_/g, " ");
    suggestions.replaceChildren();
    let queries = [];
    try {
      queries = JSON.parse(button.getAttribute("data-resolution-queries") || "[]");
      if (!Array.isArray(queries)) queries = [];
    } catch (_) { queries = []; }
    if (queries.length === 0) {
      const msg = document.createElement("p");
      msg.textContent = locationKind === "hotel_pickup" ?
        "Guest hotel pickup varies by booking. Verify the hotel's collection field; do not select a fixed place." :
        "No reliable place in the original itinerary. Check with the supplier before selecting a pin.";
      suggestions.append(msg);
    }
    queries.slice(0, 5).forEach((item) => {
      if (!item || typeof item.query !== "string" || !item.maps_url) return;
      const line = document.createElement("p");
      line.append(document.createTextNode("Source: " + String(item.source || "tour") + " · "));
      const link = document.createElement("a");
      link.href = item.maps_url;
      link.target = "_blank";
      link.rel = "noopener noreferrer";
      link.textContent = "Google: " + item.query;
      line.append(link);
      if (item.osm_url) {
        line.append(document.createTextNode(" · "));
        const osm = document.createElement("a");
        osm.href = item.osm_url;
        osm.target = "_blank";
        osm.rel = "noopener noreferrer";
        osm.textContent = "OpenStreetMap";
        line.append(osm);
      }
      suggestions.append(line);
    });
    panel.hidden = false;
    selected.hidden = true;
    panel.scrollIntoView({ behavior: "smooth", block: "start" });
    updateStatus(apiReady ? "Search a place, then select the correct Google result." :
      "Loading Google Places… Use the official Google Finder link below if unavailable.");
  }
  document.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-mad4b-find-place]");
    if (btn) chooseForm(btn);
    if (e.target.closest("#mad4b-place-finder-close")) {
      panel.hidden = true;
      currentForm = null;
    }
  });
  window.gm_authFailure = function () {
    updateStatus("Google Maps authorization denied. Use the Google-hosted Finder link instead.", true);
  };
  window.mad4bPlaceIdFinderInit = async function () {
    try {
      const [{ Map }, { AdvancedMarkerElement }] = await Promise.all([
        google.maps.importLibrary("maps"),
        google.maps.importLibrary("marker"),
        google.maps.importLibrary("places")
      ]);
      map = new Map(mapNode, {
        center: { lat: 26.82, lng: 30.8 },
        zoom: 5,
        mapId: "DEMO_MAP_ID",
        mapTypeControl: false,
        streetViewControl: false
      });
      marker = new AdvancedMarkerElement({ map, position: { lat: 26.82, lng: 30.8 } });
      marker.map = null;
      const autocomplete = document.createElement("gmp-place-autocomplete");
      autocomplete.setAttribute("aria-label", "Search Google Places");
      autocomplete.style.cssText = "display:block;width:100%;max-width:520px;min-height:48px";
      search.replaceChildren(autocomplete);
      autocomplete.addEventListener("gmp-select", async (event) => {
        try {
          if (!currentForm) {
            updateStatus("Choose a tour first.", true);
            return;
          }
          const prediction = event.placePrediction;
          if (!prediction) throw new Error("Selected place is unavailable");
          const place = prediction.toPlace();
          await place.fetchFields({ fields: ["id", "displayName", "formattedAddress", "location"] });
          if (!place.id || !place.displayName) throw new Error("Place ID or name was not returned");
          const name = String(place.displayName);
          const placeId = String(place.id);
          if (!/^[A-Za-z0-9_:-]{8,250}$/.test(placeId)) throw new Error("Unexpected Place ID format");
          const idInput = $("[name=place_id]", currentForm);
          const nameInput = $("[name=place_label]", currentForm);
          const linkInput = $("[name=maps_url]", currentForm);
          if (!idInput || !nameInput || !linkInput) throw new Error("Review form unavailable");
          idInput.value = placeId;
          nameInput.value = name;
          const providerInput = $("[name=review_provider]", currentForm);
          if (providerInput) providerInput.value = "google_places_browser";
          linkInput.value = "https://www.google.com/maps/search/?api=1&query=" +
            encodeURIComponent(name) + "&query_place_id=" + encodeURIComponent(placeId);
          selected.hidden = false;
          selected.replaceChildren();
          const h = document.createElement("strong");
          h.textContent = name;
          const info = document.createElement("p");
          info.textContent = (place.formattedAddress || "") + " | Place ID: " + placeId;
          const warning = document.createElement("p");
          warning.textContent = "Candidate only. Confirm with the supplier before any pickup or boarding writeback.";
          selected.append(h, info, warning);
          if (place.location && map && marker) {
            map.setCenter(place.location);
            map.setZoom(15);
            marker.position = place.location;
            marker.map = map;
          }
          updateStatus("Candidate saved to the review form. Add an operator note and press Save to retain it.");
        } catch (e) {
          updateStatus("Google Places could not return this candidate: " + String(e.message || e), true);
        }
      });
      apiReady = true;
      updateStatus("Google Places ready. Select a tour to start searching.");
    } catch (e) {
      updateStatus("Google Places unavailable: " + String(e.message || e) +
        ". Use the official Finder link instead.", true);
    }
  };
  window.addEventListener("error", (ev) => {
    if (String(ev.filename || "").includes("maps.googleapis.com")) {
      updateStatus("Google Maps failed to load; use the external Google Finder.", true);
    }
  });
}());
