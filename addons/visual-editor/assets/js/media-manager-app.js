( function () {
	'use strict';

	const DEFAULT_QUERY = Object.freeze( {
		search: '',
		entityFamily: 'all',
		fieldFamily: 'all',
		sort: 'entity_asc',
		limit: 20,
		cursor: '',
	} );
	const CONFLICT_CODES = [
		'media_scan_busy',
		'media_scan_generation_mismatch',
		'media_scan_revision_changed',
		'media_scan_superseded',
	];
	const state = {
		root: null,
		trigger: null,
		hasLoaded: false,
		requestSequence: 0,
		expansionSequence: 0,
		requestStatus: 'idle',
		pendingRequest: '',
		presentation: 'idle',
		scan: null,
		query: Object.assign( {}, DEFAULT_QUERY ),
		items: [],
		pagination: {
			hasMore: false,
			nextCursor: '',
		},
		results: {
			status: 'idle',
			error: null,
			scrollTop: 0,
		},
		expansion: {
			groupRef: '',
			status: 'idle',
			row: null,
			error: null,
			selections: {},
			notices: {},
			saving: {},
		},
		assignSequence: 0,
		searchTimer: 0,
		error: null,
	};

	function bootstrap() {
		return window.DBVCVisualEditorBootstrap || {};
	}

	function config() {
		const value = bootstrap().mediaManager;

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
				.split( `{${ name }}` )
				.join( String( values[ name ] ) );
		} );

		return output;
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

	function createButton( action, label, extraClass ) {
		const button = createElement(
			'button',
			`dbvc-ve-media-manager__button${
				extraClass ? ` ${ extraClass }` : ''
			}`,
			label
		);

		button.type = 'button';
		button.hidden = true;
		button.setAttribute( 'data-dbvc-ve-media-manager-action', action );

		return button;
	}

	function createHeader() {
		const header = createElement(
			'header',
			'dbvc-ve-media-manager__header'
		);
		const icon = createElement(
			'span',
			'dbvc-ve-media-manager__header-icon'
		);
		const heading = createElement(
			'div',
			'dbvc-ve-media-manager__heading'
		);
		const title = createElement(
			'h2',
			'dbvc-ve-media-manager__title',
			text( 'mediaManagerTitle', 'Media Manager' )
		);
		const subtitle = createElement(
			'p',
			'dbvc-ve-media-manager__subtitle',
			text(
				'mediaManagerSubtitle',
				'Read-only scan of published content for empty image fields.'
			)
		);
		const closeButton = createElement(
			'button',
			'dbvc-ve-media-manager__close',
			'\u00d7'
		);

		icon.setAttribute( 'aria-hidden', 'true' );
		icon.textContent = '\u25a7';
		title.id = 'dbvc-ve-media-manager-title';
		subtitle.id = 'dbvc-ve-media-manager-description';
		closeButton.type = 'button';
		closeButton.setAttribute(
			'aria-label',
			text( 'mediaManagerClose', 'Close Media Manager' )
		);
		closeButton.setAttribute(
			'data-dbvc-ve-media-manager-action',
			'close'
		);

		heading.appendChild( title );
		heading.appendChild( subtitle );
		header.appendChild( icon );
		header.appendChild( heading );
		header.appendChild( closeButton );

		return header;
	}

	function createBody() {
		const body = createElement( 'div', 'dbvc-ve-media-manager__body' );
		const statusPanel = createElement(
			'section',
			'dbvc-ve-media-manager__status-panel'
		);
		const title = createElement(
			'h3',
			'dbvc-ve-media-manager__empty-title',
			text( 'mediaManagerShellTitle', 'Ready to check media' )
		);
		const description = createElement(
			'p',
			'dbvc-ve-media-manager__empty-description',
			text(
				'mediaManagerShellDescription',
				'Open the Media Manager to check for a current read-only scan.'
			)
		);
		const progress = createElement(
			'p',
			'dbvc-ve-media-manager__progress'
		);
		const actions = createElement(
			'div',
			'dbvc-ve-media-manager__actions'
		);

		statusPanel.setAttribute(
			'aria-labelledby',
			'dbvc-ve-media-manager-state-title'
		);
		title.id = 'dbvc-ve-media-manager-state-title';
		title.setAttribute( 'data-dbvc-ve-media-manager-state-title', '' );
		description.setAttribute(
			'data-dbvc-ve-media-manager-state-description',
			''
		);
		progress.hidden = true;
		progress.setAttribute( 'data-dbvc-ve-media-manager-progress', '' );
		actions.appendChild(
			createButton(
				'refresh',
				text( 'mediaManagerActionRefresh', 'Check again' )
			)
		);
		actions.appendChild(
			createButton(
				'start',
				text( 'mediaManagerActionStart', 'Start new scan' ),
				'is-primary'
			)
		);
		actions.appendChild(
			createButton(
				'next',
				text( 'mediaManagerActionNext', 'Continue scan' ),
				'is-primary'
			)
		);
		actions.appendChild(
			createButton(
				'retry',
				text( 'mediaManagerActionRetry', 'Retry scan' ),
				'is-primary'
			)
		);
		actions.appendChild(
			createButton(
				'cancel',
				text( 'mediaManagerActionCancel', 'Cancel scan' ),
				'is-danger'
			)
		);
		statusPanel.appendChild( title );
		statusPanel.appendChild( description );
		statusPanel.appendChild( progress );
		statusPanel.appendChild( actions );
		body.appendChild( statusPanel );
		body.appendChild( createResults() );

		return body;
	}

	function createRadioFilter( name, label, options ) {
		const fieldset = createElement(
			'fieldset',
			'dbvc-ve-media-manager__filter-group'
		);
		const legend = createElement(
			'legend',
			'dbvc-ve-media-manager__filter-label',
			label
		);
		const choices = createElement(
			'div',
			'dbvc-ve-media-manager__filter-choices'
		);

		options.forEach( function ( option ) {
			const id = `dbvc-ve-media-manager-${ name }-${ option.value }`;
			const input = createElement(
				'input',
				'dbvc-ve-media-manager__filter-input'
			);
			const optionLabel = createElement(
				'label',
				'dbvc-ve-media-manager__filter-chip',
				option.label
			);

			input.id = id;
			input.type = 'radio';
			input.name = `dbvc-ve-media-manager-${ name }`;
			input.value = option.value;
			input.setAttribute( 'data-dbvc-ve-media-manager-query', name );
			optionLabel.htmlFor = id;
			choices.appendChild( input );
			choices.appendChild( optionLabel );
		} );

		fieldset.appendChild( legend );
		fieldset.appendChild( choices );

		return fieldset;
	}

	function createSortControl() {
		const wrapper = createElement(
			'div',
			'dbvc-ve-media-manager__filter-control'
		);
		const label = createElement(
			'label',
			'dbvc-ve-media-manager__filter-label',
			text( 'mediaManagerSortLabel', 'Sort' )
		);
		const select = createElement(
			'select',
			'dbvc-ve-media-manager__select'
		);
		const options = [
			[
				'entity_asc',
				text( 'mediaManagerSortEntityAsc', 'Entity (A–Z)' ),
			],
			[
				'entity_desc',
				text( 'mediaManagerSortEntityDesc', 'Entity (Z–A)' ),
			],
			[
				'missing_desc',
				text(
					'mediaManagerSortMissingDesc',
					'Missing fields (most first)'
				),
			],
			[
				'missing_asc',
				text(
					'mediaManagerSortMissingAsc',
					'Missing fields (fewest first)'
				),
			],
			[
				'scanned_desc',
				text( 'mediaManagerSortScannedDesc', 'Recently scanned' ),
			],
			[
				'scanned_asc',
				text( 'mediaManagerSortScannedAsc', 'Oldest scanned' ),
			],
		];

		select.id = 'dbvc-ve-media-manager-sort';
		select.setAttribute( 'data-dbvc-ve-media-manager-query', 'sort' );
		label.htmlFor = select.id;
		options.forEach( function ( option ) {
			const node = createElement( 'option', '', option[ 1 ] );
			node.value = option[ 0 ];
			select.appendChild( node );
		} );
		wrapper.appendChild( label );
		wrapper.appendChild( select );

		return wrapper;
	}

	function createTableHeader( label, sortFamily ) {
		const header = createElement(
			'th',
			'dbvc-ve-media-manager__table-heading',
			label
		);

		header.scope = 'col';
		if ( sortFamily ) {
			header.setAttribute(
				'data-dbvc-ve-media-manager-sort-family',
				sortFamily
			);
		}

		return header;
	}

	function createResults() {
		const section = createElement(
			'section',
			'dbvc-ve-media-manager__results-section'
		);
		const summary = createElement(
			'div',
			'dbvc-ve-media-manager__summary'
		);
		const summaryTitle = createElement(
			'h3',
			'dbvc-ve-media-manager__summary-title',
			text( 'mediaManagerResultsTitle', 'Missing media results' )
		);
		const summaryCopy = createElement(
			'p',
			'dbvc-ve-media-manager__summary-copy'
		);
		const form = createElement( 'form', 'dbvc-ve-media-manager__filters' );
		const searchControl = createElement(
			'div',
			'dbvc-ve-media-manager__filter-control is-search'
		);
		const searchLabel = createElement(
			'label',
			'dbvc-ve-media-manager__filter-label',
			text( 'mediaManagerSearchLabel', 'Search entities' )
		);
		const search = createElement(
			'input',
			'dbvc-ve-media-manager__search'
		);
		const clear = createElement(
			'button',
			'dbvc-ve-media-manager__button is-quiet',
			text( 'mediaManagerClearFilters', 'Clear filters' )
		);
		const resultError = createElement(
			'div',
			'dbvc-ve-media-manager__results-error'
		);
		const resultErrorMessage = createElement(
			'p',
			'dbvc-ve-media-manager__results-error-message'
		);
		const resultRetry = createElement(
			'button',
			'dbvc-ve-media-manager__button',
			text( 'mediaManagerRetryResults', 'Retry results' )
		);
		const loading = createElement(
			'p',
			'dbvc-ve-media-manager__results-loading',
			text( 'mediaManagerResultsLoading', 'Loading matching entities…' )
		);
		const scroller = createElement(
			'div',
			'dbvc-ve-media-manager__table-scroll'
		);
		const table = createElement( 'table', 'dbvc-ve-media-manager__table' );
		const caption = createElement(
			'caption',
			'dbvc-ve-media-manager__sr-only',
			text(
				'mediaManagerTableCaption',
				'Published entities with empty supported media fields. Results use bounded cursor pages.'
			)
		);
		const tableHead = document.createElement( 'thead' );
		const headerRow = document.createElement( 'tr' );
		const tableBody = document.createElement( 'tbody' );
		const empty = createElement(
			'div',
			'dbvc-ve-media-manager__results-empty'
		);
		const emptyTitle = createElement(
			'p',
			'dbvc-ve-media-manager__empty-title'
		);
		const emptyDescription = createElement(
			'p',
			'dbvc-ve-media-manager__empty-description'
		);
		const pager = createElement( 'div', 'dbvc-ve-media-manager__pager' );
		const loaded = createElement(
			'p',
			'dbvc-ve-media-manager__loaded-count'
		);
		const loadMoreButton = createElement(
			'button',
			'dbvc-ve-media-manager__button',
			text( 'mediaManagerLoadMore', 'Load more' )
		);

		section.hidden = true;
		section.setAttribute(
			'data-dbvc-ve-media-manager-results-section',
			''
		);
		summaryTitle.id = 'dbvc-ve-media-manager-results-title';
		summaryCopy.setAttribute( 'data-dbvc-ve-media-manager-summary', '' );
		summary.appendChild( summaryTitle );
		summary.appendChild( summaryCopy );

		form.setAttribute( 'data-dbvc-ve-media-manager-filters', '' );
		search.id = 'dbvc-ve-media-manager-search';
		search.type = 'search';
		search.maxLength = 100;
		search.placeholder = text(
			'mediaManagerSearchPlaceholder',
			'Search entities…'
		);
		search.setAttribute( 'data-dbvc-ve-media-manager-query', 'search' );
		searchLabel.htmlFor = search.id;
		searchControl.appendChild( searchLabel );
		searchControl.appendChild( search );
		form.appendChild( searchControl );
		form.appendChild(
			createRadioFilter(
				'entityFamily',
				text( 'mediaManagerEntityFilterLabel', 'Entity type' ),
				[
					{
						value: 'all',
						label: text( 'mediaManagerFilterAll', 'All' ),
					},
					{
						value: 'post',
						label: text( 'mediaManagerFilterPosts', 'Posts' ),
					},
					{
						value: 'term',
						label: text( 'mediaManagerFilterTerms', 'Terms' ),
					},
				]
			)
		);
		form.appendChild(
			createRadioFilter(
				'fieldFamily',
				text( 'mediaManagerFieldFilterLabel', 'Field type' ),
				[
					{
						value: 'all',
						label: text( 'mediaManagerFilterAll', 'All' ),
					},
					{
						value: 'featured_image',
						label: text(
							'mediaManagerFamilyFeaturedImage',
							'Featured image'
						),
					},
					{
						value: 'acf_image',
						label: text(
							'mediaManagerFamilyAcfImage',
							'ACF image'
						),
					},
					{
						value: 'acf_gallery',
						label: text(
							'mediaManagerFamilyAcfGallery',
							'ACF gallery'
						),
					},
				]
			)
		);
		form.appendChild( createSortControl() );
		clear.type = 'button';
		clear.hidden = true;
		clear.setAttribute(
			'data-dbvc-ve-media-manager-action',
			'clear-filters'
		);
		form.appendChild( clear );

		resultError.hidden = true;
		resultError.setAttribute( 'role', 'alert' );
		resultError.setAttribute(
			'data-dbvc-ve-media-manager-results-error',
			''
		);
		resultErrorMessage.setAttribute(
			'data-dbvc-ve-media-manager-results-error-message',
			''
		);
		resultRetry.type = 'button';
		resultRetry.setAttribute(
			'data-dbvc-ve-media-manager-action',
			'retry-results'
		);
		resultError.appendChild( resultErrorMessage );
		resultError.appendChild( resultRetry );

		loading.hidden = true;
		loading.setAttribute(
			'data-dbvc-ve-media-manager-results-loading',
			''
		);

		scroller.tabIndex = 0;
		scroller.setAttribute( 'role', 'region' );
		scroller.setAttribute(
			'aria-labelledby',
			'dbvc-ve-media-manager-results-title'
		);
		scroller.setAttribute(
			'data-dbvc-ve-media-manager-results-scroll',
			''
		);
		scroller.addEventListener( 'scroll', function () {
			state.results.scrollTop = scroller.scrollTop;
		} );
		table.appendChild( caption );
		headerRow.appendChild(
			createTableHeader(
				text( 'mediaManagerColumnEntity', 'Entity' ),
				'entity'
			)
		);
		headerRow.appendChild(
			createTableHeader( text( 'mediaManagerColumnType', 'Type' ) )
		);
		headerRow.appendChild(
			createTableHeader(
				text( 'mediaManagerColumnMissing', 'Missing' ),
				'missing'
			)
		);
		headerRow.appendChild(
			createTableHeader(
				text( 'mediaManagerColumnFamilies', 'Field types' )
			)
		);
		headerRow.appendChild(
			createTableHeader(
				text( 'mediaManagerColumnScanned', 'Scanned' ),
				'scanned'
			)
		);
		headerRow.appendChild(
			createTableHeader( text( 'mediaManagerColumnUpdated', 'Updated' ) )
		);
		headerRow.appendChild(
			createTableHeader(
				text( 'mediaManagerColumnFrontend', 'Front end' )
			)
		);
		tableHead.appendChild( headerRow );
		table.appendChild( tableHead );
		tableBody.setAttribute( 'data-dbvc-ve-media-manager-rows', '' );
		table.appendChild( tableBody );
		scroller.appendChild( table );

		empty.hidden = true;
		emptyTitle.setAttribute( 'data-dbvc-ve-media-manager-empty-title', '' );
		emptyDescription.setAttribute(
			'data-dbvc-ve-media-manager-empty-description',
			''
		);
		empty.appendChild( emptyTitle );
		empty.appendChild( emptyDescription );
		scroller.appendChild( empty );

		loaded.setAttribute( 'data-dbvc-ve-media-manager-loaded-count', '' );
		loadMoreButton.type = 'button';
		loadMoreButton.hidden = true;
		loadMoreButton.setAttribute(
			'data-dbvc-ve-media-manager-action',
			'load-more'
		);
		pager.appendChild( loaded );
		pager.appendChild( loadMoreButton );

		section.appendChild( summary );
		section.appendChild( form );
		section.appendChild( resultError );
		section.appendChild( loading );
		section.appendChild( scroller );
		section.appendChild( pager );

		return section;
	}

	function createFooter() {
		const footer = createElement(
			'footer',
			'dbvc-ve-media-manager__footer',
			text(
				'mediaManagerReadOnly',
				'R1 is read-only. No media assignments or content values can be changed from this panel.'
			)
		);
		const status = createElement(
			'span',
			'dbvc-ve-media-manager__sr-only'
		);

		status.setAttribute( 'role', 'status' );
		status.setAttribute( 'aria-live', 'polite' );
		status.setAttribute( 'aria-atomic', 'true' );
		status.setAttribute( 'data-dbvc-ve-media-manager-status', '' );
		footer.appendChild( status );

		return footer;
	}

	function announceStatus( message ) {
		if ( ! state.root || typeof message !== 'string' || ! message ) {
			return;
		}

		const status = state.root.querySelector(
			'[data-dbvc-ve-media-manager-status]'
		);
		if ( status ) {
			status.textContent = message;
		}
	}

	function ensureRoot() {
		if ( state.root && state.root.isConnected ) {
			return state.root;
		}

		let root = document.getElementById( 'dbvc-ve-media-manager' );

		if ( ! root ) {
			root = createElement( 'section', 'dbvc-ve-media-manager' );
			root.id = 'dbvc-ve-media-manager';
			root.hidden = true;
			root.dataset.state = 'idle';
			root.setAttribute( 'role', 'dialog' );
			root.setAttribute( 'aria-modal', 'false' );
			root.setAttribute( 'aria-hidden', 'true' );
			root.setAttribute(
				'aria-labelledby',
				'dbvc-ve-media-manager-title'
			);
			root.setAttribute(
				'aria-describedby',
				'dbvc-ve-media-manager-description'
			);
			root.appendChild( createHeader() );
			root.appendChild( createBody() );
			root.appendChild( createFooter() );
			root.addEventListener( 'click', handleClick );
			root.addEventListener( 'input', handleInput );
			root.addEventListener( 'change', handleChange );
			root.addEventListener( 'submit', handleSubmit );
			document.body.appendChild( root );
		}

		state.root = root;
		renderState( false );

		return root;
	}

	function api() {
		const client = window.DBVCVisualEditorApi;

		return client &&
			client.mediaManager &&
			typeof client.mediaManager === 'object'
			? client.mediaManager
			: null;
	}

	function supportsWpMedia() {
		return Boolean(
			bootstrap().supportsWpMedia &&
				window.wp &&
				typeof window.wp.media === 'function'
		);
	}

	// R2-B stages selections client-side only; the public summary never carries
	// the descriptor token, session id, or any server-resolved target.
	function publicSelections() {
		const selections = state.expansion.selections || {};
		const summary = {};

		Object.keys( selections ).forEach( function ( findingRef ) {
			const selection = selections[ findingRef ];
			if ( ! selection || ! Array.isArray( selection.items ) ) {
				return;
			}
			summary[ findingRef ] = {
				family: selection.family,
				input: selection.input,
				count: selection.items.length,
				saved: false,
			};
		} );

		return summary;
	}

	function findExpandedField( findingRef ) {
		const row = state.expansion.row;
		const fields = row && Array.isArray( row.fields ) ? row.fields : [];

		return (
			fields.find( function ( field ) {
				return field && field.findingRef === findingRef;
			} ) || null
		);
	}

	function safeMediaUrl( value ) {
		return typeof value === 'string' && /^https?:\/\//i.test( value )
			? value
			: '';
	}

	function normalizeAttachmentSelection( attachment ) {
		const data =
			attachment && typeof attachment.toJSON === 'function'
				? attachment.toJSON()
				: null;
		if ( ! data ) {
			return null;
		}

		const id = Number( data.id || 0 ) || 0;
		if ( id <= 0 ) {
			return null;
		}

		const sizes =
			data.sizes && typeof data.sizes === 'object' ? data.sizes : {};
		const thumbnail =
			( sizes.thumbnail && sizes.thumbnail.url ) ||
			( sizes.medium && sizes.medium.url ) ||
			data.url ||
			'';

		return {
			id,
			url: safeMediaUrl( data.url ),
			thumbnail: safeMediaUrl( thumbnail ),
			title: typeof data.title === 'string' ? data.title : '',
			alt: typeof data.alt === 'string' ? data.alt : '',
		};
	}

	function normalizeDescriptorHandle( descriptor, family ) {
		const value =
			descriptor && typeof descriptor === 'object' ? descriptor : {};
		const token =
			typeof value.token === 'string' &&
			/^ve_[a-f0-9]{12}$/.test( value.token )
				? value.token
				: '';
		const sessionId =
			typeof value.sessionId === 'string' &&
			/^ves_[a-z0-9]+$/.test( value.sessionId )
				? value.sessionId
				: '';
		if ( ! token || ! sessionId ) {
			return null;
		}

		return {
			token,
			sessionId,
			input: value.input === 'gallery' ? 'gallery' : 'image',
			family,
		};
	}

	function normalizeQuery( value ) {
		const query = value && typeof value === 'object' ? value : {};
		const entityFamilies = [ 'all', 'post', 'term' ];
		const fieldFamilies = [
			'all',
			'featured_image',
			'acf_image',
			'acf_gallery',
		];
		const sorts = [
			'entity_asc',
			'entity_desc',
			'missing_asc',
			'missing_desc',
			'scanned_asc',
			'scanned_desc',
		];
		const limit = Number( query.limit );
		const cursor =
			typeof query.cursor === 'string' &&
			/^vemg_[a-f0-9]{20}$/.test( query.cursor )
				? query.cursor
				: '';

		return {
			search:
				typeof query.search === 'string'
					? query.search.trim().slice( 0, 100 )
					: '',
			entityFamily: entityFamilies.includes( query.entityFamily )
				? query.entityFamily
				: 'all',
			fieldFamily: fieldFamilies.includes( query.fieldFamily )
				? query.fieldFamily
				: 'all',
			sort: sorts.includes( query.sort ) ? query.sort : 'entity_asc',
			limit:
				Number.isInteger( limit ) && limit >= 1 && limit <= 50
					? limit
					: 20,
			cursor,
		};
	}

	function publicState() {
		return {
			hasLoaded: state.hasLoaded,
			request: {
				status: state.requestStatus,
				action: state.pendingRequest,
			},
			presentation: state.presentation,
			scan: state.scan
				? JSON.parse( JSON.stringify( state.scan ) )
				: null,
			query: Object.assign( {}, state.query ),
			items: JSON.parse( JSON.stringify( state.items ) ),
			pagination: Object.assign( {}, state.pagination ),
			results: {
				status: state.results.status,
				error: state.results.error
					? Object.assign( {}, state.results.error )
					: null,
				scrollTop: state.results.scrollTop,
			},
			expansion: {
				groupRef: state.expansion.groupRef,
				status: state.expansion.status,
				row: state.expansion.row
					? JSON.parse( JSON.stringify( state.expansion.row ) )
					: null,
				error: state.expansion.error
					? Object.assign( {}, state.expansion.error )
					: null,
				selections: publicSelections(),
				saving: Object.assign( {}, state.expansion.saving ),
			},
			error: state.error ? Object.assign( {}, state.error ) : null,
		};
	}

	function scanPresentation( scan ) {
		const backendState =
			scan && typeof scan.state === 'string' ? scan.state : '';
		const map = {
			scanning: 'scanning',
			failed: 'error',
			canceled: 'canceled',
			complete: 'complete',
			invalidated: 'invalidated',
		};

		return map[ backendState ] || 'request_error';
	}

	function syncPresentation() {
		if ( state.requestStatus === 'loading' ) {
			state.presentation = 'loading';
			return;
		}
		if (
			state.requestStatus === 'request_error' ||
			state.requestStatus === 'stale'
		) {
			state.presentation = state.requestStatus;
			return;
		}
		if ( ! state.scan ) {
			state.presentation = state.hasLoaded ? 'no_scan' : 'idle';
			return;
		}

		state.presentation = scanPresentation( state.scan );
	}

	function stateCopy() {
		if ( state.presentation === 'loading' ) {
			return {
				title: text(
					'mediaManagerStateLoadingTitle',
					'Checking Media Manager state'
				),
				description: text(
					'mediaManagerStateLoadingDescription',
					'Waiting for the protected scan service to respond.'
				),
			};
		}
		if ( state.presentation === 'no_scan' ) {
			return {
				title: text(
					'mediaManagerStateNoScanTitle',
					'No current scan'
				),
				description: text(
					'mediaManagerStateNoScanDescription',
					'Start a read-only scan to check published content for missing media.'
				),
			};
		}
		if ( state.presentation === 'scanning' ) {
			return {
				title: text(
					'mediaManagerStateScanningTitle',
					'Scan in progress'
				),
				description: text(
					'mediaManagerStateScanningDescription',
					'Continue the bounded scan when you are ready for the next chunk.'
				),
			};
		}
		if ( state.presentation === 'complete' ) {
			return {
				title: text(
					'mediaManagerStateCompleteTitle',
					'Scan complete'
				),
				description: text(
					'mediaManagerStateCompleteDescription',
					'The current scan is ready. Search or filter the bounded results below.'
				),
			};
		}
		if ( state.presentation === 'error' ) {
			return {
				title: text(
					'mediaManagerStateFailedTitle',
					'Scan could not continue'
				),
				description:
					state.scan && state.scan.error && state.scan.error.message
						? state.scan.error.message
						: text(
								'mediaManagerStateFailedDescription',
								'The scan stopped safely. Retry is available only when the server permits it.'
						  ),
			};
		}
		if ( state.presentation === 'canceled' ) {
			return {
				title: text(
					'mediaManagerStateCanceledTitle',
					'Scan canceled'
				),
				description: text(
					'mediaManagerStateCanceledDescription',
					'No content was changed. You can start a new read-only scan.'
				),
			};
		}
		if ( state.presentation === 'invalidated' ) {
			return {
				title: text(
					'mediaManagerStateInvalidatedTitle',
					'Scan configuration changed'
				),
				description: text(
					'mediaManagerStateInvalidatedDescription',
					'Start a fresh scan before relying on these results.'
				),
			};
		}
		if ( state.presentation === 'stale' ) {
			return {
				title: text(
					'mediaManagerStateStaleTitle',
					'Scan state changed'
				),
				description: text(
					'mediaManagerStateStaleDescription',
					'A newer scan revision is authoritative. Check again before continuing.'
				),
			};
		}
		if ( state.presentation === 'request_error' ) {
			return {
				title: text(
					'mediaManagerStateRequestErrorTitle',
					'Media Manager is unavailable'
				),
				description:
					state.error && state.error.message
						? state.error.message
						: text(
								'mediaManagerStateRequestErrorDescription',
								'The protected scan request could not be completed.'
						  ),
			};
		}

		return {
			title: text( 'mediaManagerShellTitle', 'Ready for scan results' ),
			description: text(
				'mediaManagerShellDescription',
				'Open the Media Manager to check for a current scan.'
			),
		};
	}

	function setActionVisible( action, visible ) {
		if ( ! state.root ) {
			return;
		}

		const button = state.root.querySelector(
			`[data-dbvc-ve-media-manager-action="${ action }"]`
		);
		if ( button ) {
			button.hidden = ! visible;
			button.disabled = state.requestStatus === 'loading';
		}
	}

	function queryIsDefault() {
		return (
			state.query.search === '' &&
			state.query.entityFamily === 'all' &&
			state.query.fieldFamily === 'all' &&
			state.query.sort === 'entity_asc'
		);
	}

	function safeFrontendUrl( value ) {
		if ( typeof value !== 'string' || value === '' ) {
			return '';
		}

		try {
			const url = new URL( value, window.location.href );
			return [ 'http:', 'https:' ].includes( url.protocol )
				? url.href
				: '';
		} catch ( error ) {
			return '';
		}
	}

	function formatTimestamp( value ) {
		const timestamp = Number( value || 0 );
		if ( ! Number.isFinite( timestamp ) || timestamp <= 0 ) {
			return text( 'mediaManagerValueUnavailable', 'Not available' );
		}

		try {
			return new Intl.DateTimeFormat(
				document.documentElement.lang || undefined,
				{
					dateStyle: 'medium',
					timeStyle: 'short',
				}
			).format( new Date( timestamp * 1000 ) );
		} catch ( error ) {
			return new Date( timestamp * 1000 ).toLocaleString();
		}
	}

	function formatModifiedGmt( value ) {
		if ( typeof value !== 'string' || value === '' ) {
			return text( 'mediaManagerValueUnavailable', 'Not available' );
		}

		const normalized = value.includes( 'T' )
			? value
			: value.replace( ' ', 'T' );
		const timestamp = Date.parse( `${ normalized }Z` );

		return Number.isFinite( timestamp )
			? formatTimestamp( Math.floor( timestamp / 1000 ) )
			: value;
	}

	function createTableCell( content, className ) {
		const cell = createElement(
			'td',
			`dbvc-ve-media-manager__table-cell${
				className ? ` ${ className }` : ''
			}`
		);

		if ( content instanceof window.Node ) {
			cell.appendChild( content );
		} else if ( typeof content === 'string' ) {
			cell.textContent = content;
		}

		return cell;
	}

	function createFindingCounts( item ) {
		const counts =
			item && item.findingCounts && typeof item.findingCounts === 'object'
				? item.findingCounts
				: {};
		const list = createElement(
			'div',
			'dbvc-ve-media-manager__family-list'
		);
		const definitions = [
			[
				'featuredImage',
				'is-featured',
				text( 'mediaManagerFamilyFeaturedImage', 'Featured image' ),
			],
			[
				'acfImage',
				'is-image',
				text( 'mediaManagerFamilyAcfImage', 'ACF image' ),
			],
			[
				'acfGallery',
				'is-gallery',
				text( 'mediaManagerFamilyAcfGallery', 'ACF gallery' ),
			],
		];
		let rendered = 0;

		definitions.forEach( function ( definition ) {
			const count = Number( counts[ definition[ 0 ] ] || 0 );
			if ( ! Number.isInteger( count ) || count < 1 ) {
				return;
			}

			const chip = createElement(
				'span',
				`dbvc-ve-media-manager__family-chip ${ definition[ 1 ] }`,
				`${ definition[ 2 ] } ${ count }`
			);
			list.appendChild( chip );
			rendered++;
		} );

		if ( rendered === 0 ) {
			list.textContent = text(
				'mediaManagerValueUnavailable',
				'Not available'
			);
		}

		return list;
	}

	function expansionId( groupRef ) {
		return `dbvc-ve-media-manager-details-${ groupRef }`;
	}

	function fieldFamilyLabel( family ) {
		const labels = {
			featured_image: text(
				'mediaManagerFamilyFeaturedImage',
				'Featured image'
			),
			acf_image: text( 'mediaManagerFamilyAcfImage', 'ACF image' ),
			acf_gallery: text( 'mediaManagerFamilyAcfGallery', 'ACF gallery' ),
		};

		return (
			labels[ family ] ||
			text( 'mediaManagerValueUnavailable', 'Not available' )
		);
	}

	function fieldStatusLabel( status ) {
		const labels = {
			missing: text( 'mediaManagerFieldStatusMissing', 'Still missing' ),
			changed: text(
				'mediaManagerFieldStatusChanged',
				'Changed since scan'
			),
			resolved_or_changed: text(
				'mediaManagerFieldStatusResolved',
				'No longer confirmed missing'
			),
			unavailable: text(
				'mediaManagerFieldStatusUnavailable',
				'Could not revalidate'
			),
		};

		return labels[ status ] || labels.unavailable;
	}

	function createAssignButton(
		action,
		label,
		findingRef,
		groupRef,
		family,
		extraClass
	) {
		const button = createElement(
			'button',
			`dbvc-ve-media-manager__button${
				extraClass ? ` ${ extraClass }` : ''
			}`,
			label
		);

		button.type = 'button';
		button.setAttribute( 'data-dbvc-ve-media-manager-action', action );
		button.setAttribute( 'data-finding-ref', findingRef );
		if ( groupRef ) {
			button.setAttribute( 'data-group-ref', groupRef );
		}
		if ( family ) {
			button.setAttribute( 'data-family', family );
		}

		return button;
	}

	function makeAssignNotice( message ) {
		const notice = createElement(
			'p',
			'dbvc-ve-media-manager__assign-notice',
			message
		);
		notice.setAttribute( 'role', 'status' );
		notice.setAttribute( 'aria-live', 'polite' );
		notice.setAttribute( 'aria-atomic', 'true' );

		return notice;
	}

	function createStagedPreview( selection ) {
		const isGallery = selection.input === 'gallery';
		const preview = createElement(
			'div',
			`dbvc-ve-media-manager__assign-preview ${
				isGallery ? 'is-gallery' : 'is-image'
			}`
		);

		selection.items.forEach( function ( picked ) {
			const figure = createElement(
				'figure',
				'dbvc-ve-media-manager__assign-thumb'
			);
			if ( picked.thumbnail ) {
				const image = document.createElement( 'img' );
				image.src = picked.thumbnail;
				image.alt = picked.alt || picked.title || '';
				image.loading = 'lazy';
				image.decoding = 'async';
				figure.appendChild( image );
			} else {
				figure.appendChild(
					createElement(
						'span',
						'dbvc-ve-media-manager__assign-thumb-fallback',
						String( picked.id )
					)
				);
			}
			preview.appendChild( figure );
		} );

		return preview;
	}

	// R2-B assignment affordance. Only supported empty fields expose it, and only
	// when wp.media is available. It stages a client-side selection and never saves.
	function createFieldAssignControls( field ) {
		if ( field.status !== 'missing' ) {
			return null;
		}

		const notice = state.expansion.notices[ field.findingRef ];

		if ( ! supportsWpMedia() ) {
			if ( notice ) {
				const wrap = createElement(
					'div',
					'dbvc-ve-media-manager__field-assign'
				);
				wrap.appendChild( makeAssignNotice( notice ) );
				return wrap;
			}
			return null;
		}

		const wrap = createElement(
			'div',
			'dbvc-ve-media-manager__field-assign'
		);
		const groupRef = state.expansion.groupRef;
		const isGallery = field.family === 'acf_gallery';
		const selection = state.expansion.selections[ field.findingRef ];
		const actions = createElement(
			'div',
			'dbvc-ve-media-manager__assign-actions'
		);

		if (
			selection &&
			Array.isArray( selection.items ) &&
			selection.items.length
		) {
			const badge = createElement(
				'span',
				'dbvc-ve-media-manager__assign-badge',
				text( 'mediaManagerAssignUnsavedBadge', 'Unsaved selection' )
			);
			badge.setAttribute( 'data-dbvc-ve-media-manager-unsaved', 'true' );
			wrap.appendChild( badge );
			wrap.appendChild( createStagedPreview( selection ) );
			wrap.appendChild(
				createElement(
					'p',
					'dbvc-ve-media-manager__assign-note',
					selection.items.length === 1
						? text(
								'mediaManagerAssignStagedSingle',
								'1 image selected. Saving arrives in a later release.'
						  )
						: templateText(
								'mediaManagerAssignStagedPlural',
								'{count} images selected. Saving arrives in a later release.',
								{ count: selection.items.length }
						  )
				)
			);
			const isSaving = Boolean(
				state.expansion.saving[ field.findingRef ]
			);
			const saveButton = createAssignButton(
				'save-assignment',
				isSaving
					? text( 'mediaManagerAssignSaving', 'Saving…' )
					: text( 'mediaManagerAssignSave', 'Save assignment' ),
				field.findingRef,
				groupRef,
				field.family,
				'is-save'
			);
			if ( isSaving ) {
				saveButton.disabled = true;
				saveButton.setAttribute( 'aria-busy', 'true' );
			}
			const replaceButton = createAssignButton(
				'assign-media',
				isGallery
					? text(
							'mediaManagerAssignReplaceGallery',
							'Replace selection'
					  )
					: text( 'mediaManagerAssignReplaceImage', 'Replace image' ),
				field.findingRef,
				groupRef,
				field.family,
				'is-assign'
			);
			const clearButton = createAssignButton(
				'clear-selection',
				text( 'mediaManagerAssignClear', 'Clear selection' ),
				field.findingRef,
				groupRef,
				field.family,
				'is-clear'
			);
			if ( isSaving ) {
				replaceButton.disabled = true;
				clearButton.disabled = true;
			}
			actions.appendChild( saveButton );
			actions.appendChild( replaceButton );
			actions.appendChild( clearButton );
		} else {
			actions.appendChild(
				createAssignButton(
					'assign-media',
					isGallery
						? text(
								'mediaManagerAssignChooseGallery',
								'Choose gallery images'
						  )
						: text(
								'mediaManagerAssignChooseImage',
								'Choose image'
						  ),
					field.findingRef,
					groupRef,
					field.family,
					'is-assign'
				)
			);
		}

		wrap.appendChild( actions );
		if ( notice ) {
			wrap.appendChild( makeAssignNotice( notice ) );
		}

		return wrap;
	}

	function createFieldProjection( field ) {
		const item = createElement( 'li', 'dbvc-ve-media-manager__field-item' );
		const heading = createElement(
			'div',
			'dbvc-ve-media-manager__field-heading'
		);
		const label = createElement(
			'strong',
			'dbvc-ve-media-manager__field-label',
			field.label || text( 'mediaManagerUnknownField', 'Media field' )
		);
		const status = createElement(
			'span',
			`dbvc-ve-media-manager__field-status is-${ field.status }`,
			fieldStatusLabel( field.status )
		);
		const meta = createElement(
			'div',
			'dbvc-ve-media-manager__field-meta'
		);
		const family = createElement(
			'span',
			'dbvc-ve-media-manager__family-chip',
			fieldFamilyLabel( field.family )
		);

		let familyClass = 'is-featured';
		if ( field.family === 'acf_gallery' ) {
			familyClass = 'is-gallery';
		} else if ( field.family === 'acf_image' ) {
			familyClass = 'is-image';
		}
		family.classList.add( familyClass );
		heading.appendChild( label );
		heading.appendChild( status );
		meta.appendChild( family );
		if ( field.contextLabel ) {
			meta.appendChild(
				createElement(
					'span',
					'dbvc-ve-media-manager__field-context',
					field.contextLabel
				)
			);
		}
		item.appendChild( heading );
		item.appendChild( meta );
		if ( field.message ) {
			item.appendChild(
				createElement(
					'p',
					'dbvc-ve-media-manager__field-message',
					field.message
				)
			);
		}

		const assignControls = createFieldAssignControls( field );
		if ( assignControls ) {
			item.appendChild( assignControls );
		}

		return item;
	}

	function expansionSummary( detail ) {
		return templateText(
			'mediaManagerExpansionSummary',
			'{missing} still missing · {changed} changed · {resolved} no longer confirmed · {unavailable} unavailable',
			{
				missing: detail.counts.missing,
				changed: detail.counts.changed,
				resolved: detail.counts.resolvedOrChanged,
				unavailable: detail.counts.unavailable,
			}
		);
	}

	function createExpandedRow( item ) {
		const row = createElement( 'tr', 'dbvc-ve-media-manager__detail-row' );
		const cell = createElement(
			'td',
			'dbvc-ve-media-manager__detail-cell'
		);
		const panel = createElement(
			'div',
			'dbvc-ve-media-manager__detail-panel'
		);
		const label =
			item && item.entity && item.entity.label
				? item.entity.label
				: text( 'mediaManagerUntitledEntity', 'Untitled content' );
		const panelTitle = createElement(
			'h4',
			'dbvc-ve-media-manager__detail-title',
			templateText(
				'mediaManagerExpandedRegionLabel',
				'Missing media fields for {entity}',
				{ entity: label }
			)
		);

		cell.colSpan = 7;
		panel.id = expansionId( item.groupRef );
		panelTitle.id = `${ panel.id }-title`;
		panel.setAttribute( 'role', 'region' );
		panel.setAttribute( 'aria-labelledby', panelTitle.id );
		panel.setAttribute(
			'aria-busy',
			state.expansion.status === 'loading' ? 'true' : 'false'
		);
		row.setAttribute(
			'data-dbvc-ve-media-manager-expanded-group',
			item.groupRef
		);

		if ( state.expansion.status === 'loading' ) {
			const loading = createElement(
				'p',
				'dbvc-ve-media-manager__detail-message',
				text(
					'mediaManagerExpansionLoading',
					'Checking the current field state…'
				)
			);
			panel.appendChild( panelTitle );
			panel.appendChild( loading );
		} else if ( state.expansion.status === 'error' ) {
			const error = createElement(
				'div',
				'dbvc-ve-media-manager__detail-error'
			);
			error.appendChild(
				createElement(
					'strong',
					'dbvc-ve-media-manager__detail-error-title',
					text(
						'mediaManagerExpansionErrorTitle',
						'Fields could not be checked'
					)
				)
			);
			error.appendChild(
				createElement(
					'p',
					'dbvc-ve-media-manager__detail-message',
					state.expansion.error
						? state.expansion.error.message
						: text(
								'mediaManagerStateRequestErrorDescription',
								'The protected scan request could not be completed.'
						  )
				)
			);
			panel.appendChild( panelTitle );
			panel.appendChild( error );
		} else if ( state.expansion.row ) {
			const detail = state.expansion.row;
			const header = createElement(
				'div',
				'dbvc-ve-media-manager__detail-header'
			);
			const summary = createElement(
				'p',
				'dbvc-ve-media-manager__detail-summary',
				expansionSummary( detail )
			);
			const list = createElement(
				'ul',
				'dbvc-ve-media-manager__field-list'
			);

			header.appendChild( panelTitle );
			header.appendChild( summary );
			panel.appendChild( header );
			if ( detail.error ) {
				const warning = createElement(
					'p',
					'dbvc-ve-media-manager__detail-warning',
					detail.error.message
				);
				warning.setAttribute( 'role', 'status' );
				warning.setAttribute( 'aria-live', 'polite' );
				warning.setAttribute( 'aria-atomic', 'true' );
				panel.appendChild( warning );
			}
			detail.fields.forEach( function ( field ) {
				list.appendChild( createFieldProjection( field ) );
			} );
			panel.appendChild( list );
		}

		cell.appendChild( panel );
		row.appendChild( cell );

		return row;
	}

	function createResultRow( item ) {
		const value = item && typeof item === 'object' ? item : {};
		const entity =
			value.entity && typeof value.entity === 'object'
				? value.entity
				: {};
		const groupRef =
			typeof value.groupRef === 'string' &&
			/^vemg_[a-f0-9]{20}$/.test( value.groupRef )
				? value.groupRef
				: '';
		const row = createElement( 'tr', 'dbvc-ve-media-manager__table-row' );
		if ( value.resolved ) {
			row.classList.add( 'is-resolved' );
			row.setAttribute( 'data-dbvc-ve-media-manager-resolved', 'true' );
		}
		const entityCell = createElement(
			'th',
			'dbvc-ve-media-manager__table-cell is-entity'
		);
		const entityLabel = createElement(
			'span',
			'dbvc-ve-media-manager__entity-label',
			typeof entity.label === 'string' && entity.label
				? entity.label
				: text( 'mediaManagerUntitledEntity', 'Untitled content' )
		);
		const type = createElement(
			'span',
			'dbvc-ve-media-manager__type-chip',
			typeof entity.typeLabel === 'string' && entity.typeLabel
				? entity.typeLabel
				: text( 'mediaManagerValueUnavailable', 'Not available' )
		);
		const missing = createElement(
			'strong',
			'dbvc-ve-media-manager__missing-count',
			String( nonNegativeInteger( value.missingCount ) )
		);
		const openUrl =
			value.availableActions && value.availableActions.openFrontend
				? safeFrontendUrl( entity.frontendUrl )
				: '';
		const canExpand = Boolean(
			groupRef && value.availableActions && value.availableActions.expand
		);
		const expanded = Boolean(
			canExpand && state.expansion.groupRef === groupRef
		);

		if ( groupRef ) {
			row.setAttribute( 'data-dbvc-ve-media-manager-group', groupRef );
		}
		entityCell.scope = 'row';
		if ( canExpand ) {
			const rowToggle = createElement(
				'button',
				'dbvc-ve-media-manager__expand-button'
			);
			const indicator = createElement(
				'span',
				'dbvc-ve-media-manager__expand-indicator',
				'›'
			);

			rowToggle.type = 'button';
			rowToggle.setAttribute(
				'data-dbvc-ve-media-manager-action',
				'toggle-row'
			);
			rowToggle.setAttribute( 'data-group-ref', groupRef );
			rowToggle.setAttribute(
				'aria-expanded',
				expanded ? 'true' : 'false'
			);
			rowToggle.setAttribute( 'aria-controls', expansionId( groupRef ) );
			rowToggle.setAttribute(
				'aria-label',
				templateText(
					expanded
						? 'mediaManagerCollapseRow'
						: 'mediaManagerExpandRow',
					expanded
						? 'Hide missing media fields for {entity}'
						: 'Show missing media fields for {entity}',
					{ entity: entityLabel.textContent }
				)
			);
			indicator.setAttribute( 'aria-hidden', 'true' );
			rowToggle.appendChild( indicator );
			rowToggle.appendChild( entityLabel );
			entityCell.appendChild( rowToggle );
		} else {
			entityCell.appendChild( entityLabel );
		}
		row.appendChild( entityCell );
		row.appendChild( createTableCell( type ) );
		row.appendChild( createTableCell( missing, 'is-numeric' ) );
		row.appendChild( createTableCell( createFindingCounts( value ) ) );
		row.appendChild(
			createTableCell( formatTimestamp( value.scannedAt ), 'is-time' )
		);
		row.appendChild(
			createTableCell( formatModifiedGmt( value.modifiedGmt ), 'is-time' )
		);

		if ( openUrl ) {
			const link = createElement(
				'a',
				'dbvc-ve-media-manager__open-link',
				text( 'mediaManagerOpenFrontend', 'Open' )
			);
			link.href = openUrl;
			link.target = '_blank';
			link.rel = 'noopener noreferrer';
			row.appendChild( createTableCell( link, 'is-link' ) );
		} else {
			row.appendChild(
				createTableCell(
					text( 'mediaManagerNoFrontendRoute', 'No route' ),
					'is-link'
				)
			);
		}

		return row;
	}

	function syncQueryControls( section ) {
		const search = section.querySelector(
			'[data-dbvc-ve-media-manager-query="search"]'
		);
		const sort = section.querySelector(
			'[data-dbvc-ve-media-manager-query="sort"]'
		);
		const radios = section.querySelectorAll(
			'input[type="radio"][data-dbvc-ve-media-manager-query]'
		);

		if ( search && search.value !== state.query.search ) {
			search.value = state.query.search;
		}
		if ( sort ) {
			sort.value = state.query.sort;
		}
		radios.forEach( function ( radio ) {
			const key = radio.getAttribute(
				'data-dbvc-ve-media-manager-query'
			);
			radio.checked = Boolean(
				key && state.query[ key ] === radio.value
			);
		} );
	}

	function syncSortHeader( section ) {
		const family = state.query.sort.replace( /_(asc|desc)$/, '' );
		const direction = state.query.sort.endsWith( '_desc' )
			? 'descending'
			: 'ascending';

		section
			.querySelectorAll( '[data-dbvc-ve-media-manager-sort-family]' )
			.forEach( function ( header ) {
				if (
					header.getAttribute(
						'data-dbvc-ve-media-manager-sort-family'
					) === family
				) {
					header.setAttribute( 'aria-sort', direction );
				} else {
					header.removeAttribute( 'aria-sort' );
				}
			} );
	}

	function emptyResultsCopy() {
		if ( state.scan && state.scan.state === 'scanning' ) {
			return {
				title: text(
					'mediaManagerNoResultsYetTitle',
					'No findings loaded yet'
				),
				description: text(
					'mediaManagerNoResultsYetDescription',
					'Continue the bounded scan to check more published entities.'
				),
			};
		}
		if ( ! queryIsDefault() ) {
			return {
				title: text(
					'mediaManagerNoMatchesTitle',
					'No entities match these filters'
				),
				description: text(
					'mediaManagerNoMatchesDescription',
					'Clear the search or widen the entity and field filters. The scan itself is unchanged.'
				),
			};
		}

		return {
			title: text(
				'mediaManagerNoFindingsTitle',
				'No missing media assignments found'
			),
			description: text(
				'mediaManagerNoFindingsDescription',
				'The current completed scan returned no accessible entities with supported empty media fields.'
			),
		};
	}

	function renderResults() {
		if ( ! state.root ) {
			return;
		}

		const section = state.root.querySelector(
			'[data-dbvc-ve-media-manager-results-section]'
		);
		if ( ! section ) {
			return;
		}

		const scanState =
			state.scan && typeof state.scan.state === 'string'
				? state.scan.state
				: '';
		const visible = Boolean(
			state.scan &&
				[ 'scanning', 'complete', 'failed' ].includes( scanState )
		);

		section.hidden = ! visible;
		state.root.dataset.hasResults = visible ? 'true' : 'false';
		if ( ! visible ) {
			return;
		}

		const table = section.querySelector( '.dbvc-ve-media-manager__table' );
		const body = section.querySelector(
			'[data-dbvc-ve-media-manager-rows]'
		);
		const summary = section.querySelector(
			'[data-dbvc-ve-media-manager-summary]'
		);
		const resultError = section.querySelector(
			'[data-dbvc-ve-media-manager-results-error]'
		);
		const resultErrorMessage = section.querySelector(
			'[data-dbvc-ve-media-manager-results-error-message]'
		);
		const loading = section.querySelector(
			'[data-dbvc-ve-media-manager-results-loading]'
		);
		const empty = section.querySelector(
			'.dbvc-ve-media-manager__results-empty'
		);
		const emptyTitle = section.querySelector(
			'[data-dbvc-ve-media-manager-empty-title]'
		);
		const emptyDescription = section.querySelector(
			'[data-dbvc-ve-media-manager-empty-description]'
		);
		const loaded = section.querySelector(
			'[data-dbvc-ve-media-manager-loaded-count]'
		);
		const loadMoreButton = section.querySelector(
			'[data-dbvc-ve-media-manager-action="load-more"]'
		);
		const clear = section.querySelector(
			'[data-dbvc-ve-media-manager-action="clear-filters"]'
		);
		const scroller = section.querySelector(
			'[data-dbvc-ve-media-manager-results-scroll]'
		);
		const controls = section.querySelectorAll( 'input, select, button' );
		const requestLoading = state.requestStatus === 'loading';
		const firstPageLoading = state.results.status === 'loading';
		const appendLoading = state.results.status === 'append_loading';
		const resultFailed = [ 'error', 'append_error' ].includes(
			state.results.status
		);

		syncQueryControls( section );
		syncSortHeader( section );
		controls.forEach( function ( control ) {
			control.disabled = requestLoading;
		} );
		if ( clear ) {
			clear.hidden = queryIsDefault();
		}

		const scanSummary =
			state.scan &&
			state.scan.summary &&
			typeof state.scan.summary === 'object'
				? state.scan.summary
				: {};
		if ( summary ) {
			summary.textContent = templateText(
				'mediaManagerSummaryCopy',
				'{entities} entities with findings · {findings} supported empty fields in the current scan',
				{
					entities: Number( scanSummary.entitiesWithFindings || 0 ),
					findings: Number( scanSummary.totalFindings || 0 ),
				}
			);
		}

		if ( resultError ) {
			resultError.hidden = ! resultFailed;
		}
		if ( resultErrorMessage ) {
			resultErrorMessage.textContent =
				resultFailed && state.results.error
					? state.results.error.message
					: '';
		}
		if ( loading ) {
			loading.hidden = ! ( firstPageLoading || appendLoading );
			loading.textContent = appendLoading
				? text(
						'mediaManagerResultsLoadingMore',
						'Loading more matching entities…'
				  )
				: text(
						'mediaManagerResultsLoading',
						'Loading matching entities…'
				  );
		}

		if ( body ) {
			const activeElement = body.ownerDocument.activeElement;
			const activeToggle =
				activeElement instanceof window.HTMLElement
					? activeElement.closest(
							'[data-dbvc-ve-media-manager-action="toggle-row"]'
					  )
					: null;
			const activeGroupRef = activeToggle
				? activeToggle.getAttribute( 'data-group-ref' )
				: '';
			body.replaceChildren();
			state.items.forEach( function ( item ) {
				body.appendChild( createResultRow( item ) );
				if ( state.expansion.groupRef === item.groupRef ) {
					body.appendChild( createExpandedRow( item ) );
				}
			} );
			if ( activeGroupRef ) {
				const replacement = body.querySelector(
					`[data-dbvc-ve-media-manager-action="toggle-row"][data-group-ref="${ activeGroupRef }"]`
				);
				if ( replacement && typeof replacement.focus === 'function' ) {
					replacement.focus( { preventScroll: true } );
				}
			}
		}
		if ( table ) {
			table.hidden = state.items.length === 0;
		}

		const showEmpty =
			state.results.status === 'success' && state.items.length === 0;
		if ( empty ) {
			empty.hidden = ! showEmpty;
		}
		if ( showEmpty ) {
			const copy = emptyResultsCopy();
			if ( emptyTitle ) {
				emptyTitle.textContent = copy.title;
			}
			if ( emptyDescription ) {
				emptyDescription.textContent = copy.description;
			}
		}

		if ( loaded ) {
			loaded.textContent =
				state.items.length > 0
					? templateText(
							'mediaManagerLoadedCount',
							'{count} entities loaded for this query.',
							{ count: state.items.length }
					  )
					: '';
		}
		if ( loadMoreButton ) {
			loadMoreButton.hidden = ! state.pagination.hasMore;
			loadMoreButton.textContent = appendLoading
				? text(
						'mediaManagerResultsLoadingMore',
						'Loading more matching entities…'
				  )
				: text( 'mediaManagerLoadMore', 'Load more' );
		}
		if ( scroller ) {
			scroller.setAttribute(
				'aria-busy',
				firstPageLoading || appendLoading ? 'true' : 'false'
			);
			scroller.scrollTop = state.results.scrollTop;
		}
	}

	function renderState( announce ) {
		if ( ! state.root ) {
			return;
		}

		syncPresentation();
		const copy = stateCopy();
		const title = state.root.querySelector(
			'[data-dbvc-ve-media-manager-state-title]'
		);
		const description = state.root.querySelector(
			'[data-dbvc-ve-media-manager-state-description]'
		);
		const progress = state.root.querySelector(
			'[data-dbvc-ve-media-manager-progress]'
		);
		const status = state.root.querySelector(
			'[data-dbvc-ve-media-manager-status]'
		);
		const scan = state.scan;
		const processed =
			scan && scan.progress ? Number( scan.progress.processed || 0 ) : 0;
		const estimate =
			scan && scan.progress
				? Number( scan.progress.totalEstimate || 0 )
				: 0;

		state.root.dataset.state = state.presentation;
		if ( title ) {
			title.textContent = copy.title;
		}
		if ( description ) {
			description.textContent = copy.description;
		}
		if ( progress ) {
			progress.hidden = ! scan;
			progress.textContent =
				estimate > 0
					? `${ text(
							'mediaManagerProgressLabel',
							'Processed'
					  ) }: ${ processed } / ${ estimate }`
					: `${ text(
							'mediaManagerProgressLabel',
							'Processed'
					  ) }: ${ processed }`;
		}

		const loading = state.requestStatus === 'loading';
		const scanState =
			scan && typeof scan.state === 'string' ? scan.state : '';
		setActionVisible(
			'refresh',
			! loading &&
				( state.presentation === 'request_error' ||
					state.presentation === 'stale' )
		);
		setActionVisible( 'start', ! loading && scanState !== 'scanning' );
		setActionVisible( 'next', ! loading && scanState === 'scanning' );
		setActionVisible(
			'retry',
			! loading && Boolean( scan && scan.canRetry )
		);
		setActionVisible(
			'cancel',
			! loading && Boolean( scan && scan.canCancel )
		);
		renderResults();

		if ( announce && status ) {
			let resultCopy = '';
			if ( state.results.status === 'success' ) {
				resultCopy = templateText(
					'mediaManagerResultsAnnouncement',
					'{count} entities loaded for the current query.',
					{
						count: state.items.length,
					}
				);
			} else if ( state.results.status === 'loading' ) {
				resultCopy = text(
					'mediaManagerResultsLoading',
					'Loading matching entities…'
				);
			} else if ( state.results.status === 'append_loading' ) {
				resultCopy = text(
					'mediaManagerResultsLoadingMore',
					'Loading more matching entities…'
				);
			}
			status.textContent = `${ copy.title }. ${ copy.description }${
				resultCopy ? ` ${ resultCopy }` : ''
			}`;
		}
	}

	function emitStateChanged() {
		document.dispatchEvent(
			new CustomEvent( 'dbvc:visual-editor:media-manager:state-changed', {
				detail: publicState(),
			} )
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

	function scanIsOlder( scan ) {
		if ( ! state.scan || ! scan ) {
			return false;
		}

		return (
			scan.scanRef === state.scan.scanRef &&
			scan.generation === state.scan.generation &&
			Number( scan.revision || 0 ) < Number( state.scan.revision || 0 )
		);
	}

	function requestError( error ) {
		return {
			code:
				error && typeof error.code === 'string'
					? error.code
					: 'media_manager_request_failed',
			message:
				error && typeof error.message === 'string'
					? error.message
					: text(
							'mediaManagerStateRequestErrorDescription',
							'The protected scan request could not be completed.'
					  ),
			status: Number( error && error.status ? error.status : 0 ),
			retryable: Boolean( error && error.retryable ),
		};
	}

	function nonNegativeInteger( value ) {
		const number = Number( value );

		return Number.isInteger( number ) && number >= 0 ? number : 0;
	}

	function normalizeListItem( item ) {
		const value = item && typeof item === 'object' ? item : {};
		const entity =
			value.entity && typeof value.entity === 'object'
				? value.entity
				: {};
		const counts =
			value.findingCounts && typeof value.findingCounts === 'object'
				? value.findingCounts
				: {};
		const actions =
			value.availableActions && typeof value.availableActions === 'object'
				? value.availableActions
				: {};
		const groupRef =
			typeof value.groupRef === 'string' &&
			/^vemg_[a-f0-9]{20}$/.test( value.groupRef )
				? value.groupRef
				: '';

		if ( ! groupRef ) {
			return null;
		}

		return {
			groupRef,
			entity: {
				label: typeof entity.label === 'string' ? entity.label : '',
				family: [ 'post', 'term' ].includes( entity.family )
					? entity.family
					: '',
				typeLabel:
					typeof entity.typeLabel === 'string'
						? entity.typeLabel
						: '',
				frontendUrl:
					typeof entity.frontendUrl === 'string'
						? entity.frontendUrl
						: '',
			},
			status: typeof value.status === 'string' ? value.status : '',
			missingCount: nonNegativeInteger( value.missingCount ),
			findingCounts: {
				featuredImage: nonNegativeInteger( counts.featuredImage ),
				acfImage: nonNegativeInteger( counts.acfImage ),
				acfGallery: nonNegativeInteger( counts.acfGallery ),
			},
			scannedAt: nonNegativeInteger( value.scannedAt ),
			modifiedGmt:
				typeof value.modifiedGmt === 'string' ? value.modifiedGmt : '',
			availableActions: {
				expand: Boolean( actions.expand ),
				openFrontend: Boolean( actions.openFrontend ),
				assignMedia: Boolean( actions.assignMedia ),
			},
		};
	}

	function normalizeExpandedField( field ) {
		const value = field && typeof field === 'object' ? field : {};
		const statuses = [
			'missing',
			'changed',
			'resolved_or_changed',
			'unavailable',
		];
		const descriptorStatuses = [
			'not_hydrated',
			'blocked_stale',
			'unavailable',
		];
		const findingRef =
			typeof value.findingRef === 'string' &&
			/^vemf_[a-f0-9]{20}$/.test( value.findingRef )
				? value.findingRef
				: '';

		if (
			! findingRef ||
			! [ 'featured_image', 'acf_image', 'acf_gallery' ].includes(
				value.family
			) ||
			! statuses.includes( value.status )
		) {
			return null;
		}

		return {
			findingRef,
			label: typeof value.label === 'string' ? value.label : '',
			family: value.family,
			contextLabel:
				typeof value.contextLabel === 'string'
					? value.contextLabel
					: '',
			status: value.status,
			descriptorStatus: descriptorStatuses.includes(
				value.descriptorStatus
			)
				? value.descriptorStatus
				: 'unavailable',
			message: typeof value.message === 'string' ? value.message : '',
			availableActions: {
				refreshScan: Boolean(
					value.availableActions && value.availableActions.refreshScan
				),
				hydrateDescriptor: false,
				assignMedia: false,
			},
		};
	}

	function normalizeExpandedRow( payload, expectedGroupRef ) {
		const value = payload && typeof payload === 'object' ? payload : {};
		const scan =
			value.scan && typeof value.scan === 'object' ? value.scan : {};
		const row = value.row && typeof value.row === 'object' ? value.row : {};
		const entity =
			row.entity && typeof row.entity === 'object' ? row.entity : {};
		const counts =
			row.counts && typeof row.counts === 'object' ? row.counts : {};
		const rowStatuses = [
			'current',
			'changed',
			'resolved_or_changed',
			'unavailable',
		];
		const currentScan = state.scan || {};

		if (
			row.groupRef !== expectedGroupRef ||
			scan.scanRef !== currentScan.scanRef ||
			scan.generation !== currentScan.generation ||
			Number( scan.revision || 0 ) !==
				Number( currentScan.revision || 0 ) ||
			! rowStatuses.includes( row.status ) ||
			! Array.isArray( row.fields )
		) {
			return null;
		}

		return {
			groupRef: expectedGroupRef,
			status: row.status,
			entity: {
				label: typeof entity.label === 'string' ? entity.label : '',
				family: [ 'post', 'term' ].includes( entity.family )
					? entity.family
					: '',
				typeLabel:
					typeof entity.typeLabel === 'string'
						? entity.typeLabel
						: '',
				frontendUrl:
					typeof entity.frontendUrl === 'string'
						? entity.frontendUrl
						: '',
			},
			counts: {
				missing: nonNegativeInteger( counts.missing ),
				changed: nonNegativeInteger( counts.changed ),
				resolvedOrChanged: nonNegativeInteger(
					counts.resolvedOrChanged
				),
				unavailable: nonNegativeInteger( counts.unavailable ),
			},
			newMissingFindingCount: nonNegativeInteger(
				row.newMissingFindingCount
			),
			fields: row.fields.map( normalizeExpandedField ).filter( Boolean ),
			error:
				row.error && typeof row.error === 'object'
					? {
							code:
								typeof row.error.code === 'string'
									? row.error.code
									: '',
							message:
								typeof row.error.message === 'string'
									? row.error.message
									: '',
							retryable: Boolean( row.error.retryable ),
					  }
					: null,
			availableActions: {
				refreshScan: Boolean(
					row.availableActions && row.availableActions.refreshScan
				),
				assignMedia: false,
			},
		};
	}

	function mergeResultItems( current, incoming ) {
		const merged = [];
		const seen = new Set();

		current.concat( incoming ).forEach( function ( item ) {
			const normalized = normalizeListItem( item );
			if ( ! normalized || seen.has( normalized.groupRef ) ) {
				return;
			}
			seen.add( normalized.groupRef );
			merged.push( normalized );
		} );

		return merged;
	}

	function commitPayload( requestId, payload, options ) {
		if ( requestId !== state.requestSequence ) {
			return null;
		}

		const scan =
			payload && payload.scan && typeof payload.scan === 'object'
				? payload.scan
				: null;
		if (
			! scan ||
			! scan.scanRef ||
			! scan.generation ||
			Number( scan.revision || 0 ) < 1
		) {
			return commitError(
				requestId,
				{
					code: 'media_manager_response_invalid',
					message: text(
						'mediaManagerStateInvalidResponse',
						'The Media Manager returned an invalid scan response.'
					),
					status: 0,
					retryable: false,
				},
				options
			);
		}
		if ( scanIsOlder( scan ) ) {
			return null;
		}

		state.hasLoaded = true;
		state.requestStatus = 'success';
		state.pendingRequest = '';
		state.error = null;
		state.scan = JSON.parse( JSON.stringify( scan ) );

		if ( payload.query && typeof payload.query === 'object' ) {
			state.query = normalizeQuery(
				Object.assign( {}, payload.query, { cursor: '' } )
			);
		}
		if ( Array.isArray( payload.items ) ) {
			state.items =
				options && options.appendResults
					? mergeResultItems( state.items, payload.items )
					: payload.items.map( normalizeListItem ).filter( Boolean );
			state.pagination = {
				hasMore: Boolean(
					payload.pagination && payload.pagination.hasMore
				),
				nextCursor:
					payload.pagination &&
					typeof payload.pagination.nextCursor === 'string'
						? payload.pagination.nextCursor
						: '',
			};
			state.results.status = 'success';
			state.results.error = null;
		} else if ( options && options.clearResults ) {
			state.items = [];
			state.pagination = { hasMore: false, nextCursor: '' };
			state.results.status = 'idle';
			state.results.error = null;
			state.results.scrollTop = 0;
		}

		renderState( true );
		emitStateChanged();

		return publicState();
	}

	function commitError( requestId, error, options ) {
		if ( requestId !== state.requestSequence ) {
			return null;
		}

		const normalized = requestError( error );
		state.hasLoaded = true;
		state.pendingRequest = '';
		state.error = normalized;

		if (
			normalized.status === 404 &&
			normalized.code === 'media_scan_expired_or_invalid'
		) {
			state.requestStatus = 'success';
			state.error = null;
			state.scan = null;
			state.items = [];
			state.pagination = { hasMore: false, nextCursor: '' };
			state.results.status = 'idle';
			state.results.error = null;
			state.results.scrollTop = 0;
		} else if (
			normalized.status === 409 &&
			CONFLICT_CODES.includes( normalized.code )
		) {
			state.requestStatus = 'stale';
		} else {
			state.requestStatus = 'request_error';
		}

		if ( options && options.resultRequest && state.scan ) {
			state.results.status = options.appendResults
				? 'append_error'
				: 'error';
			state.results.error = normalized;
		}

		renderState( true );
		emitStateChanged();

		return publicState();
	}

	function resetExpansion() {
		state.expansionSequence++;
		state.assignSequence++;
		state.expansion = {
			groupRef: '',
			status: 'idle',
			row: null,
			error: null,
			selections: {},
			notices: {},
			saving: {},
		};
	}

	function runRequest( action, executor, options ) {
		const requestOptions = options || {};
		const requestId = state.requestSequence + 1;
		state.requestSequence = requestId;
		state.requestStatus = 'loading';
		state.pendingRequest = action;
		state.error = null;
		if ( requestOptions.resultRequest ) {
			state.results.status = requestOptions.appendResults
				? 'append_loading'
				: 'loading';
			state.results.error = null;
			if ( ! requestOptions.appendResults ) {
				resetExpansion();
				state.items = [];
				state.pagination = { hasMore: false, nextCursor: '' };
				state.results.scrollTop = 0;
			}
		}
		if ( requestOptions.clearResults ) {
			resetExpansion();
		}
		renderState( true );
		emitStateChanged();

		let promise;
		try {
			promise = executor();
		} catch ( error ) {
			return Promise.resolve(
				commitError( requestId, error, requestOptions )
			);
		}

		return Promise.resolve( promise )
			.then( function ( payload ) {
				return commitPayload( requestId, payload, requestOptions );
			} )
			.catch( function ( error ) {
				return commitError( requestId, error, requestOptions );
			} );
	}

	function missingApiPromise() {
		return Promise.reject( {
			code: 'media_manager_client_unavailable',
			message: text(
				'mediaManagerStateClientUnavailable',
				'The Media Manager request client is unavailable.'
			),
			status: 0,
			retryable: false,
		} );
	}

	function collapseGroup() {
		resetExpansion();
		renderState( false );
		emitStateChanged();

		return publicState();
	}

	// Targeted re-render of just the expanded detail row so staging a selection
	// never rebuilds the whole results table and can restore field-level focus.
	function refreshExpansionPanel( focusFindingRef ) {
		const root = state.root;
		const groupRef = state.expansion.groupRef;
		if ( ! root || ! groupRef ) {
			renderState( false );
			return;
		}

		const existing = root.querySelector(
			`[data-dbvc-ve-media-manager-expanded-group="${ groupRef }"]`
		);
		const item = state.items.find( function ( candidate ) {
			return candidate.groupRef === groupRef;
		} );
		if ( ! existing || ! item ) {
			renderState( false );
			return;
		}

		const replacement = createExpandedRow( item );
		existing.replaceWith( replacement );

		if ( focusFindingRef ) {
			const control = replacement.querySelector(
				`[data-dbvc-ve-media-manager-action="assign-media"][data-finding-ref="${ focusFindingRef }"]`
			);
			if ( control && typeof control.focus === 'function' ) {
				control.focus( { preventScroll: true } );
			}
		}
	}

	function setAssignNotice( findingRef, message ) {
		if ( ! findingRef ) {
			return;
		}
		state.expansion.notices[ findingRef ] = message;
		refreshExpansionPanel( findingRef );
	}

	// R2-B: exchange the opaque finding for a fresh server-authoritative
	// descriptor, then open the native Media Library. No write occurs here.
	function beginAssignMedia( groupRef, findingRef, family ) {
		if (
			! supportsWpMedia() ||
			state.expansion.groupRef !== groupRef ||
			! state.scan
		) {
			if ( ! supportsWpMedia() ) {
				announceStatus(
					text(
						'mediaManagerAssignUnsupported',
						'Media selection is unavailable in this browser session.'
					)
				);
			}
			return;
		}

		const field = findExpandedField( findingRef );
		if ( ! field || field.status !== 'missing' ) {
			return;
		}

		delete state.expansion.notices[ findingRef ];
		const requestId = ++state.assignSequence;
		const scan = Object.assign( {}, state.scan );
		const client = api();
		announceStatus(
			text(
				'mediaManagerAssignPreparing',
				'Preparing a fresh descriptor for this field…'
			)
		);

		let promise;
		try {
			promise =
				client && typeof client.descriptor === 'function'
					? client.descriptor( scan, groupRef, findingRef )
					: missingApiPromise();
		} catch ( error ) {
			promise = Promise.reject( error );
		}

		Promise.resolve( promise )
			.then( function ( payload ) {
				if (
					requestId !== state.assignSequence ||
					state.expansion.groupRef !== groupRef
				) {
					return;
				}
				handleDescriptorPayload(
					payload,
					groupRef,
					findingRef,
					family
				);
			} )
			.catch( function ( error ) {
				if (
					requestId !== state.assignSequence ||
					state.expansion.groupRef !== groupRef
				) {
					return;
				}
				const message =
					error && error.message
						? error.message
						: text(
								'mediaManagerAssignError',
								'The media descriptor could not be prepared.'
						  );
				setAssignNotice( findingRef, message );
				announceStatus( message );
			} );
	}

	function handleDescriptorPayload( payload, groupRef, findingRef, family ) {
		const finding =
			payload && typeof payload.finding === 'object'
				? payload.finding
				: {};
		const status = typeof finding.status === 'string' ? finding.status : '';
		const handle = normalizeDescriptorHandle(
			payload && payload.descriptor,
			family
		);

		if ( status === 'writable' && handle ) {
			const field = findExpandedField( findingRef );
			if ( ! field ) {
				return;
			}
			openAssignFrame( handle, field, groupRef );
			return;
		}

		const messages = {
			changed: text(
				'mediaManagerAssignStatusChanged',
				'This field changed since the scan. Refresh the scan before assigning media.'
			),
			resolved: text(
				'mediaManagerAssignStatusResolved',
				'This field is no longer confirmed missing. Refresh the scan.'
			),
			unavailable: text(
				'mediaManagerAssignStatusUnavailable',
				'This field can no longer be edited. Refresh the scan.'
			),
		};
		const message =
			( typeof finding.message === 'string' && finding.message ) ||
			messages[ status ] ||
			messages.unavailable;
		setAssignNotice( findingRef, message );
		announceStatus( message );
	}

	function openAssignFrame( handle, field, groupRef ) {
		if ( ! supportsWpMedia() ) {
			return;
		}

		const isGallery = handle.input === 'gallery';
		const frame = window.wp.media( {
			title: isGallery
				? text(
						'mediaManagerAssignFrameGalleryTitle',
						'Select gallery images'
				  )
				: text( 'mediaManagerAssignFrameImageTitle', 'Select image' ),
			button: {
				text: isGallery
					? text(
							'mediaManagerAssignFrameGalleryButton',
							'Use selected images'
					  )
					: text(
							'mediaManagerAssignFrameImageButton',
							'Use this image'
					  ),
			},
			library: {
				type: 'image',
			},
			multiple: isGallery,
		} );

		frame.on( 'select', function () {
			const selection = frame.state().get( 'selection' );
			const items = [];

			if ( isGallery ) {
				if ( selection && typeof selection.each === 'function' ) {
					selection.each( function ( attachment ) {
						const normalized =
							normalizeAttachmentSelection( attachment );
						if ( normalized ) {
							items.push( normalized );
						}
					} );
				}
			} else {
				const attachment =
					selection && typeof selection.first === 'function'
						? selection.first()
						: null;
				const normalized = normalizeAttachmentSelection( attachment );
				if ( normalized ) {
					items.push( normalized );
				}
			}

			stageSelection( handle, field.findingRef, groupRef, items );
		} );

		frame.open();
	}

	function stageSelection( handle, findingRef, groupRef, items ) {
		if (
			state.expansion.groupRef !== groupRef ||
			! Array.isArray( items ) ||
			items.length === 0
		) {
			return;
		}

		const field = findExpandedField( findingRef );
		if ( ! field || field.status !== 'missing' ) {
			return;
		}

		delete state.expansion.notices[ findingRef ];
		state.expansion.selections[ findingRef ] = {
			descriptorToken: handle.token,
			sessionId: handle.sessionId,
			family: field.family,
			input: handle.input,
			items,
			saved: false,
		};
		refreshExpansionPanel( findingRef );
		announceStatus(
			templateText(
				'mediaManagerAssignStagedAnnouncement',
				'{count} image(s) selected for {label} but not saved yet.',
				{ count: items.length, label: field.label || '' }
			)
		);
		emitStateChanged();
	}

	function clearStagedSelection( findingRef ) {
		if (
			! state.expansion.selections[ findingRef ] ||
			state.expansion.saving[ findingRef ]
		) {
			return;
		}
		delete state.expansion.selections[ findingRef ];
		refreshExpansionPanel( findingRef );
		announceStatus(
			text(
				'mediaManagerAssignClearedAnnouncement',
				'Selection cleared. Nothing was saved.'
			)
		);
		emitStateChanged();
	}

	// R2-C: save the staged selection through the dedicated, revalidated assignment
	// endpoint. The server enforces the expected-empty precondition; the client
	// never writes and reconciles from the returned reread without a table reload.
	function saveAssignment( groupRef, findingRef ) {
		if ( state.expansion.groupRef !== groupRef || ! state.scan ) {
			return;
		}

		const selection = state.expansion.selections[ findingRef ];
		const field = findExpandedField( findingRef );
		if (
			! selection ||
			! field ||
			field.status !== 'missing' ||
			state.expansion.saving[ findingRef ]
		) {
			return;
		}

		const value =
			selection.input === 'gallery'
				? {
						attachmentIds: selection.items.map( function ( item ) {
							return Number( item.id ) || 0;
						} ),
				  }
				: {
						attachmentId:
							Number(
								selection.items[ 0 ] && selection.items[ 0 ].id
							) || 0,
				  };

		state.expansion.saving[ findingRef ] = true;
		delete state.expansion.notices[ findingRef ];
		refreshExpansionPanel( findingRef );
		announceStatus(
			text(
				'mediaManagerAssignSavingAnnouncement',
				'Saving media assignment…'
			)
		);

		const scan = Object.assign( {}, state.scan );
		const client = api();

		let promise;
		try {
			promise =
				client && typeof client.assign === 'function'
					? client.assign( scan, groupRef, findingRef, value )
					: missingApiPromise();
		} catch ( error ) {
			promise = Promise.reject( error );
		}

		Promise.resolve( promise )
			.then( function ( payload ) {
				if (
					state.expansion.groupRef !== groupRef ||
					! state.expansion.saving[ findingRef ]
				) {
					return;
				}
				reconcileAfterSave( payload, groupRef, findingRef );
			} )
			.catch( function ( error ) {
				if (
					state.expansion.groupRef !== groupRef ||
					! state.expansion.saving[ findingRef ]
				) {
					return;
				}
				delete state.expansion.saving[ findingRef ];
				const message =
					error && error.message
						? error.message
						: text(
								'mediaManagerAssignSaveError',
								'The media assignment could not be saved.'
						  );
				setAssignNotice( findingRef, message );
				announceStatus( message );
			} );
	}

	function reconcileAfterSave( payload, groupRef, findingRef ) {
		if (
			state.expansion.groupRef !== groupRef ||
			! state.expansion.saving[ findingRef ]
		) {
			return;
		}

		delete state.expansion.saving[ findingRef ];
		delete state.expansion.selections[ findingRef ];
		delete state.expansion.notices[ findingRef ];

		const savedField = findExpandedField( findingRef );
		const savedLabel = savedField ? savedField.label || '' : '';
		const oldMissing =
			state.expansion.row && state.expansion.row.counts
				? Number( state.expansion.row.counts.missing ) || 0
				: 0;
		const row = normalizeExpandedRow(
			payload && typeof payload === 'object' ? payload : {},
			groupRef
		);

		if ( row ) {
			state.expansion.row = row;
			const newMissing = Number( row.counts.missing ) || 0;
			reconcileGroupItem( groupRef, row, oldMissing, newMissing );
		}

		// Re-render from updated local state only. No list/scan request is issued.
		renderState( false );
		announceStatus(
			templateText(
				'mediaManagerAssignSavedAnnouncement',
				'Media assigned for {label}. This field is no longer empty.',
				{ label: savedLabel }
			)
		);
		emitStateChanged();
	}

	function reconcileGroupItem( groupRef, row, oldMissing, newMissing ) {
		const item = state.items.find( function ( candidate ) {
			return candidate.groupRef === groupRef;
		} );
		if ( item ) {
			item.missingCount = newMissing;
			item.findingCounts = countMissingFieldFamilies( row.fields );
			item.resolved = newMissing === 0;
		}

		const resolvedDelta = Math.max( 0, oldMissing - newMissing );
		if ( state.scan && state.scan.summary && resolvedDelta > 0 ) {
			state.scan.summary.totalFindings = Math.max(
				0,
				Number( state.scan.summary.totalFindings || 0 ) - resolvedDelta
			);
			if ( newMissing === 0 && oldMissing > 0 ) {
				state.scan.summary.entitiesWithFindings = Math.max(
					0,
					Number( state.scan.summary.entitiesWithFindings || 0 ) - 1
				);
			}
		}
	}

	function countMissingFieldFamilies( fields ) {
		const counts = { featuredImage: 0, acfImage: 0, acfGallery: 0 };
		( Array.isArray( fields ) ? fields : [] ).forEach( function ( field ) {
			if ( ! field || field.status !== 'missing' ) {
				return;
			}
			if ( field.family === 'featured_image' ) {
				counts.featuredImage++;
			} else if ( field.family === 'acf_image' ) {
				counts.acfImage++;
			} else if ( field.family === 'acf_gallery' ) {
				counts.acfGallery++;
			}
		} );

		return counts;
	}

	function expandGroup( groupRef ) {
		const normalizedGroupRef =
			typeof groupRef === 'string' &&
			/^vemg_[a-f0-9]{20}$/.test( groupRef )
				? groupRef
				: '';
		const item = state.items.find( function ( candidate ) {
			return candidate.groupRef === normalizedGroupRef;
		} );

		if (
			! normalizedGroupRef ||
			! item ||
			! item.availableActions.expand ||
			! state.scan
		) {
			return Promise.resolve( publicState() );
		}
		if ( state.expansion.groupRef === normalizedGroupRef ) {
			return Promise.resolve( collapseGroup() );
		}

		const client = api();
		const scan = Object.assign( {}, state.scan );
		const label =
			item.entity && item.entity.label
				? item.entity.label
				: text( 'mediaManagerUntitledEntity', 'Untitled content' );
		const requestId = state.expansionSequence + 1;
		state.expansionSequence = requestId;
		state.assignSequence++;
		state.expansion = {
			groupRef: normalizedGroupRef,
			status: 'loading',
			row: null,
			error: null,
			selections: {},
			notices: {},
			saving: {},
		};
		renderState( false );
		announceStatus(
			templateText(
				'mediaManagerExpansionLoadingAnnouncement',
				'Checking missing media fields for {entity}.',
				{ entity: label }
			)
		);
		emitStateChanged();

		let promise;
		try {
			promise =
				client && typeof client.group === 'function'
					? client.group( scan, normalizedGroupRef )
					: missingApiPromise();
		} catch ( error ) {
			promise = Promise.reject( error );
		}

		return Promise.resolve( promise )
			.then( function ( payload ) {
				if (
					requestId !== state.expansionSequence ||
					state.expansion.groupRef !== normalizedGroupRef
				) {
					return null;
				}

				const row = normalizeExpandedRow( payload, normalizedGroupRef );
				if ( ! row ) {
					const error = new Error(
						text(
							'mediaManagerExpansionInvalid',
							'The Media Manager returned an invalid field response.'
						)
					);
					error.code = 'media_manager_group_response_invalid';
					error.status = 0;
					error.retryable = false;
					throw error;
				}

				state.expansion.status = 'success';
				state.expansion.row = row;
				state.expansion.error = null;
				renderState( false );
				announceStatus(
					templateText(
						'mediaManagerExpansionCompleteAnnouncement',
						'Field check complete for {entity}. {summary}.',
						{
							entity: label,
							summary: expansionSummary( row ),
						}
					)
				);
				emitStateChanged();

				return publicState();
			} )
			.catch( function ( error ) {
				if (
					requestId !== state.expansionSequence ||
					state.expansion.groupRef !== normalizedGroupRef
				) {
					return null;
				}

				const normalizedError = requestError( error );
				state.expansion.status = 'error';
				state.expansion.row = null;
				state.expansion.error = normalizedError;
				renderState( false );
				announceStatus(
					templateText(
						'mediaManagerExpansionErrorAnnouncement',
						'Fields could not be checked for {entity}. {message}',
						{
							entity: label,
							message: normalizedError.message,
						}
					)
				);
				emitStateChanged();

				return publicState();
			} );
	}

	function loadLatest( query ) {
		const client = api();
		const requestedQuery = normalizeQuery(
			Object.assign( {}, state.query, query || {}, { cursor: '' } )
		);
		state.query = Object.assign( {}, requestedQuery );

		return runRequest(
			'latest',
			function () {
				return client && typeof client.latest === 'function'
					? client.latest( requestedQuery )
					: missingApiPromise();
			},
			{ resultRequest: true }
		);
	}

	function startScan() {
		const client = api();

		return runRequest(
			'start',
			function () {
				return client && typeof client.start === 'function'
					? client.start()
					: missingApiPromise();
			},
			{ clearResults: true }
		);
	}

	function loadList( query, options ) {
		const client = api();
		const scan = state.scan ? Object.assign( {}, state.scan ) : null;
		const requestedQuery = normalizeQuery(
			Object.assign( {}, state.query, query || {} )
		);
		const appendResults = Boolean(
			( options && options.append ) || requestedQuery.cursor
		);

		state.query = normalizeQuery(
			Object.assign( {}, requestedQuery, { cursor: '' } )
		);

		return runRequest(
			'list',
			function () {
				return client && typeof client.list === 'function'
					? client.list( scan, requestedQuery )
					: missingApiPromise();
			},
			{
				appendResults,
				resultRequest: true,
			}
		);
	}

	function runScanAction( action ) {
		const client = api();
		const scan = state.scan ? Object.assign( {}, state.scan ) : null;

		return runRequest(
			action,
			function () {
				return client && typeof client[ action ] === 'function'
					? client[ action ]( scan )
					: missingApiPromise();
			},
			{ clearResults: true }
		);
	}

	function advanceScan() {
		return runScanAction( 'next' ).then( function ( result ) {
			if (
				! result ||
				state.requestStatus !== 'success' ||
				! state.scan
			) {
				return result;
			}

			return [ 'scanning', 'complete', 'failed' ].includes(
				state.scan.state
			)
				? loadList( state.query )
				: result;
		} );
	}

	function loadMore() {
		if (
			state.requestStatus === 'loading' ||
			! state.pagination.hasMore ||
			! state.pagination.nextCursor
		) {
			return Promise.resolve( publicState() );
		}

		return loadList(
			Object.assign( {}, state.query, {
				cursor: state.pagination.nextCursor,
			} ),
			{ append: true }
		);
	}

	function queryFromControls() {
		if ( ! state.root ) {
			return Object.assign( {}, state.query );
		}

		const search = state.root.querySelector(
			'[data-dbvc-ve-media-manager-query="search"]'
		);
		const entity = state.root.querySelector(
			'[data-dbvc-ve-media-manager-query="entityFamily"]:checked'
		);
		const field = state.root.querySelector(
			'[data-dbvc-ve-media-manager-query="fieldFamily"]:checked'
		);
		const sort = state.root.querySelector(
			'[data-dbvc-ve-media-manager-query="sort"]'
		);

		return normalizeQuery( {
			search: search ? search.value : '',
			entityFamily: entity ? entity.value : 'all',
			fieldFamily: field ? field.value : 'all',
			sort: sort ? sort.value : 'entity_asc',
			limit: state.query.limit,
			cursor: '',
		} );
	}

	function applyQueryFromControls() {
		window.clearTimeout( state.searchTimer );
		state.searchTimer = 0;

		return loadList( queryFromControls() );
	}

	function scheduleSearch() {
		window.clearTimeout( state.searchTimer );
		state.searchTimer = window.setTimeout( function () {
			applyQueryFromControls();
		}, 300 );
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
		root.setAttribute( 'aria-hidden', 'false' );
		setTriggerExpanded( true );

		const closeButton = root.querySelector(
			'[data-dbvc-ve-media-manager-action="close"]'
		);
		if ( closeButton && typeof closeButton.focus === 'function' ) {
			window.requestAnimationFrame( function () {
				closeButton.focus();
			} );
		}

		document.dispatchEvent(
			new CustomEvent( 'dbvc:visual-editor:media-manager:opened' )
		);
		if ( ! state.hasLoaded && state.requestStatus !== 'loading' ) {
			loadLatest();
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
			new CustomEvent( 'dbvc:visual-editor:media-manager:closed' )
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

	function mediaModalIsOpen( target ) {
		if (
			target &&
			typeof target.closest === 'function' &&
			target.closest( '.media-modal, .media-frame' )
		) {
			return true;
		}

		return Boolean(
			document.querySelector(
				'.media-modal:not([aria-hidden="true"]), .media-frame:not([aria-hidden="true"])'
			)
		);
	}

	function handleClick( event ) {
		const action =
			event.target && typeof event.target.closest === 'function'
				? event.target.closest( '[data-dbvc-ve-media-manager-action]' )
				: null;
		const actionName = action
			? action.getAttribute( 'data-dbvc-ve-media-manager-action' )
			: '';

		if ( ! actionName ) {
			return;
		}

		event.preventDefault();
		if ( actionName === 'close' ) {
			close( { restoreFocus: true } );
		} else if ( actionName === 'refresh' ) {
			loadLatest();
		} else if ( actionName === 'start' ) {
			startScan();
		} else if ( actionName === 'next' ) {
			advanceScan();
		} else if ( actionName === 'retry' || actionName === 'cancel' ) {
			runScanAction( actionName );
		} else if ( actionName === 'load-more' ) {
			loadMore();
		} else if ( actionName === 'retry-results' ) {
			if ( state.results.status === 'append_error' ) {
				loadMore();
			} else {
				loadList( state.query );
			}
		} else if ( actionName === 'clear-filters' ) {
			loadList(
				Object.assign( {}, DEFAULT_QUERY, { limit: state.query.limit } )
			);
		} else if ( actionName === 'toggle-row' ) {
			expandGroup( action.getAttribute( 'data-group-ref' ) || '' );
		} else if ( actionName === 'assign-media' ) {
			beginAssignMedia(
				action.getAttribute( 'data-group-ref' ) || '',
				action.getAttribute( 'data-finding-ref' ) || '',
				action.getAttribute( 'data-family' ) || ''
			);
		} else if ( actionName === 'clear-selection' ) {
			clearStagedSelection(
				action.getAttribute( 'data-finding-ref' ) || ''
			);
		} else if ( actionName === 'save-assignment' ) {
			saveAssignment(
				action.getAttribute( 'data-group-ref' ) || '',
				action.getAttribute( 'data-finding-ref' ) || ''
			);
		}
	}

	function handleInput( event ) {
		if (
			event.target &&
			event.target.matches(
				'[data-dbvc-ve-media-manager-query="search"]'
			)
		) {
			scheduleSearch();
		}
	}

	function handleChange( event ) {
		if (
			! event.target ||
			! event.target.matches( '[data-dbvc-ve-media-manager-query]' )
		) {
			return;
		}
		if (
			event.target.matches(
				'[data-dbvc-ve-media-manager-query="search"]'
			)
		) {
			return;
		}

		applyQueryFromControls();
	}

	function handleSubmit( event ) {
		if (
			! event.target ||
			! event.target.matches( '[data-dbvc-ve-media-manager-filters]' )
		) {
			return;
		}

		event.preventDefault();
		applyQueryFromControls();
	}

	function handleKeydown( event ) {
		if (
			! state.root ||
			state.root.hidden ||
			event.key !== 'Escape' ||
			mediaModalIsOpen( event.target )
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
			'dbvc:visual-editor:media-manager:toggle',
			function ( event ) {
				toggle( event && event.detail ? event.detail : {} );
			}
		);
		document.addEventListener(
			'dbvc:visual-editor:media-manager:close',
			function ( event ) {
				close( event && event.detail ? event.detail : {} );
			}
		);
		document.addEventListener( 'keydown', handleKeydown, true );

		window.DBVCVisualEditorMediaManager = {
			cancel() {
				return runScanAction( 'cancel' );
			},
			close,
			collapse: collapseGroup,
			expand: expandGroup,
			getState: publicState,
			isOpen() {
				return Boolean( state.root && ! state.root.hidden );
			},
			list: loadList,
			loadMore,
			loadLatest,
			next() {
				return advanceScan();
			},
			open,
			retry() {
				return runScanAction( 'retry' );
			},
			start: startScan,
		};
	}

	mount();
} )();
