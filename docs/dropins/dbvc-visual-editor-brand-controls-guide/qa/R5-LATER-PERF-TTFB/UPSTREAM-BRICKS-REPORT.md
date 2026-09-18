# Upstream report draft — Bricks: ACF dynamic-data tag registration is quadratic on nested groups

**For:** Bricks Builder support / bug tracker · **Bricks version:** 2.3.8 · **File:** `includes/integrations/dynamic-data/providers/provider-acf.php` · **Prepared:** 2026-09-17 (E-160)

## Summary

On sites with many group-nested ACF fields, `Provider_Acf::register_tags()` — which runs on every frontend and REST request at `init` (priority 10001) — spends most of its time in `get_nested_parent_group_field_data()`. For every nested sub-field it scans **every registered tag and each of that tag's sub-fields** looking for the parent group's tag, recursing once per nesting level. The work is O(tags × sub-fields) per request.

Reference site: 49 field groups, 525 top-level fields (284 `group`), 3 288 nested sub-fields → 2 700 tags. `register_tags()` on a warm instance: **1 550–1 670 ms**; with the indexed lookup below: **52 ms**, producing a byte-identical registry (`$this->tags` and `$this->loop_tags` compared with `===`, i.e. same keys, order and values).

## Reproduce

Any site whose ACF schema has a few hundred `group` fields with nested sub-fields. Measure:

```php
$p = new Bricks\Integrations\Dynamic_Data\Providers\Provider_Acf( 'acf' );
$t = microtime( true ); $p->register_tags(); echo ( microtime( true ) - $t ) * 1000, " ms\n";
```

(`Provider_Acf::get_fields()` is `wp_cache`d, so this isolates the tag-building cost.)

## Fix

Keep a `sub-field key → [tag name]` index and a `tag name → first-insertion position` map, maintained at the two `$this->tags[ $name ] = $tag;` sites in `register_tag()`. `get_nested_parent_group_field_data()` then answers "first tag in array order whose *current* sub-fields carry the key" from the index (validating each candidate against the live tag, so overwritten duplicate-named tags behave exactly as before). No behaviour change; only the search strategy.

```diff
--- a/includes/integrations/dynamic-data/providers/provider-acf.php
+++ b/includes/integrations/dynamic-data/providers/provider-acf.php
@@ -32,6 +32,37 @@
 		}
 
 		return class_exists( 'ACF' );
+	}
+
+	/**
+	 * Sub-field key => [ tag name => true ] and tag name => first-insertion
+	 * position. Lets get_nested_parent_group_field_data() find the parent group's
+	 * tag without scanning every registered tag for every nested sub-field.
+	 */
+	private $sub_field_index = [];
+	private $tag_position    = [];
+	private $next_position   = 0;
+
+	private function index_tag( $name, $tag ) {
+		if ( ! isset( $this->tag_position[ $name ] ) ) {
+			$this->tag_position[ $name ] = $this->next_position++;
+		}
+
+		foreach ( $tag['field']['sub_fields'] ?? [] as $sub_field ) {
+			if ( isset( $sub_field['key'] ) ) {
+				$this->sub_field_index[ $sub_field['key'] ][ $name ] = true;
+			}
+		}
+	}
+
+	private function tag_has_sub_field_key( $tag, $key ) {
+		foreach ( $tag['field']['sub_fields'] ?? [] as $sub_field ) {
+			if ( ( $sub_field['key'] ?? null ) === $key ) {
+				return true;
+			}
+		}
+
+		return false;
 	}
 
 	public function register_tags() {
@@ -77,19 +108,14 @@
 
 		$found_tag = false;
 
-		// Find from the tags that subfields has key same as $field_key
-		foreach ( $this->tags as $tag ) {
-			$sub_fields = $tag['field']['sub_fields'] ?? false;
-
-			if ( ! $sub_fields ) {
-				continue;
-			}
+		// Find the first tag (array order) whose current sub-fields carry $field_key
+		// via the index instead of scanning every registered tag (O(tags × sub-fields)).
+		$best_position = PHP_INT_MAX;
 
-			foreach ( $sub_fields as $sub_field ) {
-				if ( $sub_field['key'] === $field_key ) {
-					$found_tag = $tag;
-					break 2; // Break out of both loops since the tag is found
-				}
+		foreach ( $this->sub_field_index[ $field_key ] ?? [] as $tag_name => $_ ) {
+			if ( $this->tag_position[ $tag_name ] < $best_position && isset( $this->tags[ $tag_name ] ) && $this->tag_has_sub_field_key( $this->tags[ $tag_name ], $field_key ) ) {
+				$best_position = $this->tag_position[ $tag_name ];
+				$found_tag     = $this->tags[ $tag_name ];
 			}
 		}
 
@@ -218,6 +244,7 @@
 				// Register the group field tag as deprecated to be used in case groups are nested inside a repeater (@since 1.5.1)
 				if ( $type === 'group' ) {
 					$this->tags[ $name ]               = $tag;
+					$this->index_tag( $name, $this->tags[ $name ] );
 					$this->tags[ $name ]['deprecated'] = 1;
 				} else {
 					$this->loop_tags[ $name ] = $tag;
@@ -245,6 +272,7 @@
 			// Only register fields from other contexts, other than CONTEXT_TEXT, if they are not sub-fields (legacy purposes)
 			elseif ( $context === self::CONTEXT_TEXT || empty( $parent_field ) ) {
 				$this->tags[ $name ] = $tag;
+				$this->index_tag( $name, $this->tags[ $name ] );
 
 				if ( $context !== self::CONTEXT_TEXT ) {
 					$this->tags[ $name ]['deprecated'] = 1;
```

## Notes

- The two other halves of the per-request cost are outside this file: ACF's own cold materialisation of the same schema (`acf_get_fields()` for every group ≈ 2 s on the reference site, no cross-request cache for PHP-registered fields) and — a separate suggestion — caching the finished tag registry per site keyed on the field-group set, which would remove both.
- DBVC ships this same change as an opt-in, fingerprint-pinned compatibility shim until it lands upstream (`Dbvc\VisualEditor\Performance\Bricks\AcfTagIndexShim`); the shim disables itself on any Bricks update that touches the pinned methods.
