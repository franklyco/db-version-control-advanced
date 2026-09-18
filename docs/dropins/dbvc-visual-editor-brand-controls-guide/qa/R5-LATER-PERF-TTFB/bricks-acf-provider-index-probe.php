<?php
/**
 * R5.later-perf TTFB probe (E-159) — Bricks ACF dynamic-data provider, indexed nested-group lookup.
 *
 * Usage (WP-CLI, from the site root, inside the LocalWP site shell):
 *   wp eval-file wp-content/plugins/db-version-control-main/docs/dropins/dbvc-visual-editor-brand-controls-guide/qa/R5-LATER-PERF-TTFB/bricks-acf-provider-index-probe.php
 *
 * What it does: builds a subclass of Bricks' `Provider_Acf` at runtime whose
 * `get_nested_parent_group_field_data()` answers from a key → tag-name index
 * instead of scanning every registered tag for every nested sub-field
 * (O(tags × sub-fields) in Bricks 2.3.8). `register_tag()` is copied verbatim
 * from the installed theme file at runtime (no Bricks code is stored here) with
 * one addition after each `$this->tags[ $name ] = $tag;` — `$this->index_tag()`.
 * Then it registers tags with both implementations and compares them strictly.
 *
 * Read-only: nothing is written; the live Providers instance is untouched.
 */
if ( ! class_exists( 'Bricks\Integrations\Dynamic_Data\Providers\Provider_Acf' ) ) {
	WP_CLI::error( 'Bricks Provider_Acf is not loaded (is the Bricks theme active?).' );
}

$theme_file = get_template_directory() . '/includes/integrations/dynamic-data/providers/provider-acf.php';
$source     = file_get_contents( $theme_file );
if ( ! preg_match( '/\n\tpublic function register_tag\( .*?\n\t}\n/s', $source, $m ) ) {
	WP_CLI::error( 'Could not locate register_tag() in ' . $theme_file );
}
$register_tag = $m[0];
$register_tag = preg_replace( '/\$this->tags\[ \$name \](\s*)= \$tag;/', '$this->tags[ $name ]$1= $tag; $this->index_tag( $name, $tag );', $register_tag, -1, $replaced );
if ( $replaced < 1 ) {
	WP_CLI::error( 'register_tag() shape changed; no tag assignments found to index.' );
}
// Private members of the parent are reached through reflection.
$register_tag = str_replace( 'self::get_fields_by_context()', '$this->call_parent_private( \'get_fields_by_context\', null )', $register_tag );
$register_tag = str_replace( '$this->get_flexible_content_parent_layout_data(', '$this->call_parent_private( \'get_flexible_content_parent_layout_data\', $this, ', $register_tag );

$class = <<<'PHP'
class Dbvc_Perf_Probe_Provider_Acf extends Bricks\Integrations\Dynamic_Data\Providers\Provider_Acf {
	private $index    = []; // sub_field key => [ tag name => true ] — every name that ever carried the key
	private $name_pos = []; // tag name => first-insertion position (= array order of $this->tags)
	private $pos      = 0;

	private function index_tag( $name, $tag ) {
		if ( ! isset( $this->name_pos[ $name ] ) ) {
			$this->name_pos[ $name ] = $this->pos++;
		}
		foreach ( $tag['field']['sub_fields'] ?? [] as $sub_field ) {
			if ( isset( $sub_field['key'] ) ) {
				$this->index[ $sub_field['key'] ][ $name ] = true;
			}
		}
	}

	private function call_parent_private( $method, $target, ...$args ) {
		static $cache = [];
		if ( ! isset( $cache[ $method ] ) ) {
			$cache[ $method ] = new ReflectionMethod( Bricks\Integrations\Dynamic_Data\Providers\Provider_Acf::class, $method );
			$cache[ $method ]->setAccessible( true );
		}
		return $cache[ $method ]->invoke( $target, ...$args );
	}

	private function tag_has_sub_field_key( $tag, $key ) {
		foreach ( $tag['field']['sub_fields'] ?? [] as $sub_field ) {
			if ( ( $sub_field['key'] ?? null ) === $key ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Same answer as the stock linear scan — "the first tag in array order whose
	 * CURRENT sub_fields carry the key" — found in O(duplicate names).
	 */
	public function get_nested_parent_group_field_data( $data = [], $parent_field = [] ) {
		if ( ! isset( $data['name'] ) || ! isset( $parent_field['key'] ) ) {
			return $data;
		}
		$field_key    = $parent_field['key'];
		$data['name'] = $parent_field['name'] . '_' . $data['name'];
		if ( ! empty( $parent_field['_bricks_locations'] ) ) {
			$data['_bricks_locations'] = array_merge( $data['_bricks_locations'], $parent_field['_bricks_locations'] );
		}
		if ( ! empty( $parent_field['name'] ) ) {
			$data['parent_group_names'][] = $parent_field['name'];
		}
		$found = null;
		$best  = PHP_INT_MAX;
		foreach ( $this->index[ $field_key ] ?? [] as $name => $_ ) {
			if ( $this->name_pos[ $name ] < $best && isset( $this->tags[ $name ] ) && $this->tag_has_sub_field_key( $this->tags[ $name ], $field_key ) ) {
				$best  = $this->name_pos[ $name ];
				$found = $this->tags[ $name ];
			}
		}
		if ( $found ) {
			$data = $this->get_nested_parent_group_field_data( $data, $found['field'] );
		}
		return $data;
	}
PHP;
eval( $class . "\n" . $register_tag . "\n}" );

$time  = static function ( $provider ) { $t = microtime( true ); $provider->register_tags(); return ( microtime( true ) - $t ) * 1000; };
$stock = new Bricks\Integrations\Dynamic_Data\Providers\Provider_Acf( 'acf' );
$fast  = new Dbvc_Perf_Probe_Provider_Acf( 'acf' );
$ms_stock = $time( $stock );
$ms_fast  = $time( $fast );
$a = $stock->get_tags();
$b = $fast->get_tags();
$diff = 0;
foreach ( $a as $k => $tag ) {
	if ( $tag !== ( $b[ $k ] ?? null ) ) {
		$diff++;
	}
}
$rp = new ReflectionProperty( Bricks\Integrations\Dynamic_Data\Providers\Provider_Acf::class, 'loop_tags' );
$rp->setAccessible( true );
WP_CLI::log( sprintf(
	'Bricks %s | stock register_tags: %.0f ms | indexed: %.0f ms | tags %d vs %d | same keys + order: %s | differing tag records (strict): %d | loop tags identical: %s',
	wp_get_theme( 'bricks' )->get( 'Version' ), $ms_stock, $ms_fast, count( $a ), count( $b ),
	array_keys( $a ) === array_keys( $b ) ? 'yes' : 'no', $diff, $rp->getValue( $stock ) === $rp->getValue( $fast ) ? 'yes' : 'no'
) );
