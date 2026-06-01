# Laravel Template

Template Laravel 13 prêt à servir de base pour de nouveaux projets SaaS, back-offices et applications métier.

L'objectif n'est pas d'avoir un starter "démo", mais une base de travail propre, reproductible et cohérente avec un environnement Docker/Sail orienté développement quotidien.

## Ce que contient le template

- Laravel 13
- PHP 8.3 dans l'image Sail
- MySQL 8.4
- Vite 8 + Tailwind CSS 4
- Laravel Reverb
- Laravel Pail
- Pest pour les tests
- Docker/Sail avec `supervisord`

## Philosophie

- `vendor` reste sur le host pour conserver le workflow natif `./vendor/bin/sail`
- `node_modules` vit uniquement dans le conteneur et est monté en `tmpfs`
- `sail down` supprime donc entièrement `node_modules`
- l'image applicative est buildée localement via `pull_policy: build`
- l'environnement de dev lance plusieurs processus en parallèle dans le conteneur principal

## Services Docker

### `laravel.test`

Conteneur applicatif principal. Il expose :

- `80` pour l'application Laravel
- `5173` pour le serveur Vite
- `8080` pour Reverb

Le projet est monté sur `/var/www/html`.

`node_modules` est monté en `tmpfs`, donc non persistant. Au démarrage du conteneur, le script [docker/8.5/start-container](/Users/dedinnich/Projets/LaravelTemplate/docker/8.5/start-container:1) recrée le répertoire et lance automatiquement `npm ci` si nécessaire.

### `mysql`

Base MySQL 8.4 avec volume persistant `sail-mysql`.

## Supervisord

Le conteneur principal démarre `supervisord` via [docker/8.5/start-container](/Users/dedinnich/Projets/LaravelTemplate/docker/8.5/start-container:41), avec la configuration [docker/8.5/supervisord.conf](/Users/dedinnich/Projets/LaravelTemplate/docker/8.5/supervisord.conf:1).

Programmes lancés :

### `php`

Processus HTTP principal Laravel. La commande réelle vient de `SUPERVISOR_PHP_COMMAND` dans le Dockerfile et lance :

```bash
php artisan serve --host=0.0.0.0 --port=80
```

### `reverb`

Lance le serveur websocket Laravel Reverb sur le port `8080`.

Commande :

```bash
php artisan reverb:start --host=0.0.0.0 --port=8080 --no-interaction --no-ansi --verbose
```

Les logs sont écrits dans `storage/logs/reverb.log`.

### `vite`

Lance le serveur de développement frontend :

```bash
npm run dev
```

Le serveur Vite écoute sur le port `5173`.

### `queue-worker`

Lance trois workers en parallèle :

```bash
php artisan queue:work --queue=default --sleep=1 --tries=1 --timeout=1800 --no-interaction --verbose
```

Configuration actuelle :

- `numprocs=3` : trois workers parallèles
- `--sleep=1` : faible latence quand la queue est vide
- `--tries=1` : pas de retry implicite au niveau worker
- `--timeout=1800` : jobs longs autorisés jusqu'à 30 minutes

La commande retenue est valide. La configuration ajoute aussi un arrêt plus propre avec :

- `stopasgroup=true`
- `killasgroup=true`
- `stopwaitsecs=1810`

Sans cela, Supervisor peut tuer trop vite un worker encore occupé sur un job long lors d'un arrêt ou d'un restart. C'est un point important pour garder un comportement prévisible sur ce template.

## Démarrage rapide

### 1. Installer les dépendances PHP

```bash
composer install
```

### 2. Préparer l'environnement

```bash
cp .env.example .env
php artisan key:generate
```

### 3. Lancer les conteneurs

```bash
./vendor/bin/sail up -d --build
```

### 4. Migrer la base

```bash
./vendor/bin/sail artisan migrate
```

## Commandes utiles

```bash
./vendor/bin/sail up -d
./vendor/bin/sail down
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan queue:monitor
./vendor/bin/sail test
./vendor/bin/sail npm run build
```

## Variables d'environnement importantes

Extrait de [.env.example](/Users/dedinnich/Projets/LaravelTemplate/.env.example:1) :

- `APP_TIMEZONE=Europe/Paris`
- `APP_LOCALE=fr`
- `QUEUE_CONNECTION=database`
- `SESSION_DRIVER=database`
- `CACHE_STORE=database`
- `REVERB_PORT=8080`

Le template part volontairement sur des drivers base de données pour `queue`, `session` et `cache`, ce qui simplifie le bootstrap initial. Si un projet a un vrai besoin de débit, il faudra probablement basculer certaines briques sur Redis.

## Points d'attention

- `php artisan serve` est acceptable ici pour un template de dev sous Sail, pas pour une cible de prod
- `queue-worker` ne consomme aujourd'hui que la queue `default`
- il n'y a pas encore de worker dédié au scheduler
- `MAIL_HOST` est prérempli dans `.env.example`, à valider selon les projets
- `node_modules` est reconstruit après un `sail down`, c'est voulu

## Évolutions probables

- ajouter un programme `scheduler` si le prochain projet en dépend
- introduire Redis si la queue, le cache ou le broadcast le justifient
- remplacer `artisan serve` par une vraie stack HTTP dédiée si le template devient plus proche d'une image d'exécution
