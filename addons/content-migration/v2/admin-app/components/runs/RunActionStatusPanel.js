export default function RunActionStatusPanel( {
	error,
	isSubmitting,
	label,
	lastCompletedAction,
	lastRecoveryAction,
	onOpenExceptions,
	onOpenOverview,
	progress,
} ) {
	if (
		! isSubmitting &&
		! error &&
		! lastCompletedAction &&
		! lastRecoveryAction
	) {
		return null;
	}

	const activeRecoveryAction =
		lastCompletedAction && lastCompletedAction.type === 'rerun'
			? lastCompletedAction
			: lastRecoveryAction;

	const message = ( () => {
		if ( isSubmitting ) {
			return `${ label || 'Working' }${
				progress && progress.total
					? ` (${ progress.current }/${ progress.total })`
					: ''
			}`;
		}

		if ( error ) {
			return error;
		}

		if ( ! lastCompletedAction ) {
			return '';
		}

		if ( lastCompletedAction.type === 'rerun' ) {
			const completed = Number( lastCompletedAction.completedCount || 0 );
			const failed = Number( lastCompletedAction.failedCount || 0 );
			const total = Number( lastCompletedAction.totalCount || 0 );

			if ( failed > 0 ) {
				return `${ lastCompletedAction.label } completed for ${ completed } of ${ total } URLs. ${ failed } failed and may need individual review.`;
			}

			return `${ lastCompletedAction.label } completed for ${ completed } URLs.`;
		}

		if ( lastCompletedAction.type === 'hide' ) {
			return 'Run hidden from the default runs list.';
		}

		if ( lastCompletedAction.type === 'restore' ) {
			return 'Hidden run restored to the default runs list.';
		}

		return '';
	} )();

	const canOpenOverview =
		lastCompletedAction &&
		typeof lastCompletedAction.runId === 'string' &&
		lastCompletedAction.runId;
	const canOpenExceptions =
		canOpenOverview &&
		lastCompletedAction &&
		lastCompletedAction.type === 'rerun' &&
		Number( lastCompletedAction.failedCount || 0 ) > 0;
	const shouldShowActions =
		typeof onOpenOverview === 'function' && canOpenOverview;
	const canOpenRecoveryOverview =
		activeRecoveryAction &&
		typeof activeRecoveryAction.runId === 'string' &&
		activeRecoveryAction.runId;
	const canOpenRecoveryExceptions =
		canOpenRecoveryOverview &&
		activeRecoveryAction &&
		activeRecoveryAction.type === 'rerun' &&
		Number( activeRecoveryAction.failedCount || 0 ) > 0;
	const shouldShowRecoveryContext =
		! isSubmitting &&
		! error &&
		activeRecoveryAction &&
		( ! lastCompletedAction || lastCompletedAction.type !== 'rerun' ) &&
		typeof onOpenOverview === 'function';
	const renderRecoveryMessage = () => {
		if ( ! activeRecoveryAction || activeRecoveryAction.type !== 'rerun' ) {
			return '';
		}

		const completed = Number( activeRecoveryAction.completedCount || 0 );
		const failed = Number( activeRecoveryAction.failedCount || 0 );
		const total = Number( activeRecoveryAction.totalCount || 0 );

		if ( failed > 0 ) {
			return `${ activeRecoveryAction.label } last completed for ${ completed } of ${ total } URLs. ${ failed } still need review.`;
		}

		return `${ activeRecoveryAction.label } last completed for ${ completed } URLs.`;
	};

	return (
		<section
			className="dbvc-cc-v2-placeholder-card dbvc-cc-v2-run-action-status"
			data-testid="dbvc-cc-v2-run-action-status"
		>
			<p className="dbvc-cc-v2-eyebrow">Run actions</p>
			{ message ? <p>{ message }</p> : null }
			{ shouldShowActions ? (
				<div className="dbvc-cc-v2-run-action-status__actions">
					<button
						type="button"
						className="button button-secondary"
						data-testid="dbvc-cc-v2-run-action-open-overview"
						onClick={ () =>
							onOpenOverview( lastCompletedAction.runId )
						}
					>
						Open overview
					</button>
					{ canOpenExceptions &&
					typeof onOpenExceptions === 'function' ? (
						<button
							type="button"
							className="button button-secondary"
							data-testid="dbvc-cc-v2-run-action-open-exceptions"
							onClick={ () =>
								onOpenExceptions( lastCompletedAction.runId )
							}
						>
							Review exceptions
						</button>
					) : null }
				</div>
			) : null }
			{ shouldShowRecoveryContext ? (
				<div className="dbvc-cc-v2-run-action-status__recovery">
					<div>
						<p className="dbvc-cc-v2-eyebrow">
							Latest recovery outcome
						</p>
						<p>{ renderRecoveryMessage() }</p>
					</div>
					<div className="dbvc-cc-v2-run-action-status__actions">
						<button
							type="button"
							className="button button-secondary"
							data-testid="dbvc-cc-v2-run-action-recovery-overview"
							onClick={ () =>
								onOpenOverview( activeRecoveryAction.runId )
							}
						>
							Open overview
						</button>
						{ canOpenRecoveryExceptions &&
						typeof onOpenExceptions === 'function' ? (
							<button
								type="button"
								className="button button-secondary"
								data-testid="dbvc-cc-v2-run-action-recovery-exceptions"
								onClick={ () =>
									onOpenExceptions(
										activeRecoveryAction.runId
									)
								}
							>
								Review exceptions
							</button>
						) : null }
					</div>
				</div>
			) : null }
		</section>
	);
}
