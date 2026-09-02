/*
 * DBVC Visual Editor — R3-C-2 Brand Control Center drawer.
 *
 * Production translation of the accepted static mockup at
 *   docs/ui-mockups/dbvc-visual-editor/r3-brand-control-center/
 *
 * Contract:
 * - Discovery-only surface. The drawer lists the registered controls the
 *   current user is allowed to see (list route from R3-C-1) and opens one
 *   row's authoritative descriptor into the existing main editor panel
 *   (open route from R3-C-1). It never mutates content itself.
 * - Rows carry ONLY `data-public-id` — no `data-owner-id`, `data-field-key`,
 *   `data-selector`, `data-path`, `data-descriptor`, or `data-token`
 *   (schematic §6 invariant 2, enforced in jsdom).
 * - Filtering is client-side in R3 — no round-trips on tab / chip / search
 *   changes. The list response's `items` array is the source of truth for
 *   the drawer's lifetime.
 * - When a row's Open action succeeds, the drawer dispatches
 *   `dbvc:visual-editor:absorb-descriptor` with the R3-C-1 payload
 *   ({descriptors, descriptorHydrations, token, publicId}). `overlay-app.js`
 *   listens for that event, merges the descriptor into the session, and
 *   opens the existing panel — same helpers the Shared Globals popover
 *   already uses, so the panel behaves identically regardless of entry
 *   point.
 * - The drawer stays open while the panel is up (drawer + panel coexist).
 */

( function () {
	'use strict';

	const DEFAULT_QUERY = Object.freeze( {
		search: '',
		category: 'all',
		status: '',
		priority: '',
		fieldFamily: '',
	} );
	const FORBIDDEN_ROW_ATTRS = Object.freeze( [
		'data-owner-id',
		'data-field-key',
		'data-selector',
		'data-path',
		'data-descriptor',
		'data-token',
	] );
	const state = {
		root: null,
		trigger: null,
		hasLoaded: false,
		requestSequence: 0,
		requestStatus: 'idle',
		items: [],
		query: Object.assign( {}, DEFAULT_QUERY ),
		activePublicId: '',
		openingPublicId: '',
		openErrors: {},
		searchTimer: 0,
		error: null,
		announcer: null,
	};

	function bootstrap() {
		return window.DBVCVisualEditorBootstrap || {};
	}

	function config() {
		const value = bootstrap().controlCenter;

		return value && typeof value === 'object' ? value : {};
	}

	function strings() {
		const value = bootstrap().strings;

		return value && typeof value === 'object' ? value : {};
	}

	function text( key, fallback ) {
		const value = strings()[ key ];

		return typeof value === 'string' && value ? value : fallback;
	}

	function templateText( key, fallback, values ) {
		let output = text( key, fallback );

		Object.keys( values || {} ).forEach( function ( name ) {
			output = output
				.split( '{' + name + '}' )
				.join( String( values[ name ] ) );
		} );

		return output;
	}

	function nonce() {
		const value = bootstrap().nonce;

		return typeof value === 'string' && value ? value : '';
	}

	function sessionId() {
		const value = bootstrap().sessionId;

		return typeof value === 'string' && value ? value : '';
	}

	function restBase() {
		const value = config().restBase;

		return typeof value === 'string' && value
			? value.replace( /\/+$/, '' )
			: '';
	}

	function createElement( tagName, className, content ) {
		const node = document.createElement( tagName );

		if ( className ) {
			node.className = className;
		}

		if ( typeof content === 'string' ) {
			node.textContent = content;
		}

		return node;
	}

	function sanitizeAttr( value ) {
		return String( value || '' );
	}

	function classifyPriority( value ) {
		const priority = sanitizeAttr( value ).toLowerCase();
		if ( priority === 'must' || priority === 'should' || priority === 'nice' ) {
			return priority;
		}
		return '';
	}

	function classifyFieldFamily( family ) {
		const value = sanitizeAttr( family ).toLowerCase();
		if (
			value === 'text' ||
			value === 'image' ||
			value === 'gallery' ||
			value === 'relationship' ||
			value === 'post_object' ||
			value === 'other'
		) {
			return value;
		}
		return 'other';
	}

	function classifyStatus( status ) {
		const value = sanitizeAttr( status ).toLowerCase();
		if (
			value === 'available' ||
			value === 'inspect_only' ||
			value === 'unsupported' ||
			value === 'unavailable'
		) {
			return value;
		}
		return 'unavailable';
	}

	function categoryLabel( slug ) {
		const key =
			'controlCenterCategory' +
			slug.charAt( 0 ).toUpperCase() +
			slug.slice( 1 );
		return text( key, slug ? slug.charAt( 0 ).toUpperCase() + slug.slice( 1 ) : text( 'controlCenterCategoryGeneral', 'General' ) );
	}

	function statusLabel( status ) {
		if ( status === 'available' ) {
			return text( 'controlCenterStatusAvailable', 'Available' );
		}
		if ( status === 'inspect_only' ) {
			return text( 'controlCenterStatusInspectOnly', 'View only' );
		}
		if ( status === 'unsupported' ) {
			return text( 'controlCenterStatusUnsupported', 'Unsupported' );
		}
		return text( 'controlCenterStatusUnavailable', 'Unavailable' );
	}

	function ownerHint( item ) {
		return templateText(
			'controlCenterOwnerHint',
			'{ownerType}/{ownerSubtype} · {fieldFamily}',
			{
				ownerType: sanitizeAttr( item.ownerType ),
				ownerSubtype: sanitizeAttr( item.ownerSubtype ),
				fieldFamily: sanitizeAttr( item.fieldFamily ),
			}
		);
	}

	function priorityFromItem( item ) {
		if ( item && typeof item === 'object' ) {
			if ( typeof item.priority === 'string' ) {
				return classifyPriority( item.priority );
			}
			if (
				item.meta &&
				typeof item.meta === 'object' &&
				typeof item.meta.priority === 'string'
			) {
				return classifyPriority( item.meta.priority );
			}
		}
		return '';
	}

	function categoriesFromItems( items ) {
		const counts = {};
		items.forEach( function ( item ) {
			const category = sanitizeAttr( item.category ).toLowerCase() || 'general';
			counts[ category ] = ( counts[ category ] || 0 ) + 1;
		} );
		const ordered = Object.keys( counts ).sort();
		return ordered.map( function ( slug ) {
			return { slug, count: counts[ slug ] };
		} );
	}

	function itemMatchesFilters( item ) {
		const query = state.query;
		const category = sanitizeAttr( item.category ).toLowerCase() || 'general';
		const status = classifyStatus( item.status );
		const priority = priorityFromItem( item );
		const fieldFamily = classifyFieldFamily( item.fieldFamily );

		if ( query.category !== 'all' && category !== query.category ) {
			return false;
		}
		if ( query.status && status !== query.status ) {
			return false;
		}
		if ( query.priority && priority !== query.priority ) {
			return false;
		}
		if ( query.fieldFamily && fieldFamily !== query.fieldFamily ) {
			return false;
		}
		if ( query.search ) {
			const needle = query.search.toLowerCase();
			const haystack = [
				item.label,
				item.group,
				item.category,
				item.ownerSubtype,
				item.fieldFamily,
			]
				.map( function ( part ) {
					return sanitizeAttr( part ).toLowerCase();
				} )
				.join( ' ' );
			if ( haystack.indexOf( needle ) === -1 ) {
				return false;
			}
		}
		return true;
	}

	function filteredItems() {
		return state.items.filter( itemMatchesFilters );
	}

	function ensureRoot() {
		if ( state.root && state.root.isConnected ) {
			return state.root;
		}

		let root = document.getElementById( 'dbvc-ve-control-center' );

		if ( ! root ) {
			root = createElement( 'aside', 'dbvc-ve-control-center' );
			root.id = 'dbvc-ve-control-center';
			root.setAttribute( 'role', 'complementary' );
			root.setAttribute( 'aria-labelledby', 'dbvc-ve-control-center-title' );
			root.hidden = true;
			root.appendChild( createHeader() );
			root.appendChild( createTabs() );
			root.appendChild( createFilters() );
			root.appendChild( createTableWrap() );
			root.appendChild( createAnnouncer() );
			root.appendChild( createFooter() );
			document.body.appendChild( root );
		}

		state.root = root;
		state.announcer = root.querySelector(
			'[data-dbvc-ve-control-center-announcer]'
		);
		bindEventListeners( root );
		return root;
	}

	function createHeader() {
		const header = createElement( 'header', 'dbvc-ve-control-center__header' );
		const icon = createElement( 'span', 'dbvc-ve-control-center__header-icon' );
		icon.setAttribute( 'aria-hidden', 'true' );
		icon.textContent = '▤';
		const titleBlock = createElement(
			'div',
			'dbvc-ve-control-center__title-block'
		);
		const title = createElement(
			'h2',
			'dbvc-ve-control-center__title',
			text( 'controlCenterTitle', 'Global Brand Controls' )
		);
		title.id = 'dbvc-ve-control-center-title';
		const summary = createElement(
			'span',
			'dbvc-ve-control-center__summary-chip'
		);
		summary.setAttribute( 'data-dbvc-ve-control-center-summary', '1' );
		titleBlock.appendChild( title );
		titleBlock.appendChild( summary );
		const close = createElement(
			'button',
			'dbvc-ve-control-center__close',
			'×'
		);
		close.type = 'button';
		close.setAttribute(
			'aria-label',
			text( 'controlCenterClose', 'Close Global Brand Control Center' )
		);
		close.setAttribute( 'data-dbvc-ve-control-center-action', 'close' );
		header.appendChild( icon );
		header.appendChild( titleBlock );
		header.appendChild( close );
		return header;
	}

	function createTabs() {
		const tabs = createElement( 'div', 'dbvc-ve-control-center__tabs' );
		tabs.setAttribute( 'role', 'tablist' );
		tabs.setAttribute(
			'aria-label',
			text( 'controlCenterTablist', 'Category' )
		);
		tabs.setAttribute( 'data-dbvc-ve-control-center-tablist', '1' );
		return tabs;
	}

	function createFilters() {
		const filters = createElement( 'div', 'dbvc-ve-control-center__filters' );
		filters.setAttribute( 'data-dbvc-ve-control-center-filters', '1' );

		const searchLabel = createElement(
			'label',
			'dbvc-ve-control-center__sr-only',
			text( 'controlCenterSearchLabel', 'Search controls' )
		);
		searchLabel.setAttribute( 'for', 'dbvc-ve-control-center-search' );
		const search = createElement( 'input', 'dbvc-ve-control-center__search' );
		search.type = 'search';
		search.id = 'dbvc-ve-control-center-search';
		search.maxLength = 100;
		search.placeholder = text(
			'controlCenterSearchPlaceholder',
			'Search controls…'
		);
		search.setAttribute( 'data-dbvc-ve-control-center-query', 'search' );

		filters.appendChild( searchLabel );
		filters.appendChild( search );
		filters.appendChild(
			createChipRow(
				'status',
				text( 'controlCenterStatusLabel', 'Status' ),
				[
					{
						value: 'available',
						label: text( 'controlCenterStatusAvailable', 'Available' ),
					},
					{
						value: 'inspect_only',
						label: text( 'controlCenterStatusInspectOnly', 'View only' ),
					},
					{
						value: 'unsupported',
						label: text( 'controlCenterStatusUnsupported', 'Unsupported' ),
					},
					{
						value: 'unavailable',
						label: text( 'controlCenterStatusUnavailable', 'Unavailable' ),
					},
				]
			)
		);
		filters.appendChild(
			createChipRow(
				'priority',
				text( 'controlCenterPriorityLabel', 'Priority' ),
				[
					{ value: 'must', label: 'Must' },
					{ value: 'should', label: 'Should' },
					{ value: 'nice', label: 'Nice' },
				]
			)
		);
		filters.appendChild(
			createChipRow(
				'fieldFamily',
				text( 'controlCenterFieldLabel', 'Field' ),
				[
					{ value: 'image', label: 'Image' },
					{ value: 'gallery', label: 'Gallery' },
					{ value: 'relationship', label: 'Relationship' },
					{ value: 'post_object', label: 'Post' },
					{ value: 'other', label: 'Other' },
				]
			)
		);

		const clear = createElement(
			'button',
			'dbvc-ve-control-center__clear-filters',
			text( 'controlCenterClearFilters', 'Clear filters' )
		);
		clear.type = 'button';
		clear.setAttribute(
			'data-dbvc-ve-control-center-action',
			'clear-filters'
		);
		clear.hidden = true;
		filters.appendChild( clear );

		return filters;
	}

	function createChipRow( axis, label, chips ) {
		const row = createElement( 'div', 'dbvc-ve-control-center__chip-row' );
		row.setAttribute( 'role', 'group' );
		row.setAttribute( 'aria-label', label + ' filter' );
		const labelNode = createElement(
			'span',
			'dbvc-ve-control-center__chip-label',
			label
		);
		row.appendChild( labelNode );
		chips.forEach( function ( chip ) {
			const button = createElement(
				'button',
				'dbvc-ve-control-center__chip',
				chip.label
			);
			button.type = 'button';
			button.setAttribute( 'aria-pressed', 'false' );
			button.setAttribute(
				'data-dbvc-ve-control-center-chip',
				axis
			);
			button.setAttribute( 'data-value', chip.value );
			row.appendChild( button );
		} );
		return row;
	}

	function createTableWrap() {
		const wrap = createElement(
			'div',
			'dbvc-ve-control-center__table-wrap'
		);
		wrap.setAttribute( 'data-dbvc-ve-control-center-table-wrap', '1' );
		const table = createElement( 'table', 'dbvc-ve-control-center__table' );
		table.setAttribute( 'role', 'table' );
		const thead = createElement( 'thead', 'dbvc-ve-control-center__thead' );
		const headerRow = document.createElement( 'tr' );
		const th1 = createElement(
			'th',
			'dbvc-ve-control-center__th',
			text( 'controlCenterTitle', 'Global Brand Controls' )
		);
		th1.scope = 'col';
		th1.textContent = 'Control';
		const th2 = createElement(
			'th',
			'dbvc-ve-control-center__th dbvc-ve-control-center__th--action'
		);
		th2.scope = 'col';
		const th2Label = createElement(
			'span',
			'dbvc-ve-control-center__sr-only',
			'Actions'
		);
		th2.appendChild( th2Label );
		headerRow.appendChild( th1 );
		headerRow.appendChild( th2 );
		thead.appendChild( headerRow );
		const tbody = createElement( 'tbody', 'dbvc-ve-control-center__tbody' );
		tbody.setAttribute( 'data-dbvc-ve-control-center-tbody', '1' );
		table.appendChild( thead );
		table.appendChild( tbody );
		wrap.appendChild( table );
		return wrap;
	}

	function createAnnouncer() {
		const announcer = createElement(
			'p',
			'dbvc-ve-control-center__sr-only'
		);
		announcer.setAttribute( 'role', 'status' );
		announcer.setAttribute( 'aria-live', 'polite' );
		announcer.setAttribute( 'aria-atomic', 'true' );
		announcer.setAttribute( 'data-dbvc-ve-control-center-announcer', '1' );
		return announcer;
	}

	function createFooter() {
		const footer = createElement( 'footer', 'dbvc-ve-control-center__footer' );
		footer.setAttribute( 'data-dbvc-ve-control-center-footer', '1' );
		return footer;
	}

	function announce( message ) {
		if ( state.announcer ) {
			state.announcer.textContent = String( message || '' );
		}
	}

	function bindEventListeners( root ) {
		if ( root.dataset.controlCenterBound === '1' ) {
			return;
		}
		root.dataset.controlCenterBound = '1';
		root.addEventListener( 'click', handleClick );
		root.addEventListener( 'input', handleInput );
	}

	function handleClick( event ) {
		const target = event.target;
		if ( ! target || typeof target.closest !== 'function' ) {
			return;
		}

		const chip = target.closest( '[data-dbvc-ve-control-center-chip]' );
		if ( chip ) {
			event.preventDefault();
			toggleChip( chip );
			return;
		}

		const action = target.closest(
			'[data-dbvc-ve-control-center-action]'
		);
		if ( ! action ) {
			return;
		}

		const name = action.getAttribute(
			'data-dbvc-ve-control-center-action'
		);
		event.preventDefault();

		if ( name === 'close' ) {
			close( { restoreFocus: true } );
		} else if ( name === 'open' ) {
			openRow( action.getAttribute( 'data-public-id' ) || '' );
		} else if ( name === 'clear-filters' ) {
			clearFilters();
		} else if ( name === 'dismiss-notice' ) {
			dismissOpenError( action.getAttribute( 'data-public-id' ) || '' );
		} else if ( name === 'retry' ) {
			loadControls();
		} else if ( name === 'select-tab' ) {
			selectTab( action.getAttribute( 'data-category' ) || 'all' );
		}
	}

	function handleInput( event ) {
		const target = event.target;
		if ( ! target || typeof target.matches !== 'function' ) {
			return;
		}
		if ( target.matches( '[data-dbvc-ve-control-center-query="search"]' ) ) {
			window.clearTimeout( state.searchTimer );
			state.searchTimer = window.setTimeout( function () {
				state.query.search = String( target.value || '' ).trim();
				renderList();
			}, 180 );
		}
	}

	function toggleChip( chip ) {
		const axis = chip.getAttribute( 'data-dbvc-ve-control-center-chip' );
		const value = chip.getAttribute( 'data-value' ) || '';
		if ( ! axis || ! Object.prototype.hasOwnProperty.call( state.query, axis ) ) {
			return;
		}
		const current = state.query[ axis ];
		state.query[ axis ] = current === value ? '' : value;
		renderList();
	}

	function selectTab( category ) {
		state.query.category = sanitizeAttr( category ) || 'all';
		renderList();
	}

	function clearFilters() {
		state.query = Object.assign( {}, DEFAULT_QUERY, {
			category: state.query.category || 'all',
		} );
		renderList();
	}

	function dismissOpenError( publicId ) {
		if ( ! publicId ) {
			return;
		}
		delete state.openErrors[ publicId ];
		renderList();
	}

	function renderTabs() {
		if ( ! state.root ) {
			return;
		}
		const tablist = state.root.querySelector(
			'[data-dbvc-ve-control-center-tablist]'
		);
		if ( ! tablist ) {
			return;
		}
		while ( tablist.firstChild ) {
			tablist.removeChild( tablist.firstChild );
		}
		const total = state.items.length;
		const activeCategory = state.query.category || 'all';
		const allTab = createTabButton(
			'all',
			text( 'controlCenterTabAll', 'All' ),
			total,
			activeCategory === 'all'
		);
		tablist.appendChild( allTab );
		categoriesFromItems( state.items ).forEach( function ( entry ) {
			tablist.appendChild(
				createTabButton(
					entry.slug,
					categoryLabel( entry.slug ),
					entry.count,
					activeCategory === entry.slug
				)
			);
		} );
	}

	function createTabButton( slug, label, count, selected ) {
		const button = createElement(
			'button',
			'dbvc-ve-control-center__tab'
		);
		button.type = 'button';
		button.setAttribute( 'role', 'tab' );
		button.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
		button.setAttribute( 'data-dbvc-ve-control-center-action', 'select-tab' );
		button.setAttribute( 'data-category', slug );
		button.appendChild( document.createTextNode( label + ' ' ) );
		const badge = createElement(
			'span',
			'dbvc-ve-control-center__tab-count',
			String( count )
		);
		button.appendChild( badge );
		return button;
	}

	function renderFilters() {
		if ( ! state.root ) {
			return;
		}
		const filters = state.root.querySelector(
			'[data-dbvc-ve-control-center-filters]'
		);
		if ( ! filters ) {
			return;
		}
		filters
			.querySelectorAll( '[data-dbvc-ve-control-center-chip]' )
			.forEach( function ( chip ) {
				const axis = chip.getAttribute(
					'data-dbvc-ve-control-center-chip'
				);
				const value = chip.getAttribute( 'data-value' ) || '';
				const active = axis && state.query[ axis ] === value;
				chip.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
			} );
		const search = filters.querySelector(
			'[data-dbvc-ve-control-center-query="search"]'
		);
		if ( search && search.value !== state.query.search ) {
			search.value = state.query.search;
		}
		const clear = filters.querySelector(
			'[data-dbvc-ve-control-center-action="clear-filters"]'
		);
		if ( clear ) {
			clear.hidden = ! hasActiveFilters();
		}
	}

	function hasActiveFilters() {
		return (
			state.query.search !== '' ||
			state.query.status !== '' ||
			state.query.priority !== '' ||
			state.query.fieldFamily !== ''
		);
	}

	function renderSummary() {
		if ( ! state.root ) {
			return;
		}
		const summary = state.root.querySelector(
			'[data-dbvc-ve-control-center-summary]'
		);
		if ( ! summary ) {
			return;
		}
		summary.textContent = templateText(
			'controlCenterSummary',
			'{count} controls',
			{ count: state.items.length }
		);
	}

	function renderFooter() {
		if ( ! state.root ) {
			return;
		}
		const footer = state.root.querySelector(
			'[data-dbvc-ve-control-center-footer]'
		);
		if ( ! footer ) {
			return;
		}
		while ( footer.firstChild ) {
			footer.removeChild( footer.firstChild );
		}
		if ( ! state.items.length ) {
			return;
		}
		const visible = filteredItems().length;
		const total = state.items.length;
		const hidden = total - visible;
		const line = createElement( 'span' );
		line.appendChild(
			document.createTextNode(
				templateText(
					'controlCenterFooterCount',
					'{visible} of {total} controls',
					{ visible, total }
				)
			)
		);
		if ( hidden > 0 ) {
			line.appendChild(
				document.createTextNode(
					' · ' +
						templateText(
							'controlCenterFooterHidden',
							'{hidden} hidden by filters',
							{ hidden }
						)
				)
			);
		}
		footer.appendChild( line );
	}

	function renderList() {
		if ( ! state.root ) {
			return;
		}
		renderTabs();
		renderFilters();
		renderSummary();

		const wrap = state.root.querySelector(
			'[data-dbvc-ve-control-center-table-wrap]'
		);
		const tbody = state.root.querySelector(
			'[data-dbvc-ve-control-center-tbody]'
		);
		if ( ! wrap || ! tbody ) {
			return;
		}

		// Restore focus to the same publicId across rerenders (row-focus continuity).
		const doc = wrap.ownerDocument || document;
		const activeElement = doc.activeElement;
		const focusedPublicId =
			activeElement &&
			typeof activeElement.closest === 'function' &&
			activeElement.closest( '.dbvc-ve-control-center__row' )
				? activeElement
						.closest( '.dbvc-ve-control-center__row' )
						.getAttribute( 'data-public-id' ) || ''
				: '';

		removeExistingPanelState( wrap );

		if ( state.requestStatus === 'loading-initial' && ! state.items.length ) {
			renderPanelState(
				wrap,
				text( 'controlCenterLoadingTitle', 'Loading Global Brand Controls' ),
				text(
					'controlCenterLoadingBody',
					'Fetching registered controls for this session.'
				),
				[]
			);
			clearRows( tbody );
			return;
		}
		if ( state.requestStatus === 'error' ) {
			const message =
				state.error && state.error.message
					? String( state.error.message )
					: text(
							'controlCenterErrorBody',
							'The registered-controls request failed. Retry when you are ready.'
					  );
			renderPanelState(
				wrap,
				text(
					'controlCenterErrorTitle',
					'Controls could not be loaded'
				),
				message,
				[
					{
						action: 'retry',
						label: text( 'controlCenterRetry', 'Retry' ),
					},
				]
			);
			clearRows( tbody );
			return;
		}
		if ( ! state.items.length ) {
			renderPanelState(
				wrap,
				text(
					'controlCenterEmptyTitle',
					'No global controls registered yet'
				),
				text(
					'controlCenterEmptyBody',
					'Once a provider registers controls, they will appear here.'
				),
				[]
			);
			clearRows( tbody );
			return;
		}

		const visible = filteredItems();
		clearRows( tbody );

		if ( ! visible.length ) {
			renderPanelState(
				wrap,
				text(
					'controlCenterEmptyFilteredTitle',
					'No controls match these filters'
				),
				text(
					'controlCenterEmptyFilteredBody',
					'Clear the filters to see every registered control again.'
				),
				[
					{
						action: 'clear-filters',
						label: text( 'controlCenterClearFilters', 'Clear filters' ),
					},
				]
			);
			renderFooter();
			return;
		}

		visible.forEach( function ( item ) {
			tbody.appendChild( renderRow( item ) );
			if ( state.openErrors[ item.publicId ] ) {
				tbody.appendChild( renderRowNotice( item ) );
			}
		} );
		renderFooter();
		announce(
			templateText(
				'controlCenterAnnounceFiltered',
				'{count} controls visible after filters.',
				{ count: visible.length }
			)
		);

		// Row-focus continuity: restore focus onto the same publicId when possible.
		if ( focusedPublicId ) {
			const restored = tbody.querySelector(
				'.dbvc-ve-control-center__row[data-public-id="' +
					cssEscape( focusedPublicId ) +
					'"] .dbvc-ve-control-center__action, .dbvc-ve-control-center__row[data-public-id="' +
					cssEscape( focusedPublicId ) +
					'"] .dbvc-ve-control-center__action--view'
			);
			if ( restored && typeof restored.focus === 'function' ) {
				restored.focus();
			}
		}
	}

	function cssEscape( value ) {
		return String( value ).replace(
			/([\\!"#$%&'()*+,./:;<=>?@[\]^`{|}~])/g,
			'\\$1'
		);
	}

	function clearRows( tbody ) {
		while ( tbody.firstChild ) {
			tbody.removeChild( tbody.firstChild );
		}
	}

	function renderRow( item ) {
		const status = classifyStatus( item.status );
		const priority = priorityFromItem( item );
		const fieldFamily = classifyFieldFamily( item.fieldFamily );
		const publicId = sanitizeAttr( item.publicId );
		const row = createElement(
			'tr',
			'dbvc-ve-control-center__row is-' + status
		);
		row.setAttribute( 'data-public-id', publicId );
		row.setAttribute(
			'data-category',
			sanitizeAttr( item.category ).toLowerCase() || 'general'
		);
		row.setAttribute( 'data-status', status );
		if ( priority ) {
			row.setAttribute( 'data-priority', priority );
		}
		row.setAttribute( 'data-field-family', fieldFamily );
		if ( state.activePublicId && state.activePublicId === publicId ) {
			row.classList.add( 'is-focused-source' );
		}
		FORBIDDEN_ROW_ATTRS.forEach( function ( attr ) {
			row.removeAttribute( attr );
		} );

		const labelCell = createElement(
			'td',
			'dbvc-ve-control-center__row-cell dbvc-ve-control-center__row-cell--label'
		);
		labelCell.setAttribute( 'data-label', 'Control' );
		const dot = createElement(
			'span',
			'dbvc-ve-control-center__status-dot dbvc-ve-control-center__status-dot--' +
				status
		);
		dot.setAttribute( 'aria-hidden', 'true' );
		dot.setAttribute( 'title', statusLabel( status ) );
		labelCell.appendChild( dot );
		labelCell.appendChild(
			createElement(
				'span',
				'dbvc-ve-control-center__label',
				sanitizeAttr( item.label )
			)
		);
		labelCell.appendChild( renderMeta( item ) );
		labelCell.appendChild(
			createElement(
				'div',
				'dbvc-ve-control-center__owner',
				ownerHint( item )
			)
		);

		const actionCell = createElement(
			'td',
			'dbvc-ve-control-center__row-cell dbvc-ve-control-center__row-cell--action'
		);
		actionCell.setAttribute( 'data-label', 'Action' );
		actionCell.appendChild( renderAction( item, status, publicId ) );

		row.appendChild( labelCell );
		row.appendChild( actionCell );
		return row;
	}

	function renderMeta( item ) {
		const meta = createElement( 'div', 'dbvc-ve-control-center__meta' );
		const category = sanitizeAttr( item.category ).toLowerCase() || 'general';
		meta.appendChild(
			createElement(
				'span',
				'dbvc-ve-control-center__meta-part',
				categoryLabel( category )
			)
		);
		if ( item.group ) {
			meta.appendChild(
				createElement(
					'span',
					'dbvc-ve-control-center__meta-part',
					sanitizeAttr( item.group )
				)
			);
		}
		const badge =
			item.meta && typeof item.meta === 'object' && item.meta.badge
				? sanitizeAttr( item.meta.badge )
				: '';
		if ( badge ) {
			meta.appendChild(
				createElement( 'span', 'dbvc-ve-control-center__badge', badge )
			);
		}
		return meta;
	}

	function renderAction( item, status, publicId ) {
		if ( status === 'unsupported' ) {
			return createElement(
				'span',
				'dbvc-ve-control-center__action-none',
				text( 'controlCenterActionUnsupported', 'Unsupported' )
			);
		}
		if ( status === 'unavailable' ) {
			return createElement(
				'span',
				'dbvc-ve-control-center__action-none',
				text( 'controlCenterActionUnavailable', 'Unavailable' )
			);
		}
		const isOpening = state.openingPublicId === publicId;
		const isView = status === 'inspect_only';
		let className = 'dbvc-ve-control-center__action';
		if ( isView ) {
			className += ' dbvc-ve-control-center__action--view';
		}
		if ( isOpening ) {
			className += ' dbvc-ve-control-center__action--opening';
		}
		const button = createElement( 'button', className );
		button.type = 'button';
		button.setAttribute( 'data-dbvc-ve-control-center-action', 'open' );
		button.setAttribute( 'data-public-id', publicId );
		if ( isOpening ) {
			button.setAttribute( 'aria-busy', 'true' );
			button.disabled = true;
			button.textContent = text( 'controlCenterActionOpening', 'Opening…' );
			const spinner = createElement(
				'span',
				'dbvc-ve-control-center__spinner'
			);
			spinner.setAttribute( 'aria-hidden', 'true' );
			button.appendChild( spinner );
		} else {
			button.textContent = isView
				? text( 'controlCenterActionView', 'View' )
				: text( 'controlCenterActionOpen', 'Open' );
		}
		return button;
	}

	function renderRowNotice( item ) {
		const notice = state.openErrors[ item.publicId ];
		const row = createElement( 'tr', 'dbvc-ve-control-center__row-notice' );
		const cell = createElement(
			'td',
			'dbvc-ve-control-center__row-notice-cell'
		);
		cell.colSpan = 2;
		const noticeNode = createElement(
			'div',
			'dbvc-ve-control-center__notice ' +
				( notice.severity === 'error'
					? 'is-error'
					: notice.severity === 'warning'
					? 'is-warning'
					: '' )
		);
		noticeNode.setAttribute(
			'role',
			notice.severity === 'error' ? 'alert' : 'status'
		);
		noticeNode.appendChild(
			document.createTextNode( notice.message )
		);
		const dismiss = createElement(
			'button',
			'dbvc-ve-control-center__notice-dismiss',
			text( 'controlCenterDismiss', 'Dismiss' )
		);
		dismiss.type = 'button';
		dismiss.setAttribute(
			'data-dbvc-ve-control-center-action',
			'dismiss-notice'
		);
		dismiss.setAttribute( 'data-public-id', item.publicId );
		noticeNode.appendChild( dismiss );
		cell.appendChild( noticeNode );
		row.appendChild( cell );
		return row;
	}

	function renderPanelState( wrap, title, body, actions ) {
		removeExistingPanelState( wrap );
		const panel = createElement(
			'div',
			'dbvc-ve-control-center__panel-state'
		);
		panel.setAttribute( 'data-dbvc-ve-control-center-panel-state', '1' );
		if ( state.requestStatus === 'loading-initial' ) {
			const spinner = createElement(
				'div',
				'dbvc-ve-control-center__loading-spinner'
			);
			spinner.setAttribute( 'aria-hidden', 'true' );
			panel.appendChild( spinner );
		}
		panel.appendChild(
			createElement(
				'p',
				'dbvc-ve-control-center__panel-state-title',
				title
			)
		);
		panel.appendChild(
			createElement( 'p', 'dbvc-ve-control-center__panel-state-body', body )
		);
		if ( actions && actions.length ) {
			const actionsRow = createElement(
				'div',
				'dbvc-ve-control-center__panel-state-actions'
			);
			actions.forEach( function ( action ) {
				const button = createElement(
					'button',
					'dbvc-ve-control-center__button dbvc-ve-control-center__button--secondary',
					action.label
				);
				button.type = 'button';
				button.setAttribute(
					'data-dbvc-ve-control-center-action',
					action.action
				);
				actionsRow.appendChild( button );
			} );
			panel.appendChild( actionsRow );
		}
		wrap.appendChild( panel );
	}

	function removeExistingPanelState( wrap ) {
		const existing = wrap.querySelector(
			'[data-dbvc-ve-control-center-panel-state]'
		);
		if ( existing && existing.parentNode ) {
			existing.parentNode.removeChild( existing );
		}
	}

	function listUrl() {
		return (
			restBase() +
			'/session/' +
			encodeURIComponent( sessionId() ) +
			'/control-center/controls'
		);
	}

	function openUrl() {
		return (
			restBase() +
			'/session/' +
			encodeURIComponent( sessionId() ) +
			'/control-center/open'
		);
	}

	function loadControls() {
		if ( ! sessionId() ) {
			return Promise.resolve();
		}
		const requestId = ++state.requestSequence;
		state.requestStatus = 'loading-initial';
		state.error = null;
		renderList();
		return window
			.fetch( listUrl(), {
				method: 'GET',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'X-WP-Nonce': nonce(),
				},
			} )
			.then( async function ( response ) {
				const payload = await response.json().catch( function () {
					return null;
				} );
				if ( requestId !== state.requestSequence ) {
					return;
				}
				if ( ! response.ok || ! payload || payload.ok !== true ) {
					state.requestStatus = 'error';
					state.error = payload && payload.message
						? { message: payload.message }
						: {
								message: text(
									'controlCenterErrorBody',
									'The registered-controls request failed. Retry when you are ready.'
								),
						  };
					renderList();
					return;
				}
				state.items = Array.isArray( payload.items ) ? payload.items : [];
				state.requestStatus = 'success';
				state.hasLoaded = true;
				renderList();
				announce(
					templateText(
						'controlCenterAnnounceOpened',
						'Global Brand Controls opened. Showing {count} registered controls.',
						{ count: state.items.length }
					)
				);
			} )
			.catch( function ( error ) {
				if ( requestId !== state.requestSequence ) {
					return;
				}
				state.requestStatus = 'error';
				state.error = {
					message:
						error && error.message
							? String( error.message )
							: text(
									'controlCenterErrorBody',
									'The registered-controls request failed. Retry when you are ready.'
							  ),
				};
				renderList();
			} );
	}

	function openRow( publicId ) {
		publicId = sanitizeAttr( publicId );
		if ( ! publicId || state.openingPublicId === publicId ) {
			return;
		}
		state.openingPublicId = publicId;
		delete state.openErrors[ publicId ];
		renderList();

		window
			.fetch( openUrl(), {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce(),
				},
				body: JSON.stringify( { publicId } ),
			} )
			.then( async function ( response ) {
				const payload = await response.json().catch( function () {
					return null;
				} );
				state.openingPublicId = '';
				if ( ! response.ok || ! payload || payload.ok !== true ) {
					recordOpenError( publicId, response.status, payload );
					renderList();
					return;
				}
				const token = firstTokenFrom( payload.descriptors );
				state.activePublicId = publicId;
				renderList();
				document.dispatchEvent(
					new CustomEvent(
						'dbvc:visual-editor:absorb-descriptor',
						{
							detail: {
								publicId,
								token,
								descriptors: payload.descriptors,
								descriptorHydrations: payload.descriptorHydrations,
							},
						}
					)
				);
				announce(
					templateText(
						'controlCenterAnnounceOpenSuccess',
						'Opened {label}.',
						{ label: labelForPublicId( publicId ) }
					)
				);
			} )
			.catch( function ( error ) {
				state.openingPublicId = '';
				recordOpenError( publicId, 0, {
					message:
						error && error.message
							? String( error.message )
							: '',
				} );
				renderList();
			} );
	}

	function firstTokenFrom( descriptors ) {
		if ( ! descriptors || typeof descriptors !== 'object' ) {
			return '';
		}
		const keys = Object.keys( descriptors );
		return keys.length ? String( keys[ 0 ] ) : '';
	}

	function labelForPublicId( publicId ) {
		const match = state.items.find( function ( item ) {
			return item.publicId === publicId;
		} );
		return match ? String( match.label || publicId ) : publicId;
	}

	function recordOpenError( publicId, status, payload ) {
		const severity = status === 409 ? 'error' : 'warning';
		const message =
			payload && payload.message
				? String( payload.message )
				: status === 404
				? text(
						'controlCenterOpenErrorUnknown',
						'That control is no longer available.'
				  )
				: status === 403
				? text(
						'controlCenterOpenErrorForbidden',
						'You cannot edit that control right now.'
				  )
				: status === 409
				? text(
						'controlCenterOpenErrorRefresh',
						'The control changed since it was listed. Refresh the drawer before trying again.'
				  )
				: text(
						'controlCenterErrorBody',
						'The registered-controls request failed. Retry when you are ready.'
				  );
		state.openErrors[ publicId ] = { severity, message };
		announce(
			templateText(
				'controlCenterAnnounceOpenError',
				'Could not open {label}. {message}',
				{ label: labelForPublicId( publicId ), message }
			)
		);
	}

	function setTriggerExpanded( expanded ) {
		if ( ! state.trigger || ! state.trigger.isConnected ) {
			return;
		}
		state.trigger.setAttribute(
			'aria-expanded',
			expanded ? 'true' : 'false'
		);
	}

	function open( options ) {
		const root = ensureRoot();
		const trigger = options && options.trigger;
		const activeElement = root.ownerDocument.activeElement;

		if ( trigger && trigger.isConnected ) {
			state.trigger = trigger;
		} else if (
			! state.trigger &&
			activeElement instanceof window.HTMLElement
		) {
			state.trigger = activeElement;
		}

		root.hidden = false;
		root.classList.remove( 'is-closed' );
		root.setAttribute( 'aria-hidden', 'false' );
		setTriggerExpanded( true );

		const closeButton = root.querySelector(
			'[data-dbvc-ve-control-center-action="close"]'
		);
		if ( closeButton && typeof closeButton.focus === 'function' ) {
			window.requestAnimationFrame( function () {
				closeButton.focus();
			} );
		}

		document.dispatchEvent(
			new CustomEvent( 'dbvc:visual-editor:control-center:opened' )
		);
		if ( ! state.hasLoaded && state.requestStatus !== 'loading-initial' ) {
			loadControls();
		} else {
			renderList();
		}
	}

	function close( options ) {
		const root = state.root;
		const restoreFocus = ! options || options.restoreFocus !== false;
		const trigger = state.trigger;
		if ( ! root || root.hidden ) {
			setTriggerExpanded( false );
			return;
		}
		root.hidden = true;
		root.setAttribute( 'aria-hidden', 'true' );
		setTriggerExpanded( false );
		window.clearTimeout( state.searchTimer );
		state.searchTimer = 0;
		if (
			restoreFocus &&
			trigger &&
			trigger.isConnected &&
			typeof trigger.focus === 'function'
		) {
			trigger.focus();
		}
		document.dispatchEvent(
			new CustomEvent( 'dbvc:visual-editor:control-center:closed' )
		);
		announce(
			text( 'controlCenterAnnounceClosed', 'Global Brand Controls closed.' )
		);
	}

	function toggle( options ) {
		const root = ensureRoot();
		if ( root.hidden ) {
			open( options || {} );
		} else {
			close( { restoreFocus: true } );
		}
	}

	function isOpen() {
		return Boolean( state.root && ! state.root.hidden );
	}

	function publicState() {
		return {
			hasLoaded: state.hasLoaded,
			requestStatus: state.requestStatus,
			items: state.items.slice(),
			query: Object.assign( {}, state.query ),
			openErrors: Object.assign( {}, state.openErrors ),
			openingPublicId: state.openingPublicId,
			activePublicId: state.activePublicId,
			isOpen: isOpen(),
		};
	}

	function handleKeydown( event ) {
		if (
			! state.root ||
			state.root.hidden ||
			event.key !== 'Escape'
		) {
			return;
		}
		event.preventDefault();
		event.stopPropagation();
		close( { restoreFocus: true } );
	}

	function mount() {
		if ( ! bootstrap().active || config().enabled !== true ) {
			return;
		}

		document.addEventListener(
			'dbvc:visual-editor:control-center:toggle',
			function ( event ) {
				toggle( event && event.detail ? event.detail : {} );
			}
		);
		document.addEventListener(
			'dbvc:visual-editor:control-center:close',
			function ( event ) {
				close( event && event.detail ? event.detail : {} );
			}
		);
		document.addEventListener( 'keydown', handleKeydown, true );

		window.DBVCVisualEditorBrandControlCenter = {
			open,
			close,
			toggle,
			list: loadControls,
			isOpen,
			getState: publicState,
		};
	}

	mount();
} )();
