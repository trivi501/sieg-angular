const mammoth = require('mammoth');
const puppeteer = require('puppeteer-core');
const fs = require('fs');
(async () => {
  const [entrada, salida] = process.argv.slice(2);
  const r = await mammoth.convertToHtml({ path: entrada });
  if (r.messages.length) console.log([...new Set(r.messages.map((m) => m.message))].slice(0, 8).join('\n'));
  const html = `<html><head><meta charset="utf-8"><style>body{font-family:Calibri,Arial;max-width:820px;margin:20px auto;font-size:14px}img{max-width:100%;border:1px solid #ccc}table{border-collapse:collapse}td,th{border:1px solid #ccc;padding:4px;font-size:12px}h1{color:#7a1f3d}</style></head><body>${r.value}</body></html>`;
  fs.writeFileSync(salida + '.html', html);
  const b = await puppeteer.launch({ executablePath: process.env.CHROME ?? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true, defaultViewport: { width: 900, height: 1200 } });
  const p = await b.newPage();
  await p.goto('file://' + salida + '.html');
  const alto = await p.evaluate(() => document.body.scrollHeight);
  for (let i = 0, y = 0; y < alto && i < 60; i++, y += 3000) {
    await p.screenshot({ path: `${salida}-${String(i).padStart(2, '0')}.png`, clip: { x: 0, y, width: 900, height: Math.min(3000, alto - y) } });
  }
  console.log('alto', alto);
  await b.close();
})();
