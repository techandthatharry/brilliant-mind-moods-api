/**
 * Dev proxy — forwards requests from localhost:8080 to Herd's nginx on
 * localhost:80 with the correct Host header so nginx routes to the
 * brilliant_mind_moods_api.test site.
 *
 * Usage:
 *   node dev-proxy.js
 *
 * In a separate terminal run once per emulator session:
 *   adb -s emulator-5554 reverse tcp:8080 tcp:8080
 *
 * Flutter then calls http://localhost:8080/api which the emulator forwards
 * to this proxy, which forwards to Herd with the right Host header.
 */

const http = require('http');

const PROXY_PORT = 8080;
const HERD_HOST = '127.0.0.1';
const HERD_PORT = 80;
const SITE_HOST = 'brilliant_mind_moods_api.test';

const server = http.createServer((clientReq, clientRes) => {
  const options = {
    hostname: HERD_HOST,
    port: HERD_PORT,
    path: clientReq.url,
    method: clientReq.method,
    headers: {
      ...clientReq.headers,
      host: SITE_HOST,
    },
  };

  const proxy = http.request(options, (res) => {
    clientRes.writeHead(res.statusCode, res.headers);
    res.pipe(clientRes, { end: true });
  });

  clientReq.pipe(proxy, { end: true });

  proxy.on('error', (err) => {
    console.error('Proxy error:', err.message);
    clientRes.writeHead(502);
    clientRes.end(JSON.stringify({ error: 'Proxy error', detail: err.message }));
  });
});

server.listen(PROXY_PORT, '127.0.0.1', () => {
  console.log(`Dev proxy running on http://127.0.0.1:${PROXY_PORT}`);
  console.log(`Forwarding to http://${SITE_HOST} via Herd`);
  console.log(`\nRun this once in another terminal:`);
  console.log(`  adb -s emulator-5554 reverse tcp:${PROXY_PORT} tcp:${PROXY_PORT}`);
});
