(function () {
  "use strict";

  var cfg = window.KAU_VE_CONFIG || {};
  if (!cfg.editMode || !window.KAUVE) return;

  var dirty = false;
  var selectedLink = null;
  var fileInput = null;
  var linkPanel = null;
  var linkInput = null;

  function qs(selector, root) {
    return (root || document).querySelector(selector);
  }

  function qsa(selector, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(selector));
  }

  function status(text) {
    var element = qs("#kau-ve-status");
    if (element) element.textContent = text;
  }

  function markDirty() {
    dirty = true;
    status("Unsaved");
  }

  function buildToolbar() {
    var toolbar = document.createElement("div");
    toolbar.id = "kau-ve-toolbar";
    toolbar.innerHTML = [
      "<span>KAU Visual Edit</span>",
      "<button type=\"button\" class=\"kau-ve-save\" id=\"kau-ve-save\">Save</button>",
      "<button type=\"button\" id=\"kau-ve-link\">Link</button>",
      "<a id=\"kau-ve-view\" href=\"" + escapeAttr(cfg.viewUrl || location.pathname) + "\">Exit</a>",
      "<span class=\"kau-ve-status\" id=\"kau-ve-status\">Click text to edit</span>"
    ].join("");
    document.body.appendChild(toolbar);

    linkPanel = document.createElement("div");
    linkPanel.className = "kau-ve-link-panel";
    linkPanel.innerHTML = [
      "<label for=\"kau-ve-link-url\">Link URL</label>",
      "<input id=\"kau-ve-link-url\" type=\"text\" autocomplete=\"off\">",
      "<div class=\"kau-ve-row\">",
      "<button type=\"button\" id=\"kau-ve-link-close\">Cancel</button>",
      "<button type=\"button\" class=\"primary\" id=\"kau-ve-link-apply\">Apply</button>",
      "</div>"
    ].join("");
    document.body.appendChild(linkPanel);
    linkInput = qs("#kau-ve-link-url");

    fileInput = document.createElement("input");
    fileInput.type = "file";
    fileInput.accept = "image/*";
    fileInput.style.display = "none";
    document.body.appendChild(fileInput);

    qs("#kau-ve-save").addEventListener("click", save);
    qs("#kau-ve-link").addEventListener("click", openLinkPanel);
    qs("#kau-ve-link-close").addEventListener("click", closeLinkPanel);
    qs("#kau-ve-link-apply").addEventListener("click", applyLink);
    installNavigationGuard();
  }

  function installNavigationGuard() {
    document.addEventListener("click", function (event) {
      var target = event.target && event.target.closest ? event.target.closest("a[href], button") : null;
      if (!target || isInsideEditor(target)) return;
      event.preventDefault();
      event.stopPropagation();
      var link = target.closest("a[href]");
      if (link) {
        selectedLink = link;
        clearSelection();
        link.classList.add("kau-ve-selected");
        status("Link selected. Use Link to edit URL.");
        return;
      }
      target.focus({ preventScroll: true });
      status("Button selected. Edit its text directly.");
    }, true);
  }

  function escapeAttr(value) {
    return String(value || "").replace(/&/g, "&amp;").replace(/"/g, "&quot;");
  }

  function prepareEditableElements() {
    var index = window.KAUVE.getIndex();

    Object.keys(index.text).forEach(function (id) {
      var element = index.text[id];
      element.classList.add("kau-ve-editable");
      element.setAttribute("contenteditable", "true");
      element.setAttribute("spellcheck", "false");
      element.addEventListener("input", markDirty);
      element.addEventListener("focus", function () {
        clearSelection();
        element.classList.add("kau-ve-selected");
        var link = element.closest("a[href]");
        selectedLink = link || null;
      });
      element.addEventListener("click", function (event) {
        if (element.closest("a[href]")) {
          event.preventDefault();
        }
      });
    });

    Object.keys(index.link).forEach(function (id) {
      var element = index.link[id];
      element.addEventListener("click", function (event) {
        event.preventDefault();
        selectedLink = element;
        clearSelection();
        element.classList.add("kau-ve-selected");
        status("Link selected");
      });
    });

    Object.keys(index.media).forEach(function (id) {
      var element = index.media[id];
      element.classList.add("kau-ve-media-editable");
      element.addEventListener("click", function (event) {
        event.preventDefault();
        event.stopPropagation();
        chooseImage(element);
      });
    });
  }

  function clearSelection() {
    qsa(".kau-ve-selected").forEach(function (element) {
      element.classList.remove("kau-ve-selected");
    });
  }

  function openLinkPanel() {
    if (!selectedLink) {
      status("Select a link first");
      return;
    }
    linkInput.value = selectedLink.getAttribute("href") || "";
    linkPanel.classList.add("open");
    linkInput.focus();
  }

  function closeLinkPanel() {
    linkPanel.classList.remove("open");
  }

  function applyLink() {
    if (!selectedLink) return;
    selectedLink.setAttribute("href", linkInput.value || "#");
    closeLinkPanel();
    markDirty();
  }

  function chooseImage(target) {
    fileInput.value = "";
    fileInput.onchange = function () {
      var file = fileInput.files && fileInput.files[0];
      if (!file) return;
      uploadImage(file).then(function (url) {
        window.KAUVE.setMediaSource(target, url);
        markDirty();
        status("Image updated. Save when ready.");
      }).catch(function (error) {
        status(error.message || "Image upload failed");
      });
    };
    fileInput.click();
  }

  function uploadImage(file) {
    var form = new FormData();
    form.append("action", "kau_ve_upload_image");
    form.append("nonce", cfg.nonce);
    form.append("file", file);

    status("Uploading image...");
    return fetch(cfg.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      body: form
    }).then(function (response) {
      return response.json();
    }).then(function (payload) {
      if (!payload || !payload.success) {
        throw new Error(payload && payload.data && payload.data.message ? payload.data.message : "Image upload failed");
      }
      return payload.data.url;
    });
  }

  function collectEdits() {
    var index = window.KAUVE.getIndex();
    var edits = { text: {}, link: {}, media: {} };

    Object.keys(index.text).forEach(function (id) {
      edits.text[id] = { html: index.text[id].innerHTML };
    });

    Object.keys(index.link).forEach(function (id) {
      edits.link[id] = { href: index.link[id].getAttribute("href") || "" };
    });

    Object.keys(index.media).forEach(function (id) {
      edits.media[id] = { src: index.media[id].getAttribute("src") || "" };
    });

    return edits;
  }

  function save() {
    var form = new FormData();
    form.append("action", "kau_ve_save_page");
    form.append("nonce", cfg.nonce);
    form.append("pageKey", cfg.pageKey);
    form.append("edits", JSON.stringify(collectEdits()));

    status("Saving...");
    fetch(cfg.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      body: form
    }).then(function (response) {
      return response.json();
    }).then(function (payload) {
      if (!payload || !payload.success) {
        throw new Error(payload && payload.data && payload.data.message ? payload.data.message : "Save failed");
      }
      dirty = false;
      status("Saved");
    }).catch(function (error) {
      status(error.message || "Save failed");
    });
  }

  window.addEventListener("beforeunload", function (event) {
    if (!dirty) return;
    event.preventDefault();
    event.returnValue = "";
  });

  function boot() {
    buildToolbar();
    prepareEditableElements();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
}());
