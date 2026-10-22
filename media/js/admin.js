/**
 * @package     Webmarka.Plugin
 * @subpackage  System.contentimageupload
 *
 * @copyright   (C) 2026 Beibit Sakhanov <https://webmarka.kz>
 * @copyright   Based on Content Image Upload 1.6, (C) 2026 Хабар
 * @license     GNU General Public License version 2 or later; see LICENSE
 *
 * Article editor panel: upload, image options and a reversible watermark.
 *
 * Image sync. With sync on, the panel keeps the intro and full text images one picture:
 * it writes the image and its options into both fields, and a change in either field is
 * copied into the other. With sync off, the panel works with the intro image only; the
 * full text image is chosen in the native field, which then gets its own watermark box.
 * On opening an article the switch follows the data: on when both fields hold the same
 * image, off when the images differ or one field is empty.
 *
 * Watermark boxes show what the image file really carries. Each box sends two inputs
 * (a hidden «0» and the box «1») of the same name; while the state of a newly chosen
 * image is being checked, the inputs lose their name, and the server leaves files alone.
 */
((document, Joomla) => {
  'use strict';

  if (!Joomla || typeof Joomla.getOptions !== 'function') {
    return;
  }

  const options = Joomla.getOptions('plgSystemContentImageUpload', {});
  const labels = options.labels || {};
  const selectors = {
    intro: options.fields?.intro || '#jform_images_image_intro',
    fulltext: options.fields?.fulltext || '#jform_images_image_fulltext',
    category: options.fields?.category || '#jform_catid',
  };
  const syncMode = ['always', 'on', 'off'].includes(options.imageSync) ? options.imageSync : 'on';
  const state = { sync: true };

  /** Image options of the two images: panel input, intro field, full text field. */
  const META = [
    { panel: 'ciu-image-alt', intro: '#jform_images_image_intro_alt', full: '#jform_images_image_fulltext_alt', type: 'text' },
    { panel: 'ciu-image-alt-empty', intro: '#jform_images_image_intro_alt_empty', full: '#jform_images_image_fulltext_alt_empty', type: 'checkbox' },
    { panel: 'ciu-image-class', intro: '#jform_images_imgclass_intro', full: '#jform_images_imgclass_fulltext', type: 'text' },
    { panel: 'ciu-image-figure-class', intro: '#jform_images_float_intro', full: '#jform_images_float_fulltext', type: 'text' },
    { panel: 'ciu-image-caption', intro: '#jform_images_image_intro_caption', full: '#jform_images_image_fulltext_caption', type: 'text' },
  ];

  let mainWatermark = null;
  let fullWatermark = null;

  function query(selector) {
    return selector ? document.querySelector(selector) : null;
  }

  function introField() {
    return query(selectors.intro);
  }

  function fulltextField() {
    return query(selectors.fulltext);
  }

  function cleanPath(value) {
    const path = String(value || '').split('#', 1)[0].trim().replace(/^\/+/, '');

    try {
      return decodeURI(path);
    } catch (error) {
      return path;
    }
  }

  /** Watermark states known so far, by clean path: from the server at page load, then from answers. */
  const knownStates = new Map();

  (options.watermarkStates || []).forEach((item) => {
    if (item && item.path) {
      knownStates.set(cleanPath(item.path), item);
    }
  });

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>'"]/g, (character) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      "'": '&#039;',
      '"': '&quot;',
    })[character]);
  }

  function extractPayload(json) {
    if (!json) {
      return null;
    }

    if (Array.isArray(json.data)) {
      return json.data.length ? json.data[0] : null;
    }

    return json.data && typeof json.data === 'object' ? json.data : json;
  }

  async function post(fields) {
    const body = new FormData();

    Object.entries(fields).forEach(([key, value]) => body.append(key, value));
    body.append(options.token, '1');

    const response = await fetch(options.ajaxUrl, {
      method: 'POST',
      body,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });

    let json;

    try {
      json = await response.json();
    } catch (error) {
      throw new Error(labels.error || '');
    }

    const payload = extractPayload(json);

    if (!response.ok || !payload || payload.success !== true) {
      throw new Error(payload?.message || json?.message || labels.error || '');
    }

    return payload;
  }

  function getSafeImageUrl(value) {
    if (!value) {
      return '';
    }

    try {
      const url = new URL(value, document.baseURI);

      return ['http:', 'https:'].includes(url.protocol) && url.origin === window.location.origin ? url.href : '';
    } catch (error) {
      return '';
    }
  }

  function getMediaPreviewUrl(value, explicitUrl = '') {
    if (explicitUrl) {
      return getSafeImageUrl(explicitUrl);
    }

    const path = String(value || '').split('#', 1)[0];

    if (!path) {
      return '';
    }

    try {
      return getSafeImageUrl(new URL(path, options.rootUrl || document.baseURI).href);
    } catch (error) {
      return '';
    }
  }

  function renderPreview(preview, url) {
    if (!preview) {
      return;
    }

    const safeUrl = getSafeImageUrl(url);

    if (!safeUrl) {
      const placeholder = document.createElement('span');
      placeholder.className = 'field-media-preview-icon';
      preview.replaceChildren(placeholder);

      return;
    }

    const image = document.createElement('img');
    image.src = safeUrl;
    image.alt = '';
    image.loading = 'lazy';
    image.decoding = 'async';
    preview.replaceChildren(image);
  }

  function setMediaField(input, path, url) {
    if (!input) {
      return;
    }

    const mediaElement = input.closest('joomla-field-media');
    const value = path || '';

    if (mediaElement && typeof mediaElement.setValue === 'function') {
      if (!value) {
        mediaElement.querySelector('.button-clear')?.click();
      }

      mediaElement.setValue(value);
    } else {
      input.value = value;
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    input.value = value;
    input.setAttribute('value', value);

    renderPreview(mediaElement?.querySelector('.field-media-preview'), getMediaPreviewUrl(value, url));
  }

  /* ----------------------------------------------------------------- targets */

  /** The image the panel works with: the intro image, or the full text image while synced and there is no intro image. */
  function panelValue() {
    const intro = introField()?.value || '';

    return state.sync ? intro || fulltextField()?.value || '' : intro;
  }

  /** A full text image of its own: only with sync off and only when it differs from the intro image. */
  function separateFullValue() {
    if (state.sync) {
      return '';
    }

    const full = fulltextField()?.value || '';

    return full && cleanPath(full) !== cleanPath(introField()?.value || '') ? full : '';
  }

  /** Puts an image into the panel: the intro field, and the full text field while synced. */
  function setPanelImage(value, url = '') {
    setMediaField(introField(), value, url);

    if (state.sync) {
      setMediaField(fulltextField(), value, url);
    }
  }

  function updatePanelPreview() {
    const value = panelValue();
    const path = cleanPath(value);
    const known = mainWatermark?.state;
    const url = known && path && path === known.path ? known.url : '';

    renderPreview(document.getElementById('ciu-main-preview'), getMediaPreviewUrl(value, url));

    const pathBox = document.getElementById('ciu-main-path');

    if (pathBox) {
      pathBox.value = path;
    }
  }

  /**
   * Gives the image fields the value Joomla itself writes (path#joomlaImage://…?width=…&height=…),
   * so the site prints width, height and loading="lazy". Old plain paths get upgraded too.
   */
  function normaliseFields(path, value, url) {
    if (!value || cleanPath(value) !== path) {
      return;
    }

    [introField(), fulltextField()].forEach((field) => {
      if (field && cleanPath(field.value) === path && field.value !== value) {
        setMediaField(field, value, url);
      }
    });
  }

  /* --------------------------------------------------------------- watermark */

  /**
   * One watermark box: the panel box for the panel image, or the box under the native
   * full text field for a separate full text image.
   */
  function createWatermark(prefix, getValue, { hideWhenEmpty = false, onKnown = null } = {}) {
    const wm = { path: '', applied: false, url: '', request: 0, checking: false };

    const elements = () => ({
      group: document.getElementById(`${prefix}-group`),
      box: document.getElementById(prefix),
      value: document.getElementById(`${prefix}-value`),
      note: document.getElementById(`${prefix}-note`),
    });

    /** Inputs without a name are not submitted, and the server then leaves the file alone. */
    function setSubmitted(submitted) {
      const { box, value } = elements();

      [value, box].forEach((element) => {
        if (!element) {
          return;
        }

        if (submitted) {
          element.name = element.dataset.name;
        } else {
          element.removeAttribute('name');
        }
      });
    }

    function render(message = '') {
      const { box, note } = elements();

      if (!box || !note) {
        return;
      }

      let text = message;
      let pending = false;

      if (!text) {
        if (wm.applied === null) {
          text = labels.watermarkChecking || '';
        } else if (!wm.path) {
          text = box.checked ? labels.watermarkOnUpload || '' : '';
        } else if (box.checked === wm.applied) {
          text = wm.applied ? labels.watermarkOn || '' : labels.watermarkOff || '';
        } else {
          text = box.checked ? labels.watermarkWillApply || '' : labels.watermarkWillRemove || '';
          pending = true;
        }
      }

      note.textContent = text;
      note.classList.toggle('ciu-pending', pending);
    }

    function setKnown(path, applied, url = '') {
      const { box } = elements();

      wm.path = path;
      wm.applied = applied;
      wm.url = url;

      if (box) {
        box.disabled = false;
        box.checked = applied;
      }

      setSubmitted(true);
      render();

      if (onKnown) {
        onKnown();
      }
    }

    function setUnknown(path, message = '') {
      const { box } = elements();

      wm.path = path;
      wm.applied = null;
      wm.url = '';

      if (box) {
        box.disabled = true;
      }

      setSubmitted(false);
      render(message);
    }

    /** The state is already known, e.g. from an upload answer: no request needed. */
    function adopt(path, applied, url, value) {
      wm.request += 1;
      wm.checking = false;
      knownStates.set(path, { path, value, watermarked: applied, url });
      setKnown(path, applied, url);
    }

    /** Shows the real state of the target image, asking the server when it is not known. */
    async function refresh() {
      const { group, box } = elements();

      if (!box) {
        return;
      }

      const value = getValue();
      const path = cleanPath(value);

      if (group) {
        group.hidden = hideWhenEmpty && !path;
      }

      if (!path) {
        wm.request += 1;
        wm.checking = false;
        setKnown('', false);

        if (hideWhenEmpty) {
          setSubmitted(false);
        }

        return;
      }

      if (path === wm.path && (wm.applied !== null || wm.checking)) {
        return;
      }

      const cached = knownStates.get(path);

      if (cached) {
        wm.request += 1;
        wm.checking = false;
        setKnown(path, cached.watermarked === true, cached.url || '');
        normaliseFields(path, cached.value, cached.url);

        return;
      }

      const requestId = ++wm.request;

      setUnknown(path);
      wm.checking = true;

      try {
        const payload = await post({ ciuAction: 'status', path: value });

        if (requestId === wm.request) {
          wm.checking = false;
          knownStates.set(path, {
            path,
            value: payload.value,
            watermarked: payload.watermarked === true,
            url: payload.url,
          });
          setKnown(path, payload.watermarked === true, payload.url || '');
          normaliseFields(path, payload.value, payload.url);
        }
      } catch (error) {
        if (requestId === wm.request) {
          wm.checking = false;
          setUnknown(path, labels.watermarkUnknown || '');
        }
      }
    }

    const { box } = elements();

    box?.addEventListener('change', () => render());

    return { refresh, adopt, render, elements, state: wm };
  }

  function refreshAll() {
    updatePanelPreview();
    mainWatermark?.refresh();
    fullWatermark?.refresh();
  }

  /* ----------------------------------------------------------- image options */

  function readField(field, type) {
    if (!field) {
      return type === 'checkbox' ? false : '';
    }

    return type === 'checkbox' ? Boolean(field.checked) : field.value || '';
  }

  /** Writes only when the value differs, so fields copying into each other never loop. */
  function writeField(field, type, value) {
    if (!field) {
      return;
    }

    if (type === 'checkbox') {
      if (field.checked !== Boolean(value)) {
        field.checked = Boolean(value);
        field.dispatchEvent(new Event('change', { bubbles: true }));
      }

      return;
    }

    const text = value ?? '';

    if (field.value !== text) {
      field.value = text;
      field.setAttribute('value', text);
      field.dispatchEvent(new Event('input', { bubbles: true }));
      field.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  /** Panel shows the options of the intro image (of the full text image while synced and there is no intro image). */
  function fillPanelOptions() {
    const fromFull = state.sync && !introField()?.value && Boolean(fulltextField()?.value);

    META.forEach((pair) => {
      const panel = document.getElementById(pair.panel);
      const source = query(fromFull ? pair.full : pair.intro) || query(pair.intro) || query(pair.full);

      if (!panel) {
        return;
      }

      if (pair.type === 'checkbox') {
        panel.checked = readField(source, pair.type);
      } else {
        panel.value = readField(source, pair.type);
      }
    });
  }

  function copyOptions(fromIntro) {
    META.forEach((pair) => {
      const source = query(fromIntro ? pair.intro : pair.full);
      const target = query(fromIntro ? pair.full : pair.intro);

      writeField(target, pair.type, readField(source, pair.type));
    });
  }

  function bindOptions() {
    META.forEach((pair) => {
      const panel = document.getElementById(pair.panel);
      const intro = query(pair.intro);
      const full = query(pair.full);

      if (!panel || panel.dataset.ciuBound === '1') {
        return;
      }

      panel.dataset.ciuBound = '1';

      const fromPanel = () => {
        const value = readField(panel, pair.type);

        writeField(intro, pair.type, value);

        if (state.sync) {
          writeField(full, pair.type, value);
        }

        // «No description» empties the description.
        if (pair.type === 'checkbox' && value) {
          const alt = META[0];
          const altPanel = document.getElementById(alt.panel);

          if (altPanel) {
            altPanel.value = '';
          }

          writeField(query(alt.intro), 'text', '');

          if (state.sync) {
            writeField(query(alt.full), 'text', '');
          }
        }
      };

      panel.addEventListener(pair.type === 'checkbox' ? 'change' : 'input', fromPanel);

      if (pair.type !== 'checkbox') {
        panel.addEventListener('change', fromPanel);
      }

      const nativeEvents = pair.type === 'checkbox' ? ['change'] : ['input', 'change'];

      // Intro image options always show in the panel; full text options only while synced.
      nativeEvents.forEach((eventName) => {
        intro?.addEventListener(eventName, () => {
          writeField(panel, pair.type, readField(intro, pair.type));
        });

        full?.addEventListener(eventName, () => {
          if (state.sync) {
            writeField(panel, pair.type, readField(full, pair.type));
          }
        });
      });
    });
  }

  /* ------------------------------------------------------- native fields */

  function setElementHidden(element, hidden, key = 'ciuOriginalDisplay') {
    if (!element) {
      return;
    }

    if (hidden) {
      if (element.dataset[key] === undefined) {
        element.dataset[key] = element.style.display || '';
      }

      element.style.display = 'none';
      element.setAttribute('aria-hidden', 'true');
    } else {
      element.style.display = element.dataset[key] || '';
      element.removeAttribute('aria-hidden');
    }
  }

  function findImagesPane() {
    return document.getElementById('images');
  }

  function hideNativeSeparateFullImageControl() {
    const markers = [
      'separate full article image',
      'отдельное изображение полного текста',
      'бөлек толық мәтін суреті',
      'толық мәтіннің бөлек суреті',
    ];

    findImagesPane()?.querySelectorAll('label').forEach((label) => {
      const text = String(label.textContent || '').trim().toLowerCase();

      if (text && markers.some((marker) => text.includes(marker))) {
        setElementHidden(label.closest('.control-group') || label.parentElement, true, 'ciuSeparateFullImageDisplay');
      }
    });
  }

  function fieldsetOf(field) {
    return field?.closest('fieldset') || field?.closest('.control-group') || field?.parentElement;
  }

  /** The intro fieldset hides with the toggle; the full text fieldset only while synced, otherwise it is where the full image is chosen. */
  function applyFieldVisibility() {
    const hide = document.getElementById('ciu-hide-original-image-fields')?.checked ?? true;

    setElementHidden(fieldsetOf(introField()), hide);
    setElementHidden(fieldsetOf(fulltextField()), hide && state.sync);
    hideNativeSeparateFullImageControl();
  }

  function setLinkFieldsetsHidden(hidden) {
    ['fieldset-linka', 'fieldset-linkb', 'fieldset-linkc'].forEach((id) => {
      setElementHidden(document.getElementById(id), hidden, 'ciuLinkFieldsetDisplay');
    });
  }

  /* ---------------------------------------------------------------- sync */

  /** On opening: one image in both fields means synced; different images or one empty field mean not. */
  function getInitialSync() {
    if (syncMode === 'always') {
      return true;
    }

    const intro = cleanPath(introField()?.value);
    const full = cleanPath(fulltextField()?.value);

    if (!intro && !full) {
      return syncMode !== 'off';
    }

    return intro === full;
  }

  function setSync(on, { align = false } = {}) {
    state.sync = on;

    const hidden = document.getElementById('ciu-sync-value');
    const toggle = document.getElementById('ciu-sync');

    if (hidden) {
      hidden.value = on ? '1' : '0';
    }

    if (toggle) {
      toggle.checked = on;
    }

    // Turning sync on makes the full text image follow the intro image (or the other way round without an intro image).
    if (on && align) {
      const intro = introField();
      const full = fulltextField();
      const fromIntro = Boolean(intro?.value) || !full?.value;
      const source = fromIntro ? intro : full;
      const target = fromIntro ? full : intro;

      if (source && target && target.value !== source.value) {
        setMediaField(target, source.value, '');
      }

      copyOptions(fromIntro);
    }

    applyFieldVisibility();
    fillPanelOptions();
    refreshAll();
  }

  /** A native image field changed: while synced, the changed field wins and the other follows. */
  function onNativeChange(event) {
    const changed = event?.currentTarget;
    const status = document.getElementById('ciu-main-status');

    // A message about an earlier upload no longer describes the image in the panel.
    if (status) {
      status.className = 'form-text';
      status.textContent = '';
    }

    if (state.sync && changed) {
      const other = changed === introField() ? fulltextField() : introField();

      if (other && other.value !== changed.value) {
        setMediaField(other, changed.value, '');
      }
    }

    refreshAll();
  }

  /* --------------------------------------------------------------- panel */

  function renderSyncControl() {
    const hiddenInput = '<input type="hidden" name="ciu_sync" id="ciu-sync-value" value="1">';

    if (syncMode === 'always') {
      return hiddenInput;
    }

    return `
      ${hiddenInput}
      <div class="control-group" id="ciu-sync-group">
        <div class="control-label">
          <label for="ciu-sync">${escapeHtml(labels.syncSwitch)}</label>
        </div>
        <div class="controls">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="ciu-sync">
          </div>
          <div class="form-text">${escapeHtml(labels.syncHelp)}</div>
        </div>
      </div>`;
  }

  function renderWatermarkControl(prefix, help = '') {
    return `
      <div class="control-group" id="${prefix}-group">
        <div class="control-label">
          <label for="${prefix}">${escapeHtml(labels.watermark)}</label>
        </div>
        <div class="controls">
          <input type="hidden" id="${prefix}-value" value="0" data-name="${prefix.replace(/-/g, '_')}">
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" id="${prefix}" value="1" data-name="${prefix.replace(/-/g, '_')}" disabled>
          </div>
          <div class="form-text" id="${prefix}-note" aria-live="polite"></div>
          ${help ? `<div class="form-text">${escapeHtml(help)}</div>` : ''}
        </div>
      </div>`;
  }

  /** The box for a separate full text image sits under the native full text field. */
  function createFullWatermarkControl() {
    if (!options.watermarkEnabled || syncMode === 'always' || document.getElementById('ciu-watermark-full-group')) {
      return;
    }

    const anchor = fulltextField()?.closest('.control-group');

    if (!anchor) {
      return;
    }

    const holder = document.createElement('div');

    holder.innerHTML = renderWatermarkControl('ciu-watermark-full');

    const group = holder.firstElementChild;

    group.hidden = true;
    anchor.after(group);
  }

  function createPanel() {
    if (document.getElementById('ciu-main-image-panel')) {
      return true;
    }

    const imagesPane = findImagesPane();

    if (!imagesPane) {
      return false;
    }

    const panel = document.createElement('div');

    panel.id = 'ciu-main-image-panel';
    panel.className = 'row g-4';
    panel.innerHTML = `
      <div class="col-12 col-lg-6">
        <fieldset class="options-form ciu-dropzone" id="ciu-dropzone">
          <legend>${escapeHtml(labels.singleTitle)}</legend>
          <div>
            ${renderSyncControl()}
            ${options.watermarkEnabled ? renderWatermarkControl('ciu-watermark', labels.watermarkHelp) : ''}
            <div class="control-group">
              <div class="control-label">
                <label for="ciu-main-path">${escapeHtml(labels.imageLabel)}</label>
              </div>
              <div class="controls">
                <div class="field-media-preview" id="ciu-main-preview"><span class="field-media-preview-icon"></span></div>
                <input type="text" class="form-control field-media-input ciu-path" id="ciu-main-path" value="" readonly placeholder="${escapeHtml(labels.imagePathPlaceholder)}">
                <div class="ciu-actions">
                  <button type="button" class="btn btn-success" id="ciu-main-upload-btn">
                    <span class="icon-upload" aria-hidden="true"></span>
                    <span>${escapeHtml(labels.singleButton)}</span>
                  </button>
                  <button type="button" class="btn btn-primary" id="ciu-media-select-btn">
                    <span class="icon-images" aria-hidden="true"></span>
                    <span>${escapeHtml(labels.select)}</span>
                  </button>
                  <button type="button" class="btn btn-outline-danger" id="ciu-clear-btn">
                    <span class="icon-times" aria-hidden="true"></span>
                    <span>${escapeHtml(labels.clear)}</span>
                  </button>
                </div>
                <div class="form-text" id="ciu-drop-hint">${escapeHtml(labels.dropHint)}</div>
                <input type="file" id="ciu-main-file" accept="${escapeHtml(options.accept || 'image/*')}" class="visually-hidden">
                <div class="form-text" id="ciu-main-status" aria-live="polite"></div>
              </div>
            </div>
          </div>
        </fieldset>
      </div>
      <div class="col-12 col-lg-6">
        <fieldset class="options-form">
          <legend>${escapeHtml(labels.imageOptions)}</legend>
          <div class="ciu-image-options">
            <div class="control-group">
              <div class="control-label"><label for="ciu-image-alt">${escapeHtml(labels.imageAlt)}</label></div>
              <div class="controls"><input type="text" class="form-control" id="ciu-image-alt" value=""></div>
            </div>
            <div class="control-group">
              <div class="control-label"><label for="ciu-image-alt-empty">${escapeHtml(labels.imageAltEmpty)}</label></div>
              <div class="controls"><div class="form-check"><input class="form-check-input" type="checkbox" id="ciu-image-alt-empty"></div></div>
            </div>
            <div class="control-group">
              <div class="control-label"><label for="ciu-image-class">${escapeHtml(labels.imageClass)}</label></div>
              <div class="controls"><input type="text" class="form-control" id="ciu-image-class" value="" placeholder="${escapeHtml(getGlobalPlaceholder(options.globalImageClass))}"></div>
            </div>
            <div class="control-group">
              <div class="control-label"><label for="ciu-image-figure-class">${escapeHtml(labels.imageFigureClass)}</label></div>
              <div class="controls"><input type="text" class="form-control" id="ciu-image-figure-class" value="" placeholder="${escapeHtml(getGlobalPlaceholder(options.globalFigureClass))}"></div>
            </div>
            <div class="control-group">
              <div class="control-label"><label for="ciu-image-caption">${escapeHtml(labels.imageCaption)}</label></div>
              <div class="controls"><input type="text" class="form-control" id="ciu-image-caption" value=""></div>
            </div>
            <div class="control-group">
              <div class="control-label"><label for="ciu-hide-original-image-fields">${escapeHtml(labels.hideOriginalImageFields)}</label></div>
              <div class="controls"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="ciu-hide-original-image-fields" checked></div></div>
            </div>
            <div class="control-group">
              <div class="control-label"><label for="ciu-hide-link-fieldsets">${escapeHtml(labels.hideLinkFieldsets)}</label></div>
              <div class="controls"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="ciu-hide-link-fieldsets" checked></div></div>
            </div>
          </div>
        </fieldset>
      </div>`;

    imagesPane.prepend(panel);
    createFullWatermarkControl();

    mainWatermark = createWatermark('ciu-watermark', panelValue, { onKnown: updatePanelPreview });
    fullWatermark = createWatermark('ciu-watermark-full', separateFullValue, { hideWhenEmpty: true });

    bindUploader();
    bindOptions();
    bindToggles();
    setSync(getInitialSync());

    return true;
  }

  function getGlobalPlaceholder(values) {
    const globalValue = String(values?.intro || '').trim() || String(values?.fulltext || '').trim();
    const placeholder = labels.imageClassPlaceholder || '';

    return globalValue ? `${placeholder} (${globalValue})` : placeholder;
  }

  function bindToggles() {
    const imageToggle = document.getElementById('ciu-hide-original-image-fields');
    const linksToggle = document.getElementById('ciu-hide-link-fieldsets');
    const syncToggle = document.getElementById('ciu-sync');

    imageToggle?.addEventListener('change', applyFieldVisibility);

    if (linksToggle) {
      setLinkFieldsetsHidden(linksToggle.checked);
      linksToggle.addEventListener('change', () => setLinkFieldsetsHidden(linksToggle.checked));
    }

    syncToggle?.addEventListener('change', () => setSync(syncToggle.checked, { align: syncToggle.checked }));
  }

  function bindUploader() {
    const button = document.getElementById('ciu-main-upload-btn');
    const file = document.getElementById('ciu-main-file');
    const clearButton = document.getElementById('ciu-clear-btn');
    const selectButton = document.getElementById('ciu-media-select-btn');
    const status = document.getElementById('ciu-main-status');

    const showStatus = (message, type = '') => {
      if (status) {
        status.className = `form-text${type ? ` text-${type}` : ''}`;
        status.textContent = message;
      }
    };

    introField()?.addEventListener('change', onNativeChange);
    fulltextField()?.addEventListener('change', onNativeChange);

    button.addEventListener('click', () => file.click());

    // «Select» presses the native button of the intro field: Joomla's own dialog writes the value.
    selectButton?.addEventListener('click', () => {
      const mediaElement = introField()?.closest('joomla-field-media');
      const nativeSelect = mediaElement?.querySelector('.button-select');

      if (!nativeSelect) {
        showStatus(labels.noMediaField || '', 'danger');

        return;
      }

      nativeSelect.click();

      let attempts = 0;
      const timer = window.setInterval(() => {
        attempts += 1;

        if (mediaElement.dialog) {
          window.clearInterval(timer);
          mediaElement.dialog.addEventListener('joomla-dialog:close', () => onNativeChange({ currentTarget: introField() }), { once: true });
        } else if (attempts >= 30) {
          window.clearInterval(timer);
        }
      }, 100);
    });

    clearButton?.addEventListener('click', () => {
      setPanelImage('', '');
      refreshAll();
      showStatus(labels.imageCleared || '');
    });

    const uploadFile = async (selected) => {
      if (!selected || button.disabled) {
        return;
      }

      if (!isAcceptedFile(selected)) {
        showStatus(labels.fileRejected || labels.error || '', 'danger');

        return;
      }

      if (!options.ajaxUrl || !options.token) {
        showStatus(labels.error || '', 'danger');
        file.value = '';

        return;
      }

      const box = mainWatermark?.elements().box;
      const wantWatermark = Boolean(options.watermarkEnabled && box?.checked);
      const buttonLabel = button.querySelector('span:last-child');
      const buttonText = buttonLabel?.textContent || '';

      button.disabled = true;

      if (buttonLabel) {
        buttonLabel.textContent = labels.uploading || '';
      }
      showStatus(`${labels.uploadingFile || ''}: ${selected.name}`);

      try {
        const payload = await post({
          ciuAction: 'upload',
          image: selected,
          watermark: wantWatermark ? '1' : '0',
          articleId: String(Number.parseInt(options.articleId || '0', 10) || 0),
          categoryId: String(Number.parseInt(query(selectors.category)?.value || '0', 10) || 0),
        });

        if (!payload.path || !payload.url) {
          throw new Error(labels.error || '');
        }

        // Known before the fields change, so their change events do not ask the server again.
        mainWatermark?.adopt(payload.path, payload.watermarked === true, payload.url, payload.value || payload.path);

        if (wantWatermark && payload.watermarked !== true && box) {
          box.checked = true;
          mainWatermark.render();
        }

        setPanelImage(payload.value || payload.path, payload.url);
        refreshAll();

        const size = payload.width && payload.height ? ` (${payload.width}×${payload.height})` : '';

        showStatus(
          [`${labels.success || ''}${size}.`, payload.warning || ''].filter(Boolean).join(' '),
          payload.warning ? 'warning' : 'success'
        );
      } catch (error) {
        showStatus(error?.message || labels.error || '', 'danger');
      } finally {
        button.disabled = false;

        if (buttonLabel) {
          buttonLabel.textContent = buttonText;
        }

        file.value = '';
      }
    };

    file.addEventListener('change', () => uploadFile(file.files?.[0]));
    bindDropZone(uploadFile);
  }

  /* --------------------------------------------------------- drag and drop */

  function hasFiles(event) {
    return Array.from(event.dataTransfer?.types || []).includes('Files');
  }

  /** Same formats as the file dialog: the accept list from the server. */
  function isAcceptedFile(selected) {
    const accept = String(options.accept || '')
      .split(',')
      .map((item) => item.trim().toLowerCase())
      .filter((item) => item.startsWith('.'));

    if (!accept.length) {
      return /^image\//.test(selected.type || '');
    }

    const name = String(selected.name || '').toLowerCase();

    return accept.some((extension) => name.endsWith(extension));
  }

  /**
   * A file dropped on the left half of the panel is uploaded like one chosen with the button.
   * Elsewhere on the page a dropped file is ignored instead of opening in the tab and losing the article.
   */
  function bindDropZone(uploadFile) {
    const zone = document.getElementById('ciu-dropzone');

    if (!zone || zone.dataset.ciuDrop === '1') {
      return;
    }

    zone.dataset.ciuDrop = '1';

    let depth = 0;
    const setActive = (active) => zone.classList.toggle('ciu-dragover', active);

    zone.addEventListener('dragenter', (event) => {
      if (!hasFiles(event)) {
        return;
      }

      event.preventDefault();
      depth += 1;
      setActive(true);
    });

    zone.addEventListener('dragover', (event) => {
      if (!hasFiles(event)) {
        return;
      }

      event.preventDefault();
      event.dataTransfer.dropEffect = 'copy';
    });

    zone.addEventListener('dragleave', () => {
      depth = Math.max(0, depth - 1);

      if (depth === 0) {
        setActive(false);
      }
    });

    zone.addEventListener('drop', (event) => {
      if (!hasFiles(event)) {
        return;
      }

      event.preventDefault();
      depth = 0;
      setActive(false);
      uploadFile(event.dataTransfer.files?.[0]);
    });

    ['dragover', 'drop'].forEach((eventName) => {
      document.addEventListener(eventName, (event) => {
        if (hasFiles(event) && !event.defaultPrevented && !zone.contains(event.target)) {
          event.preventDefault();

          if (eventName === 'dragover') {
            event.dataTransfer.dropEffect = 'none';
          }
        }
      });
    });
  }

  function boot() {
    if (createPanel()) {
      return;
    }

    let attempts = 0;
    const timer = window.setInterval(() => {
      attempts += 1;

      if (createPanel() || attempts > 30) {
        window.clearInterval(timer);
      }
    }, 300);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})(document, window.Joomla);
