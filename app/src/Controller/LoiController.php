<?php

namespace App\Controller;

use App\Repository\LoiRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LoiController extends AbstractController
{
    private const PAR_PAGE = 25;

    #[Route('/', name: 'loi_index')]
    public function index(Request $request, LoiRepository $lois): Response
    {
        $query = $request->query->get('q');
        $page = max(1, $request->query->getInt('page', 1));

        $resultat = $lois->search($query, $page, self::PAR_PAGE);

        return $this->render('loi/index.html.twig', [
            'lois' => $resultat['lois'],
            'total' => $resultat['total'],
            'query' => $query,
            'page' => $page,
            'pages' => max(1, (int) ceil($resultat['total'] / self::PAR_PAGE)),
        ]);
    }

    #[Route('/loi/{id}', name: 'loi_show', requirements: ['id' => '[A-Z0-9]{20}'])]
    public function show(string $id, LoiRepository $lois): Response
    {
        $loi = $lois->find($id);

        if ($loi === null) {
            throw $this->createNotFoundException(sprintf('Loi « %s » introuvable.', $id));
        }

        return $this->render('loi/show.html.twig', [
            'loi' => $loi,
            'articles' => $lois->findArticles($loi['id']),
        ]);
    }
}
