import { useEffect, useRef, useState } from '@wordpress/element';

import { request } from '../../api/client';
import useInspectorDetail from '../../hooks/useInspectorDetail';
import useInspectorDecisionDraft from '../../hooks/useInspectorDecisionDraft';
import InspectorDrawerHeaderActions from './InspectorDrawerHeaderActions';
import InspectorActionPanel from '../inspectors/InspectorActionPanel';
import InspectorAuditTab from '../inspectors/InspectorAuditTab';
import InspectorConflictsTab from '../inspectors/InspectorConflictsTab';
import InspectorMappingTab from '../inspectors/InspectorMappingTab';
import InspectorSourceTab from '../inspectors/InspectorSourceTab';
import InspectorSummaryTab from '../inspectors/InspectorSummaryTab';

const BASE_PANEL_TABS = [ 'summary', 'source', 'mapping', 'audit' ];

export default function InspectorDrawer( {
	discardChangesToken = 0,
	isOpen,
	onMutationComplete,
	onDirtyStateChange,
	onOpenQueueRecord,
	queueContext,
	route,
	onClose,
	onSelectTab,
	refreshToken,
} ) {
	const { detail, isLoading, error } = useInspectorDetail(
		route.runId,
		route.pageId,
		isOpen,
		refreshToken
	);
	const [ isBusy, setIsBusy ] = useState( false );
	const [ statusMessage, setStatusMessage ] = useState( '' );
	const appliedDiscardTokenRef = useRef( 0 );
	const decisionDraft = useInspectorDecisionDraft( detail );
	const hasConflicts = Array.isArray( detail?.recommendations?.conflicts )
		? detail.recommendations.conflicts.length > 0
		: false;
	const panelTabs = hasConflicts
		? [ 'summary', 'source', 'mapping', 'conflicts', 'audit' ]
		: BASE_PANEL_TABS;
	const queueItems =
		queueContext?.runId === route.runId &&
		Array.isArray( queueContext?.items )
			? queueContext.items
			: [];
	const currentQueueIndex = queueItems.findIndex(
		( item ) => item?.pageId === route.pageId
	);
	const previousQueueItem =
		currentQueueIndex > 0 ? queueItems[ currentQueueIndex - 1 ] : null;
	const nextQueueItem =
		currentQueueIndex > -1 && currentQueueIndex < queueItems.length - 1
			? queueItems[ currentQueueIndex + 1 ]
			: null;

	useEffect( () => {
		if ( typeof onDirtyStateChange !== 'function' ) {
			return;
		}

		onDirtyStateChange(
			Boolean( isOpen && decisionDraft.hasUnsavedChanges )
		);
	}, [ decisionDraft.hasUnsavedChanges, isOpen, onDirtyStateChange ] );

	useEffect( () => {
		if (
			discardChangesToken < 1 ||
			discardChangesToken === appliedDiscardTokenRef.current
		) {
			return;
		}

		appliedDiscardTokenRef.current = discardChangesToken;
		decisionDraft.restoreSavedDraft();
	}, [ decisionDraft, discardChangesToken ] );

	useEffect( () => {
		setStatusMessage( '' );
	}, [ detail?.pageId, route.panelTab ] );

	useEffect( () => {
		if ( ! isOpen ) {
			return undefined;
		}

		const handleKeyDown = ( event ) => {
			if ( event.key === 'Escape' ) {
				onClose();
			}
		};

		window.addEventListener( 'keydown', handleKeyDown );
		return () => window.removeEventListener( 'keydown', handleKeyDown );
	}, [ isOpen, onClose ] );

	const handleSave = async ( payload, onSuccess ) => {
		if ( ! route.runId || ! route.pageId ) {
			return;
		}

		setIsBusy( true );
		setStatusMessage( '' );

		try {
			await request(
				`runs/${ route.runId }/urls/${ route.pageId }/decision`,
				{
					method: 'POST',
					data: payload,
				}
			);
			decisionDraft.commitSavedDraft();
			setStatusMessage( 'Decision artifacts saved.' );
			onMutationComplete();
			if ( typeof onSuccess === 'function' ) {
				window.setTimeout( () => {
					onSuccess();
				}, 0 );
			}
		} catch ( saveError ) {
			setStatusMessage(
				saveError instanceof Error
					? saveError.message
					: 'Could not save decision artifacts.'
			);
		} finally {
			setIsBusy( false );
		}
	};

	const handleRerun = async ( stage ) => {
		if ( ! route.runId || ! route.pageId ) {
			return;
		}

		setIsBusy( true );
		setStatusMessage( '' );

		try {
			await request(
				`runs/${ route.runId }/urls/${ route.pageId }/rerun`,
				{
					method: 'POST',
					data: {
						stage,
					},
				}
			);
			setStatusMessage( `Rerun queued for ${ stage }.` );
			onMutationComplete();
		} catch ( rerunError ) {
			setStatusMessage(
				rerunError instanceof Error
					? rerunError.message
					: 'Could not rerun the selected stage.'
			);
		} finally {
			setIsBusy( false );
		}
	};

	const renderTab = () => {
		if ( ! detail ) {
			return null;
		}

		if ( route.panelTab === 'source' ) {
			return <InspectorSourceTab detail={ detail } />;
		}

		if ( route.panelTab === 'mapping' ) {
			return (
				<InspectorMappingTab
					detail={ detail }
					fieldDecisions={ decisionDraft.fieldDecisions }
					mediaDecisions={ decisionDraft.mediaDecisions }
				/>
			);
		}

		if ( route.panelTab === 'conflicts' ) {
			return (
				<InspectorConflictsTab
					detail={ detail }
					decisionDraft={ decisionDraft }
				/>
			);
		}

		if ( route.panelTab === 'audit' ) {
			return <InspectorAuditTab detail={ detail } />;
		}

		return <InspectorSummaryTab detail={ detail } />;
	};

	return (
		<aside
			className={ `dbvc-cc-v2-drawer${ isOpen ? ' is-open' : '' }` }
			data-testid="dbvc-cc-v2-inspector-drawer"
			data-open={ isOpen ? 'true' : 'false' }
			aria-hidden={ ! isOpen }
			hidden={ ! isOpen }
		>
			<div className="dbvc-cc-v2-drawer__header">
				<div className="dbvc-cc-v2-drawer__header-main">
					<p className="dbvc-cc-v2-eyebrow">URL Inspector</p>
					<h2 className="dbvc-cc-v2-drawer__title">
						{ detail?.path || route.pageId || 'No page selected' }
					</h2>
					{ currentQueueIndex > -1 ? (
						<p
							className="dbvc-cc-v2-table__meta"
							data-testid="dbvc-cc-v2-inspector-queue-meta"
						>
							Queue item { currentQueueIndex + 1 } of{ ' ' }
							{ queueItems.length }
						</p>
					) : null }
					{ statusMessage ? (
						<p className="dbvc-cc-v2-drawer__status">
							{ statusMessage }
						</p>
					) : null }
				</div>
				{ detail ? (
					<InspectorDrawerHeaderActions
						detail={ detail }
						decisionDraft={ decisionDraft }
						isBusy={ isBusy }
						navigation={
							currentQueueIndex > -1
								? {
										position: currentQueueIndex + 1,
										total: queueItems.length,
										previousItem: previousQueueItem,
										nextItem: nextQueueItem,
								  }
								: null
						}
						onClose={ onClose }
						onNavigateQueueItem={ onOpenQueueRecord }
						onRerun={ handleRerun }
						onSave={ handleSave }
					/>
				) : (
					<button
						type="button"
						className="dbvc-cc-v2-drawer__close"
						data-testid="dbvc-cc-v2-drawer-close"
						onClick={ onClose }
					>
						Close
					</button>
				) }
			</div>

			<div
				className="dbvc-cc-v2-drawer__tabs"
				role="tablist"
				aria-label="Inspector tabs"
			>
				{ panelTabs.map( ( panelTab ) => {
					const isActive = route.panelTab === panelTab;

					return (
						<button
							key={ panelTab }
							type="button"
							role="tab"
							className={ `dbvc-cc-v2-drawer__tab${
								isActive ? ' is-active' : ''
							}` }
							data-testid={ `dbvc-cc-v2-drawer-tab-${ panelTab }` }
							aria-selected={ isActive }
							onClick={ () => onSelectTab( panelTab ) }
						>
							{ panelTab }
						</button>
					);
				} ) }
			</div>

			<div className="dbvc-cc-v2-drawer__body">
				{ isLoading ? (
					<div className="dbvc-cc-v2-placeholder-card">
						<p>
							Loading URL evidence, recommendations, and
							decisions.
						</p>
					</div>
				) : null }
				{ error ? (
					<div className="dbvc-cc-v2-placeholder-card">
						<p>{ error }</p>
					</div>
				) : null }
				{ ! isLoading && ! error && detail ? renderTab() : null }
				{ detail ? (
					<InspectorActionPanel decisionDraft={ decisionDraft } />
				) : null }
			</div>
		</aside>
	);
}
