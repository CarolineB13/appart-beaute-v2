APPART & BEAUTÉ — PATCH SEO TECHNIQUE + IONOS
30/09/2026

Ce dossier est prévu pour être copié à la racine du projet Astro en conservant l'arborescence.

MODIFICATIONS
- astro.config.mjs : URL de production www + trailing slash.
- BaseLayout.astro : canonical, Open Graph, Twitter Card, robots, JSON-LD BeautySalon/WebSite/WebPage, preconnect Google Fonts.
- index.astro : préchargement des deux variantes du fond hero selon la taille d'écran.
- pages prestations : priorité de chargement donnée à l'image hero (LCP).
- Header.astro + MobileMenu.astro : dimensions explicites du logo pour limiter le CLS.
- robots.txt : autorise le site, exclut /api/, déclare le sitemap.
- sitemap.xml : liste les 11 URL actuelles de la V2.
- .htaccess : redirections 301 des anciennes URL Google + compression/cache IONOS.
- site.webmanifest : manifeste remis en JSON valide et harmonisé.
- hero-pampas-desktop.webp : même visuel, 1,5 Mo -> ~99 Ko.
- hero-pampas-mobile.webp : même visuel, 1,3 Mo -> ~70 Ko.

REDIRECTIONS 301 PRÉPARÉES
/tarifs.html -> /reservation/
/tarifs/tarifs.html -> /reservation/
/contact.html -> /contact/
/prestations.html -> /prestations/
/prestations/prestations.html -> /prestations/

IMPORTANT
Le bloc qui force HTTPS + www est volontairement COMMENTÉ dans .htaccess tant que Marjorie n'a pas validé la bascule définitive.
Ne pas l'activer sur une URL de test IONOS, sinon elle redirigerait vers le domaine de production.

APRÈS COPIE
1. npm run build
2. Vérifier que le build se termine sans erreur.
3. npm run dev puis vérifier rapidement accueil + pages Lash Lift/Browlift.
4. Ne pas envoyer en production avant validation de Marjorie.

NOTE DE VALIDATION
Le build n'a pas pu être exécuté dans l'environnement de préparation car le node_modules inclus dans le ZIP provenait de Windows et ne contenait pas le binding natif Linux requis. Les fichiers robots.txt, sitemap.xml et site.webmanifest ont en revanche été validés syntaxiquement. Le projet avait déjà compilé côté Windows avant cette passe ; refaire npm run build localement est la validation finale.
