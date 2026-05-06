<?php

$atlasRoot = env('ATLAS_STORAGE');
$atlasRoot = $atlasRoot ? rtrim($atlasRoot, '\\/') : storage_path('app/atlas');

return [
    'root' => $atlasRoot,
    'disk' => 'atlas',
];
