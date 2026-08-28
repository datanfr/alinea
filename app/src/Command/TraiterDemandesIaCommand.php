<?php

namespace App\Command;

use App\Repository\AmendementRepository;
use App\Repository\LoiRepository;
use App\Service\AnalyseAmendementIa;
use App\Service\AnalyseArrierePlan;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Agent local d'analyse : traite les demandes déposées en prod (bouton de la
 * page d'une loi) avec le modèle local (Ollama), puis pousse les résultats
 * vers la prod via son API sécurisée.
 *
 * Cycle complet, à lancer à la main à réception de l'email de demande (ou en
 * cron) :
 *   1. GET  {ALINEA_DISTANT_URL}/api/ia/demandes         → dossiers + uids manquants
 *   2. génération locale (base canutes synchronisée : mêmes uids qu'en prod)
 *   3. POST {ALINEA_DISTANT_URL}/api/ia/analyses          → lots de 10
 *   4. POST {ALINEA_DISTANT_URL}/api/ia/demandes/…/cloture
 *
 * Les analyses sont aussi persistées en base locale, mais c'est la PROD qui
 * fait foi sur ce qui reste à faire : sa base contient des analyses que la
 * machine locale n'a jamais vues (celles qu'elle a générées elle-même du
 * temps où IA_SEUIL_ANALYSE_DIRECTE était non nul). Toute sélection
 * d'amendements à traiter passe donc par /api/ia/analysees ; sans cette
 * réponse la commande s'arrête, plutôt que de refaire à l'aveugle un travail
 * déjà publié. Un amendement absent de la base locale (retard de sync) est
 * signalé et sauté : relancer après app:sync:canutes. Le périmètre d'un
 * dossier couvre les deux chambres : amendements AN (AMANR…) et, si la loi a
 * un dossier sénatorial, amendements du Sénat (SEN…).
 *
 * Avec des dossiers en argument, la commande ignore les demandes ouvertes et
 * traite ces dossiers-là : les analyses que la prod n'a pas, ou toutes avec
 * --regenerer. La prod refuse de son côté qu'un modèle local remplace une
 * analyse « claude-* » (voir ApiIaController::analyses).
 *
 * Avec --toutes, elle traite le stock complet petit à petit : les dossiers
 * promulgués sont classés par intérêt (consultations des pages en prod via
 * /api/ia/interet, puis demandes des visiteurs, puis récence de
 * promulgation), et les --lois premiers ayant des analyses manquantes sont
 * traités. Relançable à volonté (cron) : chaque passe reprend là où la
 * précédente s'est arrêtée, la prod se remplit au fil de l'eau.
 *
 *   bin/console app:ia:demandes
 *   bin/console app:ia:demandes --modele=claude-haiku-4-5 --limite=50
 *   bin/console app:ia:demandes DLR5L15N36531 --regenerer
 *   bin/console app:ia:demandes --toutes --lois=3 --modele=mistral-small3.2
 */
#[AsCommand(
    name: 'app:ia:demandes',
    description: "Traite les demandes d'analyse de la prod avec le modèle local et pousse les résultats",
)]
class TraiterDemandesIaCommand extends Command
{
    // Taille des lots poussés : assez petit pour que l'avancement soit
    // visible en prod au fil de l'eau, assez grand pour limiter les requêtes.
    private const TAILLE_LOT = 10;

    // Nombre de dossiers dont on sonde la couverture en une requête (mode
    // --toutes). Un dossier porte quelques centaines d'uids : au-delà, le
    // corps de la requête devient inutilement gros.
    private const FENETRE_SONDAGE = 15;

    private readonly HttpClient $http;

    public function __construct(
        #[Autowire(env: 'ALINEA_DISTANT_URL')] string $distantUrl,
        #[Autowire(env: 'IA_API_JETON')] private readonly string $jeton,
        #[Autowire(env: 'IA_MODELE')] private readonly string $modeleDefaut,
        private readonly AmendementRepository $amendements,
        private readonly LoiRepository $lois,
        private readonly AnalyseAmendementIa $analyseIa,
        private readonly AnalyseArrierePlan $arrierePlan,
    ) {
        parent::__construct();

        $this->http = new HttpClient([
            'base_uri' => rtrim($distantUrl, '/') . '/',
            'timeout' => 60,
            'headers' => ['Authorization' => 'Bearer ' . $this->jeton],
        ]);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('dossiers', InputArgument::IS_ARRAY, 'Dossiers AN (DLR…) à traiter directement, sans passer par les demandes')
            ->addOption('modele', 'm', InputOption::VALUE_REQUIRED, 'Modèle IA (défaut : IA_MODELE)')
            ->addOption('regenerer', 'r', InputOption::VALUE_NONE, 'Régénère aussi les analyses déjà en base (dossiers explicites uniquement)')
            ->addOption('limite', 'l', InputOption::VALUE_REQUIRED, "Nombre maximal d'analyses par dossier")
            ->addOption('toutes', 't', InputOption::VALUE_NONE, 'Traite le stock complet par priorité (consultations, demandes, récence)')
            ->addOption('lois', null, InputOption::VALUE_REQUIRED, 'En mode --toutes : nombre maximal de lois par passe', '2');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->jeton === '') {
            $io->error('IA_API_JETON est vide : renseigner le même jeton qu’en prod.');

            return Command::FAILURE;
        }

        $modele = $input->getOption('modele') ?? $this->modeleDefaut;
        $limite = $input->getOption('limite') !== null ? (int) $input->getOption('limite') : null;
        $dossiers = $input->getArgument('dossiers');

        $demandes = match (true) {
            $input->getOption('toutes') => $this->demandesParPriorite($io, max(1, (int) $input->getOption('lois'))),
            $dossiers !== [] => $this->demandesPourDossiers($io, $dossiers, $input->getOption('regenerer')),
            default => $this->demandesDeLaProd($io),
        };

        if ($demandes === null) {
            return Command::FAILURE;
        }

        if ($demandes === []) {
            $io->success($dossiers !== [] ? 'Rien à analyser pour ces dossiers.' : 'Aucune demande en attente.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('%d demande(s) en attente (%s)', \count($demandes), $modele));

        $echec = false;
        foreach ($demandes as $demande) {
            if (!$this->traiterDemande($io, $demande, $modele, $limite)) {
                $echec = true;
            }
        }

        return $echec ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Demandes en attente listées par l'API de la prod, ou null en cas d'échec.
     *
     * @return list<array{dossier: string, loi: string, manquants: list<string>}>|null
     */
    private function demandesDeLaProd(SymfonyStyle $io): ?array
    {
        try {
            $reponse = $this->http->get('api/ia/demandes');
        } catch (GuzzleException $e) {
            $io->error('Récupération des demandes en échec : ' . $e->getMessage());

            return null;
        }

        return json_decode((string) $reponse->getBody(), true)['demandes'] ?? [];
    }

    /**
     * Pseudo-demandes pour des dossiers passés en argument : tous les
     * amendements jugés avec --regenerer, sinon seulement ceux que la prod
     * n'a pas encore analysés. Null si un uid de dossier est invalide ou si
     * la couverture de la prod est inconnue.
     *
     * @param list<string> $dossiers
     *
     * @return list<array{dossier: string, loi: string, manquants: list<string>}>|null
     */
    private function demandesPourDossiers(SymfonyStyle $io, array $dossiers, bool $regenerer): ?array
    {
        $parDossier = [];
        foreach ($dossiers as $dossier) {
            if (preg_match('/^[A-Z0-9]+$/', $dossier) !== 1) {
                $io->error(sprintf('Dossier invalide : « %s » (attendu : DLR…).', $dossier));

                return null;
            }

            $parDossier[$dossier] = array_column($this->analyseIa->jugesPourDossier($dossier), 'uid');
        }

        if (!$regenerer) {
            $couverture = $this->couvertureProd($io, array_merge(...array_values($parDossier)) ?: []);
            if ($couverture === null) {
                return null;
            }
            foreach ($parDossier as $dossier => $uids) {
                $parDossier[$dossier] = array_values(array_filter(
                    $uids,
                    static fn (string $uid): bool => !\array_key_exists($uid, $couverture)
                ));
            }
        }

        $demandes = [];
        foreach ($parDossier as $dossier => $uids) {
            if ($uids !== []) {
                $demandes[] = ['dossier' => $dossier, 'loi' => '—', 'manquants' => $uids];
            }
        }

        return $demandes;
    }

    /**
     * Analyses déjà présentes en prod parmi ces uids (uid => modèle), en une
     * requête. Null si la prod est injoignable : sans cette réponse on
     * ignore ce qui existe déjà, et travailler à l'aveugle refait — voire
     * écrase — des analyses publiées.
     *
     * @param list<string> $uids
     *
     * @return array<string, ?string>|null
     */
    private function couvertureProd(SymfonyStyle $io, array $uids): ?array
    {
        if ($uids === []) {
            return [];
        }

        try {
            $reponse = $this->http->post('api/ia/analysees', ['json' => ['uids' => array_values($uids)]]);
        } catch (GuzzleException $e) {
            $io->error(sprintf(
                "Couverture de la prod inconnue (%s) : arrêt pour ne pas refaire ce qui existe déjà.\n"
                . 'Si la prod ne tourne pas encore avec cette version, déployer puis relancer.',
                $e->getMessage()
            ));

            return null;
        }

        $analysees = json_decode((string) $reponse->getBody(), true)['analysees'] ?? null;
        if (!\is_array($analysees)) {
            $io->error('Réponse inattendue de /api/ia/analysees (prod à jour ?).');

            return null;
        }

        return $analysees;
    }

    /**
     * File de fond : les dossiers promulgués classés par intérêt, dont les
     * $maxLois premiers ayant des analyses manquantes forment la passe.
     *
     * L'intérêt vient de la prod (/api/ia/interet) : consultations des pages
     * de lois sur 90 jours d'abord, puis nombre de demandes déposées, puis
     * date de promulgation. Si l'API est injoignable, la récence seule ordonne
     * la file (le traitement continue hors ligne, la poussée échouera plus
     * loin de toute façon).
     *
     * @return list<array{dossier: string, loi: string, manquants: list<string>}>|null
     */
    private function demandesParPriorite(SymfonyStyle $io, int $maxLois): ?array
    {
        $consultations = [];
        $demandesParDossier = [];
        try {
            $interet = json_decode((string) $this->http->get('api/ia/interet')->getBody(), true);
            // Consultations comptées par id JORFTEXT : ramenées au numéro de
            // loi, la clé qui relie au dossier AN.
            $nums = $this->lois->numsPourIds(array_keys($interet['consultations'] ?? []));
            foreach ($interet['consultations'] ?? [] as $loiId => $nb) {
                if (isset($nums[$loiId])) {
                    $consultations[$nums[$loiId]] = ($consultations[$nums[$loiId]] ?? 0) + $nb;
                }
            }
            foreach ($interet['demandes'] ?? [] as $d) {
                $demandesParDossier[$d['dossier']] = ($demandesParDossier[$d['dossier']] ?? 0) + $d['nb'];
            }
        } catch (GuzzleException $e) {
            $io->warning('Signaux de priorité injoignables (' . $e->getMessage() . ') : ordre par récence seule.');
        }

        // Les lois absentes de la base Légifrance n'ont pas de page dans
        // Alinéa : leurs dossiers sortent de la file.
        $file = $this->amendements->findDossiersPromulgues();
        $connus = $this->lois->numsConnus(array_map(static fn (array $d): string => trim($d['num'] ?? ''), $file));
        $file = array_values(array_filter($file, static fn (array $d): bool => isset($connus[trim($d['num'] ?? '')])));

        usort($file, static fn (array $a, array $b): int =>
            [
                $consultations[trim($b['num'] ?? '')] ?? 0,
                $demandesParDossier[$b['uid']] ?? 0,
                $b['promulguee'] ?? '',
            ] <=> [
                $consultations[trim($a['num'] ?? '')] ?? 0,
                $demandesParDossier[$a['uid']] ?? 0,
                $a['promulguee'] ?? '',
            ]);

        // Descend la file jusqu'à réunir $maxLois dossiers à traiter : les
        // dossiers déjà complets (ou sans amendement jugé) sont passés, si
        // bien que chaque passe reprend naturellement où la précédente
        // s'était arrêtée. La couverture se demande à la prod par fenêtres
        // de dossiers, pour n'y faire que quelques requêtes.
        $passe = [];
        foreach (array_chunk($file, self::FENETRE_SONDAGE) as $fenetre) {
            if (\count($passe) >= $maxLois) {
                break;
            }

            $parDossier = [];
            foreach ($fenetre as $dossier) {
                $uids = array_column($this->analyseIa->jugesPourDossier($dossier['uid']), 'uid');
                if ($uids !== []) {
                    $parDossier[$dossier['uid']] = ['num' => trim($dossier['num'] ?? '—'), 'uids' => $uids];
                }
            }
            if ($parDossier === []) {
                continue;
            }

            $couverture = $this->couvertureProd($io, array_merge(...array_column($parDossier, 'uids')));
            if ($couverture === null) {
                return null;
            }

            foreach ($parDossier as $uid => $d) {
                if (\count($passe) >= $maxLois) {
                    break;
                }
                $manquants = array_values(array_filter(
                    $d['uids'],
                    static fn (string $u): bool => !\array_key_exists($u, $couverture)
                ));
                if ($manquants !== []) {
                    $passe[] = ['dossier' => $uid, 'loi' => $d['num'], 'manquants' => $manquants];
                }
            }
        }

        return $passe;
    }

    /**
     * @param array{dossier: string, loi: string, manquants: list<string>} $demande
     */
    private function traiterDemande(SymfonyStyle $io, array $demande, string $modele, ?int $limite): bool
    {
        $dossier = $demande['dossier'];
        $manquants = array_flip($demande['manquants']);

        $io->section(sprintf('Dossier %s (loi %s) — %d analyse(s) manquante(s)', $dossier, $demande['loi'], \count($manquants)));

        if ($manquants === []) {
            return $this->cloturer($io, $dossier);
        }

        // Verrou local partagé avec app:ia:analyser : pas deux générations
        // du même dossier en parallèle.
        $verrou = $this->arrierePlan->verrouiller($dossier);
        if ($verrou === null) {
            $io->warning('Un traitement local de ce dossier est déjà en cours, dossier sauté.');

            return false;
        }

        $aTraiter = array_values(array_filter(
            $this->analyseIa->jugesPourDossier($dossier),
            static fn (array $a): bool => isset($manquants[$a['uid']])
        ));

        if (\count($aTraiter) < \count($manquants)) {
            $io->warning(sprintf(
                '%d amendement(s) introuvable(s) en base locale — lancer app:sync:canutes puis relancer.',
                \count($manquants) - \count($aTraiter)
            ));
        }
        if ($limite !== null) {
            $aTraiter = \array_slice($aTraiter, 0, $limite);
        }

        $contexte = $this->analyseIa->contextePourDossier($dossier);

        $lot = [];
        $reussies = 0;
        $echecsConsecutifs = 0;

        foreach ($aTraiter as $i => $amendement) {
            $analyse = $this->analyseIa->regenerer($amendement, $contexte, $modele);

            if ($analyse === null) {
                $io->writeln(sprintf('[%d/%d] n° %s — ÉCHEC de génération', $i + 1, \count($aTraiter), $amendement['numero']));
                if (++$echecsConsecutifs >= 5) {
                    $io->error('5 échecs consécutifs : dossier abandonné (les analyses déjà poussées restent en prod).');
                    $this->pousser($io, $lot, $modele);

                    return false;
                }
                sleep(2);

                continue;
            }

            $echecsConsecutifs = 0;
            ++$reussies;
            $lot[] = ['uid' => $amendement['uid']] + $analyse;
            $io->writeln(sprintf(
                '[%d/%d] n° %s — %s, impact %d, ambiguïté %d',
                $i + 1,
                \count($aTraiter),
                $amendement['numero'],
                $analyse['categorie'] ?? '?',
                $analyse['score_impact'] ?? 0,
                $analyse['ambiguite'] ?? 0
            ));

            if (\count($lot) >= self::TAILLE_LOT) {
                if (!$this->pousser($io, $lot, $modele)) {
                    return false;
                }
                $lot = [];
            }
        }

        if (!$this->pousser($io, $lot, $modele)) {
            return false;
        }

        $io->success(sprintf('%d analyse(s) générée(s) et poussée(s) sur %d.', $reussies, \count($aTraiter)));

        // On ne clôture que si tout le dossier y est : en cas de --limite ou
        // d'amendements sautés, la demande reste ouverte pour une relance.
        if ($reussies === \count($manquants)) {
            return $this->cloturer($io, $dossier);
        }

        $io->note('Demande laissée ouverte (analyses restantes) — relancer la commande pour terminer.');

        return $reussies === \count($aTraiter);
    }

    /**
     * @param list<array<string, mixed>> $lot
     */
    private function pousser(SymfonyStyle $io, array $lot, string $modele): bool
    {
        if ($lot === []) {
            return true;
        }

        try {
            $reponse = $this->http->post('api/ia/analyses', [
                'json' => ['modele' => $modele, 'analyses' => $lot],
            ]);
        } catch (GuzzleException $e) {
            $io->error(sprintf('Poussée de %d analyse(s) en échec : %s', \count($lot), $e->getMessage()));

            return false;
        }

        $retour = json_decode((string) $reponse->getBody(), true);
        if (!empty($retour['rejetees'])) {
            $io->warning('Rejetées par la prod (entrées invalides) : ' . implode(', ', $retour['rejetees']));
        }
        // Le garde-fou de la prod : ces amendements avaient déjà une analyse
        // d'un modèle distant, qu'un modèle local n'a pas à remplacer. Le
        // signaler haut et fort, c'est le symptôme d'une file mal filtrée.
        if (!empty($retour['ignorees'])) {
            $io->warning(sprintf(
                '%d analyse(s) ignorée(s) par la prod : une analyse « claude-* » y existe déjà '
                . "(le travail local a été fait pour rien).\nSignalement : %s",
                \count($retour['ignorees']),
                implode(', ', \array_slice($retour['ignorees'], 0, 5))
                    . (\count($retour['ignorees']) > 5 ? '…' : '')
            ));
        }

        return true;
    }

    private function cloturer(SymfonyStyle $io, string $dossier): bool
    {
        try {
            $this->http->post(sprintf('api/ia/demandes/%s/cloture', $dossier));
        } catch (GuzzleException $e) {
            $io->error('Clôture en échec : ' . $e->getMessage());

            return false;
        }

        $io->writeln('Demande clôturée.');

        return true;
    }
}
