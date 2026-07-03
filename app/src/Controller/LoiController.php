<?php

namespace App\Controller;

use App\Repository\AmendementRepository;
use App\Repository\DebatRepository;
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
    public function show(string $id, LoiRepository $lois, AmendementRepository $amendements): Response
    {
        $loi = $lois->find($id);

        if ($loi === null) {
            throw $this->createNotFoundException(sprintf('Loi « %s » introuvable.', $id));
        }

        $dossier = $amendements->findDossierPourLoi($loi['num']);

        return $this->render('loi/show.html.twig', [
            'loi' => $loi,
            'articles' => $lois->findArticles($loi['id']),
            'nbAmendements' => $dossier !== null ? $amendements->countPourDossier($dossier['uid']) : null,
        ]);
    }

    #[Route('/loi/{id}/amendements', name: 'loi_amendements', requirements: ['id' => '[A-Z0-9]{20}'])]
    public function amendements(string $id, LoiRepository $lois, AmendementRepository $amendements): Response
    {
        $loi = $lois->find($id);

        if ($loi === null) {
            throw $this->createNotFoundException(sprintf('Loi « %s » introuvable.', $id));
        }

        $dossier = $amendements->findDossierPourLoi($loi['num']);
        $phases = $dossier !== null ? $amendements->findPourDossier($dossier['uid']) : [];

        $sorts = ['adopte' => 0, 'rejete' => 0, 'irrecevable' => 0, 'autre' => 0, 'attente' => 0];
        $total = 0;
        foreach ($phases as $phase) {
            foreach ($phase['amendements'] as $amendement) {
                ++$sorts[$amendement['sort_classe']];
                ++$total;
            }
        }

        return $this->render('loi/amendements.html.twig', [
            'loi' => $loi,
            'dossier' => $dossier,
            'phases' => $phases,
            'total' => $total,
            'sorts' => $sorts,
        ]);
    }

    #[Route('/loi/{id}/amendement/{uid}', name: 'loi_amendement', requirements: ['id' => '[A-Z0-9]{20}', 'uid' => '[A-Z0-9]+'])]
    public function amendement(
        string $id,
        string $uid,
        LoiRepository $lois,
        AmendementRepository $amendements,
        DebatRepository $debats,
    ): Response {
        $loi = $lois->find($id);
        $amendement = $amendements->findOne($uid);

        if ($loi === null || $amendement === null) {
            throw $this->createNotFoundException('Amendement introuvable.');
        }

        return $this->render('loi/amendement.html.twig', [
            'loi' => $loi,
            'a' => $amendement,
            'debat' => $debats->findExtrait($amendement['seance_ref'], $amendement['numero']),
        ]);
    }
}
