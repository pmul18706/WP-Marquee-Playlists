// assets/front/player.js

(function () {
  function $(sel, root = document) { return root.querySelector(sel); }
  function $all(sel, root = document) { return Array.from(root.querySelectorAll(sel)); }

  function getPlayerOpts() {
    const player = $(".wmp2-player");
    return {
      mode: player?.getAttribute("data-mode") || "marquee",
      direction: player?.getAttribute("data-direction") || "left",
      speed: Number(player?.getAttribute("data-speed") || 60), // px/sec
      pauseHover: (player?.getAttribute("data-pausehover") || "0") === "1",
    };
  }

  // ------------------------------
  // FIX: Force items (especially slides) to respect window size
  // ------------------------------
  function looksLikeSlideItem(itemEl) {
    if (!itemEl) return false;

    // Try common signals without requiring backend changes:
    const t1 = (itemEl.getAttribute("data-type") || "").toLowerCase();
    const t2 = (itemEl.getAttribute("data-item-type") || "").toLowerCase();
    const t3 = (itemEl.getAttribute("data-kind") || "").toLowerCase();
    if (t1 === "slide" || t2 === "slide" || t3 === "slide") return true;

    // If the item contains slide-ish content, treat it as a slide.
    // (Your admin preview uses .wmp2-slide, so this is a safe bet.)
    if (itemEl.querySelector(".wmp2-slide")) return true;

    // Fallback: if it’s not media-ish (img/video) and it has significant HTML/text, likely a slide.
    const hasMedia = !!itemEl.querySelector("img,video");
    const hasText = (itemEl.textContent || "").trim().length > 0;
    if (!hasMedia && hasText) return true;

    return false;
  }

  function applyFixedSizingToWindow(win, mode) {
    if (!win) return;

    // Lock the window itself.
    win.style.overflow = "hidden";

    const w = Math.max(1, win.clientWidth || 1);
    const h = Math.max(1, win.clientHeight || 1);

    const items = $all(".wmp2-item", win);
    items.forEach((it) => {
      // Never allow content to change the window height.
      it.style.maxHeight = h + "px";
      it.style.height = (mode === "static") ? "100%" : (h + "px");
      it.style.overflow = "hidden";
      it.style.boxSizing = "border-box";

      // In static mode, everything should fill the window.
      if (mode === "static") {
        it.style.width = "100%";
        it.style.maxWidth = "100%";
        it.style.flex = "0 0 100%";
        it.style.alignItems = "stretch";
      }

      // In marquee mode, only slides should be forced to a full-screen "tile" width.
      // Media items can keep their natural width based on image/video sizing.
      if (mode !== "static" && looksLikeSlideItem(it)) {
        it.style.width = w + "px";
        it.style.minWidth = w + "px";
        it.style.maxWidth = w + "px";
        it.style.flex = "0 0 " + w + "px";

        // Ensure internal slide surface can't expand vertically
        const slideSurface = it.querySelector(".wmp2-slide") || it;
        slideSurface.style.height = "100%";
        slideSurface.style.maxHeight = "100%";
        slideSurface.style.overflow = "hidden";
        slideSurface.style.boxSizing = "border-box";
      }
    });
  }

  // ---------- STATIC MODE ----------
  function initStaticWindow(win) {
    const items = $all(".wmp2-item", win);
    if (!items.length) return;

    // Apply fixed sizing now
    applyFixedSizingToWindow(win, "static");

    let idx = 0;

    function show(i) {
      items.forEach((el, n) => { el.style.display = (n === i) ? "flex" : "none"; });

      const dur = Number(items[i].getAttribute("data-duration") || 10);
      const nextIn = Math.max(1, dur) * 1000;

      const vid = $("video", items[i]);
      if (vid) {
        try { vid.currentTime = 0; vid.play().catch(() => {}); } catch (e) {}
      }

      setTimeout(() => {
        idx = (idx + 1) % items.length;
        show(idx);
      }, nextIn);
    }

    items.forEach((el, n) => el.style.display = (n === 0) ? "flex" : "none");
    show(0);
  }

  // ---------- MARQUEE MODE ----------
  function waitForMedia(win, timeoutMs = 2500) {
    const media = $all("img,video", win);
    if (!media.length) return Promise.resolve();

    let done = false;
    return new Promise((resolve) => {
      const t = setTimeout(() => { if (!done) { done = true; resolve(); } }, timeoutMs);

      let remaining = media.length;
      const dec = () => {
        remaining--;
        if (remaining <= 0 && !done) {
          done = true;
          clearTimeout(t);
          resolve();
        }
      };

      media.forEach((m) => {
        if (m.tagName === "IMG") {
          if (m.complete) return dec();
          m.addEventListener("load", dec, { once: true });
          m.addEventListener("error", dec, { once: true });
        } else {
          if (m.readyState >= 1) return dec(); // HAVE_METADATA
          m.addEventListener("loadedmetadata", dec, { once: true });
          m.addEventListener("error", dec, { once: true });
        }
      });
    });
  }

  function buildStrip(seq, minWidth) {
    // seq currently has the base items (one set). We will repeat that set until it's wide enough,
    // then duplicate the whole strip once for seamless wrap.
    const original = $all(":scope > .wmp2-item", seq);
    if (!original.length) return { stripWidth: 0 };

    // Ensure everything is visible for marquee
    original.forEach(el => el.style.display = "flex");

    // Helper: append a clone of the original set
    const appendOriginalSet = () => {
      original.forEach(ch => seq.appendChild(ch.cloneNode(true)));
    };

    // Grow until at least minWidth
    let guard = 0;
    while (seq.scrollWidth < minWidth && guard < 50) {
      appendOriginalSet();
      guard++;
    }

    // Now measure exact strip width (this is the period)
    const stripWidth = seq.scrollWidth;

    // Duplicate the entire strip once
    const childrenNow = $all(":scope > .wmp2-item", seq);
    childrenNow.forEach(ch => seq.appendChild(ch.cloneNode(true)));

    return { stripWidth };
  }

  function initMarqueeWindow(win, opts) {
    const seq = $(".wmp2-seq", win);
    if (!seq) return;

    // If no items, nothing to do
    const base = $all(":scope > .wmp2-item", seq);
    if (!base.length) return;

    // Apply fixed sizing now (important before we measure widths)
    applyFixedSizingToWindow(win, "marquee");

    let paused = false;
    if (opts.pauseHover) {
      win.addEventListener("mouseenter", () => { paused = true; });
      win.addEventListener("mouseleave", () => { paused = false; });
    }

    const direction = (opts.direction === "right") ? 1 : -1;
    const speed = Math.max(5, Math.min(2000, Number(opts.speed || 60)));

    let stripWidth = 0;
    let offset = 0;
    let lastT = null;

    function resetToBaseItems() {
      // Restore to original base nodes only (remove clones)
      seq.innerHTML = "";
      base.forEach(it => seq.appendChild(it));
      // Re-apply sizing after DOM replacement
      applyFixedSizingToWindow(win, "marquee");
    }

    function prepare() {
      // Build strip to at least window width + buffer so you never see blank
      const minWidth = win.clientWidth + 50;
      const built = buildStrip(seq, minWidth);
      stripWidth = built.stripWidth;

      // Safety: if still tiny, just don't animate
      if (!stripWidth || stripWidth < 2) return;

      offset = 0;
      seq.style.transform = "translate3d(0,0,0)";
    }

    function tick(t) {
      if (!stripWidth) {
        requestAnimationFrame(tick);
        return;
      }

      if (!lastT) lastT = t;
      const dt = (t - lastT) / 1000;
      lastT = t;

      if (!paused) {
        offset += direction * speed * dt;

        // Use modulo wrap to avoid drift/jump
        let m = offset % stripWidth;

        if (direction < 0) {
          if (m > 0) m -= stripWidth;
          seq.style.transform = `translate3d(${m}px,0,0)`;
        } else {
          if (m < 0) m += stripWidth;
          seq.style.transform = `translate3d(${m}px,0,0)`;
        }
      }

      requestAnimationFrame(tick);
    }

    // Wait for images/videos to size, then build accurate strip and animate
    waitForMedia(win).then(() => {
      resetToBaseItems();
      prepare();
      requestAnimationFrame(tick);
    });

    // Rebuild on resize (debounced)
    let rT = null;
    window.addEventListener("resize", () => {
      clearTimeout(rT);
      rT = setTimeout(() => {
        // First reapply fixed sizing to new window dimensions
        applyFixedSizingToWindow(win, "marquee");

        waitForMedia(win).then(() => {
          resetToBaseItems();
          prepare();
        });
      }, 150);
    });
  }

  document.addEventListener("DOMContentLoaded", () => {
    const opts = getPlayerOpts();
    const windows = $all(".wmp2-window");

    // Apply sizing immediately on load (prevents first-frame “jump”)
    windows.forEach(win => applyFixedSizingToWindow(win, opts.mode));

    if (opts.mode === "static") {
      windows.forEach(initStaticWindow);
      return;
    }

    windows.forEach(win => initMarqueeWindow(win, opts));
  });
})();
