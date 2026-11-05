-- Synthetic, disposable fixture only. No production data or credentials.
CREATE DATABASE MYMAR CHARACTER SET utf8mb4;
CREATE DATABASE cofrac_mymar_integration CHARACTER SET utf8mb4;
CREATE TABLE MYMAR.users (id bigint unsigned PRIMARY KEY,enabled int,name varchar(255),password varchar(255)) ENGINE=InnoDB;
CREATE TABLE MYMAR.roles (id bigint unsigned PRIMARY KEY,name varchar(255)) ENGINE=InnoDB;
CREATE TABLE MYMAR.model_has_roles (model_id bigint unsigned,model_type varchar(255),role_id bigint unsigned,KEY(model_id)) ENGINE=InnoDB;
CREATE TABLE MYMAR.clients (id bigint unsigned PRIMARY KEY,nom varchar(255),prenom varchar(255)) ENGINE=InnoDB;
CREATE TABLE MYMAR.status (id bigint unsigned PRIMARY KEY,name varchar(255),deleted_at timestamp NULL) ENGINE=InnoDB;
CREATE TABLE MYMAR.dossiers (id bigint unsigned PRIMARY KEY,client_id bigint unsigned,status_id bigint unsigned,technicien_id bigint unsigned,sub_statut varchar(255),updated_at timestamp NULL) ENGINE=InnoDB;
CREATE TABLE MYMAR.dossier_rdv (id bigint unsigned PRIMARY KEY,dossier_id bigint unsigned,date_rdv datetime,is_old int,updated_at timestamp NULL,KEY(dossier_id)) ENGINE=InnoDB;
CREATE TABLE MYMAR.dossier_documents (
 id bigint unsigned AUTO_INCREMENT PRIMARY KEY,dossier_id bigint unsigned NOT NULL,author_id bigint unsigned NOT NULL,
 path varchar(255) NOT NULL,nom varchar(255),description varchar(255),is_public int,is_validated int,request_data mediumtext,
 created_at timestamp NULL,updated_at timestamp NULL,KEY(dossier_id),FOREIGN KEY(dossier_id) REFERENCES MYMAR.dossiers(id)
) ENGINE=InnoDB;
CREATE TABLE MYMAR.dossier_historiques (
 id bigint unsigned AUTO_INCREMENT PRIMARY KEY,author_id bigint unsigned NOT NULL,dossier_id bigint unsigned NOT NULL,
 status_id bigint unsigned,text varchar(255) NOT NULL,created_at timestamp NULL,updated_at timestamp NULL,
 FOREIGN KEY(dossier_id) REFERENCES MYMAR.dossiers(id)
) ENGINE=InnoDB;
INSERT INTO MYMAR.users VALUES (5,1,'Terrain A','PASSWORD_CANARY'),(6,1,'Terrain B','PASSWORD_CANARY'),(7,0,'Terrain C','PASSWORD_CANARY'),(99,0,'Cofrac planning','PASSWORD_CANARY');
INSERT INTO MYMAR.roles VALUES (1,'Technicien Terrain');
INSERT INTO MYMAR.model_has_roles VALUES (5,CONCAT('App',CHAR(92),'Models',CHAR(92),'User'),1),(6,CONCAT('App',CHAR(92),'Models',CHAR(92),'User'),1),(7,CONCAT('App',CHAR(92),'Models',CHAR(92),'User'),1);
INSERT INTO MYMAR.clients VALUES (1,'BENEFICIARY','Synthetic'),(2,'OTHER','Synthetic');
INSERT INTO MYMAR.status VALUES (16,'RDV APT ATTENTE',NULL),(19,'DEPOT (solde)',NULL),(20,'Depot fini',NULL),(21,'ANNULE',NULL);
INSERT INTO MYMAR.dossiers VALUES (20,1,16,5,'TODO','2026-11-05 08:00:00'),(21,2,16,6,NULL,'2026-11-05 08:00:00');
INSERT INTO MYMAR.dossier_rdv VALUES (40,20,'2026-11-04 10:00:00',0,'2026-11-05 08:00:00'),(41,21,'2026-11-04 11:00:00',0,'2026-11-05 08:00:00');
INSERT INTO MYMAR.dossier_documents (id,dossier_id,author_id,path,nom,is_public) VALUES
 (1,20,99,'invoice.pdf','FACTURE BENEFICIARY',1),
 (2,20,99,'amo.pdf','Facture AMO',0),
 (3,20,99,'identity.pdf','CNI BENEFICIARY',0),
 (4,20,99,'quote.pdf','DEVIS BENEFICIARY',1),
 (5,20,99,'link.pdf','FACTURE BENEFICIARY',1),
 (6,20,99,'../outside.pdf','FACTURE BENEFICIARY',1),
 (7,21,99,'other.pdf','FACTURE OTHER',1),
 (8,20,99,'missing.pdf','FACTURE BENEFICIARY',1),
 (9,20,99,'quote.pdf','ATTESTATION devis + financement',1);
