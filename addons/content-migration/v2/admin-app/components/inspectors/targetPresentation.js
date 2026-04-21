const getPresentation = ( item, key = 'targetPresentation' ) => {
	if ( ! item || typeof item !== 'object' ) {
		return null;
	}

	const presentation = item[ key ];
	return presentation && typeof presentation === 'object'
		? presentation
		: null;
};

export const getTargetLabel = ( item, key = 'targetPresentation' ) => {
	const presentation = getPresentation( item, key );
	if ( presentation?.label ) {
		return presentation.label;
	}

	return item?.target_ref || item?.override_target || 'Unknown target';
};

export const getTargetContext = ( item, key = 'targetPresentation' ) => {
	const presentation = getPresentation( item, key );
	return presentation?.contextLabel || '';
};

export const getTargetMachineRef = ( item, key = 'targetPresentation' ) => {
	const presentation = getPresentation( item, key );
	if ( presentation?.machineRef ) {
		return presentation.machineRef;
	}

	return item?.target_ref || item?.override_target || '';
};

export const getTargetFieldName = ( item, key = 'targetPresentation' ) => {
	const presentation = getPresentation( item, key );
	return presentation?.fieldName || '';
};

export const getTargetTypeLabel = ( item, key = 'targetPresentation' ) => {
	const presentation = getPresentation( item, key );
	return presentation?.fieldTypeLabel || '';
};
