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
    );
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
    $response = $this->get('/images/various?query=special');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Images')
        ->has('images')
    );
});
