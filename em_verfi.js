// Extracted from em_verfi.php — combined inline <script> blocks

(function () {
  // --- SAFE STUBS (prevent ReferenceErrors) ---
  window.initTopScrollbars =
    window.initTopScrollbars ||
    function () {
      console.warn("initTopScrollbars called before definition");
    };

  window.updateStatus =
    window.updateStatus ||
    function (id, status) {
      console.warn("updateStatus called before definition", { id, status });
      (window._queuedUpdateStatusCalls =
        window._queuedUpdateStatusCalls || []).push({ id, status });
    };

  ("use strict");
  // --- BOOTSTRAP STUBS: ensure certain functions exist early to avoid ReferenceErrors ---
  window.initTopScrollbars =
    window.initTopScrollbars ||
    function () {
      if (typeof window._real_initTopScrollbars === "function") {
        try {
          window._real_initTopScrollbars();
        } catch (e) {
          console.warn("initTopScrollbars stub forward failed", e);
        }
      }
    };

  window.updateStatus =
    window.updateStatus ||
    function (id, status) {
      console.warn("updateStatus called before definition", {
        id: id,
        status: status,
      });
      (window._queuedUpdateStatusCalls =
        window._queuedUpdateStatusCalls || []).push({ id: id, status: status });
    };

  /* --- inline script block #1 --- */
  /* fragment injected */

  /* --- inline script block #2 --- */
  // Helper: readbankheader select if present
  function getSelectedBank() {
    const el = document.getElementById("bankFilter");
    return el ? el.value.trim() : "";
  }

  (function () {
    // run after your calendar builder exists
    const calWrap = document.querySelector(".status-center .calendar-wrap");
    const dateInput = document.getElementById("statusDate");
    const selDateEl = document.querySelector(".status-center .sel-date");

    if (!calWrap) return;

    // helper: normalize YYYY-MM-DD
    function toISO(y, m, d) {
      return `${y}-${String(m + 1).padStart(2, "0")}-${String(d).padStart(
        2,
        "0"
      )}`;
    }

    // patch renderMonth: wrap existing renderMonth or re-run marking after render
    function markTodayAndDefaultSelection() {
      const datesGrid = calWrap.querySelector(".cal-dates");
      if (!datesGrid) return;
      const today = new Date();
      const todayY = today.getFullYear(),
        todayM = today.getMonth(),
        todayD = today.getDate();

      // remove old classes
      datesGrid.querySelectorAll("span").forEach((s) => {
        s.classList.remove("today");
      });

      // find and mark today's cell(s) for current visible month
      datesGrid.querySelectorAll("span").forEach((s) => {
        if (s.classList.contains("disable")) return;
        const val = parseInt(s.textContent, 10);
        if (!isNaN(val)) {
          // determine the month displayed by header
          const header = calWrap.querySelector(".cal-title");
          if (!header) return;
          const title = header.textContent || "";
          // parse displayed month/year (locale-safe fallback)
          const parts = title.split(" ");
          if (parts.length >= 2) {
            const monName = parts.slice(0, parts.length - 1).join(" ");
            const yr = parseInt(parts[parts.length - 1], 10);
            const testDate = new Date(`${monName} 1 ${yr}`);
            if (!isNaN(testDate)) {
              const monIndex = testDate.getMonth();
              if (yr === todayY && monIndex === todayM && val === todayD) {
                s.classList.add("today");
                // if no explicit input value, default-select today
                if (dateInput && !dateInput.value) {
                  // clear existing active
                  datesGrid
                    .querySelectorAll("span")
                    .forEach((x) => x.classList.remove("active"));
                  s.classList.add("active");
                  const iso = toISO(yr, monIndex, val);
                  dateInput.value = iso;
                  const ev = new Event("change", { bubbles: true });
                  dateInput.dispatchEvent(ev);
                  if (selDateEl) selDateEl.textContent = iso;
                } else if (
                  !dateInput &&
                  selDateEl &&
                  !selDateEl.textContent.trim()
                ) {
                  // if no input exists, set sel-date to today without changing anything else
                  selDateEl.textContent = toISO(todayY, todayM, todayD);
                }
              }
            }
          }
        }
      });
    }
    // Observe changes to the dates grid so we can mark today after each render
    const observer = new MutationObserver(() => markTodayAndDefaultSelection());
    const datesGrid = calWrap.querySelector(".cal-dates");
    if (datesGrid)
      observer.observe(datesGrid, { childList: true, subtree: true });

    // Also call once immediately in case the grid is already rendered
    markTodayAndDefaultSelection();

    // Also re-run after month nav clicks (safer)
    const prevBtn = calWrap.querySelector(".cal-prev"),
      nextBtn = calWrap.querySelector(".cal-next");
    [prevBtn, nextBtn].forEach((btn) => {
      if (btn)
        btn.addEventListener("click", () =>
          setTimeout(markTodayAndDefaultSelection, 60)
        );
    });

    // Keep a small handler for hover visual — not strictly necessary but ensures interaction
    calWrap.addEventListener("mouseover", (e) => {
      const el = e.target;
      if (el && el.matches && el.matches(".cal-dates span.today")) {
        // optional: you could also set some dynamic CSS vars here for glow
        // document.documentElement.style.setProperty('--qit-today-glow','1');
      }
    });
  })();

  (function () {
    /* --- Clock: markers + hands update --- */
    const clockCard = document.querySelector(".status-left .clock-card");
    const clockLabel = document.querySelector(".status-left .clock-large");
    const clockMeta = document.getElementById("statusClockMeta");

    if (clockCard && clockLabel) {
      // ensure markers container (only once)
      if (!clockCard.querySelector(".qit-clock-markers")) {
        const markers = document.createElement("div");
        markers.className = "qit-clock-markers";
        clockCard.appendChild(markers);
        // create hour numbers
        for (let i = 0; i < 12; i++) {
          const n = i === 0 ? 12 : i;
          const el = document.createElement("div");
          el.className = "qit-hour-marker";
          const angle = i * 30;
          el.style.transform = `rotate(${angle}deg) translateY(-${
            parseFloat(
              getComputedStyle(document.documentElement).getPropertyValue(
                "--qit-clock-size"
              ) || 180
            ) * 0.4325 || -77
          }px) rotate(-${angle}deg)`;
          el.style.left = "50%";
          el.style.top = "50%";
          el.style.margin = "0";
          el.textContent = n;
          markers.appendChild(el);
        }
        // minute ticks (skip multiples of 5)
        for (let i = 0; i < 60; i++) {
          if (i % 5 === 0) continue;
          const tick = document.createElement("div");
          tick.className = "qit-minute-tick";
          const angle = i * 6;
          tick.style.transform = `rotate(${angle}deg) translateY(-${
            parseFloat(
              getComputedStyle(document.documentElement).getPropertyValue(
                "--qit-clock-size"
              ) || 180
            ) * 0.4325 || -77
          }px)`;
          markers.appendChild(tick);
        }
      }

      // pivot dot
      if (!clockCard.querySelector(".pivot-dot")) {
        const dot = document.createElement("div");
        dot.className = "pivot-dot";
        clockCard.appendChild(dot);
      }

      // update function
      function updateClock() {
        const now = new Date();
        const s = now.getSeconds() + now.getMilliseconds() / 1000;
        const m = now.getMinutes() + s / 60;
        const h = (now.getHours() % 12) + m / 60;
        const sdeg = s * 6;
        const mdeg = m * 6;
        const hdeg = h * 30;
        // set CSS vars
        clockCard.style.setProperty("--sdeg", sdeg);
        clockLabel.style.setProperty("--mdeg", mdeg);
        clockLabel.style.setProperty("--hdeg", hdeg);
        // digital time
        const hh = String(now.getHours()).padStart(2, "0");
        const mm = String(now.getMinutes()).padStart(2, "0");
        const ss = String(now.getSeconds()).padStart(2, "0");
        clockLabel.textContent = `${hh}:${mm}:${ss}`;
        if (clockMeta) clockMeta.textContent = now.toDateString();
      }
      updateClock();
      setInterval(updateClock, 60); // smooth enough (CSS transitions give fluid look)
    }

    /* --- Calendar: build month UI inside calendar-wrap --- */
    const calWrap = document.querySelector(".status-center .calendar-wrap");
    const dateInput = document.getElementById("statusDate");
    const selDateEl = document.querySelector(".status-center .sel-date");

    if (calWrap) {
      // create container structure only if not present
      if (!calWrap.querySelector(".cal-header")) {
        calWrap.innerHTML = `
          <div class="cal-header">
            <div class="cal-title"></div>
            <div class="cal-nav">
              <button class="cal-btn cal-prev" aria-label="Previous month">&lt;</button>
              <button class="cal-btn cal-next" aria-label="Next month">&gt;</button>
            </div>
          </div>
          <div class="cal-days"></div>
          <div class="cal-dates"></div>
          <div class="date-display">Selected: <span class="sel-date">—</span></div>
        `;
      }

      const headerTitle = calWrap.querySelector(".cal-title");
      const prevBtn = calWrap.querySelector(".cal-prev");
      const nextBtn = calWrap.querySelector(".cal-next");
      const daysRow = calWrap.querySelector(".cal-days");
      const datesGrid = calWrap.querySelector(".cal-dates");

      // week day labels
      const labels = ["SU", "MO", "TU", "WE", "TH", "FR", "SA"];
      daysRow.innerHTML = labels
        .map((l) => `<div style="flex:1;text-align:center">${l}</div>`)
        .join("");

      // month state
      let current =
        dateInput && dateInput.value ? new Date(dateInput.value) : new Date();
      current.setDate(1);

      function renderMonth(dateObj) {
        const y = dateObj.getFullYear();
        const m = dateObj.getMonth();
        headerTitle.textContent = dateObj.toLocaleString(undefined, {
          month: "long",
          year: "numeric",
        });
        datesGrid.innerHTML = "";
        // first day index and days count
        const firstIndex = new Date(y, m, 1).getDay();
        const daysCount = new Date(y, m + 1, 0).getDate();
        // prev month tail
        const prevTail = new Date(y, m, 0).getDate();
        for (let i = 0; i < firstIndex; i++) {
          const el = document.createElement("span");
          el.className = "disable";
          el.textContent = prevTail - firstIndex + 1 + i;
          datesGrid.appendChild(el);
        }
        // this month days
        for (let d = 1; d <= daysCount; d++) {
          const el = document.createElement("span");
          el.textContent = d;
          const thisDate = new Date(y, m, d);
          // active today's selected
          const sel =
            dateInput && dateInput.value ? new Date(dateInput.value) : null;
          if (
            sel &&
            sel.getFullYear() === y &&
            sel.getMonth() === m &&
            sel.getDate() === d
          ) {
            el.classList.add("active");
          }
          el.addEventListener("click", () => {
            if (dateInput) {
              // set input and dispatch change
              const iso = `${y}-${String(m + 1).padStart(2, "0")}-${String(
                d
              ).padStart(2, "0")}`;
              dateInput.value = iso;
              const ev = new Event("change", { bubbles: true });
              dateInput.dispatchEvent(ev);
            } else {
              if (selDateEl)
                selDateEl.textContent = `${y}-${String(m + 1).padStart(
                  2,
                  "0"
                )}-${String(d).padStart(2, "0")}`;
            }
            // update active classes
            datesGrid
              .querySelectorAll("span")
              .forEach((s) => s.classList.remove("active"));
            el.classList.add("active");
          });
          datesGrid.appendChild(el);
        }
        // next month head (fill trailing)
        const totalCells = firstIndex + daysCount;
        const trailing = (7 - (totalCells % 7)) % 7;
        for (let t = 1; t <= trailing; t++) {
          const el = document.createElement("span");
          el.className = "disable";
          el.textContent = t;
          datesGrid.appendChild(el);
        }
      }

      prevBtn.addEventListener("click", () => {
        current.setMonth(current.getMonth() - 1);
        renderMonth(current);
      });
      nextBtn.addEventListener("click", () => {
        current.setMonth(current.getMonth() + 1);
        renderMonth(current);
      });

      // sync with existing date input
      if (dateInput) {
        // initialize from input value if present
        if (dateInput.value) {
          const parsed = new Date(dateInput.value);
          if (!isNaN(parsed)) {
            current = new Date(parsed.getFullYear(), parsed.getMonth(), 1);
          }
        }
        // update selected label when input changes
        dateInput.addEventListener("change", function () {
          const v = this.value || "—";
          if (selDateEl) selDateEl.textContent = v;
          // animate card
          calWrap.classList.remove("anim-flip");
          void calWrap.offsetWidth;
          calWrap.classList.add("anim-flip");
          // re-render month to mark active
          if (this.value) {
            const p = new Date(this.value);
            current = new Date(p.getFullYear(), p.getMonth(), 1);
          }
          renderMonth(current);
          setTimeout(() => calWrap.classList.remove("anim-flip"), 700);
        });
        // set initial selected text
        selDateEl.textContent =
          dateInput.value || new Date().toISOString().slice(0, 10);
      } else {
        selDateEl.textContent = new Date().toISOString().slice(0, 10);
      }

      // initial render
      renderMonth(current);
    }
  })();

  // show/hide sections
  function showSection(sectionId) {
    const sections = [
      "employeeTable",
      "operatorStatusSection",
      "operatorOverviewSection",
      "operatorMailingSection",
      "existingOperatorUploadSection",
    ];
    sections.forEach((s) => {
      const el = document.getElementById(s);
      if (el) el.classList.add("hidden");
    });
    const target = document.getElementById(sectionId);
    if (target) {
      target.classList.remove("hidden");
      target.classList.add("section-visible");
    }
    if (sectionId === "operatorOverviewSection") loadOverview("", 1);
  }

  function getCurrentSearchParam() {
    try {
      const url = new URL(window.location.href);
      return url.searchParams.get("search") || "";
    } catch (e) {
      return "";
    }
  }

  // Updated: loadOverview now includes bank param read from UI
  function loadOverview(filter = "", page = 1) {
    const placeholder = document.getElementById("overviewTablePlaceholder");
    const search = getCurrentSearchParam();
    const bankSel = getSelectedBank();
    const params = new URLSearchParams();
    params.set("ajax", "1");
    if (filter) params.set("filter", filter);
    if (page) params.set("page", page);
    if (search) params.set("search", search);
    if (bankSel) params.set("bank", bankSel);
    const url = "em_verfi.php?" + params.toString();
    if (placeholder)
      placeholder.innerHTML = '<div class="k-card">Loading…</div>';
    $.ajax({
      url: url,
      method: "GET",
      dataType: "html",
      cache: false,
      success: function (html) {
        if (placeholder) placeholder.innerHTML = html;
        const exportEl = document.getElementById("topExport");
        if (exportEl) {
          const p = new URLSearchParams();
          p.set("export", "1");
          if (filter) p.set("filter", filter);
          if (search) p.set("search", search);
          if (bankSel) p.set("bank", bankSel);
          exportEl.href = "em_verfi.php?" + p.toString();
        }
        initAutoScrollAll();
        initTopScrollbars();
        // bind row export buttons after fragment load
        if (typeof bindRowExports === "function") bindRowExports();
      },
      error: function (xhr, status, err) {
        if (placeholder)
          placeholder.innerHTML =
            '<div class="k-card">Error loading overview table</div>';
        console.error(
          "Overview fetch error",
          status,
          err,
          xhr && xhr.responseText
        );
      },
    });
  }

  // small helpers (AJAX posts)
  // fancy toast — animates, color, fades after 4s, not an alert dialog
  function toast(msg, opts = {}) {
    const containerId = "qait-toast-container";
    let cont = document.getElementById(containerId);
    if (!cont) {
      cont = document.createElement("div");
      cont.id = containerId;
      cont.style =
        "position:fixed;left:50%;transform:translateX(-50%);bottom:28px;z-index:99999;";
      document.body.appendChild(cont);
    }
    const el = document.createElement("div");
    el.className = "qait-toast";
    el.style =
      "min-width:220px;margin:6px;padding:10px 14px;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,0.12);font-weight:600;opacity:0;transition:opacity .35s,transform .35s;";
    // default colors, adapt if opts.type given
    if (opts.type === "error")
      (el.style.background = "#ffe6e6"), (el.style.color = "#611");
    else if (opts.type === "success")
      (el.style.background = "#e8f7e9"), (el.style.color = "#223");
    else (el.style.background = "#fff8e6"), (el.style.color = "#333");
    el.textContent = msg;
    cont.appendChild(el);

    // animate in
    requestAnimationFrame(() => {
      el.style.opacity = 1;
      el.style.transform = "translateY(0)";
    });

    // auto-fade after 4s
    setTimeout(() => {
      el.style.opacity = 0;
      setTimeout(() => {
        if (el && el.parentNode) el.parentNode.removeChild(el);
      }, 450);
    }, 4000);
  }

  // --- UPDATED updateStatus: safer cell update ---
  function updateStatus(id, status) {
    $.post(
      "update_status.php",
      { id: id, status: status },
      function (res) {
        if (res && res.success) {
          // update the row cell if fragment isn't going to be reloaded immediately
          const tr = document.getElementById("row-" + id);
          if (tr) {
            const sCell = tr.querySelector('td[data-col="status"]');
            if (sCell) {
              const inner = sCell.querySelector("strong, span") || sCell;
              inner.textContent = status;
            }
          }

          toast(res.message || "Updated");

          // now refresh the overview fragment to keep counts/pagination consistent
          if (typeof loadOverview === "function") {
            try {
              const f = window.currentFilter || "";
              const p = window.currentPage || 1;
              // reload current filter + page (AJAX fragment)
              loadOverview(f, p);
            } catch (e) {
              // fallback to full reload if something unexpected occurs
              console.warn(
                "loadOverview call failed, falling back to reload",
                e
              );
              location.reload();
            }
          } else {
            // if loadOverview not defined for some reason, fallback to safe full reload
            location.reload();
          }
        } else {
          toast(
            "Update failed: " + (res && res.message ? res.message : "unknown"),
            { type: "error" }
          );
          console.warn("updateStatus failed", res);
        }
      },
      "json"
    ).fail(function (xhr, status, err) {
      toast("Request failed", { type: "error" });
      console.error("updateStatus fail", status, err, xhr && xhr.responseText);
    });
  }

  // --- UPDATED setWork: updates <strong> inside work cell and panel ---
  function setWork(id, work) {
    $.post(
      "update_status.php",
      { id: id, work_status: work },
      function (res) {
        if (res && res.success) {
          // update inline cell
          const workWrap = document.getElementById("work-" + id);
          if (workWrap) {
            const strong = workWrap.querySelector("strong");
            if (strong) strong.textContent = work;
            else workWrap.textContent = work;
          }

          // if panel open for this id, update its work status display
          const panel = document.getElementById("opDetailPanel");
          if (panel && panel.dataset.opId == String(id)) {
            const docsStage = panel.querySelector('.stage[data-stage="docs"]');
            if (docsStage) {
              const rows = docsStage.querySelectorAll(".op-row");
              rows.forEach((r) => {
                const k = r.querySelector(".k");
                const v = r.querySelector(".v");
                if (k && v && /work status/i.test(k.textContent || "")) {
                  v.textContent = work;
                }
              });
            }
          }

          toast(res.message || "Work updated");

          // refresh overview to reflect new working / not working counts and keep filter stable
          if (typeof loadOverview === "function") {
            try {
              const f = window.currentFilter || "";
              const p = window.currentPage || 1;
              loadOverview(f, p);
            } catch (e) {
              console.warn(
                "loadOverview call failed after setWork, falling back to reload",
                e
              );
              location.reload();
            }
          } else {
            location.reload();
          }
        } else {
          toast(
            "Update failed: " + (res && res.message ? res.message : "unknown"),
            { type: "error" }
          );
          console.warn("setWork failed", res);
        }
      },
      "json"
    ).fail(function (xhr, status, err) {
      toast("Request failed — check console", { type: "error" });
      console.error("setWork failed", status, err, xhr && xhr.responseText);
    });
  }

  function saveReview(id) {
    const el = document.getElementById("review-" + id);
    if (!el) return;
    $.post(
      "update_review.php",
      { id: id, review_notes: el.value },
      function (res) {
        if (res && res.success) toast(res.message || "Saved");
        else toast("Save failed");
      },
      "json"
    ).fail(function () {
      toast("Request failed");
    });
  }
  function makeRowEditable(id) {
    const tr = document.getElementById("row-" + id);
    if (!tr) return;
    tr.querySelectorAll("td[data-col]").forEach((td) => {
      const col = td.getAttribute("data-col");
      if (!col) return;
      if (col.endsWith("_file") || col === "created_at") return;
      const cur = td.textContent.trim();
      const input = document.createElement("input");
      input.value = cur;
      input.setAttribute("data-edit", "1");
      input.style.width = "100%";
      input.className = "input-compact";
      td.innerHTML = "";
      td.appendChild(input);
    });
    toast("Row editable — edit and click Save Row");
  }
  function saveRow(id) {
    const tr = document.getElementById("row-" + id);
    if (!tr) return;
    const payload = { id: id };
    tr.querySelectorAll("td[data-col]").forEach((td) => {
      const col = td.getAttribute("data-col");
      if (!col) return;
      const input = td.querySelector("input[data-edit]");
      if (input) payload[col] = input.value;
    });
    $.post(
      "update_row.php",
      payload,
      function (res) {
        if (res && res.success) {
          toast(res.message || "Saved");
          if (
            !document
              .getElementById("operatorOverviewSection")
              .classList.contains("hidden")
          )
            loadOverview("", 1);
          else location.reload();
        } else toast("Save failed");
      },
      "json"
    ).fail(function () {
      toast("Request failed");
    });
  }

  // DOM ready
  document.addEventListener("DOMContentLoaded", function () {
    feather.replace();
    showSection("operatorStatusSection");

    document.querySelectorAll("[data-section]").forEach((el) => {
      el.addEventListener("click", function (e) {
        e.preventDefault();
        const section = el.getAttribute("data-section");
        const fil = el.getAttribute("data-filter") || "";
        showSection(section);
        document
          .querySelectorAll("[data-section]")
          .forEach((x) => x.classList.remove("is-active"));
        el.classList.add("is-active");
        if (section === "operatorOverviewSection") loadOverview(fil, 1);
      });
    });

    // header search
    const topBtn = document.getElementById("topSearchBtn"),
      topInp = document.getElementById("topSearch");
    if (topBtn && topInp)
      topBtn.addEventListener("click", function () {
        const q = topInp.value.trim();
        const url = new URL(window.location.href);
        const params = url.searchParams;
        if (q) params.set("search", q);
        else params.delete("search");
        // preserve bank filter in URL
        const selBank = getSelectedBank();
        if (selBank) params.set("bank", selBank);
        else params.delete("bank");
        window.location = window.location.pathname + "?" + params.toString();
      });

    // bank filter change -> refresh overview if overview visible, else update URL param
    const bankEl = document.getElementById("bankFilter");
    if (bankEl) {
      bankEl.addEventListener("change", function () {
        // if in overview section, reload via AJAX, else update URL to remember filter
        const overviewVisible = !document
          .getElementById("operatorOverviewSection")
          .classList.contains("hidden");
        if (overviewVisible) {
          loadOverview("", 1);
        } else {
          const url = new URL(window.location.href);
          const params = url.searchParams;
          if (this.value) params.set("bank", this.value);
          else params.delete("bank");
          window.history.replaceState(
            {},
            "",
            window.location.pathname + "?" + params.toString()
          );
        }
      });
    }

    // initialize donut
    initCharts();

    // calendar input binding + animated style
    const dateInput = document.getElementById("statusDate");
    const dateDisplay = document.querySelector(".sel-date");
    if (dateInput && dateDisplay) {
      dateInput.addEventListener("change", function () {
        dateDisplay.textContent = this.value || "—";
      });
      const now = new Date();
      const s = now.toISOString().slice(0, 10);
      dateInput.value = s;
      dateDisplay.textContent = s;
    }

    // resizable sidebar + toggle
    makeSidebarResizable();
    bindSidebarToggle();

    // clock
    startSmoothClock();

    // bind row exports for server-rendered initial table
    if (typeof bindRowExports === "function") bindRowExports();

    // bulk export (top control)
    var bulkBtn = document.getElementById("bulkExportBtn");
    if (bulkBtn) {
      bulkBtn.addEventListener("click", function () {
        var sel = document.getElementById("bulkExportSelect");
        var doc = sel ? sel.value : "";
        if (!doc) {
          alert("Choose a document to export");
          return;
        }
        var params = new URLSearchParams();
        params.set("doc", doc);

        // preserve current filter/search/bank (read from URL or UI)
        var urlp = new URL(window.location.href).searchParams;
        if (urlp.get("filter")) params.set("filter", urlp.get("filter"));
        if (urlp.get("search")) params.set("search", urlp.get("search"));
        var bankSel = getSelectedBank();
        if (bankSel) params.set("bank", bankSel);

        window.location.href = "export_all.php?" + params.toString();
      });
    }
  });

  // CHARTS: only status donut
  function initCharts() {
    try {
      const statusDonutCtx = document.getElementById("statusDonut");
      if (statusDonutCtx && !statusDonutCtx.__chartInited) {
        // Read server-supplied values from PHP (via window._ev)
        const sv = window._ev || {};
        const statusData = [
          Number(sv.operatorFilledCount) || 0,
          Number(sv.operatorPendingCount) || 0,
        ];
        new Chart(statusDonutCtx, {
          type: "doughnut",
          data: {
            labels: ["Accepted", "Pending"],
            datasets: [
              {
                data: statusData,
                backgroundColor: ["#3BAFDA", "#FFB020", "#EF4444"],
                hoverOffset: 8,
              },
            ],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: {
              animateRotate: true,
              duration: 900,
              easing: "easeOutCubic",
            },
            plugins: {
              legend: { position: "bottom" },
              datalabels: {
                color: "#222",
                formatter: function (value, ctx) {
                  const dataArr = ctx.chart.data.datasets[0].data;
                  const sum = dataArr.reduce((a, b) => a + b, 0);
                  if (!sum) return "";
                  return Math.round((value / sum) * 100) + "%";
                },
                font: { weight: "700", size: 12 },
                anchor: "center",
              },
            },
          },
          plugins: [ChartDataLabels],
        });
        statusDonutCtx.__chartInited = true;
      }
    } catch (e) {
      console.warn(e);
    }
  }

  // Smooth animated digital clock + visual ring pulse
  function startSmoothClock() {
    const el = document.getElementById("statusClock");
    const meta = document.getElementById("statusClockMeta");
    if (!el) return;
    function update() {
      const now = new Date();
      const hh = now.getHours().toString().padStart(2, "0");
      const mm = now.getMinutes().toString().padStart(2, "0");
      const ss = now.getSeconds().toString().padStart(2, "0");
      el.classList.remove("tick-anim");
      void el.offsetWidth;
      el.textContent = `${hh}:${mm}:${ss}`;
      el.classList.add("tick-anim");
      if (meta) meta.textContent = now.toDateString();
    }
    update();
    setInterval(update, 1000);
  }

  // AUTO-SCROLL for wide table + thumb sync (left <-> right)
  (function () {
    // requestAnimationFrame loop holders for each wrap
    const loops = new Map();

    window.initAutoScrollAll = function () {
      document.querySelectorAll(".auto-scroll-wrap").forEach((wrap) => {
        const inner = wrap.querySelector(".table-scroll-inner");
        const indicator = wrap.querySelector(".scroll-indicator");
        const thumb = indicator
          ? indicator.querySelector(".scroll-thumb")
          : null;
        if (!inner) return;
        inner.style.animation = "";
        inner.style.transform = "";
        if (loops.has(inner)) {
          cancelAnimationFrame(loops.get(inner));
          loops.delete(inner);
        }
        const visibleW = wrap.clientWidth;
        const contentW = inner.scrollWidth;
        if (contentW > visibleW + 8) {
          const distance = contentW - visibleW;
          const duration = Math.max(8, Math.min(45, Math.round(distance / 20))); // seconds
          inner.style.animation = `auto-scroll ${duration}s linear infinite alternate`;
          wrap.classList.add("auto-scrolling");

          // indicator and thumb sizing
          if (indicator && thumb) {
            indicator.style.display = "block";
            const ratio = visibleW / contentW;
            const thumbW = Math.max(
              40,
              Math.round(ratio * indicator.clientWidth)
            );
            thumb.style.width = thumbW + "px";
            thumb.style.left = "0px";
          }

          // pause on hover/wheel
          wrap.addEventListener(
            "mouseenter",
            () => (inner.style.animationPlayState = "paused")
          );
          wrap.addEventListener(
            "mouseleave",
            () => (inner.style.animationPlayState = "running")
          );
          wrap.addEventListener(
            "wheel",
            () => {
              inner.style.animationPlayState = "paused";
              setTimeout(
                () => (inner.style.animationPlayState = "running"),
                1200
              );
            },
            { passive: true }
          );

          // sync thumb position with transform using RAF
          function sync() {
            try {
              const cs = window.getComputedStyle(inner);
              const m = cs.transform || cs.webkitTransform || cs.msTransform;
              let translateX = 0;
              if (m && m !== "none") {
                // matrix(a, b, c, d, tx, ty)
                const nums = m.match(/matrix.*\((.+)\)/);
                if (nums && nums[1]) {
                  const parts = nums[1]
                    .split(",")
                    .map((p) => parseFloat(p.trim()));
                  // tx is parts[4] for 2d matrix
                  translateX = parts[4] || 0;
                }
              }
              // translateX is negative as it moves left
              const progress = Math.min(
                1,
                Math.max(0, -translateX / (contentW - visibleW))
              );
              if (indicator && thumb) {
                const maxLeft = indicator.clientWidth - thumb.clientWidth;
                thumb.style.left = Math.round(progress * maxLeft) + "px";
              }
            } catch (e) {
              /* fail silently */
            }
            const id = requestAnimationFrame(sync);
            loops.set(inner, id);
          }
          sync();
        } else {
          // not wide enough
          if (indicator) indicator.style.display = "none";
          wrap.classList.remove("auto-scrolling");
        }
      });
    };
  })();

  // SIDEBAR RESIZE (drag handle)
  function makeSidebarResizable() {
    const sidebar = document.getElementById("resizableSidebar");
    const handle = document.getElementById("sidebarDragHandle");
    if (!sidebar || !handle) return;
    let dragging = false;
    handle.addEventListener("mousedown", (e) => {
      dragging = true;
      document.body.style.cursor = "ew-resize";
      e.preventDefault();
    });
    document.addEventListener("mouseup", () => {
      dragging = false;
      document.body.style.cursor = "";
    });
    document.addEventListener("mousemove", (e) => {
      if (!dragging) return;
      const min = 56,
        max = Math.min(480, window.innerWidth - 280);
      const rect = sidebar.getBoundingClientRect();
      let newW = e.clientX - rect.left;
      newW = Math.max(min, Math.min(max, newW));
      sidebar.style.width = newW + "px";
      document.documentElement.style.setProperty("--sidebar-w", newW + "px");
      setTimeout(initAutoScrollAll, 120);
    });
    // touch support
    handle.addEventListener(
      "touchstart",
      (e) => {
        dragging = true;
      },
      { passive: true }
    );
    document.addEventListener("touchend", () => (dragging = false));
    document.addEventListener(
      "touchmove",
      (e) => {
        if (!dragging) return;
        const t = e.touches[0];
        const rect = sidebar.getBoundingClientRect();
        let newW = t.clientX - rect.left;
        const min = 56,
          max = Math.min(480, window.innerWidth - 280);
        newW = Math.max(min, Math.min(max, newW));
        sidebar.style.width = newW + "px";
        document.documentElement.style.setProperty("--sidebar-w", newW + "px");
        setTimeout(initAutoScrollAll, 120);
      },
      { passive: true }
    );
  }

  // COLLAPSE / EXPAND sidebar via circular button
  function bindSidebarToggle() {
    const sidebar = document.getElementById("resizableSidebar");
    const btn = document.getElementById("sidebarToggle");
    if (!sidebar || !btn) return;
    btn.addEventListener("click", function () {
      sidebar.classList.toggle("sidebar-collapsed");
      // update ARIA
      const collapsed = sidebar.classList.contains("sidebar-collapsed");
      btn.setAttribute("aria-pressed", collapsed ? "true" : "false");
      // recompute table scroll after a short delay
      setTimeout(initAutoScrollAll, 260);
    });
  }

  // expose helpers
  window.initCharts = initCharts;
  window.initAutoScrollAll = window.initAutoScrollAll || function () {};

  /* ===== Export UI binding (per-row + bulk) ===== */

  // Bind per-row export buttons (call after fragment load or initial render)
  function bindRowExports() {
    document.querySelectorAll(".rowExportBtn").forEach(function (btn) {
      if (btn._bound) return;
      btn._bound = true;
      btn.addEventListener("click", function () {
        var id = this.getAttribute("data-id") || this.dataset.id;
        if (!id) {
          alert("Missing operator id");
          return;
        }
        var sel = this.parentElement.querySelector(".rowExportSelect");
        var doc = sel ? sel.value : "";
        if (!doc) {
          alert("Choose document to export for this operator");
          return;
        }

        // include current filter/search/bank
        var params = new URLSearchParams();
        params.set("id", id);
        params.set("doc", doc);
        var urlp = new URL(window.location.href).searchParams;
        if (urlp.get("filter")) params.set("filter", urlp.get("filter"));
        if (urlp.get("search")) params.set("search", urlp.get("search"));
        var bankSel = getSelectedBank();
        if (bankSel) params.set("bank", bankSel);

        var url = "export_operator.php?" + params.toString();
        window.location.href = url;
      });
    });
  }

  // expose globally
  window.bindRowExports = bindRowExports;

  // Bulk export fragment button (if fragment uses different ids)
  var bulkFragBtn = document.getElementById("bulkExportBtnFrag");
  if (bulkFragBtn) {
    bulkFragBtn.addEventListener("click", function () {
      var sel = document.getElementById("bulkExportSelectFrag");
      var doc = sel ? sel.value : "";
      if (!doc) {
        alert("Choose a document to export");
        return;
      }
      var params = new URLSearchParams();
      params.set("doc", doc);
      // preserve active fragment filters (they come from current URL)
      var urlp = new URL(window.location.href).searchParams;
      if (urlp.get("filter")) params.set("filter", urlp.get("filter"));
      if (urlp.get("search")) params.set("search", urlp.get("search"));
      var bankSel = getSelectedBank();
      if (bankSel) params.set("bank", bankSel);
      window.location.href = "export_all.php?" + params.toString();
    });
  }

  (function () {
    /* Helper: create panel and preview elements once */
    function createUI() {
      if (document.getElementById("opDetailPanel")) return;
      // Panel
      const panel = document.createElement("div");
      panel.id = "opDetailPanel";
      panel.innerHTML = `
        <div class="hdr">
            <div class="title" data-top="Op's Data" data-bottom=""></div>
          <div>
            <button class="close" aria-label="Close">✕</button>
          </div>
        </div>
        <div class="tabs">
          <button data-stage="basic" class="active">Basic</button>
          <button data-stage="contact">Contact</button>
          <button data-stage="docs">Docs & Status</button>
        </div>
        <div class="content">
          <div class="stage active" data-stage="basic"></div>
          <div class="stage" data-stage="contact"></div>
          <div class="stage" data-stage="docs"></div>
        </div>
      `;
      document.body.appendChild(panel);
      // optional helper — toggles container state when a button with data-stage is clicked
      document
        .querySelectorAll(
          "#opDetailPanel .accordion-btn, #opDetailPanel .tabs button"
        )
        .forEach((btn) => {
          btn.addEventListener("click", () => {
            const panel =
              document.getElementById("opDetailPanel") ||
              document.querySelector(".operator-panel");
            if (!panel) return;
            // clear any existing show- classes
            panel.classList.remove("show-basic", "show-contact", "show-docs");
            const stage = btn.dataset.stage || btn.getAttribute("data-stage");
            if (stage === "basic") panel.classList.add("show-basic");
            if (stage === "contact") panel.classList.add("show-contact");
            if (stage === "docs") panel.classList.add("show-docs");
          });
        });
      // ---------- Pagination + filter state (single source of truth) ----------
      // promote to window scope so other handlers (updateStatus/setWork) can reference them
      window.currentFilter = window.currentFilter || "";
      window.currentPage = window.currentPage || 1;

      /**
       * loadOverview(filter, page)
       * - Always uses currentFilter/currentPage state so pagination retains the active filter.
       * - Sends ajax=1 plus filter + page (and preserves search/bank from UI if present).
       */
      function loadOverview(filter = "", page = 1) {
        // if caller supplied empty string intentionally, treat as "no filter"
        currentFilter = typeof filter === "string" ? filter : currentFilter;
        currentPage = typeof page === "number" && page > 0 ? page : currentPage;

        const params = new URLSearchParams();
        params.set("ajax", "1");
        if (currentFilter) params.set("filter", currentFilter);
        params.set("page", String(currentPage));

        // preserve top search and bank filter (if present in UI)
        const topSearchEl = document.getElementById("topSearch");
        const bankEl = document.getElementById("bankFilter");
        if (topSearchEl && topSearchEl.value.trim())
          params.set("search", topSearchEl.value.trim());
        if (bankEl && bankEl.value.trim())
          params.set("bank", bankEl.value.trim());

        // debug line (remove once confirmed working)
        console.log("[loadOverview] requesting:", params.toString());

        $.get(
          "em_verfi.php",
          params.toString(),
          function (res) {
            $("#overviewTablePlaceholder").html(res);
            // Optional: re-bind any fragment-only handlers here if needed (row export, etc)
            if (typeof bindRowExports === "function") bindRowExports();
          },
          "html"
        ).fail(function (xhr, status, err) {
          console.error(
            "Overview load failed",
            status,
            err,
            xhr && xhr.responseText
          );
        });
      }

      // Sidebar filter clicks (Working / Pending / All etc.)

      // Single pagination handler (works for AJAX fragment links and server fallback)
      document.addEventListener(
        "click",
        function (e) {
          const frag = e.target.closest && e.target.closest(".overview-page");
          if (frag) {
            e.preventDefault();
            const page =
              parseInt(
                frag.dataset.page || frag.getAttribute("data-page") || "1",
                10
              ) || 1;
            // use the currentFilter state rather than reading filter from the link
            loadOverview(currentFilter, page);
            return;
          }

          // server-rendered pagination links (if overview panel open, intercept and load via AJAX)
          const srv =
            e.target.closest && e.target.closest(".overview-page-server");
          if (srv) {
            const page =
              parseInt(
                srv.dataset.page || srv.getAttribute("data-page") || "1",
                10
              ) || 1;
            const overviewVisibleEl = document.getElementById(
              "operatorOverviewSection"
            );
            const overviewVisible =
              overviewVisibleEl &&
              !overviewVisibleEl.classList.contains("hidden");
            if (overviewVisible) {
              e.preventDefault();
              loadOverview(currentFilter, page);
            }
            // else allow normal link navigation (reload)
            return;
          }
        },
        false
      );

      // initial load (show all)
      loadOverview("", 1);

      // Preview hover tooltip
      const preview = document.createElement("div");
      preview.id = "opRowPreview";
      preview.innerHTML =
        '<div class="line"><span class="b preview-name">—</span></div><div class="line preview-sub">—</div>';
      document.body.appendChild(preview);

      // events: close and tabs
      panel
        .querySelector(".close")
        .addEventListener("click", () => hidePanel());
      panel.querySelectorAll(".tabs button").forEach((btn) => {
        btn.addEventListener("click", (e) => {
          const stage = btn.getAttribute("data-stage");
          // set active class on tabs
          panel
            .querySelectorAll(".tabs button")
            .forEach((b) => b.classList.remove("active"));
          btn.classList.add("active");
          // show the right stage content
          panel
            .querySelectorAll(".stage")
            .forEach((s) =>
              s.classList.toggle(
                "active",
                s.getAttribute("data-stage") === stage
              )
            );
          // persist choice so future renderIntoPanel() calls restore this tab
          try {
            panel.dataset.activeStage = stage;
          } catch (e) {
            /* ignore if not settable */
          }
        });
      });

      // close on ESC
      document.addEventListener("keydown", (e) => {
        if (e.key === "Escape") hidePanel();
      });
    }

    function showPanel() {
      const p = document.getElementById("opDetailPanel");
      if (!p) return;
      p.classList.add("visible");
    }
    function hidePanel() {
      const p = document.getElementById("opDetailPanel");
      if (!p) return;
      p.classList.remove("visible");
    }

    function renderIntoPanel(data) {
      createUI();
      const panel = document.getElementById("opDetailPanel");
      if (!panel) return;

      // store op id for actions
      const opId = data.id || data["id"] || null;
      panel.dataset.opId = opId || "";

      panel.querySelector(".title").textContent =
        data.operator_full_name || `Operator ${opId || ""}`;

      // BASIC stage
      const basic = panel.querySelector('.stage[data-stage="basic"]');
      basic.innerHTML = "";
      const basicRows = [
        ["Operator ID", "operator_id"],
        ["Full name", "operator_full_name"],
        ["Email", "email"],
        ["Mobile", "operator_contact_no"],

        ["Bank", "bank_name"],
        ["Branch", "branch_name"],
        ["Joining", "joining_date"],
        ["Aadhaar", "aadhar_number"],
        ["PAN", "pan_number"],
        ["Voter ID", "voter_id_no"],
      ];
      basicRows.forEach(([k, key]) => {
        const row = document.createElement("div");
        row.className = "op-row";

        // --- REPLACEMENT START ---
        row.dataset.field = key;
        const rawVal =
          data[key] !== undefined && data[key] !== null ? data[key] : "";
        const displayVal = escapeHTML(rawVal !== "" ? rawVal : "—");
        const inputVal = rawVal === null || rawVal === undefined ? "" : rawVal;
        row.innerHTML =
          '<div class="k">' +
          k +
          "</div>" +
          '<div class="v">' +
          '<span class="view">' +
          displayVal +
          "</span>" +
          '<input class="panel-input" data-field="' +
          key +
          '" id="panel-' +
          key +
          '" value="' +
          escapeHTML(String(inputVal)) +
          '" readonly style="display:none;width:100%;" />' +
          "</div>";
        // --- REPLACEMENT END ---
        basic.appendChild(row);
      });

      // CONTACT stage
      const contact = panel.querySelector('.stage[data-stage="contact"]');
      contact.innerHTML = "";
      const contactRows = [
        ["Mobile", "operator_contact_no"],
        ["Father", "father_name"],
        ["Alt Contact Relation", "alt_contact_relation"],
        ["Alt Contact Number", "alt_contact_number"],
        ["DOB", "dob"],
        ["Gender", "gender"],
        ["Current HNo / Street", "current_hno_street"],
        ["Current Town", "current_village_town"],
        ["Current Pincode", "current_pincode"],
        ["Permanent HNo / Street", "permanent_hno_street"],
        ["Permanent Town", "permanent_village_town"],
        ["Permanent Pincode", "permanent_pincode"],
      ];
      contactRows.forEach(([k, key]) => {
        const row = document.createElement("div");
        row.className = "op-row";
        // --- REPLACEMENT START ---
        row.dataset.field = key;
        const rawVal =
          data[key] !== undefined && data[key] !== null ? data[key] : "";
        const displayVal = escapeHTML(rawVal !== "" ? rawVal : "—");
        const inputVal = rawVal === null || rawVal === undefined ? "" : rawVal;
        row.innerHTML =
          '<div class="k">' +
          k +
          "</div>" +
          '<div class="v">' +
          '<span class="view">' +
          displayVal +
          "</span>" +
          '<input class="panel-input" data-field="' +
          key +
          '" id="panel-' +
          key +
          '" value="' +
          escapeHTML(String(inputVal)) +
          '" readonly style="display:none;width:100%;" />' +
          "</div>";
        // --- REPLACEMENT END ---
        contact.appendChild(row);
      });

      // DOCS & STATUS stage (includes action buttons + review textarea + attachments)
      const docs = panel.querySelector('.stage[data-stage="docs"]');
      docs.innerHTML = "";

      // action bar (wire to your existing global functions)
      const actions = document.createElement("div");
      actions.className = "op-actions";
      actions.style = "display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;";
      const safeId = opId ? parseInt(opId, 10) : null;

      function mkBtn(label, onClick, cls = "small-btn") {
        const btn = document.createElement("button");
        btn.className = cls + " panel-btn";
        btn.type = "button";
        btn.textContent = label;
        btn.style.minWidth = "140px";
        btn.style.padding = "10px 12px";

        if (!safeId) {
          btn.disabled = true;
          return btn;
        }

        if (typeof onClick === "function") {
          btn.addEventListener("click", onClick);
        } else if (typeof onClick === "string") {
          // keep backward compatibility for code that passes JS-as-string
          btn.addEventListener("click", function () {
            new Function(onClick)();
          });
        } else {
          console.warn("mkBtn: unsupported onClick type", onClick);
        }
        return btn;
      }

      // Accept / Pending / Reject
      actions.appendChild(
        mkBtn("Accept", `updateStatus(${safeId},'accepted')`)
      );
      actions.appendChild(
        mkBtn("Pending", `updateStatus(${safeId},'pending')`)
      );

      // Working toggles
      actions.appendChild(mkBtn("Working", `setWork(${safeId},'working')`));
      actions.appendChild(
        mkBtn("Not Working", `setWork(${safeId},'not working')`)
      );

      // Request Resubmission (new)
      actions.appendChild(
        mkBtn(
          "Request Resubmission",
          function () {
            openResubmitModal(safeId);
          },
          "small-btn"
        )
      );

      // Edit / Save Row
      actions.appendChild(
        mkBtn(
          "Edit Row",
          function () {
            panelMakeEditable(safeId);
          },
          "small-btn"
        )
      );
      actions.appendChild(
        mkBtn(
          "Save Row",
          function () {
            panelSaveRow(safeId);
          },
          "small-btn"
        )
      );

      // Download Resubmission (new) — add this line right after Request Resubmission
      actions.appendChild(
        mkBtn(
          "Download Resubmission",
          function () {
            downloadResubmissionByOperator(safeId);
          },
          "small-btn"
        )
      );

      // Save Review (will POST from panel textarea)
      const saveRevBtn = mkBtn("Save Review", `panelSaveReview(${safeId})`);
      saveRevBtn.style.fontWeight = "800";
      actions.appendChild(saveRevBtn);

      docs.appendChild(actions);

      // review textarea (panel-local)
      const revWrap = document.createElement("div");
      revWrap.style = "margin-bottom:12px;";
      revWrap.innerHTML = `
      <div style="font-weight:700;color:var(--muted);margin-bottom:6px">Review notes</div>
      <textarea id="panel-review" style="width:100%;height:96px;border:1px solid rgba(15,23,42,0.06);border-radius:8px;padding:8px;font-size:13px">${escapeHTML(
        data.review_notes || ""
      )}</textarea>
    `;
      docs.appendChild(revWrap);

      // status/work rows
      const rows3 = [
        ["Status", "status"],
        ["Work status", "work_status"],
      ];
      rows3.forEach(([k, key]) => {
        const row = document.createElement("div");
        row.className = "op-row";
        // --- REPLACEMENT START ---
        row.dataset.field = key;
        const rawVal =
          data[key] !== undefined && data[key] !== null ? data[key] : "";
        const displayVal = escapeHTML(rawVal !== "" ? rawVal : "—");
        const inputVal = rawVal === null || rawVal === undefined ? "" : rawVal;
        row.innerHTML =
          '<div class="k">' +
          k +
          "</div>" +
          '<div class="v">' +
          '<span class="view">' +
          displayVal +
          "</span>" +
          '<input class="panel-input" data-field="' +
          key +
          '" id="panel-' +
          key +
          '" value="' +
          escapeHTML(String(inputVal)) +
          '" readonly style="display:none;width:100%;" />' +
          "</div>";
        // --- REPLACEMENT END ---
        docs.appendChild(row);
      });

      // --- Enhanced attachments UI (per-doc accept/reject/replace + Mailer) ---
      const attWrap = document.createElement("div");
      attWrap.className = "op-attachments";
      let anyAttach = false;

      // list of doc keys to manage (complete list from DB)
      const docKeys = [
        "aadhar_file",
        "pan_file",
        "voter_file",
        "ration_file",
        "consent_file",
        "gps_selfie_file",
        "police_verification_file",
        "permanent_address_proof_file",
        "parent_aadhar_file",
        "nseit_cert_file",
        "self_declaration_file",
        "non_disclosure_file",
        "edu_10th_file",
        "edu_12th_file",
        "edu_college_file",
      ];
      // label map for human friendly names
      const labelMap = {
        aadhar_file: "Aadhaar Card",
        pan_file: "PAN Card",
        voter_file: "Voter ID",
        ration_file: "Ration Card",
        consent_file: "Consent",
        gps_selfie_file: "GPS Selfie",
        police_verification_file: "Police Verification",
        permanent_address_proof_file: "Permanent Address Proof",
        parent_aadhar_file: "Parent Aadhaar",
        nseit_cert_file: "NSEIT Certificate",
        self_declaration_file: "Self Declaration",
        non_disclosure_file: "Non-Disclosure Agreement",
        edu_10th_file: "10th Certificate",
        edu_12th_file: "12th Certificate",
        edu_college_file: "College Certificate",
      };
      // expose doc keys globally so modal builder can use them
      window.DOC_KEYS = docKeys;
      window.LABEL_MAP = labelMap;

      function mkDocRow(k, url) {
        const lbl = labelMap[k] || k;
        const row = document.createElement("div");
        row.className = "doc-row";
        const viewHtml = url
          ? `<a href="${escapeHTML(url)}" target="_blank">${prettifyFilename(
              url
            )}</a>`
          : "—";
        row.innerHTML = `
        <div class="k">${lbl}</div>
        <div class="v">
          <div><span class="doc-link">${viewHtml}</span></div>
          <div class="doc-actions">
            <button class="small-btn btn-accept"  data-doc="${k}">Accept</button>
            <button class="small-btn btn-reject"  data-doc="${k}">Reject</button>
            
            <label class="small-btn btn-upload"  style="cursor:pointer;">Replace<input type="file" accept=".pdf,image/*" style="display:none" data-doc="${k}"></label>
            <button class="small-btn btn-download" data-doc="${k}" ${
          !url ? "disabled" : ""
        }>Download</button>
          </div> 
          <div class="doc-reject-reason" style="display:none;margin-top:6px;">
            <input class="input-compact reason-input" placeholder="Reason for rejection" data-doc="${k}" style="  margin-right:18px ">
            <button class="small-btn btn-save-reason" data-doc="${k}">Save</button>
            <button class="small-btn btn-cancel-reason" data-doc="${k}">Cancel</button>
          </div>
        </div>
      `;
        return row;
      }

      docKeys.forEach((k) => {
        const val = data[k] || "";
        if (val) anyAttach = true;
        const row = mkDocRow(k, val);
        attWrap.appendChild(row);
      });

      if (!anyAttach) {
        const row = document.createElement("div");
        row.className = "op-row";
        row.innerHTML = `<div class="k">Attachments</div><div class="v">—</div>`;
        docs.appendChild(row);
      } else {
        docs.appendChild(document.createElement("hr"));
        const row = document.createElement("div");
        row.className = "op-row";
        row.innerHTML = `<div class="k">Attachments</div><div class="v"></div>`;
        row.querySelector(".v").appendChild(attWrap);
        docs.appendChild(row);
      }

      // Mailer button
      const mailWrap = document.createElement("div");
      mailWrap.style = "margin-top:12px";
      mailWrap.innerHTML = `<button class="small-btn" id="panel-mailer">Send Rejection Mail</button>`;
      docs.appendChild(mailWrap);

      // wire events for accept/reject/replace
      attWrap.querySelectorAll(".btn-accept").forEach((btn) => {
        btn.addEventListener("click", function () {
          const doc = this.dataset.doc;
          if (!confirm("Mark this document as ACCEPTED?")) return;
          docAction(opId, doc, "accept", "");
        });
      });

      attWrap.querySelectorAll(".btn-reject").forEach((btn) => {
        btn.addEventListener("click", function () {
          const p = this.closest(".v");
          const reasonBox = p.querySelector(".doc-reject-reason");
          if (reasonBox) reasonBox.style.display = "block";
        });
      });

      attWrap.querySelectorAll(".btn-cancel-reason").forEach((btn) => {
        btn.addEventListener("click", function () {
          this.closest(".doc-reject-reason").style.display = "none";
        });
      });

      attWrap.querySelectorAll(".btn-save-reason").forEach((btn) => {
        btn.addEventListener("click", function () {
          const doc = this.dataset.doc;
          const p = this.closest(".doc-reject-reason");
          const input = p.querySelector(".reason-input");
          const reason = (input && input.value.trim()) || "";
          if (!reason) {
            alert("Please enter reason");
            return;
          }
          docAction(opId, doc, "reject", reason);
        });
      });

      attWrap.querySelectorAll("input[type=file]").forEach((fi) => {
        fi.addEventListener("change", function () {
          const doc = this.dataset.doc;
          if (!this.files || !this.files[0]) return;
          if (!confirm("Upload replacement file?")) {
            this.value = "";
            return;
          }
          uploadDoc(opId, doc, this);
        });
      });

      // *** Download button wiring: opens download.php with id & doc_key
      attWrap.querySelectorAll(".btn-download").forEach((btn) => {
        btn.addEventListener("click", function () {
          const doc = this.dataset.doc;
          if (!opId) {
            alert("Missing operator id");
            return;
          }
          // open download endpoint in new tab so dashboard stays intact
          const dlUrl =
            "download.php?id=" +
            encodeURIComponent(opId) +
            "&doc_key=" +
            encodeURIComponent(doc);
          window.open(dlUrl, "_blank");
        });
      });

      mailWrap
        .querySelector("#panel-mailer")
        .addEventListener("click", function () {
          if (
            !confirm(
              "Send rejection mail to operator listing rejected documents?"
            )
          )
            return;
          // if a resubmission token exists for this operator (session/server), you may pass it here.
          sendRejectionMail(opId);
        });

      // show panel and restore previously selected tab (fall back to 'basic')
      showPanel();
      let restore = panel.dataset.activeStage || "basic";
      const restoreBtn = panel.querySelector(
        '.tabs button[data-stage="' + restore + '"]'
      );
      if (restoreBtn) {
        restoreBtn.click();
      } else {
        // fallback to basic if stored value missing
        const b = panel.querySelector('.tabs button[data-stage="basic"]');
        if (b) b.click();
      }
    }

    // Unified save functions — drop-in replacement (paste once)
    function toast(msg) {
      alert(msg);
    } // keep your existing toast if you have one

    function saveReview(id) {
      const el = document.getElementById("review-" + id);
      if (!el) {
        console.warn("no review input for", id);
        return;
      }
      const notes = el.value;
      $.ajax({
        url: "update_review.php",
        method: "POST",
        dataType: "json",
        data: { id: id, review_notes: notes },
        success: function (res) {
          console.log("update_review response", res);
          if (res && res.success) {
            // reflect changed value in UI (if needed)
            if (document.getElementById("review-" + id))
              document.getElementById("review-" + id).value = notes;
            toast(res.message || "Saved");
          } else {
            toast(
              "Save failed: " + (res && res.message ? res.message : "unknown")
            );
            console.warn("saveReview failed", res);
          }
        },
        error: function (xhr, status, err) {
          console.error("Request failed", status, err, xhr && xhr.responseText);
          toast("Request failed — check console");
        },
      });
    }

    function panelSaveReview(id) {
      if (!id) {
        toast("Missing id");
        return;
      }
      const ta = document.getElementById("panel-review");
      const notes = ta ? ta.value.trim() : "";
      toast("Saving review…");
      $.ajax({
        url: "update_review.php",
        method: "POST",
        dataType: "json",
        data: { id: id, review_notes: notes },
        success: function (res) {
          console.log("panelSaveReview response", res);
          if (res && res.success) {
            // update table input if present
            const tableInput = document.getElementById("review-" + id);
            if (tableInput) tableInput.value = notes;
            toast(res.message || "Review saved");
          } else {
            toast(
              "Save failed: " + (res && res.message ? res.message : "unknown")
            );
            console.warn("panelSaveReview failed", res);
          }
        },
        error: function (xhr, status, err) {
          console.error("panel save failed", xhr, status, err);
          toast("Request failed — check console");
        },
      });
    }

    window.saveReview = saveReview;
    window.panelSaveReview = panelSaveReview;

    /* Safe text -> HTML helper */
    function escapeHTML(s) {
      if (s === null || s === undefined) return "";
      return String(s).replace(
        /[&<>"]/g,
        (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c])
      );
    }

    function prettifyFilename(u) {
      try {
        return u.split("/").pop().split("?")[0];
      } catch (e) {
        return "file";
      }
    }

    function gatherRowData(tr) {
      const data = {};
      tr.querySelectorAll("td[data-col]").forEach((td) => {
        const key = td.getAttribute("data-col");
        let val = td.textContent || "";
        const a = td.querySelector("a");
        if (a && a.href) val = a.href;
        data[key] = val.trim();
      });
      // capture id and live review/work cells if present
      const id = tr.id ? tr.id.replace("row-", "") : null;
      if (id) {
        data.id = id;
        const rv = document.getElementById("review-" + id);
        if (rv) data["review_notes"] = rv.value;
        const wsEl = document.getElementById("work-" + id);
        if (wsEl) data["work_status"] = wsEl.textContent.trim();
      }
      return data;
    }

    /* ---- New helper JS functions for doc actions, uploads, mailer ---- */

    function docAction(opId, docKey, action, reason) {
      $.post(
        "update_doc_review.php",
        { id: opId, doc_key: docKey, action: action, reason: reason },
        function (res) {
          if (res && res.success) {
            alert(res.message || "Updated");
            // refresh panel if open
            const trElem = document.getElementById("row-" + opId);
            if (trElem) renderIntoPanel(gatherRowData(trElem));
          } else {
            alert(res && res.message ? res.message : "Error");
          }
        },
        "json"
      ).fail(function () {
        alert("Request failed");
      });
    }

    function uploadDoc(opId, docKey, fileInputEl) {
      const f = fileInputEl.files[0];
      const fd = new FormData();
      fd.append("id", opId);
      fd.append("doc_key", docKey);
      fd.append("file", f);
      $.ajax({
        url: "upload_docs.php",
        method: "POST",
        data: fd,
        processData: false,
        contentType: false,
        dataType: "json",
        success: function (res) {
          if (res && res.success) {
            alert("Uploaded");
            const trElem = document.getElementById("row-" + opId);
            if (trElem) renderIntoPanel(gatherRowData(trElem));
            else location.reload();
          } else alert(res && res.message ? res.message : "Upload failed");
        },
        error: function () {
          alert("Upload request failed");
        },
      });
    }

    /* sendRejectionMail now optionally accepts a token — if token is provided, it's included in the request
    so send_rejection_mail.php can include a resubmission link in the email. */
    function sendRejectionMail(opId, token) {
      const payload = { id: opId };
      if (token) payload.token = token;
      $.post(
        "send_rejection_mail.php",
        payload,
        function (res) {
          if (res && res.success) alert(res.message || "Mail sent");
          else alert(res && res.message ? res.message : "Mail failed");
        },
        "json"
      ).fail(function () {
        alert("Mail request failed");
      });
    }

    /* ---- RESUBMISSION MODAL + flow ---- */

    // Opens the modal to pick docs for resubmission for a given operator id

    /* ---- RESUBMISSION MODAL + flow (NEW) ---- */
    const RESUBMIT_DOCS = [
      { key: "aadhaar_file", label: "Aadhaar Card" },
      { key: "pan_file", label: "PAN Card" },
      { key: "voter_file", label: "Voter ID" },
      { key: "ration_file", label: "Ration Card" },
      { key: "consent_file", label: "Consent" },
      { key: "gps_selfie_file", label: "GPS Selfie" },
      { key: "police_verification_file", label: "Police Verification" },
      { key: "permanent_address_proof_file", label: "Permanent Address Proof" },
      { key: "parent_aadhar_file", label: "Parent Aadhaar" },
      { key: "nseit_cert_file", label: "NSEIT Certificate" },
      { key: "self_declaration_file", label: "Self Declaration" },
      { key: "non_disclosure_file", label: "Non-Disclosure Agreement" },
      { key: "edu_10th_file", label: "10th Certificate" },
      { key: "edu_12th_file", label: "12th Certificate" },
      { key: "edu_college_file", label: "College Certificate" },
    ];

    function ensureResubmitOverlay() {
      let overlay = document.getElementById("resubmitOverlay");
      if (overlay) return overlay;

      overlay = document.createElement("div");
      overlay.id = "resubmitOverlay";
      overlay.className = "resubmit-overlay";
      overlay.style.display = "none";
      overlay.innerHTML = `
    <div class="resubmit-modal" role="dialog" aria-modal="true">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <h3 style="margin:0;font-weight:600">Request resubmission</h3>
        <div><button id="resubmitClose" class="small-btn">Close</button></div>
      </div>
      <div style="font-size:13px;color:#445">Select documents you want the operator to re-upload. Optionally set token validity (days).</div>
      <div class="resubmit-list" id="resubmitList"></div>
      <div style="margin-top:8px">
        <label style="font-size:13px">Expires in (days):
          <input id="resubmitDays" type="number" value="7" min="1" style="width:80px;margin-left:8px;padding:6px;border-radius:6px;border:1px solid #ddd">
        </label>
      </div>
      <div class="resubmit-footer">
        <div style="display:flex;gap:8px">
          <button id="resubmitCreateBtn" class="small-btn">Create & Send</button>
          <button id="resubmitCreateOnlyBtn" class="small-btn">Create (no email)</button>
        </div>
        <div id="resubmitResult"></div>
      </div>
    </div>
  `;
      document.body.appendChild(overlay);
      document
        .getElementById("resubmitClose")
        .addEventListener("click", () => closeResubmitModal());
      return overlay;
    }

    function openResubmitModal(opId) {
      if (!opId) return alert("Missing operator id");
      const overlay = ensureResubmitOverlay();

      // populate list
      const list = overlay.querySelector("#resubmitList");
      list.innerHTML = "";
      RESUBMIT_DOCS.forEach((d) => {
        const id = "rs_" + d.key;
        const wrapper = document.createElement("label");
        wrapper.style =
          "display:flex;gap:10px;align-items:center;padding:6px;border-radius:6px;background:#fbfbfb";
        wrapper.innerHTML = `<input type="checkbox" id="${id}" name="docs[]" value="${d.key}"> <span style="font-weight:600">${d.label}</span> <small style="color:#666;margin-left:6px">(${d.key})</small>`;
        list.appendChild(wrapper);
      });

      // wire buttons
      const createBtn = overlay.querySelector("#resubmitCreateBtn");
      const createOnlyBtn = overlay.querySelector("#resubmitCreateOnlyBtn");
      const resultEl = overlay.querySelector("#resubmitResult");

      createBtn.onclick = () => submitResubmissionRequest(opId, true);
      createOnlyBtn.onclick = () => submitResubmissionRequest(opId, false);

      overlay.style.display = "flex";
      document.body.classList.add("resubmit-open");
      resultEl.textContent = "";
    }

    function closeResubmitModal() {
      const overlay = document.getElementById("resubmitOverlay");
      if (!overlay) return;
      overlay.style.display = "none";
      document.body.classList.remove("resubmit-open");
      const resultEl = overlay.querySelector("#resubmitResult");
      if (resultEl) resultEl.textContent = "";
    }

    function submitResubmissionRequest(opId, emailNow) {
      const overlay = document.getElementById("resubmitOverlay");
      if (!overlay) return;
      const checkboxes = overlay.querySelectorAll(
        'input[name="docs[]"]:checked'
      );
      const docs = Array.from(checkboxes).map((cb) => cb.value);
      if (!docs.length)
        return alert("Select at least one document for resubmission.");
      const days =
        parseInt(
          (overlay.querySelector("#resubmitDays") || { value: 7 }).value,
          10
        ) || 7;
      overlay.querySelector("#resubmitResult").textContent = "Creating token…";

      const payload = {
        id: opId,
        docs: docs,
        expires_days: days,
        email_now: emailNow ? 1 : 0,
      };
      $.ajax({
        url: "create_resubmission.php",
        method: "POST",
        dataType: "json",
        data: payload,
        success: function (res) {
          const resultEl = overlay.querySelector("#resubmitResult");
          if (res && res.success) {
            const url =
              res.url ||
              "duplicateoperator.php?token=" +
                encodeURIComponent(res.token || "");
            resultEl.innerHTML = `Resubmission created. Link: <a href="${escapeHtml(
              url
            )}" target="_blank">${escapeHtml(
              url
            )}</a> &nbsp; <button id="copyResubmitLink" class="small-btn">Copy</button>`;
            overlay
              .querySelector("#copyResubmitLink")
              .addEventListener("click", function () {
                try {
                  navigator.clipboard.writeText(url);
                  alert("Copied");
                } catch (e) {
                  prompt("Copy link", url);
                }
              });
            if (emailNow && !res.emailed) {
              sendRejectionMail(opId, res.token);
            }
          } else {
            resultEl.textContent =
              res && res.message
                ? res.message
                : "Failed to create resubmission";
          }
        },
        error: function (xhr, status, err) {
          overlay.querySelector("#resubmitResult").textContent =
            "Request failed";
          console.error(
            "create_resubmission failed",
            status,
            err,
            xhr && xhr.responseText
          );
        },
      });
    }

    /* helper: minimal escape for html injection in results (not DB) */
    function escapeHtml(s) {
      return String(s || "").replace(/[&<>"']/g, function (m) {
        return {
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#39;",
        }[m];
      });
    }

    /* Hover preview behaviour (small tooltip near pointer) */
    let previewTimer = null;
    const preview = document.getElementById ? null : null; // ensure linter ok
    function showPreviewAt(x, y, name, extra) {
      createUI();
      const p = document.getElementById("opRowPreview");
      if (!p) return;
      p.style.left = x + 14 + "px";
      p.style.top = y + 14 + "px";
      p.querySelector(".preview-name").textContent = name || "—";
      p.querySelector(".preview-sub").textContent = extra || "";
      p.style.display = "block";
    }
    function hidePreview() {
      const p = document.getElementById("opRowPreview");
      if (p) p.style.display = "none";
    }

    /* Event delegation: clicks on any row with id row-<id> open details */
    document.addEventListener(
      "click",
      function (e) {
        // ignore clicks inside the panel
        if (e.target.closest && e.target.closest("#opDetailPanel")) return;
        const tr = e.target.closest && e.target.closest('tr[id^="row-"]');
        if (tr) {
          const data = gatherRowData(tr);
          renderIntoPanel(data);
        }
      },
      false
    );

    /* Hover preview using mouseover/mouseout (mouseenter doesn't bubble) */
    document.addEventListener(
      "mouseover",
      function (e) {
        const tr = e.target.closest && e.target.closest('tr[id^="row-"]');
        if (!tr) return;
        // prevent if target is within panel or input etc.
        previewTimer = setTimeout(() => {
          const name = (
            tr.querySelector('td[data-col="operator_full_name"]') || {
              textContent: "",
            }
          ).textContent.trim();
          const idcell = (
            tr.querySelector('td[data-col="operator_id"]') || {
              textContent: "",
            }
          ).textContent.trim();
          const phone = (
            tr.querySelector('td[data-col="operator_contact_no"]') || {
              textContent: "",
            }
          ).textContent.trim();
          const htmlExtra = `${idcell} · ${phone}`;
          // position near mouse if available else near row bounding box
          const rc = tr.getBoundingClientRect();
          const x = window._lastMouseX || rc.right;
          const y = window._lastMouseY || rc.top;
          createUI();
          const p = document.getElementById("opRowPreview");
          if (p) {
            p.querySelector(".preview-name").textContent = name || "—";
            p.querySelector(".preview-sub").textContent = htmlExtra;
            p.style.left = x + 14 + "px";
            p.style.top = y + 14 + "px";
            p.style.display = "block";
          }
        }, 220); // small delay so quick mouse move doesn't pop everything
      },
      false
    );

    document.addEventListener(
      "mouseout",
      function (e) {
        const tr = e.target.closest && e.target.closest('tr[id^="row-"]');
        if (previewTimer) {
          clearTimeout(previewTimer);
          previewTimer = null;
        }
        hidePreview();
      },
      false
    );
    // track mouse position for preview placement
    document.addEventListener("mousemove", function (e) {
      window._lastMouseX = e.clientX;
      window._lastMouseY = e.clientY;
    });

    // ensure UI exists if user clicks programmatically
    createUI();

    // Make sure panel hides if user clicks outside (but not when clicking table rows)
    document.addEventListener(
      "click",
      function (e) {
        const panel = document.getElementById("opDetailPanel");
        if (!panel) return;
        if (panel.classList.contains("visible")) {
          const clickedInside =
            e.target.closest &&
            (e.target.closest("#opDetailPanel") ||
              e.target.closest('tr[id^="row-"]'));
          if (!clickedInside) hidePanel();
        }
      },
      true
    );
  })();

  (function () {
    const sel = document.getElementById("bankFilter");
    if (!sel) return;
    sel.addEventListener("focus", () => sel.classList.add("open"));
    sel.addEventListener("blur", () => sel.classList.remove("open"));
    // Some browsers fire 'change' when user opens then cancels — keep class removal defensive
    sel.addEventListener("change", () => sel.classList.remove("open"));
  })();

  var bulkBtn = document.getElementById("bulkExportBtn");
  if (bulkBtn) {
    bulkBtn.addEventListener("click", function () {
      var sel = document.getElementById("bulkExportSelect");
      var doc = sel ? sel.value : "";
      if (!doc) {
        alert("Choose a document to export");
        return;
      }
      var params = new URLSearchParams();
      params.set("doc", doc);
      var urlp = new URL(window.location.href).searchParams;
      if (urlp.get("filter")) params.set("filter", urlp.get("filter"));
      if (urlp.get("search")) params.set("search", urlp.get("search"));
      var bankSel = getSelectedBank();
      if (bankSel) params.set("bank", bankSel);
      window.location.href = "export_all.php?" + params.toString();
    });
  }

  /* ===== Custom dropdown builder
    Converts <select class="qit-select"> and <select.form-select> into animated dropdowns.
    Keeps original select in DOM (hidden) and syncs value.
  */

  (function () {
    "use strict";

    // query selects to convert (bank + bulk export + per-row selects)
    const selectorList = ["select.qit-select", "select.form-select"];

    function createDropdownFromSelect(sel) {
      if (!sel || sel.dataset.cdDone === "1") return;
      sel.dataset.cdDone = "1";

      // wrapper
      const wrap = document.createElement("div");
      wrap.className = "custom-dropdown";
      // create visible toggle
      const toggle = document.createElement("button");
      toggle.type = "button";
      toggle.className = "cd-toggle";
      toggle.setAttribute("aria-haspopup", "listbox");
      toggle.setAttribute("aria-expanded", "false");

      const labelSpan = document.createElement("span");
      labelSpan.className = "cd-label";
      labelSpan.textContent =
        (sel.options[sel.selectedIndex] &&
          sel.options[sel.selectedIndex].text) ||
        "Select…";

      const caret = document.createElement("span");
      caret.className = "cd-caret";

      toggle.appendChild(labelSpan);
      toggle.appendChild(caret);

      // menu
      const menu = document.createElement("div");
      menu.className = "cd-menu";
      menu.setAttribute("role", "listbox");
      menu.setAttribute("tabindex", "-1");

      // Build items from options
      Array.from(sel.options).forEach((opt, idx) => {
        const item = document.createElement("div");
        item.className = "cd-item";
        item.setAttribute("role", "option");
        item.dataset.value = opt.value;
        item.tabIndex = 0;
        item.innerHTML = '<span class="text">' + opt.text + "</span>";
        // if you want metadata (e.g. value) show to right
        // const meta = document.createElement('span'); meta.className = 'meta'; meta.textContent = opt.value; item.appendChild(meta);
        if (opt.disabled) item.setAttribute("aria-disabled", "true");
        if (opt.selected) {
          item.setAttribute("aria-selected", "true");
          item.classList.add("selected");
        } else {
          item.setAttribute("aria-selected", "false");
        }
        menu.appendChild(item);
      });

      // Insert wrapper before the select, then move select inside wrapper
      sel.parentNode.insertBefore(wrap, sel);
      wrap.appendChild(toggle);
      wrap.appendChild(menu);
      wrap.appendChild(sel); // original select kept, but it's visually hidden by CSS earlier

      // hide original visually but keep in DOM (already handled by CSS override if you pasted earlier)
      sel.style.position = "absolute";
      sel.style.opacity = "0";
      sel.style.pointerEvents = "none";

      // state helpers
      function open() {
        wrap.classList.add("open");
        toggle.setAttribute("aria-expanded", "true");
        menu.focus();
        // animate children with slight stagger
        const items = menu.querySelectorAll(".cd-item");
        items.forEach((it, i) => {
          it.style.transitionDelay = i * 14 + "ms";
        });
        document.addEventListener("click", docClick);
      }
      function close() {
        wrap.classList.remove("open");
        toggle.setAttribute("aria-expanded", "false");
        document.removeEventListener("click", docClick);
        // clear focused class
        menu
          .querySelectorAll(".cd-item")
          .forEach((i) => i.classList.remove("focused"));
      }

      function docClick(e) {
        if (!wrap.contains(e.target)) close();
      }

      // toggle click
      toggle.addEventListener("click", function (e) {
        e.stopPropagation();
        if (wrap.classList.contains("open")) close();
        else open();
      });

      // item click
      menu.addEventListener("click", function (e) {
        const it = e.target.closest(".cd-item");
        if (!it) return;
        if (it.getAttribute("aria-disabled") === "true") return;
        selectItem(it);
        close();
      });

      // keyboard support
      let focusIndex = -1;
      menu.addEventListener("keydown", function (e) {
        const items = Array.from(menu.querySelectorAll(".cd-item"));
        if (e.key === "ArrowDown") {
          e.preventDefault();
          focusIndex = Math.min(items.length - 1, Math.max(0, focusIndex + 1));
          updateFocus(items);
        } else if (e.key === "ArrowUp") {
          e.preventDefault();
          focusIndex = Math.max(0, focusIndex - 1);
          updateFocus(items);
        } else if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          if (focusIndex >= 0 && items[focusIndex]) {
            selectItem(items[focusIndex]);
            close();
          }
        } else if (e.key === "Escape") {
          close();
          toggle.focus();
        }
      });

      function updateFocus(items) {
        items.forEach((it, idx) => {
          it.classList.toggle("focused", idx === focusIndex);
          if (idx === focusIndex) it.scrollIntoView({ block: "nearest" });
        });
      }

      function selectItem(it) {
        const v = it.dataset.value;
        // set visual label
        labelSpan.textContent = it.querySelector(".text").textContent;
        // update aria-selected states
        menu
          .querySelectorAll(".cd-item")
          .forEach((x) => x.setAttribute("aria-selected", "false"));
        it.setAttribute("aria-selected", "true");
        // update original select value and trigger change
        sel.value = v;
        const ev = new Event("change", { bubbles: true });
        sel.dispatchEvent(ev);
      }

      // sync if original select changed programmatically elsewhere
      sel.addEventListener("change", function () {
        const cur = sel.value;
        const itm = menu.querySelector(
          '.cd-item[data-value="' + CSS.escape(cur) + '"]'
        );
        if (itm) {
          labelSpan.textContent = itm.querySelector(".text").textContent;
          menu
            .querySelectorAll(".cd-item")
            .forEach((x) => x.setAttribute("aria-selected", "false"));
          itm.setAttribute("aria-selected", "true");
        }
      });

      // pre-select index on open for keyboard nav
      menu.addEventListener("mouseover", function (e) {
        const it = e.target.closest(".cd-item");
        if (!it) return;
        const items = Array.from(menu.querySelectorAll(".cd-item"));
        focusIndex = items.indexOf(it);
        updateFocus(items);
      });

      // clicking the original disabled select (in some browsers) should open our UI
      sel.addEventListener("focus", function () {
        open();
      });
    } // createDropdownFromSelect

    // initialize all matching selects
    function initAll() {
      const nodes = document.querySelectorAll(selectorList.join(","));
      nodes.forEach((s) => createDropdownFromSelect(s));
    }

    // run on DOM ready
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", initAll);
    } else initAll();

    // expose for debugging
    window._cd_init = initAll;
  })();

  document.addEventListener(
    "click",
    function (e) {
      // AJAX fragment page click
      const frag = e.target.closest && e.target.closest(".overview-page");
      if (frag) {
        e.preventDefault();
        const page =
          parseInt(
            frag.dataset.page || frag.getAttribute("data-page") || "1",
            10
          ) || 1;
        const filter = frag.dataset.filter || "";
        loadOverview(filter, page);
        return;
      }

      // Server-rendered pagination (links that reload page)
      const srv = e.target.closest && e.target.closest(".overview-page-server");
      if (srv) {
        // allow normal link navigation (works because href has page + preserved params)
        // but intercept to loadOverview via AJAX if overview panel already open
        const page = parseInt(srv.dataset.page || "1", 10) || 1;
        const overviewVisible = !document
          .getElementById("operatorOverviewSection")
          .classList.contains("hidden");
        if (overviewVisible) {
          e.preventDefault();
          // preserve currently active filter from URL
          const urlp = new URLSearchParams(window.location.search);
          const filter = urlp.get("filter") || "";
          loadOverview(filter, page);
        }
        return;
      }
    },
    false
  );

  (function () {
    // keep references to previous implementations (if any)
    const oldMakeRowEditable = window.makeRowEditable;
    const oldSaveRow = window.saveRow;

    function panelMakeEditable(opId) {
      const panel = document.getElementById("opDetailPanel");
      if (!panel || String(panel.dataset.opId) !== String(opId)) {
        // Panel not open for this operator - attempt to open it if there's a function
        try {
          if (typeof renderOperatorIntoPanel === "function")
            renderOperatorIntoPanel(opId);
        } catch (e) {}
        return;
      }
      panel.querySelectorAll(".op-row").forEach((row) => {
        const key = row.dataset.field || "";
        const v = row.querySelector(".v");
        if (!v) return;
        let inp = v.querySelector(
          "input.panel-input, textarea.panel-input, select.panel-input"
        );
        const span = v.querySelector(".view");
        const curText = span ? span.textContent.trim() : v.textContent.trim();
        if (!inp) {
          // create input by default (text)
          inp = document.createElement("input");
          inp.type = "text";
          inp.className = "panel-input";
          inp.dataset.field = key;
          inp.value = curText === "—" ? "" : curText;
          inp.style.display = "block";
          inp.style.width = "100%";
          v.appendChild(inp);
        } else {
          inp.style.display = "block";
        }
        if (span) span.style.display = "none";
        inp.readOnly = false;
      });
      panel.dataset.editing = "1";
      try {
        toast("Panel editable — change values then Save Row");
      } catch (e) {
        console.log("Panel editable");
      }
    }

    function panelSaveRow(opId) {
      const panel = document.getElementById("opDetailPanel");
      if (!panel || String(panel.dataset.opId) !== String(opId)) {
        if (typeof oldSaveRow === "function") return oldSaveRow(opId);
        return;
      }
      const payload = { id: opId };
      // collect inputs
      panel
        .querySelectorAll(
          "input.panel-input, textarea.panel-input, select.panel-input"
        )
        .forEach((inp) => {
          const f =
            inp.dataset.field || inp.name || inp.id.replace(/^panel-/, "");
          if (!f) return;
          payload[f] = inp.value;
        });

      // send to server (uses jQuery existing in the page)
      $.post(
        "update_row.php",
        payload,
        function (res) {
          if (res && res.success) {
            const updated = res.updated_fields || payload;
            // update table cells
            Object.keys(updated).forEach((k) => {
              if (k === "id" || k === "operator_id") return;
              const td = document.getElementById("cell-" + k + "-" + opId);
              if (td) td.textContent = updated[k];
              else {
                const td2 = document.querySelector(
                  'td[data-field="' + k + '"][data-id="' + opId + '"]'
                );
                if (td2) td2.textContent = updated[k];
              }
            });

            // reflect values back to panel view and hide inputs
            panel.querySelectorAll(".op-row").forEach((row) => {
              const v = row.querySelector(".v");
              if (!v) return;
              const inp = v.querySelector(
                "input.panel-input, textarea.panel-input, select.panel-input"
              );
              const span = v.querySelector(".view");
              if (inp) {
                const newVal =
                  updated && updated[row.dataset.field]
                    ? updated[row.dataset.field]
                    : inp.value;
                if (span) span.textContent = newVal || "—";
                inp.style.display = "none";
                inp.readOnly = true;
              }
              if (span) span.style.display = "block";
            });

            panel.dataset.editing = "";
            try {
              toast(res.message || "Saved");
            } catch (e) {
              alert(res.message || "Saved");
            }
          } else {
            try {
              toast(
                "Save failed: " + (res && res.message ? res.message : "unknown")
              );
            } catch (e) {
              alert("Save failed");
            }
          }
        },
        "json"
      ).fail(function () {
        try {
          toast("Save request failed");
        } catch (e) {
          alert("Save request failed");
        }
      });
    }

    // expose helpers
    window.panelMakeEditable = panelMakeEditable;
    window.panelSaveRow = panelSaveRow;

    // override existing global functions to prefer panel editing when panel is open
    window.makeRowEditable = function (id) {
      const panel = document.getElementById("opDetailPanel");
      if (panel && String(panel.dataset.opId) === String(id)) {
        return panelMakeEditable(id);
      }
      if (typeof oldMakeRowEditable === "function")
        return oldMakeRowEditable(id);
    };
    window.saveRow = function (id) {
      const panel = document.getElementById("opDetailPanel");
      if (panel && String(panel.dataset.opId) === String(id)) {
        return panelSaveRow(id);
      }
      if (typeof oldSaveRow === "function") return oldSaveRow(id);
    };
  })();
  // Create the backdrop once
  let backdrop = document.getElementById("opBackdrop");
  if (!backdrop) {
    backdrop = document.createElement("div");
    backdrop.id = "opBackdrop";
    document.body.appendChild(backdrop);
  }

  // Watch for panel toggle
  const panel = document.getElementById("opDetailPanel");

  function syncBackdrop() {
    if (panel.classList.contains("visible")) {
      backdrop.classList.add("show");
    } else {
      backdrop.classList.remove("show");
    }
  }

  // Initial check
  syncBackdrop();

  // MutationObserver to auto-sync
  new MutationObserver(() => syncBackdrop()).observe(panel, {
    attributes: true,
  });
  // --- EXPORT REAL IMPLEMENTATIONS ---
  try {
    if (typeof initTopScrollbars === "function") {
      window.initTopScrollbars = initTopScrollbars;
    }
    if (typeof updateStatus === "function") {
      window.updateStatus = updateStatus;
      if (window._queuedUpdateStatusCalls) {
        window._queuedUpdateStatusCalls.forEach((c) => {
          try {
            window.updateStatus(c.id, c.status);
          } catch (e) {
            console.warn("queued updateStatus failed", e);
          }
        });
        window._queuedUpdateStatusCalls = [];
      }
    }
    if (typeof acceptRow === "function") window.acceptRow = acceptRow;
    if (typeof pendingRow === "function") window.pendingRow = pendingRow;
    if (typeof setWorking === "function") window.setWorking = setWorking;
    if (typeof saveRow === "function") window.saveRow = saveRow;
    if (typeof saveReview === "function") window.saveReview = saveReview;
    if (typeof requestResubmission === "function")
      window.requestResubmission = requestResubmission;
    if (typeof editRow === "function") window.editRow = editRow;
  } catch (e) {
    console.warn("Export failed", e);
  }
  // --- EXPORT & ALIAS: make functions global and support alternate names ---
  try {
    // if real functions exist, export them; otherwise try aliasing
    if (typeof setWorking === "function") {
      window.setWorking = setWorking;
      // alias older name
      if (typeof window.setWork !== "function") window.setWork = setWorking;
    } else if (typeof setWork === "function") {
      window.setWork = setWork;
      // alias to working name
      if (typeof window.setWorking !== "function") window.setWorking = setWork;
    }

    // ensure other functions are exported if present
    if (typeof initTopScrollbars === "function")
      window.initTopScrollbars = initTopScrollbars;
    if (typeof updateStatus === "function") {
      window.updateStatus = updateStatus;
      if (window._queuedUpdateStatusCalls) {
        window._queuedUpdateStatusCalls.forEach((c) => {
          try {
            window.updateStatus(c.id, c.status);
          } catch (e) {
            console.warn(e);
          }
        });
        window._queuedUpdateStatusCalls = [];
      }
    }
    if (typeof acceptRow === "function") window.acceptRow = acceptRow;
    if (typeof pendingRow === "function") window.pendingRow = pendingRow;
    if (typeof saveRow === "function") window.saveRow = saveRow;
    if (typeof saveReview === "function") window.saveReview = saveReview;
    if (typeof requestResubmission === "function")
      window.requestResubmission = requestResubmission;
    if (typeof editRow === "function") window.editRow = editRow;
  } catch (e) {
    console.warn("export/alias block error:", e);
  }
})();
function downloadResubmissionByOperator(opId) {
  fetch("get_resubmission_for_operator.php?op=" + encodeURIComponent(opId))
    .then((r) => r.json())
    .then((json) => {
      if (!json.success) {
        alert(json.message || "No resubmission request found");
        return;
      }
      if (json.doc_count === 0) {
        alert("This request has no listed documents to resubmit.");
        return;
      }
      // If server indicates files are already present, inform user then open download
      const okToDownload = confirm(
        json.has_files
          ? "Uploaded resubmission files are available. Download now?"
          : "No uploaded files yet. Do you still want to download the requested doc list?"
      );
      if (!okToDownload) return;
      const url =
        "download_resubmission.php?request_id=" +
        encodeURIComponent(json.request_id);
      window.open(url, "_blank");
    })
    .catch((err) => {
      console.error(err);
      alert("Failed to check resubmission request");
    });
}
// ---------- Operator Bulk Upload Integration (improved) ----------
/* CSS loader: the CSS below should be put in your separate CSS file as requested.
   See the CSS section after this JS block. */

(function() {
  // Create a global toast container if not present
  if (!document.getElementById('qb-toast-container')) {
    const c = document.createElement('div');
    c.id = 'qb-toast-container';
    document.body.appendChild(c);
  }
})();

function showToast(message, type = 'info', autoHide = 3000) {
  // types: info | success | error
  const container = document.getElementById('qb-toast-container');
  const t = document.createElement('div');
  t.className = `qb-toast qb-toast-${type} qb-fadein`;
  t.innerHTML = `<div class="qb-toast-body">${message}</div>`;
  container.appendChild(t);
  // auto hide
  setTimeout(()=> {
    t.classList.remove('qb-fadein');
    t.classList.add('qb-fadeout');
    setTimeout(()=> t.remove(), 450);
  }, autoHide);
}

// Helper to disable and re-enable form controls during upload
function setFormBusy(form, busy=true) {
  const btns = form.querySelectorAll('button, input[type="submit"]');
  btns.forEach(b => b.disabled = busy);
  if (busy) form.classList.add('qb-busy');
  else form.classList.remove('qb-busy');
}

// file list widget: converts <input type="file"> into a list with remove buttons
function attachFileList(input) {
  if (!input) return;
  // avoid double attach
  if (input.dataset.qbAttached) return;
  input.dataset.qbAttached = '1';

  const wrapper = document.createElement('div');
  wrapper.className = 'qb-filelist-wrapper';
  input.insertAdjacentElement('afterend', wrapper);

  function refreshList() {
    wrapper.innerHTML = '';
    // For multi-file inputs, use FileList
    const files = input.files;
    if (!files || files.length === 0) return;
    Array.from(files).forEach((f, idx) => {
      const row = document.createElement('div');
      row.className = 'qb-file-row';
      const nameSpan = document.createElement('span');
      nameSpan.className = 'qb-file-name';
      nameSpan.textContent = f.name;
      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'qb-file-remove';
      removeBtn.innerHTML = '&#x2716;';
      removeBtn.title = 'Remove';
      removeBtn.addEventListener('click', () => {
        // remove this file from input.files via DataTransfer
        const dt = new DataTransfer();
        Array.from(files).forEach((file, i) => { if (i !== idx) dt.items.add(file); });
        input.files = dt.files;
        refreshList();
      });
      row.appendChild(nameSpan);
      row.appendChild(removeBtn);
      wrapper.appendChild(row);
    });
  }

  input.addEventListener('change', refreshList);
}

// wire custom file lists for known inputs if present
document.addEventListener('DOMContentLoaded', () => {
  // match both plain and array-style input names
  const names = ['operator_excel','operator_docs','operator_docs[]','bulk_excel','bulk_docs'];
  names.forEach(name => {
    const el = document.querySelector(`input[name="${name}"]`);
    if (el) attachFileList(el);
  });

  // also attach to dynamic elements if added later (mutation observer optional)
});


// actual upload code with protection
async function uploadFormData(form) {
  // Avoid multiple concurrent uploads from same form
  if (form.dataset.uploading === '1') {
    return { success:false, message:'Upload already in progress' };
  }
  form.dataset.uploading = '1';
  setFormBusy(form, true);

  try {
    const fd = new FormData(form);
    fd.append('caseType', form.dataset.action || '');
    const res = await fetch('OperatorBulkUploadDocumentsText.php', { method:'POST', body: fd });
    const text = await res.text();
    // Try parse JSON; if not JSON, log raw text for debugging
    try {
      const json = JSON.parse(text);
      return json;
    } catch (e) {
      console.error('Non-JSON response from server:', text);
      return { success:false, message:'Invalid server response (check console)', raw:text };
    }
  } catch (err) {
    console.error('Upload fetch error', err);
    return { success:false, message:'Network error' };
  } finally {
    form.dataset.uploading = '0';
    setFormBusy(form, false);
  }
}

document.addEventListener('submit', function(e) {
  const form = e.target;
  if (!form || !form.dataset || !form.dataset.action) return;
  e.preventDefault();
  // id for result area
  const resultDiv = form.querySelector('#uploadResult') || document.getElementById('uploadResult');
  if (resultDiv) {
    resultDiv.classList.remove('qb-msg-success','qb-msg-error');
    resultDiv.textContent = '⏳ Processing...';
  }
  uploadFormData(form).then(json => {
    if (json.success) {
      if (resultDiv) {
        resultDiv.textContent = 'Imported ' + (json.message || 'success');
        resultDiv.classList.add('qb-msg-success');
      }
      showToast(json.message || 'Upload successful', 'success', 2500);
      // refresh overview list
      if (typeof loadOverview === 'function') loadOverview('', 1);
    } else {
      if (resultDiv) {
        resultDiv.textContent = '⚠️ ' + (json.message || 'Error');
        resultDiv.classList.add('qb-msg-error');
      }
      showToast(json.message || 'Upload failed. Check console.', 'error', 5000);
      if (json.raw) console.log('Server raw output:', json.raw);
    }
  }).catch(err => {
    console.error(err);
    showToast('Upload failed. Check console.', 'error', 5000);
    if (resultDiv) resultDiv.textContent = '⛔ Upload failed';
  });
});

function toastUpload(msg, type='info') { showToast(msg, type); }
// ---------------- Section toggle / sidebar link handler ----------------
(function(){
  function showSection(id){
    // hide all content-section sections
    document.querySelectorAll('.content-section').forEach(s => {
      s.style.display = 'none';
      s.classList.remove('qb-panel-visible');
    });
    const el = document.getElementById(id);
    if (!el) return;
    el.style.display = ''; // let CSS handle layout
    // small fade-in class
    el.classList.add('qb-panel-visible');
    // scroll into view for users
    el.scrollIntoView({behavior:'smooth', block:'start'});
  }

  // attach click to any sidebar nav anchor that has data-section
  document.addEventListener('click', function(e){
    const a = e.target.closest('a[data-section]');
    if (!a) return;
    e.preventDefault();
    const target = a.getAttribute('data-section');
    // set active class on nav
    document.querySelectorAll('nav a[data-section]').forEach(n => n.classList.remove('is-active'));
    a.classList.add('is-active');
    showSection(target);
  });

  // On page load: if URL hash present, try to show that section; else show default.
  document.addEventListener('DOMContentLoaded', function(){
    // try to show existingOperatorUploadSection if sidebar link exists and was clicked previously
    // default to operatorStatusSection if exists
    const hash = location.hash.replace('#','');
    if (hash && document.getElementById(hash)) {
      showSection(hash);
    } else {
      // try to find nav with is-active and show its data-section
      const active = document.querySelector('nav a.is-active');
      if (active) {
        const sec = active.getAttribute('data-section');
        if (sec && document.getElementById(sec)) showSection(sec);
      }
    }
  });
})();
// ---------- Operator upload helper (drop-in) ----------
document.addEventListener('DOMContentLoaded', function() {

  /* ---------- Config: change these headers to your keywords ---------- */
  const singleHeaders = ['operator_id', 'operator_name', 'email', 'phone']; // change as needed
  const bulkHeaders   = ['operator_id','operator_name','contact_email'];     // change as needed

  // Utility: download a CSV (header-only)
  function downloadCSV(headers, filename) {
    const csv = headers.join(',') + '\n';
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  }

  // Wire download buttons
  const dlSingle = document.getElementById('downloadSingleTemplate');
  const dlBulk   = document.getElementById('downloadBulkTemplate');
  if (dlSingle) dlSingle.addEventListener('click', function(){ downloadCSV(singleHeaders, 'operator-template.csv'); });
  if (dlBulk)   dlBulk.addEventListener('click', function(){ downloadCSV(bulkHeaders, 'bulk-operators-template.csv'); });

  // show header previews in rulebook
  const elSinglePreview = document.getElementById('singleHeadersPreview');
  const elBulkPreview   = document.getElementById('bulkHeadersPreview');
  if (elSinglePreview) elSinglePreview.textContent = singleHeaders.join(', ');
  if (elBulkPreview)   elBulkPreview.textContent = bulkHeaders.join(', ');

  // QUICk filelist for operator docs input (for directory uploads)
  function attachFileListSimple(input, outElSelector) {
    if (!input) return;
    const out = document.querySelector(outElSelector);
    input.addEventListener('change', function() {
      if (!out) return;
      out.innerHTML = '';
      const files = Array.from(input.files || []);
      if (!files.length) { out.textContent = '(no files)'; return; }
      files.slice(0, 30).forEach(f => { // cap to 30 for UI sanity
        const d = document.createElement('div');
        d.className = 'qb-file-row';
        d.innerHTML = `<span class="qb-file-name">${f.webkitRelativePath || f.name}</span><small class="qb-file-meta">${(f.size/1024|0)} KB</small>`;
        out.appendChild(d);
      });
      if (files.length > 30) {
        const more = document.createElement('div'); more.className='qb-file-row'; more.textContent = `+ ${files.length-30} more files...`;
        out.appendChild(more);
      }
    });
  }

  attachFileListSimple(document.getElementById('operatorDocs'), '#operatorDocsFileList');

  // Rule toggles (expand/collapse)
  document.querySelectorAll('.rule-toggle').forEach(btn => {
    btn.addEventListener('click', function() {
      const target = document.querySelector(this.dataset.target);
      if (!target) return;
      const showing = !target.hidden;
      target.hidden = showing;
      this.textContent = showing ? 'Show' : 'Hide';
    });
  });

  // Simulated ZIP preview expansion for demo (uses data-contents attribute)
  document.querySelectorAll('.qb-toggle-tree').forEach(btn => {
    btn.addEventListener('click', function() {
      const preview = this.nextElementSibling;
      if (!preview) return;
      if (!preview.hidden) { preview.hidden = true; preview.innerHTML=''; return; }
      preview.hidden = false;
      const items = (this.dataset.contents || '').split(',').map(s => s.trim()).filter(Boolean);
      const ul = document.createElement('ul');
      ul.className = 'qb-tree';
      items.forEach(i => {
        const li = document.createElement('li'); li.textContent = i; ul.appendChild(li);
      });
      preview.innerHTML = ''; preview.appendChild(ul);
    });
  });

  // small accessibility: submit handlers already handled in your existing code
  // but we'll prevent a double form submit UX by disabling submit on click quickly
  document.querySelectorAll('form.qb-form').forEach(form => {
    form.addEventListener('submit', function(ev) {
      const btn = form.querySelector('button[type="submit"], .submit-btn');
      if (btn) { btn.disabled = true; setTimeout(()=>btn.disabled=false, 2000); }
    });
  });

  // user note: you can update keywords list dynamically if you want
  const keywordsList = document.getElementById('keywordsList');
  if (keywordsList) {
    // Example: you can populate or update via JS like this:
    // keywordsList.innerHTML = ['identity','address','agreement'].map(k => '<li>'+k+'</li>').join('');
  }

}); // DOMContentLoaded
// Rule toggles: smooth slide + fade + accessible (paste inside DOMContentLoaded or at end)
(function () {
  // attach once
  const toggles = Array.from(document.querySelectorAll('.rule-toggle'));
  if (!toggles.length) return;

  toggles.forEach(btn => {
    const targetSelector = btn.dataset.target;
    if (!targetSelector) return;
    const panel = document.querySelector(targetSelector);
    if (!panel) return;

    // init ARIA
    btn.setAttribute('aria-expanded', 'false');
    btn.setAttribute('aria-controls', panel.id || '');
    panel.setAttribute('aria-hidden', 'true');

    // ensure panel is closed initially (remove hidden attr so CSS controls height)
    if (panel.hasAttribute('hidden')) {
      panel.removeAttribute('hidden');
      panel.style.display = 'block'; // visible to measure, but max-height 0 hides it
    }

    // helper to collapse
    function collapse() {
      // set max-height to current height for transition starting point
      panel.style.maxHeight = panel.scrollHeight + 'px';
      // force reflow
      panel.getBoundingClientRect();
      // then transition to 0
      requestAnimationFrame(() => {
        panel.style.maxHeight = '0px';
        panel.classList.remove('is-open');
        panel.setAttribute('aria-hidden', 'true');
        btn.setAttribute('aria-expanded', 'false');
        btn.classList.remove('rotate');
      });
    }

    // helper to expand
    function expand() {
      // set max-height to content height
      panel.style.maxHeight = panel.scrollHeight + 'px';
      panel.classList.add('is-open', '_flash');
      panel.setAttribute('aria-hidden', 'false');
      btn.setAttribute('aria-expanded', 'true');
      btn.classList.add('rotate');
      // remove the flash helper after animation
      setTimeout(()=> panel.classList.remove('_flash'), 380);
      // then clear maxHeight after transition completes so it can auto-size if content changes
      panel.addEventListener('transitionend', function te(ev) {
        if (ev.propertyName === 'max-height') {
          // only clear if currently open
          if (panel.classList.contains('is-open')) {
            panel.style.maxHeight = 'none';
          }
          panel.removeEventListener('transitionend', te);
        }
      });
    }

    // toggle handler (prevents double-tap problems)
    let busy = false;
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      if (busy) return;
      busy = true;
      const isOpen = panel.classList.contains('is-open');
      if (isOpen) {
        // collapse
        // set maxHeight to current content height (if none, compute)
        panel.style.maxHeight = (panel.scrollHeight || 0) + 'px';
        // small timeout to ensure starting value applied
        requestAnimationFrame(() => collapse());
      } else {
        // prepare: if maxHeight is 'none' clear style first
        panel.style.maxHeight = '0px';
        // allow a frame to apply
        requestAnimationFrame(() => expand());
      }
      // release busy after animation time (safe margin)
      setTimeout(() => { busy = false; }, 520);
      // also update button text if desired (Show <-> Hide)
      btn.textContent = isOpen ? 'Show' : 'Hide';
    });

    // optional: close panel when clicking outside (useful)
    document.addEventListener('click', function (ev) {
      const isInside = ev.target.closest && ev.target.closest(targetSelector);
      const clickedToggle = ev.target.closest && ev.target.closest('.rule-toggle');
      if (!isInside && !clickedToggle && panel.classList.contains('is-open')) {
        // collapse quietly
        collapse();
        btn.textContent = 'Show';
      }
    }, { capture: true });

  });

})();



// paste into em_verfi.js (replace older saveRow)
async function saveRow(maybeId) {
  try {
    // determine id
    let id = (typeof maybeId !== 'undefined' && maybeId !== null) ? Number(maybeId) : NaN;

    // fallback: element dataset or last clicked element
    if (!id || isNaN(id) || id <= 0) {
      const ae = document.activeElement;
      if (ae && ae.dataset && ae.dataset.id) id = Number(ae.dataset.id);
    }
    if (!id || isNaN(id) || id <= 0) {
      const el = window._lastClickedElement || document.activeElement;
      if (el) {
        const tr = el.closest ? el.closest('tr[id^="row-"]') : null;
        if (tr) {
          const m = tr.id.match(/^row-(\d+)$/);
          if (m) id = Number(m[1]);
        }
      }
    }
    // fallback: modal hidden input
    if (!id || isNaN(id) || id <= 0) {
      const modal = document.querySelector('#row-edit-modal');
      if (modal) {
        const hid = modal.querySelector('input[name="id"], input[name="operator_id"], input[type="hidden"].row-id');
        if (hid) id = Number(hid.value || hid.dataset.id || 0);
      }
    }

    if (!id || isNaN(id) || id <= 0) {
      alert('Save failed: missing row id. Make sure Save Row is called with the row id or the Save button has data-id attribute.');
      console.error('saveRow: could not determine id. maybeId=', maybeId, 'activeElement=', document.activeElement);
      return;
    }

    // collect data
    const payload = { id: id };
    const row = document.getElementById(`row-${id}`);
    const modal = document.querySelector('#row-edit-modal');

    if (modal) {
      modal.querySelectorAll('input[name],select[name],textarea[name]').forEach(el => {
        if (el.type === 'checkbox') payload[el.name] = !!el.checked;
        else if (el.type === 'radio') { if (el.checked) payload[el.name] = el.value; }
        else payload[el.name] = el.value;
      });
    }
    if (row) {
      // gather inputs displayed inline (makeRowEditable uses input[data-edit])
      row.querySelectorAll('input[name],select[name],textarea[name]').forEach(el => {
        if (el.type === 'checkbox') payload[el.name] = !!el.checked;
        else if (el.type === 'radio') { if (el.checked) payload[el.name] = el.value; }
        else payload[el.name] = el.value;
      });
      // also gather cell text content; key = data-col (this helps if your columns are DB column names)
      row.querySelectorAll('td[data-col]').forEach(td => {
        const col = td.getAttribute('data-col');
        if (!col) return;
        // do not overwrite if we already collected value via named input
        if (payload.hasOwnProperty(col)) return;
        const dataVal = td.getAttribute('data-value');
        payload[col] = dataVal !== null ? dataVal : td.textContent.trim();
      });
    }

    console.info('saveRow payload', payload);

    const resp = await fetch('update_row.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      cache: 'no-cache'
    });

    const raw = await resp.text();
    console.info('update_row.php raw response', raw);
    let json = null;
    try { json = JSON.parse(raw); } catch(e) {
      alert('Server returned invalid JSON. Check DevTools Network -> Response.');
      console.error('Invalid JSON response', raw);
      return;
    }

    if (!json.success) {
      // if backend returned helpful diagnostic we show it
      const msg = (json.message || json.error || 'server error');
      alert('Save failed: ' + msg);
      console.error('saveRow server error', json);
      // If server returned row_preview it helps identify mismatch
      if (json.row_preview) console.info('row_preview', json.row_preview);
      return;
    }

    // success — update cells inline or reload
    if (json.updated_fields && row) {
      Object.entries(json.updated_fields).forEach(([col,val]) => {
        const td = row.querySelector(`td[data-col="${col}"]`);
        if (!td) return;
        // if file columns, you may want to create a link
        if (col.endsWith('_file') && val) td.innerHTML = `<a href="${val}" target="_blank">View</a>`;
        else td.textContent = val === null ? '' : String(val);
      });
    } else {
      location.reload();
    }

    showToast ? showToast('Row saved', 'success', 2500) : alert('Row saved successfully');
  } catch (err) {
    console.error('saveRow exception', err);
    alert('Save failed (client error). Check console.');
  }
}

// optional: store last-click for fallback id resolution
document.addEventListener('click', function(e){ window._lastClickedElement = e.target; }, true);
