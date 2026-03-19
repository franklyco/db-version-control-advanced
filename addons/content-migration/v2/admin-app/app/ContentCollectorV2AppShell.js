import { useState } from '@wordpress/element';

import InspectorDrawer from '../components/drawers/InspectorDrawer';
import WorkspaceNav from '../components/WorkspaceNav';
import useWorkspaceRoute from './useWorkspaceRoute';
import RunsWorkspace from '../workspaces/runs/RunsWorkspace';
import RunOverviewWorkspace from '../workspaces/run-overview/RunOverviewWorkspace';
import ExceptionsWorkspace from '../workspaces/exceptions/ExceptionsWorkspace';
import ReadinessWorkspace from '../workspaces/readiness/ReadinessWorkspace';
import PackageWorkspace from '../workspaces/package/PackageWorkspace';

const SUMMARY_CARDS = [
	{
		label: 'Status',
		value: 'Phase 8 import bridge active',
	},
	{
		label: 'Exceptions',
		value: 'Exception queue active',
	},
	{
		label: 'Readiness',
		value: 'QA reports live',
	},
	{
		label: 'Package',
		value: 'Dry-run and execute active',
	},
];

const buildRouteLabel = ( route ) => {
	if ( route.view === 'runs' ) {
		return 'runs';
	}

	const runId = route.runId || 'journey-demo';
	return `runs/${ runId }/${ route.view }`;
};

export default function ContentCollectorV2AppShell( { bootstrap } ) {
	const views = Array.isArray( bootstrap.views )
		? bootstrap.views
		: [ 'runs', 'overview', 'exceptions', 'readiness', 'package' ];
	const defaultRunId = bootstrap.defaultRunId || 'journey-demo';
	const {
		route,
		selectRun,
		selectView,
		updateRoute,
		openDrawer,
		closeDrawer,
		isDrawerOpen,
	} = useWorkspaceRoute( bootstrap.route || {}, defaultRunId );
	const [ refreshToken, setRefreshToken ] = useState( 0 );

	const handleMutationComplete = () => {
		setRefreshToken( ( currentToken ) => currentToken + 1 );
	};

	const renderWorkspace = () => {
		if ( route.view === 'overview' ) {
			return (
				<RunOverviewWorkspace
					route={ route }
					onOpenDrawer={ openDrawer }
				/>
			);
		}

		if ( route.view === 'exceptions' ) {
			return (
				<ExceptionsWorkspace
					refreshToken={ refreshToken }
					route={ route }
					onOpenDrawer={ openDrawer }
					onRouteChange={ updateRoute }
				/>
			);
		}

		if ( route.view === 'readiness' ) {
			return (
				<ReadinessWorkspace
					refreshToken={ refreshToken }
					route={ route }
					onOpenDrawer={ openDrawer }
				/>
			);
		}

		if ( route.view === 'package' ) {
			return (
				<PackageWorkspace
					onMutationComplete={ handleMutationComplete }
					onRouteChange={ updateRoute }
					refreshToken={ refreshToken }
					route={ route }
				/>
			);
		}

		return (
			<RunsWorkspace
				onOpenDrawer={ openDrawer }
				onSelectRun={ selectRun }
				refreshToken={ refreshToken }
			/>
		);
	};

	return (
		<div
			className="dbvc-cc-v2-shell"
			data-testid="dbvc-cc-v2-shell"
			data-current-view={ route.view }
			data-run-id={ route.runId || '' }
		>
			<header className="dbvc-cc-v2-header">
				<div>
					<p className="dbvc-cc-v2-eyebrow">
						DBVC Content Collector / Migration Mapper V2
					</p>
					<h1 className="dbvc-cc-v2-title">
						Operational review workspace
					</h1>
					<p className="dbvc-cc-v2-subtitle">
						Runtime gating, domain journey, target schema sync,
						discovery, capture, deterministic extraction, context
						creation, initial classification, mapping, media
						alignment, target transforms, canonical recommendations,
						exception review, per-URL QA reports, package assembly,
						build history, override actions, per-URL reruns,
						package-first dry-run, preflight approval, and import
						execution bridging are active.
					</p>
				</div>

				<div className="dbvc-cc-v2-header__actions">
					<span className="dbvc-cc-v2-chip">
						Runtime { bootstrap.runtimeVersion || 'v2' }
					</span>
					<span className="dbvc-cc-v2-chip dbvc-cc-v2-chip--muted">
						{ buildRouteLabel( route ) }
					</span>
					<button
						type="button"
						className="button button-primary"
						data-testid="dbvc-cc-v2-drawer-toggle"
						onClick={ () =>
							openDrawer(
								route.pageId || 'seed-home',
								route.panelTab || 'summary'
							)
						}
					>
						Open inspector
					</button>
				</div>
			</header>

			<section
				className="dbvc-cc-v2-callout"
				data-testid="dbvc-cc-v2-callout"
			>
				<strong>Scope guard:</strong> Phase 8 package dry-run, preflight
				approval, and execute bridging are live. Browser QA remains
				paused until explicitly resumed.
			</section>

			<section
				className="dbvc-cc-v2-summary-strip"
				aria-label="Run summary"
			>
				{ SUMMARY_CARDS.map( ( card ) => (
					<article
						key={ card.label }
						className="dbvc-cc-v2-summary-card"
					>
						<p>{ card.label }</p>
						<strong>{ card.value }</strong>
					</article>
				) ) }
			</section>

			<WorkspaceNav
				currentView={ route.view }
				views={ views }
				onSelectView={ selectView }
			/>

			<main className="dbvc-cc-v2-main" data-testid="dbvc-cc-v2-main">
				{ renderWorkspace() }
			</main>

			<InspectorDrawer
				isOpen={ isDrawerOpen }
				onMutationComplete={ handleMutationComplete }
				route={ route }
				onClose={ closeDrawer }
				onSelectTab={ ( panelTab ) =>
					openDrawer( route.pageId || 'seed-home', panelTab )
				}
				refreshToken={ refreshToken }
			/>
		</div>
	);
}
