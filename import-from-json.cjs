/**
 * Imports firestore-export.json into Laravel.
 *
 * Prerequisites:
 *   1. firestore-export.json must be in your Downloads folder (or this directory)
 *   2. node dev-proxy.cjs must be running
 *
 * Run:  node import-from-json.cjs
 */

const http = require('http');
const fs   = require('fs');
const path = require('path');
const os   = require('os');

// ── find the export file ──────────────────────────────────────────────────────

const candidates = [
  path.join(__dirname,           'firestore-export.json'),
  path.join(os.homedir(), 'Downloads', 'firestore-export.json'),
  path.join(os.homedir(), 'Desktop',   'firestore-export.json'),
];

let exportPath = null;
for (const p of candidates) {
  if (fs.existsSync(p)) { exportPath = p; break; }
}

if (!exportPath) {
  console.error('❌  Could not find firestore-export.json');
  console.error('    Checked:', candidates.join('\n    '));
  process.exit(1);
}

console.log('📂  Using export file:', exportPath);
const data = JSON.parse(fs.readFileSync(exportPath, 'utf8'));

// ── http helper ───────────────────────────────────────────────────────────────

function apiRequest(method, urlPath, body, token) {
  return new Promise((resolve, reject) => {
    const payload = body ? JSON.stringify(body) : null;
    const options = {
      hostname: 'localhost',
      port: 8080,
      path: `/api${urlPath}`,
      method,
      headers: {
        'Content-Type':  'application/json',
        'Accept':        'application/json',
        ...(token  ? { 'Authorization': `Bearer ${token}` } : {}),
        ...(payload ? { 'Content-Length': Buffer.byteLength(payload) } : {}),
      },
    };
    const req = http.request(options, (res) => {
      let raw = '';
      res.on('data', chunk => raw += chunk);
      res.on('end', () => {
        try { resolve({ status: res.statusCode, body: JSON.parse(raw) }); }
        catch { resolve({ status: res.statusCode, body: raw }); }
      });
    });
    req.on('error', reject);
    if (payload) req.write(payload);
    req.end();
  });
}

// ── score conversion ──────────────────────────────────────────────────────────

// Firestore stored behaviour scores as −5…+5. Laravel uses 0–8.
function toLegacyScore(val) {
  const v    = (val == null ? 0 : Number(val));
  const norm = Math.min(1, Math.max(0, (v + 5) / 10));
  return Math.round(norm * 8);
}

// ── main ──────────────────────────────────────────────────────────────────────

async function main() {
  console.log('\n═══════════════════════════════════════════');
  console.log('  Firestore JSON → Laravel import');
  console.log('═══════════════════════════════════════════\n');

  // 1. Dev login
  console.log('🔑  Authenticating with Laravel…');
  const authRes = await apiRequest('POST', '/auth/dev-login', {}, null);
  if (!authRes.body.token) {
    console.error('❌  Dev login failed:', JSON.stringify(authRes.body));
    console.error('    Is node dev-proxy.cjs running?');
    process.exit(1);
  }
  const token = authRes.body.token;
  console.log(`    ✅  Authenticated as ${authRes.body.user?.email ?? 'dev user'}\n`);

  // 2. Mood entries
  const entries = data.moodEntries ?? [];
  console.log(`📋  Importing ${entries.length} mood entries…`);

  // Sort by timestamp ascending so earlier dates are upserted first
  entries.sort((a, b) => {
    const aMs = a.timestamp?._seconds ?? a.timestamp?.seconds ?? 0;
    const bMs = b.timestamp?._seconds ?? b.timestamp?.seconds ?? 0;
    return aMs - bMs;
  });

  let ok = 0, skipped = 0, failed = 0;
  for (const doc of entries) {
    const ts = doc.timestamp;
    if (!ts) { skipped++; continue; }

    // Firestore Timestamp serialises as { _seconds, _nanoseconds } or { seconds, nanoseconds }
    const seconds  = ts._seconds ?? ts.seconds ?? null;
    if (seconds == null) { skipped++; continue; }

    const date      = new Date(seconds * 1000);
    const entryDate = date.toISOString().slice(0, 10);

    const payload = {
      score:               Number(doc.score ?? 0),
      sleep_score:         toLegacyScore(doc.sleepScore),
      appetite_score:      toLegacyScore(doc.appetiteScore),
      activity_score:      toLegacyScore(doc.activityScore),
      interests_score:     toLegacyScore(doc.interestsScore),
      social_score:        toLegacyScore(doc.socialScore),
      focus_score:         toLegacyScore(doc.focusScore),
      diary:               doc.diary ?? '',
      medication_unchanged: doc.medicationUnchanged ?? true,
      entry_date:          entryDate,
    };

    const res = await apiRequest('POST', '/mood-entries', payload, token);
    if (res.status === 200 || res.status === 201) {
      ok++;
      process.stdout.write('.');
    } else {
      failed++;
      console.error(`\n    ⚠️  ${entryDate} → HTTP ${res.status}: ${JSON.stringify(res.body)}`);
    }
  }
  console.log(`\n    ✅  ${ok} imported, ${skipped} skipped, ${failed} failed.\n`);

  // 3. Medications
  const meds = data.medications ?? [];
  console.log(`💊  Importing ${meds.length} medication(s)…`);
  if (meds.length > 0) {
    const existing = await apiRequest('GET', '/medications', null, token);
    if (Array.isArray(existing.body) && existing.body.length > 0) {
      console.log(`    Laravel already has ${existing.body.length} medication(s) — skipping.\n`);
    } else {
      let mOk = 0, mFail = 0;
      for (const doc of meds) {
        const res = await apiRequest('POST', '/medications', {
          name:   doc.name   ?? 'Unknown',
          dosage: doc.dosage ?? '',
        }, token);
        if (res.status === 200 || res.status === 201) mOk++;
        else {
          mFail++;
          console.error(`    ⚠️  ${doc.name} → HTTP ${res.status}: ${JSON.stringify(res.body)}`);
        }
      }
      console.log(`    ✅  ${mOk} imported, ${mFail} failed.\n`);
    }
  } else {
    console.log('    Nothing to import.\n');
  }

  // 4. Support contacts
  const contacts = data.supportContacts ?? [];
  console.log(`👥  Importing ${contacts.length} support contact(s)…`);
  if (contacts.length > 0) {
    const existing = await apiRequest('GET', '/support-contacts', null, token);
    if (Array.isArray(existing.body) && existing.body.length > 0) {
      console.log(`    Laravel already has ${existing.body.length} contact(s) — skipping.\n`);
    } else {
      let cOk = 0, cFail = 0;
      for (const doc of contacts) {
        const res = await apiRequest('POST', '/support-contacts', {
          name:          doc.name         ?? 'Unknown',
          phone:         doc.phone        ?? '',
          is_aware:      doc.isAware      ?? doc.is_aware      ?? false,
          share_reports: doc.shareReports ?? doc.share_reports ?? false,
        }, token);
        if (res.status === 200 || res.status === 201) cOk++;
        else {
          cFail++;
          console.error(`    ⚠️  ${doc.name} → HTTP ${res.status}: ${JSON.stringify(res.body)}`);
        }
      }
      console.log(`    ✅  ${cOk} imported, ${cFail} failed.\n`);
    }
  } else {
    console.log('    Nothing to import.\n');
  }

  console.log('🎉  All done! Open the app and check Mood Trends.');
  process.exit(0);
}

main().catch(err => {
  console.error('\n❌  Fatal:', err.message ?? err);
  process.exit(1);
});
