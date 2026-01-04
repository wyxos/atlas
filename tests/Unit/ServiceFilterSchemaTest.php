<?php

use App\Support\ServiceFilterSchema;

test('service filter schema builds fields with mapping and overrides', function () {
    $schema = ServiceFilterSchema::fromMaps(
        keyMap: [
            'page' => 'cursor',
        ],
        typeMap: [
            'page' => 'hidden',
        ],
        labelMap: [
            'page' => 'Page',
        ],
    );

    expect($schema->field('page', ['description' => 'Pagination cursor']))->toBe([
        'uiKey' => 'page',
        'serviceKey' => 'cursor',
        'type' => 'hidden',
        'label' => 'Page',
        'description' => 'Pagination cursor',
    ]);
});

test('service filter schema falls back to headline labels', function () {
    $schema = ServiceFilterSchema::make();

    expect($schema->label('modelVersionId'))->toBe('Model Version Id');
});
