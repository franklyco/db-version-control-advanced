const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests/playwright',
	timeout: 60 * 1000,
	fullyParallel: false,
	retries: 0,
	use: {
		trace: 'on-first-retry',
		video: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
} );
