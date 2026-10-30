<?php

use App\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/conversions.php';
require __DIR__.'/accounts.php';

Route::get('/robots.txt', [PageController::class, 'robots']);
Route::get('/sitemap.xml', [PageController::class, 'sitemap']);
foreach (['/', '/outils', '/mes-fichiers', '/faq', '/confidentialite', '/conditions', '/mentions-legales', '/connexion', '/inscription', '/mot-de-passe-oublie', '/reinitialiser-mot-de-passe', '/profil', '/administration', '/offres', '/tarifs', '/mon-abonnement', '/souscription'] as $path) {
    Route::get($path, [PageController::class, 'show']);
}
Route::get('/outils/{slug}', [PageController::class, 'show']);
// API and private paths must never be swallowed by the SPA fallback.
