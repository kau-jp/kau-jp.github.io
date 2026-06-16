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

    all(".gcard").forEach(function (card, index) {
      var item = home.showcase[index];
      if (!item) return;
      text(".gtag", item.category, card);
      text(".nm", item.name, card);
      text(".pr", item.price, card);
      text(".gd", item.description, card);
      image("image-slot", item.image, card);
    });

    text(".split-b .txt .eyebrow", home.feature_eyebrow);
    text(".split-b .txt h2", home.feature_title);
    text(".split-b .txt > p", home.feature_description);
    image("#b-feat", home.feature_image);

    all(".band-grid .v").forEach(function (card, index) {
      var item = home.values[index];
      if (!item) return;
      text("h4", item.title, card);
      text("p", item.description, card);
    });

    lines(one(".cta-b h2"), home.cta_line_1, home.cta_accent, home.cta_suffix);
    text(".cta-b .in > p", home.cta_description);
    image("#b-cta", home.cta_image);
    link(".cta-b .btn-fill", global.contact_url);
  }

  function applyAbout(about, global) {
    text(".about-statement .big", about.statement);

    all(".philo3 .p").forEach(function (card, index) {
      var item = about.principles[index];
      if (!item) return;
      text(".n", item.label, card);
      text("h3", item.title, card);
      text("p", item.description, card);
    });

    text(".feat2 h2", about.craft_title);
    var craftParagraphs = all(".feat2 > div:last-child > p");
    if (craftParagraphs[0]) craftParagraphs[0].textContent = about.craft_paragraph_1;
    if (craftParagraphs[1]) craftParagraphs[1].textContent = about.craft_paragraph_2;
    image("#ab-craft", about.craft_image);

    renderProfile(about.profile);

    all(".timeline .tl-item").forEach(function (itemElement, index) {
      var item = about.history[index];
      if (!item) return;
      text(".y", item.year, itemElement);
      text("h4", item.title, itemElement);
      text("p", item.description, itemElement);
    });

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

    all("#nlist .nrow2").forEach(function (row, index) {
      var item = news.items[index];
      if (!item) return;
      row.dataset.cat = item.category_code || "";
      row.href = item.url || "#";
      text(".d", item.date, row);
      text(".tag-pill", item.category, row);
      text(".h", item.title, row);
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
