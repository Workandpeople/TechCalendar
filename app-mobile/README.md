# Tech Calendar Mobile

Application React Native réservée aux techniciens. Elle expose uniquement le login mobile et la page planning terrain.

## Lancement

```bash
cd app-mobile
./start.sh ios
```

Android:

```bash
cd app-mobile
./start.sh android
```

Par défaut, le script utilise `https://techcalendar.lucas-dinnichert.fr/api` comme API par défaut. Pour cibler un autre backend:

```bash
TECHCALENDAR_API_URL=https://techcalendar.lucas-dinnichert.fr/api ./start.sh ios
```

Options iOS utiles:

```bash
IOS_UDID="00008130-..." ./start.sh ios
IOS_DEVICE="Nom de l'iPhone" ./start.sh ios
IOS_SIMULATOR="iPhone 16" ./start.sh ios
```

Sans variable iOS, le script ouvre la sélection des devices disponibles via React Native.

## Architecture

- `src/api`: client HTTP JSON vers `/api/mobile/*`
- `src/storage`: stockage sécurisé du bearer token via Keychain
- `src/types`: contrats JSON partagés par les écrans
- `App.tsx`: login + planning, volontairement sans navigation complexe pour cette V1

## Backend requis

Endpoints Laravel:

- `POST /api/mobile/login`
- `GET /api/mobile/me`
- `GET /api/mobile/planning`
- `POST /api/mobile/logout`

Seuls les utilisateurs `role = 2` et `admin = false` peuvent ouvrir une session mobile.
