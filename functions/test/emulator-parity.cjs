// Run only against the explicit demo emulators; never infer a live project.
const { test, before } = require('node:test');
const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
process.env.FIRESTORE_EMULATOR_HOST = '127.0.0.1:18080';
process.env.FIREBASE_AUTH_EMULATOR_HOST = '127.0.0.1:19099';
const { initializeApp } = require('firebase-admin/app');
const { getFirestore, Timestamp } = require('firebase-admin/firestore');
initializeApp({ projectId: 'demo-peanut-suite' });
const db = getFirestore();
const origin = 'http://127.0.0.1:15001/demo-peanut-suite/us-central1/api/api/v1';
let token, outsider, bookingId;
async function identity() {
  const response = await fetch('http://127.0.0.1:19099/identitytoolkit.googleapis.com/v1/accounts:signUp?key=demo-key', {
    method: 'POST', headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ email: `parity-${randomUUID()}@example.invalid`, password: randomUUID(), returnSecureToken: true }),
  });
  assert.equal(response.status, 200);
  return response.json();
}
async function patch(bearer) {
  return fetch(`${origin}/booking/${bookingId}`, { method: 'PATCH', headers: { 'content-type': 'application/json', authorization: `Bearer ${bearer}` }, body: JSON.stringify({ booking_status: 'confirmed' }) });
}
before(async () => {
  const party = await identity(); const foreign = await identity();
  token = party.idToken; outsider = foreign.idToken; bookingId = randomUUID();
  for (const [uid, id] of [[party.localId, 'parity-party'], [foreign.localId, 'parity-outsider']]) {
    await db.collection('accounts_by_uid').doc(uid).set({ peanut_id: id });
    await db.collection('accounts').doc(id).set({ peanut_id: id, firebase_uid: uid });
  }
  await db.collection('bookings').doc(bookingId).set({ performer_peanut_id: 'parity-party', customer_peanut_id: 'parity-customer', booking_status: 'pending', updated_at: Timestamp.now() });
});
test('real Functions emulator serves the candidate API', async () => {
  const response = await fetch(`${origin}/health`); assert.equal(response.status, 200); assert.equal((await response.json()).service, 'peanut-suite-api');
});
test('real Auth verification and Firestore ownership deny unauthorized writes', async () => {
  assert.equal((await patch('invalid-token')).status, 401);
  assert.equal((await patch(outsider)).status, 403);
  assert.equal((await db.collection('bookings').doc(bookingId).get()).data().booking_status, 'pending');
});
test('verified involved party updates real emulator booking', async () => {
  assert.equal((await patch(token)).status, 200);
  assert.equal((await db.collection('bookings').doc(bookingId).get()).data().booking_status, 'confirmed');
});
test('modular SDK account and public directory reads remain available', async () => {
  for (const route of ['account/parity-party', 'performer', 'venue']) {
    const response = await fetch(`${origin}/${route}`, { headers: { authorization: `Bearer ${token}` } });
    assert.equal(response.status, 200, `route ${route}`);
  }
});
