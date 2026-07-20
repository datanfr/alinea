<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PageController extends AbstractController
{
    #[Route('/mentions-legales', name: 'mentions_legales')]
    public function mentionsLegales(): Response
    {
        return $this->render('page/mentions_legales.html.twig');
    }
}
