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

export const getFieldContextCompact = ( item ) => {
	const compact = item?.field_context_compact;
	return compact && typeof compact === 'object' ? compact : null;
};

export const getFieldContextProviderStatus = ( item ) => {
	return getFieldContextCompact( item )?.provider_status || '';
};

export const getFieldContextProviderName = ( item ) => {
	return getFieldContextCompact( item )?.provider || '';
};

export const getFieldContextWarnings = ( item ) => {
	const warnings = getFieldContextCompact( item )?.warnings;
	return Array.isArray( warnings ) ? warnings : [];
};

export const getFieldContextWritable = ( item ) => {
	const compact = getFieldContextCompact( item );
	if ( ! compact || typeof compact.writable !== 'boolean' ) {
		return null;
	}

	return compact.writable;
};

export const isFieldContextCloneProjection = ( item ) =>
	Boolean( getFieldContextCompact( item )?.clone_projection );

export const getFieldContextValueShapeLabel = ( item ) => {
	const valueShape = getFieldContextCompact( item )?.value_shape;
	if ( ! valueShape || typeof valueShape !== 'object' ) {
		return '';
	}

	const parts = [
		valueShape.content_type,
		valueShape.value_shape,
		valueShape.reference_kind,
	].filter( Boolean );

	return parts.join( ' · ' );
};
