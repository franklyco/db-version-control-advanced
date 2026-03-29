import { useMemo, useState } from '@wordpress/element';

import {
	ADVANCED_FIELD_GROUPS,
	buildRunCreatePayload,
	deriveDomainFromSitemap,
	getInitialRunCreateState,
	normalizeRunCreateBootstrap,
} from '../../workspaces/runs/runCreateFields';

const renderOption = ( option ) => {
	if ( ! option || typeof option !== 'object' ) {
		return null;
	}

	const value =
		typeof option.value === 'string' || typeof option.value === 'number'
			? `${ option.value }`
			: '';
	if ( ! value ) {
		return null;
	}

	const label =
		typeof option.label === 'string' && option.label ? option.label : value;

	return (
		<option key={ value } value={ value }>
			{ label }
		</option>
	);
};

export default function RunCreateForm( {
	error,
	isSubmitting,
	onClearError,
	onSubmit,
	runCreateBootstrap,
} ) {
	const [ isAdvancedOpen, setIsAdvancedOpen ] = useState( false );
	const [ clientError, setClientError ] = useState( '' );
	const [ statusMessage, setStatusMessage ] = useState( '' );
	const { crawlDefaults, optionSets } = useMemo(
		() => normalizeRunCreateBootstrap( runCreateBootstrap ),
		[ runCreateBootstrap ]
	);
	const [ formState, setFormState ] = useState( () =>
		getInitialRunCreateState( crawlDefaults )
	);

	const visibleError = clientError || error;
	const derivedDomain =
		formState.domain.trim() === ''
			? deriveDomainFromSitemap( formState.sitemapUrl )
			: '';

	const updateField = ( fieldName, value ) => {
		setFormState( ( currentState ) => ( {
			...currentState,
			[ fieldName ]: value,
		} ) );
		setClientError( '' );
		setStatusMessage( '' );
		if ( typeof onClearError === 'function' ) {
			onClearError();
		}
	};

	const resetAdvancedDefaults = () => {
		setFormState( ( currentState ) => ( {
			...currentState,
			...crawlDefaults,
		} ) );
		setClientError( '' );
		setStatusMessage(
			'Advanced overrides reset to shared Configure defaults.'
		);
		if ( typeof onClearError === 'function' ) {
			onClearError();
		}
	};

	const handleSubmit = async ( event ) => {
		event.preventDefault();

		const payload = buildRunCreatePayload( formState );
		if ( ! payload.sitemapUrl ) {
			setClientError(
				'Enter a sitemap URL to start a V2 crawl-backed run.'
			);
			return;
		}

		if ( ! payload.domain ) {
			setClientError(
				'Enter a source domain or provide a sitemap URL with a valid host.'
			);
			return;
		}

		setClientError( '' );
		setStatusMessage(
			'Submitting schema sync, crawl, and pipeline startup.'
		);
		const created = await onSubmit( payload );
		if ( created && created.runId ) {
			setStatusMessage(
				'Run created. Review the lifecycle snapshot below or open overview.'
			);
			return;
		}

		setStatusMessage(
			'Run request ended without a confirmed run. Review the failure details below.'
		);
	};

	const renderField = ( field ) => {
		const value = formState[ field.name ];
		const fieldId = `dbvc-cc-v2-run-create-${ field.name }`;
		const fieldClassName = `dbvc-cc-v2-toolbar__field${
			field.fullWidth ? ' dbvc-cc-v2-toolbar__field--full' : ''
		}`;

		if ( field.type === 'checkbox' ) {
			return (
				<div
					key={ field.name }
					className="dbvc-cc-v2-run-create__checkbox"
				>
					<input
						id={ fieldId }
						type="checkbox"
						checked={ !! value }
						onChange={ ( event ) =>
							updateField( field.name, event.target.checked )
						}
						data-testid={ fieldId }
					/>
					<label htmlFor={ fieldId }>{ field.label }</label>
				</div>
			);
		}

		if ( field.type === 'select' ) {
			const options = Array.isArray( optionSets[ field.optionsKey ] )
				? optionSets[ field.optionsKey ]
				: [];

			return (
				<div key={ field.name } className={ fieldClassName }>
					<label htmlFor={ fieldId }>{ field.label }</label>
					<select
						id={ fieldId }
						value={ value }
						onChange={ ( event ) =>
							updateField( field.name, event.target.value )
						}
						data-testid={ fieldId }
					>
						{ options.map( renderOption ) }
					</select>
				</div>
			);
		}

		if ( field.type === 'textarea' ) {
			return (
				<div key={ field.name } className={ fieldClassName }>
					<label htmlFor={ fieldId }>{ field.label }</label>
					<textarea
						id={ fieldId }
						rows="3"
						value={ value }
						onChange={ ( event ) =>
							updateField( field.name, event.target.value )
						}
						data-testid={ fieldId }
					/>
				</div>
			);
		}

		return (
			<div key={ field.name } className={ fieldClassName }>
				<label htmlFor={ fieldId }>{ field.label }</label>
				<input
					id={ fieldId }
					type={ field.type || 'text' }
					min={ field.min }
					max={ field.max }
					step={ field.step }
					value={ value }
					onChange={ ( event ) =>
						updateField( field.name, event.target.value )
					}
					data-testid={ fieldId }
				/>
			</div>
		);
	};

	return (
		<section
			className="dbvc-cc-v2-placeholder-card dbvc-cc-v2-run-create"
			data-testid="dbvc-cc-v2-run-create-form"
		>
			<div className="dbvc-cc-v2-run-create__header">
				<div>
					<p className="dbvc-cc-v2-eyebrow">Start run</p>
					<h3>Start a crawl-backed V2 run</h3>
					<p>
						This is the first V2-native run-start surface. It uses
						the existing `/runs` contract and shared crawl defaults.
					</p>
				</div>

				<div className="dbvc-cc-v2-actions">
					<button
						type="button"
						className="button button-secondary"
						data-testid="dbvc-cc-v2-run-create-advanced-toggle"
						onClick={ () =>
							setIsAdvancedOpen(
								( currentState ) => ! currentState
							)
						}
					>
						{ isAdvancedOpen
							? 'Hide advanced crawl overrides'
							: 'Show advanced crawl overrides' }
					</button>
					<button
						type="button"
						className="button button-secondary"
						onClick={ resetAdvancedDefaults }
						data-testid="dbvc-cc-v2-run-create-reset-overrides"
					>
						Reset advanced defaults
					</button>
				</div>
			</div>

			<form onSubmit={ handleSubmit }>
				<div className="dbvc-cc-v2-form-grid">
					<div className="dbvc-cc-v2-toolbar__field">
						<label htmlFor="dbvc-cc-v2-run-create-domain">
							Source domain
						</label>
						<input
							id="dbvc-cc-v2-run-create-domain"
							type="text"
							required={ ! derivedDomain }
							value={ formState.domain }
							onChange={ ( event ) =>
								updateField( 'domain', event.target.value )
							}
							placeholder="example.com"
							data-testid="dbvc-cc-v2-run-create-domain"
						/>
					</div>
					<div className="dbvc-cc-v2-toolbar__field">
						<label htmlFor="dbvc-cc-v2-run-create-sitemap-url">
							Sitemap URL
						</label>
						<input
							id="dbvc-cc-v2-run-create-sitemap-url"
							type="url"
							required
							value={ formState.sitemapUrl }
							onChange={ ( event ) =>
								updateField( 'sitemapUrl', event.target.value )
							}
							placeholder="https://example.com/sitemap.xml"
							data-testid="dbvc-cc-v2-run-create-sitemap-url"
						/>
					</div>
					<div className="dbvc-cc-v2-toolbar__field">
						<label htmlFor="dbvc-cc-v2-run-create-max-urls">
							Max URLs
						</label>
						<input
							id="dbvc-cc-v2-run-create-max-urls"
							type="number"
							min="1"
							value={ formState.maxUrls }
							onChange={ ( event ) =>
								updateField( 'maxUrls', event.target.value )
							}
							placeholder="Optional"
							data-testid="dbvc-cc-v2-run-create-max-urls"
						/>
					</div>
					<div className="dbvc-cc-v2-run-create__checkbox">
						<input
							id="dbvc-cc-v2-run-create-force-rebuild"
							type="checkbox"
							checked={ !! formState.forceRebuild }
							onChange={ ( event ) =>
								updateField(
									'forceRebuild',
									event.target.checked
								)
							}
							data-testid="dbvc-cc-v2-run-create-force-rebuild"
						/>
						<label htmlFor="dbvc-cc-v2-run-create-force-rebuild">
							Force target schema rebuild before crawl
						</label>
					</div>
				</div>

				{ derivedDomain ? (
					<p
						className="dbvc-cc-v2-run-create__hint"
						data-testid="dbvc-cc-v2-run-create-derived-domain"
					>
						Domain will default to{ ' ' }
						<strong>{ derivedDomain }</strong> from the sitemap URL
						unless you override it.
					</p>
				) : null }

				{ isAdvancedOpen ? (
					<div
						className="dbvc-cc-v2-run-create__advanced"
						data-testid="dbvc-cc-v2-run-create-advanced"
					>
						{ ADVANCED_FIELD_GROUPS.map( ( group ) => (
							<section
								key={ group.title }
								className="dbvc-cc-v2-run-create__group"
							>
								<div className="dbvc-cc-v2-run-create__group-copy">
									<h4>{ group.title }</h4>
									<p>{ group.description }</p>
								</div>
								<div className="dbvc-cc-v2-form-grid">
									{ group.fields.map( renderField ) }
								</div>
							</section>
						) ) }
					</div>
				) : null }

				{ visibleError ? (
					<p
						className="dbvc-cc-v2-run-create__status dbvc-cc-v2-run-create__status--error"
						data-testid="dbvc-cc-v2-run-create-error"
					>
						{ visibleError }
					</p>
				) : null }

				{ statusMessage && ! visibleError ? (
					<p
						className="dbvc-cc-v2-run-create__status"
						data-testid="dbvc-cc-v2-run-create-status"
					>
						{ statusMessage }
					</p>
				) : null }

				<div className="dbvc-cc-v2-actions">
					<button
						type="submit"
						className="button button-primary"
						disabled={ isSubmitting }
						data-testid="dbvc-cc-v2-run-create-submit"
					>
						{ isSubmitting ? 'Starting run…' : 'Start V2 run' }
					</button>
				</div>
			</form>
		</section>
	);
}
