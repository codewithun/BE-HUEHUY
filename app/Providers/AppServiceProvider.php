<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Laravel\Sanctum\Sanctum;

// === Import model yang dipakai di morph ===
use App\Models\User;                   // <- WAJIB: untuk morph 'user'
use App\Models\PersonalAccessToken;    // Sanctum token model kustom
use App\Models\Voucher;
use App\Models\Promo;
use App\Models\Ad;
use App\Models\Grab;
use App\Models\Cube;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Mode strict (bagus saat dev; kalau produksi bikin ribet, boleh dimatikan sebagian)
        Model::shouldBeStrict(true);
        Model::preventLazyLoading(true);
        Model::preventSilentlyDiscardingAttributes(true);

        // Pakai model token kustom untuk Sanctum
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // === Morph Map untuk polymorphic relation (WAJIB ada 'user') ===
        Relation::enforceMorphMap([
            'user'    => User::class,          // dipakai Sanctum: tokenable_type = 'user'
            'voucher' => Voucher::class,
            'promo'   => Promo::class,
            'ad'      => Ad::class,
            'grab'    => Grab::class,
            'cube'    => Cube::class,
        ]);
    }
}
