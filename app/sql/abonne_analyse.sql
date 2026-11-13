-- Visiteurs qui ont laissé leur adresse pour être prévenus quand l'analyse
-- IA d'un dossier est terminée (champ facultatif « me prévenir quand c'est
-- fait », à côté du bouton d'analyse de la page d'une loi).
--
-- L'adresse ne sert qu'à cet envoi : la ligne est supprimée dès l'email
-- parti, et les inscriptions oubliées (analyse jamais faite) sont purgées
-- au bout de six mois.
--
-- Idempotent : réexécutable sans risque.

CREATE SCHEMA IF NOT EXISTS alinea;

CREATE TABLE IF NOT EXISTS alinea.abonne_analyse (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    dossier_uid text        NOT NULL,   -- dossier législatif AN (DLR…)
    loi_id      text        NOT NULL,   -- id JORFTEXT de la loi (lien de la page)
    email       text        NOT NULL,
    inscrit_le  timestamptz NOT NULL DEFAULT now()
);

-- Deux inscriptions de la même personne sur la même loi : une seule alerte.
-- Sert aussi de recherche par dossier (préfixe gauche de l'index).
CREATE UNIQUE INDEX IF NOT EXISTS abonne_analyse_unique
    ON alinea.abonne_analyse (dossier_uid, lower(email));

COMMENT ON TABLE  alinea.abonne_analyse             IS 'Adresses à prévenir quand l''analyse IA d''un dossier est terminée ; la ligne est supprimée après l''envoi.';
COMMENT ON COLUMN alinea.abonne_analyse.dossier_uid IS 'UID du dossier législatif AN (assemblee.dossiers.uid).';
COMMENT ON COLUMN alinea.abonne_analyse.loi_id      IS 'Id JORFTEXT de la loi (legifrance.texte_version.id), pour le lien de l''email.';
COMMENT ON COLUMN alinea.abonne_analyse.email       IS 'Adresse saisie par le visiteur, effacée dès l''alerte envoyée.';
