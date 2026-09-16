<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Тумблер «Показывать пункт в левом меню» должен управлять пунктом меню только
 * тогда, когда его переключили. Иначе обычная правка категории (текст, фото)
 * выбивает раздел из меню — так в сентябре и пропали СВИТШОТЫ, ЗИП ХУДИ и другие.
 */
class CategoryMenuToggleTest extends TestCase
{
    use RefreshDatabase;

    private Menu $menu;

    private Category $category;

    private MenuItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->menu = Menu::create(['key' => 'primary', 'name' => 'Главное меню']);

        $this->category = Category::create([
            'slug' => 't-shirt', 'name' => 'T-SHIRT', 'is_active' => true, 'is_virtual' => false,
            'show_in_menu' => false,
        ]);

        $this->item = MenuItem::create([
            'menu_id' => $this->menu->id,
            'label' => 'ФУТБОЛКИ',
            'linkable_type' => Category::class,
            'linkable_id' => $this->category->id,
            'sort_order' => 1,
            'is_active' => true,
        ]);
    }

    public function test_обычная_правка_категории_не_выключает_пункт_меню(): void
    {
        $this->category->update(['intro_text' => '<p>Новый текст</p>']);

        $this->assertTrue((bool) $this->item->fresh()->is_active);
    }

    public function test_обычная_правка_не_перебивает_подпись_пункта(): void
    {
        $this->category->update(['intro_text' => '<p>Новый текст</p>']);

        $this->assertSame('ФУТБОЛКИ', $this->item->fresh()->label);
    }

    public function test_выключение_тумблера_убирает_пункт_из_меню(): void
    {
        $this->category->update(['show_in_menu' => false, 'name' => 'T-SHIRT']);
        $this->assertTrue((bool) $this->item->fresh()->is_active, 'тумблер не менялся — пункт не трогаем');

        $this->category->update(['show_in_menu' => true]);
        $this->category->update(['show_in_menu' => false]);

        $this->assertFalse((bool) $this->item->fresh()->is_active);
    }

    public function test_включение_тумблера_возвращает_пункт_с_прежней_подписью(): void
    {
        $this->category->update(['show_in_menu' => true]);

        $item = $this->item->fresh();

        $this->assertTrue((bool) $item->is_active);
        $this->assertSame('ФУТБОЛКИ', $item->label);
    }

    public function test_подпись_из_карточки_категории_применяется_к_пункту(): void
    {
        $this->category->update(['show_in_menu' => true, 'menu_label' => 'ФУТБОЛКИ И ЛОНГСЛИВЫ']);

        $this->assertSame('ФУТБОЛКИ И ЛОНГСЛИВЫ', $this->item->fresh()->label);
    }

    public function test_новая_категория_с_тумблером_получает_пункт_меню(): void
    {
        $collection = Category::create([
            'slug' => 'all-star', 'name' => 'ALL STAR COLLECTION', 'is_active' => true, 'is_virtual' => false,
            'show_in_menu' => true,
        ]);

        $item = MenuItem::where('linkable_type', Category::class)
            ->where('linkable_id', $collection->id)
            ->first();

        $this->assertNotNull($item);
        $this->assertTrue((bool) $item->is_active);
        $this->assertSame('ALL STAR COLLECTION', $item->label);
    }
}
