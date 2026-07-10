<?php

use App\Services\Downloads\NativeFallbackMediaValidator;

function validateNativeFallbackArtifact(string $contents, ?string $contentType = null): ?string
{
    $path = tempnam(sys_get_temp_dir(), 'atlas-native-validator-');
    if (! is_string($path)) {
        throw new RuntimeException('Unable to create test artifact.');
    }

    try {
        file_put_contents($path, $contents);

        return (new NativeFallbackMediaValidator)->rejectionForArtifact($path, $contentType);
    } finally {
        @unlink($path);
    }
}

it('rejects declared HTML content types', function (string $contentType) {
    expect((new NativeFallbackMediaValidator)->rejectionForContentType($contentType))
        ->toBe(NativeFallbackMediaValidator::HTML_REJECTION);
})->with([
    'html with parameters' => 'Text/HTML; Charset=UTF-8',
    'xhtml' => 'application/xhtml+xml',
]);

it('rejects HTML bytes despite a misleading media type', function () {
    $artifact = "\xEF\xBB\xBF <!-- gateway --><html><body>blocked</body></html>";

    expect(validateNativeFallbackArtifact($artifact, 'video/mp4'))
        ->toBe(NativeFallbackMediaValidator::HTML_REJECTION);
});

it('accepts binary media and SVG artifacts', function (string $artifact, string $contentType) {
    expect(validateNativeFallbackArtifact($artifact, $contentType))->toBeNull();
})->with([
    'mp4 prefix' => ["\x00\x00\x00\x18ftypmp42binary", 'video/mp4'],
    'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'image/svg+xml'],
]);

it('bounds artifact sniffing to the declared prefix size', function () {
    $artifact = str_repeat(' ', NativeFallbackMediaValidator::SNIFF_BYTES).'<html>outside bound</html>';

    expect(validateNativeFallbackArtifact($artifact, 'application/octet-stream'))->toBeNull();
});

it('rejects an empty artifact', function () {
    expect(validateNativeFallbackArtifact('', 'video/mp4'))
        ->toBe(NativeFallbackMediaValidator::INSPECTION_REJECTION);
});
