-- Compteur de consultations des pages de lois, agrégé par jour : sert à
-- prioriser le traitement IA de fond (les lois les plus consultées d'abord,
-- via l'endpoint /api/ia/interet consommé par app:ia:demandes --toutes).
--
-- Volontairement fruste (pas de dédoublonnage visiteur, robots compris) :
-- le signal sert d'ordre relatif, pas de statistique d'audience.
--
-- Idempotent : réexécutable sans risque.

CREATE SCHEMA IF NOT EXISTS alinea;

CREATE TABLE IF NOT EXISTS alinea.consultation_loi (
    loi_id text    NOT NULL,                     -- id JORFTEXT de la loi
    jour   date    NOT NULL DEFAULT CURRENT_DATE,
    nb     integer NOT NULL DEFAULT 1,
    PRIMARY KEY (loi_id, jour)
);

COMMENT ON TABLE  alinea.consultation_loi        IS 'Consultations des pages de lois, par jour ; priorise le traitement IA de fond.';
COMMENT ON COLUMN alinea.consultation_loi.loi_id IS 'Id JORFTEXT de la loi (legifrance.texte_version.id).';
