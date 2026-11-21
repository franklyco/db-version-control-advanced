<?php

/**
 * What the interpreter is shown of a target: the live catalog, through the shipped bridge.
 *
 * The catalog-equality check runs this twice: once in PHPUnit against the ACF doubles, and once
 * on the provisioned site against real ACF. Both runs go through shipped code from
 * `DBVC_CC_V2_Target_Field_Catalog_Service::build_catalog()` onward. Only the input differs, so
 * equal output means the two runs saw the same target.
 *
 * The projection is `corpus-target-schema.v1` per post-type object, with that object first and the
 * others offered as collections, as an ingest run offers them. Run-specific values (timestamps,
 * the catalog fingerprint and the domain name) are dropped. Field order is kept, because the
 * model reads the fields in that order.
 *
 * Dev-only. Nothing in the shipped plugin loads this file.
 */
final class DBVC_CC_Corpus_Target_Projection
{
    /**
     * @param string                            $domain A content-migration domain key; its catalog artifact is rebuilt.
     * @param DBVC_CC_Corpus_Target_Translation $target
     * @return array<string, array<string, mixed>>|WP_Error object key => corpus-target-schema.v1
     */
    public static function project($domain, DBVC_CC_Corpus_Target_Translation $target)
    {
        $result = DBVC_CC_V2_Target_Field_Catalog_Service::get_instance()->build_catalog($domain, true);
        if (is_wp_error($result)) {
            return $result;
        }

        $catalog = isset($result['catalog']) && is_array($result['catalog']) ? $result['catalog'] : [];
        $object_keys = $target->post_type_object_keys();
        $bridge = DBVC_CC_V2_Interpretation_Bridge_Service::get_instance();

        $projection = [];
        foreach ($object_keys as $object_key) {
            $schema = $bridge->build_target_schema(
                $catalog,
                $object_key,
                array_values(array_diff($object_keys, [$object_key]))
            );
            unset($schema['generated_at'], $schema['source'], $schema['name']);
            $projection[$object_key] = $schema;
        }

        return $projection;
    }
}
