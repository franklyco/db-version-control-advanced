const BASE_CRAWL_DEFAULTS = {
	request_delay: 500,
	request_timeout: 30,
	user_agent: '',
	exclude_selectors: '',
	focus_selectors: '',
	capture_mode: 'deep',
	capture_include_attribute_context: true,
	capture_include_dom_path: true,
	capture_max_elements_per_page: 2000,
	capture_max_chars_per_element: 1000,
	context_enable_boilerplate_detection: true,
	context_enable_entity_hints: true,
	ai_enable_section_typing: false,
	ai_section_typing_confidence_threshold: 0.65,
	scrub_policy_enabled: true,
	scrub_profile_mode: 'deterministic-default',
	scrub_attr_action_class: 'tokenize',
	scrub_attr_action_id: 'hash',
	scrub_attr_action_data: 'tokenize',
	scrub_attr_action_style: 'drop',
	scrub_attr_action_aria: 'keep',
	scrub_custom_allowlist: '',
	scrub_custom_denylist: '',
	scrub_ai_suggestion_enabled: false,
	scrub_preview_sample_size: 20,
};

export const ADVANCED_FIELD_GROUPS = [
	{
		title: 'Network and focus',
		description:
			'These values override the shared Configure defaults only for this run.',
		fields: [
			{
				name: 'request_delay',
				label: 'Delay between requests (ms)',
				type: 'number',
				min: 0,
				max: 10000,
			},
			{
				name: 'request_timeout',
				label: 'Request timeout (seconds)',
				type: 'number',
				min: 1,
				max: 300,
			},
			{
				name: 'user_agent',
				label: 'User-Agent',
				type: 'text',
				fullWidth: true,
			},
			{
				name: 'focus_selectors',
				label: 'Focus selectors',
				type: 'textarea',
				fullWidth: true,
			},
			{
				name: 'exclude_selectors',
				label: 'Exclude selectors',
				type: 'textarea',
				fullWidth: true,
			},
		],
	},
	{
		title: 'Capture and context',
		description:
			'These controls shape raw capture density and deterministic context packaging.',
		fields: [
			{
				name: 'capture_mode',
				label: 'Capture mode',
				type: 'select',
				optionsKey: 'captureModes',
			},
			{
				name: 'capture_include_attribute_context',
				label: 'Include attribute context',
				type: 'checkbox',
			},
			{
				name: 'capture_include_dom_path',
				label: 'Include DOM path',
				type: 'checkbox',
			},
			{
				name: 'capture_max_elements_per_page',
				label: 'Max elements per page',
				type: 'number',
				min: 100,
				max: 10000,
			},
			{
				name: 'capture_max_chars_per_element',
				label: 'Max chars per element',
				type: 'number',
				min: 100,
				max: 4000,
			},
			{
				name: 'context_enable_boilerplate_detection',
				label: 'Enable boilerplate detection',
				type: 'checkbox',
			},
			{
				name: 'context_enable_entity_hints',
				label: 'Enable entity hints',
				type: 'checkbox',
			},
			{
				name: 'ai_enable_section_typing',
				label: 'Enable section typing',
				type: 'checkbox',
			},
			{
				name: 'ai_section_typing_confidence_threshold',
				label: 'Section typing confidence threshold',
				type: 'number',
				min: 0,
				max: 1,
				step: 0.01,
			},
		],
	},
	{
		title: 'Scrub policy',
		description:
			'These controls stay per-run and keep the shared policy defaults intact.',
		fields: [
			{
				name: 'scrub_policy_enabled',
				label: 'Enable scrub policy',
				type: 'checkbox',
			},
			{
				name: 'scrub_profile_mode',
				label: 'Scrub profile mode',
				type: 'select',
				optionsKey: 'scrubProfiles',
			},
			{
				name: 'scrub_attr_action_class',
				label: 'class attribute action',
				type: 'select',
				optionsKey: 'scrubActions',
			},
			{
				name: 'scrub_attr_action_id',
				label: 'id attribute action',
				type: 'select',
				optionsKey: 'scrubActions',
			},
			{
				name: 'scrub_attr_action_data',
				label: 'data-* attribute action',
				type: 'select',
				optionsKey: 'scrubActions',
			},
			{
				name: 'scrub_attr_action_style',
				label: 'style attribute action',
				type: 'select',
				optionsKey: 'scrubActions',
			},
			{
				name: 'scrub_attr_action_aria',
				label: 'aria-* attribute action',
				type: 'select',
				optionsKey: 'scrubActions',
			},
			{
				name: 'scrub_custom_allowlist',
				label: 'Custom allowlist',
				type: 'textarea',
				fullWidth: true,
			},
			{
				name: 'scrub_custom_denylist',
				label: 'Custom denylist',
				type: 'textarea',
				fullWidth: true,
			},
			{
				name: 'scrub_ai_suggestion_enabled',
				label: 'Enable scrub AI suggestions',
				type: 'checkbox',
			},
			{
				name: 'scrub_preview_sample_size',
				label: 'Scrub preview sample size',
				type: 'number',
				min: 1,
				max: 100,
			},
		],
	},
];

const BOOLEAN_FIELD_NAMES = ADVANCED_FIELD_GROUPS.reduce(
	( names, group ) =>
		names.concat(
			group.fields
				.filter( ( field ) => field.type === 'checkbox' )
				.map( ( field ) => field.name )
		),
	[]
);

export const ADVANCED_FIELD_NAMES = ADVANCED_FIELD_GROUPS.reduce(
	( names, group ) =>
		names.concat( group.fields.map( ( field ) => field.name ) ),
	[]
);

const normalizeText = ( value ) =>
	typeof value === 'string' ? value : `${ value ?? '' }`;

export const normalizeRunCreateBootstrap = ( runCreateBootstrap = {} ) => {
	const crawlDefaults = { ...BASE_CRAWL_DEFAULTS };
	const rawDefaults =
		runCreateBootstrap && typeof runCreateBootstrap === 'object'
			? runCreateBootstrap.crawlDefaults || {}
			: {};

	ADVANCED_FIELD_NAMES.forEach( ( fieldName ) => {
		const rawValue = rawDefaults[ fieldName ];
		if ( BOOLEAN_FIELD_NAMES.includes( fieldName ) ) {
			crawlDefaults[ fieldName ] = !! rawValue;
			return;
		}

		if ( typeof rawValue !== 'undefined' ) {
			crawlDefaults[ fieldName ] = rawValue;
		}
	} );

	return {
		crawlDefaults,
		optionSets:
			runCreateBootstrap && typeof runCreateBootstrap === 'object'
				? runCreateBootstrap.optionSets || {}
				: {},
	};
};

export const deriveDomainFromSitemap = ( sitemapUrl ) => {
	const value = normalizeText( sitemapUrl ).trim();
	if ( ! value ) {
		return '';
	}

	try {
		const parsed = new URL( value );
		return parsed.host.replace( /^www\./, '' ).trim();
	} catch ( error ) {
		return '';
	}
};

export const buildRunCreatePayload = ( formState ) => {
	const domain =
		normalizeText( formState.domain ).trim() ||
		deriveDomainFromSitemap( formState.sitemapUrl );
	const sitemapUrl = normalizeText( formState.sitemapUrl ).trim();
	const maxUrls = parseInt( `${ formState.maxUrls || '' }`, 10 );
	const crawlOverrides = {};

	ADVANCED_FIELD_NAMES.forEach( ( fieldName ) => {
		crawlOverrides[ fieldName ] = formState[ fieldName ];
	} );

	const payload = {
		domain,
		sitemapUrl,
		forceRebuild: !! formState.forceRebuild,
		crawlOverrides,
	};

	if ( Number.isFinite( maxUrls ) && maxUrls > 0 ) {
		payload.maxUrls = maxUrls;
	}

	return payload;
};

export const getInitialRunCreateState = ( crawlDefaults ) => ( {
	domain: '',
	sitemapUrl: '',
	maxUrls: '',
	forceRebuild: false,
	...crawlDefaults,
} );
