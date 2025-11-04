/* ============================
   Document & Status — FINAL JS
   ============================ */

/* ---------- boot / loader (fail-safe) ---------- */
(function () {
  // define bootShow at top-level (not inside a block)
  const bootShow = () => {
    const loader = document.getElementById("loader");
    if (loader) loader.style.display = "none";
    const main = document.getElementById("main-content");
    if (main) main.style.display = "grid";
    initDS();
  };

  if (!window.__DS_INITED) {
    window.__DS_INITED = true;

    // short vibe, never forever
    document.addEventListener("DOMContentLoaded", () => {
      setTimeout(bootShow, 600);
    });

    // hard fallback after 3.5s
    setTimeout(() => {
      const loader = document.getElementById("loader");
      if (loader && loader.style.display !== "none") bootShow();
    }, 3500);
  }
})();

/* ---------- panel dataset helper (safe) ---------- */
const panel = document.getElementById("opDetailPanel");
if (panel && typeof data !== "undefined" && data && data.operator_id) {
  panel.dataset.opId = String(data.operator_id).trim();
}

/* ---------- main init ---------- */
function initDS() {
  // Don’t block download links that open in new tab
  document.addEventListener(
    "click",
    (e) => {
      const a = e.target.closest('a[target="_blank"]');
      if (a && a.classList.contains("i-download")) return; // allow default
    },
    { capture: true }
  );

  // Top status/work buttons
  const grid = document.querySelector(".action-grid");
  if (grid) {
    grid.addEventListener(
      "click",
      (e) => {
        const btn = e.target.closest(".action-btn");
        if (!btn) return;

        if (btn.dataset.status) {
          const val = btn.dataset.status;
          post("update_status.php", {
            id: OP_ROW_ID,
            operator_id: OPERATOR_ID,
            status: val,
          })
            .then((res) => {
              setChip("overallStatusChip", res.status || val);
              toast("Status updated ✔️", "success");
            })
            .catch((err) => toast("Status update failed: " + err.message, "error"));
          return;
        }

        if (btn.dataset.work) {
          const val = btn.dataset.work;
          post("update_status.php", {
            id: OP_ROW_ID,
            operator_id: OPERATOR_ID,
            work_status: val,
          })
            .then((res) => {
              setChip("workStatusChip", res.work_status || val);
              toast("Work status updated ⚙️", "success");
            })
            .catch((err) => toast("Work update failed: " + err.message, "error"));
          return;
        }

        if (btn.id === "reqResub") {
          postNewTab("create_resubmission.php", { operator_id: OPERATOR_ID });
          return;
        }
        if (btn.id === "dlResub") {
          postNewTab("download_resubmission.php", { operator_id: OPERATOR_ID });
          return;
        }
      },
      { passive: true }
    );
  }

  // Review notes save (permanent + repaint)
  const saveBtn = document.getElementById("saveReview");
  if (saveBtn) {
    saveBtn.addEventListener("click", () => {
      const dbId = Number(window.OP_ROW_ID || 0);
      if (!dbId) {
        toast("Missing DB id", "error");
        return;
      }
      const ta = document.getElementById("reviewNotes");
      const notes = (ta && ta.value ? ta.value : "").trim();

      fetch("update_row.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: dbId, review_notes: notes }),
        credentials: "same-origin",
      })
        .then(async (r) => {
          const text = await r.text();
          let j = null;
          try {
            j = JSON.parse(text);
          } catch {}
          if (!r.ok || !j || j.success !== true) {
            const msg = (j && (j.error || j.message)) || `HTTP ${r.status}: ${text.slice(0, 180)}`;
            throw new Error(msg);
          }
          return j;
        })
        .then((j) => {
          if (ta) ta.value = j.review_notes || "";
          showTick();
          toast("Review saved ✓");
        })
        .catch((e) => {
          console.error(e);
          toast("Request failed — " + e.message, "error");
        });
    });
  }

  // counts + paint
  initCounts();
  paintRowsFromSavedMap();
  autoPreviewFirst();

  // Preview handler
  const previewLabel = document.getElementById("previewLabel");
  const previewBody = document.getElementById("previewContent");
  document.querySelectorAll(".doc-item").forEach((row) => {
    row.addEventListener(
      "click",
      (e) => {
        if (e.target.closest(".ico")) return;
        setPreview(previewLabel, previewBody, row.dataset.label || "", row.dataset.file || "");
      },
      { passive: true }
    );
  });

  // Per-doc actions
  const receivedWrap = document.getElementById("docReceived");
  if (receivedWrap) {
    receivedWrap.addEventListener("click", (e) => handleDocIcons(e, window.__DS_COUNTS));
  }
  const notRecvWrap = document.getElementById("docNotReceived");
  if (notRecvWrap) {
    notRecvWrap.addEventListener("click", (e) => handleDocIcons(e, window.__DS_COUNTS));
  }

  // “Save All” bulk confirm
  const saveAll = document.getElementById("saveAllDocs");
  if (saveAll) {
    saveAll.addEventListener("click", async () => {
      saveAll.disabled = true;
      const old = saveAll.textContent;
      saveAll.textContent = "Saving…";
      try {
        const map = {};
        document.querySelectorAll(".doc-item").forEach((r) => {
          map[r.dataset.key] = r.dataset.state || "none";
        });
        const res = await fetch("update_doc_bulk.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ operator_id: OPERATOR_ID, states: map }),
        });
        const j = await res.json();
        if (!j || j.success !== true) throw new Error((j && j.message) || "Failed");
        toast("All document statuses saved ✔");
      } catch (err) {
        alert("Bulk save failed: " + err.message);
      } finally {
        saveAll.disabled = false;
        saveAll.textContent = old;
      }
    });
  }

  // Initial chip colors
  if (typeof BOOT_STATUS === "string" && BOOT_STATUS) setChip("overallStatusChip", BOOT_STATUS);
  if (typeof BOOT_WORK === "string" && BOOT_WORK) setChip("workStatusChip", BOOT_WORK);
}

/* ---------- helpers ---------- */

// auto-select first document into preview
function autoPreviewFirst() {
  const row = document.querySelector('.doc-item.received, .doc-item[data-file]:not(.not-received)');
  if (!row) return;
  const previewLabel = document.getElementById("previewLabel");
  const previewBody = document.getElementById("previewContent");
  setPreview(previewLabel, previewBody, row.dataset.label || "", row.dataset.file || "");
}

function initCounts() {
  const c = {
    accepted: Number((window.DOC_COUNTS && DOC_COUNTS.accepted) || 0),
    pending: Number((window.DOC_COUNTS && DOC_COUNTS.pending) || 0),
    received: Number((window.DOC_COUNTS && DOC_COUNTS.received) || 0),
    notReceived: Number((window.DOC_COUNTS && DOC_COUNTS.notReceived) || 0),
  };
  window.__DS_COUNTS = { ...c };
  applyCounts(c);

  // server refresh (cards only)
  fetch(`get_doc_counts.php?operator_id=${encodeURIComponent(OPERATOR_ID)}`)
    .then((r) => r.json())
    .then((d) => d && d.success && applyCounts(d))
    .catch(() => {});
}

function paintRowsFromSavedMap() {
  if (!window.DOC_STATUS_MAP) return;
  let map = {};
  try {
    map = typeof DOC_STATUS_MAP === "string" ? JSON.parse(DOC_STATUS_MAP || "{}") : DOC_STATUS_MAP || {};
  } catch {
    map = {};
  }
  Object.entries(map).forEach(([key, val]) => {
    const row = document.querySelector(`.doc-item[data-key="${key}"]`);
    if (!row) return;
    const st = (val || "none").toLowerCase().replace("accept", "accepted");
    row.dataset.state = st;
    row.classList.remove("accepted", "pending", "none");
    row.classList.add(st);
  });
}

/* ---------- per-doc actions ---------- */
function handleDocIcons(e, counts) {
  const a = e.target.closest(".ico");
  if (!a) return;

  const isDownload = a.classList.contains("i-download");
  if (!isDownload) e.preventDefault();

  const row = e.target.closest(".doc-item");
  if (!row) return;

  const key = row.dataset.key;
  const file = row.dataset.file || "";
  const cur = row.dataset.state || "none"; // none|pending|accepted

  function setState(next) {
    let c = window.__DS_COUNTS;
    if (cur === next) {
      row.dataset.state = "none";
      if (cur === "pending") c.pending = Math.max(0, c.pending - 1);
      if (cur === "accepted") c.accepted = Math.max(0, c.accepted - 1);
    } else {
      if (cur === "pending") c.pending = Math.max(0, c.pending - 1);
      if (cur === "accepted") c.accepted = Math.max(0, c.accepted - 1);
      if (next === "pending") c.pending++;
      if (next === "accepted") c.accepted++;
      row.dataset.state = next;
    }
    applyCounts(c);
    pulse(row, next);
  }

  // state actions
  if (a.classList.contains("i-pending")) {
    updateDocStatus(key, "pending").then(() => setState("pending"));
    return;
  }
  if (a.classList.contains("i-accept")) {
    updateDocStatus(key, "accepted").then(() => setState("accepted"));
    return;
  }

  // download (new tab via HTML target)
  if (isDownload) {
    if (!file) toast("No file to download", "error");
    return; // let browser handle it
  }

  // upload/replace
  if (a.classList.contains("i-replace") || a.classList.contains("i-upload")) {
    const inp = document.createElement("input");
    inp.type = "file";
    inp.onchange = () => {
      if (!inp.files[0]) return;
      const fd = new FormData();
      fd.append("id", OP_ROW_ID);
      fd.append("operator_id", OPERATOR_ID);
      fd.append("doc_key", key);
      fd.append("file", inp.files[0]);

      fetch("upload_docs.php", { method: "POST", body: fd })
        .then((r) => r.json().catch(() => ({})))
        .then((res) => {
          if (res && res.success) {
            if (row.classList.contains("not-received")) {
              let c = window.__DS_COUNTS;
              c.received++;
              c.notReceived = Math.max(0, c.notReceived - 1);
              row.classList.remove("not-received");
              row.classList.add("received");
              row.dataset.file = res.path || "uploaded";
              applyCounts(c);
            }
            toast("Uploaded");
          } else {
            toast((res && res.message) || "Upload failed", "error");
          }
        })
        .catch(() => toast("Upload failed", "error"));
    };
    inp.click();
  }
}

/* ---------- server calls & UI utils ---------- */
function updateDocStatus(doc_key, action) {
  return post("update_doc_review.php", {
    id: OP_ROW_ID,
    operator_id: OPERATOR_ID,
    doc_key,
    action, // 'pending' | 'accept'
    status: action,
  }).then((res) => {
    try {
      const j = JSON.parse(res);
      if (j && typeof j === "object" && "accepted" in j) {
        window.__DS_COUNTS = {
          accepted: j.accepted,
          pending: j.pending,
          received: j.received,
          notReceived: j.notReceived,
        };
        applyCounts(window.__DS_COUNTS);
      }
    } catch {}
    return true;
  });
}

function applyCounts(c) {
  setText("#cAccepted", c.accepted);
  setText("#cPending", c.pending);
  setText("#cReceived", c.received);
  setText("#cNotReceived", c.notReceived);
}

function pulse(el, state) {
  el.style.transition = "background .25s ease, transform .2s";
  el.style.transform = "translateY(-1px)";
  el.style.background = state === "accepted" ? "#e8f7e9" : state === "pending" ? "#fff8e6" : "";
  setTimeout(() => {
    el.style.transform = "";
    el.style.background = "";
  }, 350);
}

function setPreview(previewLabel, previewBody, label, file) {
  if (!previewLabel || !previewBody) return;
  previewLabel.innerHTML = "<b>" + escapeHtml(label) + "</b>";
  if (!file) {
    previewBody.innerHTML = "No document uploaded.";
    return;
  }
  const safe = encodeURI(file);
  const title = escapeHtml(label);

  if (/\.(png|jpe?g|gif|webp|bmp|svg)$/i.test(file)) {
    previewBody.innerHTML = `<img src="${safe}" alt="${title}" />`;
  } else {
    previewBody.innerHTML = `<iframe src="${safe}" title="${title}"></iframe>`;
  }
}

function setChip(id, val) {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = val || "—";
  el.className = "status-chip";
  const v = (val || "").toLowerCase();
  if (v.includes("accept") || v.includes("work")) el.classList.add("chip-ok");
  else if (v.includes("pending")) el.classList.add("chip-warn");
  else if (v.includes("not")) el.classList.add("chip-bad");
}

function setText(sel, val) {
  const el = document.querySelector(sel);
  if (el) el.textContent = val;
}
function showTick() {
  const t = document.getElementById("reviewSaved");
  if (!t) return;
  t.style.display = "inline";
  setTimeout(() => (t.style.display = "none"), 1600);
}
function toast(msg, type) {
  console[type === "error" ? "error" : "log"](msg);
}

/* ---------- net ---------- */
function post(url, data) {
  return fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams(data),
    credentials: "same-origin",
  }).then(async (r) => {
    const text = await r.text();
    let json = null;
    try {
      json = JSON.parse(text);
    } catch {}
    if (!r.ok || !json || json.success !== true) {
      const msg = (json && (json.error || json.message)) || `HTTP ${r.status}${text ? `: ${text.slice(0, 180)}` : ""}`;
      throw new Error(msg);
    }
    return json;
  });
}

/* ---------- utils ---------- */
function escapeHtml(s) {
  return (s || "").replace(/[&<>"']/g, (m) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[m]));
}
function postNewTab(action, params) {
  const form = document.createElement("form");
  form.method = "POST";
  form.action = action;
  form.target = "_blank";
  Object.entries(params || {}).forEach(([k, v]) => {
    const i = document.createElement("input");
    i.type = "hidden";
    i.name = k;
    i.value = v;
    form.appendChild(i);
  });
  document.body.appendChild(form);
  form.submit();
  form.remove();
}
/* Lightweight zoom controls without touching your existing JS */
(function () {
  const box = document.getElementById('previewBox');
  const content = document.getElementById('previewContent');
  if (!box || !content) return;

  // toolbar
  const bar = document.createElement('div');
  bar.className = 'preview-toolbar';
  bar.innerHTML = `
    <button type="button" id="zOut">−</button>
    <button type="button" id="zIn">+</button>
    <button type="button" id="z100">100%</button>
  `;
  box.prepend(bar);

  let scale = 1;
  const clamp = (v, a, b) => Math.min(b, Math.max(a, v));

  function getMedia() {
    return content.querySelector('img, iframe, embed, object');
  }

  function apply() {
    const m = getMedia();
    if (!m) return;
    m.style.transform = `scale(${scale})`;
    m.style.transformOrigin = 'top left';
    // enable scroll when zoomed
    content.style.overflow = scale !== 1 ? 'auto' : 'auto';
  }

  document.getElementById('zIn').onclick  = () => { scale = clamp(scale + 0.1, 0.2, 3); apply(); };
  document.getElementById('zOut').onclick = () => { scale = clamp(scale - 0.1, 0.2, 3); apply(); };
  document.getElementById('z100').onclick = () => { scale = 1; apply(); };

  // reset zoom whenever content swaps to a new doc
  new MutationObserver(() => { scale = 1; apply(); })
    .observe(content, { childList: true, subtree: true });
})();
