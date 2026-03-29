export default function InspectorUnsavedChangesDialog( {
	transition,
	onCancel,
	onDiscard,
} ) {
	if ( ! transition ) {
		return null;
	}

	return (
		<div
			className="dbvc-cc-v2-modal-backdrop"
			data-testid="dbvc-cc-v2-unsaved-dialog"
			role="presentation"
		>
			<div
				className="dbvc-cc-v2-modal"
				role="dialog"
				aria-modal="true"
				aria-labelledby="dbvc-cc-v2-unsaved-dialog-title"
			>
				<p className="dbvc-cc-v2-eyebrow">Unsaved changes</p>
				<h2 id="dbvc-cc-v2-unsaved-dialog-title">
					Discard local review edits?
				</h2>
				<p>
					You have unsaved inspector changes. Discard them before you{ ' ' }
					{ transition.label || 'leave the current inspector view' }.
				</p>
				<div className="dbvc-cc-v2-actions">
					<button
						type="button"
						className="button button-secondary"
						data-testid="dbvc-cc-v2-unsaved-dialog-cancel"
						onClick={ onCancel }
					>
						Keep editing
					</button>
					<button
						type="button"
						className="button button-primary"
						data-testid="dbvc-cc-v2-unsaved-dialog-discard"
						onClick={ onDiscard }
					>
						Discard changes
					</button>
				</div>
			</div>
		</div>
	);
}
