// @ds-adherence-ignore -- omelette starter scaffold (raw elements/hex/px by design)
/* BEGIN USAGE */
/**
 * <image-slot> ??user-fillable image placeholder.
 *
 * Drop this into a deck, mockup, or page wherever you want the user to
 * supply an image. You control the slot's shape and size; the user fills it
 * by dragging an image file onto it (or clicking to browse). The dropped
 * image persists across reloads via a .image-slots.state.json sidecar ?? * same read-via-fetch / write-via-window.omelette pattern as
 * design_canvas.jsx, so the filled slot shows on share links, downloaded
 * zips, and PPTX export. Outside the omelette runtime the slot is read-only.
 *
 * The host bridge only allows sidecar writes at the project root, so the
 * HTML that uses this component is assumed to live at the project root too
 * (same constraint as design_canvas.jsx).
 *
 * Attributes:
 *   id           Persistence key. REQUIRED for the drop to survive reload ?? *                every slot on the page needs a distinct id.
 *   shape        'rect' | 'rounded' | 'circle' | 'pill'   (default 'rounded')
 *                'circle' applies 50% border-radius; on a non-square slot
 *                that's an ellipse ??set equal width and height for a true
 *                circle.
 *   radius       Corner radius in px for 'rounded'.       (default 12)
 *   mask         Any CSS clip-path value. Overrides `shape` ??use this for
 *                hexagons, blobs, arbitrary polygons.
 *   fit          object-fit: cover | contain | fill.       (default 'cover')
 *                With cover (the default) double-clicking the filled slot
 *                enters a reframe mode: the whole image spills past the mask
 *                (translucent outside, opaque inside), drag to reposition,
 *                corner-drag to scale. The crop persists alongside the image
 *                in the sidecar. contain/fill stay static.
 *   position     object-position for fit=contain|fill.     (default '50% 50%')
 *   placeholder  Empty-state caption.                      (default 'Drop an image')
 *   src          Optional initial/fallback image URL. A user drop overrides
 *                it; clearing the drop reveals src again.
 *
 * Size and layout come from ordinary CSS on the element ??width/height
 * inline or from a parent grid ??so it composes with any layout.
 *
 * Usage:
 *   <image-slot id="hero"   style="width:800px;height:450px" shape="rounded" radius="20"
 *               placeholder="Drop a hero image"></image-slot>
 *   <image-slot id="avatar" style="width:120px;height:120px" shape="circle"></image-slot>
 *   <image-slot id="kite"   style="width:300px;height:300px"
 *               mask="polygon(50% 0, 100% 50%, 50% 100%, 0 50%)"></image-slot>
 */
/* END USAGE */

(() => {
  const STATE_FILE = '.image-slots.state.json';
  // 2? a ~600px slot in a 1920-wide deck ??retina-sharp without making the
  // sidecar enormous. A 1200px WebP at q=0.85 is ~150-300KB.
  const MAX_DIM = 1200;
  // Raster formats only. SVG is excluded (can carry script; createImageBitmap
  // on SVG blobs is inconsistent). GIF is excluded because the canvas
  // re-encode keeps only the first frame, so an animated GIF would silently
  // go still ??better to reject than surprise.
  const ACCEPT = ['image/png', 'image/jpeg', 'image/webp', 'image/avif'];

  // ?€?€ Shared sidecar store ?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€
  // One fetch + immediate write-on-change for every <image-slot> on the
  // page. Reads via fetch() so viewing works anywhere the HTML and sidecar
  // are served together; writes go through window.omelette.writeFile, which
  // the host allowlists to *.state.json basenames only.
  const subs = new Set();
  let slots = {};
  // ids explicitly cleared before the sidecar fetch resolved ??otherwise
  // the merge below can't tell "never set" from "just deleted" and would
  // resurrect the sidecar's stale value.
  const tombstones = new Set();
  let loaded = false;
  let loadP = null;

  function load() {
    if (loadP) return loadP;
    loadP = fetch(STATE_FILE)
      .then((r) => (r.ok ? r.json() : null))
      .then((j) => {
        // Merge: sidecar loses to any in-memory change that raced ahead of
        // the fetch (drop or clear) so neither is clobbered by hydration.
        if (j && typeof j === 'object') {
          const merged = Object.assign({}, j, slots);
          // A framing-only write that raced ahead of hydration must not
          // drop a user image that's only on disk ??inherit u from the
          // sidecar for any in-memory entry that lacks one.
          for (const k in slots) {
            if (merged[k] && !merged[k].u && j[k]) {
              merged[k].u = typeof j[k] === 'string' ? j[k] : j[k].u;
            }
          }
          for (const id of tombstones) delete merged[id];
          slots = merged;
        }
        tombstones.clear();
      })
      .catch(() => {})
      .then(() => { loaded = true; subs.forEach((fn) => fn()); });
    return loadP;
  }

  // Serialize writes so two near-simultaneous drops on different slots
  // can't reorder at the backend and leave the sidecar with only the
  // first. A save requested mid-flight just marks dirty and re-fires on
  // completion with the then-current slots.
  let saving = false;
  let saveDirty = false;
  function save() {
    if (saving) { saveDirty = true; return; }
    const w = window.omelette && window.omelette.writeFile;
    if (!w) return;
    saving = true;
    Promise.resolve(w(STATE_FILE, JSON.stringify(slots)))
      .catch(() => {})
      .then(() => { saving = false; if (saveDirty) { saveDirty = false; save(); } });
  }

  const S_MAX = 5;
  const clampS = (s) => Math.max(1, Math.min(S_MAX, s));

  // Normalize a stored slot value. Pre-reframe sidecars stored a bare
  // data-URL string; newer ones store {u, s, x, y}. Either shape is valid.
  function getSlot(id) {
    const v = slots[id];
    if (!v) return null;
    return typeof v === 'string' ? { u: v, s: 1, x: 0, y: 0 } : v;
  }

  function setSlot(id, val) {
    if (!id) return;
    if (val) { slots[id] = val; tombstones.delete(id); }
    else { delete slots[id]; if (!loaded) tombstones.add(id); }
    subs.forEach((fn) => fn());
    // A drop is rare + high-value ??write immediately so nav-away can't lose
    // it. Gate on the initial read so we don't overwrite a sidecar we haven't
    // merged yet; the merge in load() keeps this change once the read lands.
    if (loaded) save(); else load().then(save);
  }

  // ?€?€ Image downscale ?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€
  // Encode through a canvas so the sidecar carries resized bytes, not the
  // raw upload. Longest side is capped at 2? the slot's rendered width
  // (retina) and at MAX_DIM. WebP keeps alpha and is ~10? smaller than PNG
  // for photos, so there's no need for per-image format picking.
  async function toDataUrl(file, targetW) {
    const bitmap = await createImageBitmap(file);
    try {
      const cap = Math.min(MAX_DIM, Math.max(1, Math.round(targetW * 2)) || MAX_DIM);
      const scale = Math.min(1, cap / Math.max(bitmap.width, bitmap.height));
      const w = Math.max(1, Math.round(bitmap.width * scale));
      const h = Math.max(1, Math.round(bitmap.height * scale));
      const canvas = document.createElement('canvas');
      canvas.width = w; canvas.height = h;
      canvas.getContext('2d').drawImage(bitmap, 0, 0, w, h);
      return canvas.toDataURL('image/webp', 0.85);
    } finally {
      bitmap.close && bitmap.close();
    }
  }

  // ?€?€ Custom element ?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€?€
  const stylesheet =
    ':host{display:inline-block;position:relative;vertical-align:top;' +
    '  font:13px/1.3 system-ui,-apple-system,sans-serif;color:rgba(0,0,0,.55);width:240px;height:160px}' +
    '.frame{position:absolute;inset:0;overflow:hidden;background:rgba(0,0,0,.04)}' +
    // .frame img (clipped) and .spill (unclipped ghost + handles) share the
    // same left/top/width/height in frame-%, computed by _applyView(), so the
    // inside-mask crop and the outside-mask spill stay pixel-aligned.
    '.frame img{position:absolute;max-width:none;transform:translate(-50%,-50%);' +
    '  -webkit-user-drag:none;user-select:none;touch-action:none}' +
    // Reframe mode (double-click): the full image spills past the mask. The
    // spill layer is sized to the IMAGE bounds so its corners are where the
    // resize handles belong. The ghost <img> inside is translucent; the real
    // clipped <img> underneath shows the opaque in-mask crop.
    '.spill{position:absolute;transform:translate(-50%,-50%);display:none;z-index:1;' +
    '  cursor:grab;touch-action:none}' +
    ':host([data-panning]) .spill{cursor:grabbing}' +
    '.spill .ghost{position:absolute;inset:0;width:100%;height:100%;opacity:.35;' +
    '  pointer-events:none;-webkit-user-drag:none;user-select:none;' +
    '  box-shadow:0 0 0 1px rgba(0,0,0,.2),0 12px 32px rgba(0,0,0,.2)}' +
    '.spill .handle{position:absolute;width:12px;height:12px;border-radius:50%;' +
    '  background:#fff;box-shadow:0 0 0 1.5px #c96442,0 1px 3px rgba(0,0,0,.3);' +
    '  transform:translate(-50%,-50%)}' +
    '.spill .handle[data-c=nw]{left:0;top:0;cursor:nwse-resize}' +
    '.spill .handle[data-c=ne]{left:100%;top:0;cursor:nesw-resize}' +
    '.spill .handle[data-c=sw]{left:0;top:100%;cursor:nesw-resize}' +
    '.spill .handle[data-c=se]{left:100%;top:100%;cursor:nwse-resize}' +
    ':host([data-reframe]){z-index:10}' +
    ':host([data-reframe]) .spill{display:block}' +
    ':host([data-reframe]) .frame{box-shadow:0 0 0 2px #c96442}' +
    '.empty{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;' +
    '  justify-content:center;gap:6px;text-align:center;padding:12px;box-sizing:border-box;' +
    '  cursor:pointer;user-select:none}' +
    '.empty svg{opacity:.45}' +
    '.empty .cap{max-width:90%;font-weight:500;letter-spacing:.01em}' +
    '.empty .sub{font-size:11px}' +
    '.empty .sub u{text-underline-offset:2px;text-decoration-color:rgba(0,0,0,.25)}' +
    '.empty:hover .sub u{color:rgba(0,0,0,.75);text-decoration-color:currentColor}' +
    ':host([data-over]) .frame{outline:2px solid #c96442;outline-offset:-2px;' +
    '  background:rgba(201,100,66,.10)}' +
    '.ring{position:absolute;inset:0;pointer-events:none;border:1.5px dashed rgba(0,0,0,.25);' +
    '  transition:border-color .12s}' +
    ':host([data-over]) .ring{border-color:#c96442}' +
    ':host([data-filled]) .ring{display:none}' +
    // Controls sit BELOW the mask (top:100%), absolutely positioned so the
    // author-declared slot height is unaffected. The gap is padding, not a
    // top offset, so the hover target stays contiguous with the frame.
    '.ctl{position:absolute;top:100%;left:50%;transform:translateX(-50%);padding-top:8px;' +
    '  display:flex;gap:6px;opacity:0;pointer-events:none;transition:opacity .12s;z-index:2;' +
    '  white-space:nowrap}' +
    ':host([data-filled][data-editable]:hover) .ctl,:host([data-reframe]) .ctl' +
    '  {opacity:1;pointer-events:auto}' +
    '.ctl button{appearance:none;border:0;border-radius:6px;padding:5px 10px;cursor:pointer;' +
    '  background:rgba(0,0,0,.65);color:#fff;font:11px/1 system-ui,-apple-system,sans-serif;' +
    '  backdrop-filter:blur(6px)}' +
    '.ctl button:hover{background:rgba(0,0,0,.8)}' +
    '.err{position:absolute;left:8px;bottom:8px;right:8px;color:#b3261e;font-size:11px;' +
    '  background:rgba(255,255,255,.85);padding:4px 6px;border-radius:5px;pointer-events:none}';

  const icon =
    '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
    'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' +
    '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/>' +
    '<path d="m21 15-5-5L5 21"/></svg>';

  class ImageSlot extends HTMLElement {
    static get observedAttributes() {
      return ['shape', 'radius', 'mask', 'fit', 'position', 'placeholder', 'src', 'id'];
    }

    constructor() {
      super();
      const root = this.attachShadow({ mode: 'open' });
      // .spill and .ctl sit OUTSIDE .frame so overflow:hidden + border-radius
      // on the frame (circle, pill, rounded) can't clip them.
      root.innerHTML =
        '<style>' + stylesheet + '</style>' +
        '<div class="frame" part="frame">' +
        '  <img part="image" alt="" draggable="false" style="display:none">' +
        '  <div class="empty" part="empty">' + icon +
        '    <div class="cap"></div>' +
        '    <div class="sub">or <u>browse files</u></div></div>' +
        '  <div class="ring" part="ring"></div>' +
        '</div>' +
        '<div class="spill">' +
        '  <img class="ghost" alt="" draggable="false">' +
        '  <div class="handle" data-c="nw"></div><div class="handle" data-c="ne"></div>' +
        '  <div class="handle" data-c="sw"></div><div class="handle" data-c="se"></div>' +
        '</div>' +
        '<div class="ctl"><button data-act="replace" title="Replace image">Replace</button>' +
        '  <button data-act="clear" title="Remove image">Remove</button></div>' +
        '<input type="file" accept="' + ACCEPT.join(',') + '" hidden>';
      this._frame = root.querySelector('.frame');
      this._ring = root.querySelector('.ring');
      this._img = root.querySelector('.frame img');
      this._empty = root.querySelector('.empty');
      this._cap = root.querySelector('.cap');
      this._sub = root.querySelector('.sub');
      this._spill = root.querySelector('.spill');
      this._ghost = root.querySelector('.ghost');
      this._err = null;
      this._input = root.querySelector('input');
      this._depth = 0;
      this._gen = 0;
      this._view = { s: 1, x: 0, y: 0 };
      this._subFn = () => this._render();
      // Shadow-DOM listeners live with the shadow DOM ??bound once here so
      // disconnect/reconnect (e.g. React remount) doesn't stack handlers.
      this._empty.addEventListener('click', () => this._input.click());
      root.addEventListener('click', (e) => {
        const act = e.target && e.target.getAttribute && e.target.getAttribute('data-act');
        if (act === 'replace') { this._exitReframe(true); this._input.click(); }
        if (act === 'clear') {
          this._exitReframe(false);
          this._gen++;
          this._local = null;
          if (this.id) setSlot(this.id, null); else this._render();
        }
      });
      this._input.addEventListener('change', () => {
        const f = this._input.files && this._input.files[0];
        if (f) this._ingest(f);
        this._input.value = '';
      });
      // naturalWidth/Height aren't known until load ??re-apply so the cover
      // baseline is computed from real dimensions, not the 100%?100% fallback.
      this._img.addEventListener('load', () => this._applyView());
      // Gated on editable + fit=cover so share links and contain/fill slots
      // stay static.
      this.addEventListener('dblclick', (e) => {
        if (!this.hasAttribute('data-editable') || !this._reframes()) return;
        e.preventDefault();
        if (this.hasAttribute('data-reframe')) this._exitReframe(true);
        else this._enterReframe();
      });
      // Pan + resize both originate on the spill layer. A handle pointerdown
      // drives an aspect-locked resize anchored at the opposite corner; any
      // other pointerdown on the spill pans. Offsets are frame-% so a
      // reframed slot survives responsive resize / PPTX export.
      this._spill.addEventListener('pointerdown', (e) => {
        if (e.button !== 0 || !this.hasAttribute('data-reframe')) return;
        e.preventDefault();
        e.stopPropagation();
        this._spill.setPointerCapture(e.pointerId);
        const rect = this.getBoundingClientRect();
        const fw = rect.width || 1, fh = rect.height || 1;
        const corner = e.target.getAttribute && e.target.getAttribute('data-c');
        let move;
        if (corner) {
          // Resize about the OPPOSITE corner. Viewport-px throughout (rect
          // fw/fh, not clientWidth) so the math survives a transform:scale()
          // ancestor ??deck_stage renders slides scaled-to-fit.
          const iw = this._img.naturalWidth || 1, ih = this._img.naturalHeight || 1;
          const base = Math.max(fw / iw, fh / ih);
          const sx = corner.includes('e') ? 1 : -1;
          const sy = corner.includes('s') ? 1 : -1;
          const s0 = this._view.s;
          const w0 = iw * base * s0, h0 = ih * base * s0;
          const cx0 = (50 + this._view.x) / 100 * fw;
          const cy0 = (50 + this._view.y) / 100 * fh;
          const ox = cx0 - sx * w0 / 2, oy = cy0 - sy * h0 / 2;
          const diag0 = Math.hypot(w0, h0);
          const ux = sx * w0 / diag0, uy = sy * h0 / diag0;
          move = (ev) => {
            const proj = (ev.clientX - rect.left - ox) * ux +
                         (ev.clientY - rect.top - oy) * uy;
            const s = clampS(s0 * proj / diag0);
            const d = diag0 * s / s0;
            this._view.s = s;
            this._view.x = (ox + ux * d / 2) / fw * 100 - 50;
            this._view.y = (oy + uy * d / 2) / fh * 100 - 50;
            this._clampView();
            this._applyView();
          };
        } else {
          this.setAttribute('data-panning', '');
          const start = { px: e.clientX, py: e.clientY, x: this._view.x, y: this._view.y };
          move = (ev) => {
            this._view.x = start.x + (ev.clientX - start.px) / fw * 100;
            this._view.y = start.y + (ev.clientY - start.py) / fh * 100;
            this._clampView();
            this._applyView();
          };
        }
        const up = () => {
          try { this._spill.releasePointerCapture(e.pointerId); } catch {}
          this._spill.removeEventListener('pointermove', move);
          this._spill.removeEventListener('pointerup', up);
          this._spill.removeEventListener('pointercancel', up);
          this.removeAttribute('data-panning');
          this._dragUp = null;
        };
        // Stashed so _exitReframe (Escape / outside-click mid-drag) can
        // tear the capture + listeners down synchronously.
        this._dragUp = up;
        this._spill.addEventListener('pointermove', move);
        this._spill.addEventListener('pointerup', up);
        this._spill.addEventListener('pointercancel', up);
      });
      // Wheel zoom stays available inside reframe mode as a trackpad nicety ??      // zooms toward the cursor (offset' = cursorÂ·(1-k) + offsetÂ·k).
      this.addEventListener('wheel', (e) => {
        if (!this.hasAttribute('data-reframe')) return;
        e.preventDefault();
        const r = this.getBoundingClientRect();
        const cx = (e.clientX - r.left) / r.width * 100 - 50;
        const cy = (e.clientY - r.top) / r.height * 100 - 50;
        const prev = this._view.s;
        const next = clampS(prev * Math.pow(1.0015, -e.deltaY));
        if (next === prev) return;
        const k = next / prev;
        this._view.s = next;
        this._view.x = cx * (1 - k) + this._view.x * k;
        this._view.y = cy * (1 - k) + this._view.y * k;
        this._clampView();
        this._applyView();
      }, { passive: false });
    }

    connectedCallback() {
      // Warn once per page ??an id-less slot works for the session but
      // cannot persist, and two id-less slots would share nothing.
      if (!this.id && !ImageSlot._warned) {
        ImageSlot._warned = true;
        console.warn('<image-slot> without an id will not persist its dropped image.');
      }
      this.addEventListener('dragenter', this);
      this.addEventListener('dragover', this);
      this.addEventListener('dragleave', this);
      this.addEventListener('drop', this);
      subs.add(this._subFn);
      // width%/height% in _applyView encode the frame aspect at call time ??      // a host resize (responsive grid, pane divider) would stretch the
      // image until the next _render. Re-render on size change: _render()
      // re-seeds _view from stored before clamp/apply, so a shrink?’grow
      // cycle round-trips instead of ratcheting x/y toward the narrower
      // frame's clamp range.
      this._ro = new ResizeObserver(() => this._render());
      this._ro.observe(this);
      load();
      this._render();
    }

    disconnectedCallback() {
      subs.delete(this._subFn);
      this.removeEventListener('dragenter', this);
      this.removeEventListener('dragover', this);
      this.removeEventListener('dragleave', this);
      this.removeEventListener('drop', this);
      if (this._ro) { this._ro.disconnect(); this._ro = null; }
      this._exitReframe(false);
    }

    _enterReframe() {
      if (this.hasAttribute('data-reframe')) return;
      this.setAttribute('data-reframe', '');
      this._applyView();
      // Close on click outside (the spill handler stopPropagation()s so
      // in-image drags don't reach this) and on Escape. Listeners are held
      // on the instance so _exitReframe / disconnectedCallback can detach
      // exactly what was attached.
      this._outside = (e) => {
        if (e.composedPath && e.composedPath().includes(this)) return;
        this._exitReframe(true);
      };
      this._esc = (e) => { if (e.key === 'Escape') this._exitReframe(true); };
      document.addEventListener('pointerdown', this._outside, true);
      document.addEventListener('keydown', this._esc, true);
    }

    _exitReframe(commit) {
      if (!this.hasAttribute('data-reframe')) return;
      if (this._dragUp) this._dragUp();
      this.removeAttribute('data-reframe');
      this.removeAttribute('data-panning');
      if (this._outside) document.removeEventListener('pointerdown', this._outside, true);
      if (this._esc) document.removeEventListener('keydown', this._esc, true);
      this._outside = this._esc = null;
      if (commit) this._commitView();
    }

    attributeChangedCallback() { if (this.shadowRoot) this._render(); }

    // handleEvent ??one listener object for all four drag events keeps the
    // add/remove symmetric and the depth counter correct.
    handleEvent(e) {
      if (e.type === 'dragenter' || e.type === 'dragover') {
        // Without preventDefault the browser never fires 'drop'.
        e.preventDefault();
        e.stopPropagation();
        if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
        if (e.type === 'dragenter') this._depth++;
        this.setAttribute('data-over', '');
      } else if (e.type === 'dragleave') {
        // dragenter/leave fire for every descendant crossing ??count depth
        // so hovering the icon inside the empty state doesn't flicker.
        if (--this._depth <= 0) { this._depth = 0; this.removeAttribute('data-over'); }
      } else if (e.type === 'drop') {
        e.preventDefault();
        e.stopPropagation();
        this._depth = 0;
        this.removeAttribute('data-over');
        const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        if (f) this._ingest(f);
      }
    }

    async _ingest(file) {
      this._setError(null);
      if (!file || ACCEPT.indexOf(file.type) < 0) {
        this._setError('Drop a PNG, JPEG, WebP, or AVIF image.');
        return;
      }
      // toDataUrl can take hundreds of ms on a large photo. A Clear or a
      // newer drop during that window would be clobbered when this await
      // resumes ??bump + capture a generation so stale encodes bail.
      const gen = ++this._gen;
      try {
        const w = this.clientWidth || this.offsetWidth || MAX_DIM;
        const url = await toDataUrl(file, w);
        if (gen !== this._gen) return;
        // Only exit reframe once the new image is in hand ??a rejected type
        // or decode failure leaves the in-progress crop untouched.
        this._exitReframe(false);
        const val = { u: url, s: 1, x: 0, y: 0 };
        setSlot(this.id || '', val);
        // Keep a session-local copy for id-less slots so the drop still
        // shows, even though it cannot persist.
        if (!this.id) { this._local = val; this._render(); }
      } catch (err) {
        if (gen !== this._gen) return;
        this._setError('Could not read that image.');
        console.warn('<image-slot> ingest failed:', err);
      }
    }

    _setError(msg) {
      if (this._err) { this._err.remove(); this._err = null; }
      if (!msg) return;
      const d = document.createElement('div');
      d.className = 'err'; d.textContent = msg;
      this.shadowRoot.appendChild(d);
      this._err = d;
      setTimeout(() => { if (this._err === d) { d.remove(); this._err = null; } }, 3000);
    }

    // Reframing (pan/resize) is only meaningful for fit=cover ??contain/fill
    // keep the old object-fit path and double-click is a no-op.
    _reframes() {
      return this.hasAttribute('data-filled') &&
        (this.getAttribute('fit') || 'cover') === 'cover';
    }

    // Cover-baseline geometry, shared by clamp/apply/resize. Null until the
    // img has loaded (naturalWidth is 0 before that) or when the slot has no
    // layout box ??ResizeObserver fires with a 0?0 rect under display:none,
    // and clamping against a degenerate 1?1 frame would silently pull the
    // stored pan toward zero.
    _geom() {
      const iw = this._img.naturalWidth, ih = this._img.naturalHeight;
      const fw = this.clientWidth, fh = this.clientHeight;
      if (!iw || !ih || !fw || !fh) return null;
      return { iw, ih, fw, fh, base: Math.max(fw / iw, fh / ih) };
    }

    _clampView() {
      // Pan range on each axis is half the overflow past the frame edge.
      const g = this._geom();
      if (!g) return;
      const mx = Math.max(0, (g.iw * g.base * this._view.s / g.fw - 1) * 50);
      const my = Math.max(0, (g.ih * g.base * this._view.s / g.fh - 1) * 50);
      this._view.x = Math.max(-mx, Math.min(mx, this._view.x));
      this._view.y = Math.max(-my, Math.min(my, this._view.y));
    }

    _applyView() {
      const g = this._geom();
      const fit = this.getAttribute('fit') || 'cover';
      if (fit !== 'cover' || !g) {
        // Non-cover, or dimensions not known yet (before img load).
        this._img.style.width = '100%';
        this._img.style.height = '100%';
        this._img.style.left = '50%';
        this._img.style.top = '50%';
        this._img.style.objectFit = fit;
        this._img.style.objectPosition = this.getAttribute('position') || '50% 50%';
        return;
      }
      // Cover baseline: img fills the frame on its tighter axis at s=1, so
      // pan works immediately on the overflowing axis without zooming first.
      // Width/height and left/top are all frame-% ??depends only on the
      // frame aspect ratio, so a responsive resize keeps the same crop. The
      // spill layer mirrors the same box so its corners = image corners.
      const k = g.base * this._view.s;
      const w = (g.iw * k / g.fw * 100) + '%';
      const h = (g.ih * k / g.fh * 100) + '%';
      const l = (50 + this._view.x) + '%';
      const t = (50 + this._view.y) + '%';
      this._img.style.width = w; this._img.style.height = h;
      this._img.style.left = l; this._img.style.top = t;
      this._img.style.objectFit = '';
      this._spill.style.width = w; this._spill.style.height = h;
      this._spill.style.left = l; this._spill.style.top = t;
    }

    _commitView() {
      const v = { s: this._view.s, x: this._view.x, y: this._view.y };
      if (this._userUrl) v.u = this._userUrl;
      // Framing-only (no u) persists too so an author-src slot remembers its
      // crop; clearing the sidecar still falls through to src=.
      if (this.id) setSlot(this.id, v);
      else { this._local = v; }
    }

    _render() {
      // Shape / mask. Presets use border-radius so the dashed ring can
      // follow the rounded outline; clip-path is only applied for an
      // explicit `mask` (the ring is hidden there since a rectangle
      // dashed border chopped by an arbitrary polygon looks broken).
      const mask = this.getAttribute('mask');
      const shape = (this.getAttribute('shape') || 'rounded').toLowerCase();
      let radius = '';
      if (shape === 'circle') radius = '50%';
      else if (shape === 'pill') radius = '9999px';
      else if (shape === 'rounded') {
        const n = parseFloat(this.getAttribute('radius'));
        radius = (Number.isFinite(n) ? n : 12) + 'px';
      }
      this._frame.style.borderRadius = mask ? '' : radius;
      this._frame.style.clipPath = mask || '';
      this._ring.style.borderRadius = mask ? '' : radius;
      this._ring.style.display = mask ? 'none' : '';

      // Controls and reframe entry gate on this so share links stay read-only.
      const editable = !!(window.omelette && window.omelette.writeFile);
      this.toggleAttribute('data-editable', editable);
      this._sub.style.display = editable ? '' : 'none';

      // Content. The sidecar is also writable by the agent's write_file
      // tool, so its value isn't guaranteed canvas-originated ??only accept
      // data:image/ URLs from it. The `src` attribute is author-controlled
      // (Claude wrote it into the HTML) so it passes through unchanged.
      let stored = this.id ? getSlot(this.id) : this._local;
      if (stored && stored.u && !/^data:image\//i.test(stored.u)) stored = null;
      const srcAttr = this.getAttribute('src') || '';
      this._userUrl = (stored && stored.u) || null;
      const url = this._userUrl || srcAttr;
      // Don't clobber an in-flight reframe with a store-triggered re-render.
      if (!this.hasAttribute('data-reframe')) {
        this._view = {
          s: stored && Number.isFinite(stored.s) ? clampS(stored.s) : 1,
          x: stored && Number.isFinite(stored.x) ? stored.x : 0,
          y: stored && Number.isFinite(stored.y) ? stored.y : 0,
        };
      }
      this._cap.textContent = this.getAttribute('placeholder') || 'Drop an image';
      // Toggle via style.display ??the [hidden] attribute alone loses to
      // the display:flex / display:block rules in the stylesheet above.
      if (url) {
        if (this._img.getAttribute('src') !== url) {
          this._img.src = url;
          this._ghost.src = url;
        }
        this._img.style.display = 'block';
        this._empty.style.display = 'none';
        this.setAttribute('data-filled', '');
        this._clampView();
        this._applyView();
      } else {
        this._img.style.display = 'none';
        this._img.removeAttribute('src');
        this._ghost.removeAttribute('src');
        this._empty.style.display = 'flex';
        this.removeAttribute('data-filled');
      }
    }
  }

  if (!customElements.get('image-slot')) {
    customElements.define('image-slot', ImageSlot);
  }
})();

;/* KAU WordPress visual editor bridge */
if (!window.KAU_VISUAL_EDITOR_BUNDLE_LOADED) {
  window.KAU_VISUAL_EDITOR_BUNDLE_LOADED = true;
  (function () {
    var configElement = document.getElementById('kau-ve-config');
    if (configElement && !window.KAU_VE_CONFIG) {
      try { window.KAU_VE_CONFIG = JSON.parse(configElement.textContent || '{}'); } catch (error) { window.KAU_VE_CONFIG = {}; }
    }
  }());
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

  (function () {
    var cfg = window.KAU_VE_CONFIG || {};
    if (!cfg.editMode) return;
    var style = document.createElement('style');
    style.setAttribute('data-kau-ve-style', '1');
    style.textContent = {"value":"#kau-ve-toolbar {\n  position: fixed;\n  left: 50%;\n  bottom: 18px;\n  z-index: 2147483000;\n  display: flex;\n  align-items: center;\n  gap: 8px;\n  max-width: calc(100vw - 28px);\n  padding: 10px;\n  color: #fff;\n  background: rgba(0, 27, 61, .94);\n  border: 1px solid rgba(255, 255, 255, .18);\n  border-radius: 999px;\n  box-shadow: 0 18px 48px rgba(0, 0, 0, .32);\n  font: 600 13px/1 system-ui, -apple-system, BlinkMacSystemFont, \"Segoe UI\", sans-serif;\n  transform: translateX(-50%);\n  backdrop-filter: blur(14px);\n}\n\n#kau-ve-toolbar button,\n#kau-ve-toolbar a {\n  display: inline-flex;\n  align-items: center;\n  justify-content: center;\n  min-height: 34px;\n  padding: 0 14px;\n  color: inherit;\n  background: rgba(255, 255, 255, .1);\n  border: 1px solid rgba(255, 255, 255, .12);\n  border-radius: 999px;\n  font: inherit;\n  text-decoration: none;\n  cursor: pointer;\n  white-space: nowrap;\n}\n\n#kau-ve-toolbar button:hover,\n#kau-ve-toolbar a:hover {\n  background: rgba(255, 255, 255, .18);\n}\n\n#kau-ve-toolbar .kau-ve-save {\n  color: #001b3d;\n  background: #d7ab1a;\n  border-color: #d7ab1a;\n}\n\n#kau-ve-toolbar .kau-ve-status {\n  max-width: 240px;\n  overflow: hidden;\n  color: rgba(255, 255, 255, .72);\n  text-overflow: ellipsis;\n  white-space: nowrap;\n}\n\n.kau-ve-editable {\n  outline: 1px dashed rgba(215, 171, 26, .7);\n  outline-offset: 4px;\n  cursor: text;\n}\n\n.kau-ve-editable:hover,\n.kau-ve-selected {\n  outline: 2px solid #d7ab1a !important;\n  outline-offset: 5px;\n}\n\n.kau-ve-media-editable {\n  cursor: pointer;\n  box-shadow: 0 0 0 2px rgba(215, 171, 26, .8);\n}\n\n.kau-ve-link-panel {\n  position: fixed;\n  right: 18px;\n  bottom: 78px;\n  z-index: 2147483000;\n  display: none;\n  width: min(420px, calc(100vw - 28px));\n  padding: 12px;\n  background: #fff;\n  border: 1px solid rgba(0, 0, 0, .12);\n  border-radius: 12px;\n  box-shadow: 0 18px 48px rgba(0, 0, 0, .24);\n  font: 13px/1.4 system-ui, -apple-system, BlinkMacSystemFont, \"Segoe UI\", sans-serif;\n}\n\n.kau-ve-link-panel.open {\n  display: block;\n}\n\n.kau-ve-link-panel label {\n  display: block;\n  margin-bottom: 6px;\n  color: #334155;\n  font-weight: 700;\n}\n\n.kau-ve-link-panel input {\n  width: 100%;\n  height: 36px;\n  padding: 0 10px;\n  border: 1px solid #cbd5e1;\n  border-radius: 8px;\n  font: inherit;\n}\n\n.kau-ve-link-panel .kau-ve-row {\n  display: flex;\n  gap: 8px;\n  justify-content: flex-end;\n  margin-top: 10px;\n}\n\n.kau-ve-link-panel button {\n  min-height: 32px;\n  padding: 0 12px;\n  border: 1px solid #cbd5e1;\n  border-radius: 999px;\n  background: #fff;\n  cursor: pointer;\n}\n\n.kau-ve-link-panel .primary {\n  color: #fff;\n  background: #001b3d;\n  border-color: #001b3d;\n}\n\n@media (max-width: 720px) {\n  #kau-ve-toolbar {\n    left: 12px;\n    right: 12px;\n    flex-wrap: wrap;\n    justify-content: center;\n    border-radius: 18px;\n    transform: none;\n  }\n}\n\n","PSPath":"D:\\Akiu\\KAU\\KAU-offline\\assets\\site-tools.css","PSParentPath":"D:\\Akiu\\KAU\\KAU-offline\\assets","PSChildName":"site-tools.css","PSDrive":{"CurrentLocation":"Akiu\\KAU\\KAU-offline","Name":"D","Provider":{"ImplementingType":"Microsoft.PowerShell.Commands.FileSystemProvider","HelpFile":"System.Management.Automation.dll-Help.xml","Name":"FileSystem","PSSnapIn":"Microsoft.PowerShell.Core","ModuleName":"Microsoft.PowerShell.Core","Module":null,"Description":"","Capabilities":52,"Home":"C:\\Users\\user","Drives":"C D G E F P W"},"Root":"D:\\","Description":"DATA","MaximumSize":null,"Credential":{"UserName":null,"Password":null},"DisplayRoot":null},"PSProvider":{"ImplementingType":{"Module":"System.Management.Automation.dll","Assembly":"System.Management.Automation, Version=3.0.0.0, Culture=neutral, PublicKeyToken=31bf3856ad364e35","TypeHandle":"System.RuntimeTypeHandle","DeclaringMethod":null,"BaseType":"System.Management.Automation.Provider.NavigationCmdletProvider","UnderlyingSystemType":"Microsoft.PowerShell.Commands.FileSystemProvider","FullName":"Microsoft.PowerShell.Commands.FileSystemProvider","AssemblyQualifiedName":"Microsoft.PowerShell.Commands.FileSystemProvider, System.Management.Automation, Version=3.0.0.0, Culture=neutral, PublicKeyToken=31bf3856ad364e35","Namespace":"Microsoft.PowerShell.Commands","GUID":"b4755d19-b6a7-38dc-ae06-4167f801062f","IsEnum":false,"GenericParameterAttributes":null,"IsSecurityCritical":true,"IsSecuritySafeCritical":false,"IsSecurityTransparent":false,"IsGenericTypeDefinition":false,"IsGenericParameter":false,"GenericParameterPosition":null,"IsGenericType":false,"IsConstructedGenericType":false,"ContainsGenericParameters":false,"StructLayoutAttribute":"System.Runtime.InteropServices.StructLayoutAttribute","Name":"FileSystemProvider","MemberType":32,"DeclaringType":null,"ReflectedType":null,"MetadataToken":33556363,"GenericTypeParameters":"","DeclaredConstructors":"Void .ctor() Void .cctor()","DeclaredEvents":"","DeclaredFields":"System.Collections.ObjectModel.Collection`1[System.Management.Automation.WildcardPattern] excludeMatcher System.Management.Automation.PSTraceSource tracer Int32 FILETRANSFERSIZE System.String ProviderName","DeclaredMembers":"System.String NormalizePath(System.String) System.IO.FileSystemInfo GetFileSystemInfo(System.String, Boolean ByRef) Boolean IsFilterSet() System.Object GetChildNamesDynamicParameters(System.String) System.Object GetChildItemsDynamicParameters(System.String, Boolean) System.Object CopyItemDynamicParameters(System.String, System.String, Boolean) Boolean IsNetworkMappedDrive(System.Management.Automation.PSDriveInfo) Boolean IsSupportedDriveForPersistence(System.Management.Automation.PSDriveInfo) System.String GetRootPathForNetworkDriveOrDosDevice(System.IO.DriveInfo) System.Collections.ObjectModel.Collection`1[System.Management.Automation.PSDriveInfo] InitializeDefaultDrives() System.Object GetItemDynamicParameters(System.String) Void InvokeDefaultAction(System.String) Void GetChildItems(System.String, Boolean, UInt32) Void GetChildNames(System.String, System.Management.Automation.ReturnContainers) Boolean CheckItemExists(System.String, Boolean ByRef) System.Object RemoveItemDynamicParameters(System.String, Boolean) Void RemoveFileInfoItem(System.IO.FileInfo, Boolean) Boolean ItemExists(System.String) System.Object ItemExistsDynamicParameters(System.String) Boolean HasChildItems(System.String) Void CopyItemLocalOrToSession(System.String, System.String, Boolean, Boolean, System.Management.Automation.PowerShell) Void InitilizeFunctionPSCopyFileFromRemoteSession(System.Management.Automation.PowerShell) Boolean ValidRemoteSessionForScripting(System.Management.Automation.Runspaces.Runspace) Void InitilizeFunctionsPSCopyFileToRemoteSession(System.Management.Automation.PowerShell) Boolean PathIsReservedDeviceName(System.String, System.String) Boolean IsAbsolutePath(System.String) System.String GetCommonBase(System.String, System.String) System.String CreateNormalizedRelativePathFromStack(System.Collections.Generic.Stack`1[System.String]) Boolean IsItemContainer(System.String) Boolean IsSameVolume(System.String, System.String) System.Object GetPropertyDynamicParameters(System.String, System.Collections.ObjectModel.Collection`1[System.String]) System.Object SetPropertyDynamicParameters(System.String, System.Management.Automation.PSObject) System.Object ClearPropertyDynamicParameters(System.String, System.Collections.ObjectModel.Collection`1[System.String]) System.Object GetContentWriterDynamicParameters(System.String) System.Object ClearContentDynamicParameters(System.String) Int32 SafeGetFileAttributes(System.String) System.Security.AccessControl.ObjectSecurity NewSecurityDescriptorFromPath(System.String, System.Security.AccessControl.AccessControlSections) System.Security.AccessControl.ObjectSecurity NewSecurityDescriptorOfType(System.String, System.Security.AccessControl.AccessControlSections) System.Security.AccessControl.ObjectSecurity NewSecurityDescriptor(ItemType) System.Management.Automation.ErrorRecord CreateErrorRecord(System.String, System.String) System.String GetHelpMaml(System.String, System.String) System.Management.Automation.ProviderInfo Start(System.Management.Automation.ProviderInfo) System.Management.Automation.PSDriveInfo NewDrive(System.Management.Automation.PSDriveInfo) Void MapNetworkDrive(System.Management.Automation.PSDriveInfo) System.Management.Automation.PSDriveInfo RemoveDrive(System.Management.Automation.PSDriveInfo) System.String GetUNCForNetworkDrive(System.String) System.String GetSubstitutedPathForNetworkDosDevice(System.String) Boolean IsValidPath(System.String) Void GetItem(System.String) System.IO.FileSystemInfo GetFileSystemItem(System.String, Boolean ByRef, Boolean) Boolean ConvertPath(System.String, System.String, System.String ByRef, System.String ByRef) Void GetPathItems(System.String, Boolean, UInt32, Boolean, System.Management.Automation.ReturnContainers) Void Dir(System.IO.DirectoryInfo, Boolean, UInt32, Boolean, System.Management.Automation.ReturnContainers, InodeTracker) System.Management.Automation.FlagsExpression`1[System.IO.FileAttributes] FormatAttributeSwitchParamters() System.String Mode(System.Management.Automation.PSObject) Void RenameItem(System.String, System.String) Void NewItem(System.String, System.String, System.Object) ItemType GetItemType(System.String) Void CreateDirectory(System.String, Boolean) Boolean CreateIntermediateDirectories(System.String) Void RemoveItem(System.String, Boolean) Void RemoveDirectoryInfoItem(System.IO.DirectoryInfo, Boolean, Boolean, Boolean) Void RemoveFileSystemItem(System.IO.FileSystemInfo, Boolean) Boolean ItemExists(System.String, System.Management.Automation.ErrorRecord ByRef) Boolean DirectoryInfoHasChildItems(System.IO.DirectoryInfo) Void CopyItem(System.String, System.String, Boolean) Void CopyItemFromRemoteSession(System.String, System.String, Boolean, Boolean, System.Management.Automation.Runspaces.PSSession) Void CopyDirectoryInfoItem(System.IO.DirectoryInfo, System.String, Boolean, Boolean, System.Management.Automation.PowerShell) Void CopyFileInfoItem(System.IO.FileInfo, System.String, Boolean, System.Management.Automation.PowerShell) Void CopyDirectoryFromRemoteSession(System.String, System.String, System.String, Boolean, Boolean, System.Management.Automation.PowerShell) System.Collections.ArrayList GetRemoteSourceAlternateStreams(System.Management.Automation.PowerShell, System.String) Void RemoveFunctionsPSCopyFileFromRemoteSession(System.Management.Automation.PowerShell) System.Collections.Hashtable GetRemoteFileMetadata(System.String, System.Management.Automation.PowerShell) Void SetFileMetadata(System.String, System.IO.FileInfo, System.Management.Automation.PowerShell) Void CopyFileFromRemoteSession(System.String, System.String, System.String, Boolean, System.Management.Automation.PowerShell, Int64) Boolean PerformCopyFileFromRemoteSession(System.String, System.IO.FileInfo, System.String, Boolean, System.Management.Automation.PowerShell, Int64, Boolean, System.String) Void RemoveFunctionPSCopyFileToRemoteSession(System.Management.Automation.PowerShell) Boolean RemoteTargetSupportsAlternateStreams(System.Management.Automation.PowerShell, System.String) System.String MakeRemotePath(System.Management.Automation.PowerShell, System.String, System.String) Boolean RemoteDirectoryExist(System.Management.Automation.PowerShell, System.String) Boolean CopyFileStreamToRemoteSession(System.IO.FileInfo, System.String, System.Management.Automation.PowerShell, Boolean, System.String) System.Collections.Hashtable GetFileMetadata(System.IO.FileInfo) Void SetRemoteFileMetadata(System.IO.FileInfo, System.String, System.Management.Automation.PowerShell) Boolean PerformCopyFileToRemoteSession(System.IO.FileInfo, System.String, System.Management.Automation.PowerShell) Boolean RemoteDestinationPathIsFile(System.String, System.Management.Automation.PowerShell) System.String CreateDirectoryOnRemoteSession(System.String, Boolean, System.Management.Automation.PowerShell) System.String GetParentPath(System.String, System.String) Boolean IsUNCPath(System.String) Boolean IsUNCRoot(System.String) Boolean IsPathRoot(System.String) System.String NormalizeRelativePath(System.String, System.String) System.String NormalizeRelativePathHelper(System.String, System.String) System.String RemoveRelativeTokens(System.String) System.Collections.Generic.Stack`1[System.String] TokenizePathToStack(System.String, System.String) System.Collections.Generic.Stack`1[System.String] NormalizeThePath(System.String, System.Collections.Generic.Stack`1[System.String]) System.String GetChildName(System.String) System.String EnsureDriveIsRooted(System.String) Void MoveItem(System.String, System.String) Void MoveFileInfoItem(System.IO.FileInfo, System.String, Boolean, Boolean) Void MoveDirectoryInfoItem(System.IO.DirectoryInfo, System.String, Boolean) Void CopyAndDelete(System.IO.DirectoryInfo, System.String, Boolean) Void GetProperty(System.String, System.Collections.ObjectModel.Collection`1[System.String]) Void SetProperty(System.String, System.Management.Automation.PSObject) Void ClearProperty(System.String, System.Collections.ObjectModel.Collection`1[System.String]) System.Management.Automation.Provider.IContentReader GetContentReader(System.String) System.Object GetContentReaderDynamicParameters(System.String) System.Management.Automation.Provider.IContentWriter GetContentWriter(System.String) Void ClearContent(System.String) Void ValidateParameters(Boolean) Void GetSecurityDescriptor(System.String, System.Security.AccessControl.AccessControlSections) Void SetSecurityDescriptor(System.String, System.Security.AccessControl.ObjectSecurity) Void SetSecurityDescriptor(System.String, System.Security.AccessControl.ObjectSecurity, System.Security.AccessControl.AccessControlSections) Void \u003cRemoveDirectoryInfoItem\u003eg__WriteErrorHelper|43_0(System.Exception, \u003c\u003ec__DisplayClass43_0 ByRef) Void .ctor() Void .cctor() System.Collections.ObjectModel.Collection`1[System.Management.Automation.WildcardPattern] excludeMatcher System.Management.Automation.PSTraceSource tracer Int32 FILETRANSFERSIZE System.String ProviderName Microsoft.PowerShell.Commands.FileSystemProvider+ItemType Microsoft.PowerShell.Commands.FileSystemProvider+NativeMethods Microsoft.PowerShell.Commands.FileSystemProvider+NetResource Microsoft.PowerShell.Commands.FileSystemProvider+InodeTracker Microsoft.PowerShell.Commands.FileSystemProvider+\u003c\u003ec__DisplayClass43_0","DeclaredMethods":"System.String Mode(System.Management.Automation.PSObject) System.String NormalizePath(System.String) System.IO.FileSystemInfo GetFileSystemInfo(System.String, Boolean ByRef) Boolean IsFilterSet() System.Object GetChildNamesDynamicParameters(System.String) System.Object GetChildItemsDynamicParameters(System.String, Boolean) System.Object CopyItemDynamicParameters(System.String, System.String, Boolean) Boolean IsNetworkMappedDrive(System.Management.Automation.PSDriveInfo) Boolean IsSupportedDriveForPersistence(System.Management.Automation.PSDriveInfo) System.String GetRootPathForNetworkDriveOrDosDevice(System.IO.DriveInfo) System.Collections.ObjectModel.Collection`1[System.Management.Automation.PSDriveInfo] InitializeDefaultDrives() System.Object GetItemDynamicParameters(System.String) Void InvokeDefaultAction(System.String) Void GetChildItems(System.String, Boolean, UInt32) Void GetChildNames(System.String, System.Management.Automation.ReturnContainers) Boolean CheckItemExists(System.String, Boolean ByRef) System.Object RemoveItemDynamicParameters(System.String, Boolean) Void RemoveFileInfoItem(System.IO.FileInfo, Boolean) Boolean ItemExists(System.String) System.Object ItemExistsDynamicParameters(System.String) Boolean HasChildItems(System.String) Void CopyItemLocalOrToSession(System.String, System.String, Boolean, Boolean, System.Management.Automation.PowerShell) Void InitilizeFunctionPSCopyFileFromRemoteSession(System.Management.Automation.PowerShell) Boolean ValidRemoteSessionForScripting(System.Management.Automation.Runspaces.Runspace) Void InitilizeFunctionsPSCopyFileToRemoteSession(System.Management.Automation.PowerShell) Boolean PathIsReservedDeviceName(System.String, System.String) Boolean IsAbsolutePath(System.String) System.String GetCommonBase(System.String, System.String) System.String CreateNormalizedRelativePathFromStack(System.Collections.Generic.Stack`1[System.String]) Boolean IsItemContainer(System.String) Boolean IsSameVolume(System.String, System.String) System.Object GetPropertyDynamicParameters(System.String, System.Collections.ObjectModel.Collection`1[System.String]) System.Object SetPropertyDynamicParameters(System.String, System.Management.Automation.PSObject) System.Object ClearPropertyDynamicParameters(System.String, System.Collections.ObjectModel.Collection`1[System.String]) System.Object GetContentWriterDynamicParameters(System.String) System.Object ClearContentDynamicParameters(System.String) Int32 SafeGetFileAttributes(System.String) System.Security.AccessControl.ObjectSecurity NewSecurityDescriptorFromPath(System.String, System.Security.AccessControl.AccessControlSections) System.Security.AccessControl.ObjectSecurity NewSecurityDescriptorOfType(System.String, System.Security.AccessControl.AccessControlSections) System.Security.AccessControl.ObjectSecurity NewSecurityDescriptor(ItemType) System.Management.Automation.ErrorRecord CreateErrorRecord(System.String, System.String) System.String GetHelpMaml(System.String, System.String) System.Management.Automation.ProviderInfo Start(System.Management.Automation.ProviderInfo) System.Management.Automation.PSDriveInfo NewDrive(System.Management.Automation.PSDriveInfo) Void MapNetworkDrive(System.Management.Automation.PSDriveInfo) System.Management.Automation.PSDriveInfo RemoveDrive(System.Management.Automation.PSDriveInfo) System.String GetUNCForNetworkDrive(System.String) System.String GetSubstitutedPathForNetworkDosDevice(System.String) Boolean IsValidPath(System.String) Void GetItem(System.String) System.IO.FileSystemInfo GetFileSystemItem(System.String, Boolean ByRef, Boolean) Boolean ConvertPath(System.String, System.String, System.String ByRef, System.String ByRef) Void GetPathItems(System.String, Boolean, UInt32, Boolean, System.Management.Automation.ReturnContainers) Void Dir(System.IO.DirectoryInfo, Boolean, UInt32, Boolean, System.Management.Automation.ReturnContainers, InodeTracker) System.Management.Automation.FlagsExpression`1[System.IO.FileAttributes] FormatAttributeSwitchParamters() Void RenameItem(System.String, System.String) Void NewItem(System.String, System.String, System.Object) ItemType GetItemType(System.String) Void CreateDirectory(System.String, Boolean) Boolean CreateIntermediateDirectories(System.String) Void RemoveItem(System.String, Boolean) Void RemoveDirectoryInfoItem(System.IO.DirectoryInfo, Boolean, Boolean, Boolean) Void RemoveFileSystemItem(System.IO.FileSystemInfo, Boolean) Boolean ItemExists(System.String, System.Management.Automation.ErrorRecord ByRef) Boolean DirectoryInfoHasChildItems(System.IO.DirectoryInfo) Void CopyItem(System.String, System.String, Boolean) Void CopyItemFromRemoteSession(System.String, System.String, Boolean, Boolean, System.Management.Automation.Runspaces.PSSession) Void CopyDirectoryInfoItem(System.IO.DirectoryInfo, System.String, Boolean, Boolean, System.Management.Automation.PowerShell) Void CopyFileInfoItem(System.IO.FileInfo, System.String, Boolean, System.Management.Automation.PowerShell) Void CopyDirectoryFromRemoteSession(System.String, System.String, System.String, Boolean, Boolean, System.Management.Automation.PowerShell) System.Collections.ArrayList GetRemoteSourceAlternateStreams(System.Management.Automation.PowerShell, System.String) Void RemoveFunctionsPSCopyFileFromRemoteSession(System.Management.Automation.PowerShell) System.Collections.Hashtable GetRemoteFileMetadata(System.String, System.Management.Automation.PowerShell) Void SetFileMetadata(System.String, System.IO.FileInfo, System.Management.Automation.PowerShell) Void CopyFileFromRemoteSession(System.String, System.String, System.String, Boolean, System.Management.Automation.PowerShell, Int64) Boolean PerformCopyFileFromRemoteSession(System.String, System.IO.FileInfo, System.String, Boolean, System.Management.Automation.PowerShell, Int64, Boolean, System.String) Void RemoveFunctionPSCopyFileToRemoteSession(System.Management.Automation.PowerShell) Boolean RemoteTargetSupportsAlternateStreams(System.Management.Automation.PowerShell, System.String) System.String MakeRemotePath(System.Management.Automation.PowerShell, System.String, System.String) Boolean RemoteDirectoryExist(System.Management.Automation.PowerShell, System.String) Boolean CopyFileStreamToRemoteSession(System.IO.FileInfo, System.String, System.Management.Automation.PowerShell, Boolean, System.String) System.Collections.Hashtable GetFileMetadata(System.IO.FileInfo) Void SetRemoteFileMetadata(System.IO.FileInfo, System.String, System.Management.Automation.PowerShell) Boolean PerformCopyFileToRemoteSession(System.IO.FileInfo, System.String, System.Management.Automation.PowerShell) Boolean RemoteDestinationPathIsFile(System.String, System.Management.Automation.PowerShell) System.String CreateDirectoryOnRemoteSession(System.String, Boolean, System.Management.Automation.PowerShell) System.String GetParentPath(System.String, System.String) Boolean IsUNCPath(System.String) Boolean IsUNCRoot(System.String) Boolean IsPathRoot(System.String) System.String NormalizeRelativePath(System.String, System.String) System.String NormalizeRelativePathHelper(System.String, System.String) System.String RemoveRelativeTokens(System.String) System.Collections.Generic.Stack`1[System.String] TokenizePathToStack(System.String, System.String) System.Collections.Generic.Stack`1[System.String] NormalizeThePath(System.String, System.Collections.Generic.Stack`1[System.String]) System.String GetChildName(System.String) System.String EnsureDriveIsRooted(System.String) Void MoveItem(System.String, System.String) Void MoveFileInfoItem(System.IO.FileInfo, System.String, Boolean, Boolean) Void MoveDirectoryInfoItem(System.IO.DirectoryInfo, System.String, Boolean) Void CopyAndDelete(System.IO.DirectoryInfo, System.String, Boolean) Void GetProperty(System.String, System.Collections.ObjectModel.Collection`1[System.String]) Void SetProperty(System.String, System.Management.Automation.PSObject) Void ClearProperty(System.String, System.Collections.ObjectModel.Collection`1[System.String]) System.Management.Automation.Provider.IContentReader GetContentReader(System.String) System.Object GetContentReaderDynamicParameters(System.String) System.Management.Automation.Provider.IContentWriter GetContentWriter(System.String) Void ClearContent(System.String) Void ValidateParameters(Boolean) Void GetSecurityDescriptor(System.String, System.Security.AccessControl.AccessControlSections) Void SetSecurityDescriptor(System.String, System.Security.AccessControl.ObjectSecurity) Void SetSecurityDescriptor(System.String, System.Security.AccessControl.ObjectSecurity, System.Security.AccessControl.AccessControlSections) Void \u003cRemoveDirectoryInfoItem\u003eg__WriteErrorHelper|43_0(System.Exception, \u003c\u003ec__DisplayClass43_0 ByRef)","DeclaredNestedTypes":"Microsoft.PowerShell.Commands.FileSystemProvider+ItemType Microsoft.PowerShell.Commands.FileSystemProvider+NativeMethods Microsoft.PowerShell.Commands.FileSystemProvider+NetResource Microsoft.PowerShell.Commands.FileSystemProvider+InodeTracker Microsoft.PowerShell.Commands.FileSystemProvider+\u003c\u003ec__DisplayClass43_0","DeclaredProperties":"","ImplementedInterfaces":"System.Management.Automation.IResourceSupplier System.Management.Automation.Provider.IContentCmdletProvider System.Management.Automation.Provider.IPropertyCmdletProvider System.Management.Automation.Provider.ISecurityDescriptorCmdletProvider System.Management.Automation.Provider.ICmdletProviderSupportsHelp","TypeInitializer":"Void .cctor()","IsNested":false,"Attributes":1048833,"IsVisible":true,"IsNotPublic":false,"IsPublic":true,"IsNestedPublic":false,"IsNestedPrivate":false,"IsNestedFamily":false,"IsNestedAssembly":false,"IsNestedFamANDAssem":false,"IsNestedFamORAssem":false,"IsAutoLayout":true,"IsLayoutSequential":false,"IsExplicitLayout":false,"IsClass":true,"IsInterface":false,"IsValueType":false,"IsAbstract":false,"IsSealed":true,"IsSpecialName":false,"IsImport":false,"IsSerializable":false,"IsAnsiClass":true,"IsUnicodeClass":false,"IsAutoClass":false,"IsArray":false,"IsByRef":false,"IsPointer":false,"IsPrimitive":false,"IsCOMObject":false,"HasElementType":false,"IsContextful":false,"IsMarshalByRef":false,"GenericTypeArguments":"","CustomAttributes":"[System.Management.Automation.OutputTypeAttribute(typeof(System.Management.Automation.PathInfo), ProviderCmdlet = \"Push-Location\")] [System.Management.Automation.OutputTypeAttribute(typeof(System.Security.AccessControl.FileSecurity), ProviderCmdlet = \"Set-Acl\")] [System.Management.Automation.OutputTypeAttribute(new Type[2] { typeof(System.String), typeof(System.Management.Automation.PathInfo) }, ProviderCmdlet = \"Resolve-Path\")] [System.Management.Automation.Provider.CmdletProviderAttribute(\"FileSystem\", (System.Management.Automation.Provider.ProviderCapabilities)52)] [System.Management.Automation.OutputTypeAttribute(new Type[2] { typeof(System.Byte), typeof(System.String) }, ProviderCmdlet = \"Get-Content\")] [System.Management.Automation.OutputTypeAttribute(typeof(System.IO.FileInfo), ProviderCmdlet = \"Get-Item\")] [System.Management.Automation.OutputTypeAttribute(new Type[2] { typeof(System.IO.FileInfo), typeof(System.IO.DirectoryInfo) }, ProviderCmdlet = \"Get-ChildItem\")] [System.Management.Automation.OutputTypeAttribute(new Type[2] { typeof(System.Security.AccessControl.FileSecurity), typeof(System.Security.AccessControl.DirectorySecurity) }, ProviderCmdlet = \"Get-Acl\")] [System.Management.Automation.OutputTypeAttribute(new Type[4] { typeof(System.Boolean), typeof(System.String), typeof(System.IO.FileInfo), typeof(System.IO.DirectoryInfo) }, ProviderCmdlet = \"Get-Item\")] [System.Management.Automation.OutputTypeAttribute(new Type[5] { typeof(System.Boolean), typeof(System.String), typeof(System.DateTime), typeof(System.IO.FileInfo), typeof(System.IO.DirectoryInfo) }, ProviderCmdlet = \"Get-ItemProperty\")] [System.Management.Automation.OutputTypeAttribute(new Type[2] { typeof(System.String), typeof(System.IO.FileInfo) }, ProviderCmdlet = \"New-Item\")]"},"HelpFile":"System.Management.Automation.dll-Help.xml","Name":"FileSystem","PSSnapIn":{"Name":"Microsoft.PowerShell.Core","IsDefault":true,"ApplicationBase":"C:\\Windows\\System32\\WindowsPowerShell\\v1.0","AssemblyName":"System.Management.Automation, Version=3.0.0.0, Culture=neutral, PublicKeyToken=31bf3856ad364e35, ProcessorArchitecture=MSIL","ModuleName":"C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\System.Management.Automation.dll","PSVersion":"5.1.19041.6456","Version":"3.0.0.0","Types":"types.ps1xml typesv3.ps1xml","Formats":"Certificate.format.ps1xml DotNetTypes.format.ps1xml FileSystem.format.ps1xml Help.format.ps1xml HelpV3.format.ps1xml PowerShellCore.format.ps1xml PowerShellTrace.format.ps1xml Registry.format.ps1xml","Description":"","Vendor":"","LogPipelineExecutionDetails":false},"ModuleName":"Microsoft.PowerShell.Core","Module":null,"Description":"","Capabilities":52,"Home":"C:\\Users\\user","Drives":["C","D","G","E","F","P","W"]},"ReadCount":1};
    document.head.appendChild(style);
  }());
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

}
