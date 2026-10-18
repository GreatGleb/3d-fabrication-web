Local Three.js STLLoader bundle

Rebuild from this directory with: npm ci && npm run build
The committed browser bundle needs no CDN or runtime package installation.
Three.js 0.186.1; MIT license in site/assets/vendor/three-LICENSE.txt.
API reference: https://threejs.org/docs/pages/STLLoader.html

The editable calculator is site/calculator/index.html and site/assets/calculator.js.
Prices use example USD rates set by data-price-per-gram and data-base-setup-fee
on #stl-calculator. Keep the translated tariff hint in site/i18n/ru.json in sync.
Mass is for the selected quantity; the setup fee is added once per order.
STL defaults to mm; cm and inch selections scale dimensions and volume.
Signed tetrahedron volume assumes a closed, consistently oriented mesh;
this is not a watertightness or self-intersection validator.
Run: python3 scripts/build_pages.py --mode demo
Then: python3 scripts/check_stl_pricing.py
