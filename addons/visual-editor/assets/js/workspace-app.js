/*
 * DBVC Visual Editor — R6 Frontend Site Manager Workspace drawer.
 *
 * Production translation of the accepted static mockup at
 *   docs/ui-mockups/dbvc-visual-editor/r6-site-manager-workspace/
 * against the runtime contract at
 *   docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/R6-WORKSPACE-STATE-CONTRACT.md
 *
 * R6-D-1 shipped the SHELL: flag-gated mount, toolbar bridge, open / close /
 * toggle, Escape precedence, localStorage persistence, inert-under-overlay
 * (BCC / Media Manager), the current-object card, the Navigate / Tools
 * section tablist, and the Tools routes. R6-D-2 added OBJECT NAVIGATION:
 * the sticky search + type strip, paginated results against the R6-A
 * `object-search` read model through `DBVCVisualEditorApi.searchObjects`,
 * honest Open / Edit routing, stale-response guarding, focus continuity,
 * and the empty / error / mode-inactive states. The overlay-app side of
 * Review Fields routing and the panel clamp inset land in R6-D-3.
 *
 * Contract invariants held here:
 * - Navigation + routing only. The drawer never writes; the main editor
 *   panel stays the only field editor.
 * - `role="complementary"` landmark: no focus trap, outside click does not
 *   close, Escape closes only when nothing above it is open (contract §6).
 * - Talks to siblings exclusively through `dbvc:visual-editor:*` events and
 *   the public `window.DBVCVisualEditor*` APIs; never reaches into their DOM.
 * - Persists only `{ isOpen, section, activeType }` (D-070).
 */

( function () {
	'use strict';

	const ROOT_ID = 'dbvc-ve-workspace';
	const STORAGE_KEY = 'dbvc-ve-workspace:v1';
	// VE-prefs-2 (2026-09-16): viewer preferences are owned and written by
	// overlay-app.js (same key, VE-prefs-1). Read-only here — only the
	// `workspaceStartup` field matters, and only at mount.
	const PREFERENCES_STORAGE_KEY = 'dbvc-ve-preferences:v1';
	const WORKSPACE_STARTUPS = [ 'remember', 'open', 'closed' ];
	const DRAWER_WIDTH = '480px';
	const SECTIONS = [ 'navigate', 'tools' ];
	const PER_PAGE = 20;
	const SEARCH_DEBOUNCE_MS = 180;
	const SEARCH_MAX_LENGTH = 100;
	// R6.1-b: sort vocabulary (mirrors ObjectNavigationReadModel::SORTS).
	// `relevance` is search-scoped: offered only while a term exists, auto-
	// selected when typing starts from `recent`, and never persisted.
	const SORTS = [ 'recent', 'title_asc', 'title_desc', 'newest', 'oldest', 'relevance' ];
	const DEFAULT_SORT = 'recent';
	const KINDS = [ 'all', 'post', 'term' ];

	const state = {
		root: null,
		trigger: null,
		announcer: null,
		isOpen: false,
		section: 'navigate',
		// R6-D-2 fills these against the object-search read model.
		types: [],
		typesStatus: 'idle',
		activeType: { objectType: 'all', subtype: '' },
		sort: DEFAULT_SORT,
		// true while `relevance` was switched on automatically by typing, so
		// clearing the search can switch back to what the viewer had chosen.
		autoRelevance: false,
		search: '',
		results: [],
		page: 0,
		hasMore: false,
		requestId: 0,
		requestStatus: 'idle',
		requestError: null,
		focusedItemKey: null,
		currentObject: null,
		overlay: 'none',
		modeStatus: 'active',
		listenersBound: false,
		searchTimer: 0,
		pendingFocusFirstAppended: false,
		// The Tools button that launched the BCC / Media Manager. Those apps
		// restore focus to it on close, but they do so BEFORE dispatching
		// `:closed` — i.e. while our root is still `inert`, so the focus call
		// silently fails. We finish the hand-back ourselves (contract §5.2).
		lastToolTrigger: null,
		mounted: false,
	};

	// ------------------------------------------------------------------
	// Bootstrap helpers (same shape as brand-control-center-app.js)
	// ------------------------------------------------------------------

	function bootstrap() {
		return window.DBVCVisualEditorBootstrap || {};
	}

	function config() {
		const value = bootstrap().workspace;

		return value && typeof value === 'object' ? value : {};
	}

	function featureConfig( key ) {
		const value = bootstrap()[ key ];

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

	function svgIcon( name ) {
		const attrs =
			'aria-hidden="true" focusable="false" viewBox="0 0 24 24"';
		const common =
			'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
		const icons = {
			grid: `<svg ${ attrs }><rect ${ common } x="3" y="3" width="7" height="7" rx="1.5"/><rect ${ common } x="14" y="3" width="7" height="7" rx="1.5"/><rect ${ common } x="3" y="14" width="7" height="7" rx="1.5"/><rect ${ common } x="14" y="14" width="7" height="7" rx="1.5"/></svg>`,
			close: `<svg ${ attrs }><path ${ common } d="m6 6 12 12M18 6 6 18"/></svg>`,
			page: `<svg ${ attrs }><path ${ common } d="M7 3h7l5 5v13H7z"/><path ${ common } d="M14 3v5h5"/><path ${ common } d="M10 13h6M10 17h6"/></svg>`,
			term: `<svg ${ attrs }><path ${ common } d="M3 11V4h7l10 10-7 7z"/><circle fill="currentColor" cx="7.5" cy="8.5" r="1.5"/></svg>`,
			external: `<svg ${ attrs } class="dbvc-ve-workspace__action-icon"><path ${ common } d="M14 4h6v6"/><path ${ common } d="M20 4 10 14"/><path ${ common } d="M18 13v6H5V6h6"/></svg>`,
			chevron: `<svg ${ attrs }><path ${ common } d="m9 6 6 6-6 6"/></svg>`,
			layers: `<svg ${ attrs }><path ${ common } d="m12 3 8 4-8 4-8-4 8-4Z"/><path ${ common } d="m4 12 8 4 8-4"/><path ${ common } d="m4 17 8 4 8-4"/></svg>`,
			sliders: `<svg ${ attrs }><path ${ common } d="M4 6h11"/><circle ${ common } cx="18" cy="6" r="2"/><path ${ common } d="M9 12H4"/><circle ${ common } cx="12" cy="12" r="2"/><path ${ common } d="M16 12h4"/><path ${ common } d="M4 18h11"/><circle ${ common } cx="18" cy="18" r="2"/></svg>`,
			media: `<svg ${ attrs }><rect ${ common } x="3" y="4" width="18" height="16" rx="2.5"/><circle ${ common } cx="8.75" cy="9.75" r="1.6"/><path ${ common } d="m4 16.5 4.5-4a1.8 1.8 0 0 1 2.4 0l3 2.7a1.8 1.8 0 0 0 2.4 0l3.7-2.6"/></svg>`,
			edit: `<svg ${ attrs }><path ${ common } d="M4 20h16"/><path ${ common } d="m6 16 1-4 9-9 4 4-9 9-4 1Z"/><path ${ common } d="m14 5 4 4"/></svg>`,
			power: `<svg ${ attrs }><path ${ common } d="M12 2v10"/><path ${ common } d="M18.4 6.6a8 8 0 1 1-12.8 0"/></svg>`,
			post: `<svg ${ attrs }><path ${ common } d="M4 5h16v14H4z"/><path ${ common } d="M7 9h10M7 12h10M7 15h6"/></svg>`,
			lock: `<svg ${ attrs }><rect ${ common } x="5" y="10" width="14" height="10" rx="2"/><path ${ common } d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>`,
			clear: `<svg ${ attrs }><circle ${ common } cx="12" cy="12" r="8"/><path ${ common } d="m9 9 6 6M15 9l-6 6"/></svg>`,
			spinner: `<svg ${ attrs } class="dbvc-ve-workspace__spinner"><circle ${ common } cx="12" cy="12" r="8" stroke-dasharray="36 14"/></svg>`,
		};
		const span = createElement( 'span', 'dbvc-ve-workspace__glyph' );
		span.setAttribute( 'aria-hidden', 'true' );
		span.innerHTML = icons[ name ] || '';

		return span;
	}

	// ------------------------------------------------------------------
	// Persistence (D-070) — localStorage, try/catch wrapped, minimal shape.
	// ------------------------------------------------------------------

	function normalizeSort( value, allowRelevance ) {
		if ( SORTS.indexOf( value ) === -1 ) {
			return DEFAULT_SORT;
		}

		return value === 'relevance' && ! allowRelevance ? DEFAULT_SORT : value;
	}

	/**
	 * VE-prefs-2: `remember` (default) restores the persisted drawer state
	 * (D-070); `open` / `closed` override it on every page load. The
	 * persisted value itself is left untouched so switching back to
	 * Remember restores what the viewer last had.
	 */
	function startupPreference() {
		try {
			const raw = window.localStorage.getItem( PREFERENCES_STORAGE_KEY );
			const parsed = raw ? JSON.parse( raw ) : null;
			const value = parsed && typeof parsed === 'object' ? parsed.workspaceStartup : '';

			return WORKSPACE_STARTUPS.indexOf( value ) === -1 ? 'remember' : value;
		} catch ( _err ) {
			return 'remember';
		}
	}

	function loadPersisted() {
		const defaults = {
			isOpen: false,
			section: 'navigate',
			activeType: { objectType: 'all', subtype: '' },
			sort: DEFAULT_SORT,
		};

		try {
			const raw = window.localStorage.getItem( STORAGE_KEY );

			if ( ! raw ) {
				return defaults;
			}

			const parsed = JSON.parse( raw );

			if ( ! parsed || typeof parsed !== 'object' ) {
				return defaults;
			}

			const activeType =
				parsed.activeType && typeof parsed.activeType === 'object'
					? parsed.activeType
					: {};
			const objectType = String( activeType.objectType || 'all' );

			return {
				isOpen: parsed.isOpen === true,
				section:
					SECTIONS.indexOf( parsed.section ) === -1
						? 'navigate'
						: parsed.section,
				activeType: {
					objectType:
						[ 'all', 'post', 'term' ].indexOf( objectType ) === -1
							? 'all'
							: objectType,
					subtype: String( activeType.subtype || '' ),
				},
				// D-070 extension (R6.1-b): sort is view state; `relevance` is
				// never restored because it only means something with a term.
				sort: normalizeSort( String( parsed.sort || '' ), false ),
			};
		} catch ( _err ) {
			return defaults;
		}
	}

	function persist() {
		try {
			window.localStorage.setItem(
				STORAGE_KEY,
				JSON.stringify( {
					isOpen: state.isOpen,
					section: state.section,
					activeType: {
						objectType: state.activeType.objectType,
						subtype: state.activeType.subtype,
					},
					sort: state.sort === 'relevance' ? DEFAULT_SORT : state.sort,
				} )
			);
		} catch ( _err ) {
			/* private window / quota / permission — drop silently */
		}
	}

	// ------------------------------------------------------------------
	// Current object (bootstrap only — no request)
	// ------------------------------------------------------------------

	function resolveCurrentObject() {
		const pageContext =
			bootstrap().pageContext &&
			typeof bootstrap().pageContext === 'object'
				? bootstrap().pageContext
				: {};
		const editLink =
			bootstrap().currentEditLink &&
			typeof bootstrap().currentEditLink === 'object'
				? bootstrap().currentEditLink
				: null;
		const url = pageContext.url ? String( pageContext.url ) : '';
		const backendUrl =
			editLink && typeof editLink.url === 'string' ? editLink.url : '';
		const entityType = pageContext.entityType === 'term' ? 'term' : 'post';

		if ( ! url && ! backendUrl ) {
			return null;
		}

		return {
			objectType: entityType,
			id: Number( pageContext.entityId || 0 ) || 0,
			// R6-D-2: pageContext.title is the safe display title emitted by
			// PageContextResolver; older bootstraps without it fall back to
			// the type label rather than the edit link's button text.
			title:
				typeof pageContext.title === 'string' && pageContext.title
					? pageContext.title
					: entityType === 'term'
					? text( 'workspaceCurrentTerm', 'Current term' )
					: text( 'workspaceCurrentPage', 'Current page' ),
			typeLabel:
				entityType === 'term'
					? text( 'workspaceCurrentTerm', 'Current term' )
					: text( 'workspaceCurrentPage', 'Current page' ),
			frontendUrl: url,
			hasFrontendRoute: Boolean( url ),
			backendUrl,
			canEdit: Boolean( backendUrl ),
		};
	}

	// ------------------------------------------------------------------
	// DOM construction
	// ------------------------------------------------------------------

	function createHeader() {
		const header = createElement( 'header', 'dbvc-ve-workspace__header' );
		const icon = createElement( 'span', 'dbvc-ve-workspace__header-icon' );
		icon.setAttribute( 'aria-hidden', 'true' );
		icon.appendChild( svgIcon( 'grid' ) );

		const block = createElement( 'div', 'dbvc-ve-workspace__title-block' );
		block.appendChild(
			createElement(
				'span',
				'dbvc-ve-workspace__eyebrow',
				text( 'workspaceEyebrow', 'Visual Editor' )
			)
		);
		const title = createElement(
			'h2',
			'dbvc-ve-workspace__title',
			text( 'workspaceTitle', 'Site Manager' )
		);
		title.id = ROOT_ID + '-title';
		block.appendChild( title );

		const close = createElement( 'button', 'dbvc-ve-workspace__close' );
		close.type = 'button';
		close.setAttribute(
			'aria-label',
			text( 'workspaceClose', 'Close Site Manager' )
		);
		close.setAttribute( 'data-dbvc-ve-workspace-action', 'close' );
		close.appendChild( svgIcon( 'close' ) );

		header.appendChild( icon );
		header.appendChild( block );
		header.appendChild( close );

		return header;
	}

	function createActionLink( label, href, action, options ) {
		const settings = options || {};
		const link = createElement(
			'a',
			'dbvc-ve-workspace__action dbvc-ve-workspace__action--' +
				( settings.primary ? 'primary' : 'secondary' )
		);
		link.href = href;
		link.setAttribute( 'data-dbvc-ve-workspace-action', action );
		link.appendChild( document.createTextNode( label ) );

		if ( settings.newTab ) {
			link.target = '_blank';
			link.rel = 'noopener noreferrer';
			const sr = createElement(
				'span',
				'dbvc-ve-workspace__sr-only',
				' ' + text( 'workspaceOpensNewTab', '(opens in a new tab)' )
			);
			link.appendChild( sr );
			link.appendChild( svgIcon( 'external' ) );
		}

		return link;
	}

	function createCurrentCard() {
		const section = createElement( 'section', 'dbvc-ve-workspace__current' );
		section.setAttribute( 'data-dbvc-ve-workspace-current', '1' );
		const labelId = ROOT_ID + '-current-label';
		section.setAttribute( 'aria-labelledby', labelId );
		const current = state.currentObject;

		if ( ! current ) {
			section.hidden = true;
			return section;
		}

		const overline = createElement(
			'span',
			'dbvc-ve-workspace__overline',
			text( 'workspaceCurrentLabel', 'Current object' )
		);
		overline.id = labelId;
		section.appendChild( overline );

		const row = createElement( 'div', 'dbvc-ve-workspace__current-row' );
		const glyph = createElement( 'span', 'dbvc-ve-workspace__row-glyph' );
		glyph.setAttribute( 'aria-hidden', 'true' );
		glyph.appendChild(
			svgIcon( current.objectType === 'term' ? 'term' : 'page' )
		);
		row.appendChild( glyph );

		const textWrap = createElement( 'div', 'dbvc-ve-workspace__row-text' );
		const title = createElement(
			'span',
			'dbvc-ve-workspace__row-title',
			current.title
		);
		title.title = current.title;
		textWrap.appendChild( title );
		const meta = createElement( 'span', 'dbvc-ve-workspace__row-meta' );
		meta.appendChild(
			createElement(
				'span',
				'dbvc-ve-workspace__row-type',
				current.typeLabel
			)
		);
		meta.appendChild(
			createElement(
				'span',
				'dbvc-ve-workspace__here',
				text( 'workspaceCurrentHere', 'You are here' )
			)
		);
		textWrap.appendChild( meta );
		row.appendChild( textWrap );

		const actions = createElement( 'div', 'dbvc-ve-workspace__row-actions' );
		// D-075: no self-Open when the frontend URL is the page we are on.
		if (
			current.hasFrontendRoute &&
			! isCurrentLocation( current.frontendUrl )
		) {
			actions.appendChild(
				createActionLink(
					text( 'workspaceOpenFrontend', 'Open' ),
					current.frontendUrl,
					'current-frontend',
					{ primary: ! current.backendUrl }
				)
			);
		}

		if ( current.backendUrl ) {
			actions.appendChild(
				createActionLink(
					text( 'workspaceOpenBackend', 'Edit' ),
					current.backendUrl,
					'current-backend',
					{ primary: true, newTab: true }
				)
			);
		}

		row.appendChild( actions );
		section.appendChild( row );

		return section;
	}

	function isCurrentLocation( url ) {
		try {
			const target = new window.URL( url, window.location.href );
			const here = new window.URL( window.location.href );

			return (
				target.origin === here.origin &&
				target.pathname.replace( /\/+$/, '' ) ===
					here.pathname.replace( /\/+$/, '' )
			);
		} catch ( _err ) {
			return false;
		}
	}

	function createSectionTabs() {
		const list = createElement( 'div', 'dbvc-ve-workspace__sections' );
		list.setAttribute( 'role', 'tablist' );
		list.setAttribute(
			'aria-label',
			text( 'workspaceSectionsLabel', 'Site Manager sections' )
		);

		SECTIONS.forEach( function ( section ) {
			const tab = createElement(
				'button',
				'dbvc-ve-workspace__section-tab',
				section === 'tools'
					? text( 'workspaceSectionTools', 'Tools' )
					: text( 'workspaceSectionNavigate', 'Navigate' )
			);
			tab.type = 'button';
			tab.id = ROOT_ID + '-tab-' + section;
			tab.setAttribute( 'role', 'tab' );
			tab.setAttribute( 'aria-controls', ROOT_ID + '-panel-' + section );
			tab.setAttribute( 'data-dbvc-ve-workspace-action', 'section' );
			tab.setAttribute( 'data-dbvc-ve-workspace-section', section );
			list.appendChild( tab );
		} );

		return list;
	}

	function createNavigateBody() {
		const body = createElement(
			'div',
			'dbvc-ve-workspace__body dbvc-ve-workspace__body--navigate'
		);
		body.id = ROOT_ID + '-panel-navigate';
		body.setAttribute( 'role', 'tabpanel' );
		body.setAttribute( 'aria-labelledby', ROOT_ID + '-tab-navigate' );
		body.setAttribute( 'data-dbvc-ve-workspace-panel', 'navigate' );

		// Sticky strip: search + type tablist (contract §4).
		const sticky = createElement( 'div', 'dbvc-ve-workspace__sticky' );
		sticky.appendChild( createSearchWrap() );
		sticky.appendChild( createControls() );
		const types = createElement( 'div', 'dbvc-ve-workspace__types' );
		types.setAttribute( 'role', 'tablist' );
		types.setAttribute(
			'aria-label',
			text( 'workspaceTypesLabel', 'Object types' )
		);
		types.setAttribute( 'data-dbvc-ve-workspace-types', '1' );
		sticky.appendChild( types );
		body.appendChild( sticky );

		// The single polite live region the whole section shares
		// (component map §6 — one region, never two).
		const status = createElement( 'p', 'dbvc-ve-workspace__status' );
		status.id = ROOT_ID + '-status';
		status.setAttribute( 'role', 'status' );
		status.setAttribute( 'aria-live', 'polite' );
		status.setAttribute( 'aria-atomic', 'true' );
		status.setAttribute( 'data-dbvc-ve-workspace-status', '1' );
		status.textContent = text(
			'workspaceStatusIdle',
			'Search or pick a type to start.'
		);
		body.appendChild( status );

		// Empty / error notice slot (rendered above the retained rows).
		const notice = createElement( 'div' );
		notice.setAttribute( 'data-dbvc-ve-workspace-notice', '1' );
		body.appendChild( notice );

		const results = createElement( 'ul', 'dbvc-ve-workspace__results' );
		results.setAttribute( 'role', 'list' );
		results.setAttribute(
			'aria-label',
			text( 'workspaceResultsLabel', 'Objects' )
		);
		results.setAttribute( 'data-dbvc-ve-workspace-results', '1' );
		body.appendChild( results );

		const footer = createElement( 'footer', 'dbvc-ve-workspace__footer' );
		footer.setAttribute( 'data-dbvc-ve-workspace-footer', '1' );
		body.appendChild( footer );

		return body;
	}

	function createSearchWrap() {
		const wrap = createElement( 'div', 'dbvc-ve-workspace__search-wrap' );
		const label = createElement(
			'label',
			'dbvc-ve-workspace__sr-only',
			text( 'workspaceSearchLabel', 'Search objects' )
		);
		label.htmlFor = ROOT_ID + '-search';
		const input = createElement( 'input', 'dbvc-ve-workspace__search' );
		input.type = 'search';
		input.id = ROOT_ID + '-search';
		input.placeholder = text(
			'workspaceSearchPlaceholder',
			'Search pages, posts, and terms…'
		);
		input.maxLength = SEARCH_MAX_LENGTH;
		input.autocomplete = 'off';
		input.setAttribute( 'aria-describedby', ROOT_ID + '-status' );
		input.setAttribute( 'data-dbvc-ve-workspace-action', 'search' );
		const clear = createElement( 'button', 'dbvc-ve-workspace__search-clear' );
		clear.type = 'button';
		clear.hidden = true;
		clear.setAttribute(
			'aria-label',
			text( 'workspaceSearchClear', 'Clear search' )
		);
		clear.setAttribute( 'data-dbvc-ve-workspace-action', 'clear-search' );
		clear.appendChild( svgIcon( 'clear' ) );
		wrap.appendChild( label );
		wrap.appendChild( input );
		wrap.appendChild( clear );

		return wrap;
	}

	/**
	 * R6.1-b — the controls row: kind filter (All · Content · Taxonomies) as
	 * a segmented radiogroup, and the sort select. Both render from state in
	 * renderControls(); this only builds the static shell.
	 */
	function createControls() {
		const row = createElement( 'div', 'dbvc-ve-workspace__controls' );

		const kind = createElement( 'div', 'dbvc-ve-workspace__segmented' );
		kind.setAttribute( 'role', 'radiogroup' );
		kind.setAttribute( 'aria-label', text( 'workspaceKindLabel', 'Show' ) );
		kind.setAttribute( 'data-dbvc-ve-workspace-kinds', '1' );
		row.appendChild( kind );

		const sortWrap = createElement( 'div', 'dbvc-ve-workspace__sort' );
		const sortLabel = createElement(
			'label',
			'dbvc-ve-workspace__sr-only',
			text( 'workspaceSortLabel', 'Sort' )
		);
		sortLabel.htmlFor = ROOT_ID + '-sort';
		const select = createElement( 'select', 'dbvc-ve-workspace__sort-select' );
		select.id = ROOT_ID + '-sort';
		select.setAttribute( 'data-dbvc-ve-workspace-action', 'sort' );
		sortWrap.appendChild( sortLabel );
		sortWrap.appendChild( select );
		row.appendChild( sortWrap );

		return row;
	}

	function kindLabel( kind ) {
		if ( kind === 'post' ) {
			return text( 'workspaceKindContent', 'Content' );
		}

		if ( kind === 'term' ) {
			return text( 'workspaceKindTaxonomies', 'Taxonomies' );
		}

		return text( 'workspaceKindAll', 'All' );
	}

	function sortLabel( sort ) {
		switch ( sort ) {
			case 'title_asc':
				return text( 'workspaceSortTitleAsc', 'Title A → Z' );
			case 'title_desc':
				return text( 'workspaceSortTitleDesc', 'Title Z → A' );
			case 'newest':
				return text( 'workspaceSortNewest', 'Newest first' );
			case 'oldest':
				return text( 'workspaceSortOldest', 'Oldest first' );
			case 'relevance':
				return text( 'workspaceSortRelevance', 'Best match' );
			default:
				return text( 'workspaceSortRecent', 'Recently updated' );
		}
	}

	function currentKind() {
		return KINDS.indexOf( state.activeType.objectType ) === -1
			? 'all'
			: state.activeType.objectType;
	}

	function renderControls() {
		const kinds = navigateNode( '[data-dbvc-ve-workspace-kinds]' );
		const select = navigateNode( '[data-dbvc-ve-workspace-action="sort"]' );

		if ( kinds ) {
			const active = currentKind();

			// Segments are built once and updated in place so a keyboard user
			// arrowing through the group keeps focus across the loading and
			// response renders (rebuilding the buttons would drop it to <body>).
			if ( ! kinds.firstChild ) {
				KINDS.forEach( function ( kind ) {
					const button = createElement( 'button', 'dbvc-ve-workspace__segment', kindLabel( kind ) );
					button.type = 'button';
					button.setAttribute( 'role', 'radio' );
					button.setAttribute( 'data-dbvc-ve-workspace-action', 'kind' );
					button.setAttribute( 'data-dbvc-ve-workspace-kind', kind );
					kinds.appendChild( button );
				} );
			}

			Array.prototype.forEach.call( kinds.children, function ( button ) {
				const checked = button.getAttribute( 'data-dbvc-ve-workspace-kind' ) === active;
				button.classList.toggle( 'is-checked', checked );
				button.setAttribute( 'aria-checked', checked ? 'true' : 'false' );
				button.tabIndex = checked ? 0 : -1;
			} );
		}

		if ( select ) {
			const offered = SORTS.filter( function ( sort ) {
				return sort !== 'relevance' || state.search !== '';
			} );
			const previous = select.value;
			select.textContent = '';
			offered.forEach( function ( sort ) {
				const option = createElement( 'option', '', sortLabel( sort ) );
				option.value = sort;
				select.appendChild( option );
			} );
			select.value = offered.indexOf( state.sort ) === -1 ? DEFAULT_SORT : state.sort;
			select.disabled = state.modeStatus === 'unsafe' || state.typesStatus === 'loading';

			if ( previous !== select.value ) {
				select.setAttribute( 'data-dbvc-ve-workspace-sort', select.value );
			}
		}
	}

	function setKind( kind ) {
		const next = KINDS.indexOf( kind ) === -1 ? 'all' : kind;

		if ( next === currentKind() && state.activeType.subtype === '' ) {
			return;
		}

		state.activeType = { objectType: next, subtype: '' };
		persist();
		runSearch();
	}

	function setSort( value ) {
		const next = normalizeSort( value, state.search !== '' );

		if ( next === state.sort ) {
			return;
		}

		state.sort = next;
		state.autoRelevance = false;
		persist();
		runSearch();
	}

	/**
	 * Typing with the default order switches to Best match; clearing the
	 * term switches back. An explicit non-default choice is left alone.
	 */
	function syncRelevanceWithSearch() {
		if ( state.search !== '' ) {
			if ( state.sort === DEFAULT_SORT ) {
				state.sort = 'relevance';
				state.autoRelevance = true;
			}
			return;
		}

		if ( state.sort === 'relevance' ) {
			state.sort = DEFAULT_SORT;
			state.autoRelevance = false;
		}
	}

	// ------------------------------------------------------------------
	// Navigation — query helpers
	// ------------------------------------------------------------------

	function navigateNode( selector ) {
		return state.root ? state.root.querySelector( selector ) : null;
	}

	function typeKey( type ) {
		return ( type.objectType || 'all' ) + ':' + ( type.subtype || '' );
	}

	/**
	 * R6.1-b: true when discovery returned at least one type of `kind`, so a
	 * kind-only activeType (subtype '') can be restored or kept.
	 */
	function kindIsOffered( kind ) {
		if ( state.activeType.subtype !== '' ) {
			return false;
		}

		return state.types.some( function ( type ) {
			return type.objectType === kind;
		} );
	}

	function activeTypeDescriptor() {
		if ( state.activeType.objectType === 'all' ) {
			return null;
		}

		for ( let index = 0; index < state.types.length; index++ ) {
			if ( typeKey( state.types[ index ] ) === typeKey( state.activeType ) ) {
				return state.types[ index ];
			}
		}

		return null;
	}

	function activeTypeLabel() {
		const descriptor = activeTypeDescriptor();

		if ( descriptor ) {
			return String( descriptor.label || descriptor.subtype );
		}

		// R6.1-b: a kind-only selection reads as "Content" / "Taxonomies"
		// in the empty-state copy rather than "All".
		return kindLabel( currentKind() );
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

	function api() {
		const value = window.DBVCVisualEditorApi;

		return value && typeof value.searchObjects === 'function' ? value : null;
	}

	// ------------------------------------------------------------------
	// Navigation — requests
	// ------------------------------------------------------------------

	/**
	 * Run (or continue) the current query. The first request of a page
	 * load also asks for `types[]` so discovery + page 1 cost one
	 * round-trip; every later request omits it. `requestId` drops stale
	 * responses so a fast type switch never paints an older page.
	 */
	function runSearch( options ) {
		const settings = options || {};
		const client = api();
		const append = settings.append === true;
		const page = append ? state.page + 1 : 1;
		const includeTypes = state.typesStatus !== 'ready';
		const requestId = state.requestId + 1;

		state.requestId = requestId;
		state.requestStatus = append ? 'loading-more' : 'loading-initial';
		state.requestError = null;

		if ( includeTypes ) {
			state.typesStatus = 'loading';
		}

		if ( ! append ) {
			state.page = 0;
			state.hasMore = false;
			state.results = [];
		}

		renderNavigate();

		if ( ! client ) {
			state.requestStatus = 'error';
			state.requestError = {
				message: text( 'workspaceError', 'Object search failed.' ),
				status: 0,
			};
			if ( includeTypes ) {
				state.typesStatus = 'error';
			}
			renderNavigate();
			return Promise.resolve();
		}

		return client
			.searchObjects(
				state.search,
				state.activeType.objectType,
				{
					subtype: state.activeType.subtype,
					page,
					perPage: PER_PAGE,
					includeTypes,
					sort: state.sort,
				}
			)
			.then( function ( result ) {
				if ( requestId !== state.requestId ) {
					return;
				}

				const payload = result && typeof result === 'object' ? result : {};
				const items = Array.isArray( payload.items ) ? payload.items : [];

				if ( includeTypes ) {
					state.types = Array.isArray( payload.types )
						? payload.types.filter( isTypeDescriptor )
						: [];
					state.typesStatus = 'ready';
					// A persisted subtype that no longer exists (deleted CPT,
					// lost capability) falls back to All rather than showing
					// a tab the server did not offer. A kind-only selection
					// (R6.1-b: `{post,''}` / `{term,''}`) is valid as long as
					// the server offered at least one type of that kind.
					if ( state.activeType.objectType !== 'all' && ! activeTypeDescriptor() && ! kindIsOffered( state.activeType.objectType ) ) {
						state.activeType = { objectType: 'all', subtype: '' };
						persist();
						runSearch();
						return;
					}
				}

				state.results = append
					? state.results.concat( items.map( normalizeItem ) )
					: items.map( normalizeItem );
				state.page = Number( payload.page ) || page;
				state.hasMore = payload.hasMore === true;
				state.requestStatus = 'ready';
				state.modeStatus = 'active';
				state.pendingFocusFirstAppended = append && items.length > 0;
				renderNavigate();
				announceResults();
			} )
			.catch( function ( error ) {
				if ( requestId !== state.requestId ) {
					return;
				}

				const status = Number( error && error.status ) || 0;
				state.requestStatus = 'error';
				state.requestError = {
					message:
						error && error.message
							? String( error.message )
							: text( 'workspaceError', 'Object search failed.' ),
					status,
				};

				if ( includeTypes ) {
					state.typesStatus = 'error';
				}

				if ( status === 403 ) {
					state.modeStatus = 'unsafe';
				}

				renderNavigate();
				announce( statusText() );
			} );
	}

	function isTypeDescriptor( value ) {
		return Boolean(
			value &&
				typeof value === 'object' &&
				( value.objectType === 'post' || value.objectType === 'term' ) &&
				typeof value.subtype === 'string' &&
				value.subtype
		);
	}

	function normalizeItem( item ) {
		const source = item && typeof item === 'object' ? item : {};
		const frontendUrl =
			typeof source.frontendUrl === 'string' ? source.frontendUrl : '';

		return {
			objectType: source.objectType === 'term' ? 'term' : 'post',
			id: Number( source.id ) || 0,
			title: source.title ? String( source.title ) : '#' + ( Number( source.id ) || '' ),
			subtype: source.subtype ? String( source.subtype ) : '',
			typeLabel: source.typeLabel ? String( source.typeLabel ) : '',
			status: source.status ? String( source.status ) : '',
			statusKey: source.statusKey
				? String( source.statusKey )
				: source.objectType === 'term'
				? 'term'
				: '',
			frontendUrl,
			// Honest routes (D-075): only the server's judgement counts; a
			// URL without the flag is never promoted to an Open action.
			hasFrontendRoute: source.hasFrontendRoute === true && frontendUrl !== '',
			backendUrl: typeof source.backendUrl === 'string' ? source.backendUrl : '',
			canEdit: source.canEdit !== false,
		};
	}

	function itemKey( item ) {
		return item.objectType + ':' + item.id;
	}

	function scheduleSearch() {
		window.clearTimeout( state.searchTimer );
		state.searchTimer = window.setTimeout( function () {
			state.searchTimer = 0;
			runSearch();
		}, SEARCH_DEBOUNCE_MS );
	}

	function setActiveType( objectType, subtype ) {
		const next = {
			objectType:
				[ 'all', 'post', 'term' ].indexOf( objectType ) === -1
					? 'all'
					: objectType,
			subtype: objectType === 'all' ? '' : String( subtype || '' ),
		};

		if ( typeKey( next ) === typeKey( state.activeType ) ) {
			return;
		}

		state.activeType = next;
		persist();
		runSearch();
	}

	function setSearch( value ) {
		const next = String( value || '' ).slice( 0, SEARCH_MAX_LENGTH );

		if ( next === state.search ) {
			return;
		}

		state.search = next;
		syncRelevanceWithSearch();
		syncSearchClear();
		scheduleSearch();
	}

	function clearSearch() {
		const input = navigateNode( '[data-dbvc-ve-workspace-action="search"]' );

		if ( input ) {
			input.value = '';
			if ( typeof input.focus === 'function' ) {
				input.focus();
			}
		}

		window.clearTimeout( state.searchTimer );
		state.searchTimer = 0;
		state.search = '';
		syncRelevanceWithSearch();
		syncSearchClear();
		runSearch();
	}

	function ensureNavigationLoaded() {
		if ( state.requestStatus === 'idle' ) {
			runSearch();
		}
	}

	// ------------------------------------------------------------------
	// Navigation — rendering
	// ------------------------------------------------------------------

	function statusText() {
		if ( state.modeStatus === 'unsafe' ) {
			return text( 'workspaceModeInactive', 'Visual Editor mode is no longer active.' );
		}

		switch ( state.requestStatus ) {
			case 'loading-initial':
				return state.typesStatus === 'loading'
					? text( 'workspaceStatusTypesLoading', 'Loading object types…' )
					: text( 'workspaceStatusSearching', 'Searching…' );
			case 'loading-more':
				return text( 'workspaceLoading', 'Loading…' );
			case 'error':
				return state.requestError && state.requestError.message
					? state.requestError.message
					: text( 'workspaceError', 'Object search failed.' );
			case 'ready':
				if ( ! state.results.length ) {
					return text( 'workspaceStatusNoResults', 'No results' );
				}
				return (
					( state.hasMore
						? templateText(
								'workspaceStatusShowingMore',
								'Showing {count} · more available',
								{ count: state.results.length }
						  )
						: templateText( 'workspaceStatusShowing', 'Showing {count}', {
								count: state.results.length,
						  } ) ) +
					// R6.1-b: name a non-default order so the list explains itself.
					( state.sort !== DEFAULT_SORT ? ' · ' + sortLabel( state.sort ) : '' )
				);
			default:
				return text( 'workspaceStatusIdle', 'Search or pick a type to start.' );
		}
	}

	function announceResults() {
		announce( statusText() );
	}

	function syncSearchClear() {
		const clear = navigateNode( '[data-dbvc-ve-workspace-action="clear-search"]' );

		if ( clear ) {
			clear.hidden = state.search === '';
		}
	}

	function renderNavigate() {
		if ( ! state.root ) {
			return;
		}

		// Focus continuity (contract §6): remember the focused row across a
		// rerender, park on the status line while the list is empty or the
		// row is gone, and come back to the same object once it reappears.
		// Focus anywhere else (search input, tabs, tools) is never touched.
		const active = document.activeElement;
		const list = navigateNode( '[data-dbvc-ve-workspace-results]' );
		const status = navigateNode( '[data-dbvc-ve-workspace-status]' );
		const focusWasInResults = Boolean( list && active && list.contains( active ) );
		const focusWasParked = Boolean( status && active === status );

		if ( focusWasInResults ) {
			state.focusedItemKey = captureFocusedItemKey();
		}

		renderControls();
		renderTypes();
		renderStatus();
		renderNotice();
		renderResults();
		renderFooter();
		syncSearchState();
		restoreFocus(
			focusWasInResults || focusWasParked ? state.focusedItemKey : null,
			focusWasInResults || focusWasParked
		);
	}

	function syncSearchState() {
		const input = navigateNode( '[data-dbvc-ve-workspace-action="search"]' );

		if ( ! input ) {
			return;
		}

		const disabled =
			state.modeStatus === 'unsafe' || state.typesStatus === 'loading';
		input.disabled = disabled;
		syncSearchClear();
	}

	function renderTypes() {
		const strip = navigateNode( '[data-dbvc-ve-workspace-types]' );

		if ( ! strip ) {
			return;
		}

		strip.textContent = '';
		strip.classList.toggle( 'is-skeleton', state.typesStatus === 'loading' );

		if ( state.typesStatus === 'loading' ) {
			strip.setAttribute( 'aria-busy', 'true' );
			strip.removeAttribute( 'role' );
			for ( let index = 0; index < 5; index++ ) {
				const bar = createElement(
					'span',
					'dbvc-ve-workspace__skeleton-bar dbvc-ve-workspace__skeleton-bar--tab'
				);
				bar.setAttribute( 'aria-hidden', 'true' );
				strip.appendChild( bar );
			}
			return;
		}

		strip.removeAttribute( 'aria-busy' );
		strip.setAttribute( 'role', 'tablist' );

		// R6.1-b: the kind filter narrows the strip to the matching half; the
		// strip's own "All" tab means "all types within this kind".
		const kind = currentKind();
		const entries = [ { objectType: kind, subtype: '', label: text( 'workspaceTypeAll', 'All' ), viewable: true } ].concat(
			state.types.filter( function ( type ) {
				return kind === 'all' || type.objectType === kind;
			} )
		);

		entries.forEach( function ( type ) {
			const selected = typeKey( type ) === typeKey( state.activeType );
			const tab = createElement(
				'button',
				'dbvc-ve-workspace__type' + ( type.viewable === false ? ' is-backend-only' : '' )
			);
			tab.type = 'button';
			tab.setAttribute( 'role', 'tab' );
			tab.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
			tab.tabIndex = selected ? 0 : -1;
			tab.setAttribute( 'data-dbvc-ve-workspace-action', 'type' );
			tab.setAttribute( 'data-dbvc-ve-workspace-type', type.objectType );
			tab.setAttribute( 'data-dbvc-ve-workspace-subtype', type.subtype || '' );
			tab.appendChild( document.createTextNode( String( type.label || type.subtype ) ) );

			if ( type.viewable === false ) {
				const affix = createElement( 'span', 'dbvc-ve-workspace__type-affix' );
				affix.appendChild( svgIcon( 'lock' ) );
				affix.appendChild(
					createElement(
						'span',
						'dbvc-ve-workspace__sr-only',
						' ' + text( 'workspaceTypeBackendOnly', '(backend only)' )
					)
				);
				tab.appendChild( affix );
			}

			strip.appendChild( tab );
		} );
	}

	function renderStatus() {
		const status = navigateNode( '[data-dbvc-ve-workspace-status]' );

		if ( ! status ) {
			return;
		}

		status.textContent = statusText();
		status.classList.toggle(
			'is-error',
			state.requestStatus === 'error' || state.modeStatus === 'unsafe'
		);
	}

	function renderNotice() {
		const slot = navigateNode( '[data-dbvc-ve-workspace-notice]' );

		if ( ! slot ) {
			return;
		}

		slot.textContent = '';

		if ( state.modeStatus === 'unsafe' ) {
			slot.appendChild(
				createNoticeBlock( 'error', 'dbvc-ve-workspace__error--mode', {
					title: text( 'workspaceModeInactive', 'Visual Editor mode is no longer active.' ),
					body: text( 'workspaceModeInactiveHint', 'Refresh the page to continue. Your place on the site is unchanged.' ),
					action: 'reload',
					actionLabel: text( 'workspaceReload', 'Refresh page' ),
				} )
			);
			return;
		}

		if ( state.requestStatus === 'error' ) {
			slot.appendChild(
				createNoticeBlock( 'error', '', {
					title: state.requestError ? state.requestError.message : text( 'workspaceError', 'Object search failed.' ),
					body: state.results.length
						? text( 'workspaceErrorHint', 'The last request did not complete. The rows below are from the previous result and may be stale.' )
						: '',
					action: 'retry',
					actionLabel: text( 'workspaceRetry', 'Retry' ),
				} )
			);
			return;
		}

		if ( state.requestStatus === 'ready' && ! state.results.length ) {
			const label = activeTypeLabel();

			if ( state.search ) {
				slot.appendChild(
					createNoticeBlock( 'empty', '', {
						title: templateText( 'workspaceEmptySearch', 'No matches for “{search}” in {label}.', {
							search: state.search,
							label,
						} ),
						body: text( 'workspaceEmptySearchHint', 'Try a different word, or search all types.' ),
						action: 'clear-search',
						actionLabel: text( 'workspaceSearchClear', 'Clear search' ),
					} )
				);
			} else {
				slot.appendChild(
					createNoticeBlock( 'empty', '', {
						title: templateText( 'workspaceEmptyType', 'No {label} yet.', { label } ),
						body: text( 'workspaceEmptyTypeHint', 'New items are created in the WordPress admin; they will appear here once they exist.' ),
					} )
				);
			}
		}
	}

	function createNoticeBlock( kind, extraClass, content ) {
		const block = createElement(
			'div',
			'dbvc-ve-workspace__' + kind + ( extraClass ? ' ' + extraClass : '' )
		);

		if ( kind === 'error' ) {
			block.setAttribute( 'role', 'alert' );
		}

		block.appendChild(
			createElement( 'span', 'dbvc-ve-workspace__' + kind + '-title', content.title )
		);

		if ( content.body ) {
			block.appendChild( document.createTextNode( content.body ) );
		}

		if ( content.action ) {
			const actions = createElement( 'div', 'dbvc-ve-workspace__empty-actions' );
			const button = createElement( 'button', 'dbvc-ve-workspace__retry', content.actionLabel );
			button.type = 'button';
			button.setAttribute( 'data-dbvc-ve-workspace-action', content.action );
			actions.appendChild( button );
			block.appendChild( actions );
		}

		return block;
	}

	function renderResults() {
		const list = navigateNode( '[data-dbvc-ve-workspace-results]' );

		if ( ! list ) {
			return;
		}

		list.textContent = '';
		list.hidden = false;

		if ( state.requestStatus === 'loading-initial' ) {
			list.setAttribute( 'aria-busy', 'true' );
			for ( let index = 0; index < 3; index++ ) {
				list.appendChild( createSkeletonRow() );
			}
			return;
		}

		list.removeAttribute( 'aria-busy' );

		if ( ! state.results.length ) {
			list.hidden = true;
			return;
		}

		state.results.forEach( function ( item ) {
			list.appendChild( createRow( item ) );
		} );
	}

	function createSkeletonRow() {
		const row = createElement( 'li', 'dbvc-ve-workspace__row is-skeleton' );
		row.setAttribute( 'aria-hidden', 'true' );
		const glyph = createElement( 'span', 'dbvc-ve-workspace__row-glyph' );
		glyph.appendChild(
			createElement( 'span', 'dbvc-ve-workspace__skeleton-bar dbvc-ve-workspace__skeleton-bar--glyph' )
		);
		row.appendChild( glyph );
		const textWrap = createElement( 'div', 'dbvc-ve-workspace__row-text' );
		textWrap.appendChild( createElement( 'span', 'dbvc-ve-workspace__skeleton-bar' ) );
		textWrap.appendChild( createElement( 'span', 'dbvc-ve-workspace__skeleton-bar' ) );
		row.appendChild( textWrap );
		const actions = createElement( 'div', 'dbvc-ve-workspace__row-actions' );
		actions.appendChild(
			createElement( 'span', 'dbvc-ve-workspace__skeleton-bar dbvc-ve-workspace__skeleton-bar--action' )
		);
		row.appendChild( actions );

		return row;
	}

	function rowGlyphName( item ) {
		if ( item.objectType === 'term' ) {
			return 'term';
		}

		return item.subtype === 'page' ? 'page' : 'post';
	}

	function createRow( item ) {
		const key = itemKey( item );
		const row = createElement(
			'li',
			'dbvc-ve-workspace__row is-' +
				( item.statusKey || 'unknown' ) +
				( item.hasFrontendRoute ? '' : ' is-no-route' ) +
				( state.focusedItemKey === key ? ' is-focused' : '' )
		);
		row.setAttribute( 'data-dbvc-ve-workspace-object', key );

		const glyph = createElement( 'span', 'dbvc-ve-workspace__row-glyph' );
		glyph.setAttribute( 'aria-hidden', 'true' );
		glyph.appendChild( svgIcon( rowGlyphName( item ) ) );
		row.appendChild( glyph );

		const textWrap = createElement( 'div', 'dbvc-ve-workspace__row-text' );
		const title = createElement( 'span', 'dbvc-ve-workspace__row-title', item.title );
		title.title = item.title;
		textWrap.appendChild( title );

		const meta = createElement( 'span', 'dbvc-ve-workspace__row-meta' );
		if ( item.typeLabel ) {
			meta.appendChild( createElement( 'span', 'dbvc-ve-workspace__row-type', item.typeLabel ) );
		}
		if ( item.status ) {
			meta.appendChild(
				createElement(
					'span',
					'dbvc-ve-workspace__status-chip dbvc-ve-workspace__status-chip--' + ( item.statusKey || 'unknown' ),
					item.status
				)
			);
		}
		// D-075: explain a missing Open only when the status alone would not.
		if ( ! item.hasFrontendRoute && item.statusKey === 'publish' ) {
			meta.appendChild(
				createElement( 'span', 'dbvc-ve-workspace__row-note', text( 'workspaceNoPublicPage', 'No public page' ) )
			);
		} else if ( ! item.hasFrontendRoute && item.statusKey === 'term' ) {
			meta.appendChild(
				createElement( 'span', 'dbvc-ve-workspace__row-note', text( 'workspaceNoPublicArchive', 'No public archive' ) )
			);
		}
		textWrap.appendChild( meta );
		row.appendChild( textWrap );

		const actions = createElement( 'div', 'dbvc-ve-workspace__row-actions' );

		if ( item.hasFrontendRoute ) {
			const open = createActionLink(
				text( 'workspaceOpenFrontend', 'Open' ),
				item.frontendUrl,
				'open-frontend',
				{ primary: true }
			);
			open.setAttribute( 'data-dbvc-ve-workspace-object', key );
			actions.appendChild( open );
		}

		if ( item.backendUrl ) {
			const edit = createActionLink(
				text( 'workspaceOpenBackend', 'Edit' ),
				item.backendUrl,
				'open-backend',
				{ primary: ! item.hasFrontendRoute, newTab: true }
			);
			edit.setAttribute( 'data-dbvc-ve-workspace-object', key );
			actions.appendChild( edit );
		}

		row.appendChild( actions );

		return row;
	}

	function renderFooter() {
		const footer = navigateNode( '[data-dbvc-ve-workspace-footer]' );

		if ( ! footer ) {
			return;
		}

		// Keep focus on the Load more control across the loading re-render
		// so a keyboard user is not dropped to <body> between click and
		// response (the first appended row takes focus once it lands).
		const footerHadFocus =
			document.activeElement && footer.contains( document.activeElement );

		footer.textContent = '';

		if ( state.modeStatus === 'unsafe' || ! state.results.length ) {
			return;
		}

		if ( state.hasMore || state.requestStatus === 'loading-more' ) {
			const button = createElement( 'button', 'dbvc-ve-workspace__load-more' );
			button.type = 'button';
			button.setAttribute( 'data-dbvc-ve-workspace-action', 'load-more' );

			if ( state.requestStatus === 'loading-more' ) {
				// aria-disabled (not `disabled`) so the control stays
				// focusable while the page loads; clicks are dropped by the
				// delegated handler's aria-disabled guard.
				button.classList.add( 'is-loading' );
				button.setAttribute( 'aria-disabled', 'true' );
				button.appendChild( svgIcon( 'spinner' ) );
				button.appendChild( document.createTextNode( text( 'workspaceLoading', 'Loading…' ) ) );
			} else {
				button.textContent = text( 'workspaceLoadMore', 'Load more' );
			}

			footer.appendChild( button );

			if ( footerHadFocus && typeof button.focus === 'function' ) {
				button.focus();
			}
			return;
		}

		if ( state.requestStatus === 'ready' ) {
			footer.appendChild(
				createElement( 'span', 'dbvc-ve-workspace__end', text( 'workspaceEnd', 'No more results' ) )
			);
		}
	}

	// ------------------------------------------------------------------
	// Navigation — focus continuity + row keyboard
	// ------------------------------------------------------------------

	function captureFocusedItemKey() {
		const active = document.activeElement;
		const list = navigateNode( '[data-dbvc-ve-workspace-results]' );

		if ( ! active || ! list || ! list.contains( active ) ) {
			return null;
		}

		const row = active.closest( '[data-dbvc-ve-workspace-object]' );

		return row ? row.getAttribute( 'data-dbvc-ve-workspace-object' ) : null;
	}

	function primaryActionOfRow( row ) {
		return row ? row.querySelector( '.dbvc-ve-workspace__action--primary' ) : null;
	}

	function restoreFocus( previousKey, shouldRestore ) {
		const list = navigateNode( '[data-dbvc-ve-workspace-results]' );

		if ( ! list ) {
			return;
		}

		if ( state.pendingFocusFirstAppended ) {
			state.pendingFocusFirstAppended = false;
			const rows = list.querySelectorAll( 'li[data-dbvc-ve-workspace-object]' );
			const firstNew = rows[ rows.length - ( state.results.length - ( state.page - 1 ) * PER_PAGE ) ];
			const target = primaryActionOfRow( firstNew || rows[ rows.length - 1 ] );

			if ( target && typeof target.focus === 'function' ) {
				target.focus();
			}
			return;
		}

		if ( ! shouldRestore ) {
			return;
		}

		const row = previousKey
			? list.querySelector(
					'li[data-dbvc-ve-workspace-object="' + previousKey.replace( /"/g, '' ) + '"]'
			  )
			: null;
		const target = primaryActionOfRow( row );

		if ( target && typeof target.focus === 'function' ) {
			state.focusedItemKey = previousKey;
			target.focus();
			return;
		}

		const status = navigateNode( '[data-dbvc-ve-workspace-status]' );

		if ( status && typeof status.focus === 'function' ) {
			status.tabIndex = -1;
			status.focus();
		}
	}

	function handleResultsKeydown( event ) {
		const keys = [ 'ArrowUp', 'ArrowDown', 'Home', 'End' ];

		if ( keys.indexOf( event.key ) === -1 ) {
			return;
		}

		const list = navigateNode( '[data-dbvc-ve-workspace-results]' );
		const currentRow =
			event.target && typeof event.target.closest === 'function'
				? event.target.closest( 'li[data-dbvc-ve-workspace-object]' )
				: null;

		if ( ! list || ! currentRow || ! list.contains( currentRow ) ) {
			return;
		}

		const rows = Array.prototype.slice.call(
			list.querySelectorAll( 'li[data-dbvc-ve-workspace-object]' )
		);
		const index = rows.indexOf( currentRow );
		let next = index;

		if ( event.key === 'ArrowDown' ) {
			next = Math.min( rows.length - 1, index + 1 );
		} else if ( event.key === 'ArrowUp' ) {
			next = Math.max( 0, index - 1 );
		} else if ( event.key === 'Home' ) {
			next = 0;
		} else if ( event.key === 'End' ) {
			next = rows.length - 1;
		}

		const target = primaryActionOfRow( rows[ next ] );

		if ( target && typeof target.focus === 'function' ) {
			event.preventDefault();
			state.focusedItemKey = rows[ next ].getAttribute( 'data-dbvc-ve-workspace-object' );
			target.focus();
		}
	}

	function handleTypesKeydown( event ) {
		const tab =
			event.target && typeof event.target.closest === 'function'
				? event.target.closest( '[data-dbvc-ve-workspace-action="type"]' )
				: null;

		if ( ! tab || ( event.key !== 'ArrowLeft' && event.key !== 'ArrowRight' ) ) {
			return;
		}

		const strip = navigateNode( '[data-dbvc-ve-workspace-types]' );
		const tabs = strip
			? Array.prototype.slice.call( strip.querySelectorAll( '[data-dbvc-ve-workspace-action="type"]' ) )
			: [];
		const index = tabs.indexOf( tab );

		if ( index === -1 ) {
			return;
		}

		const delta = event.key === 'ArrowRight' ? 1 : -1;
		const next = tabs[ ( index + delta + tabs.length ) % tabs.length ];

		event.preventDefault();
		setActiveType(
			next.getAttribute( 'data-dbvc-ve-workspace-type' ),
			next.getAttribute( 'data-dbvc-ve-workspace-subtype' )
		);
		const selected = strip.querySelector( '[aria-selected="true"]' );

		if ( selected && typeof selected.focus === 'function' ) {
			selected.focus();
		}
	}

	function toolDefinitions() {
		const current = state.currentObject;
		const controlCenterEnabled =
			featureConfig( 'controlCenter' ).enabled === true;
		const mediaManagerEnabled =
			featureConfig( 'mediaManager' ).enabled === true;
		const toggleUrl =
			typeof bootstrap().toggleUrl === 'string'
				? bootstrap().toggleUrl
				: '';

		return [
			{
				action: 'tool-review-fields',
				icon: 'layers',
				label: text( 'workspaceToolReviewFields', 'Review fields' ),
				detail: text(
					'workspaceToolReviewFieldsDetail',
					'Marked fields on this page'
				),
				available: true,
			},
			{
				action: 'tool-control-center',
				icon: 'sliders',
				label: text( 'workspaceToolControlCenter', 'Brand & Globals' ),
				detail: controlCenterEnabled
					? text(
							'workspaceToolControlCenterDetail',
							'Global Brand Controls drawer'
					  )
					: text( 'workspaceToolUnavailable', 'Not enabled on this site' ),
				available: controlCenterEnabled,
			},
			{
				action: 'tool-media-manager',
				icon: 'media',
				label: text( 'workspaceToolMediaManager', 'Media Manager' ),
				detail: mediaManagerEnabled
					? text(
							'workspaceToolMediaManagerDetail',
							'Missing-image scan and site media index'
					  )
					: text( 'workspaceToolUnavailable', 'Not enabled on this site' ),
				available: mediaManagerEnabled,
			},
			{
				action: 'tool-edit-object',
				icon: 'edit',
				label: text( 'workspaceToolEditObject', 'Edit active object' ),
				detail:
					current && current.backendUrl
						? text(
								'workspaceToolEditObjectDetail',
								'Opens the WordPress editor in a new tab'
						  )
						: text(
								'workspaceToolNoEditLink',
								'No backend edit link for this page'
						  ),
				available: Boolean( current && current.backendUrl ),
				href: current && current.backendUrl ? current.backendUrl : '',
				newTab: true,
			},
			{
				action: 'tool-exit',
				icon: 'power',
				label: text( 'workspaceToolExit', 'Exit Visual Editor' ),
				detail: '',
				available: Boolean( toggleUrl ),
				href: toggleUrl,
			},
		];
	}

	function createToolsBody() {
		const body = createElement(
			'div',
			'dbvc-ve-workspace__body dbvc-ve-workspace__body--tools'
		);
		body.id = ROOT_ID + '-panel-tools';
		body.setAttribute( 'role', 'tabpanel' );
		body.setAttribute( 'aria-labelledby', ROOT_ID + '-tab-tools' );
		body.setAttribute( 'data-dbvc-ve-workspace-panel', 'tools' );

		const list = createElement( 'ul', 'dbvc-ve-workspace__tools' );
		list.setAttribute( 'role', 'list' );
		list.setAttribute( 'aria-label', text( 'workspaceToolsLabel', 'Tools' ) );

		toolDefinitions().forEach( function ( tool ) {
			const item = createElement( 'li' );
			const isLink = Boolean( tool.href ) && tool.available;
			const control = createElement(
				isLink ? 'a' : 'button',
				'dbvc-ve-workspace__tool' +
					( tool.available ? '' : ' is-unavailable' )
			);

			if ( isLink ) {
				control.href = tool.href;

				if ( tool.newTab ) {
					control.target = '_blank';
					control.rel = 'noopener noreferrer';
				}
			} else {
				control.type = 'button';

				if ( ! tool.available ) {
					control.disabled = true;
					control.setAttribute( 'aria-disabled', 'true' );
				}
			}

			control.setAttribute( 'data-dbvc-ve-workspace-action', tool.action );

			const glyph = createElement( 'span', 'dbvc-ve-workspace__row-glyph' );
			glyph.setAttribute( 'aria-hidden', 'true' );
			glyph.appendChild( svgIcon( tool.icon ) );
			control.appendChild( glyph );

			const textWrap = createElement( 'span', 'dbvc-ve-workspace__tool-text' );
			const label = createElement(
				'span',
				'dbvc-ve-workspace__tool-label',
				tool.label
			);

			if ( isLink && tool.newTab ) {
				label.appendChild(
					createElement(
						'span',
						'dbvc-ve-workspace__sr-only',
						' ' + text( 'workspaceOpensNewTab', '(opens in a new tab)' )
					)
				);
			}

			textWrap.appendChild( label );

			if ( tool.detail ) {
				textWrap.appendChild(
					createElement(
						'span',
						'dbvc-ve-workspace__tool-detail',
						tool.detail
					)
				);
			}

			control.appendChild( textWrap );

			const affordance = createElement(
				'span',
				'dbvc-ve-workspace__tool-affordance'
			);
			affordance.setAttribute( 'aria-hidden', 'true' );

			if ( tool.available ) {
				affordance.appendChild(
					svgIcon( isLink && tool.newTab ? 'external' : 'chevron' )
				);
			}

			control.appendChild( affordance );
			item.appendChild( control );
			list.appendChild( item );
		} );

		body.appendChild( list );

		return body;
	}

	function createAnnouncer() {
		const announcer = createElement( 'p', 'dbvc-ve-workspace__sr-only' );
		announcer.setAttribute( 'role', 'status' );
		announcer.setAttribute( 'aria-live', 'polite' );
		announcer.setAttribute( 'aria-atomic', 'true' );
		announcer.setAttribute( 'data-dbvc-ve-workspace-announcer', '1' );

		return announcer;
	}

	function ensureRoot() {
		if ( state.root && state.root.isConnected ) {
			return state.root;
		}

		let root = document.getElementById( ROOT_ID );

		if ( ! root ) {
			state.currentObject = resolveCurrentObject();
			root = createElement( 'aside', 'dbvc-ve-workspace' );
			root.id = ROOT_ID;
			root.setAttribute( 'role', 'complementary' );
			root.setAttribute( 'aria-labelledby', ROOT_ID + '-title' );
			root.hidden = true;
			root.appendChild( createHeader() );
			root.appendChild( createCurrentCard() );
			root.appendChild( createSectionTabs() );
			root.appendChild( createNavigateBody() );
			root.appendChild( createToolsBody() );
			root.appendChild( createAnnouncer() );
			document.body.appendChild( root );
		}

		state.root = root;
		state.announcer = root.querySelector(
			'[data-dbvc-ve-workspace-announcer]'
		);
		bindRootListeners( root );
		applySection();

		return root;
	}

	function announce( message ) {
		if ( state.announcer ) {
			state.announcer.textContent = String( message || '' );
		}
	}

	// ------------------------------------------------------------------
	// Sections
	// ------------------------------------------------------------------

	function applySection() {
		if ( ! state.root ) {
			return;
		}

		SECTIONS.forEach( function ( section ) {
			const tab = state.root.querySelector(
				'[data-dbvc-ve-workspace-section="' + section + '"]'
			);
			const panel = state.root.querySelector(
				'[data-dbvc-ve-workspace-panel="' + section + '"]'
			);
			const active = section === state.section;

			if ( tab ) {
				tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				tab.tabIndex = active ? 0 : -1;
			}

			if ( panel ) {
				panel.hidden = ! active;
			}
		} );
	}

	function setSection( section, options ) {
		if ( SECTIONS.indexOf( section ) === -1 ) {
			return;
		}

		state.section = section;
		applySection();
		persist();

		if ( options && options.focusTab && state.root ) {
			const tab = state.root.querySelector(
				'[data-dbvc-ve-workspace-section="' + section + '"]'
			);

			if ( tab && typeof tab.focus === 'function' ) {
				tab.focus();
			}
		}
	}

	// ------------------------------------------------------------------
	// Overlay awareness (BCC / Media Manager painted above us)
	// ------------------------------------------------------------------

	function setOverlay( overlay ) {
		const previous = state.overlay;
		state.overlay = overlay;

		if ( ! state.root ) {
			return;
		}

		const inert = overlay !== 'none' && state.isOpen;
		state.root.classList.toggle( 'is-inert', inert );
		// The BCC occupies the same left slot and fully covers the drawer;
		// both surfaces are slightly translucent in dark mode, so hide the
		// covered one visually (it stays mounted, state intact). The Media
		// Manager is a centred modal that does not cover the slot — leave
		// the drawer visible under it.
		state.root.classList.toggle( 'is-covered', inert && overlay === 'control-center' );

		if ( inert ) {
			state.root.setAttribute( 'inert', '' );
			state.root.setAttribute( 'aria-hidden', 'true' );
			return;
		}

		state.root.removeAttribute( 'inert' );
		state.root.setAttribute( 'aria-hidden', state.isOpen ? 'false' : 'true' );

		// Overlay just lifted: if the sibling's focus restoration bounced off
		// the inert root, focus is either on <body>, nowhere, or still on a
		// control inside the sibling's now-hidden root (browsers run the
		// focus-fixup asynchronously). Land it on the tool button that
		// opened the sibling. Focus that legitimately went elsewhere (e.g.
		// the sibling's own toolbar button) is left alone.
		if ( previous !== 'none' && state.isOpen && isFocusStranded() ) {
			focusLastToolTrigger();
		}
	}

	function isFocusStranded() {
		const active = document.activeElement;

		if ( ! active || active === document.body ) {
			return true;
		}

		return Boolean(
			typeof active.closest === 'function' &&
				active.closest(
					'#dbvc-ve-control-center, #dbvc-ve-media-manager, .dbvc-ve-media-manager'
				)
		);
	}

	function focusLastToolTrigger() {
		const trigger = state.lastToolTrigger;

		if ( trigger && trigger.isConnected && typeof trigger.focus === 'function' ) {
			trigger.focus();
		}
	}

	// ------------------------------------------------------------------
	// Open / close
	// ------------------------------------------------------------------

	function findToolbarTrigger() {
		return document.querySelector(
			'[data-dbvc-ve-toolbar-action="workspace"]'
		);
	}

	function setTriggerExpanded( expanded ) {
		const trigger =
			state.trigger && state.trigger.isConnected
				? state.trigger
				: findToolbarTrigger();

		if ( trigger ) {
			trigger.setAttribute( 'aria-expanded', expanded ? 'true' : 'false' );
		}
	}

	function setDocumentInset( open ) {
		// R6-D-3 consumer: overlay-app.js reads this when clamping the
		// movable panel so it cannot be dragged under the drawer (D-072).
		document.documentElement.style.setProperty(
			'--dbvc-ve-workspace-inset',
			open ? DRAWER_WIDTH : '0px'
		);
	}

	function open( options ) {
		const settings = options || {};
		const root = ensureRoot();
		const trigger = settings.trigger;

		if ( trigger && trigger.isConnected ) {
			state.trigger = trigger;
		} else if ( ! state.trigger ) {
			state.trigger = findToolbarTrigger();
		}

		root.hidden = false;
		state.isOpen = true;
		root.setAttribute( 'aria-hidden', 'false' );
		setTriggerExpanded( true );
		setOverlay( state.overlay );
		setDocumentInset( true );
		persist();

		if ( settings.focus !== false ) {
			const closeButton = root.querySelector(
				'[data-dbvc-ve-workspace-action="close"]'
			);

			if ( closeButton && typeof closeButton.focus === 'function' ) {
				window.requestAnimationFrame( function () {
					// Only claim focus if nothing inside the drawer already has
					// it (e.g. a fast keyboard user who moved on within a frame).
					if ( ! root.hidden && ! root.contains( document.activeElement ) ) {
						closeButton.focus();
					}
				} );
			}
		}

		document.dispatchEvent(
			new CustomEvent( 'dbvc:visual-editor:workspace:opened' )
		);
		announce( text( 'workspaceAnnounceOpened', 'Site Manager opened.' ) );
		ensureNavigationLoaded();
	}

	function close( options ) {
		const settings = options || {};
		const restoreFocus = settings.restoreFocus !== false;
		const root = state.root;
		const trigger = state.trigger;

		if ( ! root || root.hidden ) {
			state.isOpen = false;
			setTriggerExpanded( false );
			setDocumentInset( false );
			return;
		}

		root.hidden = true;
		state.isOpen = false;
		root.setAttribute( 'aria-hidden', 'true' );
		root.classList.remove( 'is-inert' );
		root.classList.remove( 'is-covered' );
		root.removeAttribute( 'inert' );
		setTriggerExpanded( false );
		setDocumentInset( false );
		persist();

		if (
			restoreFocus &&
			trigger &&
			trigger.isConnected &&
			typeof trigger.focus === 'function'
		) {
			trigger.focus();
		}

		document.dispatchEvent(
			new CustomEvent( 'dbvc:visual-editor:workspace:closed' )
		);
		announce( text( 'workspaceAnnounceClosed', 'Site Manager closed.' ) );
	}

	function toggle( options ) {
		if ( isOpen() ) {
			close( { restoreFocus: true } );
		} else {
			open( options || {} );
		}
	}

	function isOpen() {
		return Boolean( state.root && ! state.root.hidden );
	}

	// ------------------------------------------------------------------
	// Escape precedence (contract §6 / D-071)
	// ------------------------------------------------------------------

	function isWpMediaModalOpen() {
		const modals = document.querySelectorAll( '.media-modal' );

		for ( let index = 0; index < modals.length; index++ ) {
			const modal = modals[ index ];

			if ( modal.hidden ) {
				continue;
			}

			if ( modal.style && modal.style.display === 'none' ) {
				continue;
			}

			return true;
		}

		return false;
	}

	function siblingIsOpen( globalName ) {
		const api = window[ globalName ];

		return Boolean(
			api && typeof api.isOpen === 'function' && api.isOpen()
		);
	}

	function isToolbarPopoverOpen() {
		return Boolean(
			document.querySelector( '.dbvc-ve-toolbar-popover:not([hidden])' )
		);
	}

	function isFocusInsidePanel() {
		const panel = document.querySelector( '.dbvc-ve-panel:not([hidden])' );
		const active = document.activeElement;

		return Boolean( panel && active && panel.contains( active ) );
	}

	function shouldYieldEscape() {
		return (
			isWpMediaModalOpen() ||
			siblingIsOpen( 'DBVCVisualEditorMediaManager' ) ||
			siblingIsOpen( 'DBVCVisualEditorBrandControlCenter' ) ||
			isToolbarPopoverOpen() ||
			isFocusInsidePanel()
		);
	}

	function handleDocumentKeydown( event ) {
		if ( event.key !== 'Escape' || ! isOpen() ) {
			return;
		}

		// A surface above us that already handled this Escape (BCC, toolbar
		// popover, palette bulk editor) calls preventDefault + stopPropagation
		// before closing itself. stopPropagation does not stop other
		// capture listeners on `document`, and by the time we run that surface
		// may already report closed — so the event flags are the reliable
		// signal, with the live predicates as the fallback.
		if ( event.defaultPrevented || event.cancelBubble ) {
			return;
		}

		if ( shouldYieldEscape() ) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();
		close( { restoreFocus: true } );
	}

	// ------------------------------------------------------------------
	// Click routing
	// ------------------------------------------------------------------

	function dispatchSibling( eventName, detail ) {
		document.dispatchEvent(
			new CustomEvent( eventName, { detail: detail || {} } )
		);
	}

	function handleRootClick( event ) {
		const actionNode =
			event.target && typeof event.target.closest === 'function'
				? event.target.closest( '[data-dbvc-ve-workspace-action]' )
				: null;

		if ( ! actionNode || ! state.root || ! state.root.contains( actionNode ) ) {
			return;
		}

		if (
			actionNode.disabled ||
			actionNode.getAttribute( 'aria-disabled' ) === 'true'
		) {
			event.preventDefault();
			return;
		}

		const action = actionNode.getAttribute( 'data-dbvc-ve-workspace-action' );

		switch ( action ) {
			case 'close':
				event.preventDefault();
				close( { restoreFocus: true } );
				return;
			case 'section':
				event.preventDefault();
				setSection(
					actionNode.getAttribute( 'data-dbvc-ve-workspace-section' ) || 'navigate'
				);
				return;
			case 'type':
				event.preventDefault();
				setActiveType(
					actionNode.getAttribute( 'data-dbvc-ve-workspace-type' ),
					actionNode.getAttribute( 'data-dbvc-ve-workspace-subtype' )
				);
				return;
			case 'kind':
				event.preventDefault();
				setKind( actionNode.getAttribute( 'data-dbvc-ve-workspace-kind' ) );
				return;
			case 'clear-search':
				event.preventDefault();
				clearSearch();
				return;
			case 'retry':
				event.preventDefault();
				runSearch();
				return;
			case 'load-more':
				event.preventDefault();
				if ( state.hasMore && state.requestStatus !== 'loading-more' ) {
					runSearch( { append: true } );
				}
				return;
			case 'reload':
				event.preventDefault();
				window.location.reload();
				return;
			case 'open-frontend':
				// Native same-tab navigation; mode is preserved by the cookie
				// (contract §8). Remember the row so a back-navigation restore
				// lands focus on it.
				state.focusedItemKey = actionNode.getAttribute( 'data-dbvc-ve-workspace-object' );
				return;
			case 'tool-review-fields':
				event.preventDefault();
				// overlay-app.js listens for this in R6-D-3 and opens the
				// existing Review Fields popover; a no-op until then.
				dispatchSibling( 'dbvc:visual-editor:review-fields:open', {
					trigger: actionNode,
				} );
				return;
			case 'tool-control-center':
				event.preventDefault();
				state.lastToolTrigger = actionNode;
				setOverlay( 'control-center' );
				dispatchSibling( 'dbvc:visual-editor:control-center:toggle', {
					trigger: actionNode,
				} );
				return;
			case 'tool-media-manager':
				event.preventDefault();
				state.lastToolTrigger = actionNode;
				setOverlay( 'media-manager' );
				dispatchSibling( 'dbvc:visual-editor:media-manager:toggle', {
					trigger: actionNode,
				} );
				return;
			default:
				// current-* / tool-edit-object / tool-exit are plain links —
				// native navigation, no URL params appended (contract §8).
				return;
		}
	}

	function handleSectionKeydown( event ) {
		const tab =
			event.target && typeof event.target.closest === 'function'
				? event.target.closest( '[data-dbvc-ve-workspace-section]' )
				: null;

		if ( ! tab || ( event.key !== 'ArrowLeft' && event.key !== 'ArrowRight' ) ) {
			return;
		}

		const current = SECTIONS.indexOf(
			tab.getAttribute( 'data-dbvc-ve-workspace-section' )
		);
		const delta = event.key === 'ArrowRight' ? 1 : -1;
		const next = SECTIONS[ ( current + delta + SECTIONS.length ) % SECTIONS.length ];

		event.preventDefault();
		setSection( next, { focusTab: true } );
	}

	function bindRootListeners( root ) {
		if ( root.dataset.dbvcVeWorkspaceBound === '1' ) {
			return;
		}

		root.dataset.dbvcVeWorkspaceBound = '1';
		root.addEventListener( 'click', handleRootClick );
		root.addEventListener( 'keydown', handleSectionKeydown );
		root.addEventListener( 'keydown', handleTypesKeydown );
		root.addEventListener( 'keydown', handleResultsKeydown );
		root.addEventListener( 'input', function ( event ) {
			const input =
				event.target && typeof event.target.closest === 'function'
					? event.target.closest( '[data-dbvc-ve-workspace-action="search"]' )
					: null;

			if ( input ) {
				setSearch( input.value );
			}
		} );
		root.addEventListener( 'change', function ( event ) {
			const select =
				event.target && typeof event.target.closest === 'function'
					? event.target.closest( '[data-dbvc-ve-workspace-action="sort"]' )
					: null;

			if ( select ) {
				setSort( select.value );
			}
		} );
		root.addEventListener( 'keydown', handleKindKeydown );
	}

	function handleKindKeydown( event ) {
		const segment =
			event.target && typeof event.target.closest === 'function'
				? event.target.closest( '[data-dbvc-ve-workspace-action="kind"]' )
				: null;

		if ( ! segment || ( event.key !== 'ArrowLeft' && event.key !== 'ArrowRight' ) ) {
			return;
		}

		const index = KINDS.indexOf( currentKind() );
		const delta = event.key === 'ArrowRight' ? 1 : -1;

		event.preventDefault();
		setKind( KINDS[ ( index + delta + KINDS.length ) % KINDS.length ] );
		const checked = navigateNode( '[data-dbvc-ve-workspace-action="kind"][aria-checked="true"]' );

		if ( checked && typeof checked.focus === 'function' ) {
			checked.focus();
		}
	}

	// ------------------------------------------------------------------
	// Mount
	// ------------------------------------------------------------------

	function publicState() {
		return {
			isOpen: isOpen(),
			section: state.section,
			activeType: Object.assign( {}, state.activeType ),
			kind: currentKind(),
			sort: state.sort,
			types: state.types.slice(),
			typesStatus: state.typesStatus,
			search: state.search,
			results: state.results.slice(),
			page: state.page,
			hasMore: state.hasMore,
			requestStatus: state.requestStatus,
			requestError: state.requestError
				? Object.assign( {}, state.requestError )
				: null,
			focusedItemKey: state.focusedItemKey,
			currentObject: state.currentObject
				? Object.assign( {}, state.currentObject )
				: null,
			overlay: state.overlay,
			modeStatus: state.modeStatus,
		};
	}

	function bindDocumentListeners() {
		if ( state.listenersBound ) {
			return;
		}

		state.listenersBound = true;
		document.addEventListener(
			'dbvc:visual-editor:workspace:toggle',
			function ( event ) {
				toggle( event && event.detail ? event.detail : {} );
			}
		);
		document.addEventListener(
			'dbvc:visual-editor:workspace:open',
			function ( event ) {
				open( event && event.detail ? event.detail : {} );
			}
		);
		document.addEventListener(
			'dbvc:visual-editor:workspace:close',
			function ( event ) {
				close( event && event.detail ? event.detail : {} );
			}
		);
		document.addEventListener(
			'dbvc:visual-editor:control-center:opened',
			function () {
				setOverlay( 'control-center' );
			}
		);
		document.addEventListener(
			'dbvc:visual-editor:control-center:closed',
			function () {
				if ( state.overlay === 'control-center' ) {
					setOverlay( 'none' );
				}
			}
		);
		document.addEventListener(
			'dbvc:visual-editor:media-manager:opened',
			function () {
				setOverlay( 'media-manager' );
			}
		);
		document.addEventListener(
			'dbvc:visual-editor:media-manager:closed',
			function () {
				if ( state.overlay === 'media-manager' ) {
					setOverlay( 'none' );
				}
			}
		);
		// Capture phase so the precedence predicates run before bubbling
		// handlers; the BCC / toolbar handlers register earlier (enqueue
		// dependency order) and stopPropagation when they act.
		document.addEventListener( 'keydown', handleDocumentKeydown, true );
	}

	function mount() {
		if ( state.mounted || ! bootstrap().active || config().enabled !== true ) {
			return;
		}

		state.mounted = true;

		const persisted = loadPersisted();
		state.section = persisted.section;
		state.activeType = persisted.activeType;
		state.sort = persisted.sort;

		bindDocumentListeners();

		window.DBVCVisualEditorWorkspace = {
			open,
			close,
			toggle,
			isOpen,
			setSection,
			navigate( options ) {
				const settings = options && typeof options === 'object' ? options : {};

				if ( typeof settings.search === 'string' ) {
					window.clearTimeout( state.searchTimer );
					state.searchTimer = 0;
					state.search = settings.search.slice( 0, SEARCH_MAX_LENGTH );
					const input = navigateNode( '[data-dbvc-ve-workspace-action="search"]' );
					if ( input ) {
						input.value = state.search;
					}
				}

				if ( settings.objectType ) {
					state.activeType = {
						objectType:
							[ 'all', 'post', 'term' ].indexOf( settings.objectType ) === -1
								? 'all'
								: settings.objectType,
						subtype: settings.objectType === 'all' ? '' : String( settings.subtype || '' ),
					};
					persist();
				}

				if ( typeof settings.sort === 'string' ) {
					state.sort = normalizeSort( settings.sort, state.search !== '' );
					state.autoRelevance = false;
					persist();
				}

				syncRelevanceWithSearch();

				if ( ! isOpen() ) {
					open( { focus: settings.focus !== false } );
				}

				setSection( 'navigate' );
				return runSearch();
			},
			getState: publicState,
		};

		// D-070: restore an open drawer on navigation without stealing focus.
		// VE-prefs-2: unless the viewer pinned the startup state.
		const startup = startupPreference();

		if ( startup === 'open' || ( startup === 'remember' && persisted.isOpen ) ) {
			open( { focus: false } );
		}
	}

	// Live-site QA (E-152): footer scripts evaluate while the document is still
	// loading, and overlay-app.js only builds the toolbar on DOMContentLoaded —
	// a persisted open() at eval time therefore found no toolbar button to mark
	// aria-expanded. Defer exactly like overlay-app.js does; its listener was
	// registered first, so the toolbar exists by the time ours runs.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', mount );
	} else {
		mount();
	}
} )();
