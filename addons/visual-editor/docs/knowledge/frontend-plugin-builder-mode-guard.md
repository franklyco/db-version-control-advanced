# Prevent Frontend Plugin Logic From Running Inside Builders

Use this guard for any WordPress plugin, feature, or addon that should run only on the real public frontend and **not** inside page builder editing screens, previews, iframes, or builder canvases.

## Rule

Do not deactivate the plugin. Instead, wrap its frontend runtime, asset enqueues, filters, and DOM/output mutations in a single “real frontend only” check.

## Helper

```php
function myplugin_is_builder_request(): bool {
    // Bricks Builder.
    if ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) {
        return true;
    }

    if ( function_exists( 'bricks_is_builder_main' ) && bricks_is_builder_main() ) {
        return true;
    }

    if ( function_exists( 'bricks_is_builder_iframe' ) && bricks_is_builder_iframe() ) {
        return true;
    }

    // Generic builder/editor query-param fallback.
    $builder_params = [
        'bricks',
        'brickspreview',
        'bricks_preview',
        '_bricksmode',
        'elementor-preview',
        'ct_builder',
        'oxygen_iframe',
        'fl_builder',
        'vc_editable',
    ];

    foreach ( $builder_params as $param ) {
        if ( isset( $_GET[ $param ] ) ) {
            return true;
        }
    }

    return false;
}

function myplugin_should_run_frontend(): bool {
    if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_customize_preview() ) {
        return false;
    }

    if ( myplugin_is_builder_request() ) {
        return false;
    }

    return true;
}
```

## Usage

Apply the guard before enqueueing frontend assets:

```php
add_action( 'wp_enqueue_scripts', function () {
    if ( ! myplugin_should_run_frontend() ) {
        return;
    }

    wp_enqueue_style( 'myplugin-frontend', plugin_dir_url( __FILE__ ) . 'assets/frontend.css', [], '1.0.0' );
    wp_enqueue_script( 'myplugin-frontend', plugin_dir_url( __FILE__ ) . 'assets/frontend.js', [], '1.0.0', true );
}, 20 );
```

Apply the same guard before registering frontend-only filters or output changes:

```php
add_action( 'template_redirect', function () {
    if ( ! myplugin_should_run_frontend() ) {
        return;
    }

    // Real frontend runtime only.
    require_once plugin_dir_path( __FILE__ ) . 'includes/frontend-runtime.php';
}, 1 );
```

## Acceptance Criteria

- Plugin remains active in WordPress.
- No frontend plugin CSS/JS loads inside Bricks Builder, builder iframe, builder preview, or other builder edit contexts.
- No frontend output mutation runs inside builder mode.
- Plugin still works normally on public frontend pages.
- Checks are defensive: builder-specific functions must always be wrapped in `function_exists()`.
