import { mergeApplicationConfig, ApplicationConfig } from '@angular/core';
import { provideServerRendering, withRoutes } from '@angular/ssr';
import { appConfig } from './app.config';
import { serverRoutes } from './app.routes.server';
import { API_BASE_URL } from './common/service/api.service';

const serverConfig: ApplicationConfig = {
  providers: [
    provideServerRendering(withRoutes(serverRoutes)),
    // On the server there is no CORS and relative URLs have no host to resolve against,
    // so always call the API directly. This overrides the browser value from app.config.
    { provide: API_BASE_URL, useValue: 'https://learnocentric.com' },
  ]
};

export const config = mergeApplicationConfig(appConfig, serverConfig);
