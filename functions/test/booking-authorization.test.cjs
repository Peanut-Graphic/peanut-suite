const { test, before, after, beforeEach } = require('node:test');
const assert = require('node:assert/strict');
const { createHmac, randomBytes } = require('node:crypto');
const Module = require('node:module');
const express = require('express');
const secret = randomBytes(32).toString('hex');
process.env.WEBHOOK_SECRET = secret;
let writes = [], tokenChecks = [];
let booking = { performer_peanut_id: 'party-a', customer_peanut_id: 'party-b', booking_status: 'pending' };
const db = { collection: name => ({ doc: id => ({
  get: async () => ({ exists: true, data: () => name === 'accounts_by_uid' ? { peanut_id: 'party-a' } : name === 'accounts' ? { peanut_id: 'party-a' } : booking }),
  update: async updates => { writes.push({ name, id, updates }); booking = { ...booking, ...updates }; },
  set: async value => { writes.push({ name, id, value }); },
}) }) };
const original = Module._load;
Module._load = function(name, ...args) {
  if (name === 'firebase-admin') return { firestore: () => db, auth: () => ({ verifyIdToken: async token => { tokenChecks.push(token); if (token !== 'valid-token') throw Error('invalid test token'); return { uid: 'uid-a' }; } }) };
  if (name === 'firebase-admin/auth') return { getAuth: () => ({ verifyIdToken: async token => { tokenChecks.push(token); if (token !== 'valid-token') throw Error('invalid test token'); return { uid: 'uid-a' }; } }) };
  if (name === 'firebase-admin/firestore') return { getFirestore: () => db, Timestamp: { now: () => ({ seconds: 1 }), fromDate: () => ({ seconds: 1 }) } };
  return original.call(this, name, ...args);
};
const router = require('../lib/api/booking').default;
const { verifyWebhookSignature } = require('../lib/utils/auth');
Module._load = original;
let server, origin;
before(async () => { const app = express(); app.use(express.json()); app.use('/booking', router); server = app.listen(0, '127.0.0.1'); await new Promise(resolve => server.once('listening', resolve)); origin = `http://127.0.0.1:${server.address().port}`; });
after(async () => { await new Promise(resolve => server.close(resolve)); });
beforeEach(() => { writes = []; tokenChecks = []; booking = { performer_peanut_id: 'party-a', customer_peanut_id: 'party-b', booking_status: 'pending' }; });
const body = JSON.stringify({ performer_confirmed: true });
const update = headers => fetch(origin + '/booking/fixture', { method: 'PATCH', headers: { 'Content-Type': 'application/json', ...headers }, body });
test('a bearer-shaped invalid token is verified and cannot write', async () => { const r = await update({ Authorization: 'Bearer invented-token' }); assert.equal(r.status, 401); assert.deepEqual(tokenChecks, ['invented-token']); assert.equal(writes.length, 0); });
test('verified account cannot modify another party booking', async () => { booking.performer_peanut_id = 'other-a'; booking.customer_peanut_id = 'other-b'; const r = await update({ Authorization: 'Bearer valid-token' }); assert.equal(r.status, 403); assert.equal(writes.length, 0); });
test('verified involved account retains its update behavior', async () => { const r = await update({ Authorization: 'Bearer valid-token' }); assert.equal(r.status, 200); assert.equal(writes.length, 1); assert.equal(writes[0].updates.performer_confirmed, true); });
test('a configured valid signed webhook retains update behavior', async () => { const signature = createHmac('sha256', secret).update(body).digest('hex'); const r = await update({ 'X-Peanut-Signature': signature }); assert.equal(r.status, 200); assert.equal(writes.length, 1); assert.equal(tokenChecks.length, 0); });
test('missing or public-default signature cannot write', async () => { const signature = createHmac('sha256', 'peanut-webhook-secret').update(body).digest('hex'); assert.equal((await update({ 'X-Peanut-Signature': signature })).status, 401); assert.equal((await update({})).status, 401); assert.equal(writes.length, 0); });
test('unconfigured and malformed webhook secrets/signatures fail closed', () => { assert.equal(verifyWebhookSignature(body, 'a'.repeat(64), ''), false); assert.equal(verifyWebhookSignature(body, 'garbage', secret), false); });
