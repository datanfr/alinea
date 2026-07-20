<?php

namespace App\Command;

use App\Repository\ResumeIaRepository;
use App\Service\AnalyseAmendementIa;
use App\Service\AnalyseArrierePlan;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pré-génère les analyses IA des amendements adoptés et rejetés d'un dossier
 * (AN et, quand la loi a un dossier sénatorial, Sénat), sans attendre les
 * chargements de page (plafonnés à MAX_GENERATIONS par visite). Permet aussi
 * de régénérer tout un dossier après un changement de prompt, ou avec un
 * autre modèle que celui par défaut.
 *
 *   bin/console app:ia:analyser DLR5L17N50169 --modele=gemma4:31b --regenerer
 *   bin/console app:ia:analyser DLR5L17N50169 --modele=claude-haiku-4-5
 */
#[AsCommand(
    name: 'app:ia:analyser',
    description: "Pré-génère les analyses IA des amendements adoptés/rejetés d'un dossier",
)]
class AnalyserAmendementsCommand extends Command
{
    public function __construct(
        private readonly ResumeIaRepository $resumes,
        private readonly AnalyseAmendementIa $analyseIa,
        private readonly AnalyseArrierePlan $arrierePlan,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('dossier', InputArgument::REQUIRED, 'UID du dossier législatif AN (DLR…)')
            ->addOption('modele', 'm', InputOption::VALUE_REQUIRED, 'Modèle IA — « claude-* » via Anthropic, sinon Ollama (défaut : IA_MODELE)')
            ->addOption('regenerer', 'r', InputOption::VALUE_NONE, 'Régénère aussi les analyses déjà en base')
            ->addOption('limite', 'l', InputOption::VALUE_REQUIRED, "Nombre maximal d'analyses à générer");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dossier = $input->getArgument('dossier');
        $modele = $input->getOption('modele');
        $limite = $input->getOption('limite');

        // Verrou tenu jusqu'à la fin du processus : la page de la loi s'en
        // sert pour afficher « traitement en cours », et deux traitements du
        // même dossier ne peuvent pas se chevaucher.
        $verrou = $this->arrierePlan->verrouiller($dossier);
        if ($verrou === null) {
            $io->warning(sprintf('Un traitement du dossier %s est déjà en cours.', $dossier));

            return Command::SUCCESS;
        }

        // Jugés des deux chambres : le dossier AN, plus le dossier sénatorial
        // de la même loi quand il existe (uids SEN…, analysés à l'identique).
        $juges = $this->analyseIa->jugesPourDossier($dossier);
        if ($juges === []) {
            $io->warning(sprintf('Aucun amendement adopté ou rejeté pour le dossier %s.', $dossier));

            return Command::SUCCESS;
        }

        $existantes = $input->getOption('regenerer')
            ? []
            : $this->resumes->analysesAmendements(array_column($juges, 'uid'));

        $aTraiter = array_values(array_filter(
            $juges,
            static fn (array $a): bool => !isset($existantes[$a['uid']])
        ));
        if ($limite !== null) {
            $aTraiter = \array_slice($aTraiter, 0, (int) $limite);
        }

        if ($aTraiter === []) {
            $io->success('Toutes les analyses sont déjà en base (utiliser --regenerer pour les refaire).');

            return Command::SUCCESS;
        }

        $io->title(sprintf(
            '%d amendements à analyser sur %d (%s)',
            \count($aTraiter),
            \count($juges),
            $modele ?? 'modèle par défaut'
        ));

        $contexte = $this->analyseIa->contextePourDossier($dossier);

        $reussies = 0;
        $echecsConsecutifs = 0;

        foreach ($aTraiter as $i => $amendement) {
            $analyse = $this->analyseIa->regenerer($amendement, $contexte, $modele);

            if ($analyse === null) {
                $io->writeln(sprintf('[%d/%d] n° %s — ÉCHEC API', $i + 1, \count($aTraiter), $amendement['numero']));
                if (++$echecsConsecutifs >= 5) {
                    $io->error('5 échecs consécutifs : arrêt (les analyses acquises restent en base).');

                    return Command::FAILURE;
                }
                sleep(2);

                continue;
            }

            $echecsConsecutifs = 0;
            ++$reussies;
            $io->writeln(sprintf(
                '[%d/%d] n° %s — %s, impact %d, ambiguïté %d%s',
                $i + 1,
                \count($aTraiter),
                $amendement['numero'],
                $analyse['categorie'] ?? '?',
                $analyse['score_impact'] ?? 0,
                $analyse['ambiguite'] ?? 0,
                $analyse['resume'] === '' ? ' (technique, résumé vide)' : ''
            ));
        }

        $io->success(sprintf('%d analyses générées sur %d.', $reussies, \count($aTraiter)));

        return $reussies === \count($aTraiter) ? Command::SUCCESS : Command::FAILURE;
    }
}
