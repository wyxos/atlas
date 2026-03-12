<?php

test('manifest icon file is a valid png', function () {
    $iconPath = public_path('android-chrome-144x144.png');

    expect($iconPath)->toBeFile();
    expect(file_get_contents($iconPath))
        ->toStartWith("\x89PNG\r\n\x1a\n");
});
