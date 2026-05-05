const renderObject = ( value ) => (
	<pre className="dbvc-cc-v2-debug-block">
		{ JSON.stringify( value || {}, null, 2 ) }
	</pre>
);

const renderProviderSummary = ( provider ) => {
	if ( ! provider || typeof provider !== 'object' ) {
		return null;
	}

	const warnings = Array.isArray( provider.warnings )
		? provider.warnings.filter( Boolean )
		: [];
	const detailBits = [
		provider.provider || '',
		provider.transport || '',
		provider.status || '',
		provider.schema_version ? `Schema ${ provider.schema_version }` : '',
		provider.contract_version
			? `Contract ${ provider.contract_version }`
			: '',
	]
		.filter( Boolean )
		.join( ' · ' );

	return (
		<div className="dbvc-cc-v2-placeholder-card">
			<h3>Field Context provider</h3>
			{ detailBits ? (
				<p>{ detailBits }</p>
			) : (
				<p>No provider metadata.</p>
			) }
			{ provider.source_hash ? (
				<p className="dbvc-cc-v2-table__meta">
					Source hash: { provider.source_hash }
				</p>
			) : null }
			{ provider.site_fingerprint ? (
				<p className="dbvc-cc-v2-table__meta">
					Site fingerprint: { provider.site_fingerprint }
				</p>
			) : null }
			{ warnings.length ? renderObject( warnings ) : null }
		</div>
	);
};

export default function InspectorAuditTab( { detail } ) {
	const audit = detail?.evidence?.audit || {};
	const unresolvedItems = detail?.recommendations?.unresolvedItems || [];

	return (
		<div
			className="dbvc-cc-v2-inspector-tab"
			data-testid="dbvc-cc-v2-inspector-audit"
		>
			{ renderProviderSummary( audit.fieldContextProvider ) }
			<div className="dbvc-cc-v2-placeholder-card">
				<h3>Unresolved items</h3>
				{ renderObject( unresolvedItems ) }
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
