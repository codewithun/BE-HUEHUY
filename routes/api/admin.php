<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Admin\AdCategoryController;
use App\Http\Controllers\Admin\AdController;
use App\Http\Controllers\Admin\AdminContactController;
use App\Http\Controllers\Admin\ArticleController;
use App\Http\Controllers\Admin\BannerController;
use App\Http\Controllers\Admin\CorporateController;
use App\Http\Controllers\Admin\CubeController;
use App\Http\Controllers\Admin\CubeTypeController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DynamicContentController;
use App\Http\Controllers\Admin\EventController;
use App\Http\Controllers\Admin\FaqController;
use App\Http\Controllers\Admin\GrabController;
use App\Http\Controllers\Admin\HuehuyAdController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\OpeningHourController;
use App\Http\Controllers\Admin\QrcodeController;
use App\Http\Controllers\Admin\ReportContentTicketController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\VoucherController;
use App\Http\Controllers\Admin\VoucherItemController;
use App\Http\Controllers\Admin\WorldAffiliateController;
use App\Http\Controllers\Admin\WorldController;
use App\Http\Controllers\AppConfigController;
use App\Http\Controllers\PicklistController;
use App\Http\Controllers\Admin\OptionController;
use App\Http\Controllers\Admin\PromoController;
use App\Http\Controllers\Admin\PromoItemController;
use App\Http\Controllers\Admin\CommunityController;


Route::prefix('admin')->name('admin.')->group(function () {

    // * Picklist Options
    Route::prefix('/options')->group(function () {
        Route::get('/role', [PicklistController::class, 'role']);
        Route::get('/cube-type', [PicklistController::class, 'cubeType']);
        Route::get('/cube', [PicklistController::class, 'cube']);
        // Standardize: ad-category options served by OptionController (with image + wrapper)
        Route::get('/ad-category', [OptionController::class, 'adCategory']);
        Route::get('/corporate', [PicklistController::class, 'corporate']);
        Route::get('/world', [PicklistController::class, 'world']);
        Route::get('/user', [PicklistController::class, 'user']);
        Route::get('/community', [PicklistController::class, 'community']);
    });

    // Moved to public routes in main api.php for public access
    // Route::get('/app-config', [AppConfigController::class, 'index']);
    // Route::get('/app-config/{id}', [AppConfigController::class, 'show']);
    Route::post('/app-config/update-other-category', [AppConfigController::class, 'updateOtherCategoryProduct']);
    Route::post('/app-config/{id}', [AppConfigController::class, 'update']);

    Route::get('/dashboard/counter-data', [DashboardController::class, 'counterData']);

    Route::apiResource('/users', UserController::class);
    Route::put('/users/{id}/update-point', [UserController::class, 'updatePoint']);
    Route::put('/users/{id}/update-role', [UserController::class, 'updateRole']);
    Route::post('/users/{id}/assign-to-corporate', [UserController::class, 'assignToCorporate']);
    Route::delete('/users/{id}/remove-from-corporate', [UserController::class, 'removeFromCorporate']);
    Route::get('/users/check-roles-structure', [UserController::class, 'checkRolesStructure']);
    Route::post('/users/fix-corrupt-roles', [UserController::class, 'fixCorruptRoles']);
    Route::apiResource('/roles', RoleController::class);

    Route::apiResource('/articles', ArticleController::class);
    Route::apiResource('/faqs', FaqController::class);
    Route::apiResource('/banners', BannerController::class);

    Route::apiResource('/cubes', CubeController::class);
    Route::post('/cubes/create-gift', [CubeController::class, 'createGiftCube']);
    Route::put('/cubes/{id}/update-status', [CubeController::class, 'updateStatus']);
    Route::post('/cubes/ads/{id}/validate-code', [CubeController::class, 'validateCode']);
    Route::apiResource('/cube-types', CubeTypeController::class)->only(['index', 'show', 'update']);

    Route::get('/grabs', [GrabController::class, 'index']);

    Route::apiResource('/ads', AdController::class);

    Route::apiResource('/huehuy-ads', HuehuyAdController::class);

    Route::apiResource('/vouchers', VoucherController::class);
    Route::post('/vouchers/{id}/send-to-user', [VoucherController::class, 'sendToUser']);
    Route::get('/vouchers/voucher-items', [VoucherController::class, 'voucherItems']);

    Route::apiResource('/voucher-items', VoucherItemController::class);

    Route::apiResource('/worlds', WorldController::class);
    Route::get('/worlds/{id}/user', [WorldController::class, 'getWorldMember']);
    Route::post('/worlds/{id}/user', [WorldController::class, 'addWorldMember']);
    Route::post('/worlds/{id}/user-new', [WorldController::class, 'createWorldMember']);
    Route::delete('/worlds/{id}/user/{userWorldId}', [WorldController::class, 'destroyWorldMember']);

    Route::apiResource('/world-affiliates', WorldAffiliateController::class);

    Route::apiResource('/corporates', CorporateController::class);
    Route::put('/corporates/{id}/update-point', [CorporateController::class, 'updatePoint']);
    Route::get('/corporates/{id}/user', [CorporateController::class, 'getCorporateMember']);
    Route::post('/corporates/{id}/user', [CorporateController::class, 'addCorporateMember']);
    Route::post('/corporates/{id}/user-new', [CorporateController::class, 'createCorporateMember']);
    Route::delete('/corporates/{id}/user/{corporateUserId}', [CorporateController::class, 'destroyCorporateMember']);
    Route::put('/corporates/{id}/user/{corporateUserId}/update-role', [CorporateController::class, 'updateCorporateMemberRole']);

    Route::apiResource('/ad-categories', AdCategoryController::class);
    Route::apiResource('/admin-contacts', AdminContactController::class);

    Route::post('/opening-hours', [OpeningHourController::class, 'store']);
    Route::put('/opening-hours/{id}', [OpeningHourController::class, 'update']);
    Route::delete('/opening-hours/{id}', [OpeningHourController::class, 'destroy']);

    Route::apiResource('/dynamic-content', DynamicContentController::class);

    Route::apiResource('/report-content-ticket', ReportContentTicketController::class)->only(['index', 'destroy']);
    Route::put('/report-content-ticket/{id}/update-status', [ReportContentTicketController::class, 'updateStatus']);

    Route::get('/notification', [NotificationController::class, 'index']);

    // QR Code routes
    Route::get('/qrcodes', [QrcodeController::class, 'index']);
    Route::post('/qrcodes', [QrcodeController::class, 'generate']);
    Route::put('/qrcodes/{id}', [QrcodeController::class, 'update']);
    Route::delete('/qrcodes/{id}', [QrcodeController::class, 'destroy']);

    Route::apiResource('/promos', PromoController::class);

    // Promo items
    Route::apiResource('/promo-items', PromoItemController::class);
    // helper routes to manage items under a promo
    Route::get('/promos/{promoId}/items', [PromoItemController::class, 'indexByPromo']);
    Route::post('/promos/{promoId}/items', [PromoItemController::class, 'storeForPromo']);
    Route::post('promo-items/{id}/redeem', [PromoItemController::class, 'redeem'])
        ->name('promo-items.redeem') // final name: admin.promo-items.redeem
        ->whereNumber('id');

    Route::apiResource('/communities', CommunityController::class);
    // Tambah ini: daftar anggota komunitas (admin)
    Route::get('/communities/{id}/members', [CommunityController::class, 'adminMembers'])
        ->whereNumber('id');

    // Event routes
    Route::apiResource('/events', EventController::class);
    Route::post('/events/{id}/register', [EventController::class, 'register']);
    Route::get('/events/{id}/registrations', [EventController::class, 'registrations']);

});