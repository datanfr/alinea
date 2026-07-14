<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importe les comptes rendus intégraux de la séance publique du Sénat dans le
 * schéma « alinea » (mêmes tables que l'AN, chambre = 'senat').
 *
 * L'open data du Sénat (base « Débats », schéma senat.debats_*) ne fournit que
 * la structure des séances — sections, ordre des orateurs — mais plus le texte
 * des interventions (le champ intana ne contient que des renvois de page
 * depuis 2020). Le texte intégral vit sur senat.fr, découpé en fichiers HTML
 * s{AAAAMMJJ}{NNN}.html soigneusement balisés de commentaires :
 *
 *   <!--cri:pdl typleccod="1" loicod="68434" --> … <!--/cri:pdl -->
 *     borne la discussion d'un texte de loi (loicod = clé dosleg) ;
 *   <!--cri:intervenant mat="14111D" nom="Alain MARC" qua="…" --> … <!--/cri:intervenant -->
 *     borne une intervention et donne l'orateur.
 *
 * Chaque parole importée est ainsi taguée du loicod dosleg (colonne loi_ref),
 * ce qui rattache directement les débats à une loi promulguée via
 * senat.dosleg_loi.numero (= numéro Légifrance, ex. « 2025-532 »).
 */
#[AsCommand(name: 'app:import:comptes-rendus-senat', description: 'Importe les CRI de séance publique du Sénat (senat.fr) dans alinea.cr_parole')]
class ImportComptesRendusSenatCommand extends Command
{
    private const URL_SEANCES = 'https://www.senat.fr/seances/s%s/s%s';
    private const MAX_FICHIERS = 60;

    /** Contrôles C1 (Windows-1252) que les pages sèment via des entités numériques. */
    private const CP1252 = [
        "\u{85}" => '…', "\u{91}" => '’', "\u{92}" => '’', "\u{93}" => '«',
        "\u{94}" => '»', "\u{96}" => '–', "\u{97}" => '—', "\u{9C}" => 'œ',
    ];

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('loi', null, InputOption::VALUE_REQUIRED, 'Limiter aux séances des lectures Sénat d\'une loi promulguée (numéro Légifrance, ex. 2025-532)')
            ->addOption('depuis', null, InputOption::VALUE_REQUIRED, 'Sans --loi : importer toutes les séances depuis cette date', '2024-09-01')
            ->addOption('reprise', null, InputOption::VALUE_NONE, 'Ignorer les séances déjà importées');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->creerTables();

        $seances = $this->listerSeances($input->getOption('loi'), $input->getOption('depuis'));
        if ($seances === []) {
            $io->warning('Aucune séance à importer.');

            return self::SUCCESS;
        }

        if ($input->getOption('reprise')) {
            $dejaImportees = array_flip($this->connection->fetchFirstColumn(
                "SELECT uid FROM alinea.compte_rendu WHERE chambre = 'senat'"
            ));
            $seances = array_values(array_filter(
                $seances,
                static fn (string $date): bool => !isset($dejaImportees['CRSSEN' . str_replace('-', '', $date)])
            ));
        }

        $io->section(sprintf('Import de %d séances du Sénat', \count($seances)));

        $total = 0;
        $paroles = 0;
        foreach ($seances as $date) {
            $nb = $this->importerSeance($date);
            if ($nb === null) {
                $io->writeln(sprintf('  %s : aucun fichier récupéré', $date));

                continue;
            }
            ++$total;
            $paroles += $nb;
            $io->writeln(sprintf('  %s : %d paroles', $date, $nb));
        }

        $io->success(sprintf('%d séances du Sénat importées (%d paroles).', $total, $paroles));

        return self::SUCCESS;
    }

    /**
     * Dates (Y-m-d) des séances à importer.
     *
     * Avec --loi : les séances où une lecture Sénat de la loi a été discutée
     * (dosleg → sections de la base Débats ; lecassidt peut porter plusieurs
     * identifiants séparés par « ; » quand des textes sont examinés ensemble).
     *
     * @return list<string>
     */
    private function listerSeances(?string $numLoi, string $depuis): array
    {
        if ($numLoi === null || $numLoi === '') {
            return $this->connection->fetchFirstColumn(
                'SELECT DISTINCT datsea::date::text FROM senat.debats_debats WHERE datsea >= ? ORDER BY 1',
                [$depuis]
            );
        }

        return $this->connection->fetchFirstColumn(
            <<<'SQL'
            SELECT DISTINCT s.datsea::date::text
            FROM senat.dosleg_loi loi
            JOIN senat.dosleg_lecture le ON le.loicod = loi.loicod
            JOIN senat.dosleg_lecass l ON l.lecidt = le.lecidt
            JOIN senat.debats_secdis s ON s.lecassidt ~ ('(^|;)' || trim(l.lecassidt) || '(;|$)')
            WHERE trim(loi.numero) = :num
            ORDER BY 1
            SQL,
            ['num' => $numLoi]
        );
    }

    /**
     * @return int|null nombre de paroles, ou null si aucun fichier n'a pu être lu
     */
    private function importerSeance(string $date): ?int
    {
        $ymd = str_replace('-', '', $date);
        $base = sprintf(self::URL_SEANCES, substr($ymd, 0, 6), $ymd);
        $uid = 'CRSSEN' . $ymd;

        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement(
                <<<'SQL'
                INSERT INTO alinea.compte_rendu (uid, seance_ref, session_libelle, num_seance_jour, date_seance, legislature, type, url_page, chambre)
                VALUES (?, ?, 'Séance publique du Sénat', NULL, ?, NULL, 'seance', ?, 'senat')
                ON CONFLICT (uid) DO UPDATE SET
                    date_seance = excluded.date_seance,
                    url_page = excluded.url_page,
                    type = excluded.type,
                    chambre = excluded.chambre
                SQL,
                [$uid, $uid, $date, sprintf('%s/st%s000.html', $base, $ymd)]
            );
            $this->connection->executeStatement('DELETE FROM alinea.cr_parole WHERE compte_rendu_uid = ?', [$uid]);

            $nb = 0;
            $fichiers = 0;
            for ($n = 1; $n <= self::MAX_FICHIERS; ++$n) {
                $html = $this->telecharger(sprintf('%s/s%s%03d.html', $base, $ymd, $n));
                if ($html === null) {
                    break;
                }
                ++$fichiers;
                $nb = $this->importerFichier($uid, $html, $nb);
                usleep(150000); // politesse envers senat.fr
            }

            if ($fichiers === 0) {
                $this->connection->rollBack();

                return null;
            }

            $this->connection->commit();

            return $nb;
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }

    /**
     * Balayage séquentiel d'un fichier : les commentaires cri:pdl et
     * cri:intervenant fixent le contexte (loi discutée, orateur courant) des
     * paragraphes qui suivent. Les <p> hors intervention (mention d'article,
     * libellé d'amendement, texte cité) deviennent des paroles anonymes — ce
     * sont elles qui bornent les fenêtres de discussion, comme à l'AN.
     *
     * @return int dernier ordre utilisé
     */
    private function importerFichier(string $uid, string $html, int $ordre): int
    {
        // Le contenu utile vit sous <div id="wysiwyg"> ; avant, la navigation.
        $debut = strpos($html, 'id="wysiwyg"');
        if ($debut !== false) {
            $html = substr($html, $debut);
        }

        preg_match_all(
            '{<!--cri:pdl[^>]*\bloicod="(\d+)"[^>]*-->'
            . '|<!--/cri:pdl -->'
            . '|<!--cri:intervenant\b([^>]*)-->'
            . '|<!--/cri:intervenant -->'
            . '|<p\b([^>]*)>(.*?)</p>}s',
            $html,
            $blocs,
            PREG_SET_ORDER
        );

        $loi = null;
        $orateur = null;
        $qualite = null;
        foreach ($blocs as $bloc) {
            if (str_starts_with($bloc[0], '<!--cri:pdl')) {
                $loi = $bloc[1];

                continue;
            }
            if ($bloc[0] === '<!--/cri:pdl -->') {
                $loi = null;

                continue;
            }
            if (str_starts_with($bloc[0], '<!--cri:intervenant')) {
                [$orateur, $qualite] = $this->lireIntervenant($bloc[2]);

                continue;
            }
            if ($bloc[0] === '<!--/cri:intervenant -->') {
                $orateur = null;
                $qualite = null;

                continue;
            }

            $texte = $this->nettoyer($bloc[4]);
            if ($texte === '') {
                continue;
            }

            $this->connection->executeStatement(
                'INSERT INTO alinea.cr_parole (compte_rendu_uid, ordre_absolu_seance, orateur_nom, orateur_qualite, texte_brut, loi_ref)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$uid, ++$ordre, $orateur, $qualite, $texte, $loi]
            );
        }

        return $ordre;
    }

    /**
     * Nom et qualité depuis les attributs du commentaire cri:intervenant
     * (nom="Alain MARC" qua="président de séance"). Le nom de famille arrive
     * tout en capitales : on le remet en casse de titre pour l'affichage.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function lireIntervenant(string $attributs): array
    {
        preg_match('/\bnom="([^"]*)"/', $attributs, $nom);
        preg_match('/\bqua="([^"]*)"/', $attributs, $qua);

        $orateur = $this->decoder($nom[1] ?? '');
        $orateur = preg_replace_callback(
            '/\b(\p{Lu}[\p{Lu}\'’-]+)(?=\s|$)/u',
            static fn (array $m): string => mb_convert_case(mb_strtolower($m[1]), MB_CASE_TITLE),
            $orateur
        );

        return [$orateur ?: null, $this->decoder($qua[1] ?? '') ?: null];
    }

    /**
     * Texte d'un paragraphe : le nom de l'orateur en tête de première parole
     * (balisé orateur_nom, déjà porté par la colonne dédiée) est retiré.
     */
    private function nettoyer(string $fragment): string
    {
        $fragment = preg_replace('{<!--cri:orateurnom -->.*?<!--/cri:orateurnom -->}s', '', $fragment);

        return trim(preg_replace('/\s+/u', ' ', $this->decoder(strip_tags($fragment))));
    }

    private function decoder(string $texte): string
    {
        return strtr(html_entity_decode($texte, ENT_QUOTES | ENT_HTML5, 'UTF-8'), self::CP1252);
    }

    private function telecharger(string $url): ?string
    {
        exec(sprintf('curl -fsSL --max-time 30 %s 2>/dev/null', escapeshellarg($url)), $lignes, $code);

        return $code === 0 ? implode("\n", $lignes) : null;
    }

    private function creerTables(): void
    {
        $ddl = [
            'CREATE SCHEMA IF NOT EXISTS alinea',
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS alinea.compte_rendu (
                uid text PRIMARY KEY,
                seance_ref text,
                session_libelle text,
                num_seance_jour text,
                date_seance timestamptz,
                legislature integer
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS compte_rendu_seance_ref_idx ON alinea.compte_rendu (seance_ref)',
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS alinea.cr_parole (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                compte_rendu_uid text NOT NULL REFERENCES alinea.compte_rendu (uid) ON DELETE CASCADE,
                ordre_absolu_seance integer NOT NULL,
                orateur_nom text,
                orateur_qualite text,
                texte_brut text NOT NULL
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS cr_parole_cr_ordre_idx ON alinea.cr_parole (compte_rendu_uid, ordre_absolu_seance)',
            "ALTER TABLE alinea.compte_rendu ADD COLUMN IF NOT EXISTS type text NOT NULL DEFAULT 'seance'",
            'ALTER TABLE alinea.compte_rendu ADD COLUMN IF NOT EXISTS url_page text',
            "ALTER TABLE alinea.compte_rendu ADD COLUMN IF NOT EXISTS chambre text NOT NULL DEFAULT 'an'",
            'ALTER TABLE alinea.cr_parole ADD COLUMN IF NOT EXISTS loi_ref text',
            'CREATE INDEX IF NOT EXISTS cr_parole_loi_ref_idx ON alinea.cr_parole (loi_ref) WHERE loi_ref IS NOT NULL',
        ];

        foreach ($ddl as $sql) {
            $this->connection->executeStatement($sql);
        }
    }
}
