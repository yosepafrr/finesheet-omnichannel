<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    public function test_public_information_pages_are_available_without_authentication(): void
    {
        foreach ([
            '/',
            '/privacy-policy',
            '/terms-of-service',
            '/tiktok-ads-integration',
        ] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertViewIs('react');
        }
    }

    public function test_public_shell_exposes_reviewable_business_metadata(): void
    {
        $this->get('/tiktok-ads-integration')
            ->assertOk()
            ->assertSee('Finesheet | Platform Operasional E-commerce')
            ->assertSee('developer@finesheet.id')
            ->assertSee('Yosep Adriana Fauzi R');
    }
}
