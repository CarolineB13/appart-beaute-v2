// @ts-check
import { defineConfig } from 'astro/config';

export default defineConfig({
  // URL canonique de production. Le site reste généré en statique pour IONOS.
  site: 'https://www.appartbeauteinstitut.com',
  trailingSlash: 'always',
});
