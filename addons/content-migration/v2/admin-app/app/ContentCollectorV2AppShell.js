import { useRef, useState } from '@wordpress/element';

import InspectorDrawer from '../components/drawers/InspectorDrawer';
import InspectorUnsavedChangesDialog from '../components/drawers/InspectorUnsavedChangesDialog';
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
		value: 'Operator efficiency actions active',
	},
	{
		label: 'Runs',
		value: 'Run-start, replay, rerun, duplicate, and hide helpers active',
	},
	{
		label: 'Overview',
		value: 'Selected-run monitoring and activity active',
	},
	{
		label: 'Exceptions',
		value: 'Conflict-first review, queue navigation, and bulk helpers active',
	},
];

const buildBlockedTransition = ( type ) => {
	if ( type === 'tab' ) {
		return {
			type,
			label: 'switching inspector tabs',
		};
	}

	if ( type === 'record' ) {
		return {
			type,
			label: 'opening another URL',
		};
	}

	if ( type === 'view' ) {
		return {
			type,
			label: 'changing workspaces',
		};
	}

	if ( type === 'run' ) {
		return {
			type,
			label: 'switching runs',
		};
	}

	return {
		type,
		label: 'closing the inspector',
	};
};

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
	const [ isInspectorDirty, setIsInspectorDirty ] = useState( false );
	const [ discardChangesToken, setDiscardChangesToken ] = useState( 0 );
	const [ exceptionQueueContext, setExceptionQueueContext ] = useState( {
		runId: '',
		items: [],
		filter: '',
		status: '',
		q: '',
		sort: '',
	} );
	const [ pendingInspectorTransition, setPendingInspectorTransition ] =
		useState( null );
	const pendingInspectorActionRef = useRef( null );

	const handleMutationComplete = () => {
		setRefreshToken( ( currentToken ) => currentToken + 1 );
	};

	const runOrQueueInspectorTransition = ( transition, action ) => {
		if ( ! isDrawerOpen || ! isInspectorDirty ) {
			action();
			return;
		}

		pendingInspectorActionRef.current = action;
		setPendingInspectorTransition( transition );
	};

	const clearPendingInspectorTransition = () => {
		pendingInspectorActionRef.current = null;
		setPendingInspectorTransition( null );
	};

	const handleDiscardInspectorChanges = () => {
		const pendingAction = pendingInspectorActionRef.current;
		clearPendingInspectorTransition();
		setDiscardChangesToken( ( currentToken ) => currentToken + 1 );

		if ( typeof pendingAction === 'function' ) {
			pendingAction();
		}
	};

	const handleSelectView = ( view ) => {
		if ( view === route.view ) {
			return;
		}

		runOrQueueInspectorTransition( buildBlockedTransition( 'view' ), () =>
			selectView( view )
		);
	};

	const handleSelectRun = ( runId, view = 'overview' ) => {
		const nextRunId = runId || defaultRunId;
		if (
			nextRunId === route.runId &&
			view === route.view &&
			! isDrawerOpen
		) {
			return;
		}

		runOrQueueInspectorTransition(
			buildBlockedTransition(
				nextRunId !== route.runId ? 'run' : 'view'
			),
			() => selectRun( nextRunId, view )
		);
	};

	const handleOpenDrawer = ( pageId, panelTab = 'summary' ) => {
		const nextPageId = pageId || route.pageId || 'seed-home';
		const nextPanelTab = panelTab || 'summary';

		if (
			isDrawerOpen &&
			nextPageId === route.pageId &&
			nextPanelTab === route.panelTab
		) {
			return;
		}

		runOrQueueInspectorTransition(
			buildBlockedTransition(
				nextPageId !== route.pageId ? 'record' : 'tab'
			),
			() => openDrawer( nextPageId, nextPanelTab )
		);
	};

	const handleCloseDrawer = () => {
		if ( ! isDrawerOpen ) {
			return;
		}

		runOrQueueInspectorTransition( buildBlockedTransition( 'close' ), () =>
			closeDrawer()
		);
	};

	const handleRouteChange = ( nextRoute ) => {
		const patch =
			typeof nextRoute === 'function' ? nextRoute( route ) : nextRoute;
		const nextResolvedRoute = {
			...route,
			...( patch || {} ),
		};
		const changesInspectorContext =
			nextResolvedRoute.view !== route.view ||
			nextResolvedRoute.runId !== route.runId ||
			nextResolvedRoute.pageId !== route.pageId ||
			nextResolvedRoute.panel !== route.panel ||
			nextResolvedRoute.panelTab !== route.panelTab;

		if ( ! changesInspectorContext ) {
			updateRoute( nextRoute );
			return;
		}

		let transitionType = 'close';
		if ( nextResolvedRoute.view !== route.view ) {
			transitionType = 'view';
		} else if ( nextResolvedRoute.runId !== route.runId ) {
			transitionType = 'run';
		} else if ( nextResolvedRoute.pageId !== route.pageId ) {
			transitionType = 'record';
		} else if ( nextResolvedRoute.panelTab !== route.panelTab ) {
			transitionType = 'tab';
		}

		runOrQueueInspectorTransition(
			buildBlockedTransition( transitionType ),
			() => updateRoute( nextRoute )
		);
	};

	const renderWorkspace = () => {
		if ( route.view === 'overview' ) {
			return (
				<RunOverviewWorkspace
					onRouteChange={ handleRouteChange }
					route={ route }
					onOpenDrawer={ handleOpenDrawer }
					refreshToken={ refreshToken }
				/>
			);
		}

		if ( route.view === 'exceptions' ) {
			return (
				<ExceptionsWorkspace
					onMutationComplete={ handleMutationComplete }
					onQueueItemsChange={ setExceptionQueueContext }
					refreshToken={ refreshToken }
					route={ route }
					onOpenDrawer={ handleOpenDrawer }
					onRouteChange={ handleRouteChange }
				/>
			);
		}

		if ( route.view === 'readiness' ) {
			return (
				<ReadinessWorkspace
					refreshToken={ refreshToken }
					route={ route }
					onOpenDrawer={ handleOpenDrawer }
					onRouteChange={ handleRouteChange }
				/>
			);
		}

		if ( route.view === 'package' ) {
			return (
				<PackageWorkspace
					onMutationComplete={ handleMutationComplete }
					onRouteChange={ handleRouteChange }
					refreshToken={ refreshToken }
					route={ route }
				/>
			);
		}

		return (
			<RunsWorkspace
				onMutationComplete={ handleMutationComplete }
				onOpenDrawer={ handleOpenDrawer }
				onSelectRun={ handleSelectRun }
				refreshToken={ refreshToken }
				selectedRunId={ route.runId }
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
						exception review, explicit single-item recommendation
						decisions, guarded bulk review helpers, per-URL QA
						reports, package assembly, build history, override
						actions, per-URL reruns, stage-group rerun helpers,
						run-profile duplication, hidden-run cleanup,
						package-first dry-run, preflight approval, import
						execution bridging, a V2-native run-start surface, and
						selected-run monitoring plus recent activity and
						control-center shortcut surfaces are active.
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
							handleOpenDrawer(
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
				<strong>Scope guard:</strong> Readiness blockers now route into
				the right review queue, QA audit, or package workspace, while
				exceptions can apply carefully guarded low-risk bulk actions
				without leaving the audited V2 decision path.
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
				onSelectView={ handleSelectView }
			/>

			<main className="dbvc-cc-v2-main" data-testid="dbvc-cc-v2-main">
				{ renderWorkspace() }
			</main>

			<InspectorUnsavedChangesDialog
				transition={ pendingInspectorTransition }
				onCancel={ clearPendingInspectorTransition }
				onDiscard={ handleDiscardInspectorChanges }
			/>

			<InspectorDrawer
				discardChangesToken={ discardChangesToken }
				isOpen={ isDrawerOpen }
				onDirtyStateChange={ setIsInspectorDirty }
				onMutationComplete={ handleMutationComplete }
				onOpenQueueRecord={ ( item ) =>
					handleOpenDrawer(
						item?.pageId || route.pageId || 'seed-home',
						item?.quickAction?.panelTab ||
							route.panelTab ||
							'summary'
					)
				}
				route={ route }
				queueContext={ exceptionQueueContext }
				onClose={ handleCloseDrawer }
				onSelectTab={ ( panelTab ) =>
					handleOpenDrawer( route.pageId || 'seed-home', panelTab )
				}
				refreshToken={ refreshToken }
			/>
		</div>
	);
}
