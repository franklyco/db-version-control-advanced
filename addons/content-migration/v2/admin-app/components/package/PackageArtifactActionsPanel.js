const ARTIFACT_ORDER = [
	'manifest',
	'summary',
	'qa',
	'records',
	'media',
	'zip',
];

const sortArtifactEntries = ( artifactActions = {} ) =>
	Object.values( artifactActions ).sort( ( left, right ) => {
		const leftIndex = ARTIFACT_ORDER.indexOf( left?.key || '' );
		const rightIndex = ARTIFACT_ORDER.indexOf( right?.key || '' );

		return (
			( leftIndex === -1 ? Number.MAX_SAFE_INTEGER : leftIndex ) -
			( rightIndex === -1 ? Number.MAX_SAFE_INTEGER : rightIndex )
		);
	} );

export default function PackageArtifactActionsPanel( {
	artifactActions,
	activeArtifactKey,
	onInspectArtifact,
} ) {
	const items = sortArtifactEntries( artifactActions );

	if ( ! items.length ) {
		return null;
	}

	return (
		<article
			className="dbvc-cc-v2-placeholder-card dbvc-cc-v2-placeholder-card--full"
			data-testid="dbvc-cc-v2-package-artifact-actions"
		>
			<p className="dbvc-cc-v2-eyebrow">Package artifact actions</p>
			<h3>Inspect or download package artifacts</h3>
			<p>
				Use the in-app drill-ins for manifest, summary, QA, records, and
				media details. Download the raw artifact files only when you
				need external review or backup.
			</p>
			<div className="dbvc-cc-v2-package-artifact-list">
				{ items.map( ( item ) => {
					const isActive = activeArtifactKey === item.key;
					const inspectLabel = isActive ? 'Inspecting' : 'Inspect';
					const downloadLabel =
						item.key === 'zip' ? 'Download ZIP' : 'Download JSON';

					return (
						<div
							key={ item.key }
							className={ `dbvc-cc-v2-package-artifact-card${
								isActive
									? ' dbvc-cc-v2-package-artifact-card--active'
									: ''
							}` }
						>
							<div>
								<p className="dbvc-cc-v2-eyebrow">
									{ item.label || item.key }
								</p>
								<p>{ item.description || '' }</p>
								<p className="dbvc-cc-v2-table__meta">
									File: { item.fileName || 'Unavailable' }
								</p>
							</div>
							<div className="dbvc-cc-v2-actions dbvc-cc-v2-actions--stack">
								{ item.canInspect ? (
									<button
										type="button"
										className="button"
										data-testid={ `dbvc-cc-v2-package-artifact-inspect-${ item.key }` }
										disabled={ isActive }
										onClick={ () =>
											onInspectArtifact( item.key )
										}
									>
										{ inspectLabel }
									</button>
								) : null }
								{ item.canDownload && item.downloadUrl ? (
									<a
										className="button button-secondary"
										data-testid={ `dbvc-cc-v2-package-artifact-download-${ item.key }` }
										href={ item.downloadUrl }
										download={ item.fileName || undefined }
									>
										{ downloadLabel }
									</a>
								) : null }
							</div>
						</div>
					);
				} ) }
			</div>
		</article>
	);
}
