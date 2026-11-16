# Rappels de prise de rendez-vous

## Fonctionnement

Lors d'un probleme RDV natif Cofrac, la date de rappel et son activite source sont maintenant conservees sur la fiche. A l'echeance, seule une fiche encore en probleme, non supprimee, sans nouveau rendez-vous planifie et sans decision ulterieure revient en `PRISE DE RDV`. Elle redevient visible dans les rendez-vous a placer.

La date utilise l'heure de Paris. Sans heure, l'echeance est le debut de journee. Une heure inexistante au printemps est refusee ; une heure repetee en automne designe sa seconde occurrence (heure d'hiver). Le traitement est lance chaque minute : le changement n'est pas garanti a la seconde exacte.

Le commentaire, les documents, l'ancien rendez-vous en probleme et la facturation sont conserves. Une activite privee trace le changement et la version du dossier augmente. Aucun email, SMS, appel externe ou changement MYMAR n'est declenche par ce traitement. Les problemes sans date de rappel ne sont pas rouverts automatiquement.

Une decision d'etat ulterieure desarme le rappel. Le traitement reverifie la fiche et sa derniere decision sous verrou, dans l'ordre dossier puis fiche. Le pointeur d'activite est conserve apres consommation ou annulation : une nouvelle reprise historique ne ressuscite pas un ancien rappel.

## Controle et reprise historique

Les anciens commentaires ne suffisent pas : seules les dates structurees presentes dans une derniere activite V2 `status_changed` de probleme sont reprises. Les historiques ambigus ou incomplets sont ignores. Aucune date n'est deduite du texte libre et aucun dossier n'est rouvert pendant la migration.

Apres le deploiement et la migration `2026_11_16_120000_add_booking_recalls_to_dossier_operations`, depuis `/var/www/cofrac2`, avec le compte applicatif :

```bash
# Diagnostic seul, aucune ecriture metier.
sudo -u cofrac2-app php8.4 artisan dossiers:process-recalls --backfill --limit=2000

# Apres controle des compteurs : arme les anciens rappels, sans changer leur etat.
sudo -u cofrac2-app php8.4 artisan dossiers:process-recalls --backfill --apply --limit=2000

# Diagnostic des rappels echus armes, puis application explicite.
sudo -u cofrac2-app php8.4 artisan dossiers:process-recalls --limit=200
sudo -u cofrac2-app php8.4 artisan dossiers:process-recalls --apply --limit=200
```

Les commandes renvoient uniquement des compteurs, pas d'identite ni de contenu de dossier. Pour parcourir plus de 2000 anciennes fiches, utiliser `--after-id` avec `next_after_id` renvoye et poursuivre tant que `examined` atteint la limite. Le curseur porte sur les fiches examinees, y compris celles ignorees. Relancer le meme lot n'applique pas deux fois un rappel.

## Activation en production

Le serveur de production n'utilise pas le planificateur Laravel general. Ne pas activer un cron `schedule:run` pour ce correctif : cela lancerait aussi d'autres taches. Un declencheur systemd dedie ne lance que cette commande, par lots de 200, avec un compte applicatif non privilegie et sans reseau externe.

Une fois le code et le schema deployes et le site rouvert :

```bash
sudo bash /var/www/cofrac2/tools/deploy/enable-production-booking-recalls.sh --enable
sudo systemctl status cofrac2-booking-recalls.timer --no-pager
sudo journalctl -u cofrac2-booking-recalls.service -n 20 --no-pager
```

L'activation exige un checkout V2 propre, la bonne revision preparee, la base `cofrac2production`, le schema attendu, un site ouvert et l'absence de simulation. Elle ne prepare pas automatiquement les rappels historiques. Pour examiner les rappels echus avant toute execution automatique, effectuer d'abord la reprise et le diagnostic, puis activer le declencheur. Le site marque en maintenance fait sauter l'execution.

Desactivation ciblee, sans annuler les changements deja effectues et sans toucher au site :

```bash
sudo bash /var/www/cofrac2/tools/deploy/enable-production-booking-recalls.sh --disable
```

Hors de ce serveur, la commande est egalement enregistree chaque minute dans le planificateur Laravel quand la V2 est active et hors simulation. Ne pas configurer les deux modes sur un meme serveur ; les verrous et le marqueur de consommation restent une protection contre les doubles executions.
