/*
 * R6 Site Manager workspace — gallery-only helpers (states.html).
 * Two presentation toggles, nothing else: no state store, no network, no
 * persistence, no production event names. index.html does not load this file.
 */
( function () {
	'use strict';
	var body = document.body;
	var toggles = document.querySelector( '[data-mockup-toggles]' );
	if ( ! toggles ) {
		return;
	}
	toggles.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-mockup-toggle]' );
		if ( ! button ) {
			return;
		}
		var kind = button.getAttribute( 'data-mockup-toggle' );
		var pressed = button.getAttribute( 'aria-pressed' ) === 'true';
		button.setAttribute( 'aria-pressed', pressed ? 'false' : 'true' );
		if ( kind === 'dark' ) {
			body.classList.toggle( 'dbvc-ve-workspace-mockup--dark', ! pressed );
		} else if ( kind === 'motion' ) {
			body.classList.toggle( 'dbvc-ve-workspace-mockup--motion-off', ! pressed );
		}
	} );
} )();
