/**
 * @format
 */

import { AppRegistry } from 'react-native';
import App from './App';
import { name as appName } from './app.json';

try {
  const { getApp } = require('@react-native-firebase/app');
  const { getMessaging, setBackgroundMessageHandler } = require('@react-native-firebase/messaging');

  setBackgroundMessageHandler(getMessaging(getApp()), async () => undefined);
} catch {
  // Firebase is optional until the native config files are dropped in the project.
}

AppRegistry.registerComponent(appName, () => App);
