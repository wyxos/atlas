<?php

namespace Tests\Feature;

use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FileTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_file(): void
    {
        $file = File::create([
            'source' => 'YouTube',
            'url' => 'https://example.com/video.mp4',
            'filename' => 'example-video.mp4',
        ]);

        $this->assertDatabaseHas('files', [
            'id' => $file->id,
            'source' => 'YouTube',
            'url' => 'https://example.com/video.mp4',
            'filename' => 'example-video.mp4',
        ]);
    }

    public function test_file_attributes_and_casts(): void
    {
        $file = File::create([
            'source' => 'NAS',
            'url' => 'https://nas.example.com/file.mp4',
            'filename' => 'file.mp4',
            'tags' => ['tag1', 'tag2'],
            'is_blacklisted' => true,
            'liked' => true,
            'downloaded' => true,
            'download_progress' => 100,
            'seen_preview_at' => now(),
        ]);

        $retrievedFile = File::find($file->id);

        $this->assertIsArray($retrievedFile->tags);
        $this->assertEquals(['tag1', 'tag2'], $retrievedFile->tags);

        $this->assertIsBool($retrievedFile->is_blacklisted);
        $this->assertTrue($retrievedFile->is_blacklisted);

        $this->assertIsBool($retrievedFile->liked);
        $this->assertTrue($retrievedFile->liked);

        $this->assertIsBool($retrievedFile->downloaded);
        $this->assertTrue($retrievedFile->downloaded);

        $this->assertIsInt($retrievedFile->download_progress);
        $this->assertEquals(100, $retrievedFile->download_progress);

        $this->assertInstanceOf(\Carbon\Carbon::class, $retrievedFile->seen_preview_at);
    }
}
