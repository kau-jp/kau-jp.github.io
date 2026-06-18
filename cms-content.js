(function () {
  "use strict";

  function one(selector, root) {
    return (root || document).querySelector(selector);
  }

  function all(selector, root) {
    return Array.from((root || document).querySelectorAll(selector));
  }

  function text(selector, value, root) {
    var element = one(selector, root);
    if (element && value !== undefined && value !== null) {
      element.textContent = value;
    }
  }

  function link(selector, value, root) {
    var element = one(selector, root);
    if (element && value) element.href = value;
  }

  function image(selector, value, root) {
    var element = one(selector, root);
    if (!element || !value) return;
    var url = mediaUrl(value);
    element.setAttribute("src", url);
    // image-slot custom elements only read src in connectedCallback — re-insert to trigger re-render
    if (element.tagName && element.tagName.toLowerCase() === "image-slot") {
      var parent = element.parentNode;
      var next = element.nextSibling;
      if (parent) { parent.removeChild(element); parent.insertBefore(element, next); }
    }
  }

  function mediaUrl(value) {
    if (!value || /^https?:\/\//.test(value) || value.indexOf("data:") === 0) return value;
    if (window.KAU_MEDIA_BASE && value.charAt(0) === "/") {
      return window.KAU_MEDIA_BASE.replace(/\/$/, "") + value;
    }
    if (window.KAU_THEME_BASE && value.charAt(0) === "/") {
      return window.KAU_THEME_BASE.replace(/\/$/, "") + value;
    }
    if (value.indexOf("/media/") === 0 || value.indexOf("/assets/") === 0) {
      return value.slice(1);
    }
    return value;
  }

  function lines(element, first, accent, suffix) {
    if (!element) return;
    element.replaceChildren();
    element.append(document.createTextNode(first || ""));
    element.append(document.createElement("br"));
    var span = document.createElement("span");
    span.className = "mn";
    span.textContent = accent || "";
    element.append(span);
    element.append(document.createTextNode(suffix || ""));
  }

  function setButton(element, label, url) {
    if (!element) return;
    if (url) element.href = url;
    var icon = one("svg", element);
    element.replaceChildren(document.createTextNode(label || ""));
    if (icon) {
      element.append(document.createTextNode(" "));
      element.append(icon);
    }
  }

  function labelWithEnglish(anchor, label, english) {
    if (!anchor) return;
    anchor.replaceChildren(document.createTextNode(label || ""));
    if (english) {
      var span = document.createElement("span");
      span.className = "en";
      span.textContent = english;
      anchor.append(span);
    }
  }

  function createImageSlot(options) {
    var slot = document.createElement("image-slot");
    slot.setAttribute("shape", options.shape || "rect");
    if (options.fit) slot.setAttribute("fit", options.fit);
    if (options.id) slot.id = options.id;
    slot.setAttribute("placeholder", options.placeholder || "画像");
    if (options.src) slot.setAttribute("src", mediaUrl(options.src));
    return slot;
  }

  function createArrowIcon(width) {
    var svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    svg.setAttribute("viewBox", "0 0 24 24");
    svg.setAttribute("fill", "none");
    svg.setAttribute("stroke", "currentColor");
    svg.setAttribute("stroke-width", "1.6");
    svg.setAttribute("aria-hidden", "true");
    if (width) svg.setAttribute("width", String(width));
    var path = document.createElementNS("http://www.w3.org/2000/svg", "path");
    path.setAttribute("d", "M5 12h14M13 6l6 6-6 6");
    svg.append(path);
    return svg;
  }

  function applyGlobal(global) {
    if (!global) return;
    applyNavigation(global);
    applyFooter(global);
    applyShop(global);
  }

  function applyNavigation(global) {
    var nav = global.navigation || {};
    all(".nav-links").forEach(function (menu) {
      var links = all("a", menu);
      labelWithEnglish(links[0], nav.home_label, nav.home_en);
      labelWithEnglish(links[1], nav.about_label, nav.about_en);
      labelWithEnglish(links[2], nav.products_label, nav.products_en);
      labelWithEnglish(links[3], nav.news_label, nav.news_en);
    });

    all(".nav-shop-btn").forEach(function (button) {
      var icon = one("svg", button);
      button.replaceChildren(document.createTextNode(nav.shop_label || "オンラインショップ"));
      if (icon) {
        button.append(document.createTextNode(" "));
        button.append(icon);
      }
    });

    all(".nav-cta").forEach(function (element) {
      element.textContent = nav.contact_label || "お問い合わせ";
      if (global.contact_url && global.contact_url !== "#") element.href = global.contact_url;
    });
  }

  function applyShop(global) {
    var shop = global.shop || {};
    all(".nav-shop-menu").forEach(function (menu) {
      var links = all("a", menu);
      setShopLink(links[0], "a", "mk-az", shop.amazon_label || "Amazonで購入", global.amazon_url);
      setShopLink(links[1], "R", "mk-rk", shop.rakuten_label || "楽天市場で購入", global.rakuten_url);
    });

    var modal = one("#kau-cs-modal");
    if (modal) {
      var paragraphs = all("p", modal);
      if (paragraphs[0]) paragraphs[0].textContent = shop.coming_soon_label || "";
      if (paragraphs[1]) paragraphs[1].textContent = shop.coming_soon_title || "";
      if (paragraphs[2]) paragraphs[2].textContent = shop.coming_soon_description || "";
    }
  }

  function setShopLink(anchor, mark, markClass, label, url) {
    if (!anchor) return;
    if (url) anchor.href = url;
    anchor.replaceChildren();
    var span = document.createElement("span");
    span.className = "mk " + markClass;
    span.textContent = mark;
    anchor.append(span, document.createTextNode(label || ""));
  }

  function applyFooter(global) {
    var footer = global.footer || {};
    all(".footer-addr").forEach(function (element) {
      element.replaceChildren();
      [
        global.company_name,
        global.postal_code,
        global.address_line_1,
        global.address_line_2,
      ].filter(Boolean).forEach(function (value, index) {
        if (index) element.append(document.createElement("br"));
        element.append(document.createTextNode(value));
      });
    });

    all(".footer").forEach(function (footerElement) {
      var columns = all(".footer-col", footerElement);
      renderFooterColumn(columns[0], footer.products_title, footer.products_links);
      renderFooterColumn(columns[1], footer.company_title, footer.company_links);
      renderFooterColumn(columns[2], footer.support_title, footer.support_links);
      var bottom = one(".footer-bottom", footerElement);
      if (bottom) {
        var spans = all("span", bottom);
        if (spans[0] && footer.copyright) spans[0].textContent = footer.copyright;
        if (spans[1] && footer.suffix) spans[1].textContent = footer.suffix;
      }
    });
  }

  function renderFooterColumn(column, title, links) {
    if (!column) return;
    column.replaceChildren();
    var heading = document.createElement("h5");
    heading.textContent = title || "";
    column.append(heading);
    (links || []).forEach(function (item) {
      var anchor = document.createElement("a");
      anchor.href = item.url || "#";
      anchor.textContent = item.label || "";
      column.append(anchor);
    });
  }

  function applyHome(home, global) {
    if (!home) return;
    var hero = home.hero || {};
    text(".hero-b .eyebrow", hero.eyebrow);
    lines(one(".hero-b h1"), hero.line_1, hero.accent, hero.suffix);
    text(".hero-b .sub", hero.subtitle);
    image("#b-hero", hero.image);
    setButton(one(".hero-b .btn-fill"), hero.primary_label, hero.primary_url);
    setButton(one(".hero-b .btn-ghost"), hero.secondary_label, hero.secondary_url);
    text(".hero-b .scroll span", hero.scroll_label);

    var philosophy = home.philosophy || {};
    text(".intro-b .eyebrow", philosophy.eyebrow);
    text(".intro-b h2", philosophy.title);

    var showcase = home.showcase || {};
    text(".show-b .head .eyebrow", showcase.eyebrow);
    text(".show-b .head h3", showcase.title);
    setButton(one(".show-b .btn-line"), showcase.view_all_label, showcase.view_all_url);
    renderHomeShowcase(showcase.items);

    var feature = home.feature || {};
    text(".split-b .txt .eyebrow", feature.eyebrow);
    text(".split-b .txt h2", feature.title);
    text(".split-b .txt > p", feature.description);
    image("#b-feat", feature.image);
    renderStats(feature.stats);

    renderHomeValues((home.values || {}).items);

    var cta = home.cta || {};
    text(".cta-b .eyebrow", cta.eyebrow);
    lines(one(".cta-b h2"), cta.line_1, cta.accent, cta.suffix);
    text(".cta-b .in > p", cta.description);
    image("#b-cta", cta.image);
    setButton(one(".cta-b .btn-fill"), cta.primary_label, cta.primary_url || global.contact_url);
    setButton(one(".cta-b .btn-ghost"), cta.secondary_label, cta.secondary_url);
  }

  function renderHomeShowcase(items) {
    var track = one(".show-b .track");
    if (!track) return;
    track.replaceChildren();
    (items || []).forEach(function (item, index) {
      var card = document.createElement("a");
      card.href = "products.html";
      card.className = "gcard";
      card.setAttribute("data-reveal", "");

      var media = document.createElement("div");
      media.className = "gph";
      var tag = document.createElement("span");
      tag.className = "gtag";
      tag.textContent = item.category || "";
      media.append(tag, createImageSlot({
        shape: "rect",
        id: "b-g" + (index + 1),
        placeholder: "製品写真",
        src: item.image,
      }));
      card.append(media);

      var meta = document.createElement("div");
      meta.className = "gm";
      var name = document.createElement("span");
      name.className = "nm";
      name.textContent = item.name || "";
      var price = document.createElement("span");
      price.className = "pr";
      price.textContent = item.price || "";
      meta.append(name, price);
      card.append(meta);

      var description = document.createElement("div");
      description.className = "gd";
      description.textContent = item.description || "";
      card.append(description);
      track.append(card);
    });
  }

  function renderStats(stats) {
    var container = one(".split-b .specs");
    if (!container) return;
    container.replaceChildren();
    (stats || []).forEach(function (item) {
      var stat = document.createElement("div");
      stat.className = "sp";
      var value = document.createElement("div");
      value.className = "v";
      value.dataset.count = item.value || "0";
      if (item.suffix) value.dataset.suffix = item.suffix;
      value.textContent = item.value || "0";
      var label = document.createElement("div");
      label.className = "k";
      label.textContent = item.label || "";
      stat.append(value, label);
      container.append(stat);
    });
  }

  function renderHomeValues(items) {
    var grid = one(".band-grid");
    if (!grid) return;
    var icons = all(".band-grid .v img.ic").map(function (imageElement) {
      return imageElement.getAttribute("src");
    }).filter(Boolean);
    grid.replaceChildren();
    (items || []).forEach(function (item, index) {
      var card = document.createElement("div");
      card.className = "v";
      card.setAttribute("data-reveal", "");
      if (icons.length) {
        var icon = document.createElement("img");
        icon.className = "ic";
        icon.src = icons[index % icons.length];
        icon.alt = "";
        card.append(icon);
      }
      var title = document.createElement("h4");
      title.textContent = item.title || "";
      var description = document.createElement("p");
      description.textContent = item.description || "";
      card.append(title, description);
      grid.append(card);
    });
  }

  function applyAbout(about, global) {
    if (!about) return;
    var hero = about.hero || {};
    text(".page-hero h1", hero.title);
    text(".page-hero .sub", hero.subtitle);
    text(".about-statement .big", (about.statement || {}).text);
    renderPrinciples((about.principles || {}).items);

    var craft = about.craft || {};
    text(".feat2 .eyebrow", craft.eyebrow);
    text(".feat2 h2", craft.title);
    var craftParagraphs = all(".feat2 > div:last-child > p");
    if (craftParagraphs[0]) craftParagraphs[0].textContent = craft.description_1 || "";
    if (craftParagraphs[1]) craftParagraphs[1].textContent = craft.description_2 || "";
    image("#ab-craft", craft.image);

    var profile = about.profile || {};
    text(".profile .label .eyebrow", profile.eyebrow);
    text(".profile .title", profile.title);
    renderProfile(profile.items);

    var history = about.history || {};
    text(".history .label .eyebrow", history.eyebrow);
    text(".history .title", history.title);
    renderHistory(history.items);

    var access = about.access || {};
    text(".access .label .eyebrow", access.eyebrow);
    text(".access .title", access.title);
    text(".access h3", access.place_name);
    image("#ab-map", access.map_image);
    setButton(one(".access .btn-solid"), access.button_label, global.contact_url);

    var accessValues = {
      Address: [
        global.postal_code,
        global.address_line_1,
        global.address_line_2,
      ].filter(Boolean).join(" "),
      Access: global.access,
      Hours: global.hours,
      Tel: global.phone,
      Mail: global.email,
    };
    all(".access .ln").forEach(function (row) {
      var key = one(".k", row);
      var value = all("span", row)[1];
      if (key && value && accessValues[key.textContent]) value.textContent = accessValues[key.textContent];
    });
  }

  function renderPrinciples(items) {
    var grid = one(".philo3");
    if (!grid) return;
    grid.replaceChildren();
    (items || []).forEach(function (item) {
      var card = document.createElement("div");
      card.className = "p";
      card.setAttribute("data-reveal", "");
      var label = document.createElement("div");
      label.className = "n";
      label.textContent = item.label || "";
      var title = document.createElement("h3");
      title.textContent = item.title || "";
      var description = document.createElement("p");
      description.textContent = item.description || "";
      card.append(label, title, description);
      grid.append(card);
    });
  }

  function renderProfile(items) {
    var table = one(".otable");
    if (!table) return;
    table.replaceChildren();
    (items || []).forEach(function (item) {
      var row = document.createElement("div");
      row.className = "row";
      var dt = document.createElement("dt");
      dt.append(document.createTextNode(item.label || ""));
      if (item.sublabel) {
        dt.append(document.createTextNode(" "));
        var sublabel = document.createElement("span");
        sublabel.className = "en";
        sublabel.textContent = item.sublabel;
        dt.append(sublabel);
      }
      var dd = document.createElement("dd");
      dd.textContent = item.value || "";
      row.append(dt, dd);
      table.append(row);
    });
  }

  function renderHistory(items) {
    var timeline = one(".timeline");
    if (!timeline) return;
    timeline.replaceChildren();
    (items || []).forEach(function (item, index) {
      var row = document.createElement("div");
      row.className = "tl-item";
      if (index === 0) row.style.setProperty("--c", "#fff");
      if (index === items.length - 1) row.style.paddingBottom = "0";
      var year = document.createElement("div");
      year.className = "y";
      year.style.color = "#fff";
      year.textContent = item.year || "";
      var title = document.createElement("h4");
      title.style.color = "#fff";
      title.textContent = item.title || "";
      var description = document.createElement("p");
      description.style.color = "rgba(245,243,239,.6)";
      description.textContent = item.description || "";
      row.append(year, title, description);
      timeline.append(row);
    });
  }

  function applyProducts(products, global) {
    if (!products) return;
    var hero = products.hero || {};
    text(".page-hero h1", hero.title);
    text(".page-hero .lead", hero.lead);
    renderProductFilters(products.categories, products.items);
    renderProducts(products.items, global);
    setupProductFilters();

    var banner = products.banner || {};
    text(".banner .eyebrow", banner.eyebrow);
    text(".banner h2", banner.title);
    text(".banner p", banner.description);
    image("#pr-banner", banner.image);
    setButton(one(".banner .btn-fill"), banner.button_label, banner.button_url);

    var bulk = products.bulk || {};
    text(".section.center .eyebrow", bulk.eyebrow);
    text(".section.center h2", bulk.title);
    text(".section.center .lead", bulk.description);
    setButton(one(".section.center .btn-solid"), bulk.button_label, global.contact_url);
  }

  function renderProductFilters(categories, items) {
    var filters = one(".filters");
    if (!filters) return;
    var counts = countByCategory(items || []);
    filters.replaceChildren();
    (categories || []).forEach(function (category, index) {
      var button = document.createElement("button");
      button.className = "chip" + (index === 0 ? " on" : "");
      button.dataset.f = category.code || "";
      button.append(document.createTextNode(category.label || ""));
      var count = document.createElement("span");
      count.className = "c";
      count.textContent = String(counts[category.code] || 0).padStart(2, "0");
      button.append(count);
      filters.append(button);
    });
  }

  function countByCategory(items) {
    var counts = { all: items.length };
    items.forEach(function (item) {
      var category = item.category_code;
      if (category) counts[category] = (counts[category] || 0) + 1;
    });
    return counts;
  }

  function renderProducts(items, global) {
    var grid = one("#grid");
    if (!grid) return;
    grid.replaceChildren();
    (items || []).forEach(function (item, index) {
      var card = document.createElement("button");
      card.type = "button";
      card.className = "pcard";
      card.dataset.cat = item.category_code || "";
      card.setAttribute("aria-label", (item.name || "Product") + " の詳細を見る");

      var ph = document.createElement("div");
      ph.className = "ph";
      var catSpan = document.createElement("span");
      catSpan.className = "cat";
      catSpan.textContent = item.category_label || "";
      ph.append(catSpan, createImageSlot({
        shape: "rect",
        fit: "contain",
        id: "pr-" + (index + 1),
        placeholder: "製品写真",
        src: item.image,
      }));
      card.append(ph);

      var meta = document.createElement("div");
      meta.className = "meta";
      appendDiv(meta, "pname", item.name);
      appendDiv(meta, "pdesc", item.description);

      var featTags = document.createElement("div");
      featTags.className = "feat-tags";
      (item.features || "").split(",").map(function (v) { return v.trim(); }).filter(Boolean).forEach(function (value) {
        var span = document.createElement("span");
        span.textContent = value;
        featTags.append(span);
      });
      meta.append(featTags);
      appendDiv(meta, "pprice", item.price);

      var buyRow = document.createElement("div");
      buyRow.className = "buy-row";
      var azLink = document.createElement("a");
      azLink.href = item.amazon_url || global.amazon_url || "#";
      azLink.setAttribute("onclick", "showKauComingSoon(event)");
      azLink.className = "buy-btn buy-az";
      azLink.textContent = (global.shop || {}).amazon_label || "Amazonで購入";
      var rkLink = document.createElement("a");
      rkLink.href = item.rakuten_url || global.rakuten_url || "#";
      rkLink.setAttribute("onclick", "showKauComingSoon(event)");
      rkLink.className = "buy-btn buy-rk";
      rkLink.textContent = (global.shop || {}).rakuten_label || "楽天市場";
      buyRow.append(azLink, rkLink);
      meta.append(buyRow);

      card.append(meta);
      card.addEventListener("click", function () {
        openProductDetail(item, global);
      });
      grid.append(card);
    });
  }

  function getProductDetailDrawer() {
    var drawer = one("#kau-product-detail");
    var overlay = one("#kau-product-overlay");
    if (drawer && overlay) return { drawer: drawer, overlay: overlay };

    overlay = document.createElement("div");
    overlay.id = "kau-product-overlay";
    overlay.className = "product-overlay";

    drawer = document.createElement("aside");
    drawer.id = "kau-product-detail";
    drawer.className = "product-drawer";
    drawer.setAttribute("aria-hidden", "true");
    drawer.innerHTML = [
      '<div class="product-drawer-top">',
      '  <span class="product-detail-cat"></span>',
      '  <button class="product-detail-close" type="button" aria-label="Close">×</button>',
      '</div>',
      '<div class="product-drawer-body">',
      '  <div class="product-detail-photo"><img alt=""></div>',
      '  <h2 class="product-detail-name"></h2>',
      '  <p class="product-detail-desc"></p>',
      '  <div class="product-detail-tags"></div>',
      '  <dl class="product-detail-specs">',
      '    <dt>Category</dt><dd data-spec="category"></dd>',
      '    <dt>Features</dt><dd data-spec="features"></dd>',
      '    <dt>Price</dt><dd data-spec="price"></dd>',
      '  </dl>',
      '  <div class="product-detail-actions">',
      '    <a class="primary" data-action="contact" href="#contact">お問い合わせ</a>',
      '    <a class="secondary" data-action="amazon" href="#">Amazonで見る</a>',
      '    <a class="secondary" data-action="rakuten" href="#">楽天市場で見る</a>',
      '  </div>',
      '</div>'
    ].join("");

    document.body.append(overlay, drawer);
    overlay.addEventListener("click", closeProductDetail);
    one(".product-detail-close", drawer).addEventListener("click", closeProductDetail);
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape") closeProductDetail();
    });
    return { drawer: drawer, overlay: overlay };
  }

  function productFeatures(value) {
    return (value || "").split(",").map(function (v) { return v.trim(); }).filter(Boolean);
  }

  function productPrice(value) {
    if (!value) return "お問い合わせ";
    var number = Number(value);
    if (!isNaN(number) && number > 0) return "¥" + number.toLocaleString("ja-JP");
    return String(value);
  }

  function renderProductSpecs(drawer, item, features) {
    var specList = one(".product-detail-specs", drawer);
    if (!specList) return;

    specList.replaceChildren();
    [
      ["Category", item.category_label || "-"],
      ["Features", features.join(" / ") || "-"],
      ["Price", productPrice(item.price)]
    ].forEach(function (pair) {
      var dt = document.createElement("dt");
      var dd = document.createElement("dd");
      dt.textContent = pair[0];
      dd.textContent = pair[1];
      specList.append(dt, dd);
    });

    (item.specs || "").split(/\r?\n/).map(function (line) {
      return line.trim();
    }).filter(Boolean).forEach(function (line) {
      var match = line.match(/^([^:：]+)[:：]\s*(.+)$/);
      var dt = document.createElement("dt");
      var dd = document.createElement("dd");
      dt.textContent = match ? match[1].trim() : "Spec";
      dd.textContent = match ? match[2].trim() : line;
      specList.append(dt, dd);
    });
  }

  function openProductDetail(item, global) {
    var parts = getProductDetailDrawer();
    var drawer = parts.drawer;
    var features = productFeatures(item.features);
    var img = one(".product-detail-photo img", drawer);
    var amazon = one('[data-action="amazon"]', drawer);
    var rakuten = one('[data-action="rakuten"]', drawer);
    var contact = one('[data-action="contact"]', drawer);

    text(".product-detail-cat", item.category_label || "Product", drawer);
    text(".product-detail-name", item.name || "", drawer);
    text(".product-detail-desc", item.detail || item.description || "", drawer);
    renderProductSpecs(drawer, item, features);
    if (img) {
      img.src = mediaUrl(item.image || "");
      img.alt = item.name || "";
    }

    var tagWrap = one(".product-detail-tags", drawer);
    if (tagWrap) {
      tagWrap.replaceChildren();
      features.forEach(function (value) {
        var span = document.createElement("span");
        span.textContent = value;
        tagWrap.append(span);
      });
    }

    if (contact) contact.href = (global && global.contact_url) || "#contact";
    var shop = (global && global.shop) || {};
    setDetailAction(amazon, item.amazon_url || (global && global.amazon_url), shop.amazon_label || "Amazonで見る");
    setDetailAction(rakuten, item.rakuten_url || (global && global.rakuten_url), shop.rakuten_label || "楽天市場で見る");

    parts.overlay.classList.add("open");
    drawer.classList.add("open");
    drawer.setAttribute("aria-hidden", "false");
  }

  function setDetailAction(anchor, url, label) {
    if (!anchor) return;
    anchor.textContent = label;
    anchor.href = url || "#";
    anchor.style.display = url ? "" : "none";
  }

  function closeProductDetail() {
    var drawer = one("#kau-product-detail");
    var overlay = one("#kau-product-overlay");
    if (overlay) overlay.classList.remove("open");
    if (drawer) {
      drawer.classList.remove("open");
      drawer.setAttribute("aria-hidden", "true");
    }
  }

  function appendDiv(parent, className, value) {
    var div = document.createElement("div");
    div.className = className;
    div.textContent = value || "";
    parent.append(div);
  }

  function applyNews(news) {
    if (!news) return;
    var hero = news.hero || {};
    text(".page-hero h1", hero.title);
    text(".page-hero .sub", hero.subtitle);

    var featured = one(".news-feat");
    if (featured && news.featured) {
      text(".d", news.featured.date, featured);
      text(".tag-pill", news.featured.category, featured);
      text("h2", news.featured.title, featured);
      text("p", news.featured.summary, featured);
      text(".btn-line", news.featured.read_more_label, featured);
      if (news.featured.url) featured.href = news.featured.url;
      image("image-slot", news.featured.image, featured);
    }

    renderNewsFilters(news.filters);
    renderNewsItems(news.items);
    setupNewsFilters();
    setButton(one(".pager .nx"), news.pager_next_label, "#");
  }

  function setupProductFilters() {
    var chips = all(".filters .chip");
    chips.forEach(function (chip) {
      chip.addEventListener("click", function () {
        chips.forEach(function (item) { item.classList.remove("on"); });
        chip.classList.add("on");
        var filter = chip.getAttribute("data-f");
        all("#grid .pcard").forEach(function (card) {
          card.classList.toggle("hide", !(filter === "all" || card.getAttribute("data-cat") === filter));
        });
      });
    });
  }

  function renderNewsFilters(filters) {
    var container = one(".news-filters");
    if (!container) return;
    container.replaceChildren();
    (filters || []).forEach(function (filter, index) {
      var button = document.createElement("button");
      button.className = "chip" + (index === 0 ? " on" : "");
      button.dataset.f = filter.code || "";
      button.textContent = filter.label || "";
      container.append(button);
    });
  }

  function setupNewsFilters() {
    var chips = all(".news-filters .chip");
    chips.forEach(function (chip) {
      chip.addEventListener("click", function () {
        chips.forEach(function (item) { item.classList.remove("on"); });
        chip.classList.add("on");
        var filter = chip.getAttribute("data-f");
        all("#nlist .nrow2").forEach(function (row) {
          row.classList.toggle("hide", !(filter === "all" || row.getAttribute("data-cat") === filter));
        });
      });
    });
  }

  function renderNewsItems(items) {
    var list = one("#nlist");
    if (!list) return;
    list.replaceChildren();
    (items || []).forEach(function (item) {
      var row = document.createElement("a");
      row.href = item.url || "#";
      row.className = "nrow2";
      row.dataset.cat = item.category_code || "";
      var date = document.createElement("span");
      date.className = "d";
      date.textContent = item.date || "";
      var tagWrap = document.createElement("span");
      tagWrap.className = "t";
      var tag = document.createElement("span");
      tag.className = "tag-pill";
      tag.textContent = item.category || "";
      tagWrap.append(tag);
      var title = document.createElement("span");
      title.className = "h";
      title.textContent = item.title || "";
      var action = document.createElement("span");
      action.className = "a";
      action.append(createArrowIcon());
      row.append(date, tagWrap, title, action);
      list.append(row);
    });
  }

  function getContent() {
    if (window.KAU_CONTENT_DATA) return Promise.resolve(window.KAU_CONTENT_DATA);
    try {
      var draft = localStorage.getItem("KAU_ADMIN_CONTENT");
      if (draft) return Promise.resolve(JSON.parse(draft));
    } catch (e) {}
    var url = window.KAU_CONTENT_URL || "content/site.json";
    return fetch(url + (url.indexOf("?") >= 0 ? "&" : "?") + "v=" + Date.now())
      .then(function (response) {
        if (!response.ok) throw new Error("Unable to load CMS content");
        return response.json();
      });
  }

  getContent()
    .then(function (content) {
      applyGlobal(content.global);
      var _slug = location.pathname.replace(/\/+$/, "").split("/").filter(Boolean).pop() || "home";
      var page = window.KAU_PAGE || (_slug.slice(-5) === ".html" ? _slug : _slug + ".html");
      if (page === "home.html") applyHome(content.home, content.global);
      if (page === "about.html") applyAbout(content.about, content.global);
      if (page === "products.html") applyProducts(content.products, content.global);
      if (page === "news.html") applyNews(content.news);
    })
    .catch(function (error) {
      console.error(error);
    });
})();
