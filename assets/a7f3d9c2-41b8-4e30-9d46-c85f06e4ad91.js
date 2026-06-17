(function () {
  "use strict";

  var script = document.currentScript;
  var embeddedConfig = {};
  try {
    embeddedConfig = script && script.dataset && script.dataset.kauConfig ? JSON.parse(script.dataset.kauConfig) : {};
  } catch (error) {
    embeddedConfig = {};
  }
  var cfg = window.KAU_VE_CONFIG || embeddedConfig || {};
  window.KAU_VE_CONFIG = cfg;
  var editIndex = null;
  var staticBase = (cfg.staticBase || "https://kau-jp.github.io/").replace(/\/?$/, "/");

  function unique(list) {
    var seen = new Set();
    return list.filter(function (item) {
      if (!item || seen.has(item)) return false;
      seen.add(item);
      return true;
    });
  }

  function isInsideEditor(element) {
    return !!(element && element.closest && element.closest("#kau-ve-toolbar, #kau-ve-enter"));
  }

  function normalizeStaticUrl(value) {
    if (!value || typeof value !== "string") return value;
    var origin = window.location.origin;
    if (value.indexOf("assets/") === 0 || value.indexOf("media/") === 0) {
      return staticBase + value;
    }
    if (value.indexOf("/assets/") === 0 || value.indexOf("/media/") === 0) {
      return staticBase + value.replace(/^\//, "");
    }
    if (value.indexOf(origin + "/assets/") === 0 || value.indexOf(origin + "/media/") === 0) {
      return staticBase + value.slice(origin.length + 1);
    }
    return value;
  }

  function normalizeStaticPaths(root) {
    Array.prototype.slice.call((root || document).querySelectorAll("[src], [href]")).forEach(function (element) {
      ["src", "href"].forEach(function (attr) {
        if (!element.hasAttribute(attr)) return;
        var next = normalizeStaticUrl(element.getAttribute(attr));
        if (next && next !== element.getAttribute(attr)) {
          element.setAttribute(attr, next);
        }
      });
    });
  }

  function hasUsefulText(element) {
    if (!element || isInsideEditor(element)) return false;
    var text = (element.textContent || "").replace(/\s+/g, " ").trim();
    if (!text) return false;
    if (element.matches("script, style, noscript, svg, path")) return false;
    return true;
  }

  function buildTextElements() {
    var selectors = [
      "h1", "h2", "h3", "h4", "h5",
      "p", "a", "button", "dt", "dd", "li",
      ".eyebrow", ".sub", ".lead", ".big", ".title",
      ".nm", ".pr", ".gd", ".gtag", ".cat", ".pname", ".pdesc", ".pprice",
      ".tag-pill", ".d", ".h", ".k", ".v",
      ".footer-addr", ".footer-bottom span"
    ].join(",");
    var candidates = unique(Array.prototype.slice.call(document.querySelectorAll(selectors)));
    return candidates.filter(function (element) {
      if (!hasUsefulText(element)) return false;
      var parent = element.parentElement ? element.parentElement.closest(selectors) : null;
      if (parent && parent !== element && !parent.matches(".footer-addr")) return false;
      return true;
    });
  }

  function buildIndex() {
    var text = {};
    buildTextElements().forEach(function (element, index) {
      var id = "text-" + index;
      element.dataset.kauVeTextId = id;
      text[id] = element;
    });

    var links = {};
    Array.prototype.slice.call(document.querySelectorAll("a[href]")).forEach(function (element, index) {
      if (isInsideEditor(element)) return;
      var id = "link-" + index;
      element.dataset.kauVeLinkId = id;
      links[id] = element;
    });

    var media = {};
    Array.prototype.slice.call(document.querySelectorAll("img, image-slot")).forEach(function (element, index) {
      if (isInsideEditor(element)) return;
      var id = "media-" + index;
      element.dataset.kauVeMediaId = id;
      media[id] = element;
    });

    editIndex = { text: text, link: links, media: media };
    return editIndex;
  }

  function setMediaSource(element, src) {
    if (!element || !src) return;
    element.setAttribute("src", normalizeStaticUrl(src));
    if ((element.tagName || "").toLowerCase() === "image-slot") {
      var parent = element.parentNode;
      var next = element.nextSibling;
      if (parent) {
        parent.removeChild(element);
        parent.insertBefore(element, next);
      }
    }
  }

  function applyEdits() {
    var edits = cfg.edits || {};
    normalizeStaticPaths(document);
    var index = buildIndex();

    Object.keys(edits.text || {}).forEach(function (id) {
      if (index.text[id] && typeof edits.text[id].html === "string") {
        index.text[id].innerHTML = edits.text[id].html;
      }
    });

    Object.keys(edits.link || {}).forEach(function (id) {
      if (index.link[id] && edits.link[id].href) {
        index.link[id].setAttribute("href", edits.link[id].href);
      }
    });

    Object.keys(edits.media || {}).forEach(function (id) {
      if (index.media[id] && edits.media[id].src) {
        setMediaSource(index.media[id], edits.media[id].src);
      }
    });
  }

  window.KAUVE = {
    buildIndex: buildIndex,
    applyEdits: applyEdits,
    setMediaSource: setMediaSource,
    normalizeStaticPaths: normalizeStaticPaths,
    getIndex: function () { return editIndex || buildIndex(); },
    buildTextElements: buildTextElements
  };

  if (window.MutationObserver) {
    var pending = false;
    new MutationObserver(function () {
      if (pending) return;
      pending = true;
      window.setTimeout(function () {
        pending = false;
        normalizeStaticPaths(document);
      }, 0);
    }).observe(document.documentElement, {
      attributes: true,
      attributeFilter: ["src", "href"],
      childList: true,
      subtree: true
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", applyEdits);
  } else {
    applyEdits();
  }
}());
