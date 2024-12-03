#!/bin/bash

# Démarre les conteneurs Docker
./vendor/bin/sail down
./vendor/bin/sail up -d

# Lance npm run dev dans le conteneur
./vendor/bin/sail npm run dev