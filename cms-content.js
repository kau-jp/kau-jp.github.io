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
    if (element && value) element.setAttribute("src", value);
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

  function createImageSlot(options) {
    var slot = document.createElement("image-slot");
    slot.setAttribute("shape", options.shape || "rect");
    if (options.fit) slot.setAttribute("fit", options.fit);
    if (options.id) slot.id = options.id;
    slot.setAttribute("placeholder", options.placeholder || "画像");
    if (options.src) slot.setAttribute("src", options.src);
    return slot;
  }

  function createArrowIcon() {
    var svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    svg.setAttribute("viewBox", "0 0 24 24");
    svg.setAttribute("aria-hidden", "true");
    var path = document.createElementNS("http://www.w3.org/2000/svg", "path");
    path.setAttribute("d", "M5 12h14M13 6l6 6-6 6");
    svg.append(path);
    return svg;
  }

  function footerAddress(global) {
    all(".footer-addr").forEach(function (element) {
      element.replaceChildren();
      [
        global.company_name,
        global.postal_code,
        global.address_line_1,
        global.address_line_2,
      ].forEach(function (value, index) {
        if (index) element.append(document.createElement("br"));
        element.append(document.createTextNode(value || ""));
      });
    });
  }

  function applyGlobal(global) {
    if (!global) return;
    footerAddress(global);

    all(".nav-shop-menu").forEach(function (menu) {
      var links = all("a", menu);
      if (links[0] && global.amazon_url) links[0].href = global.amazon_url;
      if (links[1] && global.rakuten_url) links[1].href = global.rakuten_url;
    });

    all(".nav-cta").forEach(function (element) {
      if (global.contact_url && global.contact_url !== "#") {
        element.href = global.contact_url;
      }
    });
  }

  function applyHome(home, global) {
    text(".hero-b .eyebrow", home.hero_eyebrow);
    lines(one(".hero-b h1"), home.hero_line_1, home.hero_accent, home.hero_suffix);
    text(".hero-b .sub", home.hero_subtitle);
    image("#b-hero", home.hero_image);
    text(".intro-b h2", home.philosophy);
    text(".show-b .head h3", home.showcase_title);
    renderHomeShowcase(home.showcase);

    text(".split-b .txt .eyebrow", home.feature_eyebrow);
    text(".split-b .txt h2", home.feature_title);
    text(".split-b .txt > p", home.feature_description);
    image("#b-feat", home.feature_image);

    renderHomeValues(home.values);

    lines(one(".cta-b h2"), home.cta_line_1, home.cta_accent, home.cta_suffix);
    text(".cta-b .in > p", home.cta_description);
    image("#b-cta", home.cta_image);
    link(".cta-b .btn-fill", global.contact_url);
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
      media.append(tag);
      media.append(createImageSlot({
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
      meta.append(name);
      var price = document.createElement("span");
      price.className = "pr";
      price.textContent = item.price || "";
      meta.append(price);
      card.append(meta);

      var description = document.createElement("div");
      description.className = "gd";
      description.textContent = item.description || "";
      card.append(description);

      track.append(card);
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
      card.append(title);

      var description = document.createElement("p");
      description.textContent = item.description || "";
      card.append(description);

      grid.append(card);
    });
  }

  function applyAbout(about, global) {
    text(".about-statement .big", about.statement);
    renderPrinciples(about.principles);

    text(".feat2 h2", about.craft_title);
    var craftParagraphs = all(".feat2 > div:last-child > p");
    if (craftParagraphs[0]) craftParagraphs[0].textContent = about.craft_paragraph_1;
    if (craftParagraphs[1]) craftParagraphs[1].textContent = about.craft_paragraph_2;
    image("#ab-craft", about.craft_image);

    renderProfile(about.profile);
    renderHistory(about.history);

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
      if (key && value && accessValues[key.textContent]) {
        value.textContent = accessValues[key.textContent];
      }
    });
    image("#ab-map", about.map_image);
    link(".access .btn-solid", global.contact_url);
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
      card.append(label);

      var title = document.createElement("h3");
      title.textContent = item.title || "";
      card.append(title);

      var description = document.createElement("p");
      description.textContent = item.description || "";
      card.append(description);

      grid.append(card);
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
      row.append(year);

      var title = document.createElement("h4");
      title.style.color = "#fff";
      title.textContent = item.title || "";
      row.append(title);

      var description = document.createElement("p");
      description.style.color = "rgba(245,243,239,.6)";
      description.textContent = item.description || "";
      row.append(description);

      timeline.append(row);
    });
  }

  function normaliseProfile(profile) {
    if (Array.isArray(profile)) return profile;
    if (!profile) return [];

    var rows = [
      ["会社名", "Company", profile.company],
      ["設立", "Founded", profile.founded],
      ["代表者", "CEO", profile.ceo],
      ["資本金", "Capital", profile.capital],
      ["所在地", "Address", profile.address],
      ["事業内容", "Business", profile.business],
      ["従業員数", "Employees", profile.employees],
      ["取引銀行", "Bank", profile.bank],
    ];

    return rows
      .filter(function (row) {
        return row[2] !== undefined && row[2] !== null && row[2] !== "";
      })
      .map(function (row) {
        return { label: row[0], sublabel: row[1], value: row[2] };
      });
  }

  function renderProfile(profile) {
    var table = one(".otable");
    if (!table) return;

    table.replaceChildren();
    normaliseProfile(profile).forEach(function (item) {
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

  function applyProducts(products, global) {
    text(".page-hero .lead", products.lead);

    var grid = one("#grid");
    if (grid) {
      grid.replaceChildren();
      (products.items || []).forEach(function (item, index) {
        var card = document.createElement("div");
        card.className = "pcard";
        card.dataset.cat = item.category_code || "";

        var ph = document.createElement("div");
        ph.className = "ph";
        var catSpan = document.createElement("span");
        catSpan.className = "cat";
        catSpan.textContent = item.category_label || "";
        ph.append(catSpan);
        var slot = document.createElement("image-slot");
        slot.setAttribute("shape", "rect");
        slot.setAttribute("fit", "contain");
        slot.id = "pr-" + (index + 1);
        slot.setAttribute("placeholder", "製品写真");
        if (item.image) slot.setAttribute("src", item.image);
        ph.append(slot);
        card.append(ph);

        var meta = document.createElement("div");
        meta.className = "meta";

        var pname = document.createElement("div");
        pname.className = "pname";
        pname.textContent = item.name || "";
        meta.append(pname);

        var pdesc = document.createElement("div");
        pdesc.className = "pdesc";
        pdesc.textContent = item.description || "";
        meta.append(pdesc);

        var featTags = document.createElement("div");
        featTags.className = "feat-tags";
        (item.features || "").split(",").map(function (v) { return v.trim(); }).filter(Boolean).forEach(function (v) {
          var span = document.createElement("span");
          span.textContent = v;
          featTags.append(span);
        });
        meta.append(featTags);

        var pprice = document.createElement("div");
        pprice.className = "pprice";
        pprice.textContent = item.price || "";
        meta.append(pprice);

        var buyRow = document.createElement("div");
        buyRow.className = "buy-row";
        var azLink = document.createElement("a");
        azLink.href = item.amazon_url || global.amazon_url || "#";
        azLink.setAttribute("onclick", "showKauComingSoon(event)");
        azLink.className = "buy-btn buy-az";
        azLink.textContent = "Amazonで購入";
        buyRow.append(azLink);
        var rkLink = document.createElement("a");
        rkLink.href = item.rakuten_url || global.rakuten_url || "#";
        rkLink.setAttribute("onclick", "showKauComingSoon(event)");
        rkLink.className = "buy-btn buy-rk";
        rkLink.textContent = "楽天市場";
        buyRow.append(rkLink);
        meta.append(buyRow);

        card.append(meta);
        grid.append(card);
      });

      // update filter chip counts based on actual data
      var items = products.items || [];
      var counts = { all: items.length };
      items.forEach(function (item) {
        var c = item.category_code;
        if (c) counts[c] = (counts[c] || 0) + 1;
      });
      all(".chip[data-f]").forEach(function (chip) {
        var f = chip.getAttribute("data-f");
        var countEl = one(".c", chip);
        if (countEl && counts[f] !== undefined) {
          countEl.textContent = String(counts[f]).padStart(2, "0");
        }
      });
    }

    text(".banner h2", products.banner_title);
    text(".banner p", products.banner_description);
    image("#pr-banner", products.banner_image);
    text(".section.center h2", products.bulk_title);
    text(".section.center .lead", products.bulk_description);
    link(".section.center .btn-solid", global.contact_url);
  }

  function applyNews(news) {
    var featured = one(".news-feat");
    if (featured && news.featured) {
      text(".d", news.featured.date, featured);
      text(".tag-pill", news.featured.category, featured);
      text("h2", news.featured.title, featured);
      text("p", news.featured.summary, featured);
      if (news.featured.url) featured.href = news.featured.url;
      image("image-slot", news.featured.image, featured);
    }

    renderNewsItems(news.items);
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
      row.append(date);

      var tagWrap = document.createElement("span");
      tagWrap.className = "t";
      var tag = document.createElement("span");
      tag.className = "tag-pill";
      tag.textContent = item.category || "";
      tagWrap.append(tag);
      row.append(tagWrap);

      var title = document.createElement("span");
      title.className = "h";
      title.textContent = item.title || "";
      row.append(title);

      var action = document.createElement("span");
      action.className = "a";
      action.append(createArrowIcon());
      row.append(action);

      list.append(row);
    });
  }

  fetch("content/site.json?v=" + Date.now())
    .then(function (response) {
      if (!response.ok) throw new Error("Unable to load CMS content");
      return response.json();
    })
    .then(function (content) {
      applyGlobal(content.global);
      var page = location.pathname.split("/").pop() || "home.html";
      if (page === "home.html") applyHome(content.home, content.global);
      if (page === "about.html") applyAbout(content.about, content.global);
      if (page === "products.html") applyProducts(content.products, content.global);
      if (page === "news.html") applyNews(content.news);
    })
    .catch(function (error) {
      console.error(error);
    });
})();
