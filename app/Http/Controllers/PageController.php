<?php

namespace App\Http\Controllers;

use App\Services\Conversions\EngineRegistry;
use App\Services\Conversions\PipelineException;
use Illuminate\Http\Request;

class PageController extends Controller
{
    public const PAGES = [
        '/tarifs' => ['Tarifs — Convert', 'Découvrez la formule gratuite et les offres Pro et Business avec essai gratuit, avec des quotas transparents.'],
        '/' => ['Convert — Convertir images, documents, audio et vidéo', 'Convertissez vos fichiers avec des formats vérifiés, des limites claires et des téléchargements privés.'],
        '/outils' => ['Tous les outils de conversion — Convert', 'Choisissez parmi les outils images, PDF, documents, audio et vidéo réellement disponibles sur cet hôte.'],
        '/faq' => ['Aide et questions fréquentes — Convert', 'Formats compatibles, quotas, conservation et récupération de vos résultats : les réponses utiles pour utiliser Convert.'],
        '/offres' => ['Accès visiteur et compte gratuit — Convert', 'Comparez les quotas actuels du visiteur et du compte gratuit. Aucun abonnement payant n’est proposé.'],
        '/confidentialite' => ['Confidentialité — Convert', 'Stockage privé, conservation limitée et gestion de vos données sur Convert.'],
        '/conditions' => ['Conditions d’utilisation — Convert', 'Vos droits sur les fichiers, les limites du service et les règles d’utilisation de Convert.'],
        '/mentions-legales' => ['Mentions légales — Convert', 'Informations sur l’éditeur et l’hébergement à compléter avant ouverture publique.'],
    ];

    public function show(Request $request)
    {
        $path = '/'.$request->path();
        if ($request->path() === '/') {
            $path = '/';
        }
        $meta = self::PAGES[$path] ?? ['Votre espace privé — Convert', 'Accédez à votre compte et à vos fichiers privés.'];
        $public = isset(self::PAGES[$path]) && ! in_array($path, ['/confidentialite', '/conditions', '/mentions-legales'], true);
        $tool = null;
        if (str_starts_with($path, '/outils/')) {
            $tool = collect(config('conversion.tools'))->firstWhere('slug', $request->route('slug'));
            abort_unless($tool, 404);
            try {
                $engine = app(EngineRegistry::class)->get($tool['slug']);
            } catch (PipelineException) {
                $engine = null;
            }
            $public = $engine !== null;
            $meta = [$tool['name'].' — Convert', $tool['description'].($engine ? ' Formats de sortie vérifiés : '.strtoupper(implode(', ', $engine->targetFormats())).'.' : ' Cet outil est actuellement indisponible.')];
        }
        $canonical = rtrim(config('app.url'), '/').$path;
        $schema = $public ? ['@context' => 'https://schema.org', '@type' => $tool ? 'WebApplication' : 'WebPage', 'name' => $meta[0], 'description' => $meta[1], 'url' => $canonical, 'inLanguage' => 'fr'] : null;
        if ($tool && $schema) {
            $schema += ['applicationCategory' => 'UtilitiesApplication', 'operatingSystem' => 'Web'];
        }

        return response()->view('app', ['seo' => ['title' => $meta[0], 'description' => $meta[1], 'canonical' => $canonical,
            'robots' => $public && config('seo.indexing') ? 'index, follow' : 'noindex, nofollow', 'schema' => $schema]])
            ->header('Cache-Control', 'private, no-store');
    }

    public function robots()
    {
        $text = "User-agent: *\nDisallow: /\n";
        if (config('seo.indexing')) {
            $text = "User-agent: *\nAllow: /\nDisallow: /api/\nDisallow: /mes-fichiers\nDisallow: /profil\nDisallow: /mon-abonnement\nDisallow: /souscription\nDisallow: /administration\nDisallow: /connexion\nDisallow: /inscription\nDisallow: /mot-de-passe-oublie\nDisallow: /reinitialiser-mot-de-passe\nSitemap: ".rtrim(config('app.url'), '/')."/sitemap.xml\n";
        }

        return response($text)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function sitemap()
    {
        $paths = config('seo.indexing') ? ['/', '/outils', '/faq', '/tarifs'] : [];
        if (config('seo.indexing')) {
            foreach (config('conversion.tools') as $tool) {
                try {
                    app(EngineRegistry::class)->get($tool['slug']);
                    $paths[] = '/outils/'.$tool['slug'];
                } catch (PipelineException) { /* Planned capabilities are excluded. */
                }
            }
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($paths as $path) {
            $xml .= '<url><loc>'.htmlspecialchars(rtrim(config('app.url'), '/').$path, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></url>';
        }

        return response($xml.'</urlset>')->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
