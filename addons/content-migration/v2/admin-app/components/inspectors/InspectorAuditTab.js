const renderObject = ( value ) => (
	<pre className="dbvc-cc-v2-debug-block">
		{ JSON.stringify( value || {}, null, 2 ) }
	</pre>
);

const renderWarningList = ( warnings ) =>
	Array.isArray( warnings ) && warnings.length ? (
		<ul className="dbvc-cc-v2-inspector-list dbvc-cc-v2-inspector-list--compact">
			{ warnings.map( ( warning, index ) => (
				<li key={ `field-context-audit-warning-${ index }` }>
					{ warning?.message || warning?.code || '' }
				</li>
			) ) }
		</ul>
	) : (
		<p className="dbvc-cc-v2-table__meta">No provider warnings.</p>
	);

export default function InspectorAuditTab( { detail } ) {
	const audit = detail?.evidence?.audit || {};
	const fieldContextProvider = audit.fieldContextProvider || {};
	const hasProvider =
		fieldContextProvider && typeof fieldContextProvider === 'object'
			? Object.keys( fieldContextProvider ).length > 0
			: false;

	return (
		<div
			className="dbvc-cc-v2-inspector-tab"
			data-testid="dbvc-cc-v2-inspector-audit"
		>
			<div className="dbvc-cc-v2-placeholder-card">
				<h3>Field Context provider audit</h3>
				{ hasProvider ? (
					<>
						<p>
							Status:{ ' ' }
							<strong>
								{ fieldContextProvider.status || 'unknown' }
							</strong>
						</p>
						<p>
							Provider:{ ' ' }
							{ fieldContextProvider.provider || 'unavailable' } ·
							transport:{ ' ' }
							{ fieldContextProvider.transport || 'n/a' }
						</p>
						<p>
							Contract/schema:{ ' ' }
							{ fieldContextProvider.contract_version || 0 } /{ ' ' }
							{ fieldContextProvider.schema_version || 0 }
						</p>
						<p>
							Source hash:{ ' ' }
							{ fieldContextProvider.source_hash ||
								'unavailable' }
						</p>
						<p>
							Catalog:{ ' ' }
							{ fieldContextProvider.catalog_status || 'unknown' }{ ' ' }
							· resolver:{ ' ' }
							{ fieldContextProvider.resolver_status ||
								'unknown' }
						</p>
						{ renderWarningList( fieldContextProvider.warnings ) }
					</>
				) : (
					<p className="dbvc-cc-v2-table__meta">
						No Field Context provider audit data.
					</p>
				) }
			</div>
			<div className="dbvc-cc-v2-placeholder-card">
				<h3>Artifact references</h3>
				{ renderObject( audit.artifactRefs ) }
			</div>
			<div className="dbvc-cc-v2-placeholder-card">
				<h3>Trace payloads</h3>
				{ renderObject( audit.trace ) }
			</div>
		</div>
	);
}
