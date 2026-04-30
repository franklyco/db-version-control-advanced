(function () {
  const state = {
    session: null,
    activeNode: null,
    activeDescriptor: null,
    activeController: null,
    activeRequiresSharedScopeAck: false,
    activeAcknowledgementType: 'none',
    sharedScopeAcknowledged: false,
    descriptorCache: {},
    descriptorRequests: {},
    badgeLayer: null,
    badgeNode: null,
    badgeLayoutFrame: 0,
    badgeEventsBound: false,
    previewNode: null,
    badgeHideTimeout: 0,
    prefetchTimeout: 0,
    prefetchToken: '',
    touchSelectionToken: '',
    touchSuppressToken: '',
    touchClickSuppressUntil: 0
  };

  function strings() {
    return (window.DBVCVisualEditorBootstrap && window.DBVCVisualEditorBootstrap.strings) || {};
  }

  function supportsWpMedia() {
    return Boolean(
      window.DBVCVisualEditorBootstrap
        && window.DBVCVisualEditorBootstrap.supportsWpMedia
        && window.wp
        && typeof window.wp.media === 'function'
    );
  }

  function clonePayload(payload) {
    return payload ? JSON.parse(JSON.stringify(payload)) : null;
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function cacheDescriptorPayload(payload) {
    if (!payload || !payload.descriptor || !payload.descriptor.token) {
      return;
    }

    state.descriptorCache[payload.descriptor.token] = clonePayload(payload);
  }

  function cacheDescriptorHydrations(hydrations) {
    if (!hydrations || typeof hydrations !== 'object') {
      return;
    }

    Object.keys(hydrations).forEach(function (token) {
      cacheDescriptorPayload(hydrations[token]);
    });
  }

  function getCachedDescriptorPayload(token) {
    if (!token || !state.descriptorCache[token]) {
      return null;
    }

    return clonePayload(state.descriptorCache[token]);
  }

  function getMarkerToken(node) {
    return node && typeof node.getAttribute === 'function'
      ? String(node.getAttribute('data-dbvc-ve') || '')
      : '';
  }

  function getSessionId() {
    return state.session && typeof state.session.sessionId === 'string'
      ? state.session.sessionId
      : '';
  }

  function getSessionDescriptorSummary(token) {
    if (!token
      || !state.session
      || !state.session.descriptors
      || typeof state.session.descriptors !== 'object'
      || !state.session.descriptors[token]
      || typeof state.session.descriptors[token] !== 'object') {
      return null;
    }

    return state.session.descriptors[token];
  }

  function clearDescriptorPrefetch() {
    if (state.prefetchTimeout) {
      window.clearTimeout(state.prefetchTimeout);
      state.prefetchTimeout = 0;
    }

    state.prefetchToken = '';
  }

  function loadDescriptorPayload(sessionId, token) {
    const cached = getCachedDescriptorPayload(token);

    if (cached && cached.ok && cached.descriptor) {
      return Promise.resolve(cached);
    }

    if (!sessionId || !token) {
      return Promise.reject(new Error(strings().descriptorMissing || 'Descriptor not found.'));
    }

    if (state.descriptorRequests[token]) {
      return state.descriptorRequests[token].then(clonePayload);
    }

    state.descriptorRequests[token] = window.DBVCVisualEditorApi.getDescriptor(sessionId, token)
      .then(function (result) {
        if (!result || !result.ok || !result.descriptor) {
          throw new Error(strings().descriptorMissing || 'Descriptor not found.');
        }

        cacheDescriptorPayload(result);

        return result;
      })
      .finally(function () {
        delete state.descriptorRequests[token];
      });

    return state.descriptorRequests[token].then(clonePayload);
  }

  function scheduleDescriptorPrefetch(node) {
    const token = getMarkerToken(node);
    const sessionId = getSessionId();

    clearDescriptorPrefetch();

    if (!node || !token || !sessionId) {
      return;
    }

    if (getCachedDescriptorPayload(token) || state.descriptorRequests[token]) {
      return;
    }

    state.prefetchToken = token;
    state.prefetchTimeout = window.setTimeout(function () {
      state.prefetchTimeout = 0;

      if (state.prefetchToken !== token) {
        return;
      }

      loadDescriptorPayload(sessionId, token).catch(function () {});
    }, 180);
  }

  function getDescriptorRenderContext(descriptor, node) {
    if (descriptor && descriptor.render && typeof descriptor.render.context === 'string' && descriptor.render.context) {
      return descriptor.render.context;
    }

    if (node && node.dataset && typeof node.dataset.dbvcVeContext === 'string' && node.dataset.dbvcVeContext) {
      return node.dataset.dbvcVeContext;
    }

    return 'text';
  }

  function getDescriptorRenderAttribute(descriptor, node) {
    if (descriptor && descriptor.render && typeof descriptor.render.attribute === 'string' && descriptor.render.attribute) {
      return descriptor.render.attribute;
    }

    if (node && node.dataset && typeof node.dataset.dbvcVeAttribute === 'string' && node.dataset.dbvcVeAttribute) {
      return node.dataset.dbvcVeAttribute;
    }

    return '';
  }

  function lookupDescriptorForNode(node) {
    if (!node) {
      return null;
    }

    const token = node.getAttribute('data-dbvc-ve');

    if (state.activeDescriptor && state.activeDescriptor.token === token) {
      return state.activeDescriptor;
    }

    const cached = getCachedDescriptorPayload(token);

    if (cached && cached.descriptor) {
      return cached.descriptor;
    }

    return getSessionDescriptorSummary(token);
  }

  function getDescriptorDisplayKey(descriptor) {
    return descriptor
      && descriptor.render
      && typeof descriptor.render.display_key === 'string'
      && descriptor.render.display_key
      ? descriptor.render.display_key
      : 'default';
  }

  function getDescriptorMediaSize(descriptor) {
    return descriptor
      && descriptor.source
      && typeof descriptor.source.media_size === 'string'
      && descriptor.source.media_size
      ? descriptor.source.media_size
      : '';
  }

  function isMediaRenderContext(context) {
    return context === 'image_src' || context === 'background_image';
  }

  function getDescriptorSourceGroup(descriptor, node) {
    if (descriptor && descriptor.render && typeof descriptor.render.source_group === 'string' && descriptor.render.source_group) {
      return descriptor.render.source_group;
    }

    if (node && node.dataset && typeof node.dataset.dbvcVeSourceGroup === 'string' && node.dataset.dbvcVeSourceGroup) {
      return node.dataset.dbvcVeSourceGroup;
    }

    return '';
  }

  function getDescriptorStatus(descriptor, node) {
    if (descriptor && typeof descriptor.status === 'string' && descriptor.status) {
      return descriptor.status;
    }

    if (node && node.dataset && typeof node.dataset.dbvcVeStatus === 'string' && node.dataset.dbvcVeStatus) {
      return node.dataset.dbvcVeStatus;
    }

    return 'editable';
  }

  function getDescriptorScope(descriptor, node) {
    if (descriptor && typeof descriptor.scope === 'string' && descriptor.scope) {
      return descriptor.scope;
    }

    if (!node || !node.dataset || typeof node.dataset.dbvcVeScope !== 'string') {
      return 'current_entity';
    }

    if (node.dataset.dbvcVeScope === 'shared') {
      return 'shared_entity';
    }

    if (node.dataset.dbvcVeScope === 'related') {
      return 'related_entity';
    }

    return 'current_entity';
  }

  function getDescriptorEntityType(descriptor) {
    return descriptor
      && descriptor.entity
      && typeof descriptor.entity.type === 'string'
      && descriptor.entity.type
      ? descriptor.entity.type
      : '';
  }

  function resolveRelatedBadgeLabel(entityType) {
    if (entityType === 'term') {
      return strings().badgeRelatedTerm || 'Related Term';
    }

    if (entityType === 'user') {
      return strings().badgeRelatedUser || 'Related User';
    }

    if (entityType === 'option') {
      return strings().badgeRelatedOption || 'Related Option';
    }

    if (entityType === 'post') {
      return strings().badgeRelated || 'Related Post';
    }

    return strings().badgeRelatedGeneric || 'Related';
  }

  function resolveSharedBadgeLabel(entityType) {
    if (entityType === 'term') {
      return strings().badgeSharedTerm || 'Shared Term';
    }

    if (entityType === 'user') {
      return strings().badgeSharedUser || 'Shared User';
    }

    if (entityType === 'option') {
      return strings().badgeSharedOption || 'Shared Option';
    }

    if (entityType === 'post') {
      return strings().badgeSharedPost || 'Shared Post';
    }

    return strings().badgeShared || strings().badgeSharedGeneric || 'Shared';
  }

  function resolveRelatedSaveLabel(entityType) {
    if (entityType === 'term') {
      return strings().panelRelatedScopeSaveTerm || 'Save related term';
    }

    if (entityType === 'user') {
      return strings().panelRelatedScopeSaveUser || 'Save related user';
    }

    if (entityType === 'option') {
      return strings().panelRelatedScopeSaveOption || 'Save related option';
    }

    if (entityType === 'post') {
      return strings().panelRelatedScopeSave || 'Save related post';
    }

    return strings().panelRelatedScopeSaveGeneric || 'Save related item';
  }

  function resolveSharedSaveLabel(entityType) {
    if (entityType === 'term') {
      return strings().panelSharedScopeSaveTerm || 'Save shared term';
    }

    if (entityType === 'user') {
      return strings().panelSharedScopeSaveUser || 'Save shared user';
    }

    if (entityType === 'option') {
      return strings().panelSharedScopeSaveOption || 'Save shared option';
    }

    if (entityType === 'post') {
      return strings().panelSharedScopeSavePost || 'Save shared post';
    }

    return strings().panelSharedScopeSave || strings().panelSharedScopeSaveGeneric || 'Save shared field';
  }

  function resolveRelatedAckText(entityType) {
    if (entityType === 'term') {
      return strings().panelRelatedScopeAckTerm || 'I understand this updates the related term shown in this Bricks query loop, not the current page.';
    }

    if (entityType === 'user') {
      return strings().panelRelatedScopeAckUser || 'I understand this updates the related user shown in this Bricks query loop, not the current page.';
    }

    if (entityType === 'option') {
      return strings().panelRelatedScopeAckOption || 'I understand this updates the related option source shown in this Bricks query loop, not the current page.';
    }

    if (entityType === 'post') {
      return strings().panelRelatedScopeAck || 'I understand this updates the related post shown in this Bricks query loop, not the current page.';
    }

    return strings().panelRelatedScopeAckGeneric || 'I understand this updates a related item shown in this Bricks query loop, not the current page.';
  }

  function resolveSharedAckText(entityType) {
    if (entityType === 'term') {
      return strings().panelSharedScopeAckTerm || 'I understand this updates a shared taxonomy term field and may affect other pages.';
    }

    if (entityType === 'user') {
      return strings().panelSharedScopeAckUser || 'I understand this updates a shared user field and may affect other pages.';
    }

    if (entityType === 'option') {
      return strings().panelSharedScopeAckOption || 'I understand this updates a shared Site Settings value and may affect other pages.';
    }

    if (entityType === 'post') {
      return strings().panelSharedScopeAckPost || 'I understand this updates a shared post-owned field and may affect other pages.';
    }

    return strings().panelSharedScopeAck || strings().panelSharedScopeAckGeneric || 'I understand this updates a shared field and may affect other pages.';
  }

  function resolveRelatedRequiredText(entityType) {
    if (entityType === 'term') {
      return strings().panelRelatedScopeRequiredTerm || 'Acknowledge the related-term warning before saving this field.';
    }

    if (entityType === 'user') {
      return strings().panelRelatedScopeRequiredUser || 'Acknowledge the related-user warning before saving this field.';
    }

    if (entityType === 'option') {
      return strings().panelRelatedScopeRequiredOption || 'Acknowledge the related-option warning before saving this field.';
    }

    if (entityType === 'post') {
      return strings().panelRelatedScopeRequired || 'Acknowledge the related-post warning before saving this field.';
    }

    return strings().panelRelatedScopeRequiredGeneric || 'Acknowledge the related-item warning before saving this field.';
  }

  function resolveSharedRequiredText(entityType) {
    if (entityType === 'term') {
      return strings().panelSharedScopeRequiredTerm || 'Acknowledge the shared-term warning before saving this field.';
    }

    if (entityType === 'user') {
      return strings().panelSharedScopeRequiredUser || 'Acknowledge the shared-user warning before saving this field.';
    }

    if (entityType === 'option') {
      return strings().panelSharedScopeRequiredOption || 'Acknowledge the shared-option warning before saving this field.';
    }

    if (entityType === 'post') {
      return strings().panelSharedScopeRequiredPost || 'Acknowledge the shared-post warning before saving this field.';
    }

    return strings().panelSharedScopeRequired || strings().panelSharedScopeRequiredGeneric || 'Acknowledge the shared scope warning before saving this field.';
  }

  function resolveScopeMetaLabel(scope, entityType) {
    if (scope === 'related_entity') {
      if (entityType === 'term') {
        return strings().panelScopeRelatedTerm || 'related term';
      }

      if (entityType === 'user') {
        return strings().panelScopeRelatedUser || 'related user';
      }

      if (entityType === 'option') {
        return strings().panelScopeRelatedOption || 'related option';
      }

      if (entityType === 'post') {
        return strings().panelScopeRelated || 'related post';
      }

      return strings().panelScopeRelatedGeneric || 'related item';
    }

    if (scope === 'shared_entity') {
      if (entityType === 'term') {
        return strings().panelScopeSharedTerm || 'shared term';
      }

      if (entityType === 'user') {
        return strings().panelScopeSharedUser || 'shared user';
      }

      if (entityType === 'option') {
        return strings().panelScopeSharedOption || 'shared option';
      }

      if (entityType === 'post') {
        return strings().panelScopeSharedPost || 'shared post';
      }

      return strings().panelScopeShared || strings().panelScopeSharedGeneric || 'shared target';
    }

    return '';
  }

  function normalizeProjection(candidate, fallbackValue, fallbackMode) {
    if (!candidate || typeof candidate !== 'object') {
      return {
        key: 'default',
        value: typeof fallbackValue === 'string' ? fallbackValue : '',
        mode: fallbackMode || 'text'
      };
    }

    return {
      key: typeof candidate.key === 'string' && candidate.key ? candidate.key : 'default',
      value: typeof candidate.value === 'string' ? candidate.value : (typeof fallbackValue === 'string' ? fallbackValue : ''),
      mode: typeof candidate.mode === 'string' && candidate.mode ? candidate.mode : (fallbackMode || 'text')
    };
  }

  function normalizeMediaReferenceValue(value) {
    if (!value || typeof value !== 'object') {
      return {};
    }

    const attachmentId = Number(value.attachmentId || value.id || 0) || 0;

    return Object.assign({}, value, {
      attachmentId,
      id: attachmentId
    });
  }

  function resolveMediaReferenceRenderData(value, descriptor) {
    const normalized = normalizeMediaReferenceValue(value);
    const renderAttributes = normalized.renderAttributes && typeof normalized.renderAttributes === 'object'
      ? normalized.renderAttributes
      : {};

    return {
      attachmentId: normalized.attachmentId || 0,
      src: String(renderAttributes.src || normalized.renderUrl || normalized.fullUrl || normalized.url || ''),
      srcset: String(renderAttributes.srcset || ''),
      sizes: String(renderAttributes.sizes || ''),
      fullUrl: String(normalized.fullUrl || normalized.url || ''),
      alt: String(normalized.alt || normalized.title || ''),
      title: String(normalized.title || ''),
      mediaSize: getDescriptorMediaSize(descriptor)
    };
  }

  function readComparableBackgroundImage(node) {
    if (!node) {
      return '';
    }

    const inlineStyle = node.getAttribute('style') || '';
    const inlineMatch = inlineStyle.match(/background(?:-image)?\s*:\s*url\((["']?)(.*?)\1\)/i);

    if (inlineMatch && inlineMatch[2]) {
      return normalizeValue(inlineMatch[2]);
    }

    if (window.getComputedStyle) {
      const computed = window.getComputedStyle(node).backgroundImage || '';
      const computedMatch = computed.match(/url\((["']?)(.*?)\1\)/i);

      if (computedMatch && computedMatch[2]) {
        return normalizeValue(computedMatch[2]);
      }
    }

    return '';
  }

  function resolveDisplayProjection(descriptor, saveResult) {
    const candidates = Array.isArray(saveResult && saveResult.displayCandidates) ? saveResult.displayCandidates : [];
    const displayKey = getDescriptorDisplayKey(descriptor);
    let match = null;

    if (candidates.length) {
      match = candidates.find(function (candidate) {
        return candidate && typeof candidate.key === 'string' && candidate.key === displayKey;
      }) || null;

      if (!match && displayKey === 'default') {
        match = candidates[0] || null;
      }
    }

    return normalizeProjection(match, saveResult && saveResult.displayValue, saveResult && saveResult.displayMode);
  }

  function updateCachedDescriptors(syncGroup, sourceGroup, saveResult) {
    const tokens = Object.keys(state.descriptorCache);
    const activeContext = getDescriptorRenderContext(state.activeDescriptor, state.activeNode);
    const activeMediaSize = getDescriptorMediaSize(state.activeDescriptor);

    tokens.forEach(function (token) {
      const payload = state.descriptorCache[token];
      const descriptor = payload && payload.descriptor ? payload.descriptor : null;
      const payloadGroup = descriptor
        && payload.descriptor
        && payload.descriptor.render
        && typeof payload.descriptor.render.sync_group === 'string'
        ? payload.descriptor.render.sync_group
        : '';
      const payloadSourceGroup = getDescriptorSourceGroup(descriptor);

      if (sourceGroup) {
        if (payloadSourceGroup !== sourceGroup) {
          return;
        }

        if (isMediaRenderContext(activeContext)
          && getDescriptorRenderContext(descriptor) === activeContext
          && getDescriptorMediaSize(descriptor) !== activeMediaSize) {
          return;
        }
      } else if (syncGroup) {
        if (payloadGroup !== syncGroup) {
          return;
        }
      } else if (token !== (state.activeDescriptor && state.activeDescriptor.token)) {
        return;
      }

      const projection = resolveDisplayProjection(descriptor, saveResult);

      payload.currentValue = saveResult.value;
      payload.displayValue = projection.value;
      payload.displayMode = projection.mode;

      if (saveResult && saveResult.entitySummary) {
        payload.entitySummary = clonePayload(saveResult.entitySummary);
      }

      if (saveResult && saveResult.sourceSummary) {
        payload.sourceSummary = clonePayload(saveResult.sourceSummary);
      }
    });
  }

  function normalizeValue(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
  }

  function extractTextFromHtml(value) {
    const wrapper = document.createElement('div');
    wrapper.innerHTML = typeof value === 'string' ? value : '';

    return normalizeValue(wrapper.textContent || '');
  }

  function extractDisplayText(displayValue, displayMode) {
    if ((displayMode || 'text') === 'html') {
      return extractTextFromHtml(displayValue);
    }

    return normalizeValue(displayValue);
  }

  function formatSaveSummary(saveResult) {
    const summary = saveResult && saveResult.saveSummary && typeof saveResult.saveSummary === 'object'
      ? saveResult.saveSummary
      : null;

    if (!summary) {
      return '';
    }

    const title = summary.title ? String(summary.title) : '';
    const detail = summary.detail ? String(summary.detail) : '';

    if (title && detail) {
      return `${title}. ${detail}`;
    }

    return title || detail || '';
  }

  function readNodeComparableValue(node, descriptor) {
    if (!node) {
      return '';
    }

    const context = getDescriptorRenderContext(descriptor, node);

    if (context === 'link_href') {
      const attribute = getDescriptorRenderAttribute(descriptor, node) || 'href';

      return normalizeValue(node.getAttribute(attribute) || '');
    }

    if (context === 'image_src') {
      const attribute = getDescriptorRenderAttribute(descriptor, node) || 'src';
      const imageNode = node.matches && node.matches('img') ? node : node.querySelector('img');

      return normalizeValue(imageNode ? (imageNode.getAttribute(attribute) || '') : '');
    }

    if (context === 'background_image') {
      return readComparableBackgroundImage(node);
    }

    return normalizeValue(node.textContent || '');
  }

  function getNodeDisplayValue(node, descriptor) {
    if (!node) {
      return '';
    }

    return typeof node.dataset.dbvcVeDisplayValue === 'string'
      ? node.dataset.dbvcVeDisplayValue
      : readNodeComparableValue(node, descriptor);
  }

  function hasSourceMismatch(node, result) {
    if (getDescriptorStatus(result && result.descriptor, node) !== 'editable') {
      return false;
    }

    const renderedValue = getNodeDisplayValue(node, result && result.descriptor);
    const displayValue = extractDisplayText(result.displayValue, result.displayMode);

    if (!renderedValue && !displayValue) {
      return false;
    }

    return normalizeValue(renderedValue) !== normalizeValue(displayValue);
  }

  function ensureStatusBar() {
    let bar = document.querySelector('.dbvc-ve-statusbar');

    if (bar) {
      return bar;
    }

    bar = document.createElement('div');
    bar.className = 'dbvc-ve-statusbar';
    bar.innerHTML = [
      '<div class="dbvc-ve-statusbar__title"></div>',
      '<div class="dbvc-ve-statusbar__meta"></div>',
      '<div class="dbvc-ve-statusbar__message"></div>'
    ].join('');

    document.body.appendChild(bar);

    return bar;
  }

  function ensureBadgeLayer() {
    if (state.badgeLayer && state.badgeLayer.isConnected) {
      return state.badgeLayer;
    }

    let layer = document.querySelector('.dbvc-ve-badge-layer');

    if (layer) {
      state.badgeLayer = layer;
      return layer;
    }

    layer = document.createElement('div');
    layer.className = 'dbvc-ve-badge-layer';
    document.body.appendChild(layer);
    state.badgeLayer = layer;

    return layer;
  }

  function ensureSharedBadge() {
    if (state.badgeNode && state.badgeNode.isConnected) {
      return state.badgeNode;
    }

    const badge = document.createElement('button');

    badge.type = 'button';
    badge.className = 'dbvc-ve-badge';
    badge.addEventListener('click', function (event) {
      const target = resolveBadgeTarget();

      event.preventDefault();
      event.stopPropagation();

      if (!target || !state.session) {
        return;
      }

      openEditor(target, state.session);
    });
    badge.addEventListener('mouseenter', clearBadgeHideTimeout);
    badge.addEventListener('focusin', clearBadgeHideTimeout);
    badge.addEventListener('mouseleave', function (event) {
      const relatedMarker = resolveMarkerNodeFromTarget(event.relatedTarget);

      if (relatedMarker) {
        setPreviewNode(relatedMarker);
        return;
      }

      if (state.activeNode) {
        state.previewNode = null;
        scheduleBadgeLayout();
        return;
      }

      scheduleBadgeHide();
    });
    badge.addEventListener('focusout', function (event) {
      if (isBadgeElement(event.relatedTarget)) {
        return;
      }

      if (resolveMarkerNodeFromTarget(event.relatedTarget)) {
        return;
      }

      if (state.activeNode) {
        state.previewNode = null;
        scheduleBadgeLayout();
        return;
      }

      scheduleBadgeHide();
    });

    ensureBadgeLayer().appendChild(badge);
    state.badgeNode = badge;

    return badge;
  }

  function bindBadgeEvents() {
    if (state.badgeEventsBound) {
      return;
    }

    state.badgeEventsBound = true;

    window.addEventListener('resize', scheduleBadgeLayout);
    window.addEventListener('scroll', scheduleBadgeLayout, true);
    document.addEventListener('mouseover', handleMarkerMouseOver, true);
    document.addEventListener('mouseout', handleMarkerMouseOut, true);
    document.addEventListener('focusin', handleMarkerFocusIn, true);
    document.addEventListener('focusout', handleMarkerFocusOut, true);
    document.addEventListener('pointerup', handleMarkerPointerUp, true);
    document.addEventListener('click', handleMarkerClick, true);
    document.addEventListener('keydown', handleBadgeKeydown, true);
  }

  function clearBadgeHideTimeout() {
    if (!state.badgeHideTimeout) {
      return;
    }

    window.clearTimeout(state.badgeHideTimeout);
    state.badgeHideTimeout = 0;
  }

  function scheduleBadgeHide() {
    clearBadgeHideTimeout();

    state.badgeHideTimeout = window.setTimeout(function () {
      state.badgeHideTimeout = 0;
      state.previewNode = null;
      clearDescriptorPrefetch();
      scheduleBadgeLayout();
    }, 90);
  }

  function resolveMarkerNodeFromTarget(target) {
    if (!target || typeof target.closest !== 'function') {
      return null;
    }

    return target.closest('[data-dbvc-ve]');
  }

  function isBadgeElement(target) {
    return Boolean(state.badgeNode && target && (state.badgeNode === target || state.badgeNode.contains(target)));
  }

  function setPreviewNode(node) {
    clearBadgeHideTimeout();
    state.previewNode = node && node.isConnected ? node : null;
    if (state.previewNode) {
      scheduleDescriptorPrefetch(state.previewNode);
    } else {
      clearDescriptorPrefetch();
    }
    scheduleBadgeLayout();
  }

  function handleMarkerMouseOver(event) {
    if (isBadgeElement(event.target)) {
      return;
    }

    const marker = resolveMarkerNodeFromTarget(event.target);

    if (!marker) {
      return;
    }

    if (state.activeNode && state.activeNode !== marker) {
      return;
    }

    setPreviewNode(marker);
  }

  function handleMarkerMouseOut(event) {
    if (isBadgeElement(event.target)) {
      return;
    }

    const marker = resolveMarkerNodeFromTarget(event.target);

    if (!marker) {
      return;
    }

    if (state.activeNode && state.activeNode !== marker) {
      return;
    }

    if (event.relatedTarget && marker.contains(event.relatedTarget)) {
      return;
    }

    if (isBadgeElement(event.relatedTarget)) {
      return;
    }

    if (state.previewNode === marker) {
      scheduleBadgeHide();
    }
  }

  function handleMarkerFocusIn(event) {
    if (isBadgeElement(event.target)) {
      return;
    }

    const marker = resolveMarkerNodeFromTarget(event.target);

    if (!marker) {
      return;
    }

    if (state.activeNode && state.activeNode !== marker) {
      return;
    }

    setPreviewNode(marker);
  }

  function handleMarkerFocusOut(event) {
    if (isBadgeElement(event.target)) {
      return;
    }

    const marker = resolveMarkerNodeFromTarget(event.target);

    if (!marker) {
      return;
    }

    if (state.activeNode && state.activeNode !== marker) {
      return;
    }

    if (event.relatedTarget && marker.contains(event.relatedTarget)) {
      return;
    }

    if (isBadgeElement(event.relatedTarget)) {
      return;
    }

    if (state.previewNode === marker) {
      scheduleBadgeHide();
    }
  }

  function handleBadgeKeydown(event) {
    if (event.key !== 'Escape') {
      return;
    }

    if (state.activeNode) {
      return;
    }

    clearBadgeHideTimeout();
    state.previewNode = null;
    clearDescriptorPrefetch();
    scheduleBadgeLayout();
  }

  function isTouchLikePointer(event) {
    return Boolean(event && (event.pointerType === 'touch' || event.pointerType === 'pen'));
  }

  function suppressTouchClick(token) {
    state.touchSuppressToken = token;
    state.touchClickSuppressUntil = Date.now() + 700;
  }

  function handleMarkerPointerUp(event) {
    if (!isTouchLikePointer(event) || isBadgeElement(event.target)) {
      return;
    }

    const marker = resolveMarkerNodeFromTarget(event.target);
    const token = getMarkerToken(marker);

    if (!marker || !token) {
      return;
    }

    event.preventDefault();
    event.stopPropagation();
    suppressTouchClick(token);

    if (state.activeNode && state.activeNode !== marker && state.session) {
      state.touchSelectionToken = token;
      openEditor(marker, state.session);
      return;
    }

    if (state.touchSelectionToken === token && state.previewNode === marker && state.session) {
      openEditor(marker, state.session);
      return;
    }

    state.touchSelectionToken = token;
    setPreviewNode(marker);
  }

  function handleMarkerClick(event) {
    const marker = resolveMarkerNodeFromTarget(event.target);
    const token = getMarkerToken(marker);

    if (!marker || !token) {
      return;
    }

    if (state.touchSuppressToken !== token || Date.now() > state.touchClickSuppressUntil) {
      return;
    }

    state.touchSuppressToken = '';
    state.touchClickSuppressUntil = 0;
    event.preventDefault();
    event.stopPropagation();
  }

  function resolveBadgeTarget() {
    if (state.activeNode && state.activeNode.isConnected) {
      return state.activeNode;
    }

    if (state.previewNode && state.previewNode.isConnected) {
      return state.previewNode;
    }

    return null;
  }

  function applyBadgePresentation(node) {
    const badge = ensureSharedBadge();
    const descriptor = lookupDescriptorForNode(node);
    const scope = getDescriptorScope(descriptor, node);
    const status = getDescriptorStatus(descriptor, node);
    const entityType = getDescriptorEntityType(descriptor);
    const token = node.getAttribute('data-dbvc-ve') || '';

    badge.className = 'dbvc-ve-badge';
    badge.dataset.token = token;

    if (scope === 'related_entity') {
      badge.classList.add('dbvc-ve-badge--related');
      badge.textContent = resolveRelatedBadgeLabel(entityType);
      return badge;
    }

    if (scope === 'shared_entity') {
      badge.classList.add('dbvc-ve-badge--shared');
      badge.textContent = resolveSharedBadgeLabel(entityType);
      return badge;
    }

    if (status === 'readonly') {
      badge.classList.add('dbvc-ve-badge--readonly');
      badge.textContent = strings().inspectLabel || 'Inspect';
      return badge;
    }

    badge.textContent = strings().editLabel || 'Edit';

    return badge;
  }

  function hideSharedBadge() {
    if (!state.badgeNode) {
      return;
    }

    state.badgeNode.style.opacity = '0';
    state.badgeNode.style.pointerEvents = 'none';
  }

  function scheduleBadgeLayout() {
    if (state.badgeLayoutFrame) {
      window.cancelAnimationFrame(state.badgeLayoutFrame);
    }

    state.badgeLayoutFrame = window.requestAnimationFrame(function () {
      state.badgeLayoutFrame = 0;
      positionSharedBadge();
    });
  }

  function positionSharedBadge() {
    const target = resolveBadgeTarget();
    const badge = ensureSharedBadge();

    if (!target || !target.isConnected) {
      hideSharedBadge();
      return;
    }

    const rect = target.getBoundingClientRect();

    if (!rect || rect.width <= 0 || rect.height <= 0 || rect.bottom < 0 || rect.top > window.innerHeight || rect.right < 0 || rect.left > window.innerWidth) {
      hideSharedBadge();
      return;
    }

    applyBadgePresentation(target);

    const badgeWidth = badge.offsetWidth || 0;
    const badgeHeight = badge.offsetHeight || 0;
    const left = Math.min(
      Math.max(8, rect.right - Math.min(24, badgeWidth / 2)),
      window.innerWidth - badgeWidth - 8
    );
    const top = Math.min(
      Math.max(8, rect.top - Math.max(12, badgeHeight / 2)),
      window.innerHeight - badgeHeight - 8
    );

    badge.style.left = `${left}px`;
    badge.style.top = `${top}px`;
    badge.style.opacity = '1';
    badge.style.pointerEvents = 'auto';
  }

  function updateStatusBar(statePatch) {
    const bar = ensureStatusBar();
    const title = bar.querySelector('.dbvc-ve-statusbar__title');
    const meta = bar.querySelector('.dbvc-ve-statusbar__meta');
    const message = bar.querySelector('.dbvc-ve-statusbar__message');
    const modeActive = strings().modeActive || 'Visual Editor active';

    bar.dataset.state = statePatch.kind || 'active';
    title.textContent = modeActive;
    meta.textContent = typeof statePatch.count === 'number'
      ? `${statePatch.count} ${(strings().supportedCount || 'supported fields')}`
      : '';
    message.textContent = statePatch.message || '';
  }

  function ensureEditorPanel() {
    let panel = document.querySelector('.dbvc-ve-panel');

    if (panel) {
      return panel;
    }

    panel = document.createElement('aside');
    panel.className = 'dbvc-ve-panel';
    panel.innerHTML = [
      '<div class="dbvc-ve-panel__header">',
      '  <div>',
      '    <div class="dbvc-ve-panel__eyebrow">DBVC Visual Editor</div>',
      '    <h2 class="dbvc-ve-panel__title"></h2>',
      '    <div class="dbvc-ve-panel__entity-type"></div>',
      '  </div>',
      '  <button type="button" class="dbvc-ve-panel__close" aria-label="Close">×</button>',
      '</div>',
      '<div class="dbvc-ve-panel__entity-links"></div>',
      '<div class="dbvc-ve-panel__notice"></div>',
      '<div class="dbvc-ve-panel__body">',
      '  <label class="dbvc-ve-panel__field-label" for="dbvc-ve-panel-input"></label>',
      '  <div class="dbvc-ve-panel__meta"></div>',
      '  <div class="dbvc-ve-panel__field-wrap"></div>',
      '</div>',
      '<div class="dbvc-ve-panel__status"></div>',
      '<div class="dbvc-ve-panel__actions">',
      '  <button type="button" class="dbvc-ve-panel__button dbvc-ve-panel__button--secondary" data-action="close"></button>',
      '  <button type="button" class="dbvc-ve-panel__button dbvc-ve-panel__button--primary" data-action="save"></button>',
      '</div>'
    ].join('');

    panel.querySelector('[data-action="close"]').addEventListener('click', closeEditorPanel);
    panel.querySelector('.dbvc-ve-panel__close').addEventListener('click', closeEditorPanel);
    panel.querySelector('[data-action="save"]').addEventListener('click', handleSave);

    document.body.appendChild(panel);

    renderIdlePanel();

    return panel;
  }

  function getPanelNodes() {
    const panel = ensureEditorPanel();

    return {
      panel,
      title: panel.querySelector('.dbvc-ve-panel__title'),
      entityType: panel.querySelector('.dbvc-ve-panel__entity-type'),
      entityLinks: panel.querySelector('.dbvc-ve-panel__entity-links'),
      meta: panel.querySelector('.dbvc-ve-panel__meta'),
      notice: panel.querySelector('.dbvc-ve-panel__notice'),
      fieldLabel: panel.querySelector('.dbvc-ve-panel__field-label'),
      fieldWrap: panel.querySelector('.dbvc-ve-panel__field-wrap'),
      status: panel.querySelector('.dbvc-ve-panel__status'),
      closeButton: panel.querySelector('[data-action="close"]'),
      saveButton: panel.querySelector('[data-action="save"]')
    };
  }

  function renderIdlePanel() {
    const panelNodes = getPanelNodes();

    destroyActiveController();
    clearDescriptorPrefetch();
    state.activeNode = null;
    state.activeDescriptor = null;
    state.activeController = null;
    state.activeRequiresSharedScopeAck = false;
    state.activeAcknowledgementType = 'none';
    state.sharedScopeAcknowledged = false;
    state.touchSelectionToken = '';
    panelNodes.panel.dataset.state = 'idle';
    panelNodes.panel.dataset.scope = 'current_entity';
    panelNodes.panel.dataset.status = 'idle';
    panelNodes.title.textContent = strings().panelTitle || 'Edit field';
    panelNodes.entityType.textContent = '';
    panelNodes.entityLinks.innerHTML = '';
    panelNodes.meta.innerHTML = '';
    panelNodes.notice.innerHTML = '';
    panelNodes.fieldLabel.textContent = '';
    panelNodes.fieldWrap.innerHTML = '<div class="dbvc-ve-panel__placeholder"></div>';
    panelNodes.fieldWrap.firstChild.textContent = strings().panelReady || 'Select a marker to inspect and edit it.';
    panelNodes.status.textContent = '';
    panelNodes.closeButton.textContent = strings().panelCancel || 'Close';
    panelNodes.saveButton.textContent = strings().panelSave || 'Save';
    panelNodes.saveButton.disabled = true;

    document.querySelectorAll('.dbvc-ve-target.is-active').forEach(function (node) {
      node.classList.remove('is-active');
    });

    scheduleBadgeLayout();
  }

  function closeEditorPanel() {
    renderIdlePanel();
  }

  function destroyActiveController() {
    if (state.activeController && typeof state.activeController.destroy === 'function') {
      state.activeController.destroy();
    }
  }

  function createInputController(type, value) {
    const field = document.createElement('input');

    field.id = 'dbvc-ve-panel-input';
    field.className = 'dbvc-ve-panel__input';
    field.type = type || 'text';
    field.value = value === null || typeof value === 'undefined' ? '' : String(value);

    return {
      element: field,
      getValue() {
        return field.value;
      },
      setValue(nextValue) {
        field.value = nextValue === null || typeof nextValue === 'undefined' ? '' : String(nextValue);
      },
      focus() {
        field.focus();
        field.select();
      },
      setDisabled(disabled) {
        field.disabled = Boolean(disabled);
      }
    };
  }

  function createTextareaController(value) {
    const field = document.createElement('textarea');

    field.id = 'dbvc-ve-panel-input';
    field.className = 'dbvc-ve-panel__input';
    field.rows = 8;
    field.value = value === null || typeof value === 'undefined' ? '' : String(value);

    return {
      element: field,
      getValue() {
        return field.value;
      },
      setValue(nextValue) {
        field.value = nextValue === null || typeof nextValue === 'undefined' ? '' : String(nextValue);
      },
      focus() {
        field.focus();
      },
      setDisabled(disabled) {
        field.disabled = Boolean(disabled);
      }
    };
  }

  function createToolbarButton(label, handler) {
    const button = document.createElement('button');

    button.type = 'button';
    button.className = 'dbvc-ve-panel__toolbar-button';
    button.textContent = label;
    button.addEventListener('mousedown', function (event) {
      event.preventDefault();
    });
    button.addEventListener('click', handler);

    return button;
  }

  function supportsWpEditor() {
    return Boolean(
      window.DBVCVisualEditorBootstrap
        && window.DBVCVisualEditorBootstrap.supportsWpEditor
        && window.wp
        && window.wp.editor
        && typeof window.wp.editor.initialize === 'function'
        && typeof window.wp.editor.remove === 'function'
    );
  }

  function getTinyMceEditor(editorId) {
    return window.tinymce && typeof window.tinymce.get === 'function'
      ? window.tinymce.get(editorId)
      : null;
  }

  function createFallbackRichTextController(value) {
    const wrapper = document.createElement('div');
    const toolbar = document.createElement('div');
    const editor = document.createElement('div');
    const toolbarButtons = [];

    wrapper.className = 'dbvc-ve-panel__stack';
    toolbar.className = 'dbvc-ve-panel__toolbar';
    editor.id = 'dbvc-ve-panel-input';
    editor.className = 'dbvc-ve-panel__richtext';
    editor.contentEditable = 'true';
    editor.innerHTML = typeof value === 'string' ? value : '';

    [
      { label: strings().panelRichTextBold || 'Bold', command: 'bold' },
      { label: strings().panelRichTextItalic || 'Italic', command: 'italic' },
      { label: strings().panelRichTextParagraph || 'Paragraph', command: 'formatBlock', argument: 'p' },
      { label: strings().panelRichTextBullets || 'Bullets', command: 'insertUnorderedList' },
      { label: strings().panelRichTextNumbers || 'Numbers', command: 'insertOrderedList' }
    ].forEach(function (item) {
      const button = createToolbarButton(item.label, function () {
        editor.focus();
        if (item.command === 'formatBlock') {
          document.execCommand(item.command, false, item.argument);
          return;
        }

        document.execCommand(item.command, false, null);
      });

      toolbarButtons.push(button);
      toolbar.appendChild(button);
    });

    wrapper.appendChild(toolbar);
    wrapper.appendChild(editor);

    return {
      element: wrapper,
      mount() {},
      getValue() {
        return editor.innerHTML;
      },
      setValue(nextValue) {
        editor.innerHTML = typeof nextValue === 'string' ? nextValue : '';
      },
      focus() {
        editor.focus();
      },
      setDisabled(disabled) {
        const isDisabled = Boolean(disabled);

        editor.contentEditable = isDisabled ? 'false' : 'true';
        toolbarButtons.forEach(function (button) {
          button.disabled = isDisabled;
        });
      },
      destroy() {}
    };
  }

  function createWordPressRichTextController(value) {
    const wrapper = document.createElement('div');
    const textarea = document.createElement('textarea');
    const editorId = 'dbvc-ve-panel-input';
    let initialized = false;
    let disabled = false;

    wrapper.className = 'dbvc-ve-panel__wysiwyg-host';
    textarea.id = editorId;
    textarea.className = 'dbvc-ve-panel__input dbvc-ve-panel__wysiwyg-textarea';
    textarea.value = typeof value === 'string' ? value : '';
    wrapper.appendChild(textarea);

    function syncState() {
      const editor = getTinyMceEditor(editorId);

      textarea.disabled = disabled;
      wrapper.classList.toggle('is-disabled', disabled);

      if (editor && typeof editor.setMode === 'function') {
        editor.setMode(disabled ? 'readonly' : 'design');
      }
    }

    return {
      element: wrapper,
      mount() {
        if (initialized) {
          syncState();
          return;
        }

        try {
          window.wp.editor.remove(editorId);
        } catch (error) {}

        window.wp.editor.initialize(editorId, {
          tinymce: {
            wpautop: true,
            resize: true,
            height: 260
          },
          quicktags: true,
          mediaButtons: false
        });

        initialized = true;
        syncState();
      },
      getValue() {
        const editor = getTinyMceEditor(editorId);

        if (editor && typeof editor.save === 'function') {
          editor.save();
        }

        return textarea.value;
      },
      setValue(nextValue) {
        const normalized = typeof nextValue === 'string' ? nextValue : '';
        const editor = getTinyMceEditor(editorId);

        textarea.value = normalized;

        if (editor && typeof editor.setContent === 'function') {
          editor.setContent(normalized);
        }
      },
      focus() {
        window.setTimeout(function () {
          const editor = getTinyMceEditor(editorId);

          if (editor && typeof editor.isHidden === 'function' && !editor.isHidden()) {
            editor.focus();
            return;
          }

          textarea.focus();
        }, 0);
      },
      setDisabled(nextDisabled) {
        disabled = Boolean(nextDisabled);
        syncState();
      },
      destroy() {
        if (!initialized) {
          return;
        }

        const editor = getTinyMceEditor(editorId);

        if (editor && typeof editor.save === 'function') {
          editor.save();
        }

        window.wp.editor.remove(editorId);
        initialized = false;
      }
    };
  }

  function createRichTextController(value) {
    if (supportsWpEditor()) {
      return createWordPressRichTextController(value);
    }

    return createFallbackRichTextController(value);
  }

  function createNoopLifecycle(controller) {
    return Object.assign({
      mount() {},
      destroy() {}
    }, controller);
  }

  function createCheckboxGroupController(value, descriptor) {
    const controller = createCheckboxGroupControllerBase(value, descriptor);

    return createNoopLifecycle(controller);
  }

  function createCheckboxGroupControllerBase(value, descriptor) {
    const wrapper = document.createElement('div');
    const options = Array.isArray(descriptor.ui && descriptor.ui.options) ? descriptor.ui.options : [];
    const selectedValues = new Set(Array.isArray(value) ? value.map(String) : []);
    const inputs = [];

    wrapper.className = 'dbvc-ve-panel__option-list';

    if (!options.length) {
      wrapper.innerHTML = `<div class="dbvc-ve-panel__placeholder">${strings().panelNoOptions || 'No choices were available for this field.'}</div>`;

      return {
        element: wrapper,
        getValue() {
          return [];
        },
        setValue() {},
        focus() {},
        setDisabled() {}
      };
    }

    options.forEach(function (option, index) {
      const label = document.createElement('label');
      const input = document.createElement('input');
      const text = document.createElement('span');
      const optionValue = option && typeof option.value !== 'undefined' ? String(option.value) : '';

      label.className = 'dbvc-ve-panel__option';
      input.type = 'checkbox';
      input.value = optionValue;
      input.checked = selectedValues.has(optionValue);
      input.id = `dbvc-ve-panel-input-${index}`;
      text.textContent = option && option.label ? String(option.label) : optionValue;

      label.appendChild(input);
      label.appendChild(text);
      wrapper.appendChild(label);
      inputs.push(input);
    });

    return {
      element: wrapper,
      getValue() {
        return inputs.filter(function (input) {
          return input.checked;
        }).map(function (input) {
          return input.value;
        });
      },
      setValue(nextValue) {
        const nextValues = new Set(Array.isArray(nextValue) ? nextValue.map(String) : []);

        inputs.forEach(function (input) {
          input.checked = nextValues.has(input.value);
        });
      },
      focus() {
        if (inputs[0]) {
          inputs[0].focus();
        }
      },
      setDisabled(disabled) {
        const isDisabled = Boolean(disabled);

        inputs.forEach(function (input) {
          input.disabled = isDisabled;
        });
      }
    };
  }

  function createSelectController(value, descriptor) {
    const select = document.createElement('select');
    const options = Array.isArray(descriptor.ui && descriptor.ui.options) ? descriptor.ui.options : [];

    select.id = 'dbvc-ve-panel-input';
    select.className = 'dbvc-ve-panel__input dbvc-ve-panel__select';

    options.forEach(function (option) {
      const item = document.createElement('option');
      const optionValue = option && typeof option.value !== 'undefined' ? String(option.value) : '';

      item.value = optionValue;
      item.textContent = option && option.label ? String(option.label) : optionValue;
      item.selected = optionValue === String(value || '');
      select.appendChild(item);
    });

    return createNoopLifecycle({
      element: select,
      getValue() {
        return select.value;
      },
      setValue(nextValue) {
        select.value = nextValue === null || typeof nextValue === 'undefined' ? '' : String(nextValue);
      },
      focus() {
        select.focus();
      },
      setDisabled(disabled) {
        select.disabled = Boolean(disabled);
      }
    });
  }

  function createLinkController(value) {
    const wrapper = document.createElement('div');
    const urlField = document.createElement('input');
    const titleField = document.createElement('input');
    const targetField = document.createElement('select');
    const fields = [urlField, titleField, targetField];
    const normalized = value && typeof value === 'object' ? value : {};

    wrapper.className = 'dbvc-ve-panel__stack';

    urlField.className = 'dbvc-ve-panel__input';
    urlField.type = 'url';
    urlField.placeholder = strings().panelLinkUrl || 'Link URL';
    urlField.value = normalized.url ? String(normalized.url) : '';

    titleField.className = 'dbvc-ve-panel__input';
    titleField.type = 'text';
    titleField.placeholder = strings().panelLinkTitle || 'Link title';
    titleField.value = normalized.title ? String(normalized.title) : '';

    targetField.className = 'dbvc-ve-panel__input dbvc-ve-panel__select';
    [
      { value: '', label: strings().panelLinkSameTab || 'Open in same tab' },
      { value: '_blank', label: strings().panelLinkNewTab || 'Open in new tab' }
    ].forEach(function (item) {
      const option = document.createElement('option');

      option.value = item.value;
      option.textContent = item.label;
      option.selected = item.value === String(normalized.target || '');
      targetField.appendChild(option);
    });

    wrapper.appendChild(urlField);
    wrapper.appendChild(titleField);
    wrapper.appendChild(targetField);

    return createNoopLifecycle({
      element: wrapper,
      getValue() {
        return {
          url: urlField.value,
          title: titleField.value,
          target: targetField.value
        };
      },
      setValue(nextValue) {
        const next = nextValue && typeof nextValue === 'object' ? nextValue : {};

        urlField.value = next.url ? String(next.url) : '';
        titleField.value = next.title ? String(next.title) : '';
        targetField.value = next.target ? String(next.target) : '';
      },
      focus() {
        urlField.focus();
      },
      setDisabled(disabled) {
        const isDisabled = Boolean(disabled);

        fields.forEach(function (field) {
          field.disabled = isDisabled;
        });
      }
    });
  }

  function createReadonlyPreviewController(value) {
    const preview = document.createElement('pre');

    preview.id = 'dbvc-ve-panel-input';
    preview.className = 'dbvc-ve-panel__preview';

    if (typeof value === 'string') {
      preview.textContent = value;
    } else if (value === null || typeof value === 'undefined') {
      preview.textContent = '';
    } else if (typeof value === 'object') {
      try {
        preview.textContent = JSON.stringify(value, null, 2);
      } catch (error) {
        preview.textContent = String(value);
      }
    } else {
      preview.textContent = String(value);
    }

    return createNoopLifecycle({
      element: preview,
      getValue() {
        return value;
      },
      setValue(nextValue) {
        if (typeof nextValue === 'string') {
          preview.textContent = nextValue;
          return;
        }

        if (nextValue === null || typeof nextValue === 'undefined') {
          preview.textContent = '';
          return;
        }

        if (typeof nextValue === 'object') {
          try {
            preview.textContent = JSON.stringify(nextValue, null, 2);
            return;
          } catch (error) {}
        }

        preview.textContent = String(nextValue);
      },
      focus() {
        preview.scrollIntoView({ block: 'nearest' });
      },
      setDisabled() {}
    });
  }

  function renderMediaPreviewImage(preview, url, alt) {
    preview.innerHTML = '';

    if (!url) {
      preview.textContent = strings().panelNoMedia || 'No media is currently set.';
      return;
    }

    const image = document.createElement('img');

    image.src = url;
    image.alt = alt || '';
    preview.appendChild(image);
  }

  function createMediaReferenceController(value, descriptor) {
    const wrapper = document.createElement('div');
    const preview = document.createElement('div');
    const buttonRow = document.createElement('div');
    const chooseButton = document.createElement('button');
    const clearButton = document.createElement('button');
    const urlField = document.createElement('input');
    const meta = document.createElement('div');
    const fields = [urlField, chooseButton, clearButton];
    let currentValue = normalizeMediaReferenceValue(value);
    let mediaFrame = null;

    wrapper.className = 'dbvc-ve-panel__stack';
    preview.className = 'dbvc-ve-panel__media-preview';
    buttonRow.className = 'dbvc-ve-panel__toolbar';
    meta.className = 'dbvc-ve-panel__media-meta';
    chooseButton.type = 'button';
    chooseButton.className = 'dbvc-ve-panel__toolbar-button';
    clearButton.type = 'button';
    clearButton.className = 'dbvc-ve-panel__toolbar-button';
    urlField.className = 'dbvc-ve-panel__input';
    urlField.type = 'url';
    urlField.placeholder = strings().panelMediaUrl || 'Media Library image URL';

    function render(nextValue) {
      const normalized = normalizeMediaReferenceValue(nextValue);
      const renderData = resolveMediaReferenceRenderData(normalized, descriptor);
      const previewUrl = renderData.src;
      const fullUrl = renderData.fullUrl;
      const attachmentId = renderData.attachmentId;
      const alt = renderData.alt;

      currentValue = Object.assign({}, normalized, {
        attachmentId,
        id: attachmentId
      });
      urlField.value = fullUrl ? String(fullUrl) : '';
      chooseButton.textContent = attachmentId
        ? (strings().panelMediaReplace || 'Replace from Media Library')
        : (strings().panelMediaChoose || 'Choose from Media Library');
      clearButton.textContent = strings().panelMediaClear || 'Clear image';
      clearButton.disabled = !attachmentId && !fullUrl;
      meta.textContent = attachmentId
        ? `${strings().panelMediaId || 'Attachment ID'}: ${attachmentId}`
        : (strings().panelMediaUrlHint || 'Paste a local Media Library image URL to resolve this field to an attachment ID.');
      renderMediaPreviewImage(preview, previewUrl ? String(previewUrl) : '', alt ? String(alt) : '');
    }

    function openMediaFrame() {
      if (!supportsWpMedia()) {
        urlField.focus();
        return;
      }

      if (!mediaFrame) {
        mediaFrame = window.wp.media({
          title: strings().panelMediaFrameTitle || 'Select image',
          button: {
            text: strings().panelMediaFrameButton || 'Use this image'
          },
          library: {
            type: 'image'
          },
          multiple: false
        });

        mediaFrame.on('select', function () {
          const attachment = mediaFrame.state().get('selection').first();
          const data = attachment ? attachment.toJSON() : null;

          if (!data) {
            return;
          }

          const mediaSize = getDescriptorMediaSize(descriptor);
          const selectedRenderUrl = mediaSize && data.sizes && data.sizes[mediaSize]
            ? data.sizes[mediaSize].url
            : (data.url || '');

          render({
            attachmentId: data.id || 0,
            id: data.id || 0,
            url: data.url || '',
            fullUrl: data.url || '',
            renderUrl: selectedRenderUrl,
            renderAttributes: {
              src: selectedRenderUrl,
              srcset: '',
              sizes: ''
            },
            alt: data.alt || '',
            title: data.title || ''
          });
        });
      }

      mediaFrame.open();
    }

    render(value);

    chooseButton.addEventListener('click', function () {
      openMediaFrame();
    });
    clearButton.addEventListener('click', function () {
      render({
        attachmentId: 0,
        id: 0,
        url: '',
        fullUrl: '',
        renderUrl: '',
        renderAttributes: {
          src: '',
          srcset: '',
          sizes: ''
        },
        alt: '',
        title: ''
      });
    });
    urlField.addEventListener('input', function () {
      currentValue.attachmentId = 0;
      currentValue.id = 0;
      currentValue.url = urlField.value;
      currentValue.fullUrl = urlField.value;
      currentValue.renderUrl = urlField.value;
      currentValue.renderAttributes = {
        src: urlField.value,
        srcset: '',
        sizes: ''
      };
      render(currentValue);
    });

    wrapper.appendChild(preview);
    buttonRow.appendChild(chooseButton);
    buttonRow.appendChild(clearButton);
    wrapper.appendChild(buttonRow);
    wrapper.appendChild(urlField);
    wrapper.appendChild(meta);

    return createNoopLifecycle({
      element: wrapper,
      getValue() {
        return {
          attachmentId: currentValue.attachmentId || currentValue.id || 0,
          url: urlField.value
        };
      },
      setValue(nextValue) {
        render(nextValue);
      },
      focus() {
        urlField.focus();
        urlField.select();
      },
      setDisabled(disabled) {
        const isDisabled = Boolean(disabled);

        fields.forEach(function (field) {
          field.disabled = isDisabled;
        });
      }
    });
  }

  function createMediaGalleryPreviewController(value) {
    const wrapper = document.createElement('div');
    const meta = document.createElement('div');
    const grid = document.createElement('div');

    wrapper.className = 'dbvc-ve-panel__stack';
    meta.className = 'dbvc-ve-panel__media-meta';
    grid.className = 'dbvc-ve-panel__gallery-preview';

    function render(items) {
      const normalized = Array.isArray(items) ? items : [];

      grid.innerHTML = '';
      meta.textContent = normalized.length === 1
        ? (strings().panelGallerySingle || '1 gallery image')
        : `${normalized.length} ${strings().panelGalleryCount || 'gallery images'}`;

      if (!normalized.length) {
        grid.innerHTML = `<div class="dbvc-ve-panel__placeholder">${strings().panelNoMedia || 'No media is currently set.'}</div>`;
        return;
      }

      normalized.forEach(function (item) {
        const url = item && (item.renderUrl || item.url) ? String(item.renderUrl || item.url) : '';

        if (!url) {
          return;
        }

        const figure = document.createElement('figure');
        const image = document.createElement('img');

        figure.className = 'dbvc-ve-panel__gallery-item';
        image.src = url;
        image.alt = item && item.alt ? String(item.alt) : '';
        figure.appendChild(image);
        grid.appendChild(figure);
      });
    }

    render(value);

    wrapper.appendChild(meta);
    wrapper.appendChild(grid);

    return createNoopLifecycle({
      element: wrapper,
      getValue() {
        return value;
      },
      setValue(nextValue) {
        render(nextValue);
      },
      focus() {
        wrapper.scrollIntoView({ block: 'nearest' });
      },
      setDisabled() {}
    });
  }

  function createFieldController(inputType, value, descriptor) {
    switch (inputType) {
      case 'readonly_preview':
        return createReadonlyPreviewController(value);
      case 'media_reference':
        return createMediaReferenceController(value, descriptor);
      case 'media_gallery_preview':
        return createMediaGalleryPreviewController(value, descriptor);
      case 'textarea':
        return createTextareaController(value);
      case 'richtext':
        return createRichTextController(value);
      case 'checkbox_group':
        return createCheckboxGroupController(value, descriptor);
      case 'select':
        return createSelectController(value, descriptor);
      case 'link':
        return createLinkController(value);
      case 'url':
      case 'email':
      case 'number':
        return createInputController(inputType, value);
      default:
        return createInputController('text', value);
    }
  }

  function formatEntityMeta(descriptor) {
    const scope = getDescriptorScope(descriptor);
    const status = getDescriptorStatus(descriptor);
    const sourceType = descriptor.source && descriptor.source.type ? descriptor.source.type : '';
    let sourceTypeLabel = sourceType;

    if (sourceType === 'acf_repeater_subfield') {
      sourceTypeLabel = strings().panelSourceRepeater || 'acf repeater';
    } else if (sourceType === 'acf_flexible_subfield') {
      sourceTypeLabel = strings().panelSourceFlexible || 'acf flexible';
    }

    const sourceField = descriptor.source && descriptor.source.field_name ? descriptor.source.field_name : '';
    const containerType = descriptor.source && descriptor.source.container_type ? descriptor.source.container_type : '';
    const parentField = descriptor.source && descriptor.source.parent_field_name ? descriptor.source.parent_field_name : '';
    const rowIndex = descriptor.source && Number.isFinite(Number(descriptor.source.row_index))
      ? Number(descriptor.source.row_index)
      : null;
    const layoutName = descriptor.source && descriptor.source.layout_name ? descriptor.source.layout_name : '';
    const layoutKey = descriptor.source && descriptor.source.layout_key ? descriptor.source.layout_key : '';
    const entity = descriptor.entity || {};
    const loop = descriptor.render && descriptor.render.loop && typeof descriptor.render.loop === 'object'
      ? descriptor.render.loop
      : null;
    const bits = [sourceTypeLabel, sourceField].filter(Boolean);
    const scopeLabel = resolveScopeMetaLabel(scope, entity.type || '');

    if (scopeLabel) {
      bits.unshift(scopeLabel);
    }

    if (status === 'readonly') {
      bits.unshift(strings().panelScopeReadonly || 'inspect only');
    }

    if (parentField) {
      if (containerType === 'flexible_content') {
        bits.push(`${strings().panelFlexible || 'flexible'}:${parentField}`);
      } else {
        bits.push(`${strings().panelRepeater || 'repeater'}:${parentField}`);
      }
    }

    if (rowIndex !== null && rowIndex >= 0) {
      bits.push(`${strings().panelRow || 'row'}:${rowIndex + 1}`);
    }

    if (layoutName || layoutKey) {
      bits.push(`${strings().panelLayout || 'layout'}:${layoutName || layoutKey}`);
    }

    if (entity.type === 'post' && entity.id) {
      bits.push(`${strings().panelEntityPost || 'post'}:${entity.id}`);
    } else if (entity.type === 'option') {
      bits.push(strings().panelEntityOption || 'option');
    } else if (entity.type === 'term' && entity.subtype) {
      bits.push(`${strings().panelEntityTerm || 'term'}:${entity.subtype}:${entity.id || ''}`.replace(/:$/, ''));
    } else if (entity.type === 'user' && entity.id) {
      bits.push(`${strings().panelEntityUser || 'user'}:${entity.id}`);
    }

    if (loop && loop.active) {
      const loopType = loop.query_object_type ? String(loop.query_object_type) : '';
      const loopObjectType = loop.loop_object_type ? String(loop.loop_object_type) : '';
      const loopObjectId = loop.loop_object_id ? String(loop.loop_object_id) : '';
      const loopLabel = strings().panelLoop || 'loop';
      const loopSummary = [loopType, loopObjectType && loopObjectId ? `${loopObjectType}:${loopObjectId}` : loopObjectType].filter(Boolean).join(' / ');

      if (loopSummary) {
        bits.push(`${loopLabel}:${loopSummary}`);
      }
    }

    return bits.length
      ? `${strings().panelSource || 'Source'}: ${bits.join(' / ')}`
      : '';
  }

  function createEntityLinkMarkup(link) {
    if (!link || typeof link !== 'object' || !link.url) {
      return '';
    }

    const label = link.label ? String(link.label) : String(link.url);
    const url = String(link.url);

    return `<a class="dbvc-ve-panel__entity-link" href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(label)}</a>`;
  }

  function renderEntityHeader(result, panelNodes) {
    const summary = result && result.entitySummary && typeof result.entitySummary === 'object'
      ? result.entitySummary
      : {};
    const title = summary.title ? String(summary.title) : (strings().panelTitle || 'Edit field');
    const typeLabel = summary.typeLabel ? String(summary.typeLabel) : '';
    const links = [createEntityLinkMarkup(summary.frontendLink), createEntityLinkMarkup(summary.backendLink)].filter(Boolean);

    panelNodes.title.textContent = title;
    panelNodes.entityType.textContent = typeLabel;
    panelNodes.entityLinks.innerHTML = links.join('');
  }

  function renderSourceMeta(result, panelNodes) {
    const summary = result && result.sourceSummary && typeof result.sourceSummary === 'object'
      ? result.sourceSummary
      : {};
    const label = summary.label ? String(summary.label) : '';
    const sourceSummary = summary.summary ? String(summary.summary) : '';
    const expression = summary.expression ? String(summary.expression) : '';

    panelNodes.meta.innerHTML = '';

    if (!label && !sourceSummary && !expression) {
      return;
    }

    const details = document.createElement('details');
    const summaryNode = document.createElement('summary');
    const body = document.createElement('div');
    const lines = [];

    details.className = 'dbvc-ve-panel__meta-toggle';
    summaryNode.className = 'dbvc-ve-panel__meta-summary';
    summaryNode.textContent = strings().panelSourceDetails || 'Source details';
    body.className = 'dbvc-ve-panel__meta-body';

    if (label) {
      lines.push(`<div><strong>${escapeHtml(strings().panelSourceLabel || 'Label')}:</strong> ${escapeHtml(label)}</div>`);
    }

    if (expression) {
      lines.push(`<div><strong>${escapeHtml(strings().panelSourceExpression || 'Dynamic tag')}:</strong> <code>${escapeHtml(expression)}</code></div>`);
    }

    if (sourceSummary) {
      lines.push(`<div><strong>${escapeHtml(strings().panelSource || 'Source')}:</strong> ${escapeHtml(sourceSummary)}</div>`);
    }

    if (result.saveContractSummary && result.saveContractSummary.label) {
      lines.push(`<div><strong>${escapeHtml(strings().panelSaveContract || 'Save contract')}:</strong> ${escapeHtml(String(result.saveContractSummary.label))}</div>`);
    }

    if (result.saveContractSummary && result.saveContractSummary.detail) {
      lines.push(`<div><strong>${escapeHtml(strings().panelSaveContractDetail || 'Contract detail')}:</strong> ${escapeHtml(String(result.saveContractSummary.detail))}</div>`);
    }

    body.innerHTML = lines.join('');
    details.appendChild(summaryNode);
    details.appendChild(body);
    panelNodes.meta.appendChild(details);
  }

  function updateSaveButtonState(panelNodes, canEdit) {
    const status = getDescriptorStatus(state.activeDescriptor, state.activeNode);
    const needsSharedAck = state.activeRequiresSharedScopeAck;
    const acknowledgementType = state.activeAcknowledgementType;
    const entityType = getDescriptorEntityType(state.activeDescriptor);

    if (status === 'readonly') {
      panelNodes.saveButton.textContent = strings().panelInspectOnly || 'Inspect only';
      panelNodes.saveButton.disabled = true;
      return;
    }

    if (needsSharedAck && acknowledgementType === 'related') {
      panelNodes.saveButton.textContent = resolveRelatedSaveLabel(entityType);
    } else if (needsSharedAck) {
      panelNodes.saveButton.textContent = resolveSharedSaveLabel(entityType);
    } else {
      panelNodes.saveButton.textContent = strings().panelSave || 'Save';
    }

    panelNodes.saveButton.disabled = !canEdit || (needsSharedAck && !state.sharedScopeAcknowledged);
  }

  function renderPanelNotice(result, panelNodes, canEdit) {
    const descriptor = result.descriptor;
    const warning = descriptor.ui && descriptor.ui.warning ? String(descriptor.ui.warning) : '';
    const editMessage = result.editMessage ? String(result.editMessage) : '';
    const noticeSummary = result.noticeSummary && typeof result.noticeSummary === 'object'
      ? result.noticeSummary
      : null;
    const requiresSharedAck = Boolean(result.requiresSharedScopeAck) && canEdit;
    const acknowledgementType = result.acknowledgementType || 'none';
    const entityType = getDescriptorEntityType(descriptor);

    panelNodes.notice.innerHTML = '';

    if (!warning && !editMessage && !requiresSharedAck && !noticeSummary) {
      return;
    }

    if (noticeSummary && (noticeSummary.title || noticeSummary.detail)) {
      const summaryBlock = document.createElement('div');
      const titleBlock = document.createElement('div');
      const detailBlock = document.createElement('div');

      summaryBlock.className = 'dbvc-ve-panel__notice-block is-context';
      titleBlock.className = 'dbvc-ve-panel__notice-title';
      detailBlock.className = 'dbvc-ve-panel__notice-detail';
      titleBlock.textContent = noticeSummary.title ? String(noticeSummary.title) : '';
      detailBlock.textContent = noticeSummary.detail ? String(noticeSummary.detail) : '';

      if (titleBlock.textContent) {
        summaryBlock.appendChild(titleBlock);
      }

      if (detailBlock.textContent) {
        summaryBlock.appendChild(detailBlock);
      }

      panelNodes.notice.appendChild(summaryBlock);
    }

    if (warning) {
      const warningBlock = document.createElement('div');

      warningBlock.className = 'dbvc-ve-panel__notice-block is-warning';
      warningBlock.textContent = warning;
      panelNodes.notice.appendChild(warningBlock);
    }

    if (editMessage) {
      const messageBlock = document.createElement('div');

      messageBlock.className = 'dbvc-ve-panel__notice-block is-muted';
      messageBlock.textContent = editMessage;
      panelNodes.notice.appendChild(messageBlock);
    }

    if (requiresSharedAck) {
      const label = document.createElement('label');
      const checkbox = document.createElement('input');
      const text = document.createElement('span');
      const acknowledgementText = acknowledgementType === 'related'
        ? resolveRelatedAckText(entityType)
        : resolveSharedAckText(entityType);

      label.className = 'dbvc-ve-panel__ack';
      checkbox.type = 'checkbox';
      checkbox.checked = Boolean(state.sharedScopeAcknowledged);
      text.textContent = acknowledgementText;

      checkbox.addEventListener('change', function () {
        state.sharedScopeAcknowledged = checkbox.checked;
        updateSaveButtonState(panelNodes, canEdit);
      });

      label.appendChild(checkbox);
      label.appendChild(text);
      panelNodes.notice.appendChild(label);
    }
  }

  function renderEditorPanel(result) {
    const descriptor = result.descriptor;
    const panelNodes = getPanelNodes();
    const inputType = (descriptor.ui && descriptor.ui.input) || 'text';
    const controller = createFieldController(inputType, result.currentValue, descriptor);
    const canEdit = Boolean(result.canEdit) && !result.sourceMismatch;
    let statusMessage = '';

    destroyActiveController();
    state.activeController = controller;
    state.activeRequiresSharedScopeAck = Boolean(result.requiresSharedScopeAck) && canEdit;
    state.activeAcknowledgementType = result.acknowledgementType || 'none';
    state.sharedScopeAcknowledged = false;

    if (result.sourceMismatch) {
      const renderedValue = result.renderedValue || '';
      const renderedLabel = strings().panelRenderedValue || 'Rendered value';
      const resolvedLabel = strings().panelResolvedValue || 'Resolved source value';
      const mismatchMessage = strings().panelMismatch || 'This marker is visible, but saving is disabled because the resolved backend value does not match the rendered page value yet.';
      const resolvedText = extractDisplayText(result.displayValue, result.displayMode);

      statusMessage = `${mismatchMessage} ${renderedLabel}: "${renderedValue}". ${resolvedLabel}: "${resolvedText}".`;
      controller.setDisabled(true);
    } else if (!canEdit) {
      controller.setDisabled(true);
      statusMessage = result.editMessage || '';
    }

    panelNodes.panel.dataset.state = canEdit
      ? 'ready'
      : (getDescriptorStatus(descriptor, state.activeNode) === 'readonly' ? 'inspect' : 'locked');
    panelNodes.panel.dataset.scope = getDescriptorScope(descriptor, state.activeNode);
    panelNodes.panel.dataset.status = getDescriptorStatus(descriptor, state.activeNode);
    panelNodes.fieldLabel.textContent = descriptor.ui && descriptor.ui.label ? descriptor.ui.label : '';
    renderEntityHeader(result, panelNodes);
    renderSourceMeta(result, panelNodes);
    renderPanelNotice(result, panelNodes, canEdit);
    panelNodes.fieldWrap.innerHTML = '';
    panelNodes.fieldWrap.appendChild(controller.element);
    if (typeof controller.mount === 'function') {
      controller.mount();
    }
    panelNodes.status.textContent = statusMessage;
    panelNodes.closeButton.textContent = strings().panelCancel || 'Close';
    updateSaveButtonState(panelNodes, canEdit);

    if (state.activeNode) {
      document.querySelectorAll('.dbvc-ve-target.is-active').forEach(function (node) {
        node.classList.remove('is-active');
      });
      state.activeNode.classList.add('is-active');
    }

    scheduleBadgeLayout();

    if (canEdit) {
      controller.focus();
    }
  }

  function findMarkers() {
    return Array.from(document.querySelectorAll('[data-dbvc-ve]'));
  }

  function getMarkerCount() {
    return findMarkers().length;
  }

  function getActiveSyncGroup() {
    return state.activeDescriptor
      && state.activeDescriptor.render
      && typeof state.activeDescriptor.render.sync_group === 'string'
      ? state.activeDescriptor.render.sync_group
      : '';
  }

  function getActiveSourceGroup() {
    return getDescriptorSourceGroup(state.activeDescriptor);
  }

  function setNodeDisplayValue(node, displayValue, displayMode, descriptor, currentValue) {
    const mode = displayMode || 'text';
    const nextValue = typeof displayValue === 'string' ? displayValue : '';
    const context = getDescriptorRenderContext(descriptor, node);

    if (context === 'link_href') {
      const attribute = getDescriptorRenderAttribute(descriptor, node) || 'href';

      if (nextValue) {
        node.setAttribute(attribute, nextValue);
      } else {
        node.removeAttribute(attribute);
      }

      node.dataset.dbvcVeDisplayValue = normalizeValue(nextValue);
      return;
    }

    if (context === 'image_src') {
      const attribute = getDescriptorRenderAttribute(descriptor, node) || 'src';
      const imageNode = node.matches && node.matches('img') ? node : node.querySelector('img');
      const renderData = resolveMediaReferenceRenderData(currentValue, descriptor);
      const nextSrc = renderData.src || nextValue;

      if (imageNode) {
        if (nextSrc) {
          imageNode.setAttribute(attribute, nextSrc);
        } else {
          imageNode.removeAttribute(attribute);
        }

        if (renderData.srcset) {
          imageNode.setAttribute('srcset', renderData.srcset);
        } else {
          imageNode.removeAttribute('srcset');
        }

        if (renderData.sizes) {
          imageNode.setAttribute('sizes', renderData.sizes);
        } else {
          imageNode.removeAttribute('sizes');
        }

        if (renderData.alt) {
          imageNode.setAttribute('alt', renderData.alt);
        } else {
          imageNode.removeAttribute('alt');
        }
      }

      node.dataset.dbvcVeDisplayValue = normalizeValue(nextSrc);
      return;
    }

    if (context === 'background_image') {
      const renderData = resolveMediaReferenceRenderData(currentValue, descriptor);
      const nextBackground = renderData.src || nextValue;

      node.style.backgroundImage = nextBackground ? `url("${nextBackground.replace(/"/g, '\\"')}")` : '';
      node.dataset.dbvcVeDisplayValue = normalizeValue(nextBackground);
      return;
    }

    const children = Array.from(node.childNodes);

    children.forEach(function (child) {
      node.removeChild(child);
    });

    if (mode === 'html') {
      const wrapper = document.createElement('div');

      wrapper.innerHTML = nextValue;

      while (wrapper.firstChild) {
        node.appendChild(wrapper.firstChild);
      }
    } else {
      node.appendChild(document.createTextNode(nextValue));
    }

    node.dataset.dbvcVeDisplayValue = extractDisplayText(nextValue, mode);
    scheduleBadgeLayout();
  }

  function syncSavedDisplayValues(syncGroup, sourceGroup, saveResult) {
    const activeContext = getDescriptorRenderContext(state.activeDescriptor, state.activeNode);
    const activeMediaSize = getDescriptorMediaSize(state.activeDescriptor);
    const nodes = sourceGroup
      ? findMarkers().filter(function (node) {
          if (node.dataset.dbvcVeSourceGroup !== sourceGroup) {
            return false;
          }

          if (isMediaRenderContext(activeContext)) {
            const descriptor = lookupDescriptorForNode(node);

            return getDescriptorRenderContext(descriptor, node) === activeContext
              && getDescriptorMediaSize(descriptor) === activeMediaSize;
          }

          return true;
        })
      : (syncGroup
        ? findMarkers().filter(function (node) {
            return node.dataset.dbvcVeGroup === syncGroup;
          })
        : []);

    if (!nodes.length) {
      if (state.activeNode) {
        const activeProjection = resolveDisplayProjection(state.activeDescriptor, saveResult);
        const activePayload = state.activeDescriptor ? getCachedDescriptorPayload(state.activeDescriptor.token) : null;

        setNodeDisplayValue(
          state.activeNode,
          activeProjection.value,
          activeProjection.mode,
          state.activeDescriptor,
          activePayload ? activePayload.currentValue : saveResult.value
        );
      }

      return;
    }

    nodes.forEach(function (node) {
      const descriptor = lookupDescriptorForNode(node);
      const projection = resolveDisplayProjection(descriptor, saveResult);
      const payload = descriptor ? getCachedDescriptorPayload(descriptor.token) : null;

      setNodeDisplayValue(
        node,
        projection.value,
        projection.mode,
        descriptor,
        payload ? payload.currentValue : saveResult.value
      );
    });
  }

  async function openEditor(node, session) {
    const token = getMarkerToken(node);
    const panelNodes = getPanelNodes();
    const cached = getCachedDescriptorPayload(token);

    destroyActiveController();
    state.activeController = null;
    panelNodes.panel.dataset.state = 'loading';
    panelNodes.title.textContent = strings().panelTitle || 'Edit field';
    panelNodes.entityType.textContent = '';
    panelNodes.entityLinks.innerHTML = '';
    panelNodes.meta.innerHTML = '';
    panelNodes.notice.innerHTML = '';
    panelNodes.fieldLabel.textContent = '';
    panelNodes.fieldWrap.innerHTML = '<div class="dbvc-ve-panel__placeholder"></div>';
    panelNodes.fieldWrap.firstChild.textContent = strings().panelLoading || 'Loading field details…';
    panelNodes.status.textContent = '';
    panelNodes.saveButton.disabled = true;

    state.activeNode = node;
    state.activeDescriptor = null;
    state.activeRequiresSharedScopeAck = false;
    state.activeAcknowledgementType = 'none';
    state.sharedScopeAcknowledged = false;
    state.touchSelectionToken = token;
    clearDescriptorPrefetch();

    if (cached && cached.ok && cached.descriptor) {
      cached.sourceMismatch = hasSourceMismatch(node, cached);
      cached.renderedValue = getNodeDisplayValue(node, cached.descriptor);
      state.activeDescriptor = cached.descriptor;
      renderEditorPanel(cached);
      return;
    }

    try {
      const result = await loadDescriptorPayload(session.sessionId, token);
      result.sourceMismatch = hasSourceMismatch(node, result);
      result.renderedValue = getNodeDisplayValue(node, result.descriptor);
      state.activeDescriptor = result.descriptor;
      renderEditorPanel(result);
    } catch (error) {
      panelNodes.panel.dataset.state = 'error';
      panelNodes.status.textContent = error && error.message ? error.message : (strings().descriptorMissing || 'Descriptor not found.');
      window.console.error(error);
    }
  }

  async function handleSave() {
    const panelNodes = getPanelNodes();

    if (!state.session || !state.activeNode || !state.activeDescriptor || !state.activeController) {
      return;
    }

    const token = state.activeDescriptor.token;
    const value = state.activeController.getValue();
    const acknowledgeSharedScope = !state.activeRequiresSharedScopeAck || state.sharedScopeAcknowledged;

    if (!acknowledgeSharedScope) {
      panelNodes.panel.dataset.state = 'locked';
      const entityType = getDescriptorEntityType(state.activeDescriptor);
      panelNodes.status.textContent = state.activeAcknowledgementType === 'related'
        ? resolveRelatedRequiredText(entityType)
        : resolveSharedRequiredText(entityType);
      return;
    }

    panelNodes.panel.dataset.state = 'saving';
    panelNodes.status.textContent = strings().panelSaving || 'Saving…';
    panelNodes.saveButton.disabled = true;
    state.activeController.setDisabled(true);

    try {
      const saveResult = await window.DBVCVisualEditorApi.save(state.session.sessionId, token, value, acknowledgeSharedScope);
      const syncGroup = getActiveSyncGroup();
      const sourceGroup = getActiveSourceGroup();
      const saveSummaryMessage = formatSaveSummary(saveResult);

      updateCachedDescriptors(syncGroup, sourceGroup, saveResult);
      syncSavedDisplayValues(syncGroup, sourceGroup, saveResult);
      state.activeController.setValue(saveResult.value);
      state.activeController.setDisabled(false);
      if (saveResult && saveResult.entitySummary) {
        renderEntityHeader(saveResult, panelNodes);
      }
      if (saveResult && saveResult.sourceSummary) {
        renderSourceMeta(saveResult, panelNodes);
      }
      panelNodes.panel.dataset.state = 'saved';
      panelNodes.status.textContent = saveSummaryMessage
        || (saveResult && saveResult.message ? saveResult.message : (strings().panelSaved || 'Saved successfully.'));
      updateSaveButtonState(panelNodes, true);
      updateStatusBar({
        kind: 'ready',
        count: getMarkerCount(),
        message: saveSummaryMessage || (strings().panelSaved || 'Saved successfully.')
      });
    } catch (error) {
      panelNodes.panel.dataset.state = 'error';
      panelNodes.status.textContent = error && error.message ? error.message : (strings().saveFailed || 'Save failed.');

      if (state.activeController) {
        state.activeController.setDisabled(false);
      }

      updateSaveButtonState(panelNodes, true);

      window.console.error(error);
    }
  }

  async function mount() {
    if (!window.DBVCVisualEditorBootstrap || !window.DBVCVisualEditorBootstrap.active) {
      return;
    }

    document.body.classList.add('dbvc-ve-active');
    ensureStatusBar();
    ensureEditorPanel();
    ensureBadgeLayer();
    ensureSharedBadge();
    bindBadgeEvents();

    const markers = findMarkers();
    if (!markers.length) {
      updateStatusBar({
        kind: 'empty',
        count: 0,
        message: strings().zeroMarkers || 'No supported editable nodes were detected on this page yet.'
      });
      return;
    }

    let session;

    try {
      session = await window.DBVCVisualEditorApi.getSession(DBVCVisualEditorBootstrap.sessionId);
    } catch (error) {
      window.console.error(error);
    }

    if (!session || !session.ok || !session.sessionId) {
      window.console.warn(strings().sessionMissing || 'Visual Editor session not found for this page.');
      updateStatusBar({
        kind: 'error',
        count: markers.length,
        message: strings().sessionUnavailable || 'Markers were found, but the descriptor session was unavailable for this request.'
      });
      return;
    }

    state.session = session;
    cacheDescriptorHydrations(session.descriptorHydrations);

    if (state.previewNode) {
      scheduleDescriptorPrefetch(state.previewNode);
    }

    updateStatusBar({
      kind: 'ready',
      count: markers.length,
      message: ''
    });

    markers.forEach(function (node) {
      if (node.dataset.dbvcVeMounted === '1') {
        return;
      }

      node.dataset.dbvcVeMounted = '1';
      node.classList.add('dbvc-ve-target');
      if (node.dataset.dbvcVeScope === 'shared') {
        node.classList.add('is-shared');
      } else if (node.dataset.dbvcVeScope === 'related') {
        node.classList.add('is-related');
      }
      if (node.dataset.dbvcVeStatus === 'readonly') {
        node.classList.add('is-readonly');
      }
      node.dataset.dbvcVeDisplayValue = normalizeValue(readNodeComparableValue(node, lookupDescriptorForNode(node)));
    });

    scheduleBadgeLayout();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
})();
