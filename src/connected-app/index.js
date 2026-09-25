/**
 * Connected Environments admin page — slice A1: shell, role chips, Overview
 * stat cards, explicit runners, Settings (gates + enrollment).
 *
 * Every value on screen comes from the admin REST routes that wrap the CLI
 * inspectors; nothing runs on load except the overview read. Runs are
 * explicit buttons and their summaries are announced in one live region.
 */
import {
	Fragment,
	createRoot,
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const CONFIG = window.DBVC_CONNECTED_APP || {};
const NAMESPACE = CONFIG.namespace || 'dbvc/v1';

// Relative paths ('connected/status') are qualified with the plugin namespace; the
// root is the site REST root so both pretty and ?rest_route= permalinks resolve.
apiFetch.use( ( options, next ) => {
	if (
		typeof options.path === 'string' &&
		! options.path.startsWith( '/' )
	) {
		return next( {
			...options,
			path: `/${ NAMESPACE }/${ options.path }`,
		} );
	}
	return next( options );
} );
if ( CONFIG.root ) {
	apiFetch.use( apiFetch.createRootURLMiddleware( CONFIG.root ) );
}
if ( CONFIG.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( CONFIG.nonce ) );
}

const TONES = {
	positive: [
		'synchronized',
		'clean',
		'current',
		'applied',
		'sealed',
		'received',
		'consumed',
		'enabled',
		'present',
		'enrolled',
		'available',
		'ready_gate',
		'acked',
		'released',
		'resolved',
		'published',
		'succeeded',
		'completed',
	],
	directional: [
		'outgoing',
		'incoming',
		'ready',
		'requested',
		'open',
		'selected',
		'provisional',
		'approved',
		'running',
		'pending',
		'preparing',
	],
	neutral: [
		'converged',
		'noop',
		'baseline_required',
		'observed',
		'classified',
		'disconnected',
		'withdrawn',
		'detached',
		'version_differs',
	],
	caution: [
		'local_drift',
		'behind_version',
		'ahead_version',
		'channel_mismatch',
		'needs_rebase_review',
		'stale',
		'partial',
		'mismatch',
		'held',
		'expiring',
		'migration_required',
		'paused',
	],
	negative: [
		'conflict',
		'override_changed',
		'failed',
		'compensated',
		'revoked',
		'unresolved',
		'cancelled',
		'emergency_disabled',
	],
	uncertain: [
		'unknown',
		'offline',
		'unsupported',
		'not_observed',
		'incomplete',
		'unavailable',
		'uninitialized',
		'disabled',
	],
};

function toneFor( state ) {
	const key = String( state || '' ).replace( /[\s-]+/g, '_' );
	for ( const tone of Object.keys( TONES ) ) {
		if ( TONES[ tone ].includes( key ) ) {
			return tone;
		}
	}
	return 'neutral';
}

function Badge( { state, children } ) {
	return (
		<span className={ `dbvc-badge dbvc-badge--${ toneFor( state ) }` }>
			{ children || String( state || '' ).replace( /_/g, ' ' ) }
		</span>
	);
}

function relative( iso ) {
	if ( ! iso ) {
		return __( 'never', 'dbvc' );
	}
	const then = new Date(
		iso.includes( 'T' ) ? iso : iso.replace( ' ', 'T' ) + 'Z'
	).getTime();
	if ( Number.isNaN( then ) ) {
		return iso;
	}
	const seconds = Math.round( ( Date.now() - then ) / 1000 );
	if ( seconds < 60 ) {
		return __( 'just now', 'dbvc' );
	}
	if ( seconds < 3600 ) {
		return sprintf(
			/* translators: placeholders are counts, identifiers or relative times shown in the Connected Environments page */
			__( '%d min ago', 'dbvc' ),
			Math.round( seconds / 60 )
		);
	}
	if ( seconds < 86400 ) {
		return sprintf(
			/* translators: placeholders are counts, identifiers or relative times shown in the Connected Environments page */
			__( '%d h ago', 'dbvc' ),
			Math.round( seconds / 3600 )
		);
	}
	return sprintf(
		/* translators: placeholders are counts, identifiers or relative times shown in the Connected Environments page */
		__( '%d days ago', 'dbvc' ),
		Math.round( seconds / 86400 )
	);
}

function errorMessage( error ) {
	if ( ! error ) {
		return '';
	}
	if ( error.message ) {
		return error.code
			? `${ error.message } (${ error.code })`
			: error.message;
	}
	return String( error );
}

function schedulerText( scheduler ) {
	if ( scheduler.wp_cron_disabled ) {
		return __( 'WP-Cron disabled — external runner required', 'dbvc' );
	}
	if ( scheduler.next_run ) {
		return sprintf(
			/* translators: %s: relative time of the next scheduled run */
			__( 'next run %s', 'dbvc' ),
			relative( scheduler.next_run )
		);
	}
	return __( 'nothing scheduled', 'dbvc' );
}

/**
 * Modal used for content that must be read once (invitation token). Focus moves
 * to the panel on open and returns to the opener on close; Escape closes.
 *
 * @param {Object}   props
 * @param {string}   props.title
 * @param {Function} props.onClose
 * @param {*}        props.children
 */
function Modal( { title, onClose, children } ) {
	const panelRef = useRef( null );
	useEffect( () => {
		const panel = panelRef.current;
		const doc = panel ? panel.ownerDocument : null;
		const opener = doc ? doc.activeElement : null;
		panel?.focus();
		const onKey = ( event ) => {
			if ( event.key === 'Escape' ) {
				onClose();
			}
		};
		doc?.addEventListener( 'keydown', onKey );
		return () => {
			doc?.removeEventListener( 'keydown', onKey );
			if ( opener && typeof opener.focus === 'function' ) {
				opener.focus();
			}
		};
	}, [ onClose ] );
	return (
		<div className="dbvc-ce-modal" role="presentation">
			<div
				className="dbvc-ce-modal__panel"
				role="dialog"
				aria-modal="true"
				aria-labelledby="dbvc-ce-modal-title"
				tabIndex={ -1 }
				ref={ panelRef }
			>
				<h2 id="dbvc-ce-modal-title">{ title }</h2>
				{ children }
			</div>
		</div>
	);
}

/**
 * `enrolled-20260920-939229…` → `enrolled · 9392…` (full value in the title attribute).
 *
 * @param {string} epoch
 * @return {string} Short display form.
 */
function epochShort( epoch ) {
	const value = String( epoch || '' );
	const match = value.match( /^([a-z]+)-\d{8}-([0-9a-f]+)$/ );
	if ( match ) {
		return `${ match[ 1 ] } · ${ match[ 2 ].slice( 0, 6 ) }`;
	}
	return value.length > 18 ? `${ value.slice( 0, 18 ) }…` : value;
}

function absoluteTime( mysql ) {
	if ( ! mysql ) {
		return '—';
	}
	const date = new Date(
		mysql.includes( 'T' ) ? mysql : mysql.replace( ' ', 'T' ) + 'Z'
	);
	return Number.isNaN( date.getTime() ) ? mysql : date.toLocaleString();
}

function untilText( mysql ) {
	const then = new Date( mysql.replace( ' ', 'T' ) + 'Z' ).getTime();
	if ( Number.isNaN( then ) ) {
		return mysql;
	}
	const seconds = Math.round( ( then - Date.now() ) / 1000 );
	if ( seconds <= 0 ) {
		return __( 'expired', 'dbvc' );
	}
	if ( seconds < 3600 ) {
		return sprintf(
			/* translators: %d: minutes until the invitation expires */
			__( 'in %d min', 'dbvc' ),
			Math.max( 1, Math.round( seconds / 60 ) )
		);
	}
	return sprintf(
		/* translators: %d: hours until the invitation expires */
		__( 'in %d h', 'dbvc' ),
		Math.round( seconds / 3600 )
	);
}

/**
 * Hub Environments: registry table with hold / release / revoke, the invitation
 * form (token shown once in a modal, never stored client-side) and the
 * invitations list.
 *
 * @param {Object}   props
 * @param {Object}   props.hub       Hub status report (freshness window).
 * @param {Function} props.notify
 * @param {Function} props.onChanged Reload the overview after a write.
 * @param {Function} props.onCompare Open Compare with this environment as the source.
 */
function Environments( { hub, notify, onChanged, onCompare } ) {
	const [ environments, setEnvironments ] = useState( null );
	const [ invitations, setInvitations ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ busy, setBusy ] = useState( '' );
	const [ pending, setPending ] = useState( null ); // { id, action, reason }
	const [ invite, setInvite ] = useState( {
		client: '',
		label: '',
		environment: '',
		ttl: 3600,
	} );
	const [ issued, setIssued ] = useState( null );
	const [ copied, setCopied ] = useState( false );
	const freshness = Number( hub?.freshness_seconds || 86400 );

	const load = useCallback( async () => {
		setLoading( true );
		try {
			const [ envs, invs ] = await Promise.all( [
				apiFetch( { path: 'agency/environments?limit=200' } ),
				apiFetch( { path: 'agency/invitations?limit=50' } ),
			] );
			setEnvironments( envs.environments || [] );
			setInvitations( invs.invitations || [] );
		} catch ( err ) {
			notify( 'error', errorMessage( err ) );
		}
		setLoading( false );
	}, [ notify ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const act = async ( action, id, extra = {} ) => {
		setBusy( `${ action }:${ id }` );
		try {
			const result = await apiFetch( {
				path: `agency/${ action }`,
				method: 'POST',
				data: { environment: id, ...extra },
			} );
			notify(
				'success',
				sprintf(
					/* translators: 1: environment id, 2: resulting status */
					__( '%1$s is now %2$s.', 'dbvc' ),
					result.environment_id || id,
					result.status || action
				)
			);
			setPending( null );
			await load();
			onChanged();
		} catch ( err ) {
			notify( 'error', errorMessage( err ) );
		}
		setBusy( '' );
	};

	const createInvitation = async ( event ) => {
		event.preventDefault();
		if ( busy === 'invite' ) {
			return;
		}
		setBusy( 'invite' );
		try {
			const result = await apiFetch( {
				path: 'agency/invite',
				method: 'POST',
				data: {
					client: invite.client.trim(),
					label: invite.label.trim(),
					environment: invite.environment.trim(),
					ttl: Number( invite.ttl ) || 3600,
				},
			} );
			setIssued( result );
			setCopied( false );
			setInvite( { ...invite, label: '', environment: '' } );
			await load();
		} catch ( err ) {
			notify( 'error', errorMessage( err ) );
		}
		setBusy( '' );
	};

	const closeIssued = useCallback( () => setIssued( null ), [] );

	const copyCommand = async () => {
		const command = `wp dbvc connected enroll --hub=${ issued.hub_url } --token=${ issued.token }`;
		try {
			await window.navigator.clipboard.writeText( command );
			setCopied( true );
		} catch ( err ) {
			notify(
				'error',
				__(
					'Copy failed; select the command and copy it manually.',
					'dbvc'
				)
			);
		}
	};

	const fresh = ( env ) => {
		if ( ! env.last_contact_at ) {
			return null;
		}
		const then = new Date(
			env.last_contact_at.replace( ' ', 'T' ) + 'Z'
		).getTime();
		return Date.now() - then <= freshness * 1000;
	};

	const rowActions = ( env ) => {
		const key = ( action ) => `${ action }:${ env.environment_id }`;
		if ( pending && pending.id === env.environment_id ) {
			if ( pending.action === 'hold' ) {
				return (
					<div className="dbvc-ce-rowconfirm">
						<label
							className="screen-reader-text"
							htmlFor={ `dbvc-ce-hold-${ env.environment_id }` }
						>
							{ __( 'Hold reason', 'dbvc' ) }
						</label>
						<input
							id={ `dbvc-ce-hold-${ env.environment_id }` }
							type="text"
							maxLength={ 100 }
							placeholder={ __( 'reason (optional)', 'dbvc' ) }
							value={ pending.reason }
							onChange={ ( e ) =>
								setPending( {
									...pending,
									reason: e.target.value,
								} )
							}
						/>
						<button
							type="button"
							className="btn btn--small btn--primary"
							disabled={ busy === key( 'hold' ) }
							onClick={ () =>
								act( 'hold', env.environment_id, {
									reason: pending.reason,
								} )
							}
						>
							{ __( 'Hold', 'dbvc' ) }
						</button>
						<button
							type="button"
							className="btn btn--small btn--ghost"
							onClick={ () => setPending( null ) }
						>
							{ __( 'Cancel', 'dbvc' ) }
						</button>
					</div>
				);
			}
			return (
				<div
					className="dbvc-ce-rowconfirm"
					role="alertdialog"
					aria-label={ __( 'Confirm revoke', 'dbvc' ) }
				>
					<span>
						{ __(
							'Revoke credential? The site must re-enroll.',
							'dbvc'
						) }
					</span>
					<button
						type="button"
						className="btn btn--small btn--danger"
						disabled={ busy === key( 'revoke' ) }
						onClick={ () => act( 'revoke', env.environment_id ) }
					>
						{ __( 'Revoke', 'dbvc' ) }
					</button>
					<button
						type="button"
						className="btn btn--small btn--ghost"
						onClick={ () => setPending( null ) }
					>
						{ __( 'Cancel', 'dbvc' ) }
					</button>
				</div>
			);
		}
		if ( env.status === 'revoked' ) {
			return (
				<span className="subtle">
					{ __( 'must re-enroll', 'dbvc' ) }
				</span>
			);
		}
		return (
			<>
				{ env.status === 'held' ? (
					<button
						type="button"
						className="btn btn--small"
						disabled={ busy === key( 'release' ) }
						onClick={ () => act( 'release', env.environment_id ) }
					>
						{ __( 'Release hold', 'dbvc' ) }
					</button>
				) : (
					<button
						type="button"
						className="btn btn--small btn--ghost"
						onClick={ () =>
							setPending( {
								id: env.environment_id,
								action: 'hold',
								reason: '',
							} )
						}
					>
						{ __( 'Hold', 'dbvc' ) }
					</button>
				) }
				<button
					type="button"
					className="btn btn--small btn--danger"
					onClick={ () =>
						setPending( {
							id: env.environment_id,
							action: 'revoke',
						} )
					}
				>
					{ __( 'Revoke', 'dbvc' ) }
				</button>
				<button
					type="button"
					className="btn btn--small btn--ghost"
					onClick={ () => onCompare( env.environment_id ) }
				>
					{ __( 'Compare…', 'dbvc' ) }
				</button>
			</>
		);
	};

	return (
		<div className="dbvc-ce-grid dbvc-ce-grid--wide">
			<div className="dbvc-tools-panel">
				<div className="dbvc-ce-panel__head">
					<h2>{ __( 'Environments', 'dbvc' ) }</h2>
					<span className="dbvc-ce-asof">
						{ __(
							'last contact = last authenticated request from the site',
							'dbvc'
						) }
					</span>
				</div>
				{ loading && environments === null && (
					<p className="dbvc-ce-loading">
						{ __( 'Loading…', 'dbvc' ) }
					</p>
				) }
				{ environments && environments.length === 0 && (
					<p className="dbvc-ce-empty">
						{ __(
							'No environments enrolled yet. Create an invitation and run the enroll command on the site.',
							'dbvc'
						) }
					</p>
				) }
				{ environments && environments.length > 0 && (
					<table className="widefat striped">
						<thead>
							<tr>
								<th>{ __( 'Environment', 'dbvc' ) }</th>
								<th>{ __( 'Client', 'dbvc' ) }</th>
								<th>{ __( 'Status', 'dbvc' ) }</th>
								<th>{ __( 'Epoch', 'dbvc' ) }</th>
								<th>{ __( 'Last contact', 'dbvc' ) }</th>
								<th className="num">
									{ __( 'Received', 'dbvc' ) }
								</th>
								<th>
									<span className="screen-reader-text">
										{ __( 'Actions', 'dbvc' ) }
									</span>
								</th>
							</tr>
						</thead>
						<tbody>
							{ environments.map( ( env ) => {
								const isFresh = fresh( env );
								return (
									<tr key={ env.environment_id }>
										<td>
											<span className="obj">
												{ env.label ||
													env.environment_id }
											</span>
											<code>{ env.environment_id }</code>
											{ env.enrolled_site_url && (
												<span
													className="subtle"
													style={ {
														display: 'block',
													} }
												>
													{ env.enrolled_site_url }
												</span>
											) }
										</td>
										<td>{ env.client_id }</td>
										<td>
											<Badge state={ env.status } />{ ' ' }
											{ env.hold_reason && (
												<span className="subtle">
													{ env.hold_reason }
												</span>
											) }
										</td>
										<td>
											<code
												className="dbvc-ce-epoch"
												title={ env.current_epoch }
											>
												{ epochShort(
													env.current_epoch
												) }
											</code>
										</td>
										<td>
											<span
												title={ absoluteTime(
													env.last_contact_at
												) }
											>
												{ relative(
													env.last_contact_at
												) }
											</span>{ ' ' }
											{ isFresh === true && (
												<span className="subtle">
													{ __( 'fresh', 'dbvc' ) }
												</span>
											) }
											{ isFresh === false && (
												<Badge state="stale" />
											) }
										</td>
										<td className="num">
											{ Number(
												env.received_events || 0
											).toLocaleString() }
										</td>
										<td className="dbvc-ce-actions">
											{ rowActions( env ) }
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
				) }
			</div>
			<div>
				<div className="dbvc-tools-panel">
					<div className="dbvc-ce-panel__head">
						<h2>{ __( 'Invite an environment', 'dbvc' ) }</h2>
					</div>
					<p className="dbvc-ce-empty" style={ { paddingTop: 0 } }>
						{ __(
							'Creates a single-use, expiring invitation. The token is shown once, right here; the hub keeps only its hash.',
							'dbvc'
						) }
					</p>
					<form
						className="dbvc-ce-inv-form"
						onSubmit={ createInvitation }
					>
						<div className="dbvc-ce-field">
							<label htmlFor="dbvc-ce-inv-client">
								{ __( 'Client id', 'dbvc' ) }
							</label>
							<input
								id="dbvc-ce-inv-client"
								type="text"
								required
								pattern="[A-Za-z0-9._:-]{1,64}"
								value={ invite.client }
								onChange={ ( e ) =>
									setInvite( {
										...invite,
										client: e.target.value,
									} )
								}
							/>
							<p className="description">
								{ __(
									'Bounded ASCII identifier, e.g. client-a.',
									'dbvc'
								) }
							</p>
						</div>
						<div className="dbvc-ce-field">
							<label htmlFor="dbvc-ce-inv-label">
								{ __( 'Label', 'dbvc' ) }
							</label>
							<input
								id="dbvc-ce-inv-label"
								type="text"
								maxLength={ 120 }
								placeholder={ __( 'Client A staging', 'dbvc' ) }
								value={ invite.label }
								onChange={ ( e ) =>
									setInvite( {
										...invite,
										label: e.target.value,
									} )
								}
							/>
						</div>
						<div className="dbvc-ce-field">
							<label htmlFor="dbvc-ce-inv-env">
								{ __( 'Fixed environment id', 'dbvc' ) }
							</label>
							<input
								id="dbvc-ce-inv-env"
								type="text"
								pattern="[A-Za-z0-9._:-]{1,64}"
								placeholder={ __( 'optional', 'dbvc' ) }
								value={ invite.environment }
								onChange={ ( e ) =>
									setInvite( {
										...invite,
										environment: e.target.value,
									} )
								}
							/>
						</div>
						<div className="dbvc-ce-field">
							<label htmlFor="dbvc-ce-inv-ttl">
								{ __( 'Valid for (seconds)', 'dbvc' ) }
							</label>
							<input
								id="dbvc-ce-inv-ttl"
								type="number"
								min={ 60 }
								max={ 604800 }
								value={ invite.ttl }
								onChange={ ( e ) =>
									setInvite( {
										...invite,
										ttl: e.target.value,
									} )
								}
							/>
						</div>
						<button
							type="submit"
							className="btn btn--primary"
							aria-busy={ busy === 'invite' }
						>
							{ __( 'Create invitation', 'dbvc' ) }
						</button>
					</form>
				</div>
				<div className="dbvc-tools-panel" style={ { marginTop: 12 } }>
					<div className="dbvc-ce-panel__head">
						<h2>{ __( 'Invitations', 'dbvc' ) }</h2>
						<span className="dbvc-ce-asof">
							{ __( 'tokens are never listed', 'dbvc' ) }
						</span>
					</div>
					{ invitations.length === 0 ? (
						<p className="dbvc-ce-empty">
							{ __( 'No invitations yet.', 'dbvc' ) }
						</p>
					) : (
						<table className="widefat striped">
							<thead>
								<tr>
									<th>{ __( 'Id', 'dbvc' ) }</th>
									<th>{ __( 'Client', 'dbvc' ) }</th>
									<th>{ __( 'State', 'dbvc' ) }</th>
									<th>{ __( 'Expires', 'dbvc' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ invitations.map( ( inv ) => (
									<tr key={ inv.invitation_id }>
										<td>{ inv.invitation_id }</td>
										<td>
											{ inv.client_id }
											{ inv.environment_label && (
												<span className="subtle">
													{ ' ' }
													· { inv.environment_label }
												</span>
											) }
											{ inv.environment_id && (
												<code>
													{ ' ' }
													{ inv.environment_id }
												</code>
											) }
										</td>
										<td>
											<Badge state={ inv.state } />
											{ inv.consumed_environment_id && (
												<span className="subtle">
													{ ' ' }
													{
														inv.consumed_environment_id
													}
												</span>
											) }
										</td>
										<td
											title={ absoluteTime(
												inv.expires_at
											) }
										>
											{ inv.state === 'open'
												? untilText( inv.expires_at )
												: absoluteTime(
														inv.expires_at
												  ) }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
				</div>
			</div>
			{ issued && (
				<Modal
					title={ __(
						'Invitation created — copy the token now',
						'dbvc'
					) }
					onClose={ closeIssued }
				>
					<p>
						{ sprintf(
							/* translators: 1: invitation id, 2: client id, 3: expiry time */
							__(
								'Invitation #%1$d for %2$s. It expires %3$s and is shown only here: closing this dialog discards it.',
								'dbvc'
							),
							issued.invitation_id,
							issued.client_id,
							absoluteTime( issued.expires_at )
						) }
					</p>
					<p style={ { margin: '8px 0 0' } }>
						<strong>
							{ __( 'Run on the site to enroll:', 'dbvc' ) }
						</strong>
					</p>
					<code className="dbvc-ce-secret">
						{ `wp dbvc connected enroll --hub=${ issued.hub_url } --token=${ issued.token }` }
					</code>
					<p className="dbvc-ce-empty" style={ { padding: 0 } }>
						{ __(
							"Or paste the hub URL and token into the site's Connected Environments → Settings → Enrollment form.",
							'dbvc'
						) }
					</p>
					<div className="dbvc-ce-modal__foot">
						<button
							type="button"
							className="btn"
							onClick={ copyCommand }
						>
							{ copied
								? __( 'Copied', 'dbvc' )
								: __( 'Copy command', 'dbvc' ) }
						</button>
						<button
							type="button"
							className="btn btn--primary"
							onClick={ closeIssued }
						>
							{ __( 'Done', 'dbvc' ) }
						</button>
					</div>
				</Modal>
			) }
		</div>
	);
}

const COMPARE_STATES = [
	'synchronized',
	'outgoing',
	'incoming',
	'converged',
	'conflict',
	'baseline_required',
	'unknown',
];

const DOMAINS = [ 'bricks.global_class', 'bricks.variable', 'wp.service' ];
// Per-object profiles the connector reports for each domain (DomainRegistry / ServicePostObserver).
const DOMAIN_PROFILES = {
	'bricks.global_class': 'bricks-global-class-v1',
	'bricks.variable': 'bricks-variable-v1',
	'wp.service': 'wp-service-v1',
};

function hashCell( value ) {
	if ( ! value ) {
		return <span className="subtle">—</span>;
	}
	return (
		<code className="dbvc-ce-hash">{ String( value ).slice( 0, 8 ) }…</code>
	);
}

/**
 * Right-anchored object drawer: identity, both sides' projections, baselines
 * and recent events for one instance UID. No backdrop; Escape closes and focus
 * returns to the opener.
 *
 * @param {Object}   props
 * @param {Object}   props.object  { domain, instance_uid, profile, pairing, source, target, row }
 * @param {Function} props.onClose
 * @param {Function} props.notify
 */
function ObjectDrawer( { object, onClose, notify } ) {
	const panelRef = useRef( null );
	const [ data, setData ] = useState( null );
	const { domain, instance_uid: uid, source, target, row } = object;

	useEffect( () => {
		const panel = panelRef.current;
		const doc = panel ? panel.ownerDocument : null;
		const opener = doc ? doc.activeElement : null;
		panel?.focus();
		const onKey = ( event ) => {
			if ( event.key === 'Escape' ) {
				onClose();
			}
		};
		doc?.addEventListener( 'keydown', onKey );
		return () => {
			doc?.removeEventListener( 'keydown', onKey );
			if ( opener && typeof opener.focus === 'function' ) {
				opener.focus();
			}
		};
	}, [ onClose ] );

	useEffect( () => {
		let cancelled = false;
		( async () => {
			try {
				const q = `&instance=${ encodeURIComponent( uid ) }`;
				const [
					sourceProjections,
					targetProjections,
					baselines,
					sourceEvents,
					targetEvents,
				] = await Promise.all( [
					apiFetch( {
						path: `agency/projections?environment=${ encodeURIComponent(
							source
						) }${ q }`,
					} ),
					apiFetch( {
						path: `agency/projections?environment=${ encodeURIComponent(
							target
						) }${ q }`,
					} ),
					apiFetch( { path: `agency/baselines?limit=50${ q }` } ),
					apiFetch( {
						path: `agency/events?environment=${ encodeURIComponent(
							source
						) }&limit=500${ q }`,
					} ),
					apiFetch( {
						path: `agency/events?environment=${ encodeURIComponent(
							target
						) }&limit=500${ q }`,
					} ),
				] );
				if ( ! cancelled ) {
					setData( {
						projections: [
							...sourceProjections.projections,
							...targetProjections.projections,
						].filter( ( p ) => p.domain === domain ),
						baselines: baselines.baselines.filter(
							( b ) => b.domain === domain
						),
						events: [
							...sourceEvents.events,
							...targetEvents.events,
						]
							.filter( ( e ) => e.domain === domain )
							.sort( ( a, b ) =>
								b.received_at > a.received_at ? 1 : -1
							)
							.slice( 0, 12 ),
					} );
				}
			} catch ( err ) {
				notify( 'error', errorMessage( err ) );
			}
		} )();
		return () => {
			cancelled = true;
		};
	}, [ domain, uid, source, target, notify ] );

	return (
		<aside
			className="dbvc-ce dbvc-ce-drawer"
			aria-labelledby="dbvc-ce-drawer-title"
			tabIndex={ -1 }
			ref={ panelRef }
		>
			<div className="dbvc-ce-drawer__head">
				<div>
					<h2 id="dbvc-ce-drawer-title">{ uid }</h2>
					<code>
						{ domain } · { row.profile }
					</code>
				</div>
				<button
					type="button"
					className="dbvc-ce-close"
					aria-label={ __( 'Close', 'dbvc' ) }
					onClick={ onClose }
				>
					×
				</button>
			</div>
			<div className="dbvc-ce-drawer__body">
				<h3>{ __( 'Identity', 'dbvc' ) }</h3>
				<dl className="dbvc-ce-kv">
					<dt>{ __( 'Pair', 'dbvc' ) }</dt>
					<dd>
						<code>{ source }</code> → <code>{ target }</code>
					</dd>
					<dt>{ __( 'Pairing', 'dbvc' ) }</dt>
					<dd>
						{ row.pairing }
						{ row.pairing === 'link' && row.target_instance_uid && (
							<>
								{ ' ' }
								→ <code>{ row.target_instance_uid }</code>
							</>
						) }
					</dd>
					<dt>{ __( 'State', 'dbvc' ) }</dt>
					<dd>
						<Badge state={ row.state } />{ ' ' }
						{ row.reasons && (
							<span className="subtle">{ row.reasons }</span>
						) }
					</dd>
					<dt>{ __( 'Name', 'dbvc' ) }</dt>
					<dd className="subtle">
						{ __(
							'not reported (the hub receives hashes and identities only)',
							'dbvc'
						) }
					</dd>
				</dl>
				<h3>{ __( 'Now', 'dbvc' ) }</h3>
				{ ! data && (
					<p className="dbvc-ce-loading">
						{ __( 'Loading…', 'dbvc' ) }
					</p>
				) }
				{ data && data.projections.length === 0 && (
					<p className="dbvc-ce-empty">
						{ __( 'No projection on either side.', 'dbvc' ) }
					</p>
				) }
				{ data &&
					data.projections.map( ( p ) => (
						<dl
							className="dbvc-ce-kv"
							key={ `${ p.environment_id }-${ p.profile }-${ p.instance_uid }` }
							style={ { marginBottom: 8 } }
						>
							<dt>
								<code>{ p.environment_id }</code>
							</dt>
							<dd>
								{ p.profile } · { hashCell( p.hash ) }
							</dd>
							<dt>{ __( 'Exists · complete', 'dbvc' ) }</dt>
							<dd>
								{ p.exists } · { p.complete }
							</dd>
							<dt>{ __( 'Observed', 'dbvc' ) }</dt>
							<dd>
								{ sprintf(
									/* translators: %d: observation sequence number */
									__( 'sequence %d', 'dbvc' ),
									p.observed_sequence
								) }{ ' ' }
								·{ ' ' }
								<span title={ absoluteTime( p.observed_at ) }>
									{ relative( p.observed_at ) }
								</span>
							</dd>
						</dl>
					) ) }
				<h3>{ __( 'Baselines', 'dbvc' ) }</h3>
				{ data && data.baselines.length === 0 && (
					<p className="dbvc-ce-empty">
						{ __( 'None confirmed.', 'dbvc' ) }
					</p>
				) }
				{ data && data.baselines.length > 0 && (
					<dl className="dbvc-ce-kv">
						{ data.baselines.map( ( b ) => (
							<Fragment key={ b.baseline_id }>
								<dt>
									<code>{ b.source }</code> →{ ' ' }
									<code>{ b.target }</code>
								</dt>
								<dd>
									{ hashCell( b.baseline_hash ) } ·{ ' ' }
									{ b.profile } ·{ ' ' }
									<span
										title={ absoluteTime( b.confirmed_at ) }
									>
										{ relative( b.confirmed_at ) }
									</span>
									{ b.note && (
										<span className="subtle">
											{ ' ' }
											· { b.note }
										</span>
									) }
								</dd>
							</Fragment>
						) ) }
					</dl>
				) }
				<h3>{ __( 'Recent events', 'dbvc' ) }</h3>
				{ data && data.events.length === 0 && (
					<p className="dbvc-ce-empty">
						{ __( 'No events received for this object.', 'dbvc' ) }
					</p>
				) }
				{ data && data.events.length > 0 && (
					<dl className="dbvc-ce-kv">
						{ data.events.map( ( e ) => (
							<Fragment
								key={ `${ e.environment_id }-${ e.installation_epoch }-${ e.event_id }` }
							>
								<dt>
									<code>{ e.environment_id }</code> #
									{ e.sequence }
								</dt>
								<dd>
									{ e.profile } · { hashCell( e.hash ) } ·{ ' ' }
									{ e.exists === 'no'
										? __( 'absent', 'dbvc' )
										: __( 'present', 'dbvc' ) }{ ' ' }
									· { e.origin || '—' } ·{ ' ' }
									<span
										title={ absoluteTime( e.received_at ) }
									>
										{ relative( e.received_at ) }
									</span>{ ' ' }
									·{ ' ' }
									<span className="subtle">
										{ e.routing_state }
									</span>
								</dd>
							</Fragment>
						) ) }
					</dl>
				) }
			</div>
			<div className="dbvc-ce-drawer__foot">
				<button
					type="button"
					className="btn btn--small btn--ghost"
					onClick={ onClose }
				>
					{ __( 'Close', 'dbvc' ) }
				</button>
			</div>
		</aside>
	);
}

/**
 * Hub Compare: pair picker, coverage banner, state chips as filters, rows with
 * baseline / link actions and the object drawer.
 *
 * @param {Object}   props
 * @param {Array}    props.environments     Registry rows (for the pickers).
 * @param {Object}   props.preset           { source } chosen from the Environments table, if any.
 * @param {Function} props.notify
 * @param {Function} props.onChanged
 * @param {Function} props.onCreatedRelease Open Releases with the new release selected.
 */
function Compare( {
	environments,
	preset,
	notify,
	onChanged,
	onCreatedRelease,
} ) {
	const enabled = useMemo(
		() => ( environments || [] ).filter( ( e ) => e.status !== 'revoked' ),
		[ environments ]
	);
	const [ source, setSource ] = useState( preset?.source || '' );
	const [ target, setTarget ] = useState( '' );
	const [ domain, setDomain ] = useState( '' );
	const [ result, setResult ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ filter, setFilter ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );
	const [ linking, setLinking ] = useState( null ); // { key, target_uid }
	const [ drawer, setDrawer ] = useState( null );
	const [ selection, setSelection ] = useState( {} ); // key → { domain, instance_uid }
	const [ releaseNote, setReleaseNote ] = useState( '' );
	const [ creating, setCreating ] = useState( false );

	const targets = useMemo( () => {
		const src = enabled.find( ( e ) => e.environment_id === source );
		return enabled.filter(
			( e ) =>
				e.environment_id !== source &&
				( ! src || e.client_id === src.client_id )
		);
	}, [ enabled, source ] );

	useEffect( () => {
		if (
			targets.length &&
			! targets.some( ( t ) => t.environment_id === target )
		) {
			setTarget( targets[ 0 ].environment_id );
		}
	}, [ targets, target ] );

	const compare = useCallback( async () => {
		if ( ! source || ! target ) {
			return;
		}
		setLoading( true );
		try {
			const data = await apiFetch( {
				path: `agency/compare?source=${ encodeURIComponent(
					source
				) }&target=${ encodeURIComponent( target ) }${
					domain ? `&domain=${ encodeURIComponent( domain ) }` : ''
				}`,
			} );
			setResult( data );
		} catch ( err ) {
			notify( 'error', errorMessage( err ) );
		}
		setSelection( {} );
		setLoading( false );
	}, [ source, target, domain, notify ] );

	const closeDrawer = useCallback( () => setDrawer( null ), [] );

	const rows = useMemo( () => {
		if ( ! result ) {
			return [];
		}
		return filter
			? result.rows.filter( ( r ) => r.state === filter )
			: result.rows;
	}, [ result, filter ] );

	const createRelease = async () => {
		const items = Object.values( selection )
			.map( ( o ) => `${ o.domain }:${ o.instance_uid }` )
			.join( ',' );
		if ( ! items ) {
			return;
		}
		setCreating( true );
		try {
			const res = await apiFetch( {
				path: 'agency/release-create',
				method: 'POST',
				data: { source, items, note: releaseNote.trim() },
			} );
			notify(
				'success',
				sprintf(
					/* translators: %s: release uid */
					__( 'Release %s created.', 'dbvc' ),
					res.release?.release_uid || ''
				)
			);
			setSelection( {} );
			setReleaseNote( '' );
			if ( res.release?.release_uid ) {
				onCreatedRelease( res.release.release_uid );
			}
		} catch ( err ) {
			notify( 'error', errorMessage( err ) );
		}
		setCreating( false );
	};

	const act = async ( key, path, data, success ) => {
		setBusy( key );
		try {
			const res = await apiFetch( { path, method: 'POST', data } );
			notify( 'success', success( res ) );
			setLinking( null );
			await compare();
			onChanged();
		} catch ( err ) {
			notify( 'error', errorMessage( err ) );
		}
		setBusy( '' );
	};

	const confirmBaseline = ( row, acceptAbsent ) =>
		act(
			`baseline:${ row.source_instance_uid }`,
			'agency/baseline-confirm',
			{
				source,
				target,
				domain: row.domain,
				instance: row.source_instance_uid,
				'accept-absent': acceptAbsent,
			},
			( res ) =>
				sprintf(
					/* translators: 1: confirmed count, 2: skipped count */
					__( 'Baseline: %1$d confirmed · %2$d skipped.', 'dbvc' ),
					res.confirmed,
					res.skipped
				)
		);

	const canConfirm = ( row ) =>
		row.complete === 'yes' &&
		row.fresh === 'yes' &&
		( row.state === 'converged' ||
			( row.state === 'baseline_required' &&
				row.source_hash &&
				row.source_hash === row.target_hash ) );
	const canAcceptAbsent = ( row ) =>
		row.complete === 'yes' &&
		row.fresh === 'yes' &&
		row.state === 'baseline_required' &&
		( row.source_exists === 'no' ) !== ( row.target_exists === 'no' ) &&
		( row.source_exists === 'no' || row.target_exists === 'no' );

	const coverageText = ( side ) =>
		Object.entries( side?.coverage || {} )
			.map(
				( [ d, complete ] ) =>
					`${ d } ${
						complete
							? __( 'complete', 'dbvc' )
							: __( 'incomplete', 'dbvc' )
					}`
			)
			.join( ' · ' ) || __( 'no coverage reported', 'dbvc' );

	return (
		<div className="dbvc-tools-panel">
			<div className="dbvc-ce-panel__head">
				<h2>{ __( 'Compare', 'dbvc' ) }</h2>
				{ result && (
					<span className="dbvc-ce-asof">
						{ sprintf(
							/* translators: %s: comparison time */
							__( 'compared %s', 'dbvc' ),
							relative( result.compared_at )
						) }
					</span>
				) }
			</div>
			<div className="dbvc-ce-pair">
				<div className="dbvc-ce-field">
					<label htmlFor="dbvc-ce-cmp-source">
						{ __( 'Source', 'dbvc' ) }
					</label>
					<select
						id="dbvc-ce-cmp-source"
						value={ source }
						onChange={ ( e ) => setSource( e.target.value ) }
					>
						<option value="">{ __( '— choose —', 'dbvc' ) }</option>
						{ enabled.map( ( e ) => (
							<option
								key={ e.environment_id }
								value={ e.environment_id }
							>
								{ e.environment_id }
								{ e.label ? ` · ${ e.label }` : '' } (
								{ e.client_id })
							</option>
						) ) }
					</select>
				</div>
				<div className="dbvc-ce-field">
					<label htmlFor="dbvc-ce-cmp-target">
						{ __( 'Target', 'dbvc' ) }
					</label>
					<select
						id="dbvc-ce-cmp-target"
						value={ target }
						onChange={ ( e ) => setTarget( e.target.value ) }
						disabled={ ! source }
					>
						{ targets.length === 0 && (
							<option value="">
								{ __(
									'no other environment for this client',
									'dbvc'
								) }
							</option>
						) }
						{ targets.map( ( e ) => (
							<option
								key={ e.environment_id }
								value={ e.environment_id }
							>
								{ e.environment_id }
								{ e.label ? ` · ${ e.label }` : '' }
							</option>
						) ) }
					</select>
				</div>
				<div className="dbvc-ce-field">
					<label htmlFor="dbvc-ce-cmp-domain">
						{ __( 'Domain', 'dbvc' ) }
					</label>
					<select
						id="dbvc-ce-cmp-domain"
						value={ domain }
						onChange={ ( e ) => setDomain( e.target.value ) }
					>
						<option value="">
							{ __( 'all domains', 'dbvc' ) }
						</option>
						{ DOMAINS.map( ( d ) => (
							<option key={ d } value={ d }>
								{ d }
							</option>
						) ) }
					</select>
				</div>
				<button
					type="button"
					className="btn btn--primary"
					disabled={ ! source || ! target || loading }
					onClick={ compare }
				>
					{ loading
						? __( 'Comparing…', 'dbvc' )
						: __( 'Compare', 'dbvc' ) }
				</button>
			</div>
			{ ! result && ! loading && (
				<p className="dbvc-ce-empty">
					{ __(
						'Pick a source and a target of the same client. Classification is hash-level and read-only; nothing here changes either site.',
						'dbvc'
					) }
				</p>
			) }
			{ result && (
				<>
					<div className="dbvc-ce-coverage">
						<span>
							<code>{ result.source.environment_id }</code>{ ' ' }
							{ result.source.fresh ? (
								<span>{ __( 'fresh', 'dbvc' ) }</span>
							) : (
								<Badge state="stale" />
							) }{ ' ' }
							· { coverageText( result.source ) }
						</span>
						<span>
							<code>{ result.target.environment_id }</code>{ ' ' }
							{ result.target.fresh ? (
								<span>{ __( 'fresh', 'dbvc' ) }</span>
							) : (
								<Badge state="stale" />
							) }{ ' ' }
							· { coverageText( result.target ) }
						</span>
						<span>
							{ sprintf(
								/* translators: 1: baseline count, 2: link count */
								__( '%1$d baselines · %2$d links', 'dbvc' ),
								result.baselines,
								result.links
							) }
						</span>
					</div>
					<div
						className="dbvc-ce-chips"
						role="group"
						aria-label={ __( 'Filter by state', 'dbvc' ) }
					>
						{ COMPARE_STATES.map( ( state ) => (
							<button
								key={ state }
								type="button"
								className={ `dbvc-status-badge${
									state === 'unknown' ? ' is-uncertain' : ''
								}` }
								aria-pressed={ filter === state }
								onClick={ () =>
									setFilter( filter === state ? '' : state )
								}
							>
								<span className="n">
									{ result.counts?.[ state ] ?? 0 }
								</span>{ ' ' }
								{ state.replace( /_/g, ' ' ) }
							</button>
						) ) }
					</div>
					{ rows.length === 0 ? (
						<p className="dbvc-ce-empty">
							{ __( 'No objects in this state.', 'dbvc' ) }
						</p>
					) : (
						<table className="widefat striped">
							<thead>
								<tr>
									<th>{ __( 'Object', 'dbvc' ) }</th>
									<th>{ __( 'Pairing', 'dbvc' ) }</th>
									<th>{ __( 'State', 'dbvc' ) }</th>
									<th>{ __( 'Source', 'dbvc' ) }</th>
									<th>{ __( 'Target', 'dbvc' ) }</th>
									<th>{ __( 'Baseline', 'dbvc' ) }</th>
									<th>
										<span className="screen-reader-text">
											{ __(
												'Select for release',
												'dbvc'
											) }
										</span>
									</th>
									<th>
										<span className="screen-reader-text">
											{ __( 'Actions', 'dbvc' ) }
										</span>
									</th>
								</tr>
							</thead>
							<tbody>
								{ rows.map( ( row ) => {
									const key = `${ row.domain }|${ row.profile }|${ row.source_instance_uid }`;
									const releasable =
										row.state === 'outgoing' &&
										row.complete === 'yes' &&
										row.fresh === 'yes';
									return (
										<tr key={ key }>
											<td>
												<span className="obj">
													{ row.source_instance_uid }
												</span>
												<code>
													{ row.domain } ·{ ' ' }
													{ row.profile }
												</code>
											</td>
											<td>
												{ row.pairing }
												{ row.pairing === 'link' &&
													row.target_instance_uid && (
														<span
															className="subtle"
															style={ {
																display:
																	'block',
															} }
														>
															→{ ' ' }
															{
																row.target_instance_uid
															}
														</span>
													) }
											</td>
											<td>
												<Badge state={ row.state } />
												{ row.reasons && (
													<span
														className="subtle"
														style={ {
															display: 'block',
														} }
													>
														{ row.reasons }
													</span>
												) }
												{ ( row.complete === 'no' ||
													row.fresh === 'no' ) && (
													<span
														className="subtle"
														style={ {
															display: 'block',
														} }
													>
														{ row.complete ===
															'no' &&
															__(
																'incomplete',
																'dbvc'
															) }
														{ row.complete ===
															'no' &&
															row.fresh ===
																'no' &&
															' · ' }
														{ row.fresh === 'no' &&
															__(
																'stale',
																'dbvc'
															) }
													</span>
												) }
											</td>
											<td>
												{ hashCell( row.source_hash ) }
												{ row.source_exists ===
													'no' && (
													<span className="subtle">
														{ ' ' }
														{ __(
															'absent',
															'dbvc'
														) }
													</span>
												) }
											</td>
											<td>
												{ hashCell( row.target_hash ) }
												{ row.target_exists ===
													'no' && (
													<span className="subtle">
														{ ' ' }
														{ __(
															'absent',
															'dbvc'
														) }
													</span>
												) }
												{ row.target_observed ===
													'no' && (
													<span className="subtle">
														{ ' ' }
														{ __(
															'unobserved',
															'dbvc'
														) }
													</span>
												) }
											</td>
											<td>
												{ hashCell(
													row.baseline_hash
												) }
											</td>
											<td>
												{ releasable ? (
													<input
														type="checkbox"
														aria-label={ sprintf(
															/* translators: %s: object instance UID */
															__(
																'Add %s to release',
																'dbvc'
															),
															row.source_instance_uid
														) }
														checked={
															selection[ key ] !==
															undefined
														}
														onChange={ ( e ) =>
															setSelection(
																( sel ) => {
																	const next =
																		{
																			...sel,
																		};
																	if (
																		e.target
																			.checked
																	) {
																		next[
																			key
																		] = {
																			domain: row.domain,
																			instance_uid:
																				row.source_instance_uid,
																		};
																	} else {
																		delete next[
																			key
																		];
																	}
																	return next;
																}
															)
														}
													/>
												) : (
													<span className="subtle">
														—
													</span>
												) }
											</td>
											<td className="dbvc-ce-actions">
												{ linking &&
												linking.key === key ? (
													<div className="dbvc-ce-rowconfirm">
														<label
															className="screen-reader-text"
															htmlFor={ `dbvc-ce-link-${ row.source_instance_uid }` }
														>
															{ __(
																'Target instance UID',
																'dbvc'
															) }
														</label>
														<input
															id={ `dbvc-ce-link-${ row.source_instance_uid }` }
															type="text"
															placeholder={ __(
																'target instance uid',
																'dbvc'
															) }
															value={
																linking.target_uid
															}
															onChange={ ( e ) =>
																setLinking( {
																	...linking,
																	target_uid:
																		e.target
																			.value,
																} )
															}
														/>
														<button
															type="button"
															className="btn btn--small btn--primary"
															disabled={
																busy ===
																	`link:${ key }` ||
																! linking.target_uid.trim()
															}
															onClick={ () =>
																act(
																	`link:${ key }`,
																	'agency/link-instance',
																	{
																		domain: row.domain,
																		source,
																		'source-instance':
																			row.source_instance_uid,
																		target,
																		'target-instance':
																			linking.target_uid.trim(),
																	},
																	() =>
																		__(
																			'Instances linked.',
																			'dbvc'
																		)
																)
															}
														>
															{ __(
																'Link',
																'dbvc'
															) }
														</button>
														<button
															type="button"
															className="btn btn--small btn--ghost"
															onClick={ () =>
																setLinking(
																	null
																)
															}
														>
															{ __(
																'Cancel',
																'dbvc'
															) }
														</button>
													</div>
												) : (
													<>
														<button
															type="button"
															className="btn btn--small btn--ghost"
															onClick={ () =>
																setDrawer( {
																	domain: row.domain,
																	instance_uid:
																		row.source_instance_uid,
																	profile:
																		row.profile,
																	source,
																	target,
																	row,
																} )
															}
														>
															{ __(
																'Details',
																'dbvc'
															) }
														</button>
														{ canConfirm( row ) && (
															<button
																type="button"
																className="btn btn--small"
																disabled={
																	busy ===
																	`baseline:${ row.source_instance_uid }`
																}
																onClick={ () =>
																	confirmBaseline(
																		row,
																		false
																	)
																}
															>
																{ __(
																	'Confirm baseline',
																	'dbvc'
																) }
															</button>
														) }
														{ canAcceptAbsent(
															row
														) && (
															<button
																type="button"
																className="btn btn--small"
																disabled={
																	busy ===
																	`baseline:${ row.source_instance_uid }`
																}
																onClick={ () =>
																	confirmBaseline(
																		row,
																		true
																	)
																}
															>
																{ __(
																	'Accept absent',
																	'dbvc'
																) }
															</button>
														) }
														{ row.pairing ===
															'uid' &&
															row.target_observed ===
																'no' && (
																<button
																	type="button"
																	className="btn btn--small btn--ghost"
																	onClick={ () =>
																		setLinking(
																			{
																				key,
																				target_uid:
																					'',
																			}
																		)
																	}
																>
																	{ __(
																		'Link instance…',
																		'dbvc'
																	) }
																</button>
															) }
													</>
												) }
											</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>
					) }
					<p className="dbvc-ce-empty" style={ { paddingBottom: 0 } }>
						{ result.note }
					</p>
					{ Object.keys( selection ).length > 0 && (
						<div
							className="dbvc-ce-composer"
							role="region"
							aria-label={ __( 'Release composer', 'dbvc' ) }
						>
							<div>
								<strong>
									{ sprintf(
										/* translators: %d: number of selected objects */
										_n(
											'%d object selected',
											'%d objects selected',
											Object.keys( selection ).length,
											'dbvc'
										),
										Object.keys( selection ).length
									) }
								</strong>{ ' ' }
								<span className="subtle">
									{ sprintf(
										/* translators: %s: source environment id */
										__(
											'from %s · outgoing, complete and fresh',
											'dbvc'
										),
										source
									) }
								</span>
							</div>
							<div className="dbvc-ce__actions">
								<label
									className="screen-reader-text"
									htmlFor="dbvc-ce-release-note"
								>
									{ __( 'Release note', 'dbvc' ) }
								</label>
								<input
									id="dbvc-ce-release-note"
									type="text"
									placeholder={ __( 'Release note', 'dbvc' ) }
									value={ releaseNote }
									onChange={ ( e ) =>
										setReleaseNote( e.target.value )
									}
								/>
								<button
									type="button"
									className="btn btn--primary"
									disabled={ creating }
									onClick={ createRelease }
								>
									{ __( 'Create release', 'dbvc' ) }
								</button>
							</div>
						</div>
					) }
				</>
			) }
			{ drawer && (
				<ObjectDrawer
					object={ drawer }
					onClose={ closeDrawer }
					notify={ notify }
				/>
			) }
		</div>
	);
}

const DRIFT_STATES = [
	'clean',
	'local_drift',
	'approved_override',
	'override_changed',
	'unknown',
];
const REVIEW_STATES = [ 'observed', 'classified', 'resolved' ];

/**
 * One-line inline form used for row actions that need a note (override
 * rationale, review resolution).
 *
 * @param {Object}   props
 * @param {string}   props.id
 * @param {string}   props.label       Screen-reader label for the input.
 * @param {string}   props.placeholder
 * @param {string}   props.action      Confirm button text.
 * @param {boolean}  props.busy
 * @param {boolean}  props.danger
 * @param {Function} props.onConfirm   Receives the trimmed note.
 * @param {Function} props.onCancel
 */
function NoteConfirm( {
	id,
	label,
	placeholder,
	action,
	busy,
	danger,
	onConfirm,
	onCancel,
} ) {
	const [ note, setNote ] = useState( '' );
	return (
		<div className="dbvc-ce-rowconfirm">
			<label className="screen-reader-text" htmlFor={ id }>
				{ label }
			</label>
			<input
				id={ id }
				type="text"
				maxLength={ 500 }
				placeholder={ placeholder }
				value={ note }
				onChange={ ( e ) => setNote( e.target.value ) }
			/>
			<button
				type="button"
				className={ `btn btn--small ${
					danger ? 'btn--danger' : 'btn--primary'
				}` }
				disabled={ busy || ! note.trim() }
				onClick={ () => onConfirm( note.trim() ) }
			>
				{ action }
			</button>
			<button
				type="button"
				className="btn btn--small btn--ghost"
				onClick={ onCancel }
			>
				{ __( 'Cancel', 'dbvc' ) }
			</button>
		</div>
	);
}

/**
 * Hub Framework: Definitions (publish / set desired), Status (the
 * framework-status report with adopt / override actions and a subscribe
 * form) and Reviews (classify / resolve). Reporting only; nothing here
 * writes to any environment.
 *
 * @param {Object}   props
 * @param {Array}    props.environments Registry rows (for pickers).
 * @param {Function} props.notify
 * @param {Function} props.onChanged
 */
function Framework( { environments, notify, onChanged } ) {
	const [ panel, setPanel ] = useState( 'status' );
	const [ definitions, setDefinitions ] = useState( null );
	const [ status, setStatus ] = useState( null );
	const [ reviews, setReviews ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ pending, setPending ] = useState( null ); // { key, action }
	const [ driftFilter, setDriftFilter ] = useState( '' );
	const [ reviewFilter, setReviewFilter ] = useState( '' );
	const enabled = useMemo(
		() => ( environments || [] ).filter( ( e ) => e.status !== 'revoked' ),
		[ environments ]
	);

	const [ publish, setPublish ] = useState( {
		definition: '',
		version: '',
		channel: 'stable',
		order: '',
		domain: 'bricks.global_class',
		profile: '',
		mode: 'hash',
		hash: '',
		fromEnvironment: '',
		fromInstance: '',
		fromVersion: '',
		desired: false,
		note: '',
	} );
	const [ subscribe, setSubscribe ] = useState( {
		environment: '',
		domain: 'bricks.global_class',
		instance: '',
		definition: '',
		adoptedVersion: '',
		channel: 'stable',
	} );

	const load = useCallback( async () => {
		try {
			const [ defs, st, rv ] = await Promise.all( [
				apiFetch( { path: 'agency/definitions?limit=200' } ),
				apiFetch( { path: 'agency/framework-status' } ),
				apiFetch( { path: 'agency/reviews?limit=200' } ),
			] );
			setDefinitions( defs.definitions || [] );
			setStatus( st );
			setReviews( rv );
		} catch ( err ) {
			notify( 'error', errorMessage( err ) );
		}
	}, [ notify ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const act = async ( key, path, data, success ) => {
		setBusy( key );
		try {
			const res = await apiFetch( { path, method: 'POST', data } );
			notify( 'success', success( res ) );
			setPending( null );
			await load();
			onChanged();
		} catch ( err ) {
			notify( 'error', errorMessage( err ) );
		}
		setBusy( '' );
	};

	const submitPublish = ( event ) => {
		event.preventDefault();
		const data = {
			definition: publish.definition.trim(),
			version: publish.version.trim(),
			channel: publish.channel.trim() || 'stable',
			domain: publish.domain,
			profile:
				publish.profile.trim() ||
				DOMAIN_PROFILES[ publish.domain ] ||
				'',
			desired: publish.desired,
			note: publish.note.trim(),
		};
		if ( publish.order !== '' ) {
			data.order = Number( publish.order );
		}
		if ( publish.mode === 'hash' ) {
			data.hash = publish.hash.trim();
		} else if ( publish.mode === 'environment' ) {
			data[ 'from-environment' ] = publish.fromEnvironment;
			data[ 'from-instance' ] = publish.fromInstance.trim();
		} else {
			data[ 'from-version' ] = publish.fromVersion.trim();
		}
		act( 'publish', 'agency/definition-publish', data, ( res ) =>
			sprintf(
				/* translators: 1: definition uid, 2: version */
				__( 'Published %1$s %2$s.', 'dbvc' ),
				res.definition_uid || data.definition,
				res.version || data.version
			)
		).then( () =>
			setPublish( {
				...publish,
				version: '',
				hash: '',
				fromInstance: '',
				fromVersion: '',
				note: '',
				desired: false,
			} )
		);
	};

	const submitSubscribe = ( event ) => {
		event.preventDefault();
		act(
			'subscribe',
			'agency/subscribe-framework',
			{
				environment: subscribe.environment,
				domain: subscribe.domain,
				instance: subscribe.instance.trim(),
				definition: subscribe.definition.trim(),
				'adopted-version': subscribe.adoptedVersion.trim(),
				channel: subscribe.channel.trim() || 'stable',
			},
			() => __( 'Framework subscription recorded.', 'dbvc' )
		).then( () =>
			setSubscribe( { ...subscribe, instance: '', adoptedVersion: '' } )
		);
	};

	const definitionGroups = useMemo( () => {
		const groups = {};
		( definitions || [] ).forEach( ( d ) => {
			const key = `${ d.agency_id }|${ d.definition_uid }`;
			groups[ key ] = groups[ key ] || {
				agency_id: d.agency_id,
				definition_uid: d.definition_uid,
				versions: [],
			};
			groups[ key ].versions.push( d );
		} );
		return Object.values( groups ).map( ( g ) => ( {
			...g,
			versions: g.versions.sort(
				( a, b ) =>
					a.channel.localeCompare( b.channel ) || a.order - b.order
			),
		} ) );
	}, [ definitions ] );

	const statusRows = useMemo( () => {
		const rows = status?.rows || [];
		return driftFilter
			? rows.filter( ( r ) => r.drift === driftFilter )
			: rows;
	}, [ status, driftFilter ] );

	const reviewRows = useMemo( () => {
		const rows = reviews?.reviews || [];
		return reviewFilter
			? rows.filter( ( r ) => r.state === reviewFilter )
			: rows;
	}, [ reviews, reviewFilter ] );

	const panels = [
		{ key: 'status', label: __( 'Status', 'dbvc' ) },
		{ key: 'definitions', label: __( 'Definitions', 'dbvc' ) },
		{ key: 'reviews', label: __( 'Reviews', 'dbvc' ) },
	];

	const statusActions = ( row ) => {
		const key = `${ row.subscription_id }`;
		const identity = {
			environment: row.environment_id,
			domain: row.domain,
			instance: row.instance_uid,
			definition: row.definition_uid,
		};
		if ( pending && pending.key === key && pending.action === 'override' ) {
			return (
				<NoteConfirm
					id={ `dbvc-ce-override-${ key }` }
					label={ __( 'Override rationale', 'dbvc' ) }
					placeholder={ __( 'rationale (required)', 'dbvc' ) }
					action={ __( 'Approve override', 'dbvc' ) }
					busy={ busy === `override:${ key }` }
					onConfirm={ ( rationale ) =>
						act(
							`override:${ key }`,
							'agency/override-approve',
							{ ...identity, rationale },
							( res ) =>
								sprintf(
									/* translators: %s: override state */
									__( 'Override recorded (%s).', 'dbvc' ),
									res.state || 'approved'
								)
						)
					}
					onCancel={ () => setPending( null ) }
				/>
			);
		}
		const hasOverride =
			row.override_state === 'approved' ||
			row.override_state === 'needs_rebase_review';
		return (
			<>
				{ row.desired_version &&
					row.desired_version !== row.adopted_version && (
						<button
							type="button"
							className="btn btn--small"
							disabled={ busy === `adopt:${ key }` }
							onClick={ () =>
								act(
									`adopt:${ key }`,
									'agency/adopt-version',
									{
										id: row.subscription_id,
										version: row.desired_version,
									},
									( res ) =>
										sprintf(
											/* translators: %s: adopted version */
											__( 'Adopted version %s.', 'dbvc' ),
											res.adopted_version
										)
								)
							}
						>
							{ sprintf(
								/* translators: %s: desired version */
								__( 'Adopt %s', 'dbvc' ),
								row.desired_version
							) }
						</button>
					) }
				{ ( row.drift === 'local_drift' ||
					row.drift === 'override_changed' ) && (
					<button
						type="button"
						className="btn btn--small btn--ghost"
						onClick={ () =>
							setPending( { key, action: 'override' } )
						}
					>
						{ __( 'Approve override…', 'dbvc' ) }
					</button>
				) }
				{ hasOverride && (
					<button
						type="button"
						className="btn btn--small btn--ghost"
						disabled={ busy === `detach:${ key }` }
						onClick={ () =>
							act(
								`detach:${ key }`,
								'agency/override-detach',
								identity,
								() => __( 'Override detached.', 'dbvc' )
							)
						}
					>
						{ __( 'Detach override', 'dbvc' ) }
					</button>
				) }
			</>
		);
	};

	const reviewActions = ( row ) => {
		const key = `${ row.review_item_id }`;
		if ( row.state === 'resolved' ) {
			return <span className="subtle">{ row.resolution }</span>;
		}
		if ( pending && pending.key === key && pending.action === 'resolve' ) {
			return (
				<NoteConfirm
					id={ `dbvc-ce-resolve-${ key }` }
					label={ __( 'Resolution note', 'dbvc' ) }
					placeholder={ __( 'note (required)', 'dbvc' ) }
					action={ __( 'Resolve', 'dbvc' ) }
					busy={ busy === `resolve:${ key }` }
					onConfirm={ ( note ) =>
						act(
							`resolve:${ key }`,
							'agency/review-resolve',
							{ id: row.review_item_id, note },
							() =>
								sprintf(
									/* translators: %d: review item id */
									__( 'Review item #%d resolved.', 'dbvc' ),
									row.review_item_id
								)
						)
					}
					onCancel={ () => setPending( null ) }
				/>
			);
		}
		return (
			<button
				type="button"
				className="btn btn--small btn--ghost"
				onClick={ () => setPending( { key, action: 'resolve' } ) }
			>
				{ __( 'Resolve…', 'dbvc' ) }
			</button>
		);
	};

	return (
		<div className="dbvc-tools-panel">
			<div className="dbvc-ce-panel__head">
				<h2>{ __( 'Framework', 'dbvc' ) }</h2>
				<span className="dbvc-ce-asof">
					{ __(
						'reporting only · version distance by studio-supplied order, never by label',
						'dbvc'
					) }
				</span>
			</div>
			<nav
				className="dbvc-section-nav"
				aria-label={ __( 'Framework panels', 'dbvc' ) }
				style={ { marginBottom: 12 } }
			>
				{ panels.map( ( p ) => (
					<button
						key={ p.key }
						type="button"
						aria-current={ panel === p.key ? 'page' : undefined }
						onClick={ () => setPanel( p.key ) }
					>
						{ p.label }
					</button>
				) ) }
			</nav>

			{ panel === 'status' && (
				<>
					{ ! status && (
						<p className="dbvc-ce-loading">
							{ __( 'Loading…', 'dbvc' ) }
						</p>
					) }
					{ status && (
						<>
							<div
								className="dbvc-ce-chips"
								role="group"
								aria-label={ __( 'Filter by drift', 'dbvc' ) }
							>
								{ DRIFT_STATES.map( ( state ) => (
									<button
										key={ state }
										type="button"
										className={ `dbvc-status-badge${
											state === 'unknown'
												? ' is-uncertain'
												: ''
										}` }
										aria-pressed={ driftFilter === state }
										onClick={ () =>
											setDriftFilter(
												driftFilter === state
													? ''
													: state
											)
										}
									>
										<span className="n">
											{ status.counts?.drift?.[ state ] ??
												0 }
										</span>{ ' ' }
										{ state.replace( /_/g, ' ' ) }
									</button>
								) ) }
								{ status.counts?.rebase_review > 0 && (
									<span className="dbvc-status-badge">
										<span className="n">
											{ status.counts.rebase_review }
										</span>{ ' ' }
										{ __( 'needs rebase review', 'dbvc' ) }
									</span>
								) }
							</div>
							{ statusRows.length === 0 ? (
								<p className="dbvc-ce-empty">
									{ ( status.rows || [] ).length === 0
										? __(
												'No enabled framework subscriptions yet. Subscribe an environment object to a definition below.',
												'dbvc'
										  )
										: __(
												'No rows in this drift state.',
												'dbvc'
										  ) }
								</p>
							) : (
								<table className="widefat striped">
									<thead>
										<tr>
											<th>
												{ __( 'Environment', 'dbvc' ) }
											</th>
											<th>
												{ __( 'Instance', 'dbvc' ) }
											</th>
											<th>
												{ __( 'Definition', 'dbvc' ) }
											</th>
											<th>{ __( 'Adopted', 'dbvc' ) }</th>
											<th>{ __( 'Desired', 'dbvc' ) }</th>
											<th>{ __( 'Drift', 'dbvc' ) }</th>
											<th>{ __( 'Version', 'dbvc' ) }</th>
											<th>
												{ __( 'Override', 'dbvc' ) }
											</th>
											<th>
												<span className="screen-reader-text">
													{ __( 'Actions', 'dbvc' ) }
												</span>
											</th>
										</tr>
									</thead>
									<tbody>
										{ statusRows.map( ( row ) => (
											<tr key={ row.subscription_id }>
												<td>
													<code>
														{ row.environment_id }
													</code>
												</td>
												<td>
													<span className="obj">
														{ row.instance_uid }
													</span>
													<code>
														{ row.domain } ·{ ' ' }
														{ hashCell(
															row.actual_hash
														) }
													</code>
												</td>
												<td>
													<code>
														{ row.definition_uid }
													</code>{ ' ' }
													<span className="subtle">
														{ row.channel }
													</span>
												</td>
												<td>
													{ row.adopted_version || (
														<span className="subtle">
															—
														</span>
													) }
												</td>
												<td>
													{ row.desired_version || (
														<span className="subtle">
															—
														</span>
													) }
												</td>
												<td>
													<Badge
														state={ row.drift }
													/>
													{ row.reasons && (
														<span
															className="subtle"
															style={ {
																display:
																	'block',
															} }
														>
															{ row.reasons }
														</span>
													) }
												</td>
												<td>
													<Badge
														state={ row.version }
													/>
												</td>
												<td>
													{ row.override_state ? (
														<Badge
															state={
																row.override_state
															}
														/>
													) : (
														<span className="subtle">
															{ __(
																'none',
																'dbvc'
															) }
														</span>
													) }
												</td>
												<td className="dbvc-ce-actions">
													{ statusActions( row ) }
												</td>
											</tr>
										) ) }
									</tbody>
								</table>
							) }
							<p
								className="dbvc-ce-empty"
								style={ { paddingBottom: 0 } }
							>
								{ status.note }
							</p>
						</>
					) }
					<div
						className="dbvc-tools-panel"
						style={ { marginTop: 12 } }
					>
						<div className="dbvc-ce-panel__head">
							<h2>
								{ __(
									'Subscribe an object to a definition',
									'dbvc'
								) }
							</h2>
						</div>
						<form
							className="dbvc-ce-pair"
							onSubmit={ submitSubscribe }
						>
							<div className="dbvc-ce-field">
								<label htmlFor="dbvc-ce-fw-env">
									{ __( 'Environment', 'dbvc' ) }
								</label>
								<select
									id="dbvc-ce-fw-env"
									required
									value={ subscribe.environment }
									onChange={ ( e ) =>
										setSubscribe( {
											...subscribe,
											environment: e.target.value,
										} )
									}
								>
									<option value="">
										{ __( '— choose —', 'dbvc' ) }
									</option>
									{ enabled.map( ( e ) => (
										<option
											key={ e.environment_id }
											value={ e.environment_id }
										>
											{ e.environment_id }
										</option>
									) ) }
								</select>
							</div>
							<div className="dbvc-ce-field">
								<label htmlFor="dbvc-ce-fw-domain">
									{ __( 'Domain', 'dbvc' ) }
								</label>
								<select
									id="dbvc-ce-fw-domain"
									value={ subscribe.domain }
									onChange={ ( e ) =>
										setSubscribe( {
											...subscribe,
											domain: e.target.value,
										} )
									}
								>
									{ DOMAINS.map( ( d ) => (
										<option key={ d } value={ d }>
											{ d }
										</option>
									) ) }
								</select>
							</div>
							<div className="dbvc-ce-field">
								<label htmlFor="dbvc-ce-fw-instance">
									{ __( 'Instance UID', 'dbvc' ) }
								</label>
								<input
									id="dbvc-ce-fw-instance"
									type="text"
									required
									value={ subscribe.instance }
									onChange={ ( e ) =>
										setSubscribe( {
											...subscribe,
											instance: e.target.value,
										} )
									}
								/>
							</div>
							<div className="dbvc-ce-field">
								<label htmlFor="dbvc-ce-fw-definition">
									{ __( 'Definition', 'dbvc' ) }
								</label>
								<input
									id="dbvc-ce-fw-definition"
									type="text"
									required
									pattern="[A-Za-z0-9._:-]{1,128}"
									value={ subscribe.definition }
									onChange={ ( e ) =>
										setSubscribe( {
											...subscribe,
											definition: e.target.value,
										} )
									}
								/>
							</div>
							<div className="dbvc-ce-field">
								<label htmlFor="dbvc-ce-fw-adopted">
									{ __( 'Adopted version', 'dbvc' ) }
								</label>
								<input
									id="dbvc-ce-fw-adopted"
									type="text"
									placeholder={ __( 'optional', 'dbvc' ) }
									value={ subscribe.adoptedVersion }
									onChange={ ( e ) =>
										setSubscribe( {
											...subscribe,
											adoptedVersion: e.target.value,
										} )
									}
								/>
							</div>
							<div className="dbvc-ce-field">
								<label htmlFor="dbvc-ce-fw-channel">
									{ __( 'Channel', 'dbvc' ) }
								</label>
								<input
									id="dbvc-ce-fw-channel"
									type="text"
									value={ subscribe.channel }
									onChange={ ( e ) =>
										setSubscribe( {
											...subscribe,
											channel: e.target.value,
										} )
									}
								/>
							</div>
							<button
								type="submit"
								className="btn btn--primary"
								aria-busy={ busy === 'subscribe' }
							>
								{ __( 'Subscribe', 'dbvc' ) }
							</button>
						</form>
					</div>
				</>
			) }

			{ panel === 'definitions' && (
				<div className="dbvc-ce-grid">
					<div>
						{ ! definitions && (
							<p className="dbvc-ce-loading">
								{ __( 'Loading…', 'dbvc' ) }
							</p>
						) }
						{ definitions && definitionGroups.length === 0 && (
							<p className="dbvc-ce-empty">
								{ __(
									'No definitions published yet.',
									'dbvc'
								) }
							</p>
						) }
						{ definitionGroups.map( ( group ) => (
							<div
								key={ `${ group.agency_id }|${ group.definition_uid }` }
								style={ { marginBottom: 16 } }
							>
								<h3
									style={ {
										margin: '0 0 6px',
										fontSize: 13,
									} }
								>
									<code>{ group.definition_uid }</code>{ ' ' }
									<span className="subtle">
										{ group.agency_id }
									</span>
								</h3>
								<table className="widefat striped">
									<thead>
										<tr>
											<th>{ __( 'Channel', 'dbvc' ) }</th>
											<th>{ __( 'Version', 'dbvc' ) }</th>
											<th className="num">
												{ __( 'Order', 'dbvc' ) }
											</th>
											<th>{ __( 'Hash', 'dbvc' ) }</th>
											<th>
												{ __(
													'Domain · profile',
													'dbvc'
												) }
											</th>
											<th>{ __( 'Source', 'dbvc' ) }</th>
											<th>
												{ __( 'Published', 'dbvc' ) }
											</th>
											<th>
												<span className="screen-reader-text">
													{ __( 'Actions', 'dbvc' ) }
												</span>
											</th>
										</tr>
									</thead>
									<tbody>
										{ group.versions.map( ( v ) => {
											const key = `${ group.definition_uid }|${ v.channel }|${ v.version }`;
											return (
												<tr key={ key }>
													<td>{ v.channel }</td>
													<td>
														<strong>
															{ v.version }
														</strong>{ ' ' }
														{ v.desired ===
															'yes' && (
															<Badge state="selected">
																{ __(
																	'desired',
																	'dbvc'
																) }
															</Badge>
														) }
													</td>
													<td className="num">
														{ v.order }
													</td>
													<td>
														{ hashCell( v.hash ) }
													</td>
													<td>
														<code>
															{ v.domain } ·{ ' ' }
															{ v.profile }
														</code>
													</td>
													<td>
														{ v.source ? (
															<code>
																{ v.source }
															</code>
														) : (
															<span className="subtle">
																{ __(
																	'hash',
																	'dbvc'
																) }
															</span>
														) }
													</td>
													<td>
														<span
															title={ absoluteTime(
																v.published_at
															) }
														>
															{ relative(
																v.published_at
															) }
														</span>
														{ v.note && (
															<span
																className="subtle"
																style={ {
																	display:
																		'block',
																} }
															>
																{ v.note }
															</span>
														) }
													</td>
													<td className="dbvc-ce-actions">
														{ v.desired !==
															'yes' && (
															<button
																type="button"
																className="btn btn--small"
																disabled={
																	busy ===
																	`desire:${ key }`
																}
																onClick={ () =>
																	act(
																		`desire:${ key }`,
																		'agency/definition-desire',
																		{
																			agency: group.agency_id,
																			definition:
																				group.definition_uid,
																			version:
																				v.version,
																		},
																		(
																			res
																		) =>
																			sprintf(
																				/* translators: 1: definition uid, 2: version, 3: rebase review count */
																				__(
																					'%1$s now desires %2$s (%3$d overrides need rebase review).',
																					'dbvc'
																				),
																				res.definition_uid,
																				res.version,
																				res.rebase_reviews ||
																					0
																			)
																	)
																}
															>
																{ __(
																	'Set desired',
																	'dbvc'
																) }
															</button>
														) }
													</td>
												</tr>
											);
										} ) }
									</tbody>
								</table>
							</div>
						) ) }
					</div>
					<div className="dbvc-tools-panel">
						<div className="dbvc-ce-panel__head">
							<h2>{ __( 'Publish a version', 'dbvc' ) }</h2>
						</div>
						<form
							className="dbvc-ce-inv-form"
							onSubmit={ submitPublish }
						>
							<div className="dbvc-ce-field">
								<label htmlFor="dbvc-ce-pub-definition">
									{ __( 'Definition', 'dbvc' ) }
								</label>
								<input
									id="dbvc-ce-pub-definition"
									type="text"
									required
									pattern="[A-Za-z0-9._:-]{1,128}"
									value={ publish.definition }
									onChange={ ( e ) =>
										setPublish( {
											...publish,
											definition: e.target.value,
										} )
									}
								/>
							</div>
							<div className="dbvc-ce-pair">
								<div
									className="dbvc-ce-field"
									style={ { minWidth: 0, flex: 1 } }
								>
									<label htmlFor="dbvc-ce-pub-version">
										{ __( 'Version', 'dbvc' ) }
									</label>
									<input
										id="dbvc-ce-pub-version"
										type="text"
										required
										value={ publish.version }
										onChange={ ( e ) =>
											setPublish( {
												...publish,
												version: e.target.value,
											} )
										}
									/>
								</div>
								<div
									className="dbvc-ce-field"
									style={ { minWidth: 0, flex: 1 } }
								>
									<label htmlFor="dbvc-ce-pub-channel">
										{ __( 'Channel', 'dbvc' ) }
									</label>
									<input
										id="dbvc-ce-pub-channel"
										type="text"
										value={ publish.channel }
										onChange={ ( e ) =>
											setPublish( {
												...publish,
												channel: e.target.value,
											} )
										}
									/>
								</div>
								<div
									className="dbvc-ce-field"
									style={ { minWidth: 0, width: 90 } }
								>
									<label htmlFor="dbvc-ce-pub-order">
										{ __( 'Order', 'dbvc' ) }
									</label>
									<input
										id="dbvc-ce-pub-order"
										type="number"
										min={ 1 }
										required
										value={ publish.order }
										onChange={ ( e ) =>
											setPublish( {
												...publish,
												order: e.target.value,
											} )
										}
									/>
								</div>
							</div>
							<div className="dbvc-ce-pair">
								<div
									className="dbvc-ce-field"
									style={ { minWidth: 0, flex: 1 } }
								>
									<label htmlFor="dbvc-ce-pub-domain">
										{ __( 'Domain', 'dbvc' ) }
									</label>
									<select
										id="dbvc-ce-pub-domain"
										value={ publish.domain }
										onChange={ ( e ) =>
											setPublish( {
												...publish,
												domain: e.target.value,
											} )
										}
									>
										{ DOMAINS.map( ( d ) => (
											<option key={ d } value={ d }>
												{ d }
											</option>
										) ) }
									</select>
								</div>
								<div
									className="dbvc-ce-field"
									style={ { minWidth: 0, flex: 1 } }
								>
									<label htmlFor="dbvc-ce-pub-profile">
										{ __( 'Profile', 'dbvc' ) }
									</label>
									<input
										id="dbvc-ce-pub-profile"
										type="text"
										placeholder={
											DOMAIN_PROFILES[ publish.domain ] ||
											''
										}
										value={ publish.profile }
										onChange={ ( e ) =>
											setPublish( {
												...publish,
												profile: e.target.value,
											} )
										}
									/>
								</div>
							</div>
							<div className="dbvc-ce-field">
								<label htmlFor="dbvc-ce-pub-mode">
									{ __( 'Body', 'dbvc' ) }
								</label>
								<select
									id="dbvc-ce-pub-mode"
									value={ publish.mode }
									onChange={ ( e ) =>
										setPublish( {
											...publish,
											mode: e.target.value,
										} )
									}
								>
									<option value="hash">
										{ __( 'Hash (64 hex)', 'dbvc' ) }
									</option>
									<option value="environment">
										{ __(
											'From an environment object',
											'dbvc'
										) }
									</option>
									<option value="version">
										{ __( 'Copy from a version', 'dbvc' ) }
									</option>
								</select>
							</div>
							{ publish.mode === 'hash' && (
								<div className="dbvc-ce-field">
									<label htmlFor="dbvc-ce-pub-hash">
										{ __( 'Hash', 'dbvc' ) }
									</label>
									<input
										id="dbvc-ce-pub-hash"
										type="text"
										required
										pattern="[a-f0-9]{64}"
										value={ publish.hash }
										onChange={ ( e ) =>
											setPublish( {
												...publish,
												hash: e.target.value,
											} )
										}
									/>
								</div>
							) }
							{ publish.mode === 'environment' && (
								<div className="dbvc-ce-pair">
									<div
										className="dbvc-ce-field"
										style={ { minWidth: 0, flex: 1 } }
									>
										<label htmlFor="dbvc-ce-pub-from-env">
											{ __( 'Environment', 'dbvc' ) }
										</label>
										<select
											id="dbvc-ce-pub-from-env"
											required
											value={ publish.fromEnvironment }
											onChange={ ( e ) =>
												setPublish( {
													...publish,
													fromEnvironment:
														e.target.value,
												} )
											}
										>
											<option value="">
												{ __( '— choose —', 'dbvc' ) }
											</option>
											{ enabled.map( ( e ) => (
												<option
													key={ e.environment_id }
													value={ e.environment_id }
												>
													{ e.environment_id }
												</option>
											) ) }
										</select>
									</div>
									<div
										className="dbvc-ce-field"
										style={ { minWidth: 0, flex: 2 } }
									>
										<label htmlFor="dbvc-ce-pub-from-instance">
											{ __( 'Instance UID', 'dbvc' ) }
										</label>
										<input
											id="dbvc-ce-pub-from-instance"
											type="text"
											required
											value={ publish.fromInstance }
											onChange={ ( e ) =>
												setPublish( {
													...publish,
													fromInstance:
														e.target.value,
												} )
											}
										/>
									</div>
								</div>
							) }
							{ publish.mode === 'version' && (
								<div className="dbvc-ce-field">
									<label htmlFor="dbvc-ce-pub-from-version">
										{ __(
											'Copy hash from version',
											'dbvc'
										) }
									</label>
									<input
										id="dbvc-ce-pub-from-version"
										type="text"
										required
										value={ publish.fromVersion }
										onChange={ ( e ) =>
											setPublish( {
												...publish,
												fromVersion: e.target.value,
											} )
										}
									/>
								</div>
							) }
							<label
								className="dbvc-ce-check"
								htmlFor="dbvc-ce-pub-desired"
							>
								<input
									id="dbvc-ce-pub-desired"
									type="checkbox"
									checked={ publish.desired }
									onChange={ ( e ) =>
										setPublish( {
											...publish,
											desired: e.target.checked,
										} )
									}
								/>
								<span>
									{ __(
										'Mark as the desired version for its channel',
										'dbvc'
									) }
								</span>
							</label>
							<div className="dbvc-ce-field">
								<label htmlFor="dbvc-ce-pub-note">
									{ __( 'Note', 'dbvc' ) }
								</label>
								<input
									id="dbvc-ce-pub-note"
									type="text"
									maxLength={ 500 }
									value={ publish.note }
									onChange={ ( e ) =>
										setPublish( {
											...publish,
											note: e.target.value,
										} )
									}
								/>
							</div>
							<button
								type="submit"
								className="btn btn--primary"
								aria-busy={ busy === 'publish' }
							>
								{ __( 'Publish', 'dbvc' ) }
							</button>
						</form>
					</div>
				</div>
			) }

			{ panel === 'reviews' && (
				<>
					<div
						className="dbvc-ce-pair"
						style={ { justifyContent: 'space-between' } }
					>
						<div
							className="dbvc-ce-chips"
							role="group"
							aria-label={ __( 'Filter by state', 'dbvc' ) }
							style={ { margin: 0 } }
						>
							{ REVIEW_STATES.map( ( state ) => (
								<button
									key={ state }
									type="button"
									className="dbvc-status-badge"
									aria-pressed={ reviewFilter === state }
									onClick={ () =>
										setReviewFilter(
											reviewFilter === state ? '' : state
										)
									}
								>
									<span className="n">
										{ reviews?.counts?.[ state ] ?? 0 }
									</span>{ ' ' }
									{ state }
								</button>
							) ) }
						</div>
						<button
							type="button"
							className="btn"
							disabled={ busy === 'classify' }
							onClick={ () =>
								act(
									'classify',
									'agency/review-classify',
									{},
									( res ) =>
										sprintf(
											/* translators: 1: examined count, 2: classified count, 3: resolved count */
											__(
												'Classified: %1$d examined · %2$d classified · %3$d resolved automatically.',
												'dbvc'
											),
											res.examined ?? 0,
											res.classified ?? 0,
											res.resolved ?? 0
										)
								)
							}
						>
							{ __( 'Classify now', 'dbvc' ) }
						</button>
					</div>
					{ ! reviews && (
						<p className="dbvc-ce-loading">
							{ __( 'Loading…', 'dbvc' ) }
						</p>
					) }
					{ reviews && reviewRows.length === 0 && (
						<p className="dbvc-ce-empty">
							{ ( reviews.reviews || [] ).length === 0
								? __(
										'No review items. Framework routing creates one per observed change on a subscribed object.',
										'dbvc'
								  )
								: __( 'No items in this state.', 'dbvc' ) }
						</p>
					) }
					{ reviews && reviewRows.length > 0 && (
						<table className="widefat striped">
							<thead>
								<tr>
									<th>{ __( 'Id', 'dbvc' ) }</th>
									<th>{ __( 'Environment', 'dbvc' ) }</th>
									<th>{ __( 'Instance', 'dbvc' ) }</th>
									<th>{ __( 'Definition', 'dbvc' ) }</th>
									<th>{ __( 'State', 'dbvc' ) }</th>
									<th>{ __( 'Drift · version', 'dbvc' ) }</th>
									<th>{ __( 'Seen', 'dbvc' ) }</th>
									<th>
										<span className="screen-reader-text">
											{ __( 'Actions', 'dbvc' ) }
										</span>
									</th>
								</tr>
							</thead>
							<tbody>
								{ reviewRows.map( ( row ) => (
									<tr key={ row.review_item_id }>
										<td>{ row.review_item_id }</td>
										<td>
											<code>{ row.environment_id }</code>
										</td>
										<td>
											<span className="obj">
												{ row.instance_uid }
											</span>
											<code>
												{ row.domain } · #
												{ row.event_sequence }
											</code>
										</td>
										<td>
											<code>{ row.definition_uid }</code>
										</td>
										<td>
											<Badge state={ row.state } />
											{ row.note && (
												<span
													className="subtle"
													style={ {
														display: 'block',
													} }
												>
													{ row.note }
												</span>
											) }
										</td>
										<td>
											{ row.drift ? (
												<Badge state={ row.drift } />
											) : (
												<span className="subtle">
													—
												</span>
											) }{ ' ' }
											{ row.version && (
												<Badge state={ row.version } />
											) }
										</td>
										<td>
											<span
												title={ absoluteTime(
													row.created_at
												) }
											>
												{ relative( row.created_at ) }
											</span>
										</td>
										<td className="dbvc-ce-actions">
											{ reviewActions( row ) }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
				</>
			) }
		</div>
	);
}

/**
 * Manifest payload state ("received" / "mismatch (reason)" / "requested (...)")
 * to a badge tone.
 *
 * @param {string} payload The item's payload_state string.
 * @return {string} Tone key.
 */
function payloadTone( payload ) {
	if ( payload.startsWith( 'received' ) ) {
		return 'received';
	}
	if ( payload.startsWith( 'mismatch' ) ) {
		return 'mismatch';
	}
	return 'requested';
}

const STEPPER = [
	{ key: 'manifest', label: __( 'Manifest', 'dbvc' ) },
	{ key: 'prepare', label: __( 'Prepare', 'dbvc' ) },
	{ key: 'approve', label: __( 'Approve', 'dbvc' ) },
	{ key: 'execute', label: __( 'Execute', 'dbvc' ) },
];

function countdown( iso ) {
	if ( ! iso ) {
		return '';
	}
	const then = new Date(
		iso.includes( 'T' ) ? iso : iso.replace( ' ', 'T' ) + 'Z'
	).getTime();
	if ( Number.isNaN( then ) ) {
		return iso;
	}
	const seconds = Math.round( ( then - Date.now() ) / 1000 );
	if ( seconds <= 0 ) {
		return __( 'expired', 'dbvc' );
	}
	if ( seconds < 3600 ) {
		return sprintf(
			/* translators: %d: minutes until expiry */
			__( 'expires in %d min', 'dbvc' ),
			Math.max( 1, Math.round( seconds / 60 ) )
		);
	}
	return sprintf(
		/* translators: %d: hours until expiry */
		__( 'expires in %d h', 'dbvc' ),
		Math.round( seconds / 3600 )
	);
}

/**
 * Expanded prepare-receipt detail for one item: identity, container +
 * fingerprint, the patch (changed paths), the dependency ledger and blockers.
 *
 * @param {Object} props
 * @param {Object} props.item One receipt item from the connector.
 */
function ReceiptItem( { item } ) {
	return (
		<div className="dbvc-ce-receipt-item">
			<div className="dbvc-ce-receipt-item__head">
				<Badge state={ item.outcome } />
				<code>
					{ item.domain } · { item.instance_uid }
				</code>
				<span className="subtle">{ item.operation }</span>
			</div>
			<dl className="dbvc-ce-kv">
				<dt>{ __( 'Identity', 'dbvc' ) }</dt>
				<dd>
					{ item.identity?.storage_key ? (
						<>
							<code>{ item.identity.storage_key }</code>{ ' ' }
							<span className="subtle">
								{ item.identity.source }
							</span>
						</>
					) : (
						<span className="subtle">
							{ item.identity?.source ||
								__( 'unresolved', 'dbvc' ) }
						</span>
					) }
				</dd>
				{ item.container && (
					<>
						<dt>{ __( 'Container', 'dbvc' ) }</dt>
						<dd>
							<code>{ item.container }</code>
							{ item.storage_fingerprint && (
								<span className="subtle">
									{ ' ' }
									· { hashCell( item.storage_fingerprint ) }
								</span>
							) }
						</dd>
					</>
				) }
				{ item.patch && (
					<>
						<dt>{ __( 'Patch', 'dbvc' ) }</dt>
						<dd>
							{ item.patch.strategy }
							{ Array.isArray( item.patch.changed_paths ) &&
								item.patch.changed_paths.length > 0 && (
									<span className="subtle">
										{ ' ' }
										·{ ' ' }
										{ item.patch.changed_paths
											.slice( 0, 8 )
											.map( ( p ) =>
												typeof p === 'string'
													? p
													: `${ p.path }${
															p.operation
																? ` (${ p.operation })`
																: ''
													  }`
											)
											.join( ', ' ) }
										{ item.patch.changed_paths.length > 8
											? '…'
											: '' }
									</span>
								) }
						</dd>
					</>
				) }
				{ Array.isArray( item.dependencies ) &&
					item.dependencies.length > 0 && (
						<>
							<dt>{ __( 'Dependencies', 'dbvc' ) }</dt>
							<dd>
								{ item.dependencies.map( ( dep, i ) => (
									<span
										key={ i }
										style={ { display: 'block' } }
									>
										<Badge state={ dep.status }>
											{ dep.status }
										</Badge>{ ' ' }
										<code>
											{ dep.kind }:{ dep.ref }
										</code>
										{ dep.detail && (
											<span className="subtle">
												{ ' ' }
												· { dep.detail }
											</span>
										) }
									</span>
								) ) }
							</dd>
						</>
					) }
				{ Array.isArray( item.blockers ) &&
					item.blockers.length > 0 && (
						<>
							<dt>{ __( 'Blockers', 'dbvc' ) }</dt>
							<dd>
								{ item.blockers.map( ( b, i ) => (
									<Badge key={ i } state="blocked">
										{ b }
									</Badge>
								) ) }
							</dd>
						</>
					) }
			</dl>
		</div>
	);
}

/**
 * Hub Releases: a list of releases and, for the selected one, the
 * manifest → prepare → approve → execute stepper. Reporting and orchestration
 * only; execution happens on the target's own poll behind its apply gate.
 *
 * @param {Object}   props
 * @param {Array}    props.environments Registry rows (for the prepare target picker).
 * @param {string}   props.preset       release_uid to open on mount, if any.
 * @param {Function} props.notify
 * @param {Function} props.onChanged
 * @param {Function} props.onRollout    Open Rollouts with this sealed release preset.
 */
function Releases( { environments, preset, notify, onChanged, onRollout } ) {
	const [ releases, setReleases ] = useState( null );
	const [ selected, setSelected ] = useState( preset || '' );
	const [ detail, setDetail ] = useState( null ); // { release, items }
	const [ preparations, setPreparations ] = useState( [] );
	const [ approvals, setApprovals ] = useState( [] );
	const [ step, setStep ] = useState( 'manifest' );
	const [ busy, setBusy ] = useState( '' );
	const [ expanded, setExpanded ] = useState( {} ); // operation_id → receipt|null
	const [ execExpanded, setExecExpanded ] = useState( {} ); // approval_uid → receipt|null
	const [ prepareTarget, setPrepareTarget ] = useState( '' );
	const [ confirm, setConfirm ] = useState( null ); // approval preview
	const [ rollbackOf, setRollbackOf ] = useState( null ); // approval to roll back
	const enabled = useMemo(
		() => ( environments || [] ).filter( ( e ) => e.status === 'enabled' ),
		[ environments ]
	);

	const loadList = useCallback( async () => {
		try {
			setReleases(
				( await apiFetch( { path: 'agency/releases?limit=200' } ) )
					.releases || []
			);
		} catch ( err ) {
			notify( 'error', errorMessage( err ) );
		}
	}, [ notify ] );

	const loadDetail = useCallback(
		async ( uid ) => {
			if ( ! uid ) {
				setDetail( null );
				setPreparations( [] );
				setApprovals( [] );
				return;
			}
			try {
				const [ rel, preps, apps ] = await Promise.all( [
					apiFetch( {
						path: `agency/releases?release=${ encodeURIComponent(
							uid
						) }`,
					} ),
					apiFetch( {
						path: `agency/preparations?release=${ encodeURIComponent(
							uid
						) }&limit=200`,
					} ),
					apiFetch( { path: 'agency/approvals?limit=200' } ),
				] );
				setDetail( rel );
				setPreparations( preps.preparations || [] );
				setApprovals(
					( apps.approvals || [] ).filter(
						( a ) => a.release_uid === uid
					)
				);
			} catch ( err ) {
				notify( 'error', errorMessage( err ) );
			}
		},
		[ notify ]
	);

	useEffect( () => {
		loadList();
	}, [ loadList ] );
	useEffect( () => {
		loadDetail( selected );
	}, [ selected, loadDetail ] );

	const refresh = async () => {
		await loadList();
		await loadDetail( selected );
		onChanged();
	};

	const withdraw = () =>
		run(
			`withdraw:${ selected }`,
			'agency/release-withdraw',
			{ release: selected },
			() => __( 'Release withdrawn.', 'dbvc' )
		);

	const requestPrepare = () => {
		if ( ! prepareTarget ) {
			return;
		}
		run(
			`prepare:${ prepareTarget }`,
			'agency/prepare-request',
			{ release: selected, target: prepareTarget },
			( res ) =>
				sprintf(
					/* translators: 1: target id, 2: operation id */
					__( 'Prepare requested on %1$s (%2$s).', 'dbvc' ),
					prepareTarget,
					res.operation_id || ''
				)
		);
	};

	const run = async ( key, path, data, success ) => {
		setBusy( key );
		try {
			const res = await apiFetch( { path, method: 'POST', data } );
			notify( 'success', success( res ) );
			setConfirm( null );
			setRollbackOf( null );
			await refresh();
		} catch ( err ) {
			notify( 'error', errorMessage( err ) );
		}
		setBusy( '' );
	};

	const toggleReceipt = async ( operationId ) => {
		if ( expanded[ operationId ] !== undefined ) {
			setExpanded( ( e ) => ( { ...e, [ operationId ]: undefined } ) );
			return;
		}
		try {
			const full = await apiFetch( {
				path: `agency/preparations?operation=${ encodeURIComponent(
					operationId
				) }`,
			} );
			setExpanded( ( e ) => ( { ...e, [ operationId ]: full } ) );
		} catch ( err ) {
			notify( 'error', errorMessage( err ) );
		}
	};

	const toggleExecution = async ( approvalUid ) => {
		if ( execExpanded[ approvalUid ] !== undefined ) {
			setExecExpanded( ( e ) => ( {
				...e,
				[ approvalUid ]: undefined,
			} ) );
			return;
		}
		try {
			const full = await apiFetch( {
				path: `agency/approvals?approval=${ encodeURIComponent(
					approvalUid
				) }`,
			} );
			setExecExpanded( ( e ) => ( { ...e, [ approvalUid ]: full } ) );
		} catch ( err ) {
			notify( 'error', errorMessage( err ) );
		}
	};

	const rolledBackOps = useMemo(
		() =>
			new Set(
				approvals
					.filter(
						( a ) => a.kind === 'rollback' && a.state !== 'revoked'
					)
					.map( ( a ) => a.rolls_back_operation_id )
			),
		[ approvals ]
	);
	const canRollBack = ( approval ) =>
		approval.kind !== 'rollback' &&
		approval.state === 'consumed' &&
		[ 'applied', 'partial', 'compensated' ].includes(
			approval.execution_outcome
		) &&
		! rolledBackOps.has( approval.operation_id );

	const approvalFor = ( operationId ) =>
		approvals.find( ( a ) => a.operation_id === operationId );

	if ( ! releases ) {
		return <p className="dbvc-ce-loading">{ __( 'Loading…', 'dbvc' ) }</p>;
	}

	return (
		<div className="dbvc-ce-grid dbvc-ce-grid--wide">
			<div className="dbvc-tools-panel">
				<div className="dbvc-ce-panel__head">
					<h2>{ __( 'Releases', 'dbvc' ) }</h2>
				</div>
				{ releases.length === 0 ? (
					<p className="dbvc-ce-empty">
						{ __(
							'No releases yet. Select outgoing objects in Compare and create one, or use wp dbvc agency release-create.',
							'dbvc'
						) }
					</p>
				) : (
					<table className="widefat striped">
						<thead>
							<tr>
								<th>{ __( 'Release', 'dbvc' ) }</th>
								<th>{ __( 'Source', 'dbvc' ) }</th>
								<th>{ __( 'State', 'dbvc' ) }</th>
								<th className="num">
									{ __( 'Items', 'dbvc' ) }
								</th>
								<th>{ __( 'Created', 'dbvc' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ releases.map( ( rel ) => (
								<tr
									key={ rel.release_uid }
									aria-selected={
										rel.release_uid === selected
									}
									className={
										rel.release_uid === selected
											? 'dbvc-ce-row--active'
											: ''
									}
								>
									<td>
										<button
											type="button"
											className="dbvc-ce-linkbtn"
											onClick={ () => {
												setSelected( rel.release_uid );
												setStep( 'manifest' );
											} }
										>
											{ rel.release_uid }
										</button>
										{ rel.note && (
											<span
												className="subtle"
												style={ { display: 'block' } }
											>
												{ rel.note }
											</span>
										) }
									</td>
									<td>
										<code>{ rel.source }</code>
									</td>
									<td>
										<Badge state={ rel.state } />
									</td>
									<td className="num">{ rel.items }</td>
									<td>
										<span
											title={ absoluteTime(
												rel.created_at
											) }
										>
											{ relative( rel.created_at ) }
										</span>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
			</div>

			<div className="dbvc-tools-panel">
				{ ! detail ? (
					<p className="dbvc-ce-empty">
						{ __(
							'Select a release to see its pipeline.',
							'dbvc'
						) }
					</p>
				) : (
					<>
						<div className="dbvc-ce-panel__head">
							<h2>
								<code>{ detail.release.release_uid }</code>
							</h2>
							<span className="dbvc-ce-asof">
								<Badge state={ detail.release.state } /> ·{ ' ' }
								{ detail.release.source } →{ ' ' }
								{ hashCell( detail.release.digest ) }
							</span>
						</div>
						<ol
							className="dbvc-ce-steps"
							aria-label={ __( 'Release pipeline', 'dbvc' ) }
						>
							{ STEPPER.map( ( s, i ) => (
								<li key={ s.key }>
									<button
										type="button"
										aria-current={
											step === s.key ? 'step' : undefined
										}
										className={
											step === s.key ? 'is-active' : ''
										}
										onClick={ () => setStep( s.key ) }
									>
										<span className="dbvc-ce-steps__n">
											{ i + 1 }
										</span>{ ' ' }
										{ s.label }
									</button>
								</li>
							) ) }
						</ol>

						{ step === 'manifest' && (
							<>
								<table className="widefat striped">
									<thead>
										<tr>
											<th>{ __( 'Object', 'dbvc' ) }</th>
											<th>
												{ __( 'Operation', 'dbvc' ) }
											</th>
											<th>{ __( 'After', 'dbvc' ) }</th>
											<th>{ __( 'Payload', 'dbvc' ) }</th>
										</tr>
									</thead>
									<tbody>
										{ ( detail.items || [] ).map(
											( item ) => (
												<tr
													key={ `${ item.domain }|${ item.instance_uid }` }
												>
													<td>
														<span className="obj">
															{
																item.instance_uid
															}
														</span>
														<code>
															{ item.domain } ·{ ' ' }
															{ item.profile }
														</code>
													</td>
													<td>
														<Badge
															state={
																item.operation ===
																'delete'
																	? 'unresolved'
																	: 'directional'
															}
														>
															{ item.operation }
														</Badge>
													</td>
													<td>
														{ hashCell(
															item.after_hash
														) }
													</td>
													<td>
														<Badge
															state={ payloadTone(
																item.payload
															) }
														>
															{ item.payload }
														</Badge>
													</td>
												</tr>
											)
										) }
									</tbody>
								</table>
								<p className="dbvc-ce-empty">
									{ detail.release.state === 'sealed'
										? sprintf(
												/* translators: %s: seal time */
												__(
													'Sealed %s — every payload was verified against the manifest.',
													'dbvc'
												),
												detail.release.sealed_at
													? relative(
															detail.release
																.sealed_at
													  )
													: ''
										  )
										: __(
												'Collecting payloads: the source connector supplies the canonical bodies on its own poll. Only a sealed release can be prepared.',
												'dbvc'
										  ) }
								</p>
								{ detail.release.state === 'sealed' &&
									onRollout && (
										<button
											type="button"
											className="btn btn--small"
											onClick={ () =>
												onRollout( selected )
											}
										>
											{ __( 'Roll out…', 'dbvc' ) }
										</button>
									) }
								{ detail.release.state !== 'withdrawn' && (
									<button
										type="button"
										className="btn btn--small btn--danger"
										disabled={
											busy === `withdraw:${ selected }`
										}
										onClick={ withdraw }
									>
										{ __( 'Withdraw release', 'dbvc' ) }
									</button>
								) }
							</>
						) }

						{ step === 'prepare' && (
							<>
								{ preparations.length === 0 && (
									<p className="dbvc-ce-empty">
										{ __(
											'No prepare requests yet.',
											'dbvc'
										) }
									</p>
								) }
								{ preparations.map( ( prep ) => {
									const receipt =
										expanded[ prep.operation_id ];
									return (
										<div
											key={ prep.operation_id }
											className="dbvc-ce-prep"
										>
											<div className="dbvc-ce-prep__head">
												<Badge state={ prep.outcome } />
												<code>{ prep.target }</code>
												<span className="subtle">
													{ prep.ready } ready ·{ ' ' }
													{ prep.noop } noop ·{ ' ' }
													{ prep.blocked } blocked
												</span>
												{ prep.state === 'received' &&
													prep.expires_at && (
														<span className="subtle">
															{ countdown(
																prep.expires_at
															) }
														</span>
													) }
												{ prep.state === 'received' && (
													<button
														type="button"
														className="btn btn--small btn--ghost"
														aria-expanded={
															receipt !==
															undefined
														}
														onClick={ () =>
															toggleReceipt(
																prep.operation_id
															)
														}
													>
														{ receipt !== undefined
															? __(
																	'Hide receipt',
																	'dbvc'
															  )
															: __(
																	'Show receipt',
																	'dbvc'
															  ) }
													</button>
												) }
											</div>
											{ receipt && receipt.receipt && (
												<div className="dbvc-ce-prep__body">
													{ (
														receipt.receipt.items ||
														[]
													).map( ( item, i ) => (
														<ReceiptItem
															key={ i }
															item={ item }
														/>
													) ) }
													{ receipt.hub_notes && (
														<p
															className="dbvc-ce-empty"
															style={ {
																paddingBottom: 0,
															} }
														>
															{ sprintf(
																/* translators: %d: number of items disagreeing with the hub projection */
																__(
																	"Hub notes: %d item(s) disagree with the hub's last projection.",
																	'dbvc'
																),
																receipt
																	.hub_notes
																	.items_disagreeing_with_hub_projection ??
																	0
															) }
														</p>
													) }
												</div>
											) }
										</div>
									);
								} ) }
								{ detail.release.state === 'sealed' && (
									<div
										className="dbvc-ce-pair"
										style={ { marginTop: 12 } }
									>
										<div className="dbvc-ce-field">
											<label htmlFor="dbvc-ce-prep-target">
												{ __(
													'Request prepare on',
													'dbvc'
												) }
											</label>
											<select
												id="dbvc-ce-prep-target"
												value={ prepareTarget }
												onChange={ ( e ) =>
													setPrepareTarget(
														e.target.value
													)
												}
											>
												<option value="">
													{ __(
														'— target —',
														'dbvc'
													) }
												</option>
												{ enabled
													.filter(
														( e ) =>
															e.environment_id !==
															detail.release
																.source
													)
													.map( ( e ) => (
														<option
															key={
																e.environment_id
															}
															value={
																e.environment_id
															}
														>
															{ e.environment_id }
														</option>
													) ) }
											</select>
										</div>
										<button
											type="button"
											className="btn btn--primary"
											disabled={
												! prepareTarget ||
												busy ===
													`prepare:${ prepareTarget }`
											}
											onClick={ requestPrepare }
										>
											{ __( 'Request prepare', 'dbvc' ) }
										</button>
									</div>
								) }
							</>
						) }

						{ step === 'approve' && (
							<>
								{ preparations.filter(
									( p ) => p.state === 'received'
								).length === 0 && (
									<p className="dbvc-ce-empty">
										{ __(
											'No received receipts to approve yet.',
											'dbvc'
										) }
									</p>
								) }
								{ preparations
									.filter( ( p ) => p.state === 'received' )
									.map( ( prep ) => {
										const approval = approvalFor(
											prep.operation_id
										);
										const approvable =
											( prep.outcome === 'ready' ||
												prep.outcome === 'noop' ) &&
											countdown( prep.expires_at ) !==
												__( 'expired', 'dbvc' );
										return (
											<div
												key={ prep.operation_id }
												className="dbvc-ce-prep"
											>
												<div className="dbvc-ce-prep__head">
													<Badge
														state={ prep.outcome }
													/>
													<code>{ prep.target }</code>
													{ approval ? (
														<Badge
															state={
																approval.state
															}
														/>
													) : (
														<span className="subtle">
															{ countdown(
																prep.expires_at
															) }
														</span>
													) }
													{ ! approval &&
														approvable && (
															<button
																type="button"
																className="btn btn--small btn--primary"
																onClick={ () =>
																	setConfirm(
																		{
																			operation_id:
																				prep.operation_id,
																			target: prep.target,
																			target_epoch:
																				prep.target_epoch,
																			receipt_digest:
																				prep.receipt_digest,
																			release_digest:
																				detail
																					.release
																					.digest,
																			expires_at:
																				prep.expires_at,
																		}
																	)
																}
															>
																{ __(
																	'Approve…',
																	'dbvc'
																) }
															</button>
														) }
													{ approval &&
														approval.state ===
															'approved' && (
															<button
																type="button"
																className="btn btn--small btn--ghost"
																disabled={
																	busy ===
																	`revoke:${ approval.approval_uid }`
																}
																onClick={ () =>
																	run(
																		`revoke:${ approval.approval_uid }`,
																		'agency/revoke-approval',
																		{
																			approval:
																				approval.approval_uid,
																			reason: 'revoked from the page',
																		},
																		() =>
																			__(
																				'Approval revoked.',
																				'dbvc'
																			)
																	)
																}
															>
																{ __(
																	'Revoke',
																	'dbvc'
																) }
															</button>
														) }
												</div>
												{ ! approvable &&
													! approval && (
														<p
															className="dbvc-ce-empty"
															style={ {
																padding:
																	'4px 0 0',
															} }
														>
															{ __(
																'Not approvable: the receipt is blocked or expired. Prepare again.',
																'dbvc'
															) }
														</p>
													) }
											</div>
										);
									} ) }
							</>
						) }

						{ step === 'execute' && (
							<>
								<p
									className="dbvc-ce-empty"
									style={ { paddingTop: 0 } }
								>
									{ __(
										"Execution is not a button here: an approved operation runs on the target's next poll, behind its own apply gate. This shows the receipt once it has.",
										'dbvc'
									) }
								</p>
								{ approvals.length === 0 && (
									<p className="dbvc-ce-empty">
										{ __(
											'No approvals for this release yet.',
											'dbvc'
										) }
									</p>
								) }
								{ approvals.map( ( approval ) => {
									const exec =
										execExpanded[ approval.approval_uid ];
									return (
										<div
											key={ approval.approval_uid }
											className="dbvc-ce-prep"
										>
											<div className="dbvc-ce-prep__head">
												<Badge
													state={ approval.state }
												/>
												<code>{ approval.target }</code>
												{ approval.execution_outcome ? (
													<Badge
														state={
															approval.execution_outcome
														}
													/>
												) : (
													<span className="subtle">
														{ __(
															"awaiting the target's poll",
															'dbvc'
														) }
													</span>
												) }
												{ approval.executed_at && (
													<span
														className="subtle"
														title={ absoluteTime(
															approval.executed_at
														) }
													>
														{ relative(
															approval.executed_at
														) }
													</span>
												) }
												{ approval.kind ===
													'rollback' && (
													<Badge state="withdrawn">
														{ __(
															'rollback',
															'dbvc'
														) }
													</Badge>
												) }
												{ approval.execution_outcome && (
													<button
														type="button"
														className="btn btn--small btn--ghost"
														aria-expanded={
															exec !== undefined
														}
														onClick={ () =>
															toggleExecution(
																approval.approval_uid
															)
														}
													>
														{ exec !== undefined
															? __(
																	'Hide receipt',
																	'dbvc'
															  )
															: __(
																	'Show receipt',
																	'dbvc'
															  ) }
													</button>
												) }
												{ canRollBack( approval ) && (
													<button
														type="button"
														className="btn btn--small btn--danger"
														onClick={ () =>
															setRollbackOf(
																approval
															)
														}
													>
														{ __(
															'Roll back…',
															'dbvc'
														) }
													</button>
												) }
											</div>
											{ exec &&
												exec.execution_receipt && (
													<div className="dbvc-ce-prep__body">
														<dl className="dbvc-ce-kv">
															<dt>
																{ __(
																	'Counts',
																	'dbvc'
																) }
															</dt>
															<dd>
																{ Object.entries(
																	exec
																		.execution_receipt
																		.counts ||
																		{}
																)
																	.filter(
																		( [
																			,
																			v,
																		] ) =>
																			v >
																			0
																	)
																	.map(
																		( [
																			k,
																			v,
																		] ) =>
																			`${ v } ${ k }`
																	)
																	.join(
																		' · '
																	) || '—' }
															</dd>
														</dl>
														{ (
															exec
																.execution_receipt
																.items || []
														).map( ( item, i ) => (
															<div
																key={ i }
																className="dbvc-ce-receipt-item"
															>
																<div className="dbvc-ce-receipt-item__head">
																	<Badge
																		state={
																			item.outcome
																		}
																	/>
																	<code>
																		{
																			item.instance_uid
																		}
																	</code>
																	<span className="subtle">
																		{ item.verified
																			? __(
																					'verified',
																					'dbvc'
																			  )
																			: __(
																					'not verified',
																					'dbvc'
																			  ) }
																		{ item.error
																			? ` · ${ item.error }`
																			: '' }
																	</span>
																</div>
															</div>
														) ) }
														{ Array.isArray(
															exec
																.execution_receipt
																.warnings
														) &&
															exec.execution_receipt.warnings.map(
																( w, i ) => (
																	<p
																		key={
																			i
																		}
																		className="dbvc-inline-notice dbvc-inline-notice--warning"
																	>
																		<span>
																			{
																				w
																			}
																		</span>
																	</p>
																)
															) }
													</div>
												) }
										</div>
									);
								} ) }
							</>
						) }
					</>
				) }
			</div>

			{ confirm && (
				<Modal
					title={ __( 'Approve this operation?', 'dbvc' ) }
					onClose={ () => setConfirm( null ) }
				>
					<p>
						{ __(
							'Approving binds the exact release and receipt to this target and epoch. The target executes it on its next poll only if its apply gate is on; nothing is written now.',
							'dbvc'
						) }
					</p>
					<dl className="dbvc-ce-kv">
						<dt>{ __( 'Target · epoch', 'dbvc' ) }</dt>
						<dd>
							<code>{ confirm.target }</code> ·{ ' ' }
							<code>{ epochShort( confirm.target_epoch ) }</code>
						</dd>
						<dt>{ __( 'Release digest', 'dbvc' ) }</dt>
						<dd>{ hashCell( confirm.release_digest ) }</dd>
						<dt>{ __( 'Receipt digest', 'dbvc' ) }</dt>
						<dd>{ hashCell( confirm.receipt_digest ) }</dd>
						<dt>{ __( 'Receipt', 'dbvc' ) }</dt>
						<dd>{ countdown( confirm.expires_at ) }</dd>
					</dl>
					<div className="dbvc-ce-modal__foot">
						<button
							type="button"
							className="btn"
							onClick={ () => setConfirm( null ) }
						>
							{ __( 'Cancel', 'dbvc' ) }
						</button>
						<button
							type="button"
							className="btn btn--primary"
							disabled={
								busy === `approve:${ confirm.operation_id }`
							}
							onClick={ () =>
								run(
									`approve:${ confirm.operation_id }`,
									'agency/approve',
									{
										operation: confirm.operation_id,
										note: 'approved from the page',
									},
									() => __( 'Operation approved.', 'dbvc' )
								)
							}
						>
							{ __( 'Approve', 'dbvc' ) }
						</button>
					</div>
				</Modal>
			) }
			{ rollbackOf && (
				<Modal
					title={ __( 'Roll back this operation?', 'dbvc' ) }
					onClose={ () => setRollbackOf( null ) }
				>
					<p>
						{ __(
							"A reviewed rollback asks the target to restore the before image it journalled for this operation, guarded by the operation's after fingerprint. A container that changed since the apply is reported as a restore conflict and is never overwritten; nothing is written now.",
							'dbvc'
						) }
					</p>
					<dl className="dbvc-ce-kv">
						<dt>{ __( 'Operation', 'dbvc' ) }</dt>
						<dd>
							<code>{ rollbackOf.operation_id }</code>
						</dd>
						<dt>{ __( 'Target', 'dbvc' ) }</dt>
						<dd>
							<code>{ rollbackOf.target }</code>
						</dd>
						<dt>{ __( 'Reverses', 'dbvc' ) }</dt>
						<dd>
							<Badge state={ rollbackOf.execution_outcome } />
						</dd>
					</dl>
					<div className="dbvc-ce-modal__foot">
						<button
							type="button"
							className="btn"
							onClick={ () => setRollbackOf( null ) }
						>
							{ __( 'Cancel', 'dbvc' ) }
						</button>
						<button
							type="button"
							className="btn btn--danger"
							disabled={
								busy === `rollback:${ rollbackOf.operation_id }`
							}
							onClick={ () =>
								run(
									`rollback:${ rollbackOf.operation_id }`,
									'agency/rollback',
									{
										operation: rollbackOf.operation_id,
										note: 'rolled back from the page',
									},
									( res ) =>
										sprintf(
											/* translators: %s: rollback operation id */
											__(
												'Rollback %s created; the target restores on its next poll.',
												'dbvc'
											),
											res.operation_id || ''
										)
								)
							}
						>
							{ __( 'Roll back', 'dbvc' ) }
						</button>
					</div>
				</Modal>
			) }
		</div>
	);
}

const OUTBOX_STATES = [ 'pending', 'delivered', 'acked', 'released' ];

/**
 * Connector Activity: the site's own outbox (produced observations), the inbox
 * of received observations (never applied), and the release receipts + execution
 * journal it produced. Reads only; the apply-gate state is a banner on Releases.
 *
 * @param {Object}   props
 * @param {Object}   props.connector Connector status report (apply gate, gate state).
 * @param {Function} props.notify
 * @param {Function} props.onRun     Runner dispatcher shared with the header.
 * @param {string}   props.running   Key of the runner in flight, if any.
 */
function ConnectorActivity( { connector, notify, onRun, running } ) {
	const [ panel, setPanel ] = useState( 'activity' );
	const [ outbox, setOutbox ] = useState( null );
	const [ inbox, setInbox ] = useState( null );
	const [ preparations, setPreparations ] = useState( null );
	const [ operations, setOperations ] = useState( null );
	const [ outboxFilter, setOutboxFilter ] = useState( '' );
	const [ domainFilter, setDomainFilter ] = useState( '' );
	const [ expanded, setExpanded ] = useState( {} );
	const [ journalExpanded, setJournalExpanded ] = useState( {} );
	const applyEnabled = !! connector?.connection?.apply_enabled;

	const load = useCallback( async () => {
		try {
			const [ ob, ib, prep, ops ] = await Promise.all( [
				apiFetch( { path: 'connected/outbox?limit=200' } ),
				apiFetch( { path: 'connected/inbox?limit=200' } ),
				apiFetch( { path: 'connected/preparations?limit=200' } ),
				apiFetch( { path: 'connected/operations?limit=200' } ),
			] );
			setOutbox( ob );
			setInbox( ib );
			setPreparations( prep );
			setOperations( ops );
		} catch ( err ) {
			notify( 'error', errorMessage( err ) );
		}
	}, [ notify ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const outboxRows = useMemo( () => {
		let rows = outbox?.events || [];
		if ( outboxFilter ) {
			rows = rows.filter( ( r ) => r.delivery_state === outboxFilter );
		}
		if ( domainFilter ) {
			rows = rows.filter( ( r ) => r.domain === domainFilter );
		}
		return rows;
	}, [ outbox, outboxFilter, domainFilter ] );

	const inboxBySource = useMemo( () => {
		const groups = {};
		( inbox?.items || [] ).forEach( ( item ) => {
			groups[ item.source_environment_id ] =
				groups[ item.source_environment_id ] || [];
			groups[ item.source_environment_id ].push( item );
		} );
		return Object.entries( groups );
	}, [ inbox ] );

	const toggleReceipt = async ( operationId ) => {
		if ( expanded[ operationId ] !== undefined ) {
			setExpanded( ( e ) => ( { ...e, [ operationId ]: undefined } ) );
			return;
		}
		try {
			const full = await apiFetch( {
				path: `connected/preparations?operation=${ encodeURIComponent(
					operationId
				) }`,
			} );
			setExpanded( ( e ) => ( { ...e, [ operationId ]: full } ) );
		} catch ( err ) {
			notify( 'error', errorMessage( err ) );
		}
	};

	const toggleJournal = async ( operationId ) => {
		if ( journalExpanded[ operationId ] !== undefined ) {
			setJournalExpanded( ( e ) => ( {
				...e,
				[ operationId ]: undefined,
			} ) );
			return;
		}
		try {
			const full = await apiFetch( {
				path: `connected/operations?operation=${ encodeURIComponent(
					operationId
				) }`,
			} );
			setJournalExpanded( ( e ) => ( { ...e, [ operationId ]: full } ) );
		} catch ( err ) {
			notify( 'error', errorMessage( err ) );
		}
	};

	const panels = [
		{ key: 'activity', label: __( 'Activity', 'dbvc' ) },
		{ key: 'inbox', label: __( 'Inbox', 'dbvc' ) },
		{ key: 'releases', label: __( 'Releases', 'dbvc' ) },
	];

	return (
		<div className="dbvc-tools-panel">
			<div className="dbvc-ce-panel__head">
				<h2>{ __( 'Connector activity', 'dbvc' ) }</h2>
				<span className="dbvc-ce-asof">
					{ __(
						"this site's own outbox, inbox and release receipts",
						'dbvc'
					) }
				</span>
			</div>
			<nav
				className="dbvc-section-nav"
				aria-label={ __( 'Activity panels', 'dbvc' ) }
				style={ { marginBottom: 12 } }
			>
				{ panels.map( ( p ) => (
					<button
						key={ p.key }
						type="button"
						aria-current={ panel === p.key ? 'page' : undefined }
						onClick={ () => setPanel( p.key ) }
					>
						{ p.label }
					</button>
				) ) }
			</nav>

			{ panel === 'activity' && (
				<>
					<div
						className="dbvc-ce-chips"
						role="group"
						aria-label={ __( 'Filter outbox', 'dbvc' ) }
					>
						{ OUTBOX_STATES.map( ( state ) => (
							<button
								key={ state }
								type="button"
								className="dbvc-status-badge"
								aria-pressed={ outboxFilter === state }
								onClick={ () =>
									setOutboxFilter(
										outboxFilter === state ? '' : state
									)
								}
							>
								<span className="n">
									{ outbox?.counts?.[ state ] ?? 0 }
								</span>{ ' ' }
								{ state }
							</button>
						) ) }
						{ DOMAINS.map( ( d ) => (
							<button
								key={ d }
								type="button"
								className="dbvc-status-badge"
								aria-pressed={ domainFilter === d }
								onClick={ () =>
									setDomainFilter(
										domainFilter === d ? '' : d
									)
								}
							>
								{ d }
							</button>
						) ) }
						<button
							type="button"
							className="btn btn--small btn--ghost"
							disabled={ running === 'reconcile' }
							onClick={ () =>
								onRun( {
									key: 'reconcile',
									label: __( 'Reconcile', 'dbvc' ),
									role: 'connector',
									path: 'connected/reconcile',
									data: domainFilter
										? { domain: domainFilter }
										: {},
									after: load,
								} )
							}
						>
							{ domainFilter
								? sprintf(
										/* translators: %s: domain */
										__( 'Reconcile %s', 'dbvc' ),
										domainFilter
								  )
								: __( 'Reconcile all', 'dbvc' ) }
						</button>
					</div>
					{ ! outbox && (
						<p className="dbvc-ce-loading">
							{ __( 'Loading…', 'dbvc' ) }
						</p>
					) }
					{ outbox && outboxRows.length === 0 && (
						<p className="dbvc-ce-empty">
							{ __(
								'No outbox events for this filter.',
								'dbvc'
							) }
						</p>
					) }
					{ outbox && outboxRows.length > 0 && (
						<table className="widefat striped">
							<thead>
								<tr>
									<th className="num">
										{ __( 'Seq', 'dbvc' ) }
									</th>
									<th>{ __( 'Object', 'dbvc' ) }</th>
									<th>{ __( 'Origin', 'dbvc' ) }</th>
									<th>{ __( 'Hash', 'dbvc' ) }</th>
									<th>{ __( 'Delivery', 'dbvc' ) }</th>
									<th>{ __( 'Observed', 'dbvc' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ outboxRows.map( ( row ) => (
									<tr key={ row.event_id }>
										<td className="num">
											{ row.sequence }
										</td>
										<td>
											<span className="obj">
												{ row.instance_uid }
											</span>
											<code>
												{ row.domain }
												{ row.exists === 'no'
													? ' · absent'
													: '' }
											</code>
										</td>
										<td>
											{ row.origin ? (
												<Badge state="directional">
													{ row.origin }
												</Badge>
											) : (
												<span className="subtle">
													—
												</span>
											) }
										</td>
										<td>{ hashCell( row.hash ) }</td>
										<td>
											<Badge
												state={ row.delivery_state }
											/>
										</td>
										<td>
											<span
												title={ absoluteTime(
													row.created_at
												) }
											>
												{ relative( row.created_at ) }
											</span>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
				</>
			) }

			{ panel === 'inbox' && (
				<>
					<div className="dbvc-inline-notice dbvc-inline-notice--info">
						<p>
							{ __(
								'Received observations are stored for comparison and never applied to this site. Delivery is acknowledged only after the row is stored.',
								'dbvc'
							) }
						</p>
					</div>
					{ ! inbox && (
						<p className="dbvc-ce-loading">
							{ __( 'Loading…', 'dbvc' ) }
						</p>
					) }
					{ inbox && inboxBySource.length === 0 && (
						<p className="dbvc-ce-empty">
							{ __( 'No received observations.', 'dbvc' ) }
						</p>
					) }
					{ inboxBySource.map( ( [ sourceId, items ] ) => (
						<div key={ sourceId } style={ { marginBottom: 12 } }>
							<h3 style={ { margin: '0 0 6px', fontSize: 13 } }>
								<code>{ sourceId }</code>{ ' ' }
								<span className="subtle">
									{ sprintf(
										/* translators: %d: number of received observations */
										_n(
											'%d observation',
											'%d observations',
											items.length,
											'dbvc'
										),
										items.length
									) }
								</span>
							</h3>
							<table className="widefat striped">
								<thead>
									<tr>
										<th className="num">
											{ __( 'Seq', 'dbvc' ) }
										</th>
										<th>{ __( 'Object', 'dbvc' ) }</th>
										<th>{ __( 'Hash', 'dbvc' ) }</th>
										<th>{ __( 'Received', 'dbvc' ) }</th>
										<th>{ __( 'Acked', 'dbvc' ) }</th>
									</tr>
								</thead>
								<tbody>
									{ items.map( ( item ) => (
										<tr key={ item.delivery_id }>
											<td className="num">
												{ item.sequence }
											</td>
											<td>
												<span className="obj">
													{ item.instance_uid }
												</span>
												<code>
													{ item.domain } ·{ ' ' }
													{ item.profile }
												</code>
											</td>
											<td>
												{ hashCell( item.hash ) }
												{ item.exists === 'no' && (
													<span className="subtle">
														{ ' ' }
														{ __(
															'absent',
															'dbvc'
														) }
													</span>
												) }
											</td>
											<td>
												<span
													title={ absoluteTime(
														item.received_at
													) }
												>
													{ relative(
														item.received_at
													) }
												</span>
											</td>
											<td>
												{ item.acked_at ? (
													<Badge state="acked">
														{ __(
															'acked',
															'dbvc'
														) }
													</Badge>
												) : (
													<span className="subtle">
														{ __(
															'pending',
															'dbvc'
														) }
													</span>
												) }
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) ) }
				</>
			) }

			{ panel === 'releases' && (
				<>
					{ applyEnabled ? (
						<div className="dbvc-inline-notice dbvc-inline-notice--warning">
							<p>
								<strong>
									{ __( 'Apply is on.', 'dbvc' ) }
								</strong>{ ' ' }
								{ __(
									'Approved releases are executed here on the next poll with a conditional, journalled, verified write.',
									'dbvc'
								) }
							</p>
						</div>
					) : (
						<div className="dbvc-inline-notice dbvc-inline-notice--info">
							<p>
								<strong>
									{ __(
										'Apply is off — approved releases are not executed here.',
										'dbvc'
									) }
								</strong>{ ' ' }
								{ __(
									'Prepare receipts are still produced as dry runs.',
									'dbvc'
								) }
							</p>
						</div>
					) }

					<h3 style={ { fontSize: 13, margin: '12px 0 6px' } }>
						{ __( 'Prepare receipts', 'dbvc' ) }
					</h3>
					{ ! preparations && (
						<p className="dbvc-ce-loading">
							{ __( 'Loading…', 'dbvc' ) }
						</p>
					) }
					{ preparations &&
						( preparations.preparations || [] ).length === 0 && (
							<p className="dbvc-ce-empty">
								{ __(
									'No prepare receipts produced yet.',
									'dbvc'
								) }
							</p>
						) }
					{ ( preparations?.preparations || [] ).map( ( prep ) => {
						const full = expanded[ prep.operation_id ];
						return (
							<div
								key={ prep.operation_id }
								className="dbvc-ce-prep"
							>
								<div className="dbvc-ce-prep__head">
									<Badge state={ prep.outcome } />
									<code>{ prep.release_uid }</code>
									<span className="subtle">
										{ prep.ready } ready · { prep.noop }{ ' ' }
										noop · { prep.blocked } blocked
									</span>
									<span className="subtle">
										{ countdown( prep.expires_at ) }
									</span>
									{ prep.reported_at ? (
										<Badge state="received">
											{ __( 'reported', 'dbvc' ) }
										</Badge>
									) : (
										<span className="subtle">
											{ prep.report_error ||
												__( 'unreported', 'dbvc' ) }
										</span>
									) }
									<button
										type="button"
										className="btn btn--small btn--ghost"
										aria-expanded={ full !== undefined }
										onClick={ () =>
											toggleReceipt( prep.operation_id )
										}
									>
										{ full !== undefined
											? __( 'Hide items', 'dbvc' )
											: __( 'Show items', 'dbvc' ) }
									</button>
								</div>
								{ full && full.receipt && (
									<div className="dbvc-ce-prep__body">
										{ ( full.receipt.items || [] ).map(
											( item, i ) => (
												<ReceiptItem
													key={ i }
													item={ item }
												/>
											)
										) }
									</div>
								) }
							</div>
						);
					} ) }

					<h3 style={ { fontSize: 13, margin: '16px 0 6px' } }>
						{ __( 'Execution journal', 'dbvc' ) }
					</h3>
					{ operations &&
						( operations.operations || [] ).length === 0 && (
							<p className="dbvc-ce-empty">
								{ __(
									'Nothing has executed on this site.',
									'dbvc'
								) }
							</p>
						) }
					{ ( operations?.operations || [] ).map( ( op ) => {
						const full = journalExpanded[ op.operation_id ];
						return (
							<div
								key={ op.operation_id }
								className="dbvc-ce-prep"
							>
								<div className="dbvc-ce-prep__head">
									<Badge state={ op.state } />
									<Badge state={ op.outcome } />
									<code>{ op.release_uid }</code>
									<span className="subtle">
										{ op.applied } applied · { op.stale }{ ' ' }
										stale · { op.failed } failed
									</span>
									{ op.finished_at && (
										<span
											className="subtle"
											title={ absoluteTime(
												op.finished_at
											) }
										>
											{ relative( op.finished_at ) }
										</span>
									) }
									{ ! op.reported_at && op.report_error && (
										<Badge state="failed">
											{ op.report_error }
										</Badge>
									) }
									<button
										type="button"
										className="btn btn--small btn--ghost"
										aria-expanded={ full !== undefined }
										onClick={ () =>
											toggleJournal( op.operation_id )
										}
									>
										{ full !== undefined
											? __( 'Hide steps', 'dbvc' )
											: __( 'Show steps', 'dbvc' ) }
									</button>
								</div>
								{ full &&
									Array.isArray(
										full.execution_receipt?.journal
									) && (
										<div className="dbvc-ce-prep__body">
											<ol className="dbvc-ce-journal">
												{ full.execution_receipt.journal.map(
													( entry, i ) => (
														<li key={ i }>
															{ entry.container && (
																<code>
																	{
																		entry.container
																	}
																</code>
															) }{ ' ' }
															{ entry.step }
															{ entry.result
																? ` → ${ entry.result }`
																: '' }
															{ entry.storage_key
																? ` (${ entry.storage_key })`
																: '' }
														</li>
													)
												) }
											</ol>
										</div>
									) }
							</div>
						);
					} ) }
				</>
			) }
		</div>
	);
}

/**
 * Per-target delivery queues as "astage 7 pending · 0 acked", oldest age when pending.
 *
 * @param {Object} deliveries Map of target id to counters.
 * @return {string} Summary text.
 */
function deliveriesText( deliveries ) {
	const entries = Object.entries( deliveries || {} );
	if ( ! entries.length ) {
		return '—';
	}
	return entries
		.map( ( [ target, v ] ) => {
			if ( ! v || typeof v !== 'object' ) {
				return `${ target } ${ v }`;
			}
			const parts = [
				sprintf(
					/* translators: %d: number of pending deliveries */
					__( '%d pending', 'dbvc' ),
					v.pending ?? 0
				),
				sprintf(
					/* translators: %d: number of acknowledged deliveries */
					__( '%d acked', 'dbvc' ),
					v.acked ?? 0
				),
			];
			if ( v.cancelled ) {
				parts.push(
					sprintf(
						/* translators: %d: number of cancelled deliveries */
						__( '%d cancelled', 'dbvc' ),
						v.cancelled
					)
				);
			}
			return `${ target }: ${ parts.join( ' · ' ) }`;
		} )
		.join( ' — ' );
}

/* ---------- Header ---------- */

function Header( { overview, loading, onRefresh, runners, onRun, running } ) {
	const [ open, setOpen ] = useState( false );
	const menuRef = useRef( null );
	useEffect( () => {
		if ( ! open ) {
			return undefined;
		}
		const close = ( event ) => {
			if (
				menuRef.current &&
				! menuRef.current.contains( event.target )
			) {
				setOpen( false );
			}
		};
		document.addEventListener( 'mousedown', close );
		return () => document.removeEventListener( 'mousedown', close );
	}, [ open ] );

	const connector = overview?.connector;
	const hub = overview?.hub;
	return (
		<header className="dbvc-ce__header">
			<div>
				<h1 className="dbvc-ce__title">
					{ __( 'Connected Environments', 'dbvc' ) }
				</h1>
				<div className="dbvc-ce__meta">
					{ overview?.roles?.hub && (
						<span className="dbvc-ce-role">
							<span className="dbvc-ce-role__dot" />{ ' ' }
							{ __( 'Hub', 'dbvc' ) } ·{ ' ' }
							<code>
								{ hub?.environments?.[ 0 ]?.agency_id ||
									'studio' }
							</code>
						</span>
					) }
					{ overview?.roles?.connector && (
						<span className="dbvc-ce-role">
							<span
								className={ `dbvc-ce-role__dot${
									connector?.identity?.enrollment_state ===
									'enrolled'
										? ''
										: ' dbvc-ce-role__dot--muted'
								}` }
							/>{ ' ' }
							{ __( 'Connector', 'dbvc' ) } ·{ ' ' }
							{ connector?.identity?.enrollment_state ===
							'enrolled' ? (
								<>
									{ __( 'enrolled as', 'dbvc' ) }{ ' ' }
									<code>
										{ connector.identity.environment_id }
									</code>
								</>
							) : (
								connector?.identity?.enrollment_state ||
								__( 'not enrolled', 'dbvc' )
							) }
						</span>
					) }
					<span className="dbvc-ce-asof">
						{ hub?.schema?.version
							? sprintf(
									/* translators: placeholders are counts, identifiers or relative times shown in the Connected Environments page */
									__(
										'Hub schema v%1$d · freshness window %2$s h',
										'dbvc'
									),
									hub.schema.version,
									Math.round(
										( hub.freshness_seconds || 86400 ) /
											3600
									)
							  ) + ' · '
							: '' }
						{ overview?.generated_at
							? sprintf(
									/* translators: placeholders are counts, identifiers or relative times shown in the Connected Environments page */
									__( 'as of %s', 'dbvc' ),
									new Date(
										overview.generated_at
									).toLocaleString()
							  )
							: '' }
					</span>
				</div>
			</div>
			<div className="dbvc-ce__actions">
				<button
					type="button"
					className="btn btn--ghost"
					onClick={ onRefresh }
					disabled={ loading }
				>
					{ loading
						? __( 'Refreshing…', 'dbvc' )
						: __( 'Refresh', 'dbvc' ) }
				</button>
				{ runners.length > 0 && (
					<div className="dbvc-ce-menu" ref={ menuRef }>
						<button
							type="button"
							className="btn"
							aria-haspopup="menu"
							aria-expanded={ open }
							onClick={ () => setOpen( ! open ) }
							disabled={ !! running }
						>
							{ running
								? sprintf(
										/* translators: placeholders are counts, identifiers or relative times shown in the Connected Environments page */
										__( 'Running %s…', 'dbvc' ),
										running
								  )
								: __( 'Run now ▾', 'dbvc' ) }
						</button>
						{ open && (
							<ul className="dbvc-ce-menu__list" role="menu">
								{ runners.map( ( runner ) => (
									<li key={ runner.key } role="none">
										<button
											type="button"
											role="menuitem"
											onClick={ () => {
												setOpen( false );
												onRun( runner );
											} }
										>
											{ runner.label }
										</button>
									</li>
								) ) }
							</ul>
						) }
					</div>
				) }
			</div>
		</header>
	);
}

/* ---------- Overview ---------- */

function StatCard( { label, value, detail } ) {
	return (
		<div className="dbvc-tools-panel dbvc-ce-stat">
			<div className="dbvc-ce-stat__label">{ label }</div>
			<div className="dbvc-ce-stat__value">{ value }</div>
			<div className="dbvc-ce-stat__detail">{ detail }</div>
		</div>
	);
}

function HubOverview( { hub, onNavigate } ) {
	const environments = useMemo( () => hub?.environments || [], [ hub ] );
	const freshness = hub?.freshness_seconds || 86400;
	const counts = useMemo( () => {
		const out = { enabled: 0, held: 0, revoked: 0, stale: 0 };
		environments.forEach( ( env ) => {
			out[ env.status ] = ( out[ env.status ] || 0 ) + 1;
			const contact = env.last_contact_at
				? new Date(
						env.last_contact_at.replace( ' ', 'T' ) + 'Z'
				  ).getTime()
				: 0;
			if (
				env.status === 'enabled' &&
				( ! contact || Date.now() - contact > freshness * 1000 )
			) {
				out.stale += 1;
			}
		} );
		return out;
	}, [ environments, freshness ] );
	const reviews = hub?.reviews || {};
	const releases = hub?.releases || {};
	const preparations = hub?.preparations || {};
	const approvals = hub?.approvals || {};
	const awaiting =
		( reviews.classified || 0 ) +
		( preparations.received || 0 ) +
		( approvals.approved || 0 );
	const attention = [];
	environments
		.filter( ( env ) => env.status === 'held' )
		.forEach( ( env ) =>
			attention.push( {
				key: `held-${ env.environment_id }`,
				state: 'held',
				title: sprintf(
					/* translators: placeholders are counts, identifiers or relative times shown in the Connected Environments page */
					__( '%s is held', 'dbvc' ),
					env.label || env.environment_id
				),
				detail: `${ env.hold_reason || '' } · ${ __(
					'last contact',
					'dbvc'
				) } ${ relative( env.last_contact_at ) }`,
			} )
		);
	environments
		.filter( ( env ) => env.status === 'enabled' )
		.forEach( ( env ) => {
			const contact = env.last_contact_at
				? new Date(
						env.last_contact_at.replace( ' ', 'T' ) + 'Z'
				  ).getTime()
				: 0;
			if ( ! contact || Date.now() - contact > freshness * 1000 ) {
				attention.push( {
					key: `stale-${ env.environment_id }`,
					state: 'stale',
					title: sprintf(
						/* translators: placeholders are counts, identifiers or relative times shown in the Connected Environments page */
						__(
							'%s has not reported inside the freshness window',
							'dbvc'
						),
						env.label || env.environment_id
					),
					detail: `${ __( 'last contact', 'dbvc' ) } ${ relative(
						env.last_contact_at
					) } · ${ __(
						'its projections read unknown, never clean',
						'dbvc'
					) }`,
				} );
			}
		} );
	if ( reviews.classified ) {
		attention.push( {
			key: 'reviews',
			state: 'classified',
			title: sprintf(
				/* translators: placeholders are counts, identifiers or relative times shown in the Connected Environments page */
				__(
					'%d review item(s) classified and waiting for a decision',
					'dbvc'
				),
				reviews.classified
			),
			detail: __( 'Framework → Reviews (slice A4)', 'dbvc' ),
		} );
	}
	if ( preparations.received ) {
		attention.push( {
			key: 'receipts',
			state: 'ready',
			title: sprintf(
				/* translators: placeholders are counts, identifiers or relative times shown in the Connected Environments page */
				__( '%d prepare receipt(s) received', 'dbvc' ),
				preparations.received
			),
			detail: __( 'Releases → Prepare (slice A5)', 'dbvc' ),
		} );
	}
	if ( approvals.approved ) {
		attention.push( {
			key: 'approvals',
			state: 'expiring',
			title: sprintf(
				/* translators: placeholders are counts, identifiers or relative times shown in the Connected Environments page */
				__( '%d approval(s) open and not yet executed', 'dbvc' ),
				approvals.approved
			),
			detail: __(
				"Executed on the target's next poll while its apply gate is on",
				'dbvc'
			),
		} );
	}
	return (
		<>
			<div className="dbvc-ce-stats">
				<StatCard
					label={ __( 'Environments', 'dbvc' ) }
					value={ environments.length }
					detail={
						<>
							{ counts.enabled } { __( 'enabled', 'dbvc' ) } ·{ ' ' }
							{ counts.held || 0 } { __( 'held', 'dbvc' ) } ·{ ' ' }
							{ counts.stale ? (
								<Badge state="stale">
									{ counts.stale } { __( 'stale', 'dbvc' ) }
								</Badge>
							) : (
								`0 ${ __( 'stale', 'dbvc' ) }`
							) }
						</>
					}
				/>
				<StatCard
					label={ __( 'Framework', 'dbvc' ) }
					value={ hub?.definitions ?? 0 }
					detail={ sprintf(
						/* translators: placeholders are counts, identifiers or relative times shown in the Connected Environments page */
						__( '%1$d definition(s) · %2$d override(s)', 'dbvc' ),
						hub?.definitions ?? 0,
						hub?.overrides ?? 0
					) }
				/>
				<StatCard
					label={ __( 'Releases', 'dbvc' ) }
					value={ releases.total || 0 }
					detail={ sprintf(
						/* translators: placeholders are counts, identifiers or relative times shown in the Connected Environments page */
						__(
							'%1$d sealed · %2$d collecting payloads · %3$d withdrawn',
							'dbvc'
						),
						releases.sealed || 0,
						releases.open || 0,
						releases.withdrawn || 0
					) }
				/>
				<StatCard
					label={ __( 'Awaiting you', 'dbvc' ) }
					value={ awaiting }
					detail={ sprintf(
						/* translators: placeholders are counts, identifiers or relative times shown in the Connected Environments page */
						__(
							'%1$d reviews · %2$d receipts · %3$d approvals open',
							'dbvc'
						),
						reviews.classified || 0,
						preparations.received || 0,
						approvals.approved || 0
					) }
				/>
			</div>
			<div className="dbvc-ce-grid">
				<div className="dbvc-tools-panel">
					<div className="dbvc-ce-panel__head">
						<h2>{ __( 'Attention', 'dbvc' ) }</h2>
						<span className="dbvc-ce-asof">
							{ __(
								'Built from environment state, reviews, receipts and approvals · nothing here runs by itself',
								'dbvc'
							) }
						</span>
					</div>
					{ attention.length === 0 ? (
						<p className="dbvc-ce-loading">
							{ __(
								'Nothing needs a decision right now. Zero is shown against the last received reports, not as proof of a clean fleet.',
								'dbvc'
							) }
						</p>
					) : (
						<ul className="dbvc-ce-attention">
							{ attention.map( ( item ) => (
								<li
									key={ item.key }
									className="dbvc-ce-attention__item"
								>
									<Badge state={ item.state } />
									<div className="dbvc-ce-attention__what">
										<strong>{ item.title }</strong>
										<span>{ item.detail }</span>
									</div>
									<span />
								</li>
							) ) }
						</ul>
					) }
				</div>
				<div className="dbvc-tools-panel">
					<div className="dbvc-ce-panel__head">
						<h2>{ __( 'Hub', 'dbvc' ) }</h2>
					</div>
					<dl className="dbvc-ce-kv">
						<dt>{ __( 'Events received', 'dbvc' ) }</dt>
						<dd>
							{ hub?.events?.total ?? 0 } ·{ ' ' }
							{ __( 'pending routing', 'dbvc' ) }{ ' ' }
							{ hub?.events?.pending ?? 0 }
						</dd>
						<dt>{ __( 'Deliveries', 'dbvc' ) }</dt>
						<dd>{ deliveriesText( hub?.deliveries ) }</dd>
						<dt>{ __( 'Baselines', 'dbvc' ) }</dt>
						<dd>{ hub?.baselines ?? 0 }</dd>
						<dt>{ __( 'Protocol', 'dbvc' ) }</dt>
						<dd>
							{ hub?.protocol?.protocol } ·{ ' ' }
							{ __( 'canonicalizer', 'dbvc' ) } v
							{ hub?.protocol?.canonicalizer_version }
						</dd>
					</dl>
					<p style={ { marginTop: 12 } }>
						<button
							type="button"
							className="btn btn--small btn--ghost"
							onClick={ () => onNavigate( 'settings' ) }
						>
							{ __( 'Settings', 'dbvc' ) }
						</button>
					</p>
				</div>
			</div>
		</>
	);
}

function ConnectorOverview( { connector, onRun, running } ) {
	const connection = connector?.connection || {};
	const identity = connector?.identity || {};
	const jobs = connector?.jobs || {};
	const outbox = connector?.outbox || {};
	const domains = connector?.domains || {};
	const scheduler = connector?.scheduler || {};
	const enrolled = identity.enrollment_state === 'enrolled';
	return (
		<>
			{ connector?.gate_state === 'ready' &&
				! connection.apply_enabled && (
					<div className="dbvc-inline-notice dbvc-inline-notice--info">
						<p>
							<strong>{ __( 'Apply is off.', 'dbvc' ) }</strong>{ ' ' }
							{ __(
								'Approved releases are not executed on this site. Turn it on under Settings only when this environment should accept studio releases.',
								'dbvc'
							) }
						</p>
					</div>
				) }
			{ connection.apply_enabled && (
				<div className="dbvc-inline-notice dbvc-inline-notice--warning">
					<p>
						<strong>{ __( 'Apply is on.', 'dbvc' ) }</strong>{ ' ' }
						{ __(
							'Releases the hub approved for this environment are executed on its next poll with a conditional, journalled, verified write.',
							'dbvc'
						) }
					</p>
				</div>
			) }
			<div
				className="dbvc-ce-stats"
				style={ { gridTemplateColumns: 'repeat(3, minmax(0, 1fr))' } }
			>
				<div className="dbvc-tools-panel">
					<div className="dbvc-ce-panel__head">
						<h2>{ __( 'Connection', 'dbvc' ) }</h2>
						<Badge
							state={
								enrolled
									? connection.state || 'enrolled'
									: identity.enrollment_state ||
									  'uninitialized'
							}
						/>
					</div>
					<dl className="dbvc-ce-kv">
						<dt>{ __( 'Environment', 'dbvc' ) }</dt>
						<dd>
							<code>{ identity.environment_id || '—' }</code>
						</dd>
						<dt>{ __( 'Epoch', 'dbvc' ) }</dt>
						<dd>
							<code>{ identity.installation_epoch || '—' }</code>
						</dd>
						<dt>{ __( 'Hub', 'dbvc' ) }</dt>
						<dd>
							{ connection.hub_url ? (
								<code>{ connection.hub_url }</code>
							) : (
								__( 'none', 'dbvc' )
							) }
						</dd>
						<dt>{ __( 'Principal', 'dbvc' ) }</dt>
						<dd>
							{ connection.principal ? (
								<code>{ connection.principal }</code>
							) : (
								__( 'none', 'dbvc' )
							) }
						</dd>
						{ connection.hold_reason ? (
							<>
								<dt>{ __( 'Hold', 'dbvc' ) }</dt>
								<dd>
									<Badge state="held" />{ ' ' }
									{ connection.hold_reason }
								</dd>
							</>
						) : null }
						<dt>{ __( 'Last delivery', 'dbvc' ) }</dt>
						<dd>
							{ connection.delivery?.finished_at
								? `${ relative(
										connection.delivery.finished_at
								  ) } · ${ __( 'sent', 'dbvc' ) } ${
										connection.delivery.sent
								  } · ${ __( 'delivered', 'dbvc' ) } ${
										connection.delivery.delivered
								  }`
								: __( 'never', 'dbvc' ) }
						</dd>
						<dt>{ __( 'Last poll', 'dbvc' ) }</dt>
						<dd>
							{ connection.last_poll?.finished_at
								? relative( connection.last_poll.finished_at )
								: __( 'never', 'dbvc' ) }
						</dd>
						<dt>{ __( 'Scheduler', 'dbvc' ) }</dt>
						<dd>{ schedulerText( scheduler ) }</dd>
					</dl>
				</div>
				<div className="dbvc-tools-panel">
					<div className="dbvc-ce-panel__head">
						<h2>{ __( 'Coverage', 'dbvc' ) }</h2>
					</div>
					<dl className="dbvc-ce-kv">
						{ Object.entries( domains ).map(
							( [ domain, info ] ) => (
								<div
									key={ domain }
									style={ { display: 'contents' } }
								>
									<dt>
										<code>{ domain }</code>
									</dt>
									<dd>
										<Badge
											state={
												info.coverage === 'available'
													? 'available'
													: 'unavailable'
											}
										/>{ ' ' }
										{ info.reason
											? `${ info.reason } · `
											: '' }
										{ info.objects
											? sprintf(
													/* translators: placeholders are counts, identifiers or relative times shown in the Connected Environments page */
													__(
														'%1$d present · %2$d absent · %3$d incomplete',
														'dbvc'
													),
													info.objects.present || 0,
													info.objects.absent || 0,
													info.objects.incomplete || 0
											  )
											: '' }
									</dd>
								</div>
							)
						) }
					</dl>
				</div>
				<div className="dbvc-tools-panel">
					<div className="dbvc-ce-panel__head">
						<h2>{ __( 'Pending work', 'dbvc' ) }</h2>
					</div>
					<dl className="dbvc-ce-kv">
						<dt>{ __( 'Dirty markers', 'dbvc' ) }</dt>
						<dd>
							{ jobs.total || 0 } · { jobs.due || 0 }{ ' ' }
							{ __( 'due', 'dbvc' ) } · { jobs.leased || 0 }{ ' ' }
							{ __( 'leased', 'dbvc' ) } · { jobs.errored || 0 }{ ' ' }
							{ __( 'errored', 'dbvc' ) }
						</dd>
						<dt>{ __( 'Outbox', 'dbvc' ) }</dt>
						<dd>
							{ outbox.pending || 0 } { __( 'pending', 'dbvc' ) }{ ' ' }
							· { outbox.delivered || 0 }{ ' ' }
							{ __( 'delivered', 'dbvc' ) } ·{ ' ' }
							{ outbox.rejected || 0 }{ ' ' }
							{ __( 'rejected', 'dbvc' ) }
						</dd>
						<dt>{ __( 'Inbox', 'dbvc' ) }</dt>
						<dd>
							{ connection.inbox?.total || 0 }{ ' ' }
							{ __( 'received', 'dbvc' ) } ·{ ' ' }
							{ connection.inbox?.unacked || 0 }{ ' ' }
							{ __( 'awaiting ack', 'dbvc' ) }
						</dd>
						<dt>{ __( 'Receipts', 'dbvc' ) }</dt>
						<dd>
							{ connection.preparations?.total || 0 }{ ' ' }
							{ __( 'produced', 'dbvc' ) } ·{ ' ' }
							{ connection.preparations?.unreported || 0 }{ ' ' }
							{ __( 'unreported', 'dbvc' ) }
						</dd>
						<dt>{ __( 'Executions', 'dbvc' ) }</dt>
						<dd>
							{ connection.operations?.total || 0 } ·{ ' ' }
							{ connection.operations?.unreported || 0 }{ ' ' }
							{ __( 'unreported', 'dbvc' ) }
						</dd>
					</dl>
					<p
						className="dbvc-ce__actions"
						style={ { marginTop: 12, flexWrap: 'wrap' } }
					>
						{ [
							[ 'process', __( 'Observe now', 'dbvc' ) ],
							[ 'deliver', __( 'Deliver now', 'dbvc' ) ],
							[ 'poll', __( 'Poll now', 'dbvc' ) ],
							[ 'release', __( 'Run release work', 'dbvc' ) ],
						].map( ( [ key, label ] ) => (
							<button
								key={ key }
								type="button"
								className="btn btn--small"
								disabled={
									!! running ||
									( key !== 'process' && ! enrolled )
								}
								onClick={ () =>
									onRun( { key, label, role: 'connector' } )
								}
							>
								{ label }
							</button>
						) ) }
					</p>
				</div>
			</div>
		</>
	);
}

/* ---------- Settings ---------- */

function Settings( { overview, onSaved, notify } ) {
	const connectorSettings = overview?.settings?.connector || {};
	const hubSettings = overview?.settings?.hub || {};
	const [ connectorEnabled, setConnectorEnabled ] = useState(
		connectorSettings.dbvc_addon_connected_environments_enabled === '1'
	);
	const [ applyEnabled, setApplyEnabled ] = useState(
		connectorSettings.dbvc_addon_connected_environments_apply_enabled ===
			'1'
	);
	const [ hubEnabled, setHubEnabled ] = useState(
		hubSettings.dbvc_addon_agency_control_enabled === '1'
	);
	const [ hubUrl, setHubUrl ] = useState(
		overview?.connector?.connection?.hub_url || ''
	);
	const [ token, setToken ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );

	useEffect( () => {
		setConnectorEnabled(
			connectorSettings.dbvc_addon_connected_environments_enabled === '1'
		);
		setApplyEnabled(
			connectorSettings.dbvc_addon_connected_environments_apply_enabled ===
				'1'
		);
		setHubEnabled( hubSettings.dbvc_addon_agency_control_enabled === '1' );
	}, [ overview ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const [ confirmApply, setConfirmApply ] = useState( false );
	const applyTurningOn =
		applyEnabled &&
		connectorSettings.dbvc_addon_connected_environments_apply_enabled !==
			'1';

	const save = async () => {
		if ( applyTurningOn && ! confirmApply ) {
			setConfirmApply( true );
			return;
		}
		setConfirmApply( false );
		setBusy( 'settings' );
		try {
			const result = await apiFetch( {
				path: 'connected-admin/settings',
				method: 'POST',
				data: {
					connector: {
						enabled: connectorEnabled,
						apply_enabled: applyEnabled,
					},
					hub: { enabled: hubEnabled },
				},
			} );
			if ( result.errors && result.errors.length ) {
				notify( 'warning', result.errors.join( ' ' ) );
			} else {
				notify( 'success', __( 'Settings saved.', 'dbvc' ) );
			}
			if ( ! result.page_available ) {
				notify(
					'warning',
					__(
						'Both modules are now off: this page disappears on the next load. Re-enable from Configure → Add-ons.',
						'dbvc'
					)
				);
			}
			onSaved();
		} catch ( error ) {
			notify( 'error', errorMessage( error ) );
		}
		setBusy( '' );
	};

	const enroll = async () => {
		setBusy( 'enroll' );
		try {
			const result = await apiFetch( {
				path: 'connected/enroll',
				method: 'POST',
				data: { hub: hubUrl, token },
			} );
			setToken( '' );
			notify(
				'success',
				sprintf(
					/* translators: placeholders are counts, identifiers or relative times shown in the Connected Environments page */
					__( 'Enrolled as %1$s (%2$s).', 'dbvc' ),
					result.environment_id,
					result.connection_state
				)
			);
			onSaved();
		} catch ( error ) {
			notify( 'error', errorMessage( error ) );
		}
		setBusy( '' );
	};

	const resume = async () => {
		setBusy( 'resume' );
		try {
			const result = await apiFetch( {
				path: 'connected/resume',
				method: 'POST',
				data: {},
			} );
			notify(
				'success',
				sprintf(
					/* translators: placeholders are counts, identifiers or relative times shown in the Connected Environments page */
					__( 'Connection state: %s', 'dbvc' ),
					result.connection_state || JSON.stringify( result )
				)
			);
			onSaved();
		} catch ( error ) {
			notify( 'error', errorMessage( error ) );
		}
		setBusy( '' );
	};

	const enrolled =
		overview?.connector?.identity?.enrollment_state === 'enrolled';
	return (
		<div className="dbvc-ce-grid">
			<div className="dbvc-tools-panel">
				<div className="dbvc-ce-panel__head">
					<h2>{ __( 'Connector', 'dbvc' ) }</h2>
				</div>
				<label
					className="dbvc-ce-check"
					htmlFor="dbvc-ce-connector-enabled"
				>
					<input
						id="dbvc-ce-connector-enabled"
						type="checkbox"
						checked={ connectorEnabled }
						onChange={ ( e ) =>
							setConnectorEnabled( e.target.checked )
						}
					/>
					<span>
						{ __(
							'Enable Connected Environments (connector)',
							'dbvc'
						) }
						<span className="description">
							{ __(
								'Observation, outbox, enrollment, inbox, prepare receipts. Never applies content by itself.',
								'dbvc'
							) }
						</span>
					</span>
				</label>
				<label
					className="dbvc-ce-check"
					htmlFor="dbvc-ce-apply-enabled"
				>
					<input
						id="dbvc-ce-apply-enabled"
						type="checkbox"
						checked={ applyEnabled }
						disabled={ ! connectorEnabled }
						onChange={ ( e ) =>
							setApplyEnabled( e.target.checked )
						}
					/>
					<span>
						{ __(
							'Allow approved releases to be applied here',
							'dbvc'
						) }
						<span className="description">
							{ __(
								"Off by default. Executes only releases the hub approved for this environment, after this site's own prepare receipt, with a conditional write that refuses a container edited in the meantime. Bricks global classes and variables only in this release.",
								'dbvc'
							) }
						</span>
					</span>
				</label>
				{ overview?.roles?.connector && (
					<>
						<h3 style={ { fontSize: 13, margin: '16px 0 8px' } }>
							{ __( 'Enrollment', 'dbvc' ) }
						</h3>
						{ enrolled ? (
							<p
								className="description"
								style={ { margin: '0 0 8px' } }
							>
								{ sprintf(
									/* translators: placeholders are counts, identifiers or relative times shown in the Connected Environments page */
									__(
										'Enrolled as %1$s with %2$s. Enrolling again rotates the epoch and re-emits every object.',
										'dbvc'
									),
									overview.connector.identity.environment_id,
									overview.connector.connection.hub_url
								) }
							</p>
						) : null }
						<div className="dbvc-ce-field">
							<label htmlFor="dbvc-ce-hub-url">
								{ __( 'Hub URL', 'dbvc' ) }
							</label>
							<input
								id="dbvc-ce-hub-url"
								type="url"
								className="regular-text"
								value={ hubUrl }
								onChange={ ( e ) =>
									setHubUrl( e.target.value )
								}
								placeholder="https://studio.example/"
							/>
						</div>
						<div className="dbvc-ce-field">
							<label htmlFor="dbvc-ce-token">
								{ __( 'Invitation token', 'dbvc' ) }
							</label>
							<input
								id="dbvc-ce-token"
								type="password"
								className="regular-text"
								value={ token }
								onChange={ ( e ) => setToken( e.target.value ) }
								autoComplete="off"
								placeholder={ __(
									'pasted once, never shown again',
									'dbvc'
								) }
							/>
						</div>
						<p className="dbvc-ce__actions">
							<button
								type="button"
								className="btn btn--primary"
								disabled={ !! busy || ! hubUrl || ! token }
								onClick={ enroll }
							>
								{ busy === 'enroll'
									? __( 'Enrolling…', 'dbvc' )
									: __( 'Enroll', 'dbvc' ) }
							</button>
							<button
								type="button"
								className="btn btn--ghost"
								disabled={ !! busy || ! enrolled }
								onClick={ resume }
							>
								{ busy === 'resume'
									? __( 'Checking…', 'dbvc' )
									: __( 'Resume connection', 'dbvc' ) }
							</button>
						</p>
					</>
				) }
			</div>
			<div>
				<div className="dbvc-tools-panel">
					<div className="dbvc-ce-panel__head">
						<h2>{ __( 'Hub', 'dbvc' ) }</h2>
					</div>
					<label
						className="dbvc-ce-check"
						htmlFor="dbvc-ce-hub-enabled"
					>
						<input
							id="dbvc-ce-hub-enabled"
							type="checkbox"
							checked={ hubEnabled }
							onChange={ ( e ) =>
								setHubEnabled( e.target.checked )
							}
						/>
						<span>
							{ __(
								'Enable Agency Control (studio hub)',
								'dbvc'
							) }
							<span className="description">
								{ __(
									'Enrollment invitations, application-password principals per environment, durable receipt, routing, comparison, framework review, releases and approvals. Needs neither Bricks nor the connector.',
									'dbvc'
								) }
							</span>
						</span>
					</label>
					{ overview?.hub && (
						<dl className="dbvc-ce-kv">
							<dt>{ __( 'Schema', 'dbvc' ) }</dt>
							<dd>
								v{ overview.hub.schema?.version } ·{ ' ' }
								{ overview.hub.schema?.transactional === false
									? __( 'non-transactional storage', 'dbvc' )
									: __( 'transactional', 'dbvc' ) }
							</dd>
							<dt>{ __( 'Freshness window', 'dbvc' ) }</dt>
							<dd>
								{ Math.round(
									( overview.hub.freshness_seconds ||
										86400 ) / 3600
								) }{ ' ' }
								h{ ' ' }
								<span className="subtle">
									(
									<code>
										dbvc_agency_control_freshness_seconds
									</code>
									)
								</span>
							</dd>
						</dl>
					) }
				</div>
				<div className="dbvc-tools-panel" style={ { marginTop: 12 } }>
					<div className="dbvc-ce-panel__head">
						<h2>{ __( 'Emergency stop', 'dbvc' ) }</h2>
					</div>
					<p className="description" style={ { margin: 0 } }>
						{ __( 'Define', 'dbvc' ) }{ ' ' }
						<code>DBVC_CONNECTED_EMERGENCY_DISABLE</code> /{ ' ' }
						<code>DBVC_AGENCY_EMERGENCY_DISABLE</code>{ ' ' }
						{ __(
							'in wp-config.php to stop either module regardless of these settings.',
							'dbvc'
						) }
					</p>
				</div>
				{ confirmApply && (
					<div
						className="dbvc-inline-notice dbvc-inline-notice--warning"
						role="alertdialog"
						aria-labelledby="dbvc-ce-confirm-apply"
					>
						<p id="dbvc-ce-confirm-apply">
							<strong>{ __( 'Turn on apply?', 'dbvc' ) }</strong>{ ' ' }
							{ __(
								'Releases the studio hub approves for this environment will be executed on its next poll: conditional, journalled, verified writes to Bricks global classes and variables. Nothing runs without an explicit approval on the hub.',
								'dbvc'
							) }
						</p>
						<p className="dbvc-ce__actions">
							<button
								type="button"
								className="btn btn--primary btn--small"
								onClick={ save }
							>
								{ __( 'Confirm and save', 'dbvc' ) }
							</button>
							<button
								type="button"
								className="btn btn--ghost btn--small"
								onClick={ () => setConfirmApply( false ) }
							>
								{ __( 'Cancel', 'dbvc' ) }
							</button>
						</p>
					</div>
				) }
				<p className="dbvc-ce__actions" style={ { marginTop: 12 } }>
					<button
						type="button"
						className="btn btn--primary"
						disabled={ !! busy || confirmApply }
						onClick={ save }
					>
						{ busy === 'settings'
							? __( 'Saving…', 'dbvc' )
							: __( 'Save settings', 'dbvc' ) }
					</button>
				</p>
			</div>
		</div>
	);
}

/* ---------- Rollouts ---------- */

function cohortLabel( index ) {
	return index === 0
		? __( 'Canary', 'dbvc' )
		: sprintf(
				/* translators: %d: cohort number (2, 3, …) */
				__( 'Cohort %d', 'dbvc' ),
				index + 1
		  );
}

function Rollouts( { environments, preset, notify, onChanged } ) {
	const [ rollouts, setRollouts ] = useState( null );
	const [ releasesList, setReleasesList ] = useState( [] );
	const [ selected, setSelected ] = useState( '' );
	const [ detail, setDetail ] = useState( null ); // { rollout, targets }
	const [ busy, setBusy ] = useState( '' );
	const [ composing, setComposing ] = useState( false );
	const [ composeRelease, setComposeRelease ] = useState( preset || '' );
	const [ plan, setPlan ] = useState( {} ); // environment_id → cohort index (string)
	const [ note, setNote ] = useState( '' );
	const [ confirmWithdraw, setConfirmWithdraw ] = useState( false );
	const [ evidence, setEvidence ] = useState( {} ); // approval_uid → inspector payload|undefined
	const [ pruning, setPruning ] = useState( false );
	const [ pruneDays, setPruneDays ] = useState( 30 );
	const [ pruneInfo, setPruneInfo ] = useState( null ); // dry-run result

	const enabled = useMemo(
		() => ( environments || [] ).filter( ( e ) => e.status === 'enabled' ),
		[ environments ]
	);
	const sealed = useMemo(
		() => releasesList.filter( ( r ) => r.state === 'sealed' ),
		[ releasesList ]
	);
	const composeSource = useMemo(
		() =>
			sealed.find( ( r ) => r.release_uid === composeRelease )?.source ||
			'',
		[ sealed, composeRelease ]
	);
	const assignable = useMemo(
		() => enabled.filter( ( e ) => e.environment_id !== composeSource ),
		[ enabled, composeSource ]
	);

	const loadList = useCallback( async () => {
		try {
			const [ list, rels ] = await Promise.all( [
				apiFetch( { path: 'agency/rollouts?limit=200' } ),
				apiFetch( { path: 'agency/releases?limit=200' } ),
			] );
			setRollouts( list.rollouts || [] );
			setReleasesList( rels.releases || [] );
		} catch ( err ) {
			notify( 'error', errorMessage( err ) );
		}
	}, [ notify ] );

	const loadDetail = useCallback(
		async ( uid ) => {
			if ( ! uid ) {
				setDetail( null );
				return;
			}
			try {
				setDetail(
					await apiFetch( {
						path: `agency/rollouts?rollout=${ encodeURIComponent(
							uid
						) }`,
					} )
				);
			} catch ( err ) {
				notify( 'error', errorMessage( err ) );
			}
		},
		[ notify ]
	);

	useEffect( () => {
		loadList();
	}, [ loadList ] );
	useEffect( () => {
		loadDetail( selected );
	}, [ selected, loadDetail ] );
	useEffect( () => {
		if ( preset ) {
			setComposing( true );
			setComposeRelease( preset );
		}
	}, [ preset ] );

	const refresh = async () => {
		await loadList();
		await loadDetail( selected );
		onChanged();
	};

	const run = async ( key, path, data, success, after ) => {
		setBusy( key );
		try {
			const res = await apiFetch( { path, method: 'POST', data } );
			notify( 'success', success( res ) );
			if ( after ) {
				after( res );
			}
			await refresh();
		} catch ( err ) {
			notify( 'error', errorMessage( err ) );
		}
		setBusy( '' );
	};

	const cohortsFromPlan = () => {
		const byIndex = {};
		Object.keys( plan ).forEach( ( env ) => {
			const idx = plan[ env ];
			if ( idx === '' || idx === undefined || idx === null ) {
				return;
			}
			const i = Number( idx );
			byIndex[ i ] = byIndex[ i ] || [];
			byIndex[ i ].push( env );
		} );
		return Object.keys( byIndex )
			.map( Number )
			.sort( ( a, b ) => a - b )
			.map( ( i ) => byIndex[ i ] );
	};

	const cohorts = cohortsFromPlan();
	const canCreate = !! composeRelease && cohorts.length > 0;

	const create = () =>
		run(
			'create',
			'agency/rollout-create',
			{ release: composeRelease, cohorts, note },
			( res ) =>
				sprintf(
					/* translators: %s: rollout id */
					__( 'Rollout %s created.', 'dbvc' ),
					res.rollout?.rollout_uid || ''
				),
			( res ) => {
				setComposing( false );
				setPlan( {} );
				setNote( '' );
				setComposeRelease( '' );
				setSelected( res.rollout?.rollout_uid || '' );
			}
		);

	const advance = () =>
		run(
			`advance:${ selected }`,
			'agency/rollout-advance',
			{ rollout: selected },
			() => __( 'Rollout advanced.', 'dbvc' )
		);
	const pause = () =>
		run(
			`pause:${ selected }`,
			'agency/rollout-pause',
			{ rollout: selected },
			() => __( 'Rollout paused; later cohorts will not start.', 'dbvc' )
		);
	const resume = () =>
		run(
			`resume:${ selected }`,
			'agency/rollout-resume',
			{ rollout: selected },
			() => __( 'Rollout resumed.', 'dbvc' )
		);
	const withdraw = () =>
		run(
			`withdraw:${ selected }`,
			'agency/rollout-withdraw',
			{ rollout: selected },
			() => __( 'Rollout withdrawn.', 'dbvc' ),
			() => setConfirmWithdraw( false )
		);
	const retry = ( target ) =>
		run(
			`retry:${ target }`,
			'agency/rollout-retry',
			{ rollout: selected, target },
			() =>
				sprintf(
					/* translators: %s: target environment id */
					__(
						'Target %s reset to pending; resume to re-drive it.',
						'dbvc'
					),
					target
				)
		);

	const toggleEvidence = async ( approvalUid ) => {
		if ( ! approvalUid ) {
			return;
		}
		if ( evidence[ approvalUid ] !== undefined ) {
			setEvidence( ( e ) => ( { ...e, [ approvalUid ]: undefined } ) );
			return;
		}
		try {
			const full = await apiFetch( {
				path: `agency/approvals?approval=${ encodeURIComponent(
					approvalUid
				) }`,
			} );
			setEvidence( ( e ) => ( { ...e, [ approvalUid ]: full } ) );
		} catch ( err ) {
			notify( 'error', errorMessage( err ) );
		}
	};

	const previewPrune = async ( days ) => {
		try {
			setPruneInfo(
				await apiFetch( {
					path: 'agency/rollout-prune',
					method: 'POST',
					data: { days: Number( days ), dry_run: true },
				} )
			);
		} catch ( err ) {
			notify( 'error', errorMessage( err ) );
		}
	};
	const openPrune = () => {
		setPruning( true );
		setPruneInfo( null );
		previewPrune( pruneDays );
	};
	const doPrune = async () => {
		setBusy( 'prune' );
		try {
			const res = await apiFetch( {
				path: 'agency/rollout-prune',
				method: 'POST',
				data: { days: Number( pruneDays ), dry_run: false },
			} );
			notify(
				'success',
				sprintf(
					/* translators: %d: number of rollouts removed */
					__( 'Pruned %d finished rollout(s).', 'dbvc' ),
					res.pruned || 0
				)
			);
			setPruning( false );
			setPruneInfo( null );
			setSelected( '' );
			setDetail( null );
			await loadList();
			onChanged();
		} catch ( err ) {
			notify( 'error', errorMessage( err ) );
		}
		setBusy( '' );
	};

	if ( ! rollouts ) {
		return <p className="dbvc-ce-loading">{ __( 'Loading…', 'dbvc' ) }</p>;
	}

	const rollout = detail?.rollout || null;
	const targets = detail?.targets || [];
	const byCohort = {};
	targets.forEach( ( t ) => {
		byCohort[ t.cohort ] = byCohort[ t.cohort ] || [];
		byCohort[ t.cohort ].push( t );
	} );
	const cohortIndexes = Object.keys( byCohort )
		.map( Number )
		.sort( ( a, b ) => a - b );

	return (
		<div className="dbvc-ce-grid dbvc-ce-grid--wide">
			<div className="dbvc-tools-panel">
				<div className="dbvc-ce-panel__head">
					<h2>{ __( 'Rollouts', 'dbvc' ) }</h2>
					<div className="dbvc-ce-actions">
						<button
							type="button"
							className="button"
							onClick={ openPrune }
						>
							{ __( 'Prune finished…', 'dbvc' ) }
						</button>
						<button
							type="button"
							className="button"
							onClick={ () => setComposing( ( c ) => ! c ) }
							aria-expanded={ composing }
						>
							{ composing
								? __( 'Close', 'dbvc' )
								: __( 'New rollout…', 'dbvc' ) }
						</button>
					</div>
				</div>
				<p className="dbvc-ce-hint">
					{ __(
						'A fleet rollout stages one sealed release across ordered cohorts of targets — the canary first. Each target is an ordinary single-environment operation; a cohort opens the next only once every target verifies, and one failure pauses the rest. It is not one distributed transaction.',
						'dbvc'
					) }
				</p>
				{ composing && (
					<div className="dbvc-ce-rollout-composer">
						<div className="dbvc-ce-field">
							<label htmlFor="dbvc-ce-rollout-release">
								{ __( 'Sealed release', 'dbvc' ) }
							</label>
							<select
								id="dbvc-ce-rollout-release"
								value={ composeRelease }
								onChange={ ( e ) =>
									setComposeRelease( e.target.value )
								}
							>
								<option value="">
									{ __( 'Select a release…', 'dbvc' ) }
								</option>
								{ sealed.map( ( r ) => (
									<option
										key={ r.release_uid }
										value={ r.release_uid }
									>
										{ `${ r.release_uid } · ${ r.source } · ${ r.items } item(s)` }
									</option>
								) ) }
							</select>
						</div>
						{ sealed.length === 0 && (
							<p className="dbvc-ce-empty">
								{ __(
									'No sealed releases. Seal a release in Releases first.',
									'dbvc'
								) }
							</p>
						) }
						{ !! composeRelease && (
							<>
								<p className="dbvc-ce-hint">
									{ __(
										'Assign each target to a cohort. Canary runs first; higher cohorts run in order, each gated on the previous. Leave a target Excluded to omit it.',
										'dbvc'
									) }
								</p>
								<table className="widefat striped">
									<thead>
										<tr>
											<th>{ __( 'Target', 'dbvc' ) }</th>
											<th>{ __( 'Cohort', 'dbvc' ) }</th>
										</tr>
									</thead>
									<tbody>
										{ assignable.length === 0 && (
											<tr>
												<td colSpan={ 2 }>
													<span className="dbvc-ce-empty">
														{ __(
															'No enabled targets other than the release source.',
															'dbvc'
														) }
													</span>
												</td>
											</tr>
										) }
										{ assignable.map( ( env ) => (
											<tr key={ env.environment_id }>
												<td>
													<code>
														{ env.environment_id }
													</code>
													{ env.label ? (
														<span className="dbvc-ce-muted">
															{ ' ' }
															{ env.label }
														</span>
													) : null }
												</td>
												<td>
													<select
														value={
															plan[
																env
																	.environment_id
															] ?? ''
														}
														onChange={ ( e ) =>
															setPlan(
																( p ) => ( {
																	...p,
																	[ env.environment_id ]:
																		e.target
																			.value,
																} )
															)
														}
													>
														<option value="">
															{ __(
																'Excluded',
																'dbvc'
															) }
														</option>
														{ [ 0, 1, 2, 3, 4 ].map(
															( i ) => (
																<option
																	key={ i }
																	value={ i }
																>
																	{ cohortLabel(
																		i
																	) }
																</option>
															)
														) }
													</select>
												</td>
											</tr>
										) ) }
									</tbody>
								</table>
								<div className="dbvc-ce-field">
									<label htmlFor="dbvc-ce-rollout-note">
										{ __( 'Note (optional)', 'dbvc' ) }
									</label>
									<input
										id="dbvc-ce-rollout-note"
										type="text"
										value={ note }
										maxLength={ 191 }
										onChange={ ( e ) =>
											setNote( e.target.value )
										}
									/>
								</div>
								<div className="dbvc-ce-plan-summary">
									{ cohorts.length === 0 ? (
										<span className="dbvc-ce-empty">
											{ __(
												'Assign at least one target (the canary) to a cohort.',
												'dbvc'
											) }
										</span>
									) : (
										<span>
											{ sprintf(
												/* translators: 1: cohort count, 2: target count */
												__(
													'%1$d cohort(s), %2$d target(s).',
													'dbvc'
												),
												cohorts.length,
												cohorts.reduce(
													( n, c ) => n + c.length,
													0
												)
											) }
										</span>
									) }
									<button
										type="button"
										className="button button-primary"
										disabled={
											! canCreate || busy === 'create'
										}
										onClick={ create }
									>
										{ busy === 'create'
											? __( 'Creating…', 'dbvc' )
											: __( 'Create rollout', 'dbvc' ) }
									</button>
								</div>
							</>
						) }
					</div>
				) }
				{ rollouts.length === 0 ? (
					<p className="dbvc-ce-empty">
						{ __(
							'No rollouts yet. Create one from a sealed release, or use wp dbvc agency rollout-create.',
							'dbvc'
						) }
					</p>
				) : (
					<table className="widefat striped">
						<thead>
							<tr>
								<th>{ __( 'Rollout', 'dbvc' ) }</th>
								<th>{ __( 'State', 'dbvc' ) }</th>
								<th>{ __( 'Progress', 'dbvc' ) }</th>
								<th className="num">
									{ __( 'Targets', 'dbvc' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ rollouts.map( ( r ) => (
								<tr
									key={ r.rollout_uid }
									className={
										r.rollout_uid === selected
											? 'dbvc-ce-row--active'
											: undefined
									}
								>
									<td>
										<button
											type="button"
											className="dbvc-ce-linkbtn"
											onClick={ () =>
												setSelected( r.rollout_uid )
											}
										>
											{ r.rollout_uid }
										</button>
										<span className="dbvc-ce-muted">
											{ r.release_uid }
										</span>
									</td>
									<td>
										<Badge state={ r.state }>
											{ r.state }
										</Badge>
									</td>
									<td>
										{ sprintf(
											/* translators: 1: current cohort (1-based), 2: cohort count */
											__( 'Cohort %1$d of %2$d', 'dbvc' ),
											Math.min( r.cohort + 1, r.cohorts ),
											r.cohorts
										) }
									</td>
									<td className="num">{ r.targets }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
			</div>
			<div className="dbvc-tools-panel">
				{ ! rollout ? (
					<p className="dbvc-ce-empty">
						{ __(
							'Select a rollout to review its cohorts and outcomes.',
							'dbvc'
						) }
					</p>
				) : (
					<>
						<div className="dbvc-ce-panel__head">
							<h2>{ rollout.rollout_uid }</h2>
							<Badge state={ rollout.state }>
								{ rollout.state }
							</Badge>
						</div>
						<dl className="dbvc-ce-kv">
							<div>
								<dt>{ __( 'Release', 'dbvc' ) }</dt>
								<dd>
									<code>{ rollout.release_uid }</code>
								</dd>
							</div>
							<div>
								<dt>{ __( 'Source', 'dbvc' ) }</dt>
								<dd>
									<code>{ rollout.source }</code>
								</dd>
							</div>
							{ !! rollout.note && (
								<div>
									<dt>{ __( 'Note', 'dbvc' ) }</dt>
									<dd>{ rollout.note }</dd>
								</div>
							) }
							{ !! rollout.paused_reason && (
								<div>
									<dt>{ __( 'Paused', 'dbvc' ) }</dt>
									<dd>{ rollout.paused_reason }</dd>
								</div>
							) }
						</dl>
						<div className="dbvc-ce-actions">
							{ rollout.state === 'running' && (
								<button
									type="button"
									className="button button-primary"
									disabled={
										busy === `advance:${ selected }`
									}
									onClick={ advance }
								>
									{ __( 'Advance', 'dbvc' ) }
								</button>
							) }
							{ rollout.state === 'running' && (
								<button
									type="button"
									className="button"
									disabled={ busy === `pause:${ selected }` }
									onClick={ pause }
								>
									{ __( 'Pause', 'dbvc' ) }
								</button>
							) }
							{ rollout.state === 'paused' && (
								<button
									type="button"
									className="button button-primary"
									disabled={ busy === `resume:${ selected }` }
									onClick={ resume }
								>
									{ __( 'Resume', 'dbvc' ) }
								</button>
							) }
							{ [ 'running', 'paused', 'failed' ].includes(
								rollout.state
							) && (
								<button
									type="button"
									className="button button-link-delete"
									onClick={ () => setConfirmWithdraw( true ) }
								>
									{ __( 'Withdraw…', 'dbvc' ) }
								</button>
							) }
						</div>
						{ rollout.state === 'running' && (
							<p className="dbvc-ce-hint">
								{ __(
									'Advance is idempotent: it requests prepares, approves received receipts and reads outcomes. Targets execute on their own poll, so re-Advance as they report until the cohort verifies.',
									'dbvc'
								) }
							</p>
						) }
						{ cohortIndexes.map( ( ci ) => (
							<div key={ ci } className="dbvc-ce-cohort">
								<h3>
									{ cohortLabel( ci ) }
									{ ci === rollout.cohort &&
										rollout.state === 'running' && (
											<span className="dbvc-ce-muted">
												{ ' ' }
												{ __( '· current', 'dbvc' ) }
											</span>
										) }
								</h3>
								<table className="widefat striped">
									<thead>
										<tr>
											<th>{ __( 'Target', 'dbvc' ) }</th>
											<th>{ __( 'State', 'dbvc' ) }</th>
											<th>{ __( 'Outcome', 'dbvc' ) }</th>
											<th>
												{ __( 'Evidence', 'dbvc' ) }
											</th>
										</tr>
									</thead>
									<tbody>
										{ byCohort[ ci ].map( ( t ) => {
											const open =
												evidence[ t.approval_uid ] !==
												undefined;
											return (
												<Fragment
													key={
														t.target_environment_id
													}
												>
													<tr>
														<td>
															<code>
																{
																	t.target_environment_id
																}
															</code>
															{ t.detail ? (
																<span className="dbvc-ce-muted">
																	{ ' ' }
																	{ t.detail }
																</span>
															) : null }
														</td>
														<td>
															<Badge
																state={
																	t.state
																}
															>
																{ t.state }
															</Badge>
														</td>
														<td>
															{ t.outcome ? (
																<Badge
																	state={
																		t.outcome
																	}
																>
																	{
																		t.outcome
																	}
																</Badge>
															) : (
																<span className="dbvc-ce-muted">
																	—
																</span>
															) }
														</td>
														<td className="dbvc-ce-actions">
															{ t.approval_uid ? (
																<button
																	type="button"
																	className="button-link"
																	aria-expanded={
																		open
																	}
																	onClick={ () =>
																		toggleEvidence(
																			t.approval_uid
																		)
																	}
																>
																	{ open
																		? __(
																				'Hide receipt',
																				'dbvc'
																		  )
																		: __(
																				'Receipt',
																				'dbvc'
																		  ) }
																</button>
															) : (
																<span className="dbvc-ce-muted">
																	—
																</span>
															) }
															{ t.state ===
																'failed' &&
																[
																	'running',
																	'paused',
																].includes(
																	rollout.state
																) && (
																	<button
																		type="button"
																		className="button-link"
																		disabled={
																			busy ===
																			`retry:${ t.target_environment_id }`
																		}
																		onClick={ () =>
																			retry(
																				t.target_environment_id
																			)
																		}
																	>
																		{ __(
																			'Retry',
																			'dbvc'
																		) }
																	</button>
																) }
														</td>
													</tr>
													{ open && (
														<tr className="dbvc-ce-evidence">
															<td colSpan={ 4 }>
																<RolloutEvidence
																	payload={
																		evidence[
																			t
																				.approval_uid
																		]
																	}
																/>
															</td>
														</tr>
													) }
												</Fragment>
											);
										} ) }
									</tbody>
								</table>
							</div>
						) ) }
					</>
				) }
			</div>
			{ confirmWithdraw && rollout && (
				<Modal
					title={ __( 'Withdraw rollout', 'dbvc' ) }
					onClose={ () => setConfirmWithdraw( false ) }
				>
					<p>
						{ __(
							'Withdrawing closes this rollout. Targets already applied are not reverted — use a rollback for that.',
							'dbvc'
						) }
					</p>
					<div className="dbvc-ce-actions">
						<button
							type="button"
							className="button"
							onClick={ () => setConfirmWithdraw( false ) }
						>
							{ __( 'Cancel', 'dbvc' ) }
						</button>
						<button
							type="button"
							className="button button-primary"
							disabled={ busy === `withdraw:${ selected }` }
							onClick={ withdraw }
						>
							{ __( 'Withdraw rollout', 'dbvc' ) }
						</button>
					</div>
				</Modal>
			) }
			{ pruning && (
				<Modal
					title={ __( 'Prune finished rollouts', 'dbvc' ) }
					onClose={ () => setPruning( false ) }
				>
					<p>
						{ __(
							'Delete finished rollouts (completed, withdrawn or failed) and their target rows once they are older than the window. Running and paused rollouts are never removed; applied targets are unaffected.',
							'dbvc'
						) }
					</p>
					<div className="dbvc-ce-field">
						<label htmlFor="dbvc-ce-rollout-prune-days">
							{ __( 'Older than (days)', 'dbvc' ) }
						</label>
						<input
							id="dbvc-ce-rollout-prune-days"
							type="number"
							min="0"
							value={ pruneDays }
							onChange={ ( e ) => {
								setPruneDays( e.target.value );
								setPruneInfo( null );
							} }
						/>
					</div>
					<p className="dbvc-ce-hint" aria-live="polite">
						{ pruneInfo
							? sprintf(
									/* translators: 1: count, 2: days */
									__(
										'%1$d finished rollout(s) older than %2$d day(s) would be removed.',
										'dbvc'
									),
									pruneInfo.prunable || 0,
									pruneInfo.older_than_days || 0
							  )
							: __(
									'Preview to see how many would be removed.',
									'dbvc'
							  ) }
					</p>
					<div className="dbvc-ce-actions">
						<button
							type="button"
							className="button"
							onClick={ () => previewPrune( pruneDays ) }
						>
							{ __( 'Preview', 'dbvc' ) }
						</button>
						<button
							type="button"
							className="button button-primary"
							disabled={
								busy === 'prune' ||
								! pruneInfo ||
								( pruneInfo.prunable || 0 ) === 0
							}
							onClick={ doPrune }
						>
							{ busy === 'prune'
								? __( 'Pruning…', 'dbvc' )
								: __( 'Prune', 'dbvc' ) }
						</button>
					</div>
				</Modal>
			) }
		</div>
	);
}

function RolloutEvidence( { payload } ) {
	if ( payload === null ) {
		return <p className="dbvc-ce-loading">{ __( 'Loading…', 'dbvc' ) }</p>;
	}
	const receipt = payload.execution_receipt || null;
	const warnings = ( receipt && receipt.warnings ) || [];
	return (
		<div className="dbvc-ce-evidence__body">
			<dl className="dbvc-ce-kv">
				<div>
					<dt>{ __( 'Execution outcome', 'dbvc' ) }</dt>
					<dd>
						<Badge state={ payload.execution_outcome || 'unknown' }>
							{ payload.execution_outcome ||
								__( 'pending', 'dbvc' ) }
						</Badge>
					</dd>
				</div>
				<div>
					<dt>{ __( 'Operation', 'dbvc' ) }</dt>
					<dd>
						<code>{ payload.operation_id }</code>
					</dd>
				</div>
				<div>
					<dt>{ __( 'Executed', 'dbvc' ) }</dt>
					<dd>{ payload.executed_at || '—' }</dd>
				</div>
			</dl>
			<div className="dbvc-ce-evidence__renders">
				<h4>{ __( 'Rendering evidence', 'dbvc' ) }</h4>
				{ warnings.length === 0 ? (
					<p className="dbvc-ce-muted">
						{ receipt
							? __(
									'No render warnings reported for this target.',
									'dbvc'
							  )
							: __(
									'No execution receipt yet — the target has not reported.',
									'dbvc'
							  ) }
					</p>
				) : (
					<ul className="dbvc-ce-warnings">
						{ warnings.map( ( w, i ) => (
							<li key={ i }>{ w }</li>
						) ) }
					</ul>
				) }
			</div>
		</div>
	);
}

/* ---------- App ---------- */

function App() {
	const [ overview, setOverview ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ view, setView ] = useState( 'overview' );
	const [ comparePreset, setComparePreset ] = useState( null );
	const [ releasePreset, setReleasePreset ] = useState( null );
	const [ rolloutPreset, setRolloutPreset ] = useState( '' );
	const [ toasts, setToasts ] = useState( [] );
	const [ live, setLive ] = useState( '' );
	const [ running, setRunning ] = useState( '' );

	const notify = useCallback( ( tone, message ) => {
		const id = Date.now() + Math.random();
		setToasts( ( list ) => [ ...list, { id, tone, message } ] );
		setLive( message );
		window.setTimeout(
			() => setToasts( ( list ) => list.filter( ( t ) => t.id !== id ) ),
			8000
		);
	}, [] );

	const load = useCallback( async () => {
		setLoading( true );
		try {
			const data = await apiFetch( { path: 'connected-admin/overview' } );
			setOverview( data );
			setError( null );
		} catch ( err ) {
			setError( err );
		}
		setLoading( false );
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	const runners = useMemo( () => {
		const list = [];
		if ( overview?.roles?.connector ) {
			list.push(
				{
					key: 'process',
					label: __( 'Observe now (process dirty markers)', 'dbvc' ),
					role: 'connector',
				},
				{
					key: 'deliver',
					label: __( 'Deliver outbox now', 'dbvc' ),
					role: 'connector',
				},
				{
					key: 'poll',
					label: __( 'Poll hub inbox now', 'dbvc' ),
					role: 'connector',
				},
				{
					key: 'release',
					label: __( 'Run release work now', 'dbvc' ),
					role: 'connector',
				}
			);
		}
		if ( overview?.roles?.hub ) {
			list.push(
				{
					key: 'route',
					label: __( 'Route pending events (hub)', 'dbvc' ),
					role: 'hub',
				},
				{
					key: 'review_classify',
					label: __( 'Classify review items (hub)', 'dbvc' ),
					role: 'hub',
				}
			);
		}
		return list;
	}, [ overview ] );

	const run = useCallback(
		async ( runner ) => {
			setRunning( runner.label );
			try {
				const path =
					runner.path ||
					( runner.role === 'hub'
						? `agency/${ runner.key.replace( '_', '-' ) }`
						: `connected/run/${ runner.key }` );
				const result = await apiFetch( {
					path,
					method: 'POST',
					data: runner.data || {},
				} );
				const summary = Object.entries( result || {} )
					.filter(
						( [ k, v ] ) =>
							[ 'number', 'string', 'boolean' ].includes(
								typeof v
							) &&
							! [
								'context',
								'started_at',
								'finished_at',
								'error',
							].includes( k ) &&
							v !== null &&
							v !== ''
					)
					.slice( 0, 8 )
					.map( ( [ k, v ] ) => `${ k }: ${ v }` )
					.join( ' · ' );
				notify(
					result && result.blocked ? 'warning' : 'success',
					`${ runner.label } — ${ summary || __( 'done', 'dbvc' ) }${
						result && result.blocked
							? ` · ${ __( 'blocked', 'dbvc' ) }: ${
									result.blocked
							  }`
							: ''
					}`
				);
				if ( typeof runner.after === 'function' ) {
					await runner.after();
				}
				load();
			} catch ( err ) {
				notify( 'error', errorMessage( err ) );
			}
			setRunning( '' );
		},
		[ load, notify ]
	);

	const sections = [
		{ key: 'overview', label: __( 'Overview', 'dbvc' ) },
		...( overview?.roles?.hub
			? [
					{
						key: 'environments',
						label: __( 'Environments', 'dbvc' ),
					},
					{ key: 'compare', label: __( 'Compare', 'dbvc' ) },
					{ key: 'framework', label: __( 'Framework', 'dbvc' ) },
					{ key: 'releases', label: __( 'Releases', 'dbvc' ) },
					{ key: 'rollouts', label: __( 'Rollouts', 'dbvc' ) },
			  ]
			: [] ),
		...( overview?.roles?.connector
			? [ { key: 'activity', label: __( 'Activity', 'dbvc' ) } ]
			: [] ),
		{ key: 'settings', label: __( 'Settings', 'dbvc' ) },
	];

	return (
		<>
			<Header
				overview={ overview }
				loading={ loading }
				onRefresh={ load }
				runners={ runners }
				onRun={ run }
				running={ running }
			/>
			<nav
				className="dbvc-section-nav"
				aria-label={ __( 'Sections', 'dbvc' ) }
			>
				{ sections.map( ( section ) => (
					<button
						key={ section.key }
						type="button"
						aria-current={
							view === section.key ? 'page' : undefined
						}
						onClick={ () => setView( section.key ) }
					>
						{ section.label }
					</button>
				) ) }
			</nav>
			{ error && (
				<div
					className="dbvc-inline-notice dbvc-inline-notice--error"
					role="alert"
				>
					<p>{ errorMessage( error ) }</p>
				</div>
			) }
			{ ! overview && loading && (
				<p className="dbvc-ce-loading">{ __( 'Loading…', 'dbvc' ) }</p>
			) }
			{ overview && view === 'overview' && (
				<>
					{ overview.roles.hub && (
						<HubOverview
							hub={ overview.hub }
							onNavigate={ setView }
						/>
					) }
					{ overview.roles.connector && (
						<div
							style={ { marginTop: overview.roles.hub ? 16 : 0 } }
						>
							<ConnectorOverview
								connector={ overview.connector }
								onRun={ run }
								running={ running }
							/>
						</div>
					) }
				</>
			) }
			{ overview && overview.roles.hub && view === 'environments' && (
				<Environments
					hub={ overview.hub }
					notify={ notify }
					onChanged={ load }
					onCompare={ ( source ) => {
						setComparePreset( { source } );
						setView( 'compare' );
					} }
				/>
			) }
			{ overview && overview.roles.hub && view === 'compare' && (
				<Compare
					environments={ overview.hub?.environments || [] }
					preset={ comparePreset }
					notify={ notify }
					onChanged={ load }
					onCreatedRelease={ ( uid ) => {
						setReleasePreset( uid );
						setView( 'releases' );
					} }
				/>
			) }
			{ overview && overview.roles.hub && view === 'framework' && (
				<Framework
					environments={ overview.hub?.environments || [] }
					notify={ notify }
					onChanged={ load }
				/>
			) }
			{ overview && overview.roles.hub && view === 'releases' && (
				<Releases
					environments={ overview.hub?.environments || [] }
					preset={ releasePreset }
					notify={ notify }
					onChanged={ load }
					onRollout={ ( uid ) => {
						setRolloutPreset( uid );
						setView( 'rollouts' );
					} }
				/>
			) }
			{ overview && overview.roles.hub && view === 'rollouts' && (
				<Rollouts
					environments={ overview.hub?.environments || [] }
					preset={ rolloutPreset }
					notify={ notify }
					onChanged={ load }
				/>
			) }
			{ overview && overview.roles.connector && view === 'activity' && (
				<ConnectorActivity
					connector={ overview.connector }
					notify={ notify }
					onRun={ run }
					running={ running }
				/>
			) }
			{ overview && view === 'settings' && (
				<Settings
					overview={ overview }
					onSaved={ load }
					notify={ notify }
				/>
			) }
			<div className="dbvc-ce-live" aria-live="polite" role="status">
				{ live }
			</div>
			<div className="dbvc-ce-toast" aria-hidden={ toasts.length === 0 }>
				{ toasts.map( ( toast ) => (
					<div
						key={ toast.id }
						className={ `dbvc-inline-notice dbvc-inline-notice--${ toast.tone }` }
					>
						<p>{ toast.message }</p>
					</div>
				) ) }
			</div>
		</>
	);
}

const mount = document.getElementById( 'dbvc-connected-app' );
if ( mount ) {
	createRoot( mount ).render( <App /> );
}
