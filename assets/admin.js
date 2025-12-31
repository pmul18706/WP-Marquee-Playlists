jQuery(function($){
  const elList = $('#wmp-playlist-list');
  const elEditor = $('#wmp-editor');
  const page = (WMP && WMP.page) ? WMP.page : (elEditor.attr('data-page') || 'items');
  const initialPlaylistId = (WMP && WMP.playlist_id) ? WMP.playlist_id : '';

  function nowLocalISO(){
    const d = new Date();
    const pad = n => String(n).padStart(2,'0');
    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }

  function escapeHtml(s){
    return String(s || '').replace(/[&<>"']/g, m => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[m]));
  }

  function filenameFromUrl(url){
    try{
      const u = new URL(url, window.location.origin);
      const path = u.pathname || '';
      const base = path.split('/').filter(Boolean).pop() || '';
      return decodeURIComponent(base);
    } catch(e){
      const parts = String(url || '').split('?')[0].split('#')[0].split('/');
      return decodeURIComponent(parts.pop() || '');
    }
  }

  function typeLabel(type){
    return (type === 'video') ? 'Video' : 'Image / GIF';
  }

  function defaultPlaylist(){
    return {
      id: '',
      token: '',
      name: '',
      mode: 'marquee',
      direction: 'left',
      speed: 60,
      pause_on_hover: 1,
      num_windows: 1,
      windows: [{x:0,y:0,width:800,height:200,fit:'contain'}],
      stage_bg: '',
      item_gap: 18,
      items: []
    };
  }

  function parsePlaylists(){
    const raw = elList.attr('data-playlists') || '[]';
    try { return JSON.parse(raw); } catch(e){ return []; }
  }

  function setPlaylists(pls){
    elList.attr('data-playlists', JSON.stringify(pls));
    renderPlaylistList(pls);
  }

  function findPlaylistById(id){
    const playlists = parsePlaylists();
    return playlists.find(x => String(x.id) === String(id)) || null;
  }

  function previewUrl(pl){
    return (pl && pl.token) ? (WMP.site_url.replace(/\/$/,'') + '/simp/' + pl.token + '/') : '';
  }

  function adminItemsUrl(id){
    const base = 'admin.php?page=wmp_playlists_items';
    return id ? (base + '&playlist_id=' + encodeURIComponent(id)) : base;
  }
  function adminOptionsUrl(id){
    const base = 'admin.php?page=wmp_playlists_options';
    return id ? (base + '&playlist_id=' + encodeURIComponent(id)) : base;
  }

  function renderPlaylistList(playlists){
    const html = [];
    html.push('<div class="wmp-playlists">');
    if (!playlists.length){
      html.push('<div style="color:#666;">No playlists yet.</div>');
    } else {
      playlists.forEach(pl => {
        const purl = previewUrl(pl);
        html.push(`
          <div class="wmp-pl" data-id="${escapeHtml(pl.id)}">
            <div class="title">${escapeHtml(pl.name || 'Untitled')}</div>
            <div class="sub">Mode: <b>${escapeHtml(pl.mode || 'marquee')}</b> • Windows: <b>${escapeHtml(pl.num_windows || 1)}</b></div>
            <div class="buttons">
              <a class="button button-secondary" href="${escapeHtml(adminItemsUrl(pl.id))}">Items</a>
              <a class="button button-secondary" href="${escapeHtml(adminOptionsUrl(pl.id))}">Options</a>
              ${purl ? `<a class="button button-secondary" target="_blank" href="${escapeHtml(purl)}">Preview</a>` : ''}
              ${purl ? `<button class="button button-secondary wmp-copy">Copy Link</button>` : ''}
              <button class="button button-link-delete wmp-delete">Delete</button>
            </div>
            ${purl ? `<div class="sub">Share URL: <code>${escapeHtml(purl)}</code></div>` : ''}
          </div>
        `);
      });
    }
    html.push('</div>');
    elList.html(html.join(''));
  }

  function normalizeWindows(p){
    p.num_windows = Math.max(1, Math.min(12, parseInt(p.num_windows || 1, 10)));
    if (!Array.isArray(p.windows)) p.windows = [];
    if (p.windows.length === 0) p.windows = [{x:0,y:0,width:800,height:200,fit:'contain'}];

    while (p.windows.length < p.num_windows){
      const prev = p.windows[p.windows.length-1] || {x:0,y:0,width:800,height:200,fit:'contain'};
      const h = parseInt(prev.height || 200, 10);
      p.windows.push({x:0, y:(p.windows.length*(h+10)), width:800, height:h, fit:(prev.fit||'contain')});
    }
    p.windows = p.windows.slice(0, p.num_windows);

    const allowed = new Set(['contain','fill','cover','stretch']);
    p.windows = p.windows.map(w => {
      const fit = allowed.has(String(w.fit||'contain')) ? String(w.fit) : 'contain';
      return {
        x: Math.max(0, Math.min(8000, parseInt(w.x || 0, 10))),
        y: Math.max(0, Math.min(8000, parseInt(w.y || 0, 10))),
        width: Math.max(100, Math.min(3000, parseInt(w.width || 800, 10))),
        height: Math.max(50, Math.min(2000, parseInt(w.height || 200, 10))),
        fit
      };
    });

    if (typeof p.item_gap === 'undefined') p.item_gap = 18;
    p.item_gap = Math.max(0, Math.min(200, parseInt(p.item_gap || 18, 10)));

    if (typeof p.stage_bg === 'undefined') p.stage_bg = '';

    return p;
  }

  function renderItemsEditor(pl){
    const p = normalizeWindows(pl ? JSON.parse(JSON.stringify(pl)) : defaultPlaylist());

    const html = [];
    html.push(`<h2>Playlist Items</h2>`);
    html.push(`<div class="wmp-help">Items + schedule + window targeting. Display settings live on the Options page.</div>`);

    html.push(`
      <div class="wmp-row">
        <label>Playlist Name</label>
        <input type="text" id="wmp_name" value="${escapeHtml(p.name)}" placeholder="e.g. Lobby Screens" />
      </div>

      <div class="wmp-row">
        <label>Add Media</label>
        <button class="button button-primary" id="wmp_add_media">Add Image/GIF/Video</button>
      </div>

      <div class="wmp-items">
        <div id="wmp_items"></div>
      </div>

      <div class="wmp-actions">
        <button class="button button-primary" id="wmp_save">Save Items</button>
        <a class="button button-secondary" href="${escapeHtml(adminOptionsUrl(p.id || ''))}">Go to Options</a>
        <button class="button button-secondary" id="wmp_new">New Playlist</button>
        <button class="button button-secondary" id="wmp_preview" ${p.token ? '' : 'disabled'}>Preview</button>
      </div>

      <input type="hidden" id="wmp_id" value="${escapeHtml(p.id || '')}" />
      <input type="hidden" id="wmp_token" value="${escapeHtml(p.token || '')}" />
    `);

    elEditor.html(html.join(''));
    renderItems(p);

    $('#wmp_new').on('click', function(e){
      e.preventDefault();
      renderItemsEditor(defaultPlaylist());
    });

    $('#wmp_preview').on('click', function(e){
      e.preventDefault();
      const token = $('#wmp_token').val();
      if (!token) return;
      window.open(WMP.site_url.replace(/\/$/,'') + '/simp/' + token + '/', '_blank');
    });

    $('#wmp_save').on('click', function(e){
      e.preventDefault();
      const payload = collectEditorItemsOnly();
      savePlaylist(payload);
    });

    $('#wmp_add_media').on('click', function(e){
      e.preventDefault();
      openAddMediaPicker();
    });
  }

  function renderOptionsEditor(pl){
    const p = normalizeWindows(pl ? JSON.parse(JSON.stringify(pl)) : defaultPlaylist());
    const isStatic = (p.mode === 'static');
    const isMarquee = !isStatic;

    const html = [];
    html.push(`<h2>Playlist Options</h2>`);
    html.push(`<div class="wmp-help">Layout + behavior + appearance. Items live on the Items page.</div>`);

    html.push(`
      <div class="wmp-row">
        <label>Playlist Name</label>
        <input type="text" id="wmp_name" value="${escapeHtml(p.name)}" />
      </div>

      <div class="wmp-row">
        <label>Mode</label>
        <select id="wmp_mode">
          <option value="marquee" ${p.mode==='marquee'?'selected':''}>Marquee (scroll)</option>
          <option value="static" ${p.mode==='static'?'selected':''}>Static (no scroll)</option>
        </select>
      </div>

      <div id="wmp_marquee_opts" class="${isMarquee ? '' : 'wmp-hidden'}">
        <div class="wmp-row">
          <label>Direction</label>
          <select id="wmp_direction">
            <option value="left" ${p.direction==='left'?'selected':''}>Left</option>
            <option value="right" ${p.direction==='right'?'selected':''}>Right</option>
          </select>
        </div>

        <div class="wmp-row">
          <label>Speed (px/sec)</label>
          <input type="number" id="wmp_speed" min="10" max="500" value="${escapeHtml(p.speed)}" />
        </div>

        <div class="wmp-row">
          <label>Pause on hover</label>
          <input type="checkbox" id="wmp_pause" ${p.pause_on_hover ? 'checked':''} />
        </div>
      </div>

      <div id="wmp_static_opts" class="${isStatic ? '' : 'wmp-hidden'}">
        <div class="wmp-help">Static mode rotation is controlled by per-item duration (on Items page).</div>
      </div>

      <hr />

      <div class="wmp-row">
        <label>Front Background</label>
        <input type="text" id="wmp_stage_bg" value="${escapeHtml(p.stage_bg || '')}" placeholder="#000000 (blank = transparent)" />
      </div>
      <div class="wmp-help">Hex only (#000 or #000000). Leave blank for transparent.</div>

      <div class="wmp-row">
        <label>Spacing Between Items</label>
        <input type="number" id="wmp_item_gap" min="0" max="200" value="${escapeHtml(p.item_gap)}" />
      </div>

      <hr />

      <div class="wmp-row">
        <label>Number of Windows</label>
        <input type="number" id="wmp_num_windows" min="1" max="12" value="${escapeHtml(p.num_windows)}" />
      </div>

      <div id="wmp_windows"></div>

      <div class="wmp-actions">
        <button class="button button-primary" id="wmp_save">Save Options</button>
        <a class="button button-secondary" href="${escapeHtml(adminItemsUrl(p.id || ''))}">Go to Items</a>
        <button class="button button-secondary" id="wmp_preview" ${p.token ? '' : 'disabled'}>Preview</button>
      </div>

      <input type="hidden" id="wmp_id" value="${escapeHtml(p.id || '')}" />
      <input type="hidden" id="wmp_token" value="${escapeHtml(p.token || '')}" />
    `);

    elEditor.html(html.join(''));

    renderWindows(p);

    $('#wmp_mode').on('change', function(){
      const v = $(this).val();
      $('#wmp_marquee_opts').toggleClass('wmp-hidden', v !== 'marquee');
      $('#wmp_static_opts').toggleClass('wmp-hidden', v !== 'static');
    });

    $('#wmp_num_windows').on('change', function(){
      const n = Math.max(1, Math.min(12, parseInt($(this).val() || '1', 10)));
      $(this).val(n);

      const current = collectEditorOptionsOnly();
      current.num_windows = n;
      normalizeWindows(current);
      renderWindows(current);
    });

    $('#wmp_preview').on('click', function(e){
      e.preventDefault();
      const token = $('#wmp_token').val();
      if (!token) return;
      window.open(WMP.site_url.replace(/\/$/,'') + '/simp/' + token + '/', '_blank');
    });

    $('#wmp_save').on('click', function(e){
      e.preventDefault();
      const payload = collectEditorOptionsOnly();
      savePlaylist(payload);
    });
  }

  function renderWindows(p){
    const wrap = $('#wmp_windows');
    const html = [];
    for (let i=0;i<p.num_windows;i++){
      const w = p.windows[i] || {x:0,y:0,width:800,height:200,fit:'contain'};
      html.push(`
        <div class="wmp-window-box" data-w="${i+1}">
          <h3>Window ${i+1}</h3>
          <div class="wmp-window-grid">
            <div><label>X</label><input type="number" class="wmp_wx" value="${escapeHtml(w.x)}" min="0" max="8000"></div>
            <div><label>Y</label><input type="number" class="wmp_wy" value="${escapeHtml(w.y)}" min="0" max="8000"></div>
            <div><label>Width</label><input type="number" class="wmp_ww" value="${escapeHtml(w.width)}" min="100" max="3000"></div>
            <div><label>Height</label><input type="number" class="wmp_wh" value="${escapeHtml(w.height)}" min="50" max="2000"></div>
            <div style="grid-column:1 / span 2;">
              <label>Fit Mode</label>
              <select class="wmp_fit">
                <option value="contain" ${w.fit==='contain'?'selected':''}>Contain (no crop)</option>
                <option value="fill" ${w.fit==='fill'?'selected':''}>Fill (letterbox, no crop)</option>
                <option value="cover" ${w.fit==='cover'?'selected':''}>Cover (fill, crop)</option>
                <option value="stretch" ${w.fit==='stretch'?'selected':''}>Stretch (distort)</option>
              </select>
            </div>
          </div>
        </div>
      `);
    }
    wrap.html(html.join(''));
  }

  function windowCheckboxes(num, selected){
    const sel = new Set(Array.isArray(selected) ? selected.map(n=>parseInt(n,10)) : []);
    let out = '<div style="display:flex;gap:10px;flex-wrap:wrap;">';
    out += `<label style="font-size:12px;color:#374151;"><input type="checkbox" class="wmp_win_all" ${sel.size===0?'checked':''}> All</label>`;
    for (let i=1;i<=num;i++){
      out += `<label style="font-size:12px;color:#374151;"><input type="checkbox" class="wmp_win" value="${i}" ${sel.has(i)?'checked':''}> W${i}</label>`;
    }
    out += '</div>';
    return out;
  }

  function renderItems(p){
    const wrap = $('#wmp_items');
    if (!wrap.length) return;

    const numW = Math.max(1, Math.min(12, parseInt(p.num_windows || 1, 10)));
    const items = Array.isArray(p.items) ? p.items : [];

    const html = [];
    if (!items.length){
      html.push('<div style="color:#666;">No items yet.</div>');
    } else {
      html.push('<div id="wmp_sortable">');
      items.forEach((it) => {
        const isVideo = (it.type === 'video');
        const url = it.url || '';
        const fname = filenameFromUrl(url) || '(no filename)';
        const tlabel = typeLabel(it.type);

        const thumbInner = isVideo
          ? `<video src="${escapeHtml(url)}" muted playsinline></video>`
          : `<img src="${escapeHtml(url)}" alt="">`;

        html.push(`
          <div class="wmp-item-row" data-id="${escapeHtml(it.id)}">

            <div class="thumb">
              <a href="#" class="wmp-change-media" title="Click to replace media">${thumbInner}</a>
            </div>

            <div class="meta">
              <div class="wmp-item-header">
                <div class="wmp-fileblock">
                  <div class="filename" title="${escapeHtml(fname)}">${escapeHtml(fname)}</div>
                  <div class="filehint">Click thumbnail to replace • URL stored internally</div>
                </div>
                <div class="wmp-pill">
                  <span style="opacity:.7;">Type:</span>
                  <input class="wmp_read_type wmp-readonly" type="text" value="${escapeHtml(tlabel)}" readonly style="border:none;padding:0;background:transparent;width:auto;max-width:120px;">
                </div>
              </div>

              <div class="wmp-fields">
                <div class="wmp-field">
                  <label>Start date/time</label>
                  <input type="datetime-local" class="wmp_start" value="${escapeHtml(it.start || '')}" />
                </div>

                <div class="wmp-field">
                  <label>End date/time</label>
                  <input type="datetime-local" class="wmp_end" value="${escapeHtml(it.end || '')}" />
                </div>

                <div class="wmp-field">
                  <label>Duration (sec)</label>
                  <input type="number" class="wmp_duration" value="${escapeHtml(it.duration || 0)}" min="0" max="86400" />
                </div>

                <div class="wmp-field">
                  <label>Type (info)</label>
                  <input type="text" class="wmp_type_display wmp-readonly" value="${escapeHtml(tlabel)}" readonly />
                </div>
              </div>

              <!-- Keep URL & type as hidden fields for saving -->
              <input type="hidden" class="wmp_url" value="${escapeHtml(url)}" />
              <input type="hidden" class="wmp_type" value="${escapeHtml(isVideo ? 'video' : 'image')}" />

              <div class="wmp-item-sub">
                <div class="wmp-winpick-title">Show in windows</div>
                <div class="wmp_windows_pick">${windowCheckboxes(numW, it.windows)}</div>
              </div>
            </div>

            <div class="controls">
              <div class="drag">drag</div>
              <button class="button button-secondary wmp-up">Move up</button>
              <button class="button button-secondary wmp-down">Move down</button>
              <button class="button button-link-delete wmp-remove">Remove</button>
            </div>

          </div>
        `);
      });
      html.push('</div>');
    }

    wrap.html(html.join(''));
    $('#wmp_sortable').sortable({ handle: '.drag' });

    wrap.off('click.wmp').on('click.wmp', '.wmp-up', function(e){
      e.preventDefault();
      const row = $(this).closest('.wmp-item-row');
      row.prev('.wmp-item-row').before(row);
    });
    wrap.on('click.wmp', '.wmp-down', function(e){
      e.preventDefault();
      const row = $(this).closest('.wmp-item-row');
      row.next('.wmp-item-row').after(row);
    });

    wrap.on('click.wmp', '.wmp-remove', function(e){
      e.preventDefault();
      $(this).closest('.wmp-item-row').remove();
    });

    // window targeting toggles
    wrap.on('change.wmp', '.wmp_win_all', function(){
      const row = $(this).closest('.wmp-item-row');
      if ($(this).is(':checked')){
        row.find('.wmp_win').prop('checked', false);
      }
    });
    wrap.on('change.wmp', '.wmp_win', function(){
      const row = $(this).closest('.wmp-item-row');
      if ($(this).is(':checked')){
        row.find('.wmp_win_all').prop('checked', false);
      }
      const any = row.find('.wmp_win:checked').length > 0;
      if (!any) row.find('.wmp_win_all').prop('checked', true);
    });

    // click thumbnail to replace media
    wrap.on('click.wmp', '.wmp-change-media', function(e){
      e.preventDefault();
      const row = $(this).closest('.wmp-item-row');
      openReplaceMediaPicker(row);
    });
  }

  function openAddMediaPicker(){
    const frame = wp.media({
      title: 'Select media',
      button: { text: 'Add to playlist' },
      multiple: true,
      library: { type: ['image','video'] }
    });

    frame.on('select', function(){
      const selection = frame.state().get('selection').toJSON();
      const p2 = collectEditorAll();
      selection.forEach(att => {
        const url = att.url || '';
        if (!url) return;
        const isVideo = (att.type === 'video');
        p2.items.push({
          id: 'it_' + Math.random().toString(16).slice(2),
          url,
          type: isVideo ? 'video' : 'image',
          start: nowLocalISO(),
          end: '',
          duration: 0,
          windows: []
        });
      });
      p2.items.forEach((it, idx) => it.order = idx+1);
      renderItems(p2);
    });

    frame.open();
  }

  function openReplaceMediaPicker(row){
    const frame = wp.media({
      title: 'Replace media',
      button: { text: 'Use this media' },
      multiple: false,
      library: { type: ['image','video'] }
    });

    frame.on('select', function(){
      const att = frame.state().get('selection').first().toJSON();
      const url = att.url || '';
      if (!url) return;
      const isVideo = (att.type === 'video');

      row.find('.wmp_url').val(url);
      row.find('.wmp_type').val(isVideo ? 'video' : 'image');
      row.find('.wmp_type_display').val(typeLabel(isVideo ? 'video' : 'image'));
      row.find('.wmp_read_type').val(typeLabel(isVideo ? 'video' : 'image'));

      // update filename display
      const fname = filenameFromUrl(url) || '(no filename)';
      row.find('.filename').text(fname).attr('title', fname);

      // update thumb
      const thumb = row.find('.thumb a.wmp-change-media');
      const inner = isVideo
        ? `<video src="${escapeHtml(url)}" muted playsinline></video>`
        : `<img src="${escapeHtml(url)}" alt="">`;
      thumb.html(inner);
    });

    frame.open();
  }

  function collectEditorAll(){
    const p = defaultPlaylist();
    p.id = $('#wmp_id').val() || '';
    p.token = $('#wmp_token').val() || '';
    p.name = $('#wmp_name').val() || '';

    if ($('#wmp_mode').length){
      p.mode = ($('#wmp_mode').val() === 'static') ? 'static' : 'marquee';
    }
    if ($('#wmp_direction').length){
      p.direction = ($('#wmp_direction').val() === 'right') ? 'right' : 'left';
    }
    if ($('#wmp_speed').length){
      p.speed = parseFloat($('#wmp_speed').val() || '60');
    }
    if ($('#wmp_pause').length){
      p.pause_on_hover = $('#wmp_pause').is(':checked') ? 1 : 0;
    }
    if ($('#wmp_num_windows').length){
      p.num_windows = Math.max(1, Math.min(12, parseInt($('#wmp_num_windows').val() || '1', 10)));
    }
    if ($('#wmp_stage_bg').length){
      p.stage_bg = $('#wmp_stage_bg').val() || '';
    }
    if ($('#wmp_item_gap').length){
      p.item_gap = parseInt($('#wmp_item_gap').val() || '18', 10);
    }

    if ($('#wmp_windows').length && $('#wmp_windows .wmp-window-box').length){
      p.windows = [];
      $('#wmp_windows .wmp-window-box').each(function(){
        p.windows.push({
          x: parseInt($(this).find('.wmp_wx').val() || '0', 10),
          y: parseInt($(this).find('.wmp_wy').val() || '0', 10),
          width: parseInt($(this).find('.wmp_ww').val() || '800', 10),
          height: parseInt($(this).find('.wmp_wh').val() || '200', 10),
          fit: $(this).find('.wmp_fit').val() || 'contain'
        });
      });
    }

    p.items = [];
    $('#wmp_items .wmp-item-row').each(function(idx){
      const row = $(this);
      const id = row.attr('data-id') || ('it_' + Math.random().toString(16).slice(2));
      const url = row.find('.wmp_url').val() || '';
      const type = (row.find('.wmp_type').val() === 'video') ? 'video' : 'image';
      const start = row.find('.wmp_start').val() || '';
      const end = row.find('.wmp_end').val() || '';
      const duration = parseInt(row.find('.wmp_duration').val() || '0', 10);

      let wins = [];
      const all = row.find('.wmp_win_all').is(':checked');
      if (!all){
        row.find('.wmp_win:checked').each(function(){
          wins.push(parseInt($(this).val(),10));
        });
      } else {
        wins = [];
      }

      p.items.push({ id, url, type, start, end, duration, windows: wins, order: idx+1 });
    });

    return normalizeWindows(p);
  }

  function collectEditorItemsOnly(){
    const existing = findPlaylistById($('#wmp_id').val() || '') || defaultPlaylist();
    const p = normalizeWindows(JSON.parse(JSON.stringify(existing)));

    p.id = $('#wmp_id').val() || '';
    p.token = $('#wmp_token').val() || '';
    p.name = $('#wmp_name').val() || p.name || '';

    p.items = [];
    $('#wmp_items .wmp-item-row').each(function(idx){
      const row = $(this);
      const id = row.attr('data-id') || ('it_' + Math.random().toString(16).slice(2));
      const url = row.find('.wmp_url').val() || '';
      const type = (row.find('.wmp_type').val() === 'video') ? 'video' : 'image';
      const start = row.find('.wmp_start').val() || '';
      const end = row.find('.wmp_end').val() || '';
      const duration = parseInt(row.find('.wmp_duration').val() || '0', 10);

      let wins = [];
      const all = row.find('.wmp_win_all').is(':checked');
      if (!all){
        row.find('.wmp_win:checked').each(function(){
          wins.push(parseInt($(this).val(),10));
        });
      } else {
        wins = [];
      }

      p.items.push({ id, url, type, start, end, duration, windows: wins, order: idx+1 });
    });

    return normalizeWindows(p);
  }

  function collectEditorOptionsOnly(){
    const existing = findPlaylistById($('#wmp_id').val() || '') || defaultPlaylist();
    const p = normalizeWindows(JSON.parse(JSON.stringify(existing)));

    p.id = $('#wmp_id').val() || '';
    p.token = $('#wmp_token').val() || '';
    p.name = $('#wmp_name').val() || p.name || '';

    p.mode = ($('#wmp_mode').val() === 'static') ? 'static' : 'marquee';
    p.direction = ($('#wmp_direction').val() === 'right') ? 'right' : 'left';
    p.speed = parseFloat($('#wmp_speed').val() || '60');
    p.pause_on_hover = $('#wmp_pause').is(':checked') ? 1 : 0;

    p.stage_bg = $('#wmp_stage_bg').val() || '';
    p.item_gap = parseInt($('#wmp_item_gap').val() || '18', 10);

    p.num_windows = Math.max(1, Math.min(12, parseInt($('#wmp_num_windows').val() || '1', 10)));

    p.windows = [];
    $('#wmp_windows .wmp-window-box').each(function(){
      p.windows.push({
        x: parseInt($(this).find('.wmp_wx').val() || '0', 10),
        y: parseInt($(this).find('.wmp_wy').val() || '0', 10),
        width: parseInt($(this).find('.wmp_ww').val() || '800', 10),
        height: parseInt($(this).find('.wmp_wh').val() || '200', 10),
        fit: $(this).find('.wmp_fit').val() || 'contain'
      });
    });

    return normalizeWindows(p);
  }

  function savePlaylist(payload){
    $.post(WMP.ajax_url, {
      action: 'wmp_save_playlist',
      nonce: WMP.nonce,
      playlist: JSON.stringify(payload)
    }).done(resp => {
      if (!resp || !resp.success){
        alert((resp && resp.data) ? resp.data : 'Save failed');
        return;
      }
      const pl2 = resp.data.playlist;
      const pls = resp.data.playlists;
      setPlaylists(pls);

      if (page === 'options') renderOptionsEditor(pl2);
      else renderItemsEditor(pl2);

      alert('Saved.');
    }).fail(() => alert('Save failed'));
  }

  // Right-side list actions
  elList.on('click', '.wmp-copy', function(e){
    e.preventDefault();
    const id = $(this).closest('.wmp-pl').attr('data-id');
    const pl = findPlaylistById(id);
    const url = previewUrl(pl);
    if (!url) return;
    navigator.clipboard.writeText(url).then(()=>alert('Copied link.'));
  });

  elList.on('click', '.wmp-delete', function(e){
    e.preventDefault();
    const id = $(this).closest('.wmp-pl').attr('data-id');
    if (!confirm('Delete this playlist?')) return;

    $.post(WMP.ajax_url, { action:'wmp_delete_playlist', nonce: WMP.nonce, id })
      .done(resp => {
        if (!resp || !resp.success){
          alert((resp && resp.data) ? resp.data : 'Delete failed');
          return;
        }
        setPlaylists(resp.data.playlists);
        if (page === 'options') renderOptionsEditor(defaultPlaylist());
        else renderItemsEditor(defaultPlaylist());
      })
      .fail(()=>alert('Delete failed'));
  });

  // Init
  const playlists = parsePlaylists();
  renderPlaylistList(playlists);

  let initial = null;
  if (initialPlaylistId) initial = findPlaylistById(initialPlaylistId);
  if (!initial && playlists.length) initial = playlists[0];

  if (page === 'options') renderOptionsEditor(initial || defaultPlaylist());
  else renderItemsEditor(initial || defaultPlaylist());
});
