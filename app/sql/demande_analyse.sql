-- Demandes d'analyse IA déposées depuis le bouton de la page d'une loi
-- (mode différé : la prod n'a pas d'Ollama, les analyses sont générées sur
-- une machine locale qui interroge l'API /api/ia puis pousse les résultats).
--
-- Une seule demande ouverte (traite_le IS NULL) par dossier : l'index partiel
-- garantit qu'un second clic ne crée pas de doublon ni de second email.
--
-- Idempotent : réexécutable sans risque.

CREATE SCHEMA IF NOT EXISTS alinea;

CREATE TABLE IF NOT EXISTS alinea.demande_analyse (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    dossier_uid text        NOT NULL,   -- dossier législatif AN (DLR…)
    loi_id      text        NOT NULL,   -- id JORFTEXT de la loi (lien de la page)
    demande_le  timestamptz NOT NULL DEFAULT now(),
    traite_le   timestamptz             -- null tant que les analyses n'ont pas été poussées
);

CREATE UNIQUE INDEX IF NOT EXISTS demande_analyse_ouverte
    ON alinea.demande_analyse (dossier_uid)
    WHERE traite_le IS NULL;

COMMENT ON TABLE  alinea.demande_analyse             IS 'Demandes d''analyse IA (bouton de la page d''une loi), traitées par l''agent local.';
COMMENT ON COLUMN alinea.demande_analyse.dossier_uid IS 'UID du dossier législatif AN (assemblee.dossiers.uid).';
COMMENT ON COLUMN alinea.demande_analyse.loi_id      IS 'Id JORFTEXT de la loi (legifrance.texte_version.id).';
COMMENT ON COLUMN alinea.demande_analyse.traite_le   IS 'Date de clôture (analyses poussées) ; null si en attente.';
