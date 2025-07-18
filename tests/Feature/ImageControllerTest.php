<?php

use App\Models\File;
use App\Models\User;

it('can access images index page', function () {
    // Create and authenticate a user
    $user = User::factory()->create();
    $this->actingAs($user);

    // Create some test image files
    $image1 = File::create([
        'source' => 'test',
        'filename' => 'test-image.jpg',
        'path' => 'images/test-image.jpg',
        'size' => 2048,
        'mime_type' => 'image/jpeg',
        'hash' => 'image123',
        'not_found' => false,
    ]);

    $image2 = File::create([
        'source' => 'test',
        'filename' => 'another-image.png',
        'path' => 'images/another-image.png',
        'size' => 1024,
        'mime_type' => 'image/png',
        'hash' => 'image456',
        'not_found' => false,
    ]);

    // Test that the images route is accessible
    $response = $this->get('/images');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Images')
        ->has('images')
        ->has('search')
    );
});

it('handles special characters in image paths correctly', function () {
    // Create and authenticate a user
    $user = User::factory()->create();
    $this->actingAs($user);

    // Create test image files with special characters in paths
    $imageWithSpaces = File::create([
        'source' => 'test',
        'filename' => 'image with spaces.jpg',
        'path' => 'images/folder with spaces/image with spaces.jpg',
        'size' => 2048,
        'mime_type' => 'image/jpeg',
        'hash' => 'image_spaces',
        'not_found' => false,
    ]);

    $imageWithSpecialChars = File::create([
        'source' => 'test',
        'filename' => 'image[special]chars.jpg',
        'path' => 'images/folder[brackets]/image[special]chars.jpg',
        'size' => 2048,
        'mime_type' => 'image/jpeg',
        'hash' => 'image_special',
        'not_found' => false,
    ]);

    $imageWithUnicode = File::create([
        'source' => 'test',
        'filename' => 'imagé_ñoñó.jpg',
        'path' => 'images/foldér/imagé_ñoñó.jpg',
        'size' => 2048,
        'mime_type' => 'image/jpeg',
        'hash' => 'image_unicode',
        'not_found' => false,
    ]);

    // Make request to images index
    $response = $this->get('/images');

    $response->assertStatus(200);

    $data = $response->getOriginalContent()->getData();
    $images = $data['page']['props']['images']['data'];

    // Find our test images in the response
    $foundImages = collect($images)->whereIn('hash', ['image_spaces', 'image_special', 'image_unicode']);

    expect($foundImages)->toHaveCount(3);

    // Check that each image has a properly formatted URL with encoded special characters
    foreach ($foundImages as $image) {
        // Verify the raw path still exists
        expect($image['path'])->toBeString();
        expect($image['path'])->not->toBeEmpty();

        // Verify the new image_url field exists and is properly encoded
        expect($image)->toHaveKey('image_url');
        expect($image['image_url'])->toBeString();
        expect($image['image_url'])->toStartWith('/atlas/');

        // Verify that special characters are properly encoded in the URL
        if (str_contains($image['path'], ' ')) {
            expect($image['image_url'])->toContain('%20'); // Space should be encoded
        }
        if (str_contains($image['path'], '[')) {
            expect($image['image_url'])->toContain('%5B'); // [ should be encoded
        }
        if (str_contains($image['path'], ']')) {
            expect($image['image_url'])->toContain('%5D'); // ] should be encoded
        }
    }
});

it('can search images with special characters', function () {
    // Create and authenticate a user
    $user = User::factory()->create();
    $this->actingAs($user);

    // Create test image with special characters
    $image = File::create([
        'source' => 'test',
        'filename' => 'special[image].jpg',
        'path' => 'images/special[folder]/special[image].jpg',
        'size' => 2048,
        'mime_type' => 'image/jpeg',
        'hash' => 'special_search',
        'not_found' => false,
        'title' => 'Special Image Title',
    ]);

    // Test search functionality
    $response = $this->get('/images?query=special');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Images')
        ->has('images')
        ->has('search')
    );
});
