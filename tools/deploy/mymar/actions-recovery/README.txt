Reprise ciblee du pilote MAR apres refus du format de configuration client.

La configuration privee conserve les empreintes d'identite. Le client applicatif
ne recoit que les deux identifiants numeriques approuves. Aucun assouplissement
du validateur runtime et aucun changement de correspondance.

Le paquet exige la revision runtime et les empreintes du premier paquet. Il
revalide les comptes, sources, privileges, journaux vides et ecritures fermees.
La configuration initiale est sauvegardee en prive, puis corrigee atomiquement.

Les controles applicatifs et HTTP utilisent seulement GET. Une disponibilite
inconnue interrompt la reprise. Les ecritures ne sont ouvertes qu'en dernier.
Aucun pull, migration, seeder, maintenance ou redemarrage des services Cofrac.
Aucun placement, probleme RDV, email ou SMS de test.

En cas d'echec, les ecritures sont fermees et le compte SQL reverrouille. Le
format corrige et les reservations sont conserves ; ne pas restaurer la base.
Le stop.sh indique ferme les seules actions MAR, sans toucher aux lecteurs.

Sur le Mac : bash ./fetch-recovery.sh
Cette commande active reellement le pilote si tous les controles passent.
