<?php

namespace App\Controller;

use App\Repository\LoiRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class SitemapController extends AbstractController
{
    /**
     * Sitemap XML : accueil, une entrée par loi et les pages fixes.
     *
     * La liste des ids est mise en cache 24 h (elle ne bouge qu'au
     * app:sync:canutes) ; les URL absolues sont générées à chaque requête
     * pour suivre l'hôte et le schéma réellement servis.
     */
    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET', 'HEAD'], format: 'xml')]
    public function index(LoiRepository $lois, CacheInterface $cache): Response
    {
        $ids = $cache->get('sitemap_lois', static function (ItemInterface $item) use ($lois): array {
            $item->expiresAfter(86400);

            return $lois->findIdsPourSitemap();
        });

        $response = $this->render('sitemap.xml.twig', ['ids' => $ids]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
