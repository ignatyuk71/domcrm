<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_existing_pages_have_shared_navigation_and_a_single_active_menu_item(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));

        foreach (['/analytics' => 'sales', '/sole-inventory' => 'soles'] as $path => $tab) {
            $response = $this->get($path)->assertOk()->assertSessionHas('analytics.last_tab', $tab);
            $xpath = $this->xpath($response->getContent());
            $links = $xpath->query('//nav[@aria-label="Розділи аналітики"]/a');
            $this->assertCount(2, $links);
            $this->assertSame(url('/analytics'), $links->item(0)->getAttribute('href'));
            $this->assertSame(url('/sole-inventory'), $links->item(1)->getAttribute('href'));
            $activeTabs = $xpath->query('//nav[@aria-label="Розділи аналітики"]/a[@aria-current="page"]');
            $this->assertCount(1, $activeTabs);
            $this->assertSame(url($path), $activeTabs->item(0)->getAttribute('href'));

            $menu = $xpath->query('//nav[@class="sidebar-nav"]/a[@data-analytics-menu]');
            $this->assertCount(1, $menu);
            $this->assertSame('Аналітика', trim($menu->item(0)->textContent));
            $this->assertSame('page', $menu->item(0)->getAttribute('aria-current'));
            $this->assertStringContainsString('active', $menu->item(0)->getAttribute('class'));
            // Окремого складського пункту для підошви більше немає.
            $this->assertCount(0, $xpath->query('//nav[@class="sidebar-nav"]/a[not(@data-analytics-menu)]/span[normalize-space(.)="Запас підошви"]'));
        }
    }

    public function test_sidebar_remembers_last_tab_when_leaving_analytics(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));

        foreach (['/sole-inventory' => 'soles', '/analytics' => 'sales'] as $path => $tab) {
            $this->get($path)->assertOk();
            $response = $this->get('/orders')->assertOk()->assertSessionHas('analytics.last_tab', $tab);
            $menu = $this->xpath($response->getContent())->query('//a[@data-analytics-menu]')->item(0);
            $this->assertSame(url($path), $menu->getAttribute('href'));
            $this->assertFalse($menu->hasAttribute('aria-current'));
        }
    }

    public function test_sidebar_defaults_to_sales_and_never_uses_unknown_saved_values_as_urls(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));

        foreach ([null, 'https://example.invalid'] as $savedTab) {
            $response = $this->withSession(['analytics.last_tab' => $savedTab])->get('/orders')->assertOk();
            $menu = $this->xpath($response->getContent())->query('//a[@data-analytics-menu]')->item(0);
            $this->assertSame(route('analytics.sales'), $menu->getAttribute('href'));
        }
    }

    public function test_direct_sales_links_still_open_sales_even_if_inventory_was_last_selected(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]))
            ->withSession(['analytics.last_tab' => 'soles'])
            ->get('/analytics?date_from=2026-08-01&date_to=2026-08-31')
            ->assertOk()->assertSee('crm-sales-analytics')
            ->assertSessionHas('analytics.last_tab', 'sales');
    }

    public function test_non_owners_cannot_open_either_tab_and_do_not_see_analytics_in_menu(): void
    {
        foreach ([User::ROLE_OPERATOR, User::ROLE_PACKER] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get('/analytics')->assertForbidden();
            $this->get('/sole-inventory')->assertForbidden();
            $this->view('layouts.sidebar')->assertDontSee('data-analytics-menu', false);
        }
    }

    public function test_guests_must_sign_in_before_opening_either_tab(): void
    {
        $this->get('/analytics')->assertRedirect(route('login'));
        $this->get('/sole-inventory')->assertRedirect(route('login'));
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }
}
