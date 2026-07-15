<?php

namespace App\Command;

use App\Repository\AmendementRepository;
use App\Service\AnalyseAmendementIa;
use App\Service\AnalyseArrierePlan;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
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
 * Les analyses sont aussi persistées en base locale (regenerer) : local et
 * prod restent alignés. Un amendement absent de la base locale (retard de
 * sync) est signalé et sauté — relancer après app:sync:canutes.
 *
 *   bin/console app:ia:demandes
 *   bin/console app:ia:demandes --modele=gemma4:27b --limite=50
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

    private readonly HttpClient $http;

    public function __construct(
        #[Autowire(env: 'ALINEA_DISTANT_URL')] string $distantUrl,
        #[Autowire(env: 'IA_API_JETON')] private readonly string $jeton,
        #[Autowire(env: 'IA_MODELE')] private readonly string $modeleDefaut,
        private readonly AmendementRepository $amendements,
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
            ->addOption('modele', 'm', InputOption::VALUE_REQUIRED, 'Modèle IA local (défaut : IA_MODELE)')
            ->addOption('limite', 'l', InputOption::VALUE_REQUIRED, "Nombre maximal d'analyses par dossier");
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

        try {
            $reponse = $this->http->get('api/ia/demandes');
            $demandes = json_decode((string) $reponse->getBody(), true)['demandes'] ?? [];
        } catch (GuzzleException $e) {
            $io->error('Récupération des demandes en échec : ' . $e->getMessage());

            return Command::FAILURE;
        }

        if ($demandes === []) {
            $io->success('Aucune demande en attente.');

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
            $this->amendements->findParSortPourDossier($dossier, ['Adopté', 'Rejeté']),
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

        $contexte = [
            'seances' => $this->amendements->findSeancesPourDossier($dossier),
            'crCommissions' => $this->amendements->findCrCommissionsPourDossier($dossier),
        ];

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
            $io->warning('Rejetées par la prod : ' . implode(', ', $retour['rejetees']));
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
