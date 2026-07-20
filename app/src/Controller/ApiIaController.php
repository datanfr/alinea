<?php

namespace App\Controller;

use App\Repository\DemandeAnalyseRepository;
use App\Repository\ResumeIaRepository;
use App\Service\AnalyseAmendementIa;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API entre la prod et l'agent d'analyse local : la prod n'a pas d'Ollama,
 * les analyses IA y sont poussées depuis une machine locale (commande
 * app:ia:demandes) qui consomme ces trois points d'entrée :
 *
 *   GET  /api/ia/demandes                     → demandes en attente + uids à analyser
 *   POST /api/ia/analyses                     → dépôt d'un lot d'analyses (upsert)
 *   POST /api/ia/demandes/{dossier}/cloture   → demande traitée
 *
 * Sécurité : jeton partagé IA_API_JETON, transmis en Authorization: Bearer,
 * comparé en temps constant. Jeton vide côté serveur = API désactivée (404,
 * pour ne pas révéler l'existence des routes).
 */
#[Route('/api/ia')]
class ApiIaController extends AbstractController
{
    public function __construct(
        #[Autowire(env: 'IA_API_JETON')] private readonly string $jeton,
    ) {
    }

    #[Route('/demandes', name: 'api_ia_demandes', methods: ['GET'])]
    public function demandes(
        Request $request,
        DemandeAnalyseRepository $demandes,
        AnalyseAmendementIa $analyseIa,
        ResumeIaRepository $resumes,
    ): JsonResponse {
        $this->verifierJeton($request);

        $liste = [];
        foreach ($demandes->listerEnAttente() as $demande) {
            // Jugés des deux chambres : uids AN (AMANR…) et Sénat (SEN…).
            $uids = array_column($analyseIa->jugesPourDossier($demande['dossier_uid']), 'uid');
            $faites = $resumes->analysesAmendements($uids);

            $liste[] = [
                'dossier' => $demande['dossier_uid'],
                'loi' => $demande['loi_id'],
                'demande_le' => $demande['demande_le'],
                'manquants' => array_values(array_filter(
                    $uids,
                    static fn (string $uid): bool => !isset($faites[$uid])
                )),
            ];
        }

        return $this->json(['demandes' => $liste]);
    }

    /**
     * Dépose un lot d'analyses générées ailleurs. Corps attendu :
     *   { "modele": "gemma4:latest", "analyses": [ { "uid": "AMANR5…",
     *     "resume": "…", "resume_detaille": null, "intention": "…",
     *     "ambiguite": 30, "categorie": "fond", "score_impact": 55 }, … ] }
     * Les entrées invalides sont rejetées une à une (listées dans la réponse),
     * les valides sont enregistrées — l'agent peut réessayer sans tout renvoyer.
     */
    #[Route('/analyses', name: 'api_ia_analyses', methods: ['POST'])]
    public function analyses(Request $request, ResumeIaRepository $resumes): JsonResponse
    {
        $this->verifierJeton($request);

        $corps = json_decode($request->getContent(), true);
        if (!\is_array($corps) || !\is_array($corps['analyses'] ?? null)) {
            return $this->json(['erreur' => 'Corps JSON attendu : {modele, analyses: [...]}.'], Response::HTTP_BAD_REQUEST);
        }

        $modele = \is_string($corps['modele'] ?? null) ? $corps['modele'] : null;
        $enregistrees = 0;
        $rejetees = [];

        foreach ($corps['analyses'] as $i => $entree) {
            $analyse = $this->normaliser($entree);
            if ($analyse === null) {
                $rejetees[] = \is_array($entree) && \is_string($entree['uid'] ?? null) ? $entree['uid'] : "#$i";
                continue;
            }

            $resumes->enregistrerAmendement($entree['uid'], $analyse, $modele);
            ++$enregistrees;
        }

        return $this->json(['enregistrees' => $enregistrees, 'rejetees' => $rejetees]);
    }

    #[Route('/demandes/{dossier}/cloture', name: 'api_ia_cloture', requirements: ['dossier' => '[A-Z0-9]+'], methods: ['POST'])]
    public function cloture(string $dossier, Request $request, DemandeAnalyseRepository $demandes): JsonResponse
    {
        $this->verifierJeton($request);

        $demandes->cloturer($dossier);

        return $this->json(['cloturee' => $dossier]);
    }

    /**
     * Même forme de garde-fous que côté génération : uid plausible (AMANR5…
     * pour l'AN, SEN… pour le Sénat), scores bornés à [0, 100], catégorie
     * dans la liste fermée ou nulle.
     *
     * @return array{resume: string, resume_detaille: ?string, intention: ?string, ambiguite: int, categorie: ?string, score_impact: int}|null
     */
    private function normaliser(mixed $entree): ?array
    {
        if (!\is_array($entree)
            || !\is_string($entree['uid'] ?? null)
            || preg_match('/^[A-Z0-9]{1,40}$/', $entree['uid']) !== 1
            || !\is_string($entree['resume'] ?? null)
        ) {
            return null;
        }

        $detaille = trim((string) ($entree['resume_detaille'] ?? ''));
        $intention = trim((string) ($entree['intention'] ?? ''));
        $categorie = $entree['categorie'] ?? null;

        return [
            'resume' => trim($entree['resume']),
            'resume_detaille' => $detaille !== '' ? $detaille : null,
            'intention' => $intention !== '' ? $intention : null,
            'ambiguite' => min(100, max(0, (int) ($entree['ambiguite'] ?? 0))),
            'categorie' => \in_array($categorie, AnalyseAmendementIa::CATEGORIES, true) ? $categorie : null,
            'score_impact' => min(100, max(0, (int) ($entree['score_impact'] ?? 0))),
        ];
    }

    /**
     * Jeton Bearer comparé en temps constant. 404 si l'API n'est pas
     * configurée, 401 si le jeton manque ou ne correspond pas.
     */
    private function verifierJeton(Request $request): void
    {
        if ($this->jeton === '') {
            throw $this->createNotFoundException();
        }

        $entete = (string) $request->headers->get('Authorization', '');
        $fourni = str_starts_with($entete, 'Bearer ') ? substr($entete, 7) : '';

        if ($fourni === '' || !hash_equals($this->jeton, $fourni)) {
            throw new UnauthorizedHttpException('Bearer', 'Jeton invalide.');
        }
    }
}
