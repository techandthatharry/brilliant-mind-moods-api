/**
 * One-time migration: Firestore → Laravel MySQL
 *
 * Prerequisites:
 *   1. Save your Firebase service account key as firebase-service-account.json
 *      in this directory (Project Settings → Service Accounts → Generate new key)
 *   2. Make sure node dev-proxy.cjs is running in another terminal
 *   3. Run:  node migrate-from-firestore.cjs
 */

const admin  = require('firebase-admin');
const https  = require('https');
const http   = require('http');
const path   = require('path');

// ── config ────────────────────────────────────────────────────────────────────

const SERVICE_ACCOUNT_PATH = path.join(__dirname, 'firebase-service-account.json');
const USER_EMAIL            = 'harry@techandthat.com';
const API_BASE              = 'http://localhost:8080/api';

// ── bootstrap firebase admin ──────────────────────────────────────────────────

let serviceAccount;
try {
  serviceAccount = require(SERVICE_ACCOUNT_PATH);
} catch {
  console.error('❌  firebase-service-account.json not found.');
  console.error('    Download it from Firebase Console → Project Settings → Service Accounts');
  process.exit(1);
}

admin.initializeApp({ credential: admin.credential.cert(serviceAccount) });
const db = admin.firestore();

// ── tiny http helper (no extra deps needed) ───────────────────────────────────

function apiRequest(method, path, body, token) {
  return new Promise((resolve, reject) => {
    const payload = body ? JSON.stringify(body) : null;
    const options = {
      hostname: 'localhost',
      port: 8080,
      path: `/api${path}`,
      method,
      headers: {
        'Content-Type':  'application/json',
        'Accept':        'application/json',
        ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
        ...(payload ? { 'Content-Length': Buffer.byteLength(payload) } : {}),
      },
    };

    const req = http.request(options, (res) => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => {
        try {
          resolve({ status: res.statusCode, body: JSON.parse(data) });
        } catch {
          resolve({ status: res.statusCode, body: data });
        }
      });
    });

    req.on('error', reject);
    if (payload) req.write(payload);
    req.end();
  });
}

// ── score conversion ──────────────────────────────────────────────────────────

// Old Firestore entries store behaviour scores as −5…+5 floats.
// Laravel expects the 0–8 legacy scale.
function toLegacyScore(val) {
  const v     = (val == null ? 0 : Number(val));
  const norm  = Math.min(1, Math.max(0, (v + 5) / 10));
  return Math.round(norm * 8);
}

// ── migration steps ───────────────────────────────────────────────────────────

async function getDevToken() {
  console.log('🔑  Getting Laravel dev token…');
  const res = await apiRequest('POST', '/auth/dev-login', {}, null);
  if (!res.body.token) {
    throw new Error(`Dev login failed (${res.status}): ${JSON.stringify(res.body)}`);
  }
  console.log(`    ✅  Authenticated as ${res.body.user?.email ?? 'dev user'}`);
  return res.body.token;
}

async function getFirebaseUid() {
  console.log(`\n🔍  Looking up Firebase UID for ${USER_EMAIL}…`);
  const user = await admin.auth().getUserByEmail(USER_EMAIL);
  console.log(`    ✅  UID: ${user.uid}`);
  return user.uid;
}

async function migrateMoodEntries(uid, token) {
  console.log('\n📋  Migrating mood entries…');

  const snap = await db.collection('mood_entries')
    .where('userId', '==', uid)
    .get();

  if (snap.empty) {
    console.log('    No mood entries found in Firestore — skipping.');
    return;
  }

  console.log(`    Found ${snap.docs.length} entries…`);

  let ok = 0, skipped = 0, failed = 0;

  for (const doc of snap.docs) {
    const d = doc.data();

    // Must have a timestamp to derive entry_date
    if (!d.timestamp) { skipped++; continue; }

    const date      = d.timestamp.toDate();
    const entryDate = date.toISOString().slice(0, 10); // yyyy-MM-dd

    const payload = {
      score:             Number(d.score ?? 0),
      sleep_score:       toLegacyScore(d.sleepScore),
      appetite_score:    toLegacyScore(d.appetiteScore),
      activity_score:    toLegacyScore(d.activityScore),
      interests_score:   toLegacyScore(d.interestsScore),
      social_score:      toLegacyScore(d.socialScore),
      focus_score:       toLegacyScore(d.focusScore),
      diary:             d.diary ?? '',
      medication_unchanged: d.medicationUnchanged ?? true,
      entry_date:        entryDate,
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

  console.log(`\n    ✅  ${ok} imported, ${skipped} skipped (no timestamp), ${failed} failed.`);
}

async function migrateMedications(uid, token) {
  console.log('\n💊  Migrating medications…');

  // Skip if Laravel already has medications (avoid duplicates on re-run)
  const existing = await apiRequest('GET', '/medications', null, token);
  if (Array.isArray(existing.body) && existing.body.length > 0) {
    console.log(`    Laravel already has ${existing.body.length} medication(s) — skipping.`);
    return;
  }

  const snap = await db.collection('medications')
    .where('userId', '==', uid)
    .get();

  if (snap.empty) {
    console.log('    No medications found in Firestore — skipping.');
    return;
  }

  console.log(`    Found ${snap.docs.length} medication(s)…`);

  let ok = 0, failed = 0;
  for (const doc of snap.docs) {
    const d = doc.data();
    const res = await apiRequest('POST', '/medications', {
      name:   d.name   ?? 'Unknown',
      dosage: d.dosage ?? '',
    }, token);
    if (res.status === 200 || res.status === 201) { ok++; }
    else {
      failed++;
      console.error(`    ⚠️  ${d.name} → HTTP ${res.status}: ${JSON.stringify(res.body)}`);
    }
  }

  console.log(`    ✅  ${ok} imported, ${failed} failed.`);
}

async function migrateSupportContacts(uid, token) {
  console.log('\n👥  Migrating support contacts…');

  const existing = await apiRequest('GET', '/support-contacts', null, token);
  if (Array.isArray(existing.body) && existing.body.length > 0) {
    console.log(`    Laravel already has ${existing.body.length} contact(s) — skipping.`);
    return;
  }

  const snap = await db.collection('support_network')
    .where('userId', '==', uid)
    .get();

  if (snap.empty) {
    console.log('    No support contacts found in Firestore — skipping.');
    return;
  }

  console.log(`    Found ${snap.docs.length} contact(s)…`);

  let ok = 0, failed = 0;
  for (const doc of snap.docs) {
    const d = doc.data();
    const res = await apiRequest('POST', '/support-contacts', {
      name:          d.name          ?? 'Unknown',
      phone:         d.phone         ?? '',
      is_aware:      d.isAware       ?? d.is_aware       ?? false,
      share_reports: d.shareReports  ?? d.share_reports  ?? false,
    }, token);
    if (res.status === 200 || res.status === 201) { ok++; }
    else {
      failed++;
      console.error(`    ⚠️  ${d.name} → HTTP ${res.status}: ${JSON.stringify(res.body)}`);
    }
  }

  console.log(`    ✅  ${ok} imported, ${failed} failed.`);
}

// ── main ──────────────────────────────────────────────────────────────────────

async function main() {
  console.log('═══════════════════════════════════════════');
  console.log('  Firestore → Laravel migration');
  console.log('═══════════════════════════════════════════');

  const token = await getDevToken();
  const uid   = await getFirebaseUid();

  await migrateMoodEntries(uid, token);
  await migrateMedications(uid, token);
  await migrateSupportContacts(uid, token);

  console.log('\n🎉  Migration complete!');
  process.exit(0);
}

main().catch(err => {
  console.error('\n❌  Fatal error:', err.message ?? err);
  process.exit(1);
});
