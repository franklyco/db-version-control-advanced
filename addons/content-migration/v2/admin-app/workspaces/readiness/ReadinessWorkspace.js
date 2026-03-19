import ReadinessIssuesList from '../../components/readiness/ReadinessIssuesList';
import ReadinessPagesTable from '../../components/readiness/ReadinessPagesTable';
import ReadinessSummaryCards from '../../components/readiness/ReadinessSummaryCards';
import useRunReadiness from '../../hooks/useRunReadiness';

export default function ReadinessWorkspace( {
	refreshToken,
	route,
	onOpenDrawer,
} ) {
	const {
		readinessStatus,
		summary,
		blockingIssues,
		warnings,
		pageReports,
		schemaFingerprint,
		isLoading,
		error,
	} = useRunReadiness( route.runId, refreshToken );

	return (
		<section
			className="dbvc-cc-v2-workspace"
			data-testid="dbvc-cc-v2-workspace-readiness"
		>
			<div className="dbvc-cc-v2-workspace__header">
				<div>
					<p className="dbvc-cc-v2-eyebrow">Readiness Workspace</p>
					<h2>{ route.runId || 'journey-demo' }</h2>
				</div>
			</div>

			{ ! route.runId ? (
				<div className="dbvc-cc-v2-placeholder-card">
					<h3>Select a run</h3>
					<p>
						The readiness workspace needs a V2 run before it can
						load QA summaries.
					</p>
				</div>
			) : null }

			{ route.runId && isLoading ? (
				<div className="dbvc-cc-v2-placeholder-card">
					<p>Loading readiness and QA reports.</p>
				</div>
			) : null }

			{ route.runId && error ? (
				<div className="dbvc-cc-v2-placeholder-card">
					<p>{ error }</p>
				</div>
			) : null }

			{ route.runId && ! isLoading && ! error ? (
				<>
					<ReadinessSummaryCards
						readinessStatus={ readinessStatus }
						summary={ summary }
						schemaFingerprint={ schemaFingerprint }
					/>

					<div className="dbvc-cc-v2-grid dbvc-cc-v2-grid--readiness-lists">
						<ReadinessIssuesList
							title="Blocking issues"
							items={ blockingIssues }
							testId="dbvc-cc-v2-readiness-blockers"
						/>
						<ReadinessIssuesList
							title="Warnings"
							items={ warnings }
							testId="dbvc-cc-v2-readiness-warnings"
						/>
					</div>

					<article className="dbvc-cc-v2-placeholder-card">
						<h3>Per-URL QA reports</h3>
						<ReadinessPagesTable
							items={ pageReports }
							onOpenDrawer={ onOpenDrawer }
						/>
					</article>
				</>
			) : null }
		</section>
	);
}
