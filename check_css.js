const fs = require('fs');
const content = fs.readFileSync('public/build/assets/app-DrQvebzw.css', 'utf8');
const checks = ['.md\\:hidden{', '.md\\:block{', '.lg\\:static{', '.sm\\:grid-cols-6{', '.sm\\:grid-cols-5{'];
for (const c of checks) {
  console.log(c, '=>', content.includes(c) ? 'FOUND' : 'MISSING');
}
console.log('total length:', content.length);
