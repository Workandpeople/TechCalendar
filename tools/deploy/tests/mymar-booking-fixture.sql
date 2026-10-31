-- Synthetic data, used only by check-mymar-booking.sh in disposable containers.
CREATE DATABASE MYMAR CHARACTER SET utf8mb4;
CREATE DATABASE cofrac2production;
CREATE TABLE cofrac2production.users (id bigint PRIMARY KEY,password varchar(255));
INSERT INTO cofrac2production.users VALUES (101,'COFRAC_PASSWORD_CANARY');
CREATE TABLE MYMAR.users (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,name varchar(255),email varchar(255) UNIQUE,password varchar(255),enabled int,created_at timestamp NULL,updated_at timestamp NULL) ENGINE=InnoDB;
CREATE TABLE MYMAR.roles (id bigint PRIMARY KEY,name varchar(255)) ENGINE=InnoDB;
CREATE TABLE MYMAR.model_has_roles (model_id bigint,model_type varchar(255),role_id bigint) ENGINE=InnoDB;
CREATE TABLE MYMAR.status (id bigint unsigned PRIMARY KEY,deleted_at timestamp NULL) ENGINE=InnoDB;
CREATE TABLE MYMAR.clients (id bigint unsigned PRIMARY KEY,nom varchar(255),prenom varchar(255),adress varchar(255),zip varchar(255),ville varchar(255),phone varchar(255),mobile varchar(255),email varchar(255),latitude varchar(255),longitude varchar(255),updated_at timestamp NULL) ENGINE=InnoDB;
CREATE TABLE MYMAR.dossiers (id bigint unsigned PRIMARY KEY,client_id bigint unsigned NOT NULL,status_id bigint unsigned NOT NULL,technicien_id bigint unsigned NULL,mar_type int,sub_statut varchar(255),commentaire_status varchar(255),date_rappel datetime,created_at timestamp NULL,updated_at timestamp NULL,FOREIGN KEY(client_id) REFERENCES MYMAR.clients(id),FOREIGN KEY(status_id) REFERENCES MYMAR.status(id)) ENGINE=InnoDB;
CREATE TABLE MYMAR.dossier_rdv (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,dossier_id bigint unsigned NOT NULL,author_id bigint unsigned NOT NULL,date_rdv datetime NOT NULL,duree int,is_old int,created_at timestamp NULL,updated_at timestamp NULL,FOREIGN KEY(dossier_id) REFERENCES MYMAR.dossiers(id),FOREIGN KEY(author_id) REFERENCES MYMAR.users(id)) ENGINE=InnoDB;
CREATE TABLE MYMAR.dossier_historiques (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,author_id bigint unsigned NOT NULL,dossier_id bigint unsigned NOT NULL,status_id bigint unsigned NULL,text varchar(255) NOT NULL,created_at timestamp NULL,updated_at timestamp NULL,FOREIGN KEY(dossier_id) REFERENCES MYMAR.dossiers(id),FOREIGN KEY(status_id) REFERENCES MYMAR.status(id)) ENGINE=InnoDB;
INSERT INTO MYMAR.users (id,name,email,password,enabled) VALUES
 (5,'Terrain A','terrain-a@example.test','SOURCE_PASSWORD_CANARY',1),
 (6,'Terrain B','terrain-b@example.test','SOURCE_PASSWORD_CANARY',1),
 (7,'Terrain inactive','inactive@example.test','SOURCE_PASSWORD_CANARY',0),
 (8,'Terrain outside scope','outside@example.test','SOURCE_PASSWORD_CANARY',1),
 (9,'Not terrain','other@example.test','SOURCE_PASSWORD_CANARY',1),
 (99,'Cofrac planning','cofrac-planning@integration.invalid','UNUSABLE_RANDOM_CANARY',0);
INSERT INTO MYMAR.roles VALUES (1,'Technicien Terrain');
INSERT INTO MYMAR.model_has_roles VALUES
 (5,CONCAT('App',CHAR(92),'Models',CHAR(92),'User'),1),
 (6,CONCAT('App',CHAR(92),'Models',CHAR(92),'User'),1),
 (7,CONCAT('App',CHAR(92),'Models',CHAR(92),'User'),1),
 (8,CONCAT('App',CHAR(92),'Models',CHAR(92),'User'),1);
INSERT INTO MYMAR.status VALUES (1,NULL),(2,NULL),(3,NULL),(14,NULL),(15,NULL),(16,NULL);
INSERT INTO MYMAR.clients VALUES
 (1,'BENEFICIARY_CANARY','Synthetic','3 rue de test','75001','Paris','0100000000',NULL,'beneficiary@example.test','48.86','2.34','2026-10-31 08:00:00'),
 (2,'Invalid coordinates','Synthetic','Lieu dit','01560','Lescheroux',NULL,'0600000000',NULL,'invalid',NULL,'2026-10-31 08:00:00');
INSERT INTO MYMAR.dossiers (id,client_id,status_id,technicien_id,mar_type,created_at,updated_at) VALUES
 (20,1,14,5,1,'2026-10-31 08:00:00','2026-10-31 08:00:00'),
 (21,2,1,5,1,'2026-10-31 08:00:00','2026-10-31 08:00:00'),
 (22,1,3,6,1,'2026-10-31 08:00:00','2026-10-31 08:00:00'),
 (23,1,16,5,1,'2026-10-31 08:00:00','2026-10-31 08:00:00'),
 (24,1,14,NULL,1,'2026-10-31 08:00:00','2026-10-31 08:00:00'),
 (25,1,1,NULL,1,'2026-10-31 08:00:00','2026-10-31 08:00:00'),
 (26,1,14,NULL,1,'2026-10-31 08:00:00','2026-10-31 08:00:00'),
 (27,1,1,NULL,1,'2026-10-31 08:00:00','2026-10-31 08:00:00'),
 (28,1,14,NULL,1,'2026-10-31 08:00:00','2026-10-31 08:00:00');
INSERT INTO MYMAR.dossier_rdv (id,dossier_id,author_id,date_rdv,duree,is_old) VALUES
 (1,20,5,'2026-10-01 10:00:00',1,0),(2,21,5,'2026-10-01 12:00:00',2,0),
 (3,22,6,'2026-11-02 10:40:00',1,0),(4,23,5,'2026-11-04 10:00:00',2,0);
