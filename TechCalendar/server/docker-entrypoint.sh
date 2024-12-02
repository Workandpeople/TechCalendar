#!/bin/bash
set -e

# Exécuter le script de création des liens symboliques
/var/www/html/creation_liens_symbolique.sh

# Démarrer le serveur Apache
exec apache2-foreground