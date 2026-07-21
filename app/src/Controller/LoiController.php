<?php

namespace App\Controller;

use App\Repository\AmendementRepository;
use App\Repository\DebatRepository;
use App\Repository\DemandeAnalyseRepository;
use App\Repository\JurisprudenceRepository;
use App\Repository\LoiRepository;
use App\Repository\ResumeIaRepository;
use App\Repository\SenatRepository;
use App\Service\AnalyseAmendementIa;
use App\Service\AnalyseArrierePlan;
use App\Service\NotificationAnalyse;
use App\Service\ProvenanceAnalyseur;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        $horsVigueur = $request->query->getBoolean('hors_vigueur');

        $resultat = $lois->search($query, $page, self::PAR_PAGE, $horsVigueur);

        return $this->render('loi/index.html.twig', [
            'lois' => $resultat['lois'],
            'total' => $resultat['total'],
            'query' => $query,
            'horsVigueur' => $horsVigueur,
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
        JurisprudenceRepository $jurisprudences,
        ProvenanceAnalyseur $analyseur,
        ResumeIaRepository $resumes,
        AnalyseArrierePlan $arrierePlan,
        DemandeAnalyseRepository $demandes,
        CacheInterface $cache,
        #[Autowire(env: 'bool:IA_ANALYSE_DIFFEREE')] bool $analyseDifferee,
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
        $juges = [];

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
        }

        // Volet Sénat : séances publiques importées (alinea, chambre senat),
        // amendements Ameli les plus discutés, et, pour le Rayon X et les
        // cartes, les amendements adoptés/rejetés avec leur dispositif.
        // Même politique de cache que le classement AN.
        $senat = null;
        $jugesSenat = [];
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
            $jugesSenat = $senatRepository->findJugesPourDossier($dossierSenat['signet']);
        }

        // Rayon X et cartes : les deux chambres confondues. Un passage du
        // texte promulgué peut ainsi remonter à un amendement AN comme à un
        // amendement Sénat (commission ou séance).
        $juges = array_merge($juges, $jugesSenat);

        // Les analyses IA sont lues en base uniquement : leur génération
        // (locale via Ollama, potentiellement longue) se lance en tâche
        // de fond depuis le bouton de la page, jamais pendant la requête.
        // Le périmètre couvre les deux chambres ; le flux de demande, lui,
        // reste ancré sur le dossier AN (bouton absent pour une loi sans
        // dossier AN).
        $ia = ['analyses' => $juges !== [] ? $resumes->analysesAmendements(array_column($juges, 'uid')) : []];
        $totalJuges = \count($juges);
        $analysesFaites = \count($ia['analyses']);
        if ($dossier !== null && $totalJuges > 0) {
            $statutIa = match (true) {
                $analysesFaites >= $totalJuges => 'complet',
                $arrierePlan->estEnCours($dossier['uid']) => 'en_cours',
                $analyseDifferee && $demandes->enAttente($dossier['uid']) => 'demande',
                default => 'a_faire',
            };
        }

        if ($juges !== []) {
            $adoptes = array_values(array_filter($juges, static fn (array $a): bool => $a['sort'] === 'Adopté'));

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
                    'chambre' => $a['chambre'] ?? 'an',
                    'intentionIa' => $analyse['intention'] ?? null,
                    'ambiguiteIa' => $analyse['ambiguite'] ?? null,
                    'categorieIa' => $analyse['categorie'] ?? null,
                    'scoreImpactIa' => $analyse['score_impact'] ?? null,
                    // À défaut d'intention IA, le début de l'exposé sommaire sert
                    // d'aperçu dans la fiche en marge (cf. construireFiche).
                    'exposeSommaire' => ($analyse['intention'] ?? null) === null
                        ? $this->debutExpose($a['expose_sommaire'] ?? null)
                        : null,
                    'url' => $this->urlAmendement($a, $loi['id']),
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
                    'chambre' => $a['chambre'] ?? 'an',
                    'resumeIa' => $analyse['resume'] ?? null,
                    'intentionIa' => $analyse['intention'] ?? null,
                    'categorieIa' => $analyse['categorie'] ?? null,
                    'scoreImpactIa' => $analyse['score_impact'] ?? null,
                    'ambiguiteIa' => $analyse['ambiguite'] ?? null,
                    // En l'absence de résumé IA, on affiche le début de l'exposé
                    // sommaire (texte brut, tronqué) plutôt qu'un « Analyse en cours ».
                    'exposeSommaire' => ($analyse['resume'] ?? null) === null
                        ? $this->debutExpose($a['expose_sommaire'] ?? null)
                        : null,
                    'url' => $this->urlAmendement($a, $loi['id']),
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

        // Jurisprudence Judilibre : lue en base uniquement (l'import est une
        // commande), bloc absent tant que la loi n'a pas été synchronisée.
        $jurisprudence = null;
        if ($jurisprudences->estDisponible()) {
            $sync = $jurisprudences->syncPourLoi($loi['num']);
            if ($sync !== null) {
                $jurisprudence = $sync + ['decisions' => $jurisprudences->findPourLoi($loi['num'], 5)];
            }
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
            'jurisprudence' => $jurisprudence,
        ]);
    }

    /**
     * Bouton « Lancer l'analyse IA » de la page de la loi. Deux modes selon
     * IA_ANALYSE_DIFFEREE :
     *   - direct (défaut) : app:ia:analyser part en arrière-plan sur la
     *     machine (Ollama local), sans effet si un traitement est en cours ;
     *   - différé (prod, pas d'Ollama) : la demande est enregistrée en base
     *     et un email est envoyé — l'agent local la traitera via /api/ia.
     *     Exception : sous IA_SEUIL_ANALYSE_DIRECTE analyses manquantes, le
     *     traitement part directement en arrière-plan sur la prod, possible
     *     sans Ollama car le modèle « claude-* » passe par l'API Anthropic
     *     (IA_MODELE et ANTHROPIC_KEY requis). 0 = toujours différer.
     */
    #[Route('/loi/{id}/analyser', name: 'loi_analyser', requirements: ['id' => '[A-Z0-9]{20}'], methods: ['POST'])]
    public function analyser(
        string $id,
        Request $request,
        LoiRepository $lois,
        AmendementRepository $amendements,
        AnalyseAmendementIa $analyseIa,
        AnalyseArrierePlan $arrierePlan,
        DemandeAnalyseRepository $demandes,
        NotificationAnalyse $notification,
        ResumeIaRepository $resumes,
        #[Autowire(env: 'bool:IA_ANALYSE_DIFFEREE')] bool $analyseDifferee,
        #[Autowire(env: 'int:IA_SEUIL_ANALYSE_DIRECTE')] int $seuilAnalyseDirecte,
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
            $directe = true;
            if ($analyseDifferee) {
                // Le seuil s'applique au travail restant (analyses manquantes),
                // pas au total du dossier : un gros dossier presque terminé se
                // finit sur place.
                $juges = $analyseIa->jugesPourDossier($dossier['uid']);
                $manquantes = \count($juges) - \count($resumes->analysesAmendements(array_column($juges, 'uid')));
                $directe = $manquantes > 0 && $manquantes < $seuilAnalyseDirecte;

                // L'email ne part qu'à la création : les clics suivants
                // retombent sur la demande déjà ouverte. Le compte annoncé
                // couvre les deux chambres, comme le traitement.
                if (!$directe && $demandes->deposer($dossier['uid'], $loi['id'])) {
                    $notification->demandeDeposee($loi, $dossier['uid'], \count($juges));
                }
            }
            if ($directe) {
                $arrierePlan->lancer($dossier['uid']);
            }
        }

        return $this->redirectToRoute('loi_show', ['id' => $id]);
    }

    /**
     * Clé de correspondance entre une division d'amendement (« Article 14 bis »,
     * « Article PREMIER ») et un numéro d'article du texte affiché. Null pour
     * les divisions qui ne désignent pas directement un article (« Après
     * l'article 3 », titres, annexes, états…).
     */
    /**
     * Lien de détail d'un amendement : chaque chambre a sa page interne
     * (détail, débat retrouvé dans les CRI, analyse IA).
     */
    private function urlAmendement(array $a, string $loiId): string
    {
        $route = ($a['chambre'] ?? 'an') === 'senat' ? 'loi_amendement_senat' : 'loi_amendement';

        return $this->generateUrl($route, ['id' => $loiId, 'uid' => $a['uid']]);
    }

    /**
     * Début de l'exposé sommaire en texte brut (HTML Légifrance/AN aplati),
     * tronqué à 200 caractères, pour tenir lieu d'aperçu tant que l'analyse IA
     * n'a pas produit de résumé. Renvoie null si l'exposé est vide.
     */
    private function debutExpose(?string $html, int $longueur = 200): ?string
    {
        if ($html === null || $html === '') {
            return null;
        }

        $texte = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8')));
        if ($texte === '') {
            return null;
        }

        return mb_strlen($texte) > $longueur ? mb_substr($texte, 0, $longueur) . '…' : $texte;
    }

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
    public function amendements(
        string $id,
        LoiRepository $lois,
        AmendementRepository $amendements,
        SenatRepository $senatRepository,
    ): Response {
        $loi = $lois->find($id);

        if ($loi === null) {
            throw $this->createNotFoundException(sprintf('Loi « %s » introuvable.', $id));
        }

        $dossier = $amendements->findDossierPourLoi($loi['num']);
        $phases = $dossier !== null ? $amendements->findPourDossier($dossier['uid']) : [];

        // Phases du Sénat (Ameli) mêlées à celles de l'AN, le tout dans
        // l'ordre chronologique du premier dépôt : la navette alterne les
        // chambres, l'entrelacement restitue le parcours réel du texte.
        $dossierSenat = $senatRepository->findDossierPourLoi($loi['num']);
        if ($dossierSenat !== null) {
            $phases = array_merge($phases, $senatRepository->findPourDossier($dossierSenat['signet']));
        }
        $premierDepot = static function (array $phase): string {
            $dates = array_filter(array_column($phase['amendements'], 'date_depot'));

            return $dates === [] ? '9999' : min($dates);
        };
        usort($phases, static fn (array $a, array $b): int => $premierDepot($a) <=> $premierDepot($b));

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
            'dossierSenat' => $dossierSenat,
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

    /**
     * Page d'un amendement du Sénat (Ameli) : le pendant sénatorial de la
     * page AN, le débat étant retrouvé dans les CRI de séance publique
     * importés (les amendements de commission COM-… n'ont pas d'extrait,
     * les comptes rendus des réunions de commission n'étant pas importés).
     */
    #[Route('/loi/{id}/senat/amendement/{uid}', name: 'loi_amendement_senat', requirements: ['id' => '[A-Z0-9]{20}', 'uid' => 'SEN\d+'])]
    public function amendementSenat(
        string $id,
        string $uid,
        LoiRepository $lois,
        AmendementRepository $amendements,
        SenatRepository $senatRepository,
        AnalyseAmendementIa $analyseIa,
    ): Response {
        $loi = $lois->find($id);
        $dossierSenat = $loi !== null ? $senatRepository->findDossierPourLoi($loi['num']) : null;
        $amendement = $dossierSenat !== null ? $senatRepository->findOne($dossierSenat['signet'], $uid) : null;

        if ($loi === null || $amendement === null) {
            throw $this->createNotFoundException('Amendement introuvable.');
        }

        // Analyse IA : lue en base, ou générée à la volée pour les jugés —
        // les résumés des deux chambres sont ancrés sur le dossier AN.
        $analyse = null;
        $dossier = $amendements->findDossierPourLoi($loi['num']);
        if ($dossier !== null && \in_array($amendement['sort'], ['Adopté', 'Rejeté'], true)) {
            $analyse = $analyseIa->analyser($dossier['uid'], [$amendement])['analyses'][$uid] ?? null;
        }

        return $this->render('loi/amendement_senat.html.twig', [
            'loi' => $loi,
            'a' => $amendement,
            'analyse' => $analyse,
            'debat' => $senatRepository->findExtrait(
                $dossierSenat['loicod'],
                $amendement['numero'],
                $amendement['date_depot']
            ),
        ]);
    }
}
