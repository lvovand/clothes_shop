<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The old site's desktop and mobile hero banners are two independent slide
     * lists (different image counts, no 1:1 pairing) — this splits the single
     * image_desktop/image_mobile row into one row per device instead.
     */
    public function up(): void
    {
        Schema::table('homepage_slides', function (Blueprint $table) {
            $table->string('device')->default('desktop')->after('id');
            $table->string('image')->nullable()->after('device');
        });

        DB::table('homepage_slides')->orderBy('id')->get()->each(function ($row) {
            DB::table('homepage_slides')->where('id', $row->id)->update([
                'device' => 'desktop',
                'image' => $row->image_desktop,
            ]);

            if ($row->image_mobile) {
                DB::table('homepage_slides')->insert([
                    'device' => 'mobile',
                    'image' => $row->image_mobile,
                    'link_url' => $row->link_url,
                    'link_text' => $row->link_text,
                    'is_active' => $row->is_active,
                    'sort_order' => $row->sort_order,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::table('homepage_slides', function (Blueprint $table) {
            $table->dropColumn(['image_desktop', 'image_mobile']);
        });
    }

    public function down(): void
    {
        Schema::table('homepage_slides', function (Blueprint $table) {
            $table->string('image_desktop')->nullable();
            $table->string('image_mobile')->nullable();
        });

        DB::table('homepage_slides')->where('device', 'desktop')->orderBy('id')->get()->each(function ($row) {
            DB::table('homepage_slides')->where('id', $row->id)->update(['image_desktop' => $row->image]);
        });

        DB::table('homepage_slides')->where('device', 'mobile')->delete();

        Schema::table('homepage_slides', function (Blueprint $table) {
            $table->dropColumn(['device', 'image']);
        });
    }
};
