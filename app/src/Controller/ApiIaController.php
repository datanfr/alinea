<?php

namespace App\Controller;

use App\Repository\ConsultationLoiRepository;
use App\Repository\DemandeAnalyseRepository;
use App\Repository\ResumeIaRepository;
use App\Service\AnalyseAmendementIa;
use App\Service\NotificationAnalyse;
use Doctrine\DBAL\Connection;
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
 *   GET  /api/ia/interet                      → signaux de priorité (consultations, demandes)
 *   POST /api/ia/analysees                    → parmi des uids, ceux déjà analysés ici
 *   POST /api/ia/analyses                     → dépôt d'un lot d'analyses (upsert)
 *   POST /api/ia/demandes/{dossier}/cloture   → demande traitée
 *
 * La prod est la source de vérité de ce qui est publié : sa base contient
 * des analyses que la machine locale n'a pas (celles qu'elle a générées
 * elle-même quand IA_SEUIL_ANALYSE_DIRECTE était non nul). L'agent local
 * interroge donc /analysees avant de travailler, sans quoi il refait — et
 * dégrade — ce qui existe déjà.
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
     * Signaux de priorité pour le traitement de fond (app:ia:demandes
     * --toutes) : consultations des pages de lois sur 90 jours, et
     * historique des demandes déposées (clôturées comprises), par dossier.
     */
    #[Route('/interet', name: 'api_ia_interet', methods: ['GET'])]
    public function interet(
        Request $request,
        ConsultationLoiRepository $consultations,
        Connection $connection,
    ): JsonResponse {
        $this->verifierJeton($request);

        $demandes = $connection->fetchAllAssociative(
            'SELECT dossier_uid, loi_id, count(*) AS nb, max(demande_le) AS derniere
             FROM alinea.demande_analyse GROUP BY dossier_uid, loi_id'
        );

        return $this->json([
            'consultations' => $consultations->totalParLoi(90),
            'demandes' => array_map(static fn (array $d): array => [
                'dossier' => $d['dossier_uid'],
                'loi' => $d['loi_id'],
                'nb' => (int) $d['nb'],
                'derniere' => $d['derniere'],
            ], $demandes),
        ]);
    }

    /**
     * Parmi une liste d'uids, ceux qui ont déjà une analyse ici, avec le
     * modèle qui l'a produite. Corps attendu : { "uids": ["AMANR5…", …] }.
     *
     * POST plutôt que GET : la liste porte volontiers plusieurs milliers
     * d'uids, ce qui déborde une URL. Sans effet de bord malgré le verbe.
     */
    #[Route('/analysees', name: 'api_ia_analysees', methods: ['POST'])]
    public function analysees(Request $request, ResumeIaRepository $resumes): JsonResponse
    {
        $this->verifierJeton($request);

        $corps = json_decode($request->getContent(), true);
        if (!\is_array($corps) || !\is_array($corps['uids'] ?? null)) {
            return $this->json(['erreur' => 'Corps JSON attendu : {uids: [...]}.'], Response::HTTP_BAD_REQUEST);
        }

        $uids = array_values(array_filter(
            $corps['uids'],
            static fn (mixed $uid): bool => \is_string($uid) && preg_match('/^[A-Z0-9]{1,40}$/', $uid) === 1
        ));

        return $this->json(['analysees' => $resumes->modelesAmendements($uids)]);
    }

    /**
     * Dépose un lot d'analyses générées ailleurs. Corps attendu :
     *   { "modele": "gemma4:latest", "analyses": [ { "uid": "AMANR5…",
     *     "resume": "…", "resume_detaille": null, "intention": "…",
     *     "ambiguite": 30, "categorie": "fond", "score_impact": 55 }, … ] }
     * Les entrées invalides sont rejetées une à une (listées dans la réponse),
     * les valides sont enregistrées — l'agent peut réessayer sans tout renvoyer.
     *
     * Garde-fou de qualité : une analyse produite par un modèle distant
     * (« claude-* », les plus fins) n'est jamais remplacée par celle d'un
     * modèle local. Ces entrées sont ignorées et listées à part, sauf si le
     * corps porte « force »: true — le seul cas où l'on assume le
     * remplacement (régénération volontaire après changement de prompt).
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
        $force = ($corps['force'] ?? false) === true;

        // Modèles en place pour tout le lot, en une requête.
        $enPlace = $resumes->modelesAmendements(array_values(array_filter(
            array_map(static fn (mixed $e): mixed => \is_array($e) ? ($e['uid'] ?? null) : null, $corps['analyses']),
            static fn (mixed $uid): bool => \is_string($uid)
        )));

        $enregistrees = 0;
        $rejetees = [];
        $ignorees = [];

        foreach ($corps['analyses'] as $i => $entree) {
            $analyse = $this->normaliser($entree);
            if ($analyse === null) {
                $rejetees[] = \is_array($entree) && \is_string($entree['uid'] ?? null) ? $entree['uid'] : "#$i";
                continue;
            }

            if (!$force && $this->seraitUneRegression($enPlace[$entree['uid']] ?? null, $modele)) {
                $ignorees[] = $entree['uid'];
                continue;
            }

            $resumes->enregistrerAmendement($entree['uid'], $analyse, $modele);
            ++$enregistrees;
        }

        return $this->json([
            'enregistrees' => $enregistrees,
            'rejetees' => $rejetees,
            'ignorees' => $ignorees,
        ]);
    }

    /**
     * Remplacer l'analyse d'un modèle distant par celle d'un modèle local
     * dégrade la page : les « claude-* » jugent l'impact et l'ambiguïté plus
     * finement que les modèles tournant sur une machine de bureau. Tout le
     * reste est permis : première analyse, modèle local par un autre, ou
     * montée vers un modèle distant.
     */
    private function seraitUneRegression(?string $enPlace, ?string $entrant): bool
    {
        return $enPlace !== null
            && str_starts_with($enPlace, 'claude-')
            && !str_starts_with((string) $entrant, 'claude-');
    }

    /**
     * L'agent local ne clôture qu'un dossier entièrement servi : c'est donc
     * ici, et nulle part ailleurs dans le flux différé, que « l'analyse est
     * prête » pour les visiteurs qui ont laissé leur adresse.
     */
    #[Route('/demandes/{dossier}/cloture', name: 'api_ia_cloture', requirements: ['dossier' => '[A-Z0-9]+'], methods: ['POST'])]
    public function cloture(
        string $dossier,
        Request $request,
        DemandeAnalyseRepository $demandes,
        NotificationAnalyse $notification,
    ): JsonResponse {
        $this->verifierJeton($request);

        $demandes->cloturer($dossier);

        return $this->json([
            'cloturee' => $dossier,
            'alertes' => $notification->analyseTerminee($dossier),
        ]);
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
