(function () {
  window.DBVCVisualEditorApi = {
    getSession(sessionId, options) {
      const shouldHydrate = Boolean(options && options.hydrate);
      const query = shouldHydrate ? '?hydrate=1' : '';

      return fetch(`${DBVCVisualEditorBootstrap.restBase}/session/${encodeURIComponent(sessionId)}${query}`, {
        headers: { 'X-WP-Nonce': DBVCVisualEditorBootstrap.nonce }
      }).then(async (response) => {
        const data = await response.json().catch(function () {
          return null;
        });

        if (response.ok) {
          return data;
        }

        throw new Error((data && data.message) || `Visual Editor session request failed (${response.status}).`);
      });
    },

    getDescriptor(sessionId, token) {
      return fetch(`${DBVCVisualEditorBootstrap.restBase}/session/${encodeURIComponent(sessionId)}/descriptor/${encodeURIComponent(token)}`, {
        headers: { 'X-WP-Nonce': DBVCVisualEditorBootstrap.nonce }
      }).then(async (response) => {
        const data = await response.json().catch(function () {
          return null;
        });

        if (response.ok) {
          return data;
        }

        throw new Error((data && data.message) || `Visual Editor descriptor request failed (${response.status}).`);
      });
    },

    searchReferences(sessionId, token, search) {
      const params = new URLSearchParams();

      if (typeof search === 'string' && search.trim()) {
        params.set('search', search.trim());
      }

      return fetch(`${DBVCVisualEditorBootstrap.restBase}/session/${encodeURIComponent(sessionId)}/reference-search/${encodeURIComponent(token)}?${params.toString()}`, {
        headers: { 'X-WP-Nonce': DBVCVisualEditorBootstrap.nonce }
      }).then(async (response) => {
        const data = await response.json().catch(function () {
          return null;
        });

        if (response.ok) {
          return data;
        }

        throw new Error((data && data.message) || `Visual Editor reference search failed (${response.status}).`);
      });
    },

    searchObjects(search, objectType) {
      const params = new URLSearchParams();

      if (typeof search === 'string' && search.trim()) {
        params.set('search', search.trim());
      }

      if (typeof objectType === 'string' && objectType.trim() && objectType !== 'all') {
        params.set('objectType', objectType.trim());
      }

      return fetch(`${DBVCVisualEditorBootstrap.restBase}/object-search?${params.toString()}`, {
        headers: { 'X-WP-Nonce': DBVCVisualEditorBootstrap.nonce }
      }).then(async (response) => {
        const data = await response.json().catch(function () {
          return null;
        });

        if (response.ok) {
          return data;
        }

        throw new Error((data && data.message) || `Visual Editor object search failed (${response.status}).`);
      });
    },

    getSharedGlobalFields(sessionId) {
      return fetch(`${DBVCVisualEditorBootstrap.restBase}/session/${encodeURIComponent(sessionId)}/shared-global-fields`, {
        headers: { 'X-WP-Nonce': DBVCVisualEditorBootstrap.nonce }
      }).then(async (response) => {
        const data = await response.json().catch(function () {
          return null;
        });

        if (response.ok) {
          return data;
        }

        throw new Error((data && data.message) || `Visual Editor shared global fields request failed (${response.status}).`);
      });
    },

    save(sessionId, token, value, acknowledgeSharedScope) {
      return fetch(`${DBVCVisualEditorBootstrap.restBase}/session/${encodeURIComponent(sessionId)}/save`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': DBVCVisualEditorBootstrap.nonce
        },
        body: JSON.stringify({ token, value, acknowledgeSharedScope: Boolean(acknowledgeSharedScope) })
      }).then(async (response) => {
        const data = await response.json().catch(function () {
          return null;
        });

        if (response.ok) {
          return data;
        }

        throw new Error((data && data.message) || `Visual Editor save request failed (${response.status}).`);
      });
    },

    seedCurrentField(sessionId, token, options) {
      const payload = Object.assign({
        acknowledgeSeed: true,
        mode: 'seed'
      }, options || {});

      return fetch(`${DBVCVisualEditorBootstrap.restBase}/session/${encodeURIComponent(sessionId)}/collection-seed/${encodeURIComponent(token)}`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': DBVCVisualEditorBootstrap.nonce
        },
        body: JSON.stringify(payload)
      }).then(async (response) => {
        const data = await response.json().catch(function () {
          return null;
        });

        if (response.ok) {
          return data;
        }

        throw new Error((data && data.message) || `Visual Editor collection seed request failed (${response.status}).`);
      });
    }
  };
})();
