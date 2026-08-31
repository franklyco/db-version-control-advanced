/**
 * DBVC Visual Editor - R1 Media Manager static mockup, local-only interaction script.
 *
 * MOCKUP ONLY. This file exists so a reviewer can feel the interaction model. It is not a
 * production module and must not be translated verbatim.
 *
 * Hard rules honoured here:
 *   - no network request, no fetch, no XHR, no beacon
 *   - no persistence: no localStorage, sessionStorage, cookie, or IndexedDB
 *   - no production state store
 *   - no mutation vocabulary: nothing here saves, assigns, uploads, or hydrates anything
 *   - no exact filtered total is ever computed or displayed
 *
 * Search, filter, and sort below SIMULATE a server round-trip against the rows already in
 * the DOM. Production does not do this: every query change re-requests the page via
 * GET /dbvc/v1/visual-editor/media-manager/scans/{scanRef} and renders whatever order the
 * read model returns. A production client must never re-sort a server-ordered page.
 */
( function () {
	'use strict';

	const root = document.querySelector( '.dbvc-ve-media-manager' );
	if ( ! root ) {
		return;
	}

	const announcer = root.querySelector( '[data-mockup-live="announcer"]' );
	const tbody = root.querySelector( '[data-mockup-region="rows"]' );
	const emptyState = root.querySelector( '[data-mockup-region="empty"]' );
	const results = root.querySelector( '[data-mockup-region="results"]' );
	const reopen = document.querySelector( '[data-mockup-action="reopen"]' );

	/**
	 * Announces a short message through the polite live region.
	 *
	 * @param {string} message Human-readable status text.
	 */
	function announce( message ) {
		if ( announcer ) {
			announcer.textContent = message;
		}
	}

	/**
	 * Returns the expansion row a toggle controls.
	 *
	 * @param {HTMLElement} toggle Row toggle button.
	 * @return {HTMLElement|null} Expansion row element.
	 */
	function expansionFor( toggle ) {
		const id = toggle.getAttribute( 'aria-controls' );
		return id ? document.getElementById( id ) : null;
	}

	/**
	 * Collapses every expanded row. R1 keeps a single expansion open at a time so each
	 * expansion corresponds to one revalidation request.
	 *
	 * @param {HTMLElement} [except] Toggle to leave untouched.
	 */
	function collapseAll( except ) {
		const toggles = root.querySelectorAll(
			'[data-mockup-action="toggle-row"]'
		);
		Array.prototype.forEach.call( toggles, function ( toggle ) {
			if ( toggle === except ) {
				return;
			}
			const expansion = expansionFor( toggle );
			toggle.setAttribute( 'aria-expanded', 'false' );
			toggle
				.closest( '.dbvc-ve-media-manager__row' )
				.classList.remove( 'is-expanded' );
			if ( expansion ) {
				expansion.hidden = true;
			}
		} );
	}

	/**
	 * Toggles one row, collapsing any other open row first.
	 *
	 * @param {HTMLElement} toggle Row toggle button.
	 */
	function toggleRow( toggle ) {
		const row = toggle.closest( '.dbvc-ve-media-manager__row' );
		const expansion = expansionFor( toggle );
		const wasExpanded = toggle.getAttribute( 'aria-expanded' ) === 'true';
		const label = row.querySelector(
			'.dbvc-ve-media-manager__entity-label'
		);
		const name = label ? label.textContent.trim() : 'entity';

		collapseAll( toggle );

		toggle.setAttribute( 'aria-expanded', wasExpanded ? 'false' : 'true' );
		row.classList.toggle( 'is-expanded', ! wasExpanded );
		if ( expansion ) {
			expansion.hidden = wasExpanded;
		}

		if ( wasExpanded ) {
			announce( 'Collapsed ' + name + '.' );
			return;
		}

		const panel = expansion
			? expansion.querySelector( '.dbvc-ve-media-manager__expansion' )
			: null;

		// Production revalidates here via GET /scans/{scanRef}/groups/{groupRef}. The
		// announcement must describe what the DOM actually shows at the moment it is read:
		// a pending panel may never be announced as revalidated.
		if ( ! panel || panel.classList.contains( 'is-pending' ) ) {
			announce(
				'Expanded ' + name + '. Requesting field detail. Still pending.'
			);
		} else {
			announce( 'Expanded ' + name + '. ' + revalidatedSummary( panel ) );
		}

		if ( panel ) {
			panel.focus();
		}
	}

	/**
	 * Describes a revalidated expansion from the panel the user can actually see.
	 *
	 * Reads the rendered status and field rows rather than any stored payload, so the
	 * sentence can never claim a result the DOM does not show.
	 *
	 * @param {HTMLElement} panel Expansion panel element.
	 * @return {string} Sentence describing the revalidated state.
	 */
	function revalidatedSummary( panel ) {
		const status = panel.getAttribute( 'data-expansion-status' ) || '';
		const fields = panel.querySelectorAll(
			'.dbvc-ve-media-manager__field'
		).length;
		const wording = {
			current: 'Revalidated: current.',
			changed: 'Revalidated: scan evidence changed.',
			resolved_or_changed: 'Revalidated: no longer confirmed missing.',
			unavailable: 'Revalidation could not complete.',
		};

		return (
			( wording[ status ] || 'Revalidated.' ) +
			' ' +
			count( fields, 'field', 'fields' ) +
			' listed.'
		);
	}

	/**
	 * Formats a count with a correctly pluralised noun.
	 *
	 * @param {number} total    The count.
	 * @param {string} singular Noun for exactly one.
	 * @param {string} plural   Noun for any other count.
	 * @return {string} e.g. "1 entity" or "6 entities".
	 */
	function count( total, singular, plural ) {
		return total + ' ' + ( total === 1 ? singular : plural );
	}

	/**
	 * Reads the current query controls.
	 *
	 * @return {{search: string, entity: string, field: string, sort: string}} Query state.
	 */
	function readQuery() {
		const search = root.querySelector( '[data-mockup-input="search"]' );
		const entity = root.querySelector(
			'[data-mockup-input="entity-family"]:checked'
		);
		const field = root.querySelector(
			'[data-mockup-input="field-family"]:checked'
		);
		const sort = root.querySelector( '[data-mockup-input="sort"]' );

		return {
			search: search ? search.value.trim().toLowerCase() : '',
			entity: entity ? entity.value : 'all',
			field: field ? field.value : 'all',
			sort: sort ? sort.value : 'entity_asc',
		};
	}

	/**
	 * Applies the simulated server response: filters and orders the rows already in the DOM.
	 *
	 * Deliberately reports "showing N of the current page" rather than any filtered total,
	 * because R1 never exposes an exact filtered count.
	 */
	function applyQuery() {
		const query = readQuery();
		const rows = Array.prototype.slice.call(
			root.querySelectorAll( '.dbvc-ve-media-manager__row' )
		);
		let visible = 0;

		rows.forEach( function ( row ) {
			const families = (
				row.getAttribute( 'data-field-families' ) || ''
			).split( ' ' );
			const label = row.getAttribute( 'data-entity-label' ) || '';
			const matches =
				( query.search === '' ||
					label.indexOf( query.search ) !== -1 ) &&
				( query.entity === 'all' ||
					row.getAttribute( 'data-entity-family' ) ===
						query.entity ) &&
				( query.field === 'all' ||
					families.indexOf( query.field ) !== -1 );

			row.hidden = ! matches;

			const toggle = row.querySelector(
				'[data-mockup-action="toggle-row"]'
			);
			const expansion = toggle ? expansionFor( toggle ) : null;
			if ( expansion && ! matches ) {
				expansion.hidden = true;
				toggle.setAttribute( 'aria-expanded', 'false' );
				row.classList.remove( 'is-expanded' );
			}

			if ( matches ) {
				visible += 1;
			}
		} );

		reorder( rows, query.sort );
		markSortColumn( query.sort );

		if ( emptyState ) {
			emptyState.hidden = visible !== 0;
		}

		announce(
			visible === 0
				? 'No entities match these filters.'
				: 'Showing ' +
						count( visible, 'entity', 'entities' ) +
						' on this page.'
		);
	}

	/**
	 * Reorders row/expansion pairs to match the selected sort key.
	 *
	 * @param {Array<HTMLElement>} rows Entity rows.
	 * @param {string}             sort Sort key.
	 */
	function reorder( rows, sort ) {
		if ( ! tbody ) {
			return;
		}

		const sorted = rows.slice();

		if ( sort === 'missing_desc' ) {
			sorted.sort( function ( a, b ) {
				return (
					Number( b.getAttribute( 'data-missing' ) ) -
					Number( a.getAttribute( 'data-missing' ) )
				);
			} );
		} else if ( sort === 'scanned_desc' ) {
			sorted.sort( function ( a, b ) {
				return (
					Number( b.getAttribute( 'data-scanned' ) ) -
					Number( a.getAttribute( 'data-scanned' ) )
				);
			} );
		} else {
			sorted.sort( function ( a, b ) {
				return (
					a.getAttribute( 'data-entity-label' ) || ''
				).localeCompare( b.getAttribute( 'data-entity-label' ) || '' );
			} );
		}

		sorted.forEach( function ( row ) {
			const toggle = row.querySelector(
				'[data-mockup-action="toggle-row"]'
			);
			const expansion = toggle ? expansionFor( toggle ) : null;
			tbody.appendChild( row );
			if ( expansion ) {
				tbody.appendChild( expansion );
			}
		} );
	}

	/**
	 * Moves aria-sort to the header matching the active sort key.
	 *
	 * @param {string} sort Sort key.
	 */
	function markSortColumn( sort ) {
		const headers = root.querySelectorAll( '[data-mockup-sortcol]' );
		Array.prototype.forEach.call( headers, function ( header ) {
			if ( header.getAttribute( 'data-mockup-sortcol' ) === sort ) {
				header.setAttribute(
					'aria-sort',
					sort === 'entity_asc' ? 'ascending' : 'descending'
				);
			} else {
				header.removeAttribute( 'aria-sort' );
			}
		} );
	}

	root.addEventListener( 'click', function ( event ) {
		const trigger = event.target.closest( '[data-mockup-action]' );
		if ( ! trigger || ! root.contains( trigger ) ) {
			return;
		}

		const action = trigger.getAttribute( 'data-mockup-action' );

		if ( action === 'toggle-row' ) {
			toggleRow( trigger );
			return;
		}

		if ( action === 'close' ) {
			root.hidden = true;
			if ( reopen ) {
				reopen.hidden = false;
				reopen.focus();
			}
			return;
		}

		if ( action === 'refresh' ) {
			// Production posts to /scans here and polls chunks. The mockup only narrates.
			announce(
				'Refresh requested. In production this starts a new scan and reports progress.'
			);
			return;
		}

		if ( action === 'load-more' ) {
			// A second cursor page would be invented data, so the control reports the limit instead.
			trigger.disabled = true;
			announce(
				'This mockup bundles a single fixture page. In production this requests the next cursor page.'
			);
		}
	} );

	root.addEventListener( 'input', function ( event ) {
		if ( event.target.matches( '[data-mockup-input="search"]' ) ) {
			applyQuery();
		}
	} );

	root.addEventListener( 'change', function ( event ) {
		if ( event.target.matches( '[data-mockup-input]' ) ) {
			applyQuery();
		}
	} );

	if ( reopen ) {
		reopen.addEventListener( 'click', function () {
			root.hidden = false;
			reopen.hidden = true;
			if ( results ) {
				results.focus();
			}
			announce( 'Media Manager reopened.' );
		} );
	}

	// Escape closes the drawer, matching the overlay panel convention.
	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key !== 'Escape' || root.hidden ) {
			return;
		}
		const close = root.querySelector( '[data-mockup-action="close"]' );
		if ( close ) {
			close.click();
		}
	} );
} )();
