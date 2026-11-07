<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_image_upload_returns_its_public_url_and_size(): void
    {
        Storage::fake('public');
        $this->signIn();

        $response = $this->post('/v1/uploads', [
            'file' => UploadedFile::fake()->image('cover.jpg', 1200, 800),
        ], ['Accept' => 'application/json'])->assertCreated();

        $url = $response->json('url');
        $this->assertStringContainsString('/storage/uploads/', $url);
        $this->assertSame(1200, $response->json('width'));
        $this->assertSame(800, $response->json('height'));
        Storage::disk('public')->assertExists(str($url)->after('/storage/')->toString());
    }

    public function test_only_images_are_accepted(): void
    {
        Storage::fake('public');
        $this->signIn();

        $this->post('/v1/uploads', [
            'file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_uploads_need_a_signed_in_user(): void
    {
        $this->post('/v1/uploads', [
            'file' => UploadedFile::fake()->image('cover.jpg'),
        ], ['Accept' => 'application/json'])->assertUnauthorized();
    }
}
