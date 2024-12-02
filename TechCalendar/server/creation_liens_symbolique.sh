#!/bin/bash
# creation_liens_symbolique.sh

# Attendre que les fichiers soient montés (optionnel)
sleep 5

# Créer les liens symboliques
ln -sf /var/www/html/resources/assets /var/www/html/public/assets
ln -sf /var/www/html/resources/css /var/www/html/public/css
ln -sf /var/www/html/resources/fonts /var/www/html/public/fonts
ln -sf /var/www/html/resources/js /var/www/html/public/js

echo "Liens symboliques créés avec succès."