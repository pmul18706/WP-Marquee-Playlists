// assets/app/app.js

console.log("WMP2 app.js loaded", window.WMP2);

(function () {
  try {
    const $ = (sel) => document.querySelector(sel);

    async function api(path, opts = {}) {
      // Make REST base + path concatenation bulletproof
      const base = (WMP2.rest || "").endsWith("/") ? WMP2.rest : (WMP2.rest + "/");
      const cleanPath = String(path || "").replace(/^\/+/, ""); // no leading slash
      const res = await fetch(base + cleanPath, {
        method: opts.method || "GET",
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce": WMP2.nonce,
        },
        body: opts.body ? JSON.stringify(opts.body) : undefined,
      });

      const txt = await res.text();
      let data;
      try { data = JSON.parse(txt); } catch (e) { data = txt; }

      if (!res.ok) {
        const msg = data && data.message ? data.message : "Request failed: " + res.status;
        throw new Error(msg);
      }
      return data;
    }

    function esc(s) {
      return String(s ?? "").replace(/[&<>"']/g, (m) => ({
        "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
      }[m]));
    }

    // ---------- Slide Editor Modal ----------
    let slideModalEl = null;

    function ensureSlideModal() {
      if (slideModalEl) return slideModalEl;

      const bd = document.createElement("div");
      bd.className = "wmp2-modal-backdrop";
      bd.id = "wmp2-slide-modal";
      bd.innerHTML = `
        <div class="wmp2-modal" role="dialog" aria-modal="true">
          <div class="wmp2-modal-header">
            <h2>Slide Editor</h2>
            <div class="wmp2-modal-actions">
              <button class="wmp2-btn" id="wmp2-slide-cancel" type="button">Cancel</button>
              <button class="wmp2-btn primary" id="wmp2-slide-save" type="button">Save</button>
            </div>
          </div>

          <div class="wmp2-modal-toolbar" id="wmp2-slide-toolbar">
            <span class="wmp2-pill">
              <label>Font</label>
              <select id="wmp2-font">
                <option value="">Default</option>
                <option value="Ubuntu">Ubuntu</option>
                <option value="Arial">Arial</option>
                <option value="Helvetica">Helvetica</option>
                <option value="Georgia">Georgia</option>
                <option value="Times New Roman">Times New Roman</option>
                <option value="Consolas">Consolas</option>
                <option value="Verdana">Verdana</option>
                <option value="Tahoma">Tahoma</option>
              </select>
            </span>

            <span class="wmp2-pill">
              <label>Size</label>
              <input id="wmp2-fontsize" type="number" min="8" max="120" step="1" value="28" style="width:76px">
            </span>

            <button class="wmp2-toolbtn" id="wmp2-bold" type="button"><b>B</b></button>
            <button class="wmp2-toolbtn" id="wmp2-italic" type="button"><i>I</i></button>
            <button class="wmp2-toolbtn" id="wmp2-underline" type="button"><u>U</u></button>

            <span class="wmp2-pill">
              <label>Text</label>
              <input id="wmp2-color" type="color" value="#ffffff">
            </span>

            <span class="wmp2-pill">
              <label>BG</label>
              <input id="wmp2-bg" type="color" value="#000000">
            </span>

            <button class="wmp2-toolbtn" data-align="left" type="button">Left</button>
            <button class="wmp2-toolbtn" data-align="center" type="button">Center</button>
            <button class="wmp2-toolbtn" data-align="right" type="button">Right</button>

            <span class="wmp2-pill">
              <label>Marquee</label>
              <input id="wmp2-marquee-enabled" type="checkbox">
            </span>

            <span class="wmp2-pill">
              <label>Dir</label>
              <select id="wmp2-marquee-dir">
                <option value="left">Left</option>
                <option value="right">Right</option>
              </select>
            </span>

            <span class="wmp2-pill">
              <label>Speed</label>
              <input id="wmp2-marquee-speed" type="range" min="5" max="2000" value="80">
            </span>
          </div>

          <div class="wmp2-modal-body">
            <div class="wmp2-panel">
              <div class="wmp2-panel-hd">Edit</div>
              <div class="wmp2-panel-bd">
                <div id="wmp2-slide-edit" contenteditable="true"></div>
                <div class="wmp2-note">Variables: <code>{{time}}</code> <code>{{date}}</code> (and weather later)</div>
              </div>
            </div>

            <div class="wmp2-panel">
              <div class="wmp2-panel-hd">Preview</div>
              <div class="wmp2-panel-bd">
                <div id="wmp2-slide-preview"></div>
              </div>
            </div>
          </div>
        </div>
      `;

      document.body.appendChild(bd);
      slideModalEl = bd;

      bd.addEventListener("click", (e) => {
        if (e.target === bd) closeSlideEditor();
      });

      return bd;
    }

    // ---------- Text selection helpers ----------
    function wrapSelectionWithSpan(styleObj) {
      const sel = window.getSelection();
      if (!sel || sel.rangeCount === 0) return;

      const range = sel.getRangeAt(0);
      if (range.collapsed) return;

      const span = document.createElement("span");
      Object.assign(span.style, styleObj);

      try {
        range.surroundContents(span);
      } catch {
        const frag = range.extractContents();
        span.appendChild(frag);
        range.insertNode(span);
      }

      sel.removeAllRanges();
      const nr = document.createRange();
      nr.selectNodeContents(span);
      nr.collapse(false);
      sel.addRange(nr);
    }

    function simp2Url(token) {
      const base = (WMP2.site_url || "").replace(/\/$/, "");
      return `${base}/simp2/${token}/`;
    }

    function filenameFromUrl(url) {
      try {
        const u = new URL(url, window.location.origin);
        const parts = (u.pathname || "").split("/").filter(Boolean);
        return decodeURIComponent(parts.pop() || "");
      } catch (e) {
        const parts = String(url || "").split("?")[0].split("#")[0].split("/").filter(Boolean);
        return decodeURIComponent(parts.pop() || "");
      }
    }

    function nowLocalInput() {
      const d = new Date();
      const pad = (n) => String(n).padStart(2, "0");
      return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    }

    function clamp(n, a, b) {
      n = Number(n);
      if (Number.isNaN(n)) n = a;
      return Math.max(a, Math.min(b, n));
    }

    function normalizeHex(v) {
      const s = String(v || "").trim();
      if (!s) return "";
      if (/^#([0-9a-fA-F]{3})$/.test(s)) return s.toLowerCase();
      if (/^#([0-9a-fA-F]{6})$/.test(s)) return s.toLowerCase();
      return "";
    }

    // State
    let playlists = [];
    let selected = null;
    let items = [];

    // Categories
    let categories = [];
    let catMap = new Map();
    function rebuildCatMap() {
      catMap = new Map();
      (categories || []).forEach((c) => {
        catMap.set(Number(c.id), { id: Number(c.id), name: c.name, count: Number(c.count || 0) });
      });
    }
    async function loadCategories() {
      categories = await api("categories");
      if (!Array.isArray(categories)) categories = [];
      rebuildCatMap();
    }

    // ---------- UI helpers ----------
    function modal(html) {
      const bd = document.createElement("div");
      bd.className = "wmp2-modal-backdrop";
      bd.innerHTML = `<div class="wmp2-modal">${html}</div>`;
      bd.addEventListener("click", (e) => { if (e.target === bd) bd.remove(); });
      document.body.appendChild(bd);
      return { el: bd, close: () => bd.remove(), qs: (sel) => bd.querySelector(sel) };
    }

    function toast(msg) {
      const t = document.createElement("div");
      t.textContent = msg;
      t.style.cssText =
        "position:fixed;left:12px;bottom:12px;background:#111827;color:#fff;padding:10px 12px;border-radius:12px;font-size:13px;z-index:999999;box-shadow:0 10px 30px rgba(0,0,0,.35)";
      document.body.appendChild(t);
      setTimeout(() => t.remove(), 2200);
    }

    // --- attachments hydrate ---
    async function hydrateAttachmentUrls(itemsArr) {
      const ids = Array.from(new Set(
        itemsArr
          .filter(it => it.item_type === "media" && it.ref_id)
          .map(it => Number(it.ref_id))
          .filter(n => n > 0)
      ));
      if (!ids.length) return;

      const missing = Array.from(new Set(
        itemsArr
          .filter(it => it.item_type === "media" && it.ref_id && !(it.meta && it.meta.url))
          .map(it => Number(it.ref_id))
          .filter(n => n > 0)
      ));
      const toFetch = missing.length ? missing : ids;
      if (!toFetch.length) return;

      const data = await api(`attachments?ids=${encodeURIComponent(toFetch.join(","))}`);
      const map = new Map();
      (Array.isArray(data) ? data : []).forEach(row => {
        map.set(Number(row.id), { url: row.url, kind: row.kind || "image" });
      });

      itemsArr.forEach(it => {
        it.windows = Array.isArray(it.windows) ? it.windows : [];
        it.meta = (it.meta && typeof it.meta === "object") ? it.meta : {};
        if (it.item_type === "media" && it.ref_id) {
          const info = map.get(Number(it.ref_id));
          if (info) {
            it.meta.url = info.url;
            it.meta.kind = info.kind;
          }
        }
      });
    }

    function hydrateItems(itemsArr) {
      itemsArr.forEach(it => {
        it.windows = Array.isArray(it.windows) ? it.windows : [];
        it.meta = it.meta && typeof it.meta === "object" ? it.meta : {};
      });
    }

    // ---------- Playlists list ----------
    function renderList() {
      const el = $("#wmp2-list");
      if (!el) return;

      if (!playlists.length) {
        el.innerHTML = `<div style="color:#666">No playlists yet.</div>`;
        return;
      }

      el.innerHTML = playlists.map(p => `
        <div class="wmp2-listitem" data-id="${esc(p.id)}">
          <div style="min-width:0">
            <div style="font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(p.name)}</div>
            <div class="wmp2-link">Preview: <a target="_blank" href="${esc(simp2Url(p.token))}">${esc(simp2Url(p.token))}</a></div>
          </div>
          <div class="wmp2-pill">${esc(p.role)}</div>
        </div>
      `).join("");

      el.querySelectorAll(".wmp2-listitem").forEach(row => {
        row.addEventListener("click", () => {
          const id = Number(row.getAttribute("data-id"));
          selectPlaylist(id);
        });
      });
    }

    // ---------- Windows selection helpers ----------
    function windowCheckboxes(num, selectedWindows) {
      const sel = new Set(Array.isArray(selectedWindows) ? selectedWindows.map(n => Number(n)) : []);
      const allChecked = sel.size === 0;

      let out = `<div class="wmp2-win">
        <label><input type="checkbox" class="wmp2-win-all" ${allChecked ? "checked" : ""}> All</label>`;
      for (let i = 1; i <= num; i++) {
        out += `<label><input type="checkbox" class="wmp2-win-one" value="${i}" ${sel.has(i) ? "checked" : ""}> W${i}</label>`;
      }
      out += `</div>`;
      return out;
    }

    // ---------- Options panel ----------
    let detailView = "items";
    function getOpts() {
      const o = selected?.options || {};
      return {
        mode: o.mode || "marquee",
        direction: o.direction || "left",
        speed: Number(o.speed ?? 60),
        pause_on_hover: Number(o.pause_on_hover ?? 1),
        item_gap: Number(o.item_gap ?? 18),
        stage_bg: o.stage_bg || "",
        num_windows: Number(o.num_windows ?? 1),
        windows: Array.isArray(o.windows) ? o.windows : [{ x: 0, y: 0, width: 800, height: 200, fit: "contain" }],
        weather_location: o.weather_location || "",
      };
    }

    function ensureWindowsLength(opts) {
      const n = clamp(opts.num_windows, 1, 12);
      const arr = Array.isArray(opts.windows) ? opts.windows.slice() : [];
      while (arr.length < n) arr.push({ x: 0, y: 0, width: 800, height: 200, fit: "contain" });
      return arr.slice(0, n).map(w => ({
        x: clamp(w.x ?? 0, -99999, 99999),
        y: clamp(w.y ?? 0, -99999, 99999),
        width: clamp(w.width ?? 800, 1, 99999),
        height: clamp(w.height ?? 200, 1, 99999),
        fit: ["contain", "cover", "fill", "stretch"].includes(w.fit) ? w.fit : "contain",
      }));
    }

    function renderOptionsPanel() {
      const opts = getOpts();
      const windows = ensureWindowsLength(opts);

      return `
        <div class="wmp2-options">
          <div class="wmp2-row" style="justify-content:space-between">
            <div class="wmp2-subtitle" style="margin:0">Playlist Options</div>
            <div class="wmp2-row" style="margin:0">
              <button id="wmp2-preview" class="wmp2-btn secondary" type="button">Preview</button>
              <button id="wmp2-save-options" class="wmp2-btn" type="button">Save Options</button>
            </div>
          </div>

          <div class="wmp2-grid">
            <div class="wmp2-field">
              <label>Mode</label>
              <select id="opt-mode">
                <option value="marquee" ${opts.mode === "marquee" ? "selected" : ""}>Marquee</option>
                <option value="static" ${opts.mode === "static" ? "selected" : ""}>Static</option>
              </select>
            </div>

            <div class="wmp2-field">
              <label>Direction</label>
              <select id="opt-direction">
                <option value="left" ${opts.direction === "left" ? "selected" : ""}>Left</option>
                <option value="right" ${opts.direction === "right" ? "selected" : ""}>Right</option>
              </select>
            </div>

            <div class="wmp2-field">
              <label>Speed (px/sec)</label>
              <input id="opt-speed" type="number" min="5" max="2000" value="${esc(opts.speed)}">
            </div>

            <div class="wmp2-field">
              <label>Pause on hover</label>
              <select id="opt-pause">
                <option value="1" ${Number(opts.pause_on_hover) ? "selected" : ""}>Yes</option>
                <option value="0" ${!Number(opts.pause_on_hover) ? "selected" : ""}>No</option>
              </select>
            </div>

            <div class="wmp2-field">
              <label>Spacing between items (px)</label>
              <input id="opt-gap" type="number" min="0" max="500" value="${esc(opts.item_gap)}">
            </div>

            <div class="wmp2-field">
              <label>Background color (hex)</label>
              <input id="opt-bg" type="text" placeholder="#000000" value="${esc(opts.stage_bg)}">
            </div>

            <div class="wmp2-field">
              <label>Weather location (lat,lon)</label>
              <input id="opt-weather" type="text" placeholder="41.2459,-75.8813" value="${esc(opts.weather_location)}">
            </div>

            <div class="wmp2-field">
              <label>Number of windows</label>
              <input id="opt-numw" type="number" min="1" max="12" value="${esc(opts.num_windows)}">
            </div>
          </div>

          <div class="wmp2-subtitle" style="margin-top:12px">Windows</div>
          <div class="wmp2-windows">
            ${windows.map((w, i) => `
              <div class="wmp2-window-card" data-w="${i}">
                <div class="wmp2-window-head">
                  <b>Window ${i + 1}</b>
                  <span class="wmp2-mini">x:${esc(w.x)} y:${esc(w.y)} w:${esc(w.width)} h:${esc(w.height)}</span>
                </div>

                <div class="wmp2-grid">
                  <div class="wmp2-field">
                    <label>X</label>
                    <input class="win-x" type="number" value="${esc(w.x)}">
                  </div>
                  <div class="wmp2-field">
                    <label>Y</label>
                    <input class="win-y" type="number" value="${esc(w.y)}">
                  </div>
                  <div class="wmp2-field">
                    <label>Width</label>
                    <input class="win-w" type="number" min="1" value="${esc(w.width)}">
                  </div>
                  <div class="wmp2-field">
                    <label>Height</label>
                    <input class="win-h" type="number" min="1" value="${esc(w.height)}">
                  </div>
                  <div class="wmp2-field">
                    <label>Fit mode</label>
                    <select class="win-fit">
                      <option value="contain" ${w.fit==="contain"?"selected":""}>Contain</option>
                      <option value="cover" ${w.fit==="cover"?"selected":""}>Cover</option>
                      <option value="fill" ${w.fit==="fill"?"selected":""}>Fill (keep AR)</option>
                      <option value="stretch" ${w.fit==="stretch"?"selected":""}>Stretch</option>
                    </select>
                  </div>
                  <div class="wmp2-field">
                    <label>Quick hint</label>
                    <input type="text" value="Contain = fit; Cover = crop; Fill = keep AR; Stretch = distort" readonly>
                  </div>
                </div>
              </div>
            `).join("")}
          </div>
        </div>
      `;
    }

    function readOptionsFromUI() {
      const mode = ($("#opt-mode")?.value || "marquee");
      const direction = ($("#opt-direction")?.value || "left");
      const speed = clamp($("#opt-speed")?.value ?? 60, 5, 2000);
      const pause_on_hover = Number($("#opt-pause")?.value || 1) ? 1 : 0;
      const item_gap = clamp($("#opt-gap")?.value ?? 18, 0, 500);
      const stage_bg = normalizeHex($("#opt-bg")?.value || "");
      const num_windows = clamp($("#opt-numw")?.value ?? 1, 1, 12);
      const weather_location = String($("#opt-weather")?.value || "").trim();

      const windows = [];
      document.querySelectorAll(".wmp2-window-card").forEach(card => {
        windows.push({
          x: Number(card.querySelector(".win-x")?.value || 0),
          y: Number(card.querySelector(".win-y")?.value || 0),
          width: Number(card.querySelector(".win-w")?.value || 800),
          height: Number(card.querySelector(".win-h")?.value || 200),
          fit: card.querySelector(".win-fit")?.value || "contain",
        });
      });

      return {
        mode: (mode === "static" ? "static" : "marquee"),
        direction: (direction === "right" ? "right" : "left"),
        speed,
        pause_on_hover,
        item_gap,
        stage_bg,
        weather_location,
        num_windows,
        windows: ensureWindowsLength({ num_windows, windows }),
      };
    }

    // ---------- Categories UI ----------
    function openCategoryManager() {
      const listHtml = () => {
        if (!categories.length) return `<div style="color:#666">No categories yet.</div>`;
        return `
          <div style="display:flex;flex-direction:column;gap:8px;margin-top:10px">
            ${categories.map(c => `
              <div style="display:flex;align-items:center;justify-content:space-between;border:1px solid #e7e7ea;border-radius:12px;padding:10px;background:#fbfbfc">
                <div style="min-width:0">
                  <div style="font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(c.name)}</div>
                  <div style="font-size:12px;color:#6b7280">Count: ${esc(c.count ?? 0)} • ID: ${esc(c.id)}</div>
                </div>
                <button class="wmp2-btn danger wmp2-del-cat" data-id="${esc(c.id)}" type="button">Delete</button>
              </div>
            `).join("")}
          </div>
        `;
      };

      const m = modal(`
        <h3>Media / Slide Categories</h3>
        <div class="wmp2-row">
          <label style="width:110px">New category</label>
          <input id="wmp2-new-cat" type="text" placeholder="business1">
          <button class="wmp2-btn" id="wmp2-create-cat" type="button">Create</button>
        </div>

        <div class="wmp2-row" style="justify-content:space-between">
          <div style="font-size:12px;color:#6b7280">Categories can apply to media and slides.</div>
          <button class="wmp2-btn secondary" id="wmp2-assign-media" type="button">Tag media…</button>
        </div>

        <div id="wmp2-cat-list">${listHtml()}</div>

        <div class="wmp2-modal-actions">
          <button class="wmp2-btn secondary" id="wmp2-close" type="button">Close</button>
        </div>
      `);

      const bindDeletes = () => {
        m.el.querySelectorAll(".wmp2-del-cat").forEach(btn => {
          btn.addEventListener("click", async () => {
            const id = Number(btn.getAttribute("data-id"));
            if (!confirm("Delete this category?")) return;
            try {
              await api(`categories/${id}`, { method: "DELETE" });
              toast("Category deleted");
              await refresh();
            } catch (e) { alert(e.message); }
          });
        });
      };

      const refresh = async () => {
        await loadCategories();
        m.qs("#wmp2-cat-list").innerHTML = listHtml();
        bindDeletes();
      };

      m.qs("#wmp2-close").addEventListener("click", m.close);

      m.qs("#wmp2-create-cat").addEventListener("click", async () => {
        const name = m.qs("#wmp2-new-cat").value.trim();
        if (!name) return;
        try {
          await api("categories", { method: "POST", body: { name } });
          m.qs("#wmp2-new-cat").value = "";
          toast("Category created");
          await refresh();
        } catch (e) { alert(e.message); }
      });

      m.qs("#wmp2-assign-media").addEventListener("click", () => openAssignMediaCategories());

      refresh().catch(() => {});
    }

    function openAssignMediaCategories() {
      if (!window.wp || !wp.media) {
        alert("wp.media is not available. Make sure wp_enqueue_media() is called.");
        return;
      }
      if (!categories.length) {
        alert("Create at least one category first.");
        return;
      }

      const frame = wp.media({
        title: "Select media to tag",
        button: { text: "Next" },
        multiple: true,
        library: { type: ["image", "video"] },
      });

      frame.on("select", async () => {
        const selection = frame.state().get("selection").toJSON();
        const ids = selection.map(a => Number(a.id)).filter(n => n > 0);
        if (!ids.length) return;

        const m = modal(`
          <h3>Tag Selected Media</h3>
          <div style="font-size:12px;color:#6b7280;margin-bottom:10px">
            Selected media: <b>${esc(ids.length)}</b>
          </div>

          <div style="display:flex;flex-direction:column;gap:8px;margin:10px 0">
            ${categories.map(c => `
              <label style="display:flex;gap:10px;align-items:center;border:1px solid #e7e7ea;border-radius:12px;padding:10px;background:#fbfbfc">
                <input type="checkbox" class="wmp2-cat-pick" value="${esc(c.id)}">
                <div>
                  <div style="font-weight:800">${esc(c.name)}</div>
                  <div style="font-size:12px;color:#6b7280">ID: ${esc(c.id)}</div>
                </div>
              </label>
            `).join("")}
          </div>

          <div class="wmp2-modal-actions">
            <button class="wmp2-btn secondary" id="wmp2-cancel" type="button">Cancel</button>
            <button class="wmp2-btn" id="wmp2-apply" type="button">Apply</button>
          </div>
        `);

        m.qs("#wmp2-cancel").addEventListener("click", m.close);

        m.qs("#wmp2-apply").addEventListener("click", async () => {
          const term_ids = Array.from(m.el.querySelectorAll(".wmp2-cat-pick:checked"))
            .map(cb => Number(cb.value))
            .filter(n => n > 0);

          if (!term_ids.length) {
            alert("Pick at least one category.");
            return;
          }

          try {
            for (const id of ids) {
              await api(`media/${id}/categories`, { method: "POST", body: { term_ids } });
            }
            toast("Media tagged");
            await loadCategories();
            m.close();
          } catch (e) { alert(e.message); }
        });
      });

      frame.open();
    }

    function openAddCategoryRule() {
      if (!selected) return;
      if (!categories.length) {
        alert("No categories yet. Create one first.");
        return;
      }

      const m = modal(`
        <h3>Add Category Rule</h3>
        <div class="wmp2-row">
          <label style="width:110px">Category</label>
          <select id="wmp2-rule-cat">
            ${categories.map(c => `<option value="${esc(c.id)}">${esc(c.name)}</option>`).join("")}
          </select>
        </div>
        <div class="wmp2-row">
          <label style="width:110px">Count</label>
          <input id="wmp2-rule-count" type="number" min="1" max="50" value="2">
        </div>
        <div class="wmp2-row">
          <label style="width:110px">Duration (sec)</label>
          <input id="wmp2-rule-dur" type="number" min="0" max="86400" value="0">
        </div>
        <div class="wmp2-help">
          Adds a <b>category</b> item. The player expands it at playback time and rotates continuously.
        </div>
        <div class="wmp2-modal-actions">
          <button class="wmp2-btn secondary" id="wmp2-cancel" type="button">Cancel</button>
          <button class="wmp2-btn" id="wmp2-add" type="button">Add Rule</button>
        </div>
      `);

      m.qs("#wmp2-cancel").addEventListener("click", m.close);

      m.qs("#wmp2-add").addEventListener("click", async () => {
        const term_id = Number(m.qs("#wmp2-rule-cat").value);
        const count = clamp(m.qs("#wmp2-rule-count").value, 1, 50);
        const duration_sec = clamp(m.qs("#wmp2-rule-dur").value, 0, 86400);

        try {
          items = await api(`playlists/${selected.id}/items`, {
            method: "POST",
            body: {
              item_type: "category",
              ref_id: term_id,
              start_at: nowLocalInput().replace("T", " ") + ":00",
              end_at: null,
              duration_sec,
              windows: [],
              meta: { count },
            },
          });

          hydrateItems(items);
          await hydrateAttachmentUrls(items);
          toast("Category rule added");
          m.close();
          renderDetail();
        } catch (e) { alert(e.message); }
      });
    }

    let currentSlideCtx = null; // { playlist_id, item_id, slide_id }

    function closeSlideEditor() {
      if (!slideModalEl) return;
      slideModalEl.classList.remove("is-open");
      currentSlideCtx = null;
    }

    function readSlideStyleFromUI() {
      const fontFamily = document.querySelector("#wmp2-font")?.value || "";
      const fontSize = parseInt(document.querySelector("#wmp2-fontsize")?.value || "28", 10);
      const color = document.querySelector("#wmp2-color")?.value || "";
      const bg = document.querySelector("#wmp2-bg")?.value || "";
      const marqueeEnabled = !!document.querySelector("#wmp2-marquee-enabled")?.checked;
      const marqueeDir = document.querySelector("#wmp2-marquee-dir")?.value || "left";
      const marqueeSpeed = parseInt(document.querySelector("#wmp2-marquee-speed")?.value || "80", 10);

      const align = document.querySelector("#wmp2-slide-preview")?.dataset?.align || "";

      return {
        bg,
        color,
        fontFamily,
        fontSize: isFinite(fontSize) ? `${fontSize}px` : "",
        align,
        marquee: { enabled: marqueeEnabled, direction: marqueeDir, speed: marqueeSpeed }
      };
    }

    function applyWrapperStyle(el, style) {
      const s = el.style;
      s.background = style.bg || "#000";
      s.color = style.color || "#fff";
      s.fontFamily = style.fontFamily || "";
      s.fontSize = style.fontSize || "";
      s.textAlign = style.align || "";
      s.fontWeight = "";
    }

    // ------------------------------
    // FIX: Preview "canvas" must be fixed to playlist screen size
    // We use Window 1 dimensions as the playlist "screen size", since options store windows.
    // ------------------------------
    function getPlaylistCanvasSize() {
      const o = selected?.options || {};
      const wins = Array.isArray(o.windows) ? o.windows : [];
      const w1 = wins[0] || {};
      const width = clamp(w1.width ?? 1920, 1, 99999);
      const height = clamp(w1.height ?? 1080, 1, 99999);
      return { width, height };
    }

    function scaleViewportToHost(hostEl, viewportEl, vw, vh) {
      if (!hostEl || !viewportEl) return;
      // Give it a tick so layout can compute dimensions correctly
      const hw = Math.max(1, hostEl.clientWidth || 1);
      const hh = Math.max(1, hostEl.clientHeight || 1);

      const scale = Math.min(hw / vw, hh / vh, 1);
      viewportEl.style.transformOrigin = "top left";
      viewportEl.style.transform = `scale(${scale})`;
    }

    function updateSlidePreview() {
      const edit = document.querySelector("#wmp2-slide-edit");
      const prev = document.querySelector("#wmp2-slide-preview");
      if (!edit || !prev) return;

      const style = readSlideStyleFromUI();
      const html = edit.innerHTML || "";

      const { width: vw, height: vh } = getPlaylistCanvasSize();

      // Build a fixed-size "canvas" viewport, scaled to fit the panel.
      prev.innerHTML = "";

      // Stage (host area)
      prev.style.cssText = [
        "width:100%",
        "height:100%",
        "min-height:240px",
        "display:grid",
        "place-items:center",
        "overflow:auto",
        "background:rgba(0,0,0,.04)",
        "border-radius:12px",
        "padding:8px",
      ].join(";");

      // Viewport (fixed logical resolution)
      const viewport = document.createElement("div");
      viewport.className = "wmp2-slide-viewport";
      viewport.style.cssText = [
        `width:${vw}px`,
        `height:${vh}px`,
        "position:relative",
        "overflow:hidden", // critical: prevents content from resizing the canvas
        "border-radius:14px",
        "box-shadow:0 12px 30px rgba(0,0,0,.25)",
        "background:#000",
      ].join(";");

      // Wrap is the actual slide surface
      const wrap = document.createElement("div");
      wrap.className = "wmp2-slide";
      wrap.style.cssText = [
        "position:absolute",
        "inset:0",
        "box-sizing:border-box",
        "padding:12px",
        "overflow:hidden", // critical
        "border-radius:0",
      ].join(";");

      applyWrapperStyle(wrap, style);

      if (style.marquee?.enabled) {
        const track = document.createElement("div");
        track.className = "wmp2-slide-marquee-track";
        track.innerHTML = html;

        // Basic marquee behavior; your CSS animations should already exist
        const sp = Math.max(5, Math.min(2000, parseInt(style.marquee.speed || 80, 10)));
        const dur = Math.max(3, Math.min(60, Math.floor(12000 / sp)));
        const anim = (style.marquee.direction === "right") ? "wmp2_slide_marquee_right" : "wmp2_slide_marquee_left";
        track.style.animation = `${anim} ${dur}s linear infinite`;

        // Helps avoid weird line breaks in marquee mode
        track.style.whiteSpace = "nowrap";
        track.style.willChange = "transform";

        wrap.appendChild(track);
      } else {
        wrap.innerHTML = html;
      }

      viewport.appendChild(wrap);
      prev.appendChild(viewport);

      // Scale the fixed viewport to fit inside the preview panel
      scaleViewportToHost(prev, viewport, vw, vh);
    }

    // Re-scale on window resize (only matters when modal is open)
    window.addEventListener("resize", () => {
      if (!slideModalEl || !slideModalEl.classList.contains("is-open")) return;
      const prev = document.querySelector("#wmp2-slide-preview");
      const viewport = document.querySelector("#wmp2-slide-preview .wmp2-slide-viewport");
      if (!prev || !viewport) return;
      const { width: vw, height: vh } = getPlaylistCanvasSize();
      scaleViewportToHost(prev, viewport, vw, vh);
    });

    // FIXED: openSlideEditor(ctx) used undefined vars before
    async function openSlideEditor(ctx) {
      ensureSlideModal();

      const playlist_id = Number(ctx?.playlist_id || selected?.id || 0);
      const item_id = Number(ctx?.item_id || 0);
      const slide_id = Number(ctx?.slide_id || 0);

      let slide_html = String(ctx?.slide_html || "");
      let slide_style = (ctx?.slide_style && typeof ctx.slide_style === "object") ? ctx.slide_style : {};

      // If missing html/style, try to load from slide endpoint
      if ((!slide_html || slide_html.trim() === "") && slide_id) {
        try {
          const s = await api(`slides/${slide_id}`);
          if (s && typeof s === "object") {
            slide_html = String(s.content_html || slide_html || "");
            slide_style = (s.style && typeof s.style === "object") ? s.style : slide_style;
          }
        } catch (e) { /* ignore */ }
      }

      currentSlideCtx = { playlist_id, item_id, slide_id };

      const edit = document.querySelector("#wmp2-slide-edit");
      const prev = document.querySelector("#wmp2-slide-preview");
      if (!edit || !prev) { alert("Slide editor UI missing."); return; }

      edit.innerHTML = slide_html || "<div style='text-align:center;font-weight:800'>New Slide</div>";

      const st = slide_style || {};
      document.querySelector("#wmp2-font").value = st.fontFamily || "";
      document.querySelector("#wmp2-fontsize").value = parseInt(String(st.fontSize || "28").replace("px", ""), 10) || 28;
      document.querySelector("#wmp2-color").value = st.color || "#ffffff";
      document.querySelector("#wmp2-bg").value = st.bg || "#000000";
      document.querySelector("#wmp2-marquee-enabled").checked = !!(st.marquee && st.marquee.enabled);
      document.querySelector("#wmp2-marquee-dir").value = (st.marquee && st.marquee.direction) ? st.marquee.direction : "left";
      document.querySelector("#wmp2-marquee-speed").value = (st.marquee && st.marquee.speed) ? st.marquee.speed : 80;

      prev.dataset.align = st.align || "";

      document.querySelector("#wmp2-slide-cancel").onclick = closeSlideEditor;

      document.querySelector("#wmp2-bold").onclick = () => wrapSelectionWithSpan({ fontWeight: "800" });
      document.querySelector("#wmp2-italic").onclick = () => wrapSelectionWithSpan({ fontStyle: "italic" });
      document.querySelector("#wmp2-underline").onclick = () => wrapSelectionWithSpan({ textDecoration: "underline" });

      document.querySelectorAll('#wmp2-slide-toolbar .wmp2-toolbtn[data-align]').forEach(btn => {
        btn.onclick = () => {
          prev.dataset.align = btn.dataset.align || "";
          updateSlidePreview();
        };
      });

      ["#wmp2-font", "#wmp2-fontsize", "#wmp2-color", "#wmp2-bg", "#wmp2-marquee-enabled", "#wmp2-marquee-dir", "#wmp2-marquee-speed"]
        .forEach(sel => {
          const el = document.querySelector(sel);
          if (el) el.oninput = updateSlidePreview;
        });

      edit.oninput = updateSlidePreview;

      document.querySelector("#wmp2-slide-save").onclick = async () => {
        try {
          const new_html = edit.innerHTML || "";
          const new_style = readSlideStyleFromUI();

          // 1) Save Slide CPT
          if (slide_id) {
            await api(`slides/${slide_id}`, {
              method: "PATCH",
              body: { content_html: new_html, style: new_style }
            });
          }

          // 2) Save playlist item using YOUR real route:
          // PATCH /wmp/v2/items/{item_id}
          if (item_id) {
            const mergedMeta = { ...(ctx?.meta || {}), slide_html: new_html, slide_style: new_style };
            await api(`items/${item_id}`, {
              method: "PATCH",
              body: {
                slide_html: new_html,
                slide_style: new_style,
                meta: mergedMeta
              }
            });
          }

          if (typeof selectPlaylist === "function" && selected?.id) {
            await selectPlaylist(selected.id);
          }

          closeSlideEditor();
          toast("Slide saved");
        } catch (e) {
          alert(e.message || String(e));
        }
      };

      slideModalEl.classList.add("is-open");
      updateSlidePreview();
    }

    async function openSlideCreator() {
      try {
        if (!selected?.id) { alert("Select a playlist first."); return; }

        const created = await api("slides", {
          method: "POST",
          body: {
            title: "New Slide",
            content_html: `<div style="text-align:center;font-weight:800">New Slide</div>`,
            style: { bg: "#000000", color: "#ffffff", fontSize: "32px", align: "center" }
          }
        });

        items = await api(`playlists/${selected.id}/items`, {
          method: "POST",
          body: {
            item_type: "slide",
            ref_id: created.id,
            duration_sec: 10,
            start_at: null,
            end_at: null,
            windows: [],
            meta: { title: "New Slide" },
            slide_html: created.content_html || "",
            slide_style: created.style || {}
          }
        });

        hydrateItems(items);
        await hydrateAttachmentUrls(items);
        renderDetail();
        toast("Slide added");

        const newItem = items.find(x => x.item_type === "slide" && Number(x.ref_id) === Number(created.id)) || items[items.length - 1];
        if (newItem) {
          openSlideEditor({
            playlist_id: selected.id,
            item_id: newItem.id,
            slide_id: newItem.ref_id,
            slide_html: newItem.slide_html || created.content_html || "",
            slide_style: newItem.slide_style || created.style || {},
            meta: newItem.meta || { title: "New Slide" }
          });
        }
      } catch (e) {
        alert(e.message || String(e));
      }
    }

    // ---------- Add media ----------
    function openMediaPicker() {
      if (!window.wp || !wp.media) {
        alert("wp.media is not available. Make sure wp_enqueue_media() is called.");
        return;
      }

      const frame = wp.media({
        title: "Select media",
        button: { text: "Add to playlist" },
        multiple: true,
        library: { type: ["image", "video"] },
      });

      frame.on("select", async () => {
        const selection = frame.state().get("selection").toJSON();
        try {
          for (const att of selection) {
            const ref_id = Number(att.id);
            await api(`playlists/${selected.id}/items`, {
              method: "POST",
              body: {
                item_type: "media",
                ref_id,
                start_at: nowLocalInput().replace("T", " ") + ":00",
                end_at: null,
                duration_sec: 0,
                windows: [],
                meta: {},
              },
            });
          }
          items = await api(`playlists/${selected.id}/items`);
          hydrateItems(items);
          await hydrateAttachmentUrls(items);
          renderDetail();
          toast("Media added");
        } catch (e) { alert(e.message); }
      });

      frame.open();
    }

    // ---------- Items render ----------
    function renderItems() {
      const opts = selected?.options || {};
      const numW = Math.max(1, Math.min(12, Number(opts.num_windows || 1)));

      if (!items.length) return `<div style="color:#666">No items yet. Add media, a slide, or a category rule.</div>`;

      return `<div class="wmp2-items">
        ${items.map((it, idx) => {
          const type = it.item_type;
          const isMedia = type === "media";
          const isSlide = type === "slide";
          const isCat = type === "category";

          let thumb = "", title = "", meta = "";

          if (isMedia) {
            const url = it.meta?.url || "";
            const kind = it.meta?.kind || "image";
            title = filenameFromUrl(url) || (it.ref_id ? `Attachment #${it.ref_id}` : "(media)");
            meta = `Media (${kind})`;
            thumb = url
              ? (kind === "video"
                ? `<video src="${esc(url)}" muted playsinline></video>`
                : `<img src="${esc(url)}" alt="">`)
              : `<div style="font-weight:800;color:#666;font-size:12px">MEDIA</div>`;
          } else if (isSlide) {
            title = it.meta?.title || `Slide #${it.ref_id || it.id}`;
            meta = `Slide`;
            thumb = `<div style="font-weight:900;color:#374151;font-size:12px">SLIDE</div>`;
          } else if (isCat) {
            const termId = Number(it.ref_id || 0);
            const cat = catMap.get(termId);
            const count = Number(it.meta?.count || 1);
            title = cat ? `Category: ${cat.name}` : `Category #${termId}`;
            meta = `Category Rule (x${count})`;
            thumb = `<div style="font-weight:900;color:#374151;font-size:12px">CAT</div>`;
          } else {
            title = `Item ${it.id}`;
            meta = type;
            thumb = `<div style="font-weight:800;color:#666;font-size:12px">${esc(String(type || "").toUpperCase())}</div>`;
          }

          const startVal = it.start_at ? String(it.start_at).replace(" ", "T").slice(0, 16) : "";
          const endVal = it.end_at ? String(it.end_at).replace(" ", "T").slice(0, 16) : "";

          const extraField = isCat ? `
            <div class="wmp2-field">
              <label>Category count</label>
              <input type="number" class="wmp2-cat-count" min="1" max="50" value="${esc(Number(it.meta?.count || 1))}">
            </div>
          ` : ``;

          return `
            <div class="wmp2-item" data-id="${esc(it.id)}">
              <div class="wmp2-thumb">${thumb}</div>

              <div>
                <div class="wmp2-itemhead">
                  <div style="min-width:0">
                    <div class="wmp2-filename" title="${esc(title)}">${esc(title)}</div>
                    <div class="wmp2-meta">${esc(meta)}</div>
                  </div>
                  <div class="wmp2-pill">#${idx + 1}</div>
                </div>

                <div class="wmp2-fields">
                  <div class="wmp2-field">
                    <label>Start date/time</label>
                    <input type="datetime-local" class="wmp2-start" value="${esc(startVal)}">
                  </div>
                  <div class="wmp2-field">
                    <label>End date/time</label>
                    <input type="datetime-local" class="wmp2-end" value="${esc(endVal)}">
                  </div>
                  <div class="wmp2-field">
                    <label>Duration (sec)</label>
                    <input type="number" class="wmp2-duration" min="0" max="86400" value="${esc(it.duration_sec || 0)}">
                  </div>
                  <div class="wmp2-field">
                    <label>Type</label>
                    <input type="text" value="${esc(type)}" readonly>
                  </div>
                  ${extraField}
                </div>

                <div style="margin-top:10px">
                  <div style="font-size:11px;color:#6b7280;margin:0 0 6px 2px">Show in windows</div>
                  ${windowCheckboxes(numW, it.windows)}
                </div>
              </div>

              <div class="wmp2-controls">
                <button class="wmp2-btn secondary wmp2-up" type="button" ${idx === 0 ? "disabled" : ""}>Up</button>
                <button class="wmp2-btn secondary wmp2-down" type="button" ${idx === items.length - 1 ? "disabled" : ""}>Down</button>
                ${isSlide ? `<button class="wmp2-btn secondary wmp2-edit-slide" type="button">Edit Slide</button>` : ""}
                <button class="wmp2-btn danger wmp2-del" type="button">Delete</button>
              </div>
            </div>
          `;
        }).join("")}
      </div>`;
    }

    // ---------- Detail render ----------
    function renderDetail() {
      const el = $("#wmp2-detail");
      if (!el) return;

      if (!selected) { el.innerHTML = "Select a playlist…"; return; }

      const preview = simp2Url(selected.token);

      const showingOptions = detailView === "options";
      const showingItems = !showingOptions;

      el.innerHTML = `
        <div class="wmp2-row">
          <label style="width:110px">Name</label>
          <input id="wmp2-name" type="text" value="${esc(selected.name)}">
          <button id="wmp2-save" class="wmp2-btn" type="button">Save</button>
        </div>

        <div class="wmp2-row">
          <div><b>Preview</b>: <a target="_blank" href="${esc(preview)}">${esc(preview)}</a></div>
        </div>

        <div class="wmp2-tabs">
          <button class="wmp2-tab ${showingItems ? "is-active" : ""}" data-view="items" type="button">Items</button>
          <button class="wmp2-tab ${showingOptions ? "is-active" : ""}" data-view="options" type="button">Options</button>
        </div>

        <hr class="wmp2-hr">

        ${showingItems ? `
          <div class="wmp2-row">
            <button id="wmp2-add-media" class="wmp2-btn secondary" type="button">Add Media</button>
            <button id="wmp2-add-slide" class="wmp2-btn secondary" type="button">Add Slide</button>
            <button id="wmp2-add-cat-rule" class="wmp2-btn secondary" type="button">Add Category Rule</button>
            <button id="wmp2-manage-cats" class="wmp2-btn secondary" type="button">Manage Categories</button>
            <button id="wmp2-save-order" class="wmp2-btn secondary" type="button" ${items.length < 2 ? "disabled" : ""}>Save Order</button>
          </div>

          <div class="wmp2-subtitle">Items</div>
          <div id="wmp2-items-area">${renderItems()}</div>
        ` : `
          ${renderOptionsPanel()}
        `}
      `;

      document.querySelectorAll(".wmp2-tab").forEach(tab => {
        tab.addEventListener("click", () => {
          detailView = tab.getAttribute("data-view") || "items";
          renderDetail();
        });
      });

      // Preview button WAS NEVER WIRED in your file — wire it now
      $("#wmp2-preview")?.addEventListener("click", () => window.open(preview, "_blank"));

      // Save playlist name
      $("#wmp2-save").addEventListener("click", async () => {
        const name = $("#wmp2-name").value.trim() || "Untitled Playlist";
        try {
          const updated = await api(`playlists/${selected.id}`, { method: "PATCH", body: { name } });
          selected.name = updated.name;
          playlists = playlists.map(p => p.id === selected.id ? { ...p, name: updated.name } : p);
          renderList();
          renderDetail();
        } catch (e) { alert(e.message); }
      });

      // Save options
      $("#wmp2-save-options")?.addEventListener("click", async () => {
        try {
          const options = readOptionsFromUI();
          const updated = await api(`playlists/${selected.id}`, { method: "PATCH", body: { options } });
          selected.options = updated.options || options;
          toast("Options saved");
          renderDetail();
        } catch (e) { alert(e.message); }
      });

      // Changing num_windows re-renders immediately
      $("#opt-numw")?.addEventListener("change", () => {
        try {
          const options = readOptionsFromUI();
          selected.options = { ...(selected.options || {}), ...options };
          renderDetail();
        } catch (e) {}
      });

      // Buttons
      $("#wmp2-add-media")?.addEventListener("click", () => openMediaPicker());
      $("#wmp2-add-slide")?.addEventListener("click", () => openSlideCreator());
      $("#wmp2-manage-cats")?.addEventListener("click", () => openCategoryManager());
      $("#wmp2-add-cat-rule")?.addEventListener("click", () => openAddCategoryRule());

      // Save order
      $("#wmp2-save-order")?.addEventListener("click", async () => {
        try {
          const order = items.map(x => x.id);
          items = await api(`playlists/${selected.id}/items/reorder`, { method: "POST", body: { order } });
          hydrateItems(items);
          await hydrateAttachmentUrls(items);
          renderDetail();
          toast("Order saved");
        } catch (e) { alert(e.message); }
      });

      const itemsArea = $("#wmp2-items-area");
      if (!itemsArea) return;

      // Click actions
      itemsArea.addEventListener("click", async (e) => {
        const row = e.target.closest(".wmp2-item");
        if (!row) return;
        const id = Number(row.getAttribute("data-id"));
        const idx = items.findIndex(x => Number(x.id) === id);
        if (idx < 0) return;

        if (e.target.classList.contains("wmp2-up")) {
          if (idx === 0) return;
          [items[idx - 1], items[idx]] = [items[idx], items[idx - 1]];
          renderDetail();
          return;
        }
        if (e.target.classList.contains("wmp2-down")) {
          if (idx === items.length - 1) return;
          [items[idx + 1], items[idx]] = [items[idx], items[idx + 1]];
          renderDetail();
          return;
        }
        if (e.target.classList.contains("wmp2-del")) {
          if (!confirm("Delete this item?")) return;
          try {
            items = await api(`items/${id}`, { method: "DELETE" });
            hydrateItems(items);
            await hydrateAttachmentUrls(items);
            renderDetail();
            toast("Item deleted");
          } catch (err) { alert(err.message); }
          return;
        }
        if (e.target.classList.contains("wmp2-edit-slide")) {
          openSlideEditor({
            playlist_id: selected.id,
            item_id: items[idx].id,
            slide_id: items[idx].ref_id,
            slide_html: items[idx].slide_html || "",
            slide_style: items[idx].slide_style || {},
            meta: items[idx].meta || {},
          });
          return;
        }
      });

      // Change autosaves
      itemsArea.addEventListener("change", async (e) => {
        const row = e.target.closest(".wmp2-item");
        if (!row) return;

        const id = Number(row.getAttribute("data-id"));
        const it = items.find(x => Number(x.id) === id);
        if (!it) return;

        // window checkbox logic
        if (e.target.classList.contains("wmp2-win-all")) {
          row.querySelectorAll(".wmp2-win-one").forEach(cb => cb.checked = false);
        }
        if (e.target.classList.contains("wmp2-win-one")) {
          if (e.target.checked) {
            const all = row.querySelector(".wmp2-win-all");
            if (all) all.checked = false;
          } else {
            const any = row.querySelectorAll(".wmp2-win-one:checked").length > 0;
            if (!any) {
              const all = row.querySelector(".wmp2-win-all");
              if (all) all.checked = true;
            }
          }
        }

        const start = row.querySelector(".wmp2-start")?.value || "";
        const end = row.querySelector(".wmp2-end")?.value || "";
        const duration = Number(row.querySelector(".wmp2-duration")?.value || 0);

        let windows = [];
        const allChecked = row.querySelector(".wmp2-win-all")?.checked;
        if (!allChecked) {
          row.querySelectorAll(".wmp2-win-one:checked").forEach(cb => windows.push(Number(cb.value)));
        } else {
          windows = [];
        }

        const start_at = start ? start.replace("T", " ") + ":00" : null;
        const end_at = end ? end.replace("T", " ") + ":00" : null;

        // category meta update
        let meta = null;
        if (it.item_type === "category") {
          const count = clamp(row.querySelector(".wmp2-cat-count")?.value ?? (it.meta?.count || 1), 1, 50);
          meta = { ...(it.meta || {}), count };
        }

        try {
          items = await api(`items/${id}`, {
            method: "PATCH",
            body: { start_at, end_at, duration_sec: duration, windows, ...(meta ? { meta } : {}) }
          });

          hydrateItems(items);
          await hydrateAttachmentUrls(items);
          renderDetail();
          toast("Saved");
        } catch (err) { alert(err.message); }
      });
    }

    // ---------- Data loading ----------
    async function loadPlaylists() {
      playlists = await api("playlists");
      renderList();
      renderDetail();
    }

    async function selectPlaylist(id) {
      await loadCategories();
      selected = await api(`playlists/${id}`);
      items = await api(`playlists/${id}/items`);
      hydrateItems(items);
      await hydrateAttachmentUrls(items);
      detailView = "items";
      renderDetail();
    }

    async function createPlaylist() {
      try {
        const created = await api("playlists", { method: "POST", body: { name: "New Playlist" } });
        playlists = [created, ...playlists];
        renderList();
        await selectPlaylist(created.id);
      } catch (e) { alert(e.message); }
    }

    document.addEventListener("DOMContentLoaded", () => {
      const btn = $("#wmp2-create");
      if (btn) btn.addEventListener("click", createPlaylist);
      loadPlaylists().catch(err => alert(err.message));
    });

  } catch (e) {
    console.error("WMP2 app fatal:", e);
    const root = document.querySelector("#wmp2-app");
    if (root) root.innerHTML = `<pre style="white-space:pre-wrap;color:#b32d2e;font-weight:800">${e.stack || e.message}</pre>`;
  }
})();
