// Salin aset statis (favicon) ke folder build yang disajikan .htaccess di /account/assets/.
const fs = require('fs');
const path = require('path');

const out = path.resolve(__dirname, '../../../public_html/account/assets');
fs.mkdirSync(out, { recursive: true });
fs.copyFileSync(require.resolve('@jdc/ui/logo.svg'), path.join(out, 'favicon.svg'));
console.log('aset account disalin ke', out);
