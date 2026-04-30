(function () {
  window.DBVCVisualEditorApi = {
    getSession(sessionId) {
      return fetch(`${DBVCVisualEditorBootstrap.restBase}/session/${encodeURIComponent(sessionId)}?hydrate=1`, {
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
    }
  };
})();
