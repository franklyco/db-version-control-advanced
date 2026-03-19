export default function RunOverviewWorkspace( { route, onOpenDrawer } ) {
	return (
		<section
			className="dbvc-cc-v2-workspace"
			data-testid="dbvc-cc-v2-workspace-overview"
		>
			<div className="dbvc-cc-v2-workspace__header">
				<div>
					<p className="dbvc-cc-v2-eyebrow">Run Overview</p>
					<h2>{ route.runId || 'journey-demo' }</h2>
				</div>
				<button
					type="button"
					className="button button-secondary"
					onClick={ () => onOpenDrawer( 'overview-home', 'summary' ) }
				>
					Open inspector
				</button>
			</div>

			<div className="dbvc-cc-v2-grid">
				<article className="dbvc-cc-v2-placeholder-card">
					<h3>Current stage</h3>
					<p>Canonical recommendation generation active</p>
				</article>
				<article className="dbvc-cc-v2-placeholder-card">
					<h3>Pipeline progress</h3>
					<p>
						Schema sync, crawl, AI context, mapping, media
						alignment, and transform previews are active.
					</p>
				</article>
				<article className="dbvc-cc-v2-placeholder-card">
					<h3>Next actions</h3>
					<p>
						Phase 6 review surfaces will consume the finalized
						recommendation payloads and exception states.
					</p>
				</article>
			</div>
		</section>
	);
}
