(function () {
  var body = document.body;
  var toggle = document.getElementById("admin-sidebar-toggle");
  var closeBtn = document.getElementById("admin-sidebar-close");
  var scrim = document.getElementById("admin-sidebar-scrim");
  var sidebar = document.getElementById("admin-sidebar");

  function setOpen(open) {
    if (open) {
      body.classList.add("admin-sidebar-open");
      if (toggle) toggle.setAttribute("aria-expanded", "true");
      if (scrim) scrim.removeAttribute("hidden");
    } else {
      body.classList.remove("admin-sidebar-open");
      if (toggle) toggle.setAttribute("aria-expanded", "false");
      if (scrim) scrim.setAttribute("hidden", "hidden");
    }
  }

  if (toggle && sidebar) {
    toggle.addEventListener("click", function () {
      var open = !body.classList.contains("admin-sidebar-open");
      setOpen(open);
    });
  }

  if (closeBtn) {
    closeBtn.addEventListener("click", function () {
      setOpen(false);
    });
  }

  if (scrim) {
    scrim.addEventListener("click", function () {
      setOpen(false);
    });
  }

  window.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
      setOpen(false);
    }
  });

  window.addEventListener("resize", function () {
    if (window.matchMedia("(min-width: 901px)").matches) {
      setOpen(false);
    }
  });

  document.querySelectorAll(".admin-alert[data-dismissible] .admin-alert__dismiss").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var root = btn.closest(".admin-alert");
      if (root && root.parentElement) {
        root.parentElement.removeChild(root);
      }
    });
  });

  /** Keep the current sidebar item visible after full page navigation (desktop scrollable nav). */
  function scrollActiveSidebarLinkIntoView() {
    var nav = document.querySelector(".admin-sidebar__nav");
    var active = nav && nav.querySelector("a.admin-navlink.is-active");
    if (!nav || !active) {
      return;
    }
    requestAnimationFrame(function () {
      active.scrollIntoView({ block: "center", inline: "nearest", behavior: "auto" });
    });
  }

  scrollActiveSidebarLinkIntoView();
})();

/* ============================================================
   Theme switcher
   Source of truth: PHP session (cms-admin/actions/theme-update.php
   writes $_SESSION['wpm_theme']; includes/header.php reads it and
   renders data-theme on <html> server-side — no FOUC, and it follows
   the admin across every page navigation). This block:
     1. Applies the chosen theme to <html> immediately on change.
     2. Persists it to the session via fetch() so the next page load
        (any menu, any tab) keeps the same theme.
     3. Also mirrors it to localStorage as a same-tab fallback only.
   ============================================================ */
(function () {
  var THEME_KEY    = "wpm-theme";
  var DEFAULT      = "deep-purple";
  var VALID_THEMES = ["dark-modern", "light-modern", "deep-purple"];
  var html         = document.documentElement;

  function persistToSession(theme, select) {
    var action = select.dataset.themeAction;
    var token  = select.dataset.csrfToken;
    if (!action) { return; }
    fetch(action, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
        "X-CSRF-Token": token || ""
      },
      body: "theme=" + encodeURIComponent(theme),
      credentials: "same-origin"
    }).catch(function () {
      /* Network hiccup: theme still applied for this page view via
         localStorage/dataset; it will just re-sync from session on the
         next successful navigation instead. */
    });
  }

  function applyTheme(theme, select) {
    if (VALID_THEMES.indexOf(theme) === -1) { theme = DEFAULT; }
    html.dataset.theme = theme;
    try { localStorage.setItem(THEME_KEY, theme); } catch (e) {}
    if (select) {
      if (select.value !== theme) { select.value = theme; }
      persistToSession(theme, select);
    }
  }

  function initSelect() {
    var select = document.getElementById("theme-switcher");
    if (!select) { return; }
    var current = html.dataset.theme || DEFAULT;
    select.value = current;
    select.addEventListener("change", function () {
      applyTheme(select.value, select);
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initSelect);
  } else {
    initSelect();
  }
}());

/* ============================================================
   Global search (navbar)
   Debounced AJAX call to actions/search.php, renders a grouped
   dropdown of results (Pages & Articles, Contact Messages) under
   the search box.
   ============================================================ */
(function () {
  var input = document.getElementById("admin-search-input");
  var resultsBox = document.getElementById("admin-search-results");
  if (!input || !resultsBox) { return; }

  var wrapper = input.closest(".admin-search");
  var pagesPrefix = (wrapper && wrapper.dataset.pagesPrefix) || "";
  var searchAction = input.dataset.searchAction;
  var debounceTimer = null;
  var activeController = null;

  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function hideResults() {
    resultsBox.setAttribute("hidden", "hidden");
    resultsBox.innerHTML = "";
  }

  function renderResults(items, query) {
    if (!items.length) {
      resultsBox.innerHTML =
        '<div class="admin-search__empty">No results for “' + escapeHtml(query) + '”.</div>';
      resultsBox.removeAttribute("hidden");
      return;
    }

    var groups = {};
    var order = [];
    items.forEach(function (item) {
      if (!groups[item.type]) {
        groups[item.type] = [];
        order.push(item.type);
      }
      groups[item.type].push(item);
    });

    var html = "";
    order.forEach(function (type) {
      html += '<div class="admin-search__group-label">' + escapeHtml(type) + "</div>";
      groups[type].forEach(function (item) {
        var href = pagesPrefix + item.url;
        html +=
          '<a class="admin-search__item" href="' + escapeHtml(href) + '">' +
          '<span class="admin-search__item-title">' + escapeHtml(item.title || "(untitled)") + "</span>" +
          '<span class="admin-search__item-subtitle">' + escapeHtml(item.subtitle || "") + "</span>" +
          "</a>";
      });
    });

    resultsBox.innerHTML = html;
    resultsBox.removeAttribute("hidden");
  }

  function runSearch(query) {
    if (!searchAction) { return; }
    if (activeController) { activeController.abort(); }
    activeController = new AbortController();

    fetch(searchAction + "?q=" + encodeURIComponent(query), {
      credentials: "same-origin",
      signal: activeController.signal
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data || !data.ok) { return; }
        renderResults(data.results || [], query);
      })
      .catch(function () { /* aborted or network hiccup: ignore */ });
  }

  input.addEventListener("input", function () {
    var query = input.value.trim();
    window.clearTimeout(debounceTimer);
    if (query.length < 2) {
      hideResults();
      return;
    }
    debounceTimer = window.setTimeout(function () { runSearch(query); }, 250);
  });

  input.addEventListener("focus", function () {
    if (input.value.trim().length >= 2 && resultsBox.innerHTML) {
      resultsBox.removeAttribute("hidden");
    }
  });

  document.addEventListener("click", function (e) {
    if (!wrapper.contains(e.target)) {
      hideResults();
    }
  });

  input.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
      hideResults();
      input.blur();
    }
  });
}());

/* ============================================================
   Notification bell — Growth Agent jobs needing attention
   (failed generations, SEO recommendations awaiting review).
   Server-rendered dropdown, just a plain show/hide toggle —
   same click-outside-to-close pattern as the search box above.
   ============================================================ */
(function () {
  var toggle = document.getElementById("admin-notif-toggle");
  var panel = document.getElementById("admin-notif-panel");
  var wrapper = document.getElementById("admin-notif");
  if (!toggle || !panel || !wrapper) { return; }

  function isOpen() {
    return !panel.hasAttribute("hidden");
  }

  function setOpen(open) {
    if (open) {
      panel.removeAttribute("hidden");
      toggle.setAttribute("aria-expanded", "true");
    } else {
      panel.setAttribute("hidden", "hidden");
      toggle.setAttribute("aria-expanded", "false");
    }
  }

  toggle.addEventListener("click", function (e) {
    e.stopPropagation();
    setOpen(!isOpen());
  });

  document.addEventListener("click", function (e) {
    if (!wrapper.contains(e.target)) {
      setOpen(false);
    }
  });

  window.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
      setOpen(false);
    }
  });
}());
