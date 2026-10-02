<?php

namespace Tests\Feature;

use Tests\TestCase;

class SpaShellTest extends TestCase
{
    private string $spaPath;

    private bool $createdSpa = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spaPath = public_path('spa.html');
        if (! is_file($this->spaPath)) {
            file_put_contents($this->spaPath, '<!doctype html><title>test</title>');
            $this->createdSpa = true;
        }
    }

    protected function tearDown(): void
    {
        if ($this->createdSpa && is_file($this->spaPath)) {
            unlink($this->spaPath);
        }

        parent::tearDown();
    }

    public function test_spa_shell_must_be_revalidated_so_installed_pwa_gets_new_deploys(): void
    {
        $response = $this->get('/outsource');

        $response->assertOk();
        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
    }
}
