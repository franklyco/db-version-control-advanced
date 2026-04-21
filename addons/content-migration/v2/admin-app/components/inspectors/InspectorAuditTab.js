const renderObject = ( value ) => (
	<pre className="dbvc-cc-v2-debug-block">
		{ JSON.stringify( value || {}, null, 2 ) }
	</pre>
);

export default function InspectorAuditTab( { detail } ) {
	const audit = detail?.evidence?.audit || {};

	return (
		<div
			className="dbvc-cc-v2-inspector-tab"
			data-testid="dbvc-cc-v2-inspector-audit"
		>
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
