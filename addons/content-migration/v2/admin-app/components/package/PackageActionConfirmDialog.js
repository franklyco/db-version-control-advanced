export default function PackageActionConfirmDialog( {
	action,
	isBusy,
	onCancel,
	onConfirm,
} ) {
	if ( ! action ) {
		return null;
	}

	return (
		<div
			className="dbvc-cc-v2-modal-backdrop"
			data-testid="dbvc-cc-v2-package-action-confirm-dialog"
		>
			<div className="dbvc-cc-v2-modal">
				<h2>{ action.title }</h2>
				<p>{ action.description }</p>
				<div className="dbvc-cc-v2-actions">
					<button
						type="button"
						className="button"
						onClick={ onCancel }
					>
						Cancel
					</button>
					<button
						type="button"
						className="button button-primary"
						data-testid={ action.confirmTestId }
						disabled={ isBusy }
						onClick={ onConfirm }
					>
						{ isBusy ? action.loadingLabel : action.confirmLabel }
					</button>
				</div>
			</div>
		</div>
	);
}
