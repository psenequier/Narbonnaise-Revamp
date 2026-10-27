-- Schéma GRR 4.1.1 réduit aux colonnes lues par la reprise (ADR 014).
-- Tables TEMPORAIRES : elles ne valident pas la transaction du test et
-- disparaissent avec la connexion. Aucune donnée réelle.
CREATE TEMPORARY TABLE grr_utilisateurs (
  login varchar(190) NOT NULL PRIMARY KEY,
  nom varchar(30) NOT NULL DEFAULT '',
  prenom varchar(30) NOT NULL DEFAULT '',
  password varchar(255) NOT NULL DEFAULT '',
  email varchar(100) NOT NULL DEFAULT '',
  statut varchar(30) NOT NULL DEFAULT '',
  etat varchar(20) NOT NULL DEFAULT '',
  default_area smallint NOT NULL DEFAULT 0,
  default_room smallint NOT NULL DEFAULT 0,
  source varchar(10) NOT NULL DEFAULT 'local',
  changepwd tinyint NOT NULL DEFAULT 0
);
CREATE TEMPORARY TABLE grr_type_area (
  id int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  type_name varchar(30) NOT NULL DEFAULT '',
  order_display smallint NOT NULL DEFAULT 0,
  couleur smallint NOT NULL DEFAULT 0,
  type_letter char(2) NOT NULL DEFAULT '',
  disponible varchar(1) NOT NULL DEFAULT '2'
);
CREATE TEMPORARY TABLE grr_entry (
  id int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  start_time int NOT NULL DEFAULT 0,
  end_time int NOT NULL DEFAULT 0,
  entry_type int NOT NULL DEFAULT 0,
  repeat_id int NOT NULL DEFAULT 0,
  room_id int NOT NULL DEFAULT 1,
  timestamp timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  create_by varchar(100) NOT NULL DEFAULT '',
  beneficiaire_ext varchar(200) NOT NULL DEFAULT '',
  beneficiaire varchar(100) NOT NULL DEFAULT '',
  name varchar(80) NOT NULL DEFAULT '',
  type char(2) DEFAULT NULL,
  description text,
  statut_entry char(1) NOT NULL DEFAULT '-',
  option_reservation int NOT NULL DEFAULT 0,
  overload_desc text,
  moderate tinyint DEFAULT 0,
  jours tinyint NOT NULL DEFAULT 0,
  clef int NOT NULL DEFAULT 0,
  courrier int NOT NULL DEFAULT 0,
  nbparticipantmax int NOT NULL DEFAULT 0,
  supprimer tinyint(1) NOT NULL DEFAULT 0
);
CREATE TEMPORARY TABLE grr_area (
  id int NOT NULL PRIMARY KEY,
  area_name varchar(30) NOT NULL,
  enable_periods char(1) NOT NULL DEFAULT 'n'
);
CREATE TEMPORARY TABLE grr_room (
  id int NOT NULL PRIMARY KEY,
  area_id int NOT NULL DEFAULT 0,
  room_name varchar(60) NOT NULL DEFAULT ''
);
CREATE TEMPORARY TABLE grr_j_mailuser_room (
  login varchar(190) NOT NULL DEFAULT '',
  id_room int NOT NULL DEFAULT 0
);
