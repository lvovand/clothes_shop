<?php

namespace Tests\Feature;

use App\Filament\Pages\ShippingPaymentSettings;
use App\Filament\Resources\WarehouseResource\Pages\EditWarehouse;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WarehouseAdminSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_edit_page_renders_shipping_origin_fields(): void
    {
        $this->actingAs(User::factory()->create());

        $warehouse = Warehouse::where('code', 'orenburg')->firstOrFail();

        Livewire::test(EditWarehouse::class, ['record' => $warehouse->getRouteKey()])
            ->assertFormFieldExists('cdek_sender_city_code')
            ->assertFormFieldExists('cdek_shipment_point')
            ->assertFormFieldExists('yandex_dropoff_city')
            ->assertFormFieldExists('yandex_dropoff_id')
            ->assertSuccessful();
    }

    public function test_shipping_settings_page_renders_without_origin_fields(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ShippingPaymentSettings::class)
            ->assertSuccessful();
    }
}
