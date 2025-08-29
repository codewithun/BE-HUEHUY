<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Google\Client as GoogleClient;   // composer require google/apiclient:^2
use App\Models\User;

Route::get('/', function () {
    return response()->json([
        'message' => 'Welcome to the API of ' . config('app.name') . '!',
    ]);
});

/**
 * LOGIN: terima id_token dari Google Identity Services, verifikasi,
 * lalu login-kan user ke session (Sanctum pakai guard "web").
 * FE WAJIB: GET /sanctum/csrf-cookie lalu kirim header X-XSRF-TOKEN.
 */
Route::post('/login/google-idtoken', function (Request $r) {
    $idToken = (string) $r->input('id_token');
    if (!$idToken) {
        return response()->json(['message' => 'id_token required'], 422);
    }

    $client  = new GoogleClient(['client_id' => config('services.google.client_id')]);
    $payload = $client->verifyIdToken($idToken);
    if (!$payload) {
        return response()->json(['message' => 'Invalid ID token'], 401);
    }

    // Hardening
    $iss = $payload['iss'] ?? '';
    if (!in_array($iss, ['accounts.google.com', 'https://accounts.google.com'], true)) {
        return response()->json(['message' => 'Invalid issuer'], 401);
    }
    if (($payload['aud'] ?? null) !== config('services.google.client_id')) {
        return response()->json(['message' => 'Invalid audience'], 401);
    }

    $googleId = $payload['sub'];
    $email    = $payload['email'] ?? null;
    $name     = $payload['name'] ?? ($email ?: 'Google User '.$googleId);
    $avatar   = $payload['picture'] ?? null;
    $emailVerifiedAt = ($payload['email_verified'] ?? false) ? now() : null;

    $user = User::updateOrCreate(
        ['google_id' => $googleId],
        [
            'name'  => $name,
            'email' => $email ?: ($googleId.'@no-email.local'),
            'avatar'=> $avatar,
            'email_verified_at' => $emailVerifiedAt,
        ]
    );

    Auth::login($user, true);
    $r->session()->regenerate(); // anti session fixation

    return response()->json([
        'message' => 'ok',
        'user'    => ['id'=>$user->id,'name'=>$user->name,'email'=>$user->email,'avatar'=>$user->avatar],
    ], 200);
})->name('login.google');

/** LOGOUT (hancurkan sesi) */
Route::post('/logout', function (Request $r) {
    Auth::guard('web')->logout();
    $r->session()->invalidate();
    $r->session()->regenerateToken();
    return response()->noContent(); // 204
})->middleware('auth');

/** Cek siapa yg login di guard web (debug cepat) */
Route::get('/whoami', function (Request $r) {
    return $r->user()
        ? response()->json(['id'=>$r->user()->id,'name'=>$r->user()->name,'email'=>$r->user()->email,'avatar'=>$r->user()->avatar])
        : response()->json(['message'=>'guest'], 200);
})->middleware('auth');

/** Fallback JSON 404 utk route web yg gak ada */
Route::fallback(function () {
    return response()->json(['message' => 'Not Found'], 404);
});
