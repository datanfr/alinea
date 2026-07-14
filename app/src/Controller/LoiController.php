<?php

namespace App\Controller;

use App\Repository\AmendementRepository;
use App\Repository\DebatRepository;
use App\Repository\LoiRepository;
use App\Repository\ResumeIaRepository;
use App\Repository\SenatRepository;
use App\Service\AnalyseAmendementIa;
use App\Service\AnalyseArrierePlan;
use App\Service\ProvenanceAnalyseur;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

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
    public function show(
        string $id,
        LoiRepository $lois,
        AmendementRepository $amendements,
        DebatRepository $debats,
        SenatRepository $senatRepository,
        ProvenanceAnalyseur $analyseur,
        ResumeIaRepository $resumes,
        AnalyseArrierePlan $arrierePlan,
        CacheInterface $cache,
    ): Response {
        $loi = $lois->find($id);

        if ($loi === null) {
            throw $this->createNotFoundException(sprintf('Loi « %s » introuvable.', $id));
        }

        $articles = $lois->findArticles($loi['id']);
        $dossier = $amendements->findDossierPourLoi($loi['num']);
        $nbAmendements = null;
        $provenance = [];
        $amendementsParArticle = [];
        $autresParDivision = [];
        $autresTotal = 0;
        $statutIa = null;
        $analysesFaites = 0;
        $totalJuges = 0;
        $topDebat = [];

        if ($dossier !== null) {
            $nbAmendements = $amendements->countPourDossier($dossier['uid']);

            // Amendements les plus discutés en séance (sans IA, mesuré sur les
            // CRI). Requête lourde (jointure regex sur cr_parole) : mise en cache
            // par dossier, TTL 24 h — à vider (cache:pool:clear) après un réimport
            // des comptes rendus si l'on veut rafraîchir avant expiration.
            $classement = $cache->get(
                'debat_top_' . $dossier['uid'],
                static function (ItemInterface $item) use ($debats, $dossier): array {
                    $item->expiresAfter(86400);

                    return $debats->classerParDebat($dossier['uid'], 3);
                }
            );
            foreach ($classement as $c) {
                $a = $amendements->findOne($c['uid']);
                if ($a === null) {
                    continue;
                }
                $topDebat[] = [
                    'numero' => $a['numero'],
                    'auteur' => $a['auteur'],
                    'groupe' => $a['groupe'],
                    'statut' => $a['statut'],
                    'classe' => $a['sort_classe'],
                    'nbCitations' => $c['nb_cit'],
                    'ancre' => 'amdt-' . $c['uid'],
                    'url' => $this->generateUrl('loi_amendement', ['id' => $loi['id'], 'uid' => $c['uid']]),
                ];
            }

            // Adoptés et rejetés en une passe : les adoptés nourrissent le
            // Rayon X, l'ensemble alimente les résumés IA.
            $juges = $amendements->findParSortPourDossier($dossier['uid'], ['Adopté', 'Rejeté']);
            $adoptes = array_values(array_filter($juges, static fn (array $a): bool => $a['sort'] === 'Adopté'));

            // Les analyses IA sont lues en base uniquement : leur génération
            // (locale via Ollama, potentiellement longue) se lance en tâche
            // de fond depuis le bouton de la page, jamais pendant la requête.
            $ia = ['analyses' => $resumes->analysesAmendements(array_column($juges, 'uid'))];
            $totalJuges = \count($juges);
            $analysesFaites = \count($ia['analyses']);
            if ($totalJuges > 0) {
                $statutIa = match (true) {
                    $analysesFaites >= $totalJuges => 'complet',
                    $arrierePlan->estEnCours($dossier['uid']) => 'en_cours',
                    default => 'a_faire',
                };
            }

            $resultat = $analyseur->annoter($articles, $adoptes);
            $articles = $resultat['articles'];

            // La fiche en marge n'affiche que l'intention (opacité modulée
            // par impact + ambiguïté) et le lien de détail : le payload se
            // limite à ça — plus le sort et la catégorie qu'exigent les
            // filtres —, le reste vit sur la page de l'amendement.
            foreach ($resultat['refs'] as $uid => $a) {
                $analyse = $ia['analyses'][$uid] ?? null;
                $provenance[$uid] = [
                    'numero' => $a['numero'],
                    'date' => $a['date_depot'],
                    'classe' => $a['sort_classe'],
                    'intentionIa' => $analyse['intention'] ?? null,
                    'ambiguiteIa' => $analyse['ambiguite'] ?? null,
                    'categorieIa' => $analyse['categorie'] ?? null,
                    'scoreImpactIa' => $analyse['score_impact'] ?? null,
                    'url' => $this->generateUrl('loi_amendement', ['id' => $loi['id'], 'uid' => $uid]),
                ];
            }

            // Amendements sans passage surligné : les rejetés (jamais dans le
            // texte, mais leur résumé explique ce qu'ils proposaient) et les
            // adoptés dont la modification n'a pu être localisée dans le texte.
            // On les range sous l'article (de la proposition) qu'ils visaient —
            // la numérotation de l'examen diffère de celle de la loi promulguée
            // (renumérotation, suppressions), donc on ne les rattache pas aux
            // articles affichés, on les regroupe par division d'origine. Seul le
            // résumé court est exposé ; le détaillé reste sur la page de l'amendement.
            foreach ($juges as $a) {
                if (isset($resultat['refs'][$a['uid']])) {
                    continue;
                }
                $analyse = $ia['analyses'][$a['uid']] ?? null;
                $division = $a['division'] ?? 'Hors article';
                $autresParDivision[$division][] = [
                    'uid' => $a['uid'],
                    'numero' => $a['numero'],
                    'phase' => $a['phase'],
                    'auteur' => $a['auteur'],
                    'groupe' => $a['groupe'],
                    'division' => $a['division'],
                    'statut' => $a['statut'],
                    'classe' => $a['sort_classe'],
                    'resumeIa' => $analyse['resume'] ?? null,
                    'intentionIa' => $analyse['intention'] ?? null,
                    'categorieIa' => $analyse['categorie'] ?? null,
                    'scoreImpactIa' => $analyse['score_impact'] ?? null,
                    'ambiguiteIa' => $analyse['ambiguite'] ?? null,
                    'url' => $this->generateUrl('loi_amendement', ['id' => $loi['id'], 'uid' => $a['uid']]),
                ];
            }

            // Dans chaque groupe : les amendements déjà analysés d'abord, par
            // impact décroissant, pour surfacer les plus significatifs.
            foreach ($autresParDivision as &$cartes) {
                usort($cartes, static function (array $x, array $y): int {
                    $analyseX = $x['resumeIa'] !== null ? 1 : 0;
                    $analyseY = $y['resumeIa'] !== null ? 1 : 0;

                    return [$analyseY, $y['scoreImpactIa'] ?? -1] <=> [$analyseX, $x['scoreImpactIa'] ?? -1];
                });
            }
            unset($cartes);

            // Les groupes dont la division correspond à un article affiché
            // remontent au niveau de cet article, dans la colonne du texte.
            // Les autres (« Après l'article… », articles renumérotés ou
            // supprimés pendant la navette, annexes) restent en bas de page.
            $numsAffiches = [];
            foreach ($articles as $article) {
                $cle = $this->cleArticle('Article ' . $article['num']);
                if ($cle !== null) {
                    $numsAffiches[$cle] = $article['num'];
                }
            }
            foreach ($autresParDivision as $division => $cartes) {
                $cle = $this->cleArticle($division);
                if ($cle !== null && isset($numsAffiches[$cle])) {
                    $num = $numsAffiches[$cle];
                    $amendementsParArticle[$num] = array_merge($amendementsParArticle[$num] ?? [], $cartes);
                    unset($autresParDivision[$division]);
                    continue;
                }
                $autresTotal += \count($cartes);
            }

            // Articles dans l'ordre de la proposition (Article PREMIER, 2, 3,
            // 3 bis, 4…), les divisions non numérotées en fin.
            uksort($autresParDivision, fn (string $x, string $y): int => $this->rangDivision($x) <=> $this->rangDivision($y));
        }

        // Volet Sénat : séances publiques importées (alinea, chambre senat) et
        // amendements Ameli les plus discutés. Même politique de cache que le
        // classement AN — le classement ne bouge qu'au réimport des CRI.
        $senat = null;
        $dossierSenat = $senatRepository->findDossierPourLoi($loi['num']);
        if ($dossierSenat !== null) {
            $seancesSenat = $senatRepository->findSeancesPourLoi($dossierSenat['loicod']);
            $topSenat = [];
            if ($seancesSenat !== []) {
                $topSenat = $cache->get(
                    'debat_senat_top_' . $dossierSenat['loicod'],
                    static function (ItemInterface $item) use ($senatRepository, $dossierSenat): array {
                        $item->expiresAfter(86400);

                        return $senatRepository->classerParDebat($dossierSenat['loicod'], $dossierSenat['signet'], 3);
                    }
                );
            }
            $senat = [
                'dossier' => $dossierSenat,
                'seances' => $seancesSenat,
                'top' => $topSenat,
                'nbAmendements' => $senatRepository->countAmendements($dossierSenat['signet']),
            ];
        }

        return $this->render('loi/show.html.twig', [
            'loi' => $loi,
            'articles' => $articles,
            'nbAmendements' => $nbAmendements,
            'provenance' => $provenance,
            'amendementsParArticle' => $amendementsParArticle,
            'autresParDivision' => $autresParDivision,
            'autresTotal' => $autresTotal,
            'statutIa' => $statutIa,
            'analysesFaites' => $analysesFaites,
            'totalJuges' => $totalJuges,
            'topDebat' => $topDebat,
            'senat' => $senat,
        ]);
    }

    /**
     * Lance en arrière-plan l'analyse IA de tous les amendements jugés du
     * dossier (bouton de la page de la loi). Sans effet si un traitement est
     * déjà en cours.
     */
    #[Route('/loi/{id}/analyser', name: 'loi_analyser', requirements: ['id' => '[A-Z0-9]{20}'], methods: ['POST'])]
    public function analyser(
        string $id,
        Request $request,
        LoiRepository $lois,
        AmendementRepository $amendements,
        AnalyseArrierePlan $arrierePlan,
    ): Response {
        $loi = $lois->find($id);

        if ($loi === null) {
            throw $this->createNotFoundException(sprintf('Loi « %s » introuvable.', $id));
        }

        if (!$this->isCsrfTokenValid('analyser' . $id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $dossier = $amendements->findDossierPourLoi($loi['num']);
        if ($dossier !== null) {
            $arrierePlan->lancer($dossier['uid']);
        }

        return $this->redirectToRoute('loi_show', ['id' => $id]);
    }

    /**
     * Clé de correspondance entre une division d'amendement (« Article 14 bis »,
     * « Article PREMIER ») et un numéro d'article du texte affiché. Null pour
     * les divisions qui ne désignent pas directement un article (« Après
     * l'article 3 », titres, annexes, états…).
     */
    private function cleArticle(string $titre): ?string
    {
        if (preg_match('/^Article\s+(.+)$/iu', trim($titre), $m) !== 1) {
            return null;
        }

        $cle = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $m[1])));

        return match ($cle) {
            'premier', '1er' => '1',
            default => $cle,
        };
    }

    /**
     * Clé de tri d'une division d'amendement (« Article 4 bis A » → 401) pour
     * ordonner les amendements comme dans la proposition. Les divisions non
     * numérotées passent en fin.
     */
    private function rangDivision(string $titre): int
    {
        if (preg_match('/(PREMIER|\d+)/i', $titre, $m) !== 1) {
            return \PHP_INT_MAX;
        }

        $base = strcasecmp($m[1], 'PREMIER') === 0 ? 1 : (int) $m[1];

        $suffixes = ['bis' => 1, 'ter' => 2, 'quater' => 3, 'quinquies' => 4, 'sexies' => 5,
            'septies' => 6, 'octies' => 7, 'nonies' => 8, 'decies' => 9];
        $rang = 0;
        foreach ($suffixes as $mot => $r) {
            if (stripos($titre, $mot) !== false) {
                $rang = $r;
                break;
            }
        }

        return $base * 100 + $rang;
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
        AnalyseAmendementIa $analyseIa,
    ): Response {
        $loi = $lois->find($id);
        $amendement = $amendements->findOne($uid);

        if ($loi === null || $amendement === null) {
            throw $this->createNotFoundException('Amendement introuvable.');
        }

        // Toutes les séances publiques du dossier servent de repli : le
        // seanceDiscussionRef de l'open data manque parfois, ou pointe vers
        // la mauvaise séance quand l'examen a été réservé ou reporté. Les
        // amendements de commission, eux, se cherchent dans les CR des
        // réunions de commission du dossier.
        $seancesDossier = [];
        $crCommissions = [];
        $dossier = $amendements->findDossierPourLoi($loi['num']);
        if ($dossier !== null) {
            $seancesDossier = $amendements->findSeancesPourDossier($dossier['uid']);
            $crCommissions = $amendements->findCrCommissionsPourDossier($dossier['uid']);
        }

        // Analyse IA : lue en base, ou générée à la volée pour les amendements
        // jugés (adoptés/rejetés) — un seul appel API au pire.
        $analyse = null;
        if ($dossier !== null && \in_array($amendement['sort'], ['Adopté', 'Rejeté'], true)) {
            $analyse = $analyseIa->analyser($dossier['uid'], [$amendement])['analyses'][$uid] ?? null;
        }

        return $this->render('loi/amendement.html.twig', [
            'loi' => $loi,
            'a' => $amendement,
            'analyse' => $analyse,
            'debat' => $debats->findExtrait(
                $amendement['seance_ref'],
                $amendement['numero'],
                $seancesDossier,
                $amendement['date_depot'],
                $crCommissions
            ),
        ]);
    }
}
