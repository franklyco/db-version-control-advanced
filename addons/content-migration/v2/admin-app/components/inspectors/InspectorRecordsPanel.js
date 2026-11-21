/**
 * The records one source URL becomes, and the unresolved items a reviewer may leave out.
 *
 * A page read as a set (V3 guide D-19) packages one post per included record. Excluding a
 * record drops it from the package without touching its siblings. An unresolved item - a page
 * heading on a team page, a footer line the interpreter declined - holds the page's package until
 * a reviewer either maps it or decides to leave it out here.
 *
 * @param {Object}   root0                   Props.
 * @param {Array}    root0.recordDecisions   Records with their include state.
 * @param {Array}    root0.unresolvedItems   Unresolved items with their leave-unmapped state.
 * @param {Function} root0.setRecordIncluded Include or exclude one record.
 * @param {Function} root0.setLeftUnmapped   Leave one unresolved item unmapped, or not.
 * @return {Element|null} The panel, or nothing for a page with neither.
 */
export default function InspectorRecordsPanel( {
	recordDecisions,
	unresolvedItems,
	setRecordIncluded,
	setLeftUnmapped,
} ) {
	const records = Array.isArray( recordDecisions ) ? recordDecisions : [];
	const items = Array.isArray( unresolvedItems ) ? unresolvedItems : [];

	if ( ! records.length && ! items.length ) {
		return null;
	}

	const labelFor = ( entityRef ) =>
		records.find( ( record ) => record.entityRef === entityRef )?.label ||
		'';

	return (
		<div className="dbvc-cc-v2-action-panel__columns">
			{ records.length ? (
				<div
					className="dbvc-cc-v2-placeholder-card"
					data-testid="dbvc-cc-v2-inspector-records"
				>
					<h4>Records on this page ({ records.length })</h4>
					<p className="dbvc-cc-v2-table__meta">
						Each included record is packaged as its own post.
					</p>
					{ records.map( ( record ) => (
						<div
							key={ record.entityRef }
							className="dbvc-cc-v2-toolbar__field"
						>
							<input
								id={ `dbvc-cc-v2-record-include-${ record.entityRef }` }
								type="checkbox"
								checked={ record.included }
								data-testid={ `dbvc-cc-v2-record-include-${ record.entityRef }` }
								onChange={ ( event ) =>
									setRecordIncluded(
										record.entityRef,
										event.target.checked
									)
								}
							/>
							<label
								htmlFor={ `dbvc-cc-v2-record-include-${ record.entityRef }` }
							>
								{ record.label } ({ record.targetObjectKey })
							</label>
						</div>
					) ) }
				</div>
			) : null }

			{ items.length ? (
				<div
					className="dbvc-cc-v2-placeholder-card"
					data-testid="dbvc-cc-v2-inspector-unresolved"
				>
					<h4>Unresolved items ({ items.length })</h4>
					<p className="dbvc-cc-v2-table__meta">
						Leave an item unmapped to stop it holding the package.
					</p>
					{ items.map( ( item ) => (
						<div
							key={ item.itemId }
							className="dbvc-cc-v2-toolbar__field"
						>
							<input
								id={ `dbvc-cc-v2-leave-unmapped-${ item.itemId }` }
								type="checkbox"
								checked={ item.leftUnmapped }
								data-testid={ `dbvc-cc-v2-leave-unmapped-${ item.itemId }` }
								onChange={ ( event ) =>
									setLeftUnmapped(
										item.itemId,
										event.target.checked
									)
								}
							/>
							<label
								htmlFor={ `dbvc-cc-v2-leave-unmapped-${ item.itemId }` }
							>
								Leave unmapped: { item.itemId }
								{ item.reason ? ` - ${ item.reason }` : '' }
								{ item.entityRef
									? ` (${ labelFor( item.entityRef ) })`
									: '' }
							</label>
						</div>
					) ) }
				</div>
			) : null }
		</div>
	);
}
