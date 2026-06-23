<?php

return [
    'global_settings' => [
        'label' => 'Bricks Settings',
        'options' => ['bricks_global_settings'],
        'portable' => true,
        'phase' => 1,
        'matcher' => 'whole_object',
        'normalizer' => 'global_settings',
        'risk' => 'medium',
        'supports' => ['replace', 'keep', 'skip'],
    ],

    'color_palette' => [
        'label' => 'Color Palettes',
        'options' => ['bricks_color_palette'],
        'portable' => true,
        'phase' => 1,
        'matcher' => 'palette_entry',
        'normalizer' => 'palette',
        'risk' => 'low',
        'supports' => ['add', 'replace', 'keep', 'skip'],
    ],

    'global_classes' => [
        'label' => 'Global Classes',
        'options' => ['bricks_global_classes', 'bricks_global_classes_categories'],
        'portable' => true,
        'phase' => 1,
        'matcher' => 'class_object',
        'normalizer' => 'global_classes',
        'risk' => 'medium',
        'supports' => ['add', 'replace', 'keep', 'skip'],
    ],

    'global_variables' => [
        'label' => 'Global CSS Variables',
        'options' => ['bricks_global_variables', 'bricks_global_variables_categories'],
        'portable' => true,
        'phase' => 1,
        'matcher' => 'variable_object',
        'normalizer' => 'global_variables',
        'risk' => 'low',
        'supports' => ['add', 'replace', 'keep', 'skip'],
    ],
];
