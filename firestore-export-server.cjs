/**
 * Serves a Firestore export page on http://localhost:3000
 * Open that URL in Chrome, sign in with Google, click Export.
 * The page downloads firestore-export.json to your Downloads folder.
 * Then run:  node import-from-json.cjs  to push it into Laravel.
 *
 * Run:  node firestore-export-server.cjs
 */

const http = require('http');

const HTML = `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Firestore Export</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 600px; margin: 60px auto; padding: 0 20px; background: #f8f9fa; color: #2C3E50; }
    h1   { font-size: 1.4rem; margin-bottom: 4px; }
    p    { color: #666; margin-top: 4px; }
    button { padding: 12px 24px; font-size: 1rem; border: none; border-radius: 8px; cursor: pointer; margin-top: 16px; }
    #btn-signin  { background: #2C3E50; color: white; }
    #btn-export  { background: #27ae60; color: white; display: none; }
    #log { margin-top: 24px; background: #1a1a2e; color: #a8e6cf; padding: 16px; border-radius: 8px; font-family: monospace; font-size: 0.85rem; white-space: pre-wrap; min-height: 80px; display: none; }
    #user-info { margin-top: 12px; font-size: 0.9rem; color: #27ae60; display: none; }
  </style>
</head>
<body>
  <h1>🔥 Firestore → Laravel Export</h1>
  <p>Sign in with your Google account, then export your data as JSON.</p>

  <button id="btn-signin">Sign in with Google</button>
  <button id="btn-signout" style="background:#c0392b;color:white;display:none">Sign out (wrong account)</button>
  <div id="user-info"></div>
  <button id="btn-export">Export all data as JSON</button>
  <div id="log"></div>

  <!-- Firebase compat SDK (no bundler needed) -->
  <script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js"></script>
  <script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-auth-compat.js"></script>
  <script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-firestore-compat.js"></script>

  <script>
    firebase.initializeApp({
      apiKey:     "AIzaSyAedpEXTmbcMeOkKwLYIbldUF2LxOUPwy0",
      authDomain: "brilliant-mind-moods.firebaseapp.com",
      projectId:  "brilliant-mind-moods",
    });

    const auth      = firebase.auth();
    const db        = firebase.firestore();
    const btnSignIn = document.getElementById('btn-signin');
    const btnExport = document.getElementById('btn-export');
    const userInfo  = document.getElementById('user-info');
    const logEl     = document.getElementById('log');

    function log(msg) {
      logEl.style.display = 'block';
      logEl.textContent += msg + '\\n';
    }

    // UID seen in Firestore Console — export only works if you sign in as this account
    const EXPECTED_UID = 'vCZPA1yGjRcHQtcaJgf3dru1rY52';

    auth.onAuthStateChanged(user => {
      if (user) {
        btnSignIn.style.display = 'none';
        btnExport.style.display = 'inline-block';
        userInfo.style.display  = 'block';
        const match = user.uid === EXPECTED_UID;
        userInfo.innerHTML =
          (match ? '✅' : '⚠️') + ' Signed in as <strong>' + user.email + '</strong><br>' +
          'Your UID:      <code>' + user.uid + '</code><br>' +
          'Expected UID:  <code>' + EXPECTED_UID + '</code><br>' +
          (match
            ? '<span style="color:#27ae60">✅ UID matches — ready to export</span>'
            : '<span style="color:#e74c3c">⚠️ UID mismatch — sign out and sign in with the correct Google account</span>');
        if (!match) {
          btnExport.style.display = 'none';
          document.getElementById('btn-signout').style.display = 'inline-block';
        }
      } else {
        btnSignIn.style.display   = 'inline-block';
        btnExport.style.display   = 'none';
        userInfo.style.display    = 'none';
        document.getElementById('btn-signout').style.display = 'none';
      }
    });

    btnSignIn.addEventListener('click', () => {
      const provider = new firebase.auth.GoogleAuthProvider();
      auth.signInWithPopup(provider).catch(err => alert('Sign-in failed: ' + err.message));
    });

    document.getElementById('btn-signout').addEventListener('click', () => {
      auth.signOut();
    });

    async function readCollection(name, uid) {
      // Try with userId filter first
      let snap = await db.collection(name)
        .where('userId', '==', uid)
        .get();

      if (snap.empty) {
        // Fall back: read the whole collection and filter client-side.
        // Useful if documents were stored without a userId field.
        log('  ' + name + ': 0 with userId filter — trying full read…');
        snap = await db.collection(name).get();
        log('  ' + name + ': ' + snap.docs.length + ' total doc(s) without filter');
        // Keep all docs — if security rules limited the read that's fine
        return snap.docs.map(d => ({ id: d.id, ...d.data() }));
      }

      log('  ' + name + ': ' + snap.docs.length + ' document(s) (userId matched)');
      return snap.docs.map(d => ({ id: d.id, ...d.data() }));
    }

    btnExport.addEventListener('click', async () => {
      btnExport.disabled    = true;
      btnExport.textContent = 'Exporting…';
      logEl.textContent     = '';
      const uid = auth.currentUser.uid;
      log('Signed in as: ' + auth.currentUser.email);
      log('Firebase UID: ' + uid);
      log('');

      try {
        const moodEntries     = await readCollection('mood_entries',    uid);
        const medications     = await readCollection('medications',     uid);
        const supportContacts = await readCollection('support_network', uid);

        const payload = { moodEntries, medications, supportContacts };

        const json = JSON.stringify(payload, null, 2);
        const blob = new Blob([json], { type: 'application/json' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = 'firestore-export.json';
        a.click();
        URL.revokeObjectURL(url);

        log('\\n✅ Done! firestore-export.json downloaded.');
        log('Now run:  node import-from-json.cjs');
      } catch (e) {
        log('❌ Error: ' + e.message);
      } finally {
        btnExport.disabled    = false;
        btnExport.textContent = 'Export all data as JSON';
      }
    });
  </script>
</body>
</html>`;

const server = http.createServer((req, res) => {
  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
  res.end(HTML);
});

server.listen(3000, '127.0.0.1', () => {
  console.log('');
  console.log('  Firestore export server running.');
  console.log('  Open  http://localhost:3000  in Chrome.');
  console.log('  Sign in with Google, click Export, save the JSON.');
  console.log('  Then Ctrl+C here and run:  node import-from-json.cjs');
  console.log('');
});
