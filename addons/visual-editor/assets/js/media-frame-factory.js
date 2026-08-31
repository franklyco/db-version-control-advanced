( function () {
	/**
	 * RK-011 Slice 1 — shared `wp.media` frame factory.
	 *
	 * One construction site for both `overlay-app.js` and `media-manager-app.js` so
	 * frame configuration cannot drift again. This slice is deliberately
	 * behavior-neutral: it synthesizes the frame config and offers a guarded
	 * disposer, but each caller keeps ownership of its own selection handling,
	 * prefetch hook, and lifecycle discipline (Slice 2 promotes the overlay onto
	 * the single-active-frame pattern; Slice 3 moves the prefetch hook here).
	 */
	function supportsWpMedia() {
		return !! (
			typeof window !== 'undefined' &&
			window.wp &&
			typeof window.wp.media === 'function'
		);
	}

	/**
	 * Guarded frame teardown: `detach()` first (so DOM listeners drop), then
	 * `remove()` (Backbone.View) or `dispose()` (some jsdom mocks). Failure to
	 * tear down must not break the surrounding panel lifecycle.
	 *
	 * @param {object|null} frame
	 */
	function disposeFrame( frame ) {
		if ( ! frame ) {
			return;
		}
		try {
			if ( typeof frame.detach === 'function' ) {
				frame.detach();
			}
			if ( typeof frame.remove === 'function' ) {
				frame.remove();
			} else if ( typeof frame.dispose === 'function' ) {
				frame.dispose();
			}
		} catch ( error ) {
			// A frame that fails to tear down must not break the panel lifecycle.
		}
	}

	/**
	 * Build a single-image or ordered-multiple `wp.media` frame with the standard
	 * Visual Editor / Media Manager configuration.
	 *
	 * @param {Object}              options
	 * @param {'single'|'multiple'} options.mode
	 * @param {string}              options.title
	 * @param {string}              options.buttonText
	 * @param {Object}              [options.previousFrame] Optional: tear this frame down before
	 *                                                      constructing the new one. Lets a caller
	 *                                                      adopt the single-active-frame pattern
	 *                                                      by passing its current reference.
	 * @return {{frame: object | null, dispose: Function}}
	 */
	function createMediaFrame( options ) {
		options = options || {};

		if ( options.previousFrame ) {
			disposeFrame( options.previousFrame );
		}

		if ( ! supportsWpMedia() ) {
			return { frame: null, dispose() {} };
		}

		const isMultiple = options.mode === 'multiple';
		const frame = window.wp.media( {
			title: options.title || '',
			button: { text: options.buttonText || '' },
			library: { type: 'image' },
			multiple: isMultiple,
		} );

		return {
			frame,
			dispose() {
				disposeFrame( frame );
			},
		};
	}

	window.DBVCVisualEditorMediaFrame = {
		supportsWpMedia,
		createMediaFrame,
		disposeFrame,
	};
} )();
