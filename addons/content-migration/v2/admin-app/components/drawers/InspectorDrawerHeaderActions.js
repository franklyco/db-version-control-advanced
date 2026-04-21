import { useEffect, useRef, useState } from '@wordpress/element';

export default function InspectorDrawerHeaderActions( {
	detail,
	decisionDraft,
	isBusy,
	navigation,
	onClose,
	onNavigateQueueItem,
	onRerun,
	onSave,
} ) {
	const hasQueueNavigation = Boolean( navigation && navigation.total > 0 );
	const canGoPrevious = Boolean( navigation?.previousItem?.pageId );
	const canGoNext = Boolean( navigation?.nextItem?.pageId );
	const rerunStages = Array.isArray( detail?.actions?.rerunStages )
		? detail.actions.rerunStages
		: [];
	const savePayload = decisionDraft.buildSavePayload();
	const [ isRerunMenuOpen, setIsRerunMenuOpen ] = useState( false );
	const rerunMenuRef = useRef( null );

	useEffect( () => {
		if ( ! isRerunMenuOpen ) {
			return undefined;
		}

		const handlePointerDown = ( event ) => {
			if (
				rerunMenuRef.current &&
				! rerunMenuRef.current.contains( event.target )
			) {
				setIsRerunMenuOpen( false );
			}
		};
		const handleKeyDown = ( event ) => {
			if ( event.key === 'Escape' ) {
				setIsRerunMenuOpen( false );
			}
		};

		document.addEventListener( 'mousedown', handlePointerDown );
		document.addEventListener( 'keydown', handleKeyDown );

		return () => {
			document.removeEventListener( 'mousedown', handlePointerDown );
			document.removeEventListener( 'keydown', handleKeyDown );
		};
	}, [ isRerunMenuOpen ] );

	useEffect( () => {
		if ( isBusy ) {
			setIsRerunMenuOpen( false );
		}
	}, [ isBusy ] );

	useEffect( () => {
		setIsRerunMenuOpen( false );
	}, [ detail?.pageId ] );

	const handleToggleRerunMenu = () => {
		setIsRerunMenuOpen( ( isOpen ) => ! isOpen );
	};

	const handleClose = () => {
		setIsRerunMenuOpen( false );
		onClose();
	};

	const handleRerunStage = ( stage ) => {
		setIsRerunMenuOpen( false );
		onRerun( stage );
	};

	return (
		<div className="dbvc-cc-v2-drawer__header-actions">
			<div className="dbvc-cc-v2-drawer__action-row">
				{ rerunStages.length ? (
					<div
						ref={ rerunMenuRef }
						className={ `dbvc-cc-v2-drawer__rerun-menu${
							isRerunMenuOpen ? ' is-open' : ''
						}` }
						data-testid="dbvc-cc-v2-drawer-rerun-menu"
					>
						<button
							type="button"
							className="dbvc-cc-v2-drawer__action dbvc-cc-v2-drawer__action--quiet"
							disabled={ isBusy }
							data-testid="dbvc-cc-v2-drawer-rerun-toggle"
							aria-haspopup="true"
							aria-expanded={ isRerunMenuOpen }
							aria-controls="dbvc-cc-v2-drawer-rerun-popover"
							aria-label={
								isRerunMenuOpen
									? 'Close rerun actions'
									: 'Open rerun actions'
							}
							onClick={ handleToggleRerunMenu }
						>
							<span className="dbvc-cc-v2-drawer__action-label">
								Rerun
							</span>
							<span
								className="dbvc-cc-v2-drawer__action-indicator"
								aria-hidden="true"
							>
								&gt;
							</span>
						</button>
						<div
							id="dbvc-cc-v2-drawer-rerun-popover"
							className="dbvc-cc-v2-drawer__rerun-popover"
							hidden={ ! isRerunMenuOpen }
						>
							{ rerunStages.map( ( stage ) => (
								<button
									key={ stage }
									type="button"
									className="dbvc-cc-v2-drawer__rerun-option"
									disabled={ isBusy }
									data-testid={ `dbvc-cc-v2-rerun-${ stage }` }
									onClick={ () => handleRerunStage( stage ) }
								>
									Rerun { stage }
								</button>
							) ) }
						</div>
					</div>
				) : null }

				<button
					type="button"
					className="dbvc-cc-v2-drawer__action dbvc-cc-v2-drawer__action--primary"
					disabled={ isBusy || ! decisionDraft.canSave }
					data-testid="dbvc-cc-v2-save-decision"
					onClick={ () => onSave( savePayload ) }
				>
					Save decision
				</button>
				<button
					type="button"
					className="dbvc-cc-v2-drawer__action"
					disabled={ isBusy || ! decisionDraft.canSave }
					data-testid="dbvc-cc-v2-save-close"
					onClick={ () => onSave( savePayload, handleClose ) }
				>
					Save and close
				</button>
				<button
					type="button"
					className="dbvc-cc-v2-drawer__close"
					data-testid="dbvc-cc-v2-drawer-close"
					onClick={ handleClose }
				>
					Close
				</button>
			</div>

			{ hasQueueNavigation ? (
				<div
					className="dbvc-cc-v2-drawer__nav-row"
					data-testid="dbvc-cc-v2-inspector-queue-nav"
				>
					<button
						type="button"
						className="dbvc-cc-v2-drawer__nav-button"
						disabled={ isBusy || ! canGoPrevious }
						data-testid="dbvc-cc-v2-inspector-previous"
						aria-label="Previous URL"
						onClick={ () =>
							onNavigateQueueItem( navigation.previousItem )
						}
					>
						&lt;
					</button>
					<button
						type="button"
						className="dbvc-cc-v2-drawer__nav-button"
						disabled={ isBusy || ! canGoNext }
						data-testid="dbvc-cc-v2-inspector-next"
						aria-label="Next URL"
						onClick={ () =>
							onNavigateQueueItem( navigation.nextItem )
						}
					>
						&gt;
					</button>
				</div>
			) : null }
		</div>
	);
}
