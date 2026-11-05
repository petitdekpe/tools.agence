<?php

namespace App\Controller;

use App\Service\SiteSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Manifeste de l'application installable (PWA). Public : le navigateur le lit sans session. Le service worker est public/sw.js. */
class PwaController extends AbstractController
{
    // Sans extension : le serveur intégré de PHP (php -S) répond 404 à une adresse en « .webmanifest » qui n'est pas un fichier.
    #[Route('/manifest', name: 'app_manifest', methods: 'GET')]
    public function manifest(SiteSettings $settings): JsonResponse
    {
        $site = $settings->get();
        $name = $site->getHeaderTitle();

        $response = new JsonResponse([
            'id' => '/',
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'description' => $site->getHeaderSubtitle() ?: 'La bibliothèque d\'outils de PROCOM',
            'lang' => 'fr',
            'start_url' => '/?source=pwa',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => '#ffffff',
            'icons' => [
                ['src' => '/pwa/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/pwa/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/pwa/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                ['name' => 'Calendrier éditorial', 'url' => '/calendrier', 'icons' => [['src' => '/pwa/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png']]],
                ['name' => 'Mon profil', 'url' => '/profil', 'icons' => [['src' => '/pwa/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png']]],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json; charset=utf-8']);
        $response->setEncodingOptions(\JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
