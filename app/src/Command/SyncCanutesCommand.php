<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use GuzzleHttp\Client as HttpClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Met à jour la base canutes locale depuis l'API REST des Tricoteuses
 * (PostgREST sur https://db.code4code.eu/canutes/, service « API canutes »).
 *
 * La synchronisation est additive : chaque table est parcourue par pagination
 * keyset sur sa clé primaire, puis upsertée localement via
 * jsonb_populate_recordset + ON CONFLICT. Les tables volumineuses de
 * legifrance (texte_version, article) ne sont rafraîchies que pour les textes
 * publiés depuis --depuis, ainsi que les articles des lois correspondantes ;
 * pour une remise à niveau complète, restaurer le dump quotidien
 * (https://dump.tricoteuses.fr/canutes/latest/canutes.dump).
 */
#[AsCommand(name: 'app:sync:canutes', description: 'Met à jour les schémas assemblee/legifrance depuis l\'API PostgREST des Tricoteuses')]
class SyncCanutesCommand extends Command
{
    private const API = 'https://db.code4code.eu/canutes/';

    /** Tables synchronisées intégralement : [schéma, table, clé primaire, filtre PostgREST, taille de page]. */
    private const TABLES = [
        ['assemblee', 'acteurs', 'uid', '', 2000],
        ['assemblee', 'organes', 'uid', '', 2000],
        ['assemblee', 'scrutins', 'uid', '', 500],
        ['assemblee', 'dossiers', 'uid', '', 500],
        ['assemblee', 'documents', 'uid', '', 500],
        ['assemblee', 'reunions', 'uid', '', 1000],
        ['assemblee', 'amendements', 'uid', 'legislature=eq.17', 1000],
        // Sénat : tables parentes (FK) avant ameli_sub, elle-même parente
        // des amendements synchronisés ensuite par la section ameli_amd.
        ['senat', 'dosleg_loi', 'loicod', '', 2000],
        ['senat', 'dosleg_lecture', 'lecidt', '', 2000],
        ['senat', 'dosleg_lecass', 'lecassidt', '', 2000],
        ['senat', 'ameli_ses', 'id', '', 2000],
        ['senat', 'ameli_sor', 'id', '', 2000],
        ['senat', 'ameli_ent', 'id', '', 2000],
        ['senat', 'ameli_com_ameli', 'entid', '', 2000],
        ['senat', 'ameli_sen_ameli', 'entid', '', 2000],
        ['senat', 'ameli_grppol_ameli', 'entid', '', 2000],
        ['senat', 'ameli_mot', 'id', '', 2000],
        ['senat', 'ameli_irr', 'id', '', 2000],
        ['senat', 'ameli_avicom', 'id', '', 2000],
        ['senat', 'ameli_avigvt', 'id', '', 2000],
        ['senat', 'ameli_typrect', 'id', '', 2000],
        ['senat', 'ameli_typsub', 'id', '', 2000],
        ['senat', 'ameli_txt_ameli', 'id', '', 2000],
        ['senat', 'ameli_sub', 'id', '', 5000],
        ['senat', 'debats_debats', 'datsea', '', 2000],
        ['senat', 'debats_secdis', 'secdiscle', '', 5000],
    ];

    private HttpClient $http;

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
        $this->http = new HttpClient(['base_uri' => self::API, 'timeout' => 180]);
    }

    protected function configure(): void
    {
        $this
            ->addOption('depuis', 'd', InputOption::VALUE_REQUIRED, 'Date de publication JORF à partir de laquelle rafraîchir legifrance', date('Y-m-d', strtotime('-30 days')))
            ->addOption('table', 't', InputOption::VALUE_REQUIRED, 'Limiter à une seule table (nom sans schéma)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $depuis = (string) $input->getOption('depuis');
        $seule = $input->getOption('table');

        foreach (self::TABLES as [$schema, $table, $pk, $filtre, $page]) {
            if ($seule !== null && $seule !== $table) {
                continue;
            }
            $io->section(sprintf('%s.%s', $schema, $table));
            $n = $this->synchroniserTable($io, $schema, $table, $pk, $filtre, $page);
            $io->writeln(sprintf('  %d lignes upsertées.', $n));
        }

        if ($seule === null || $seule === 'ameli_amd') {
            $cutoff = date('Y-m-d', strtotime('-90 days'));
            $io->section('senat.ameli_amd (déposés depuis ' . $cutoff . ')');
            $n = $this->synchroniserTable($io, 'senat', 'ameli_amd', 'id', 'datdep=gte.' . $cutoff, 1000);
            $io->writeln(sprintf('  %d lignes upsertées.', $n));

            $io->section('senat.ameli_amdsen (signataires des amendements récents)');
            $n = $this->synchroniserAmdsen($io, $cutoff);
            $io->writeln(sprintf('  %d lignes upsertées.', $n));
        }

        if ($seule === null || $seule === 'texte_version') {
            $io->section('legifrance.texte_version (publiés depuis ' . $depuis . ')');
            $filtre = 'data->META->META_SPEC->META_TEXTE_CHRONICLE->>DATE_PUBLI=gte.' . $depuis;
            $n = $this->synchroniserTable($io, 'legifrance', 'texte_version', 'id', $filtre, 200);
            $io->writeln(sprintf('  %d lignes upsertées.', $n));
        }

        if ($seule === null || $seule === 'article') {
            $io->section('legifrance.article (articles des lois publiées depuis ' . $depuis . ')');
            $n = $this->synchroniserArticles($io, $depuis);
            $io->writeln(sprintf('  %d articles upsertés.', $n));
        }

        $io->success('Synchronisation terminée.');

        return self::SUCCESS;
    }

    /**
     * @return int nombre de lignes upsertées
     */
    private function synchroniserTable(SymfonyStyle $io, string $schema, string $table, string $pk, string $filtre, int $parPage): int
    {
        $upsert = $this->preparerUpsert($schema, $table, $pk);
        $total = 0;
        $curseur = '';

        while (true) {
            $query = sprintf('order=%s&limit=%d', $pk, $parPage);
            if ($curseur !== '') {
                $query .= sprintf('&%s=gt.%s', $pk, rawurlencode($curseur));
            }
            if ($filtre !== '') {
                $query .= '&' . $filtre;
            }

            $lignes = $this->requeter($schema, $table . '?' . $query);
            if ($lignes === []) {
                break;
            }

            $total += $this->upserter($upsert, $lignes);
            $curseur = (string) $lignes[array_key_last($lignes)][$pk];
            $io->write(sprintf("\r  %d…", $total));
            usleep(100000); // politesse envers l'API
        }
        $io->writeln('');

        return $total;
    }

    /**
     * Rafraîchit les articles rattachés (CONTEXTE.TEXTE.@cid) aux lois
     * publiées au JORF depuis $depuis, telles que déjà présentes localement
     * après la synchro de texte_version.
     *
     * @return int nombre d'articles upsertés
     */
    private function synchroniserArticles(SymfonyStyle $io, string $depuis): int
    {
        $cids = $this->connection->fetchFirstColumn(
            <<<'SQL'
            SELECT DISTINCT c.cid FROM legifrance.texte_version tv
            CROSS JOIN LATERAL (VALUES (tv.id), (tv.data->'META'->'META_COMMUN'->>'CID')) AS c(cid)
            WHERE tv.nature = 'LOI'
              AND tv.data->'META'->'META_SPEC'->'META_TEXTE_CHRONICLE'->>'DATE_PUBLI' >= :depuis
              AND c.cid IS NOT NULL
            SQL,
            ['depuis' => $depuis]
        );
        $io->writeln(sprintf('  %d lois concernées.', \count($cids)));

        $upsert = $this->preparerUpsert('legifrance', 'article', 'id');
        $total = 0;
        foreach ($cids as $cid) {
            $lignes = $this->requeter('legifrance', 'article?data->CONTEXTE->TEXTE->>%40cid=eq.' . rawurlencode($cid) . '&limit=2000');
            if ($lignes !== []) {
                $total += $this->upserter($upsert, $lignes);
            }
            usleep(100000);
        }

        return $total;
    }

    /**
     * Les signataires (PK composite amdid+senid) des amendements déposés
     * depuis $cutoff : pagination par offset sur le sous-ensemble filtré,
     * l'upsert étant idempotent.
     *
     * @return int nombre de lignes upsertées
     */
    private function synchroniserAmdsen(SymfonyStyle $io, string $cutoff): int
    {
        $minAmdId = $this->connection->fetchOne('SELECT min(id) FROM senat.ameli_amd WHERE datdep >= ?', [$cutoff]);
        if ($minAmdId === null) {
            return 0;
        }

        $upsert = $this->preparerUpsert('senat', 'ameli_amdsen', 'amdid,senid');
        $total = 0;
        for ($offset = 0; ; $offset += 2000) {
            $lignes = $this->requeter('senat', sprintf('ameli_amdsen?amdid=gte.%d&order=amdid,senid&limit=2000&offset=%d', $minAmdId, $offset));
            if ($lignes === []) {
                break;
            }
            $total += $this->upserter($upsert, $lignes);
            $io->write(sprintf("\r  %d…", $total));
            usleep(100000);
        }
        $io->writeln('');

        return $total;
    }

    /**
     * Construit l'INSERT … ON CONFLICT couvrant toutes les colonnes locales ;
     * jsonb_populate_recordset fait correspondre les clés JSON de l'API aux
     * colonnes par leur nom et ignore les clés inconnues.
     */
    private function preparerUpsert(string $schema, string $table, string $pk): string
    {
        $colonnes = $this->connection->fetchFirstColumn(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
            [$schema, $table]
        );
        $clePrimaire = explode(',', $pk);

        $citer = fn (string $c) => '"' . $c . '"';
        $liste = implode(', ', array_map($citer, $colonnes));
        $maj = implode(', ', array_map(
            fn (string $c) => sprintf('"%s" = excluded."%s"', $c, $c),
            array_values(array_diff($colonnes, $clePrimaire))
        ));

        return sprintf(
            'INSERT INTO %s.%s (%s) SELECT %s FROM jsonb_populate_recordset(NULL::%s.%s, ?::jsonb) ON CONFLICT (%s) DO UPDATE SET %s',
            $schema, $table, $liste, $liste, $schema, $table, implode(', ', array_map($citer, $clePrimaire)), $maj
        );
    }

    private function upserter(string $upsert, array $lignes): int
    {
        return (int) $this->connection->executeStatement($upsert, [json_encode($lignes)]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function requeter(string $schema, string $cheminEtQuery): array
    {
        $tentatives = 0;
        while (true) {
            try {
                $reponse = $this->http->get($cheminEtQuery, ['headers' => ['Accept-Profile' => $schema]]);

                return json_decode((string) $reponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                if (++$tentatives >= 3) {
                    throw $e;
                }
                sleep(5 * $tentatives);
            }
        }
    }
}
