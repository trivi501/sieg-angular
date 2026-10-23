import packageInfo from '../../package.json';

export const environment = {
  appVersion: packageInfo.version,
  production: true,
  // Ajustar al dominio del backend en producción.
  apiUrl: '/api'
};
