<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Laravel\Sanctum\Sanctum;

// === Import model yang dipakai di morph ===
// Sesuaikan dengan model yang kamu punya
use App\Models\PersonalAccessToken;
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
        // Strict mode (bagus untuk dev; kalau production bikin ribet, kamu boleh matikan sebagian)
        Model::shouldBeStrict(true);
        Model::preventLazyLoading(true);
        Model::preventSilentlyDiscardingAttributes(true);

        // Sanctum pakai model kustom kamu
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // === PENTING: Morph Map untuk polymorphic relation ===
        // Mapping string di kolom target_type -> class model
        Relation::enforceMorphMap([
            'voucher'  => Voucher::class,
            'promo'    => Promo::class,

            // Kalau notifikasi kamu pernah mengarah ke entity lain, boleh dimasukkan juga:
            'ad'       => Ad::class,
            'grab'     => Grab::class,
            'cube'     => Cube::class,
        ]);
    }
}
