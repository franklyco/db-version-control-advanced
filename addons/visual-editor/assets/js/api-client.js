( function () {
	// R5.later-perf (2026-09-06) — gated `dbvc.ve.api.*` User Timing spans
	// for the Visual Editor / BCC api surface. Enabled via URL query arg
	// `?dbvc_ve_perf=1` — zero-cost no-op otherwise (early-return skips the
	// wrap; the original api object is exposed unchanged). Feeds Chrome
	// DevTools Performance panel's User Timing track for the audit recipe
	// at docs/dropins/dbvc-visual-editor-brand-controls-guide/qa/
	// R5-LATER-PERF-MEASUREMENT-RECIPE.md. Media-manager methods are
	// intentionally NOT instrumented — MM has its own audit slice.
	const PERF_PREFIX = 'dbvc.ve.';
	let perfMeasureId = 0;

	function isPerformanceProfilerEnabled() {
		try {
			if ( typeof window.URLSearchParams !== 'function' ) {
				return false;
			}
			return (
				new window.URLSearchParams( window.location.search ).get(
					'dbvc_ve_perf'
				) === '1'
			);
		} catch ( err ) {
			return false;
		}
	}

	function supportsPerformanceTimings() {
		return Boolean(
			window.performance &&
				typeof window.performance.mark === 'function' &&
				typeof window.performance.measure === 'function'
		);
	}

	function normalizePerfName( name ) {
		return (
			String( name || 'step' )
				.replace( /[^a-z0-9_.:-]+/gi, '_' )
				.replace( /^_+|_+$/g, '' )
				.slice( 0, 80 ) || 'step'
		);
	}

	function createPerfSpan( name ) {
		if ( ! isPerformanceProfilerEnabled() || ! supportsPerformanceTimings() ) {
			return { end() {} };
		}
		const normalized = normalizePerfName( name );
		const id = `${ Date.now() }.${ ++perfMeasureId }`;
		const startName = `${ PERF_PREFIX }${ normalized }.start.${ id }`;
		const endName = `${ PERF_PREFIX }${ normalized }.end.${ id }`;
		let ended = false;
		try {
			window.performance.mark( startName );
		} catch ( err ) {
			return { end() {} };
		}
		return {
			end() {
				if ( ended ) {
					return;
				}
				ended = true;
				try {
					window.performance.mark( endName );
					window.performance.measure(
						`${ PERF_PREFIX }${ normalized }`,
						startName,
						endName
					);
					if ( typeof window.performance.clearMarks === 'function' ) {
						window.performance.clearMarks( startName );
						window.performance.clearMarks( endName );
					}
				} catch ( err ) {
					/* swallow */
				}
			},
		};
	}

	function measurePerf( name, callback ) {
		const span = createPerfSpan( name );
		try {
			const result = callback();
			if ( result && typeof result.finally === 'function' ) {
				return result.finally( function () {
					span.end();
				} );
			}
			span.end();
			return result;
		} catch ( err ) {
			span.end();
			throw err;
		}
	}

	function mediaManagerBaseUrl() {
		const bootstrap = window.DBVCVisualEditorBootstrap || {};
		const config = bootstrap.mediaManager;
		const baseUrl =
			config && typeof config === 'object' ? config.restBase : '';

		return typeof baseUrl === 'string' ? baseUrl.replace( /\/+$/, '' ) : '';
	}

	function mediaManagerError( message, code, status, data ) {
		const error = new Error( message );

		error.code = code || 'media_manager_request_failed';
		error.status = Number( status || 0 );
		error.retryable = Boolean( data && data.retryable );
		error.data = data || null;

		return error;
	}

	function mediaManagerRequest( path, options ) {
		const baseUrl = mediaManagerBaseUrl();
		const bootstrap = window.DBVCVisualEditorBootstrap || {};

		if ( ! baseUrl ) {
			return Promise.reject(
				mediaManagerError(
					'The Media Manager endpoint is unavailable.',
					'media_manager_endpoint_unavailable',
					0,
					null
				)
			);
		}

		const requestOptions = Object.assign( {}, options || {} );
		requestOptions.headers = Object.assign(
			{
				'X-WP-Nonce': bootstrap.nonce || '',
			},
			requestOptions.headers || {}
		);

		return fetch( `${ baseUrl }${ path }`, requestOptions ).then(
			async ( response ) => {
				const data = await response.json().catch( function () {
					return null;
				} );

				if ( response.ok ) {
					return data;
				}

				throw mediaManagerError(
					( data && data.message ) ||
						`Media Manager request failed (${ response.status }).`,
					data && data.code,
					response.status,
					data
				);
			}
		);
	}

	function mediaManagerQuery( query, includeCursor ) {
		const value = query && typeof query === 'object' ? query : {};
		const params = new URLSearchParams();

		[ 'search', 'entityFamily', 'fieldFamily', 'sort' ].forEach(
			function ( key ) {
				if ( typeof value[ key ] === 'string' && value[ key ] !== '' ) {
					params.set( key, value[ key ] );
				}
			}
		);

		if ( Number.isInteger( value.limit ) && value.limit > 0 ) {
			params.set( 'limit', String( value.limit ) );
		}

		if (
			includeCursor &&
			typeof value.cursor === 'string' &&
			value.cursor !== ''
		) {
			params.set( 'cursor', value.cursor );
		}

		return params;
	}

	function mediaManagerIdentity( scan ) {
		const value = scan && typeof scan === 'object' ? scan : {};
		const identity = {
			scanRef: typeof value.scanRef === 'string' ? value.scanRef : '',
			generation:
				typeof value.generation === 'string' ? value.generation : '',
			expectedRevision: Number( value.revision || 0 ),
		};

		if (
			! identity.scanRef ||
			! identity.generation ||
			! Number.isInteger( identity.expectedRevision ) ||
			identity.expectedRevision < 1
		) {
			throw mediaManagerError(
				'The Media Manager scan identity is unavailable.',
				'media_manager_identity_unavailable',
				0,
				null
			);
		}

		return identity;
	}

	function mediaManagerAction( action, scan ) {
		let identity;

		try {
			identity = mediaManagerIdentity( scan );
		} catch ( error ) {
			return Promise.reject( error );
		}

		return mediaManagerRequest(
			`/scans/${ encodeURIComponent( identity.scanRef ) }/${ action }`,
			{
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( {
					generation: identity.generation,
					expectedRevision: identity.expectedRevision,
				} ),
			}
		);
	}

	window.DBVCVisualEditorApi = {
		getSession( sessionId, options ) {
			const shouldHydrate = Boolean( options && options.hydrate );
			const query = shouldHydrate ? '?hydrate=1' : '';

			return fetch(
				`${
					DBVCVisualEditorBootstrap.restBase
				}/session/${ encodeURIComponent( sessionId ) }${ query }`,
				{
					headers: { 'X-WP-Nonce': DBVCVisualEditorBootstrap.nonce },
				}
			).then( async ( response ) => {
				const data = await response.json().catch( function () {
					return null;
				} );

				if ( response.ok ) {
					return data;
				}

				throw new Error(
					( data && data.message ) ||
						`Visual Editor session request failed (${ response.status }).`
				);
			} );
		},

		getDescriptor( sessionId, token ) {
			return fetch(
				`${
					DBVCVisualEditorBootstrap.restBase
				}/session/${ encodeURIComponent(
					sessionId
				) }/descriptor/${ encodeURIComponent( token ) }`,
				{
					headers: { 'X-WP-Nonce': DBVCVisualEditorBootstrap.nonce },
				}
			).then( async ( response ) => {
				const data = await response.json().catch( function () {
					return null;
				} );

				if ( response.ok ) {
					return data;
				}

				throw new Error(
					( data && data.message ) ||
						`Visual Editor descriptor request failed (${ response.status }).`
				);
			} );
		},

		getDescriptors( sessionId, tokens ) {
			return fetch(
				`${
					DBVCVisualEditorBootstrap.restBase
				}/session/${ encodeURIComponent( sessionId ) }/descriptors`,
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': DBVCVisualEditorBootstrap.nonce,
					},
					body: JSON.stringify( {
						tokens: Array.isArray( tokens ) ? tokens : [],
					} ),
				}
			).then( async ( response ) => {
				const data = await response.json().catch( function () {
					return null;
				} );

				if ( response.ok ) {
					return data;
				}

				throw new Error(
					( data && data.message ) ||
						`Visual Editor descriptor batch request failed (${ response.status }).`
				);
			} );
		},

		touchSession( sessionId ) {
			return fetch(
				`${
					DBVCVisualEditorBootstrap.restBase
				}/session/${ encodeURIComponent( sessionId ) }/touch`,
				{
					method: 'POST',
					headers: { 'X-WP-Nonce': DBVCVisualEditorBootstrap.nonce },
				}
			).then( async ( response ) => {
				const data = await response.json().catch( function () {
					return null;
				} );

				if ( response.ok ) {
					return data;
				}

				throw new Error(
					( data && data.message ) ||
						`Visual Editor session touch failed (${ response.status }).`
				);
			} );
		},

		searchReferences( sessionId, token, search ) {
			const params = new URLSearchParams();

			if ( typeof search === 'string' && search.trim() ) {
				params.set( 'search', search.trim() );
			}

			return fetch(
				`${
					DBVCVisualEditorBootstrap.restBase
				}/session/${ encodeURIComponent(
					sessionId
				) }/reference-search/${ encodeURIComponent(
					token
				) }?${ params.toString() }`,
				{
					headers: { 'X-WP-Nonce': DBVCVisualEditorBootstrap.nonce },
				}
			).then( async ( response ) => {
				const data = await response.json().catch( function () {
					return null;
				} );

				if ( response.ok ) {
					return data;
				}

				throw new Error(
					( data && data.message ) ||
						`Visual Editor reference search failed (${ response.status }).`
				);
			} );
		},

		searchObjects( search, objectType ) {
			const params = new URLSearchParams();

			if ( typeof search === 'string' && search.trim() ) {
				params.set( 'search', search.trim() );
			}

			if (
				typeof objectType === 'string' &&
				objectType.trim() &&
				objectType !== 'all'
			) {
				params.set( 'objectType', objectType.trim() );
			}

			return fetch(
				`${
					DBVCVisualEditorBootstrap.restBase
				}/object-search?${ params.toString() }`,
				{
					headers: { 'X-WP-Nonce': DBVCVisualEditorBootstrap.nonce },
				}
			).then( async ( response ) => {
				const data = await response.json().catch( function () {
					return null;
				} );

				if ( response.ok ) {
					return data;
				}

				throw new Error(
					( data && data.message ) ||
						`Visual Editor object search failed (${ response.status }).`
				);
			} );
		},

		getSharedGlobalFields( sessionId ) {
			return fetch(
				`${
					DBVCVisualEditorBootstrap.restBase
				}/session/${ encodeURIComponent(
					sessionId
				) }/shared-global-fields`,
				{
					headers: { 'X-WP-Nonce': DBVCVisualEditorBootstrap.nonce },
				}
			).then( async ( response ) => {
				const data = await response.json().catch( function () {
					return null;
				} );

				if ( response.ok ) {
					return data;
				}

				throw new Error(
					( data && data.message ) ||
						`Visual Editor shared global fields request failed (${ response.status }).`
				);
			} );
		},

		save( sessionId, token, value, acknowledgeSharedScope ) {
			return fetch(
				`${
					DBVCVisualEditorBootstrap.restBase
				}/session/${ encodeURIComponent( sessionId ) }/save`,
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': DBVCVisualEditorBootstrap.nonce,
					},
					body: JSON.stringify( {
						token,
						value,
						acknowledgeSharedScope: Boolean(
							acknowledgeSharedScope
						),
					} ),
				}
			).then( async ( response ) => {
				const data = await response.json().catch( function () {
					return null;
				} );

				if ( response.ok ) {
					return data;
				}

				throw new Error(
					( data && data.message ) ||
						`Visual Editor save request failed (${ response.status }).`
				);
			} );
		},

		saveComposite( sessionId, token, values, options ) {
			const payload = Object.assign(
				{
					values: Array.isArray( values ) ? values : [],
					baseValues: [],
					acknowledgeCompositeScope: false,
					acknowledgements: {},
				},
				options || {}
			);

			return fetch(
				`${
					DBVCVisualEditorBootstrap.restBase
				}/session/${ encodeURIComponent(
					sessionId
				) }/composite-save/${ encodeURIComponent( token ) }`,
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': DBVCVisualEditorBootstrap.nonce,
					},
					body: JSON.stringify( payload ),
				}
			).then( async ( response ) => {
				const data = await response.json().catch( function () {
					return null;
				} );

				if ( response.ok ) {
					return data;
				}

				const error = new Error(
					( data && data.message ) ||
						`Visual Editor composite save request failed (${ response.status }).`
				);

				error.status = response.status;
				error.data = data;

				throw error;
			} );
		},

		seedCurrentField( sessionId, token, options ) {
			const payload = Object.assign(
				{
					acknowledgeSeed: true,
					mode: 'seed',
				},
				options || {}
			);

			return fetch(
				`${
					DBVCVisualEditorBootstrap.restBase
				}/session/${ encodeURIComponent(
					sessionId
				) }/collection-seed/${ encodeURIComponent( token ) }`,
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': DBVCVisualEditorBootstrap.nonce,
					},
					body: JSON.stringify( payload ),
				}
			).then( async ( response ) => {
				const data = await response.json().catch( function () {
					return null;
				} );

				if ( response.ok ) {
					return data;
				}

				throw new Error(
					( data && data.message ) ||
						`Visual Editor collection seed request failed (${ response.status }).`
				);
			} );
		},

		mediaManager: {
			latest( query ) {
				const params = mediaManagerQuery( query, false );
				const suffix = params.toString()
					? `?${ params.toString() }`
					: '';

				return mediaManagerRequest( `/scans/latest${ suffix }` );
			},

			start() {
				return mediaManagerRequest( '/scans', { method: 'POST' } );
			},

			// R2-H Slice 2c: the durable, cross-user Media Index list (instant open,
			// read-time per-user eligibility filtered). Same search/entity/field/sort
			// surface as the scan list, plus offset paging.
			index( query ) {
				const params = mediaManagerQuery( query, false );
				const value = query && typeof query === 'object' ? query : {};
				if ( Number.isInteger( value.offset ) && value.offset > 0 ) {
					params.set( 'offset', String( value.offset ) );
				}
				const suffix = params.toString()
					? `?${ params.toString() }`
					: '';

				return mediaManagerRequest( `/index${ suffix }` );
			},

			// R2-H Slice 2c: expand one index entry. The opaque entity ref resolves to a
			// detached, per-entity snapshot server-side; the response carries that
			// snapshot's scan/group identity so the existing assign/replace routes drive
			// mutation unchanged.
			indexExpand( entityRef ) {
				if (
					typeof entityRef !== 'string' ||
					! /^vemx_[a-f0-9]{24}$/.test( entityRef )
				) {
					return Promise.reject(
						mediaManagerError(
							'The media index entry is unavailable.',
							'media_index_ref_invalid',
							0,
							null
						)
					);
				}

				return mediaManagerRequest( '/index/expand', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { entityRef } ),
				} );
			},

			list( scan, query ) {
				let identity;

				try {
					identity = mediaManagerIdentity( scan );
				} catch ( error ) {
					return Promise.reject( error );
				}

				const params = mediaManagerQuery( query, true );
				params.set( 'generation', identity.generation );
				params.set(
					'expectedRevision',
					String( identity.expectedRevision )
				);

				return mediaManagerRequest(
					`/scans/${ encodeURIComponent(
						identity.scanRef
					) }?${ params.toString() }`
				);
			},

			group( scan, groupRef ) {
				let identity;

				try {
					identity = mediaManagerIdentity( scan );
				} catch ( error ) {
					return Promise.reject( error );
				}

				if (
					typeof groupRef !== 'string' ||
					! /^vemg_[a-f0-9]{20}$/.test( groupRef )
				) {
					return Promise.reject(
						mediaManagerError(
							'The Media Manager finding group is unavailable.',
							'media_manager_group_unavailable',
							0,
							null
						)
					);
				}

				const params = new URLSearchParams();
				params.set( 'generation', identity.generation );
				params.set(
					'expectedRevision',
					String( identity.expectedRevision )
				);

				return mediaManagerRequest(
					`/scans/${ encodeURIComponent(
						identity.scanRef
					) }/groups/${ encodeURIComponent(
						groupRef
					) }?${ params.toString() }`
				);
			},

			descriptor( scan, groupRef, findingRef ) {
				let identity;

				try {
					identity = mediaManagerIdentity( scan );
				} catch ( error ) {
					return Promise.reject( error );
				}

				if (
					typeof groupRef !== 'string' ||
					! /^vemg_[a-f0-9]{20}$/.test( groupRef )
				) {
					return Promise.reject(
						mediaManagerError(
							'The Media Manager finding group is unavailable.',
							'media_manager_group_unavailable',
							0,
							null
						)
					);
				}

				if (
					typeof findingRef !== 'string' ||
					! /^vemf_[a-f0-9]{20}$/.test( findingRef )
				) {
					return Promise.reject(
						mediaManagerError(
							'The Media Manager finding is unavailable.',
							'media_manager_finding_unavailable',
							0,
							null
						)
					);
				}

				return mediaManagerRequest(
					`/scans/${ encodeURIComponent(
						identity.scanRef
					) }/groups/${ encodeURIComponent(
						groupRef
					) }/findings/${ encodeURIComponent(
						findingRef
					) }/descriptor`,
					{
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( {
							generation: identity.generation,
							expectedRevision: identity.expectedRevision,
						} ),
					}
				);
			},

			assign( scan, groupRef, findingRef, value ) {
				let identity;

				try {
					identity = mediaManagerIdentity( scan );
				} catch ( error ) {
					return Promise.reject( error );
				}

				if (
					typeof groupRef !== 'string' ||
					! /^vemg_[a-f0-9]{20}$/.test( groupRef )
				) {
					return Promise.reject(
						mediaManagerError(
							'The Media Manager finding group is unavailable.',
							'media_manager_group_unavailable',
							0,
							null
						)
					);
				}

				if (
					typeof findingRef !== 'string' ||
					! /^vemf_[a-f0-9]{20}$/.test( findingRef )
				) {
					return Promise.reject(
						mediaManagerError(
							'The Media Manager finding is unavailable.',
							'media_manager_finding_unavailable',
							0,
							null
						)
					);
				}

				const body = {
					generation: identity.generation,
					expectedRevision: identity.expectedRevision,
				};
				const selection =
					value && typeof value === 'object' ? value : {};
				if (
					Number.isInteger( selection.attachmentId ) &&
					selection.attachmentId > 0
				) {
					body.attachmentId = selection.attachmentId;
				}
				if ( Array.isArray( selection.attachmentIds ) ) {
					body.attachmentIds = selection.attachmentIds
						.map( function ( id ) {
							return Number( id ) || 0;
						} )
						.filter( function ( id ) {
							return Number.isInteger( id ) && id > 0;
						} );
				}

				return mediaManagerRequest(
					`/scans/${ encodeURIComponent(
						identity.scanRef
					) }/groups/${ encodeURIComponent(
						groupRef
					) }/findings/${ encodeURIComponent(
						findingRef
					) }/assignment`,
					{
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( body ),
					}
				);
			},

			replace( scan, groupRef, findingRef, expectedValueRef, value ) {
				let identity;

				try {
					identity = mediaManagerIdentity( scan );
				} catch ( error ) {
					return Promise.reject( error );
				}

				if (
					typeof groupRef !== 'string' ||
					! /^vemg_[a-f0-9]{20}$/.test( groupRef )
				) {
					return Promise.reject(
						mediaManagerError(
							'The Media Manager finding group is unavailable.',
							'media_manager_group_unavailable',
							0,
							null
						)
					);
				}

				if (
					typeof findingRef !== 'string' ||
					! /^vemf_[a-f0-9]{20}$/.test( findingRef )
				) {
					return Promise.reject(
						mediaManagerError(
							'The Media Manager finding is unavailable.',
							'media_manager_finding_unavailable',
							0,
							null
						)
					);
				}

				if (
					typeof expectedValueRef !== 'string' ||
					! /^vemv_[a-f0-9]{24}$/.test( expectedValueRef )
				) {
					return Promise.reject(
						mediaManagerError(
							'The current media value could not be confirmed. Refresh the scan before replacing it.',
							'media_manager_value_ref_invalid',
							0,
							null
						)
					);
				}

				const body = {
					generation: identity.generation,
					expectedRevision: identity.expectedRevision,
					expectedValueRef,
				};
				const selection =
					value && typeof value === 'object' ? value : {};
				if (
					Number.isInteger( selection.attachmentId ) &&
					selection.attachmentId > 0
				) {
					body.attachmentId = selection.attachmentId;
				}
				if ( Array.isArray( selection.attachmentIds ) ) {
					body.attachmentIds = selection.attachmentIds
						.map( function ( id ) {
							return Number( id ) || 0;
						} )
						.filter( function ( id ) {
							return Number.isInteger( id ) && id > 0;
						} );
				}

				return mediaManagerRequest(
					`/scans/${ encodeURIComponent(
						identity.scanRef
					) }/groups/${ encodeURIComponent(
						groupRef
					) }/findings/${ encodeURIComponent(
						findingRef
					) }/replacement`,
					{
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( body ),
					}
				);
			},

			next( scan ) {
				return mediaManagerAction( 'next', scan );
			},

			retry( scan ) {
				return mediaManagerAction( 'retry', scan );
			},

			cancel( scan ) {
				return mediaManagerAction( 'cancel', scan );
			},
		},
	};

	// R5.later-perf: wrap the Visual Editor / BCC api surface with
	// `dbvc.ve.api.<method>` User Timing spans when `?dbvc_ve_perf=1`.
	// The `mediaManager` sub-object is preserved by reference — its
	// methods are NOT wrapped (out of scope for this audit; media-manager
	// gets its own audit slice). Wrap-after-define keeps the method
	// definitions above readable + gives us a single-decision-point for
	// the profiling scope.
	if ( isPerformanceProfilerEnabled() ) {
		const originalApi = window.DBVCVisualEditorApi;
		const spannedApi = {};
		Object.keys( originalApi ).forEach( function ( key ) {
			const value = originalApi[ key ];
			if ( key === 'mediaManager' ) {
				spannedApi[ key ] = value;
				return;
			}
			if ( typeof value === 'function' ) {
				spannedApi[ key ] = function () {
					const args = arguments;
					const self = this;
					return measurePerf( 'api.' + key, function () {
						return value.apply( self, args );
					} );
				};
			} else {
				spannedApi[ key ] = value;
			}
		} );
		window.DBVCVisualEditorApi = spannedApi;
	}
} )();
