-- Disposable container fixture only; never included in the operator's audit package.
CREATE DATABASE MYMAR;
CREATE DATABASE cofrac2production;
CREATE TABLE MYMAR.status (id bigint PRIMARY KEY, name varchar(190), deleted_at timestamp NULL) ENGINE=InnoDB;
CREATE TABLE MYMAR.roles (id bigint PRIMARY KEY, name varchar(100)) ENGINE=InnoDB;
CREATE TABLE MYMAR.model_has_roles (role_id bigint, model_id bigint, model_type varchar(120)) ENGINE=InnoDB;
CREATE TABLE MYMAR.users (id bigint PRIMARY KEY, name varchar(190), email varchar(190), password varchar(190), enabled int, cofrac_id int) ENGINE=InnoDB;
CREATE TABLE MYMAR.dossiers (id bigint PRIMARY KEY, status_id bigint, technicien_id bigint NULL, auditeur_id bigint NULL, mar_type int) ENGINE=InnoDB;
CREATE TABLE MYMAR.dossier_rdv (id bigint PRIMARY KEY, dossier_id bigint, date_rdv datetime, duree int NULL, is_old int NULL, updated_at timestamp NULL) ENGINE=InnoDB;
CREATE TABLE cofrac2production.users (id bigint PRIMARY KEY, first_name varchar(100), last_name varchar(100), email varchar(190), password varchar(190), role int, deleted_at timestamp NULL, external_client_id bigint NULL) ENGINE=InnoDB;
CREATE TABLE cofrac2production.coffrac_user_links (source_user_id bigint PRIMARY KEY, user_id bigint) ENGINE=InnoDB;
INSERT INTO MYMAR.status VALUES (6,'VISITE INITIALE',NULL),(12,'APRES TRAVAUX',NULL),(20,'ANNULE',NULL);
INSERT INTO MYMAR.roles VALUES (4,'Technicien'),(5,'Gerant');
INSERT INTO MYMAR.model_has_roles VALUES (4,1,CONCAT('App',CHAR(92),'Models',CHAR(92),'User')),(4,2,CONCAT('App',CHAR(92),'Models',CHAR(92),'User')),(4,3,CONCAT('App',CHAR(92),'Models',CHAR(92),'User'));
INSERT INTO MYMAR.users VALUES
 (1,'Technicien Un','one@example.test','CANARY_SOURCE_PASSWORD',1,7),
 (2,'Technicien Deux','two@example.test','CANARY_SOURCE_PASSWORD',0,NULL),
 (3,'Technicien Trois','three@example.test','CANARY_SOURCE_PASSWORD',1,7),
 (9,'Non technicien','private@example.test','CANARY_SOURCE_PASSWORD',1,NULL);
INSERT INTO cofrac2production.users VALUES
 (101,'Tech','Un','ONE@example.test','CANARY_TARGET_PASSWORD',2,NULL,NULL),
 (102,'Tech','Deux','two@example.test','CANARY_TARGET_PASSWORD',2,NULL,NULL),
 (103,'Tech','Ancien',' TWO@example.test ','CANARY_TARGET_PASSWORD',2,'2026-01-01 00:00:00',NULL);
INSERT INTO cofrac2production.coffrac_user_links VALUES (7,101);
INSERT INTO MYMAR.dossiers VALUES (1,6,1,NULL,0),(2,12,2,NULL,1),(3,20,3,NULL,1);
INSERT INTO MYMAR.dossier_rdv VALUES
 (1,1,'2026-10-25 01:30:00',45,0,'2026-10-25 01:00:00'),
 (2,1,'2026-10-24 08:00:00',30,1,'2026-10-25 01:00:00'),
 (3,2,'2026-11-02 10:00:00',NULL,0,'2026-10-25 01:00:00'),
 (4,3,'2026-11-03 10:00:00',0,0,'2026-10-25 01:00:00'),
 (5,999,'2026-11-04 10:00:00',60,NULL,'2026-10-25 01:00:00');
CREATE USER 'audit_reader'@'localhost';
GRANT SELECT ON MYMAR.* TO 'audit_reader'@'localhost';
GRANT SELECT ON cofrac2production.* TO 'audit_reader'@'localhost';
