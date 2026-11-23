const fs = require('node:fs');
for (const file of ['sweetalert2.min.js', 'sweetalert2.min.css']) {
  fs.copyFileSync(`node_modules/sweetalert2/dist/${file}`, `public/assets/${file}`);
}
